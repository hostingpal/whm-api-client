<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Dns as UapiDns;
use HostingPal\WhmApiClient\Api\Whm\Dns as WhmDns;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Support\LiveCurlClient;
use HostingPal\WhmApiClient\Tests\Support\LiveTestEnvironment;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Opted-in, redacted verification of the 21 read-only DNS operations against the test zone.
 *
 * Every request is a read-only GET approved by LiveCurlClient. Assertions check the
 * outcome, HTTP status and documented shape, never record values, and failure
 * messages carry only exception classes and numeric statuses. The zone line for
 * getzonerecord and the host for lookupnsip/lookupnsips are derived at runtime from
 * earlier read-only responses and bound to the guarded transport.
 *
 * The test zone exists only locally, so resolvedomainname, which needs public
 * resolution, targets a public name. Everything else stays on the test zone.
 *
 * The WHM and UAPI zone-line and authority-record shapes are identical, so both
 * families share the UAPI aliases.
 *
 * @phpstan-import-type ParseZoneResult from UapiDns as ZoneLines
 * @phpstan-import-type HasLocalAuthorityResult from UapiDns as AuthorityRecords
 */
#[Group('live')]
final class DnsLiveTest extends TestCase
{
    private const ZONE = 'npr.rs';

    /** Reserved by RFC 2606 and resolvable, unlike the test zone, which is NXDOMAIN publicly. */
    private const PUBLIC_DOMAIN = 'example.com';

    public function testWhmListZonesIncludesTheTestZone(): void
    {
        $whm = self::whm();
        $zones = self::safely(static fn (): array => $whm->dns()->listZones());

        self::assertTrue(array_key_exists('zone', $zones), 'listzones must return its zone list.');
        $found = false;
        foreach ($zones['zone'] as $zone) {
            $found = $found || ($zone['domain'] ?? null) === self::ZONE;
        }

        self::assertTrue($found, 'listzones must include the test zone.');
        self::assertWhmSuccess($whm);
    }

    public function testWhmParseDnsZone(): void
    {
        $whm = self::whm();
        $zone = self::safely(static fn (): array => $whm->dns()->parseDnsZone(self::ZONE));

        self::assertParsedZone($zone['payload'] ?? []);
        self::assertWhmSuccess($whm);
    }

    public function testWhmGetZoneRecordForALineDerivedFromTheParsedZone(): void
    {
        $parser = self::whm();
        $zone = self::safely(static fn (): array => $parser->dns()->parseDnsZone(self::ZONE));
        [$lineIndex, $recordType] = self::firstRecord($zone['payload'] ?? []);
        // parse_dns_zone line_index starts at 0; the getzonerecord line number starts at 1.
        $line = $lineIndex + 1;

        $whm = self::whm(zoneLine: $line);
        $result = self::safely(static fn (): array => $whm->dns()->getZoneRecord(self::ZONE, $line));
        $records = $result['record'] ?? [];

        self::assertTrue(count($records) === 1, 'getzonerecord must return exactly the requested line.');
        self::assertTrue(($records[0]['Line'] ?? null) === $line, 'getzonerecord must report the requested line.');
        self::assertTrue(
            ($records[0]['type'] ?? null) === $recordType,
            'getzonerecord must return the record type parse_dns_zone reported for that line.',
        );
        // The record data lives in the per-type fields of the pinned discriminator mapping, beyond the base fields.
        self::assertTrue(
            array_diff_key($records[0], array_flip(['Line', 'class', 'name', 'ttl', 'type'])) !== [],
            'getzonerecord must return the record type fields beyond the five base fields.',
        );
        self::assertWhmSuccess($whm);
    }

    public function testWhmListMxRecords(): void
    {
        $whm = self::whm();
        $result = self::safely(static fn (): array => $whm->dns()->listMxRecords(self::ZONE));

        self::assertTrue(array_key_exists('record', $result), 'listmxs must return its record list.');
        foreach ($result['record'] as $record) {
            self::assertTrue(($record['type'] ?? null) === 'MX', 'listmxs must return only MX records.');
        }

        self::assertWhmSuccess($whm);
    }

    public function testWhmResolveDomainName(): void
    {
        $whm = self::whm();
        $result = self::safely(static fn (): array => $whm->dns()->resolveDomainName(self::PUBLIC_DOMAIN));

        self::assertTrue(
            filter_var($result['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'resolvedomainname must return an IPv4 address.',
        );
        self::assertWhmSuccess($whm);
    }

    public function testWhmGetNameserverConfig(): void
    {
        $whm = self::whm();
        $result = self::safely(static fn (): array => $whm->dns()->getNameserverConfig());

        self::assertTrue(array_key_exists('nameservers', $result), 'get_nameserver_config must list nameservers.');
        foreach ($result['nameservers'] as $nameserver) {
            self::assertTrue($nameserver !== '', 'Configured nameservers must be nonempty hostnames.');
        }

        self::assertWhmSuccess($whm);
    }

    public function testWhmLookupNameserverIp(): void
    {
        $nameserver = self::nameserver();
        $whm = self::whm(nameserver: $nameserver);
        $result = self::safely(static fn (): array => $whm->dns()->lookupNameserverIp($nameserver));

        self::assertTrue(
            filter_var($result['ip'] ?? '', FILTER_VALIDATE_IP) !== false,
            'lookupnsip must return an IP address.',
        );
        self::assertWhmSuccess($whm);
    }

    public function testWhmLookupNameserverIps(): void
    {
        $nameserver = self::nameserver();
        $whm = self::whm(nameserver: $nameserver);
        $result = self::safely(static fn (): array => $whm->dns()->lookupNameserverIps($nameserver));

        self::assertTrue(
            array_key_exists('ipv4', $result) || array_key_exists('ipv6', $result),
            'lookupnsips must return at least one address family.',
        );
        if (array_key_exists('ipv4', $result)) {
            self::assertTrue(
                filter_var($result['ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
                'lookupnsips ipv4 must be an IPv4 address.',
            );
        }

        // Observed 2026-10-15: ipv6 is an empty string, not omitted, when the nameserver has no IPv6 address.
        if (array_key_exists('ipv6', $result)) {
            self::assertTrue(
                $result['ipv6'] === '' || filter_var($result['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
                'lookupnsips ipv6 must be an IPv6 address or empty.',
            );
        }

        self::assertWhmSuccess($whm);
    }

    public function testWhmHasLocalAuthority(): void
    {
        $whm = self::whm();
        $result = self::safely(static fn (): array => $whm->dns()->hasLocalAuthority(self::ZONE));

        self::assertAuthority($result['records'] ?? []);
        self::assertWhmSuccess($whm);
    }

    /** @param Closure(WhmDns): array<string, mixed> $operation */
    #[DataProvider('whmAvailability')]
    public function testWhmRecordTypeAvailability(Closure $operation, string $flag): void
    {
        $whm = self::whm();
        $result = self::safely(static fn (): array => $operation($whm->dns()));

        self::assertTrue(array_key_exists($flag, $result), 'The availability flag must be present.');
        self::assertWhmSuccess($whm);
    }

    /** @return iterable<string, array{Closure(WhmDns): array<string, mixed>, string}> */
    public static function whmAvailability(): iterable
    {
        yield 'ALIAS' => [static fn (WhmDns $dns): array => $dns->isAliasAvailable(), 'alias'];
        yield 'HTTPS' => [static fn (WhmDns $dns): array => $dns->isHttpsAvailable(), 'https'];
        yield 'SVCB' => [static fn (WhmDns $dns): array => $dns->isSvcbAvailable(), 'svcb'];
    }

    public function testWhmExportZoneFiles(): void
    {
        $whm = self::whm();
        $result = self::safely(static fn (): array => $whm->dns()->exportZoneFiles(self::ZONE));
        $payload = $result['payload'] ?? [];

        self::assertTrue(count($payload) === 1, 'export_zone_files must return the one requested zone.');
        self::assertTrue(($payload[0]['zone'] ?? null) === self::ZONE, 'The exported zone must be the test zone.');
        $text = base64_decode($payload[0]['text_b64'] ?? '', true);
        self::assertTrue($text !== false && $text !== '', 'The exported zone text must be nonempty base64.');
        self::assertWhmSuccess($whm);
    }

    public function testWhmFetchDsRecordsForDomains(): void
    {
        $whm = self::whm();
        $result = self::safely(static fn (): array => $whm->dns()->fetchDsRecordsForDomains(self::ZONE));

        self::assertTrue(array_key_exists('domains', $result), 'fetch_ds_records_for_domains must list domains.');
        foreach ($result['domains'] as $domain) {
            self::assertTrue(($domain['domain'] ?? null) === self::ZONE, 'DS records must belong to the test zone.');
        }

        self::assertWhmSuccess($whm);
    }

    #[DataProvider('routes')]
    public function testUapiParseZone(bool $direct): void
    {
        [$client, $account] = self::uapi($direct);
        $zone = self::safely(static fn (): array => $account->dns()->parseZone(self::ZONE));

        self::assertParsedZone($zone);
        self::assertUapiSuccess($client, $account, $direct);
    }

    #[DataProvider('routes')]
    public function testUapiHasLocalAuthority(bool $direct): void
    {
        [$client, $account] = self::uapi($direct);
        $records = self::safely(static fn (): array => $account->dns()->hasLocalAuthority(self::ZONE));

        self::assertAuthority($records);
        self::assertUapiSuccess($client, $account, $direct);
    }

    #[DataProvider('routes')]
    public function testUapiEnsureDomainsResideOnlyLocally(bool $direct): void
    {
        [$client, $account] = self::uapi($direct);
        $results = self::safely(static fn (): ?array => $account->dns()->ensureDomainsResideOnlyLocally(self::ZONE));

        self::assertTrue($results === null || count($results) === 1, 'Expected one result for the one domain.');
        self::assertUapiSuccess($client, $account, $direct);
    }

    #[DataProvider('routes')]
    public function testUapiEnsureDomainsUseRecognizedNameservers(bool $direct): void
    {
        [$client, $account] = self::uapi($direct);
        $results = self::safely(
            static fn (): ?array => $account->dns()->ensureDomainsUseRecognizedNameservers(self::ZONE),
        );

        self::assertTrue($results === null || count($results) === 1, 'Expected one result for the one domain.');
        foreach ($results ?? [] as $result) {
            self::assertTrue(
                $result === null
                    || in_array($result['type'] ?? null, ['domain_not_registered', 'unrecognized_nameservers'], true),
                'A failed nameserver check must carry a documented reason code.',
            );
        }

        self::assertUapiSuccess($client, $account, $direct);
    }

    /** @param Closure(UapiDns): array<string, mixed> $operation */
    #[DataProvider('uapiAvailability')]
    public function testUapiRecordTypeAvailability(bool $direct, Closure $operation, string $flag): void
    {
        [$client, $account] = self::uapi($direct);
        $result = self::safely(static fn (): array => $operation($account->dns()));

        self::assertTrue(array_key_exists($flag, $result), 'The availability flag must be present.');
        self::assertUapiSuccess($client, $account, $direct);
    }

    /** @return iterable<string, array{bool, Closure(UapiDns): array<string, mixed>, string}> */
    public static function uapiAvailability(): iterable
    {
        $operations = [
            'ALIAS' => [static fn (UapiDns $dns): array => $dns->isAliasAvailable(), 'alias'],
            'HTTPS' => [static fn (UapiDns $dns): array => $dns->isHttpsAvailable(), 'https'],
            'SVCB' => [static fn (UapiDns $dns): array => $dns->isSvcbAvailable(), 'svcb'],
        ];
        foreach ([true, false] as $direct) {
            foreach ($operations as $name => [$operation, $flag]) {
                yield ($direct ? 'direct ' : 'bridge ') . $name => [$direct, $operation, $flag];
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function routes(): iterable
    {
        yield 'direct' => [true];
        yield 'bridge' => [false];
    }

    /** @param ZoneLines $items */
    private static function assertParsedZone(array $items): void
    {
        self::assertTrue($items !== [], 'The parsed test zone must contain lines.');
        foreach ($items as $item) {
            self::assertTrue(
                in_array($item['type'] ?? null, ['record', 'control', 'comment'], true),
                'Every parsed line must carry a documented type.',
            );
            foreach ([$item['dname_b64'] ?? '', $item['text_b64'] ?? '', ...$item['data_b64'] ?? []] as $encoded) {
                self::assertTrue(base64_decode($encoded, true) !== false, 'Encoded zone fields must be base64.');
            }
        }

        self::firstRecord($items);
    }

    /**
     * Return the line index and record type of the first resource record.
     *
     * @param ZoneLines $items
     * @return array{int, string}
     */
    private static function firstRecord(array $items): array
    {
        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'record') {
                continue;
            }

            $lineIndex = $item['line_index'] ?? null;
            $recordType = $item['record_type'] ?? null;
            self::assertTrue(
                $lineIndex !== null && $recordType !== null && isset($item['dname_b64'], $item['data_b64']),
                'A parsed resource record must carry its line, type, owner and data.',
            );

            return [(int) $lineIndex, (string) $recordType];
        }

        self::fail('The parsed test zone must contain a resource record.');
    }

    /** @param AuthorityRecords $records */
    private static function assertAuthority(array $records): void
    {
        self::assertTrue(count($records) === 1, 'Expected one authority record for the one domain.');
        self::assertTrue(($records[0]['domain'] ?? null) === self::ZONE, 'The authority record must be the test zone.');
        self::assertTrue(
            array_key_exists('local_authority', $records[0]),
            'The authority record must report local authority.',
        );
    }

    private static function assertWhmSuccess(WhmClient $whm): void
    {
        $metadata = $whm->responseMeta();
        self::assertTrue($metadata?->httpStatus === 200, 'Expected HTTP 200.');
        self::assertTrue(($metadata?->whm['result'] ?? null) === 1, 'Expected WHM success.');
        self::assertTrue($metadata->uapi === null, 'WHM DNS must retain only WHM metadata.');
    }

    private static function assertUapiSuccess(Client $client, UapiClient $account, bool $direct): void
    {
        $metadata = $account->responseMeta();
        self::assertTrue($metadata?->httpStatus === 200, 'Expected HTTP 200.');
        self::assertTrue(($metadata?->uapi['status'] ?? null) === 1, 'Expected UAPI success.');
        self::assertTrue($account->isBridged() === !$direct, 'Expected configured account route.');
        self::assertTrue($account->username() === 'npr', 'Expected fixed test account.');
        if ($direct) {
            self::assertTrue($metadata->whm === null, 'Direct UAPI must not retain WHM metadata.');
        } else {
            self::assertTrue(($metadata?->whm['result'] ?? null) === 1, 'Expected outer WHM success.');
            self::assertNull($client->whm()->responseMeta());
        }
    }

    /** Derive a nameserver host from get_nameserver_config, or skip when none is configured. */
    private static function nameserver(): string
    {
        $whm = self::whm();
        $nameservers = self::safely(static fn (): array => $whm->dns()->getNameserverConfig())['nameservers'] ?? [];
        if ($nameservers === []) {
            self::markTestSkipped('get_nameserver_config returned no nameserver host to look up.');
        }

        return $nameservers[0];
    }

    /** A WHM handle whose transport also approves the derived zone line or nameserver host. */
    private static function whm(?int $zoneLine = null, ?string $nameserver = null): WhmClient
    {
        return self::client(false, new LiveCurlClient(zoneLine: $zoneLine, nameserver: $nameserver))->whm();
    }

    /** @return array{Client, UapiClient} */
    private static function uapi(bool $direct): array
    {
        $client = self::client($direct, new LiveCurlClient());

        return [$client, $client->uapi('npr')];
    }

    private static function client(bool $direct, LiveCurlClient $http): Client
    {
        $tokens = LiveTestEnvironment::fromEnvironment();
        $factory = new Psr17Factory();

        return new Client([
            'host' => 'test.oblak.host',
            'port' => $direct ? 2083 : 2087,
            'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'npr' : 'root',
            'token' => $direct ? $tokens['cpanel'] : $tokens['whm'],
        ], new HttpTransport($http, $factory, $factory));
    }

    /**
     * Do not expose upstream exception messages, payloads or credentials.
     *
     * @template TResult
     * @param Closure(): TResult $operation
     * @return TResult
     */
    private static function safely(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $error) {
            $metadata = $error instanceof ApiException ? $error->metadata : null;
            $whm = $metadata?->whm['result'] ?? null;
            $uapi = $metadata?->uapi['status'] ?? null;
            self::fail(sprintf(
                'Live DNS operation failed (%s; HTTP %s; WHM %s; UAPI %s).',
                $error::class,
                $metadata === null ? 'unavailable' : (string) $metadata->httpStatus,
                is_int($whm) ? (string) $whm : 'unavailable',
                is_int($uapi) ? (string) $uapi : 'unavailable',
            ));
        }
    }
}
