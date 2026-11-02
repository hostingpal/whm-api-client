<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsInspection;
use PHPUnit\Framework\TestCase;

/** Contracts here are written by hand, so these tests never move with the generated specs. */
final class DomainRowsInspectionTest extends TestCase
{
    /** A row union like domains_data: an object branch first, then a list of rows. */
    private const ROWS = ['type' => 'union', 'anyOf' => [
        ['type' => 'object', 'fields' => ['main_domain' => ['type' => 'string']]],
        ['type' => 'list', 'items' => ['type' => 'object', 'fields' => [
            'domain' => ['type' => 'string'],
            'type' => ['type' => 'string'],
            'port' => ['type' => 'int', 'accept' => ['numeric-string']],
            'customlog' => ['type' => 'list', 'items' => ['type' => 'object', 'fields' => [
                'format' => ['type' => 'string'], 'target' => ['type' => 'string'],
            ]]],
        ]]],
    ]];

    private const RECORDS = ['type' => 'object', 'fields' => ['records' => ['type' => 'list', 'items' => [
        'type' => 'object', 'fields' => ['domain' => ['type' => 'string'], 'local_authority' => ['type' => 'flag']],
    ]]]];

    private const SUMMARY = ['type' => 'object', 'fields' => [
        'main_domain' => ['type' => 'string', 'nullable' => true],
        'sub_domains' => ['type' => 'list', 'items' => ['type' => 'string']],
    ]];

    public function testListsOnlyUndeclaredKeyNamesAndTypesNeverValues(): void
    {
        $raw = (string) json_encode(['result' => ['status' => 1, 'data' => [
            ['domain' => 'private-domain.example', 'type' => 'addon_domain', 'phpversion' => 'private-php-value',
                'status' => 'private-status-value', 'port' => '80',
                'nested' => ['inner' => 'private-inner', 'private.dotted.key' => 7, '12345' => null]],
            ['domain' => 'private-other.example', 'customlog' => [['target' => 'private', 'format' => 'private']]],
        ]]]);

        $lines = DomainRowsInspection::undeclared('direct-uapi', $raw, self::ROWS);

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
            DomainRowsInspection::undeclared('whm', $whm, self::RECORDS),
        );
        self::assertSame(
            ['undeclared $.records[].extra: bool'],
            DomainRowsInspection::undeclared('bridged-uapi', $bridged, self::RECORDS),
        );
    }

    public function testATypeMismatchNamesOnlyTheDeclaredPathAndTheObservedType(): void
    {
        $raw = (string) json_encode(['status' => 1, 'data' => ['main_domain' => ['private'], 'sub_domains' => []]]);

        self::assertSame(
            ['mismatch $.main_domain: list'],
            DomainRowsInspection::undeclared('direct-uapi', $raw, self::SUMMARY),
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
