<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\Fingerprint;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    public function testUnknownKeysAndValuesNeverEscapeAndNestedUnknownNamesStayWildcarded(): void
    {
        $payload = (object) [
            'known' => 'private-value',
            'mailbox@example.invalid' => (object) ['password-secret' => [(object) ['domain.invalid' => 4]]],
        ];

        self::assertSame([
            'paths' => [
                '$' => ['object'],
                '$.*' => ['object'],
                '$.*.*' => ['list'],
                '$.*.*[]' => ['object'],
                '$.*.*[].*' => ['int'],
                '$.known' => ['string'],
                '$.optional' => ['missing'],
                '$.required' => ['missing'],
            ],
            'discrepancies' => [
                ['path' => '$.*', 'kind' => 'unknown_field'],
                ['path' => '$.required', 'kind' => 'missing_required'],
            ],
        ], Fingerprint::analyze($payload, true, [
            'type' => 'object',
            'fields' => [
                'known' => ['type' => 'string'],
                'optional' => ['type' => 'string'],
                'required' => ['type' => 'int'],
            ],
            'required' => ['required'],
        ]));
    }

    public function testListsAggregateWireTypesWithoutRetainingIndicesOrFabricatingEmptyItems(): void
    {
        self::assertSame([
            'paths' => [
                '$' => ['list'],
                '$[]' => ['empty-object', 'object'],
                '$[].value' => ['empty-string', 'missing', 'null', 'numeric-string'],
            ],
            'discrepancies' => [],
        ], Fingerprint::analyze([
            (object) ['value' => '12'],
            (object) ['value' => ''],
            (object) ['value' => null],
            (object) [],
        ], true, [
            'type' => 'list',
            'items' => ['type' => 'object', 'fields' => ['value' => ['type' => 'string', 'nullable' => true]]],
        ]));

        self::assertSame([
            'paths' => ['$' => ['empty-list']],
            'discrepancies' => [],
        ], Fingerprint::analyze([], true, ['type' => 'list', 'items' => ['type' => 'int']]));
    }

    public function testAcceptedWireVariantsAreObservedBeforeNormalization(): void
    {
        self::assertSame([
            'paths' => [
                '$' => ['object'],
                '$.boolean' => ['int'],
                '$.flag' => ['bool'],
                '$.integer' => ['numeric-string'],
                '$.number' => ['numeric-string'],
                '$.stringFlag' => ['numeric-string'],
            ],
            'discrepancies' => [],
        ], Fingerprint::analyze((object) [
            'integer' => '12',
            'number' => '+0012.5',
            'boolean' => 1,
            'flag' => false,
            'stringFlag' => '0',
        ], true, [
            'type' => 'object',
            'fields' => [
                'integer' => ['type' => 'int', 'accept' => ['numeric-string']],
                'number' => ['type' => 'number', 'accept' => ['numeric-string'], 'preserve' => true],
                'boolean' => ['type' => 'bool', 'accept' => ['int-flag']],
                'flag' => ['type' => 'flag', 'accept' => ['bool']],
                'stringFlag' => ['type' => 'bool', 'accept' => ['string-flag']],
            ],
        ]));
    }

    public function testInvalidWireVariantsAreReportedAtTheirOwnPaths(): void
    {
        $result = Fingerprint::analyze((object) ['integer' => '01', 'boolean' => 2, 'flag' => 9], true, [
            'type' => 'object',
            'fields' => [
                'integer' => ['type' => 'int', 'accept' => ['numeric-string']],
                'boolean' => ['type' => 'bool', 'accept' => ['int-flag']],
                'flag' => ['type' => 'flag'],
            ],
        ]);

        self::assertSame([
            ['path' => '$.boolean', 'kind' => 'type_mismatch'],
            ['path' => '$.flag', 'kind' => 'type_mismatch'],
            ['path' => '$.integer', 'kind' => 'type_mismatch'],
        ], $result['discrepancies']);
    }

    public function testMapsWildcardDynamicKeysButRetainDeclaredValueFields(): void
    {
        self::assertSame([
            'paths' => [
                '$' => ['object'],
                '$.*' => ['object'],
                '$.*.enabled' => ['bool'],
            ],
            'discrepancies' => [],
        ], Fingerprint::analyze((object) [
            'private@example.invalid' => (object) ['enabled' => true],
        ], true, ['type' => 'map', 'values' => [
            'type' => 'object', 'fields' => ['enabled' => ['type' => 'bool']],
        ]]));
    }

    public function testEmptyContainersAndOmissionRemainDistinctFromNull(): void
    {
        self::assertSame([
            'paths' => ['$' => ['empty-object'], '$.optional' => ['missing']],
            'discrepancies' => [],
        ], Fingerprint::analyze((object) [], true, [
            'type' => 'object', 'fields' => ['optional' => ['type' => 'string']],
        ]));
        self::assertSame([
            'paths' => ['$' => ['empty-list'], '$.optional' => ['missing']],
            'discrepancies' => [],
        ], Fingerprint::analyze([], true, [
            'type' => 'object', 'fields' => ['optional' => ['type' => 'string']],
        ]));
        self::assertSame([
            'paths' => ['$' => ['empty-list']],
            'discrepancies' => [],
        ], Fingerprint::analyze([], true, ['type' => 'map', 'values' => ['type' => 'int']]));
        self::assertSame([
            'paths' => ['$' => ['empty-list']],
            'discrepancies' => [['path' => '$', 'kind' => 'type_mismatch']],
        ], Fingerprint::analyze([], true, [
            'type' => 'object', 'fields' => ['id' => ['type' => 'int']], 'required' => ['id'],
        ]));
        self::assertSame([
            'paths' => ['$' => ['null']],
            'discrepancies' => [],
        ], Fingerprint::analyze(null, true, ['type' => 'int', 'nullable' => true]));
        self::assertSame([
            'paths' => ['$' => ['missing']],
            'discrepancies' => [['path' => '$', 'kind' => 'missing_required']],
        ], Fingerprint::analyze(null, false, ['type' => 'int', 'nullable' => true]));
    }

    public function testMixedAndInvalidStructuresNeverLeakUndeclaredNames(): void
    {
        self::assertSame([
            'paths' => ['$' => ['object'], '$.*' => ['float']],
            'discrepancies' => [],
        ], Fingerprint::analyze((object) ['customer@example.invalid' => 2.5], true, ['type' => 'mixed']));
        self::assertSame([
            'paths' => ['$' => ['object'], '$.*' => ['bool']],
            'discrepancies' => [['path' => '$', 'kind' => 'type_mismatch']],
        ], Fingerprint::analyze((object) ['secret-name' => true], true, ['type' => 'int']));
    }

    public function testOverflowedJsonNumberStillProducesSanitizedMismatchEvidence(): void
    {
        $payload = json_decode('{"value":1e999}', false, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            'paths' => ['$' => ['object'], '$.value' => ['nonfinite-number']],
            'discrepancies' => [['path' => '$.value', 'kind' => 'type_mismatch']],
        ], Fingerprint::analyze($payload, true, [
            'type' => 'object', 'fields' => ['value' => ['type' => 'number']],
        ]));
    }

    public function testUnionsChooseCompatibleWireBranchesWithoutFalseMismatches(): void
    {
        $type = ['type' => 'union', 'anyOf' => [
            ['type' => 'int', 'accept' => ['numeric-string']],
            ['type' => 'list', 'items' => ['type' => 'bool']],
        ]];
        self::assertSame([
            'paths' => ['$' => ['numeric-string']],
            'discrepancies' => [],
        ], Fingerprint::analyze('12', true, $type));
        self::assertSame([
            'paths' => ['$' => ['list'], '$[]' => ['bool']],
            'discrepancies' => [],
        ], Fingerprint::analyze([true, false], true, $type));
        self::assertSame([
            'paths' => ['$' => ['object'], '$.*' => ['string']],
            'discrepancies' => [['path' => '$', 'kind' => 'type_mismatch']],
        ], Fingerprint::analyze((object) ['private@example.invalid' => 'secret'], true, $type));
    }
}
