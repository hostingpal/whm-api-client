<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\DomainInfoSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\DnsSpec as WhmDnsSpec;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsInspection;
use PHPUnit\Framework\TestCase;

final class DomainRowsInspectionTest extends TestCase
{
    public function testListsOnlyUndeclaredKeyNamesAndTypesNeverValues(): void
    {
        $raw = (string) json_encode(['result' => ['status' => 1, 'data' => [
            ['domain' => 'private-domain.example', 'type' => 'addon_domain', 'phpversion' => 'private-php-value',
                'status' => 'private-status-value', 'port' => '80',
                'nested' => ['inner' => 'private-inner', 'private.dotted.key' => 7, '12345' => null]],
            ['domain' => 'private-other.example', 'customlog' => [['target' => 'private', 'format' => 'private']]],
        ]]]);

        $lines = DomainRowsInspection::undeclared('direct-uapi', $raw, DomainInfoSpec::DOMAINS_DATA['returns']);

        self::assertSame([
            'undeclared $[].nested.<key>: int',
            'undeclared $[].nested.<key>: null',
            'undeclared $[].nested.inner: string',
            'undeclared $[].nested: object',
            'undeclared $[].phpversion: string',
            'undeclared $[].status: string',
        ], $lines);
        $output = implode("\n", $lines);
        foreach (['private', 'dotted', '12345', '80'] as $value) {
            self::assertStringNotContainsString($value, $output);
        }
    }

    public function testReadsTheBridgedAndWhmPayloadsWhereEachRouteHoldsThem(): void
    {
        $records = ['records' => [['domain' => 'private.example', 'local_authority' => 1, 'extra' => true]]];
        $whm = (string) json_encode(['metadata' => ['result' => 1], 'data' => $records]);
        $bridged = (string) json_encode(['metadata' => ['result' => 1],
            'data' => ['uapi' => ['status' => 1, 'data' => $records]]]);

        self::assertSame(
            ['undeclared $.records[].extra: bool'],
            DomainRowsInspection::undeclared('whm', $whm, WhmDnsSpec::HAS_LOCAL_AUTHORITY['returns']),
        );
        self::assertSame(
            ['undeclared $.records[].extra: bool'],
            DomainRowsInspection::undeclared('bridged-uapi', $bridged, WhmDnsSpec::HAS_LOCAL_AUTHORITY['returns']),
        );
    }

    public function testATypeMismatchNamesOnlyTheDeclaredPathAndTheObservedType(): void
    {
        $raw = (string) json_encode(['status' => 1, 'data' => ['main_domain' => ['private'],
            'addon_domains' => [], 'parked_domains' => [], 'sub_domains' => []]]);

        self::assertSame(
            ['mismatch $.main_domain: list'],
            DomainRowsInspection::undeclared('direct-uapi', $raw, DomainInfoSpec::LIST_DOMAINS['returns']),
        );
    }

    /** A void operation has no return contract, so only the kind of data it still sent is named. */
    public function testAVoidContractNamesOnlyTheKindOfDataSent(): void
    {
        $raw = '{"status":1,"data":{"private":"private value"}}';
        self::assertSame(['no return contract: object'], DomainRowsInspection::undeclared('direct-uapi', $raw, []));
        self::assertSame(
            ['no return contract: null'],
            DomainRowsInspection::undeclared('direct-uapi', '{"status":1,"data":null}', []),
        );
    }

    public function testAnUnreadableResponseSaysSoWithoutContent(): void
    {
        self::assertSame(['unreadable'], DomainRowsInspection::undeclared('whm', null, ['type' => 'mixed']));
        $mixed = ['type' => 'mixed'];
        self::assertSame(['unreadable'], DomainRowsInspection::undeclared('whm', 'private <html>', $mixed));
        self::assertSame(['no payload'], DomainRowsInspection::undeclared('whm', '{"metadata":{}}', $mixed));
    }
}
