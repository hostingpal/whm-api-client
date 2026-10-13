<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Config;

use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use HostingPal\WhmApiClient\Authentication\TokenType;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Config\ClientConfigFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientConfigFactoryTest extends TestCase
{
    public function testWhmDefaultsToRootAndSecureWhmPort(): void
    {
        $config = ClientConfigFactory::fromArray([
            'host' => 'WHM.Example.test', 'token' => 'test-token', 'authType' => 'whm',
        ]);

        self::assertSame('whm.example.test', $config->host);
        self::assertSame('https://whm.example.test:2087', $config->baseUri());
        self::assertSame('whm root:test-token', $config->credentials->authorizationHeader());
        self::assertNull($config->defaultCpanelUser);
    }

    public function testDirectCpanelUsesItsAccountAndSecureCpanelPort(): void
    {
        $config = ClientConfigFactory::fromArray([
            'host' => 'cpanel.example.test', 'token' => 'test-token', 'authType' => 'cpanel', 'username' => 'account',
        ]);

        self::assertSame('https://cpanel.example.test:2083', $config->baseUri());
        self::assertSame('cpanel account:test-token', $config->credentials->authorizationHeader());
    }

    public function testPreservesExplicitWhmAccountAndDefaultCpanelTarget(): void
    {
        $config = ClientConfigFactory::fromArray([
            'host' => '192.0.2.1', 'token' => 'test-token', 'authType' => 'whm',
            'username' => 'reseller', 'defaultCpanelUser' => 'account', 'port' => 443,
        ]);

        self::assertSame('https://192.0.2.1:443', $config->baseUri());
        self::assertSame('whm reseller:test-token', $config->credentials->authorizationHeader());
        self::assertSame('account', $config->defaultCpanelUser);
    }

    #[DataProvider('validIpv6Hosts')]
    public function testNormalizesIpv6ForSecureUri(string $host): void
    {
        $config = ClientConfigFactory::fromArray([
            'host' => $host, 'token' => 'test-token', 'authType' => 'whm', 'port' => 65535,
        ]);

        self::assertSame('2001:db8::1', $config->host);
        self::assertSame('https://[2001:db8::1]:65535', $config->baseUri());
    }

    /** @return iterable<string, array{string}> */
    public static function validIpv6Hosts(): iterable
    {
        yield 'bare' => ['2001:DB8::1'];
        yield 'bracketed' => ['[2001:DB8::1]'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidFactoryOptions(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientConfigFactory::fromArray($options);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidOptions(): iterable
    {
        $valid = ['host' => 'example.test', 'token' => 'test-token', 'authType' => 'whm'];
        yield 'missing auth type' => [['host' => 'example.test', 'token' => 'test-token']];
        yield 'missing host' => [['token' => 'test-token', 'authType' => 'whm']];
        yield 'missing token' => [['host' => 'example.test', 'authType' => 'whm']];
        yield 'cpanel username required' => [array_replace($valid, ['authType' => 'cpanel'])];
        yield 'unknown key' => [$valid + ['typo' => 'value']];
        foreach (['host', 'token', 'authType', 'username', 'port', 'defaultCpanelUser'] as $key) {
            yield $key . ' null' => [array_replace($valid, [$key => null])];
            yield $key . ' array' => [array_replace($valid, [$key => []])];
        }
        yield 'wrong auth type' => [array_replace($valid, ['authType' => 'basic'])];
        yield 'uppercase auth type' => [array_replace($valid, ['authType' => 'WHM'])];
        yield 'empty username' => [$valid + ['username' => '']];
        yield 'empty default target' => [$valid + ['defaultCpanelUser' => '']];
        yield 'uppercase default target' => [$valid + ['defaultCpanelUser' => 'Account']];
        yield 'string port' => [$valid + ['port' => '2087']];
        yield 'float port' => [$valid + ['port' => 2087.0]];
        yield 'boolean token' => [array_replace($valid, ['token' => true])];
    }

    #[DataProvider('invalidHosts')]
    public function testDirectConfigurationRejectsMalformedHosts(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientConfig($host, new TokenCredentials(TokenType::Whm, 'root', 'test-token'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHosts(): iterable
    {
        yield 'empty' => [''];
        yield 'scheme' => ['https://example.test'];
        yield 'path' => ['example.test/path'];
        yield 'query' => ['example.test?q=1'];
        yield 'fragment' => ['example.test#fragment'];
        yield 'userinfo' => ['root@example.test'];
        yield 'embedded port' => ['example.test:2087'];
        yield 'space' => ['example.test '];
        yield 'control' => ["example.test\0"];
        yield 'invalid label' => ['-example.test'];
        yield 'underscore' => ['example_test'];
        yield 'invalid IPv4' => ['999.999.999.999'];
        yield 'invalid IPv6' => ['[2001:db8:::1]'];
        yield 'unclosed bracket' => ['[2001:db8::1'];
        yield 'bracketed DNS' => ['[example.test]'];
        yield 'IPv6 with port' => ['[2001:db8::1]:2087'];
    }

    #[DataProvider('invalidPorts')]
    public function testDirectConfigurationRejectsInvalidPorts(int $port): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientConfig('example.test', new TokenCredentials(TokenType::Whm, 'root', 'test-token'), $port);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPorts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too large' => [65536];
    }

    public function testRejectsDifferentDefaultAccountForDirectCpanel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientConfig(
            'example.test',
            new TokenCredentials(TokenType::Cpanel, 'account', 'test-token'),
            null,
            'other',
        );
    }

    public function testAcceptsMatchingDefaultAccountForDirectCpanel(): void
    {
        $config = new ClientConfig(
            'example.test',
            new TokenCredentials(TokenType::Cpanel, 'account', 'test-token'),
            1,
            'account',
        );

        self::assertSame('https://example.test:1', $config->baseUri());
        self::assertSame('account', $config->defaultCpanelUser);
    }

    public function testDirectConfigurationValidatesDefaultAccount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientConfig(
            'example.test',
            new TokenCredentials(TokenType::Whm, 'root', 'test-token'),
            null,
            'bad:account',
        );
    }

    public function testErrorsAndDebuggingDoNotExposeFactoryToken(): void
    {
        $options = ['host' => 'example.test', 'token' => 'unique-secret-token', 'authType' => 'whm'];
        $config = ClientConfigFactory::fromArray($options);
        ob_start();
        var_dump($config);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString('unique-secret-token', $debug);

        try {
            ClientConfigFactory::fromArray($options + ['unique-secret-key' => 'unique-secret-value']);
            self::fail('Unknown option was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('unique-secret', $exception->getMessage());
        }
    }
}
