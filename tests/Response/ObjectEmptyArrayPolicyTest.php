<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use HostingPal\WhmApiClient\Tools\LiveVerify\Fingerprint;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectEmptyArrayPolicyTest extends TestCase
{
    public function testExplicitObjectPolicyPreservesDefaultsAndDistinguishesEmptyContainers(): void
    {
        $read = new PayloadReader(new ResponseMetadata(200), 'fixture');
        $object = ['type' => 'object', 'fields' => []];
        self::assertSame([], ShapeValidator::value([], $object, $read));
        self::assertSame([], ShapeValidator::value([], $object + ['accept_empty_array' => true], $read));
        self::assertSame([], ShapeValidator::value((object) [], $object + ['accept_empty_array' => false], $read));
        $this->expectException(InvalidResponseException::class);
        ShapeValidator::value([], $object + ['accept_empty_array' => false], $read);
    }

    public function testNestedPolicyAndFingerprintAgreeWithoutWeakeningSiblingShapes(): void
    {
        $strict = ['type' => 'object', 'fields' => [], 'accept_empty_array' => false];
        self::assertSame(
            [['path' => '$', 'kind' => 'type_mismatch']],
            Fingerprint::analyze([], true, $strict)['discrepancies'],
        );
        self::assertSame([], Fingerprint::analyze((object) [], true, $strict)['discrepancies']);
        $root = ['type' => 'object', 'fields' => [
            'strict' => $strict, 'legacy' => ['type' => 'object', 'fields' => []],
        ]];
        self::assertSame([], Fingerprint::analyze((object) ['strict' => (object) [], 'legacy' => []], true, $root)
            ['discrepancies']);
        self::assertSame(
            [['path' => '$.strict', 'kind' => 'type_mismatch']],
            Fingerprint::analyze((object) ['strict' => [], 'legacy' => []], true, $root)['discrepancies'],
        );
        self::assertSame(
            [['path' => '$[]', 'kind' => 'type_mismatch']],
            Fingerprint::analyze([[]], true, ['type' => 'list', 'items' => $strict])['discrepancies'],
        );
        self::assertSame([], Fingerprint::analyze([], true, ['type' => 'map', 'values' => $strict])['discrepancies']);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function badTypes(): iterable
    {
        yield 'nonboolean' => [['type' => 'object', 'fields' => [], 'accept_empty_array' => 0]];
        yield 'scalar' => [['type' => 'string', 'accept_empty_array' => false]];
        yield 'list child' => [['type' => 'list', 'items' => ['type' => 'int', 'accept_empty_array' => true]]];
        yield 'map child' => [['type' => 'map', 'values' => ['type' => 'int', 'accept_empty_array' => false]]];
        yield 'union child' => [['type' => 'union', 'anyOf' => [['type' => 'mixed', 'accept_empty_array' => false]]]];
    }

    /** @param array<mixed> $type */
    #[DataProvider('badTypes')]
    public function testCheckerRejectsInvalidPolicyRecursively(array $type): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'fixture', 'method' => 'GET', 'outcome' => 'flag', 'params' => [],
            'returns' => $type]);
    }
}
