<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ControlPostAdmissionTest extends TestCase
{
    private const MARKER = 'fixture-0123456789abcdef0123456789abcdef@wdisposable.example.com';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const CREATE = self::WHM . 'createacct?api.version=1';

    private const CREATE_BODY = 'username=wdisposable&domain=wdisposable.example.com'
        . '&contactemail=fixture-0123456789abcdef0123456789abcdef%40wdisposable.example.com'
        . '&quota=64&forcedns=0&hasshell=0&ip=n';

    public function testEachExactControlMutationIsAdmittedOnceAndNeverAgain(): void
    {
        $account = self::account();
        $admission = $account->controlPostAdmission();
        $posts = $account->controlPosts();
        self::assertCount(4, $posts);
        foreach ($posts as $uri => $body) {
            self::assertTrue($admission->admit($uri, $body));
            self::assertFalse($admission->admit($uri, $body));
        }
        foreach ($posts as $uri => $body) {
            self::assertFalse($admission->admit($uri, $body));
        }
    }

    /** The independently spelled createacct body is admitted, with the marker the binding holds. */
    public function testTheExactCreationBodyIsAdmitted(): void
    {
        self::assertTrue(self::account()->controlPostAdmission()->admit(self::CREATE, self::CREATE_BODY));
    }

    #[DataProvider('inexactRequests')]
    public function testAnInexactRequestIsRefusedAndConsumesNothing(string $uri, string $body): void
    {
        $account = self::account();
        $admission = $account->controlPostAdmission();

        self::assertFalse($admission->admit($uri, $body));
        foreach ($account->controlPosts() as $exactUri => $exactBody) {
            self::assertTrue($admission->admit($exactUri, $exactBody));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function inexactRequests(): iterable
    {
        yield 'another marker' => [self::CREATE, str_replace('0123', '3210', self::CREATE_BODY)];
        yield 'an extra field' => [self::CREATE, self::CREATE_BODY . '&plan=default'];
        yield 'reordered fields' => [self::CREATE, 'domain=wdisposable.example.com&username=wdisposable'
            . substr(self::CREATE_BODY, strlen('username=wdisposable&domain=wdisposable.example.com'))];
        yield 'an empty body' => [self::CREATE, ''];
        yield 'another URI' => [self::CREATE . '&api.version=1', self::CREATE_BODY];
        yield 'another account removed' => [self::WHM . 'removeacct?api.version=1', 'username=npr&keepdns=0'];
        yield 'a removal that keeps DNS' => [self::WHM . 'removeacct?api.version=1', 'username=wdisposable&keepdns=1'];
        yield 'another function' => [self::WHM . 'suspendacct?api.version=1', 'user=wdisposable'];
    }

    /** A repeat stays refused even with another body, so no request can stand in for a consumed one. */
    public function testAConsumedMutationIsRefusedWhateverItsBody(): void
    {
        $admission = self::account()->controlPostAdmission();
        self::assertTrue($admission->admit(self::CREATE, self::CREATE_BODY));
        self::assertFalse($admission->admit(self::CREATE, self::CREATE_BODY . '&plan=default'));
        self::assertFalse($admission->admit(self::CREATE, self::CREATE_BODY));
    }

    /** Each guard asks its own admission, so one guard's consumption never spends another's. */
    public function testEachAdmissionConsumesOnlyItsOwnRequests(): void
    {
        $account = self::account();
        self::assertTrue($account->controlPostAdmission()->admit(self::CREATE, self::CREATE_BODY));
        self::assertTrue($account->controlPostAdmission()->admit(self::CREATE, self::CREATE_BODY));
    }

    public function testAdmissionNeverPrintsOrPersists(): void
    {
        $admission = self::account()->controlPostAdmission();
        self::assertSame([], $admission->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($admission);
    }

    private static function account(): DisposableAccount
    {
        return new DisposableAccount(
            DisposableAccountServer::USER,
            DisposableAccountServer::DOMAIN,
            DisposableAccountServer::TOKEN_NAME,
            self::MARKER,
            time() + 900,
            false,
        );
    }
}
