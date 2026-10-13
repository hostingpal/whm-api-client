<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Authentication;

use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use HostingPal\WhmApiClient\Authentication\TokenType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenCredentialsTest extends TestCase
{
    public function testBuildsWhmAuthorizationHeader(): void
    {
        $credentials = new TokenCredentials(TokenType::Whm, 'root', 'test-token');

        self::assertSame('whm root:test-token', $credentials->authorizationHeader());
    }

    public function testBuildsCpanelAuthorizationHeader(): void
    {
        $credentials = new TokenCredentials(TokenType::Cpanel, 'account_1', 'test-token');

        self::assertSame('cpanel account_1:test-token', $credentials->authorizationHeader());
    }

    #[DataProvider('invalidUsernames')]
    public function testRejectsInvalidUsername(string $username): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenCredentials(TokenType::Whm, $username, 'test-token');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUsernames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Root'];
        yield 'colon' => ['root:other'];
        yield 'hyphen' => ['some-user'];
        yield 'non ASCII' => ['café'];
        yield 'space' => ['root '];
        yield 'control' => ["root\0"];
    }

    #[DataProvider('invalidTokens')]
    public function testRejectsInvalidTokenWithoutExposingIt(string $token): void
    {
        try {
            new TokenCredentials(TokenType::Whm, 'root', $token);
            self::fail('Invalid credentials were accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['secret token'];
        yield 'tab' => ["secret\ttoken"];
        yield 'newline' => ["secret\ntoken"];
        yield 'carriage return' => ["secret\rtoken"];
        yield 'null' => ["secret\0token"];
        yield 'control' => ["secret\x01token"];
        yield 'delete' => ["secret\x7ftoken"];
    }

    public function testDoesNotExposeTokenWhenDebugged(): void
    {
        $credentials = new TokenCredentials(TokenType::Whm, 'root', 'unique-secret-token');

        ob_start();
        var_dump($credentials);
        $debug = ob_get_clean();

        self::assertIsString($debug);
        self::assertStringNotContainsString('unique-secret-token', $debug);
        self::assertStringContainsString('root', $debug);
    }
}
