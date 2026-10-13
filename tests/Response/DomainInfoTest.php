<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\DomainInfo;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DomainInfoTest extends TestCase
{
    public function testMapsEveryReviewedFieldWithoutNarrowingTheDomainType(): void
    {
        $domain = DomainInfo::fromPayload((object) [
            'docroot' => '/home/account/public_html',
            'domain' => 'example.test',
            'domain_type' => 'future-domain-kind',
            'ipv4' => '192.0.2.10',
            'ipv4_ssl' => '192.0.2.11',
            'ipv6' => '2001:db8::1',
            'ipv6_is_dedicated' => 1,
            'modsecurity_enabled' => 0,
            'parent_domain' => 'parent.test',
            'php_version' => 'ea-php83',
            'port' => 80,
            'port_ssl' => 443,
            'user' => 'account',
            'user_owner' => 'reseller',
            'extension' => new stdClass(),
        ], new ResponseMetadata(200));

        self::assertSame('/home/account/public_html', $domain->docroot);
        self::assertSame('example.test', $domain->domain);
        self::assertSame('future-domain-kind', $domain->domainType);
        self::assertSame('192.0.2.10', $domain->ipv4);
        self::assertSame('192.0.2.11', $domain->ipv4Ssl);
        self::assertSame('2001:db8::1', $domain->ipv6);
        self::assertSame(1, $domain->ipv6IsDedicated);
        self::assertSame(0, $domain->modsecurityEnabled);
        self::assertSame('parent.test', $domain->parentDomain);
        self::assertSame('ea-php83', $domain->phpVersion);
        self::assertSame(80, $domain->port);
        self::assertSame(443, $domain->portSsl);
        self::assertSame('account', $domain->user);
        self::assertSame('reseller', $domain->userOwner);
        self::assertSame([
            'docroot' => '/home/account/public_html',
            'domain' => 'example.test',
            'domain_type' => 'future-domain-kind',
            'ipv4' => '192.0.2.10',
            'ipv4_ssl' => '192.0.2.11',
            'ipv6' => '2001:db8::1',
            'ipv6_is_dedicated' => 1,
            'modsecurity_enabled' => 0,
            'parent_domain' => 'parent.test',
            'php_version' => 'ea-php83',
            'port' => 80,
            'port_ssl' => 443,
            'user' => 'account',
            'user_owner' => 'reseller',
        ], $domain->toArray());
    }

    public function testRetainsOmittedFieldsSeparatelyFromExplicitNullZeroAndBlankValues(): void
    {
        $metadata = new ResponseMetadata(200);
        $omitted = DomainInfo::fromPayload(new stdClass(), $metadata);
        $explicit = DomainInfo::fromPayload((object) [
            'docroot' => '',
            'domain' => '',
            'domain_type' => '',
            'ipv4' => '',
            'ipv4_ssl' => '',
            'ipv6' => null,
            'ipv6_is_dedicated' => 0,
            'modsecurity_enabled' => 1,
            'parent_domain' => '',
            'php_version' => '',
            'port' => 0,
            'port_ssl' => 0,
            'user' => '',
            'user_owner' => '',
        ], $metadata);

        self::assertNull($omitted->docroot);
        self::assertNull($omitted->domain);
        self::assertNull($omitted->domainType);
        self::assertNull($omitted->ipv4);
        self::assertNull($omitted->ipv4Ssl);
        self::assertNull($omitted->ipv6);
        self::assertNull($omitted->ipv6IsDedicated);
        self::assertNull($omitted->modsecurityEnabled);
        self::assertNull($omitted->parentDomain);
        self::assertNull($omitted->phpVersion);
        self::assertNull($omitted->port);
        self::assertNull($omitted->portSsl);
        self::assertNull($omitted->user);
        self::assertNull($omitted->userOwner);
        self::assertSame([], $omitted->toArray());
        self::assertSame([
            'docroot' => '',
            'domain' => '',
            'domain_type' => '',
            'ipv4' => '',
            'ipv4_ssl' => '',
            'ipv6' => null,
            'ipv6_is_dedicated' => 0,
            'modsecurity_enabled' => 1,
            'parent_domain' => '',
            'php_version' => '',
            'port' => 0,
            'port_ssl' => 0,
            'user' => '',
            'user_owner' => '',
        ], $explicit->toArray());
        self::assertSame(0, $explicit->port);
        self::assertSame(0, $explicit->portSsl);
        self::assertSame(0, $explicit->ipv6IsDedicated);
        self::assertSame(1, $explicit->modsecurityEnabled);
    }

    public function testDetachesMutableInputReferencesAndExportedArrays(): void
    {
        $name = 'example.test';
        $ipv6 = null;
        $flag = 0;
        $port = '80';
        $securePort = '443';
        $payload = (object) [
            'domain' => &$name,
            'ipv6' => &$ipv6,
            'ipv6_is_dedicated' => &$flag,
            'port' => &$port,
            'port_ssl' => &$securePort,
        ];
        $domain = DomainInfo::fromPayload($payload, new ResponseMetadata(200));

        $name = 'changed.test';
        $ipv6 = '2001:db8::1';
        $flag = 1;
        $port = 443;
        $securePort = '8443';
        $payload->user = 'changed';
        $exported = $domain->toArray();
        $exported['domain'] = 'exported.test';
        $exported['port'] = '8080';
        $exported['port_ssl'] = '8443';

        self::assertSame('example.test', $domain->domain);
        self::assertNull($domain->ipv6);
        self::assertSame(0, $domain->ipv6IsDedicated);
        self::assertSame(80, $domain->port);
        self::assertSame(443, $domain->portSsl);
        self::assertSame([
            'domain' => 'example.test',
            'ipv6' => null,
            'ipv6_is_dedicated' => 0,
            'port' => '80',
            'port_ssl' => '443',
        ], $domain->toArray());
    }

    #[DataProvider('validPorts')]
    public function testNormalizesPortPropertiesWhilePreservingWireValues(int|string $wire, int $expected): void
    {
        $domain = DomainInfo::fromPayload((object) [
            'port' => $wire,
            'port_ssl' => $wire,
        ], new ResponseMetadata(200));

        self::assertSame($expected, $domain->port);
        self::assertSame($expected, $domain->portSsl);
        self::assertSame(['port' => $wire, 'port_ssl' => $wire], $domain->toArray());
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function validPorts(): iterable
    {
        yield 'observed HTTP port' => ['80', 80];
        yield 'observed HTTPS port' => ['443', 443];
        yield 'string zero' => ['0', 0];
        yield 'string above network port range' => ['65536', 65536];
        yield 'largest representable string' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'documented negative integer' => [-1, -1];
        yield 'documented smallest integer' => [PHP_INT_MIN, PHP_INT_MIN];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedRecordsWithTheResponseSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            DomainInfo::fromPayload($payload, $metadata);
            self::fail('Malformed records must not produce typed domain information.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertStringNotContainsString('sensitive-response-value', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'null' => [null];
        yield 'scalar' => ['sensitive-response-value'];
        yield 'list' => [[]];
        yield 'associative array' => [['domain' => 'example.test']];
        yield 'non-JSON object' => [new ResponseMetadata(200)];
        foreach (
            ['docroot', 'domain', 'domain_type', 'ipv4', 'ipv4_ssl', 'parent_domain', 'php_version',
                'user', 'user_owner'] as $field
        ) {
            yield $field . ' null' => [(object) [$field => null]];
            yield $field . ' integer' => [(object) [$field => 1]];
        }
        yield 'ipv6 integer' => [(object) ['ipv6' => 1]];
        yield 'ipv6 object' => [(object) ['ipv6' => new stdClass()]];
        foreach (['ipv6_is_dedicated', 'modsecurity_enabled'] as $field) {
            foreach ([null, false, '0', 1.0, -1, 2] as $index => $value) {
                yield $field . ' invalid flag ' . $index => [(object) [$field => $value]];
            }
        }
        foreach (['port', 'port_ssl'] as $field) {
            foreach ([null, false, '80.0', 80.0, new stdClass(), []] as $index => $value) {
                yield $field . ' invalid integer ' . $index => [(object) [$field => $value]];
            }
            foreach (
                ['', ' ', ' 80', '80 ', "80\n", "80\0", '00', '080', '+80', '-1', '-0', '8e1',
                    '0x50', '８０', '80tail'] as $index => $value
            ) {
                yield $field . ' noncanonical decimal ' . $index => [(object) [$field => $value]];
            }
            yield $field . ' integer overflow' => [(object) [
                $field => PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648',
            ]];
            yield $field . ' long integer overflow' => [(object) [$field => (string) PHP_INT_MAX . '0']];
        }
    }
}
