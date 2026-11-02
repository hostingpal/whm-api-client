<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeDirectoryRecipe;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MimeDirectoryRecipeTest extends TestCase
{
    private const ENTROPY = '0123456789abcdef0123456789abcdef';

    private const WHM = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wlmime';

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    private const FIXTURE = '%2Fhome%2Fwlmime%2Fpublic_html%2Fwlmime-fixture-0123456789abcdef0123456789abcdef';

    public function testNamesDeriveFromTheRunValueAndStayDistinctPerRound(): void
    {
        $recipe = self::recipe();
        self::assertSame('wlmime-fixture-' . self::ENTROPY, $recipe->directory);
        self::assertSame('/home/wlmime/public_html/wlmime-fixture-' . self::ENTROPY, $recipe->absoluteDirectory());
        self::assertSame('public_html/wlmime-fixture-' . self::ENTROPY, $recipe->uploadDirectory());
        self::assertSame('wlmime-0123456789abcdef.txt', $recipe->uploadName);
        self::assertSame('application/x-wlmime-a-0123456789ab', $recipe->mimeTypes['a']);
        self::assertSame('.wlmb0123456789ab', $recipe->mimeExtensions['b']);
        self::assertSame('https://example.net/wlmime-target-b-0123456789ab', $recipe->redirectTargets['b']);
        self::assertSame("http://wlmime.example.com\nhttps://wlmime.example.com", $recipe->hotlinkUrls('b'));
        foreach (
            [$recipe->mimeTypes, $recipe->mimeExtensions, $recipe->handlerExtensions, $recipe->handlers,
                $recipe->redirectSources, $recipe->redirectTargets, $recipe->hotlinkExtensions,
                $recipe->hotlinkTargets, $recipe->authNames] as $pair
        ) {
            self::assertNotSame($pair['a'], $pair['b']);
        }
        self::assertNotSame($recipe->users['one'], $recipe->users['two']);
    }

    /** Passwords come from fresh randomness, hold every character class and are distinct within and across runs. */
    public function testPasswordsAreStrongAndNeverDerivedFromTheRunValue(): void
    {
        $first = self::recipe();
        $second = self::recipe();
        $passwords = [$first->userPassword('one'), $first->userPassword('two'), $second->userPassword('one')];
        self::assertCount(3, array_unique($passwords));
        foreach ($passwords as $password) {
            self::assertGreaterThanOrEqual(32, strlen($password));
            self::assertMatchesRegularExpression('/[a-z]/', $password);
            self::assertMatchesRegularExpression('/[A-Z]/', $password);
            self::assertMatchesRegularExpression('/[0-9]/', $password);
            self::assertMatchesRegularExpression('/[^A-Za-z0-9]/', $password);
            self::assertStringNotContainsString(substr(self::ENTROPY, 0, 8), $password);
        }
    }

    /** @param array{route: string, method: string, uri: string, body: string} $expected */
    #[DataProvider('wires')]
    public function testEveryRequestIsSpelledExactly(string $phase, array $expected): void
    {
        self::assertSame($expected, self::recipe()->wire($phase));
    }

    /** @return iterable<string, array{string, array{route: string, method: string, uri: string, body: string}}> */
    public static function wires(): iterable
    {
        yield 'version' => ['version', ['route' => 'whm', 'method' => 'GET',
            'uri' => 'https://test.oblak.host:2087/json-api/version?api.version=1', 'body' => '']];
        yield 'direct list' => ['mime_types_baseline', ['route' => 'direct-uapi', 'method' => 'GET',
            'uri' => self::DIRECT . 'Mime/list_mime?type=user', 'body' => '']];
        yield 'bridged list' => ['mime_types_after_delete_bridged', ['route' => 'bridged-uapi', 'method' => 'GET',
            'uri' => self::WHM . '&cpanel.module=Mime&cpanel.function=list_mime&type=user', 'body' => '']];
        yield 'direct add' => ['add_mime_direct', ['route' => 'direct-uapi', 'method' => 'POST',
            'uri' => self::DIRECT . 'Mime/add_mime',
            'body' => 'extension=.wlma0123456789ab&type=application%2Fx-wlmime-a-0123456789ab']];
        yield 'bridged delete keeps routing in the query' => ['delete_mime_bridged', ['route' => 'bridged-uapi',
            'method' => 'POST', 'uri' => self::WHM . '&cpanel.module=Mime&cpanel.function=delete_mime',
            'body' => 'type=application%2Fx-wlmime-a-0123456789ab']];
        yield 'default redirect' => ['add_redirect_direct', ['route' => 'direct-uapi', 'method' => 'POST',
            'uri' => self::DIRECT . 'Mime/add_redirect',
            'body' => 'domain=wlmime.example.com&redirect=https%3A%2F%2Fexample.net%2Fwlmime-target-a-0123456789ab'
                . '&src=%2Fwlmime-redirect-a-0123456789ab']];
        yield 'explicit redirect options' => ['add_redirect_bridged', ['route' => 'bridged-uapi', 'method' => 'POST',
            'uri' => self::WHM . '&cpanel.module=Mime&cpanel.function=add_redirect',
            'body' => 'domain=wlmime.example.com&redirect=https%3A%2F%2Fexample.net%2Fwlmime-target-b-0123456789ab'
                . '&redirect_wildcard=0&redirect_www=0&src=%2Fwlmime-redirect-b-0123456789ab&type=temp']];
        yield 'redirect removal with docroot' => ['delete_redirect_direct', ['route' => 'direct-uapi',
            'method' => 'POST', 'uri' => self::DIRECT . 'Mime/delete_redirect',
            'body' => 'domain=wlmime.example.com&docroot=%2Fhome%2Fwlmime%2Fpublic_html'
                . '&src=%2Fwlmime-redirect-b-0123456789ab']];
        yield 'hotlink with two referrers' => ['add_hotlink_bridged', ['route' => 'bridged-uapi', 'method' => 'POST',
            'uri' => self::WHM . '&cpanel.module=Mime&cpanel.function=add_hotlink',
            'body' => 'extensions=wlhlb0123456789ab%2Cwlhlc0123456789ab'
                . '&redirect_url=https%3A%2F%2Fexample.net%2Fwlmime-hotlink-b-0123456789ab'
                . '&urls=http%3A%2F%2Fwlmime.example.com%0Ahttps%3A%2F%2Fwlmime.example.com&allow_null=0']];
        yield 'hotlink removal has an empty body' => ['delete_hotlink_direct', ['route' => 'direct-uapi',
            'method' => 'POST', 'uri' => self::DIRECT . 'Mime/delete_hotlink', 'body' => '']];
        yield 'listing' => ['docroot_listing', ['route' => 'direct-uapi', 'method' => 'GET',
            'uri' => self::DIRECT . 'Fileman/list_files?dir=%2Fhome%2Fwlmime%2Fpublic_html&types=dir%7Cfile',
            'body' => '']];
        yield 'upload keeps its fields in the query' => ['create_directory', ['route' => 'direct-uapi',
            'method' => 'POST', 'uri' => self::DIRECT . 'Fileman/upload_files?dir=public_html%2Fwlmime-fixture-'
                . self::ENTROPY . '&overwrite=0&permissions=0644', 'body' => '']];
        yield 'inventory' => ['directory_inventory', ['route' => 'direct-uapi', 'method' => 'GET',
            'uri' => self::DIRECT . 'Fileman/list_files?dir=' . self::FIXTURE . '&show_hidden=1', 'body' => '']];
        yield 'bridged indexing' => ['set_indexing_bridged', ['route' => 'bridged-uapi', 'method' => 'POST',
            'uri' => self::WHM . '&cpanel.module=DirectoryIndexes&cpanel.function=set_indexing',
            'body' => 'dir=' . self::FIXTURE . '&type=fancy']];
        yield 'protection' => ['protect_direct', ['route' => 'direct-uapi', 'method' => 'POST',
            'uri' => self::DIRECT . 'DirectoryPrivacy/configure_directory_protection',
            'body' => 'dir=' . self::FIXTURE . '&enabled=1&authname=wlmime-realm-a-0123456789ab']];
        yield 'disable omits the realm' => ['unprotect_bridged', ['route' => 'bridged-uapi', 'method' => 'POST',
            'uri' => self::WHM . '&cpanel.module=DirectoryPrivacy&cpanel.function=configure_directory_protection',
            'body' => 'dir=' . self::FIXTURE . '&enabled=0']];
        yield 'user removal' => ['delete_user_bridged', ['route' => 'bridged-uapi', 'method' => 'POST',
            'uri' => self::WHM . '&cpanel.module=DirectoryPrivacy&cpanel.function=delete_user',
            'body' => 'dir=' . self::FIXTURE . '&user=wlmime-user-one-0123456789ab']];
        yield 'bridged leech read' => ['leech_final', ['route' => 'bridged-uapi', 'method' => 'GET',
            'uri' => self::WHM . '&cpanel.module=DirectoryProtection&cpanel.function=list_directories&dir='
                . self::FIXTURE, 'body' => '']];
    }

    /** The password reaches its own add_user body, form-encoded once, and no other request. */
    public function testEachPasswordAppearsOnlyInItsOwnAddUserBody(): void
    {
        $recipe = self::recipe();
        foreach (['one' => 'add_user_direct', 'two' => 'add_user_bridged'] as $user => $owner) {
            $password = $recipe->userPassword($user);
            foreach (array_keys(MimeDirectoryRecipe::PHASES) as $phase) {
                $wire = $recipe->wire($phase);
                self::assertStringNotContainsString(rawurlencode($password), $wire['uri'], $phase);
                $expected = $phase === $owner ? 1 : 0;
                self::assertSame($expected, substr_count($wire['body'], rawurlencode($password)), $phase);
            }
        }
        self::assertSame(
            'dir=' . self::FIXTURE . '&user=wlmime-user-two-0123456789ab&password='
                . rawurlencode($recipe->userPassword('two')),
            $recipe->wire('add_user_bridged')['body'],
        );
    }

    public function testEveryRoundSendsEachMutationOncePerRoute(): void
    {
        $mutations = [];
        foreach (MimeDirectoryRecipe::PHASES as [$section, $route, $operation, $method]) {
            if ($method === 'POST' && !str_starts_with($operation, 'uapi:Fileman/')) {
                $mutations[$operation][] = $route;
            }
        }
        self::assertCount(12, $mutations);
        foreach ($mutations as $operation => $routes) {
            self::assertContains('direct-uapi', $routes, $operation);
            self::assertContains('bridged-uapi', $routes, $operation);
        }
    }

    public function testUploadBodyIsAssembledByHand(): void
    {
        self::assertSame(
            "--BOUNDARY\r\nContent-Disposition: form-data; name=\"file-0\"; filename=\"wlmime-0123456789abcdef.txt\""
                . "\r\nContent-Type: application/octet-stream\r\n\r\nSynthetic directory-access fixture file.\n\r\n"
                . "--BOUNDARY--\r\n",
            self::recipe()->expectedUploadBody('BOUNDARY'),
        );
    }

    /** @param array{string, string, string, int, bool} $binding */
    #[DataProvider('foreignBindings')]
    public function testRefusesAnyOtherBinding(array $binding, string $entropy): void
    {
        [$user, $domain, $token, $lifetime, $readOnly] = $binding;
        $account = DisposableAccount::fresh($user, $domain, $token, $lifetime, $readOnly);
        $this->expectException(InvalidArgumentException::class);
        new MimeDirectoryRecipe($entropy, $account);
    }

    /** @return iterable<string, array{array{string, string, string, int, bool}, string}> */
    public static function foreignBindings(): iterable
    {
        $own = ['wlmime', 'wlmime.example.com', 'wlmime-fixture', 900, false];
        yield 'short entropy' => [$own, '0123'];
        yield 'uppercase entropy' => [$own, strtoupper(self::ENTROPY)];
        yield 'other account' => [['wlupload', 'wlupload.example.com', 'wlmime-fixture', 900, false], self::ENTROPY];
        yield 'other domain' => [['wlmime', 'other.example.com', 'wlmime-fixture', 900, false], self::ENTROPY];
        yield 'other token' => [['wlmime', 'wlmime.example.com', 'wlmime-other', 900, false], self::ENTROPY];
        yield 'read-only token' => [['wlmime', 'wlmime.example.com', 'wlmime-fixture', 900, true], self::ENTROPY];
    }

    public function testUnknownPhaseIsRefused(): void
    {
        $this->expectException(LogicException::class);
        self::recipe()->wire('add_mime_twice');
    }

    public function testNeitherDumpsNorSerializationRevealRunValues(): void
    {
        $recipe = self::recipe();
        self::assertSame([], $recipe->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($recipe);
    }

    private static function recipe(): MimeDirectoryRecipe
    {
        return new MimeDirectoryRecipe(self::ENTROPY, DisposableAccount::fresh(
            'wlmime',
            'wlmime.example.com',
            'wlmime-fixture',
            900,
            false,
        ));
    }
}
