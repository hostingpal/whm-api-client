<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\WebmailSessionRecipe;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebmailSessionRecipeTest extends TestCase
{
    private const ENTROPY = '0123456789abcdef0123456789abcdef';

    private const PASSWORD = 'Ws!9PRIVATEpassword0123456789';

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    private const BRIDGE = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wlsession';

    public function testEveryPhaseHasOneExactRequestSpelledByHand(): void
    {
        $recipe = self::recipe();
        $password = 'Ws%219PRIVATEpassword0123456789';
        $mailbox = 'box0123456789abcdef';
        $expected = [
            'version' => ['GET', 'https://test.oblak.host:2087/json-api/version?api.version=1', ''],
            'sessions_initial_direct' => ['GET', self::DIRECT . 'ActiveSessions/list_active_sessions', ''],
            'sessions_initial_bridged' => ['GET', self::BRIDGE
                . '&cpanel.module=ActiveSessions&cpanel.function=list_active_sessions', ''],
            'create_mailbox' => ['POST', self::DIRECT . 'Email/add_pop', 'email=' . $mailbox . '&password='
                . $password . '&domain=wlsession.example.com&quota=16&send_welcome_email=0'],
            'self_direct' => ['POST', self::DIRECT . 'Session/create_webmail_session_for_self',
                'remote_address=192.0.2.10'],
            'mail_user_direct' => ['POST', self::DIRECT . 'Session/create_webmail_session_for_mail_user',
                'domain=wlsession.example.com&login=' . $mailbox . '&remote_address=192.0.2.10'],
            'check_password_direct' => ['POST', self::DIRECT
                . 'Session/create_webmail_session_for_mail_user_check_password', 'domain=wlsession.example.com'
                . '&login=' . $mailbox . '&password=' . $password . '&remote_address=192.0.2.10'],
            'self_bridged' => ['POST', self::BRIDGE
                . '&cpanel.module=Session&cpanel.function=create_webmail_session_for_self',
                'remote_address=192.0.2.10'],
            'mail_user_bridged' => ['POST', self::BRIDGE
                . '&cpanel.module=Session&cpanel.function=create_webmail_session_for_mail_user',
                'domain=wlsession.example.com&login=' . $mailbox . '&remote_address=192.0.2.10'],
            'check_password_bridged' => ['POST', self::BRIDGE
                . '&cpanel.module=Session&cpanel.function=create_webmail_session_for_mail_user_check_password',
                'domain=wlsession.example.com&login=' . $mailbox . '&password=' . $password
                    . '&remote_address=192.0.2.10'],
            'sessions_final_direct' => ['GET', self::DIRECT . 'ActiveSessions/list_active_sessions', ''],
            'sessions_final_bridged' => ['GET', self::BRIDGE
                . '&cpanel.module=ActiveSessions&cpanel.function=list_active_sessions', ''],
        ];

        self::assertSame(array_keys($expected), WebmailSessionRecipe::PHASES);
        foreach ($expected as $phase => [$method, $uri, $body]) {
            self::assertSame(['method' => $method, 'uri' => $uri, 'body' => $body], $recipe->request($phase), $phase);
        }
    }

    public function testRoutesAndCreatorsFollowThePhaseNames(): void
    {
        self::assertSame('whm', WebmailSessionRecipe::route('version'));
        self::assertSame('direct-uapi', WebmailSessionRecipe::route('create_mailbox'));
        self::assertSame('direct-uapi', WebmailSessionRecipe::route('self_direct'));
        self::assertSame('bridged-uapi', WebmailSessionRecipe::route('check_password_bridged'));
        self::assertSame('bridged-uapi', WebmailSessionRecipe::route('sessions_final_bridged'));
        self::assertSame('self', WebmailSessionRecipe::creator('self_bridged'));
        self::assertSame('mail_user', WebmailSessionRecipe::creator('mail_user_direct'));
        self::assertSame('check_password', WebmailSessionRecipe::creator('check_password_bridged'));
        self::assertNull(WebmailSessionRecipe::creator('create_mailbox'));
        self::assertNull(WebmailSessionRecipe::creator('sessions_initial_direct'));
    }

    public function testUnknownPhasesAreRefused(): void
    {
        $this->expectException(LogicException::class);
        self::recipe()->request('login_webmail');
    }

    public function testTheAddressIsADocumentationAddressAndTheMailboxComesFromTheRunValue(): void
    {
        $recipe = self::recipe();
        self::assertSame('192.0.2.10', WebmailSessionRecipe::REMOTE_ADDRESS);
        self::assertSame('box0123456789abcdef', $recipe->mailbox);
        self::assertStringNotContainsString(WebmailSessionRecipe::ACCOUNT, $recipe->mailbox);
        self::assertSame(self::PASSWORD, $recipe->password());
    }

    /** The password is in memory only: no dump, cast or encoding of the recipe shows it, and it cannot be copied. */
    public function testThePasswordIsNeverExposedByTheRecipe(): void
    {
        $recipe = self::recipe();
        MarkerExposure::assertNeverExposed(self::PASSWORD, $recipe);
        MarkerExposure::assertNeverExposed('PRIVATEpassword', $recipe);
        self::assertSame([], $recipe->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($recipe);
    }

    #[DataProvider('invalidBindings')]
    public function testOnlyItsOwnWritableAccountAndAStrongPasswordAreAccepted(
        string $entropy,
        string $password,
        DisposableAccount $account,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new WebmailSessionRecipe($entropy, $password, $account);
    }

    /** @return iterable<string, array{string, string, DisposableAccount}> */
    public static function invalidBindings(): iterable
    {
        $own = self::account();
        yield 'short entropy' => ['0123', self::PASSWORD, $own];
        yield 'uppercase entropy' => [strtoupper(self::ENTROPY), self::PASSWORD, $own];
        yield 'short password' => [self::ENTROPY, 'Ws!9short', $own];
        yield 'password with an ampersand' => [self::ENTROPY, self::PASSWORD . '&x=1', $own];
        yield 'password with a space' => [self::ENTROPY, self::PASSWORD . ' x', $own];
        yield 'another account' => [self::ENTROPY, self::PASSWORD, DisposableAccount::fresh(
            'wlupload',
            'wlupload.example.com',
            WebmailSessionRecipe::TOKEN_NAME,
            900,
            false,
        )];
        yield 'another domain' => [self::ENTROPY, self::PASSWORD, DisposableAccount::fresh(
            'wlsession',
            'other.example.com',
            WebmailSessionRecipe::TOKEN_NAME,
            900,
            false,
        )];
        yield 'another token' => [self::ENTROPY, self::PASSWORD, DisposableAccount::fresh(
            'wlsession',
            'wlsession.example.com',
            'other-token',
            900,
            false,
        )];
        yield 'read-only token' => [self::ENTROPY, self::PASSWORD, DisposableAccount::fresh(
            'wlsession',
            'wlsession.example.com',
            WebmailSessionRecipe::TOKEN_NAME,
            900,
            true,
        )];
    }

    private static function recipe(): WebmailSessionRecipe
    {
        return new WebmailSessionRecipe(self::ENTROPY, self::PASSWORD, self::account());
    }

    private static function account(): DisposableAccount
    {
        return DisposableAccount::fresh(
            WebmailSessionRecipe::ACCOUNT,
            WebmailSessionRecipe::DOMAIN,
            WebmailSessionRecipe::TOKEN_NAME,
            900,
            false,
        );
    }
}
