<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\RecordEncoder;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordInputTest extends TestCase
{
    public const RECORDS = ['start' => 1, 'separator' => '', 'fields' => [
        'settingId' => ['prefix' => 'setting_id', 'type' => 'int', 'required' => true],
        'state' => ['prefix' => 'state', 'type' => 'string|int', 'required' => true],
        'remove' => ['prefix' => 'remove', 'type' => 'bool', 'required' => false],
    ]];

    public function testPreservesOriginalRowPositionsAndDeclaredFieldOrder(): void
    {
        self::assertSame(
            'setting_id1=1&state1=On&setting_id2=1&state2=Off&remove2=0',
            ArgumentEncoder::encode(self::params(), ['settings' => [
                ['state' => 'On', 'settingId' => 1],
                ['remove' => false, 'state' => 'Off', 'settingId' => 1],
            ]])->encode(),
        );
    }

    /** @return iterable<array{mixed}> */
    public static function invalid(): iterable
    {
        foreach (
            [
            [], 'bad', [1 => ['settingId' => 1, 'state' => 'On']], ['x' => []], [[]], [[1, 'On']],
            [1], [['settingId' => '1', 'state' => 'On']], [['settingId' => 1]],
            [['settingId' => 1, 'remove' => true]], [['state' => 'On']],
            [['settingId' => 1, 'state' => false]], [['settingId' => 1, 'state' => 1.2]],
            [['settingId' => 1, 'state' => null]], [['settingId' => 1, 'state' => '', 'remove' => null]],
            [['settingId' => 1, 'state' => '', 'remove' => 0]],
            [['settingId' => 1, 'state' => '', 'remove' => '0']],
            [['settingId' => 1, 'state' => '', 'other' => 'private']]] as $input
        ) {
            yield [$input];
        }
    }

    #[DataProvider('invalid')]
    public function testStrictRows(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode(self::params(), ['settings' => $input]);
    }

    /** @return iterable<array{mixed}> */
    public static function malformed(): iterable
    {
        foreach (
            [null, [], 'bad', ['start' => -1] + self::RECORDS,
            ['start' => 1.0] + self::RECORDS, ['start' => 2147483648] + self::RECORDS,
            ['separator' => '.'] + self::RECORDS, ['fields' => []] + self::RECORDS,
            ['unknown' => true] + self::RECORDS] as $spec
        ) {
            yield [$spec];
        }
        foreach (array_keys(self::RECORDS) as $key) {
            $spec = self::RECORDS;
            unset($spec[$key]);
            yield [$spec];
        }
        foreach (['prefix', 'type', 'required'] as $key) {
            $spec = self::RECORDS;
            unset($spec['fields']['state'][$key]);
            yield [$spec];
        }
        foreach (
            [
            ['type' => 'float'], ['prefix' => ''], ['prefix' => 'a.b'],
            ['required' => 0], ['required' => null], ['unknown' => true],
            ['prefix' => 'setting_id'], ['prefix' => 'setting_id1']] as $bad
        ) {
            $spec = self::RECORDS;
            $spec['fields']['state'] = $bad + $spec['fields']['state'];
            yield [$spec];
        }
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedDescriptorsEvenWithoutArguments(mixed $descriptor): void
    {
        $params = ['settings' => ['name' => 'settings', 'encoding' => 'records', 'records' => $descriptor]];
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'fixture', 'method' => 'POST', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
    }

    /** @return iterable<array{array<string, mixed>, array<string, mixed>}> */
    public static function collisions(): iterable
    {
        $record = ['name' => 'settings', 'encoding' => 'records', 'records' => self::RECORDS];
        $rows = [['settingId' => 1, 'state' => 'On']];
        foreach (
            [
            [['name' => 'state1', 'encoding' => 'scalar'], 'On'],
            [['name' => 'state1', 'encoding' => 'repeat'], ['On', 'On']],
            [['name' => 'other', 'encoding' => 'collection', 'collection' => ['kind' => 'map',
                'item_type' => 'string', 'empty' => 'allow',
                'allow_scalar' => false, 'prefix' => '']], ['state1' => 'On']],
            [['name' => 'other', 'encoding' => 'collection', 'collection' => ['kind' => 'indexed',
                'item_type' => 'string', 'empty' => 'reject', 'allow_scalar' => false, 'prefix' => 'state',
                'separator' => '', 'start' => 1]], ['On']],
            [['name' => 'other', 'encoding' => 'records', 'records' => self::RECORDS], $rows],
            ] as [$other, $value]
        ) {
            yield [['settings' => $record, 'other' => $other], ['settings' => $rows, 'other' => $value]];
            yield [['other' => $other, 'settings' => $record], ['other' => $value, 'settings' => $rows]];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('collisions')]
    public function testRejectsConcreteCollisionsInBothOrders(array $params, array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode($params, $arguments);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('collisions')]
    public function testKnownRecordOverlapsFailExactlyBeforeDispatch(array $params, array $arguments): void
    {
        $other = $params['other'];
        if (
            is_array($other) && is_array($other['collection'] ?? null)
            && ($other['collection']['kind'] ?? null) === 'map'
        ) {
            $this->expectException(InvalidArgumentException::class);
            ArgumentEncoder::encode($params, $arguments);
            return;
        }
        $calls = 0;
        $caught = null;
        try {
            OperationRunner::run(['function' => 'fixture', 'method' => 'POST', 'outcome' => 'flag',
                'params' => $params, 'returns' => null], [], static function () use (&$calls): never {
                    $calls++;
                    throw new \RuntimeException('Dispatch must not run.');
                });
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertSame(LogicException::class, $caught === null ? null : $caught::class);
        self::assertSame(0, $calls);
    }

    public function testRoutingReservationsRejectConcreteRecords(): void
    {
        $fields = ArgumentEncoder::encode(self::params(), ['settings' => [['settingId' => 1, 'state' => 'On']]]);
        $this->expectException(InvalidArgumentException::class);
        $fields->withRouting(['state1' => 'route']);
    }

    public function testRecordDescriptorCannotAttachToScalarOrCollectionEncoding(): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'fixture', 'method' => 'POST', 'outcome' => 'flag',
            'params' => ['settings' => ['name' => 'settings', 'encoding' => 'scalar', 'records' => self::RECORDS]],
            'returns' => null]);
    }

    public function testLeadingZeroPrefixDoesNotOverlapCanonicalIndexes(): void
    {
        $spec = self::RECORDS;
        $spec['fields']['state']['prefix'] = 'setting_id0';
        self::assertSame($spec, RecordEncoder::check($spec));
    }

    /** @return array<string, mixed> */
    private static function params(): array
    {
        return ['settings' => ['name' => 'settings', 'encoding' => 'records', 'records' => self::RECORDS]];
    }
}
