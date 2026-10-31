<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
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

    private const PAIRS = ['start' => 0, 'separator' => '-', 'first_unsuffixed' => true, 'fields' => [
        'group' => ['prefix' => 'group', 'type' => 'string', 'required' => false],
        'backend' => ['prefix' => 'backend', 'type' => 'string', 'required' => true],
    ]];

    public function testFirstUnsuffixedPairsPreserveValuesOrderAndPositions(): void
    {
        $params = ['rows' => ['name' => 'rows', 'encoding' => 'records', 'records' => self::PAIRS]];
        self::assertSame(self::RECORDS, RecordEncoder::check(self::RECORDS));
        self::assertSame(self::PAIRS, RecordEncoder::check(self::PAIRS));
        foreach (
            [
            [[['group' => 'Mail', 'backend' => 'mx1']], 'group=Mail&backend=mx1'],
            [
            [['group' => 'Mail', 'backend' => 'mx1'], ['group' => 'Mail', 'backend' => 'mx2']],
                'group=Mail&backend=mx1&group-1=Mail&backend-1=mx2'],
            [
            [['backend' => ''], ['group' => 'Mail', 'backend' => 'mx2'], ['backend' => 'mx2']],
                'backend=&group-1=Mail&backend-1=mx2&backend-2=mx2'],
            ] as [$rows, $expected]
        ) {
            self::assertSame($expected, ArgumentEncoder::encode($params, ['rows' => $rows])->encode());
        }
    }

    /** @return iterable<array{mixed}> */
    public static function malformedFirstUnsuffixed(): iterable
    {
        foreach ([false, null, 1, 'true', [], new \stdClass()] as $bad) {
            yield [['first_unsuffixed' => $bad] + self::PAIRS];
        }
        yield [['start' => 1] + self::PAIRS];
        yield [['separator' => ''] + self::PAIRS];
    }

    #[DataProvider('malformedFirstUnsuffixed')]
    public function testRejectsMalformedFirstUnsuffixedWithoutArguments(mixed $descriptor): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'fixture', 'method' => 'POST', 'outcome' => 'flag',
            'params' => ['rows' => ['name' => 'rows', 'encoding' => 'records', 'records' => $descriptor]],
            'returns' => null]);
    }

    /** @return iterable<array{array<string, mixed>, array<string, mixed>}> */
    public static function firstUnsuffixedCollisions(): iterable
    {
        $record = ['name' => 'rows', 'encoding' => 'records', 'records' => self::PAIRS];
        $rows = [['group' => 'Mail', 'backend' => 'mx1'], ['group' => 'Mail', 'backend' => 'mx2']];
        foreach (
            [
            [['name' => 'group', 'encoding' => 'scalar'], 'Mail'],
            [['name' => 'backend-1', 'encoding' => 'scalar'], 'mx2'],
            [['name' => 'group', 'encoding' => 'repeat'], ['Mail']],
            [['name' => 'group', 'encoding' => 'numbered'], ['Mail']],
            [['name' => 'other', 'encoding' => 'collection', 'collection' => ['kind' => 'indexed',
                'item_type' => 'string', 'empty' => 'reject', 'allow_scalar' => false,
                'prefix' => 'backend', 'separator' => '-', 'start' => 1]], ['mx2']],
            [['name' => 'other', 'encoding' => 'records', 'records' => self::PAIRS], $rows],
            ] as [$other, $value]
        ) {
            yield [['rows' => $record, 'other' => $other], ['rows' => $rows, 'other' => $value]];
            yield [['other' => $other, 'rows' => $record], ['other' => $value, 'rows' => $rows]];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('firstUnsuffixedCollisions')]
    public function testFirstUnsuffixedConcreteCollisions(array $params, array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode($params, $arguments);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('firstUnsuffixedCollisions')]
    public function testFirstUnsuffixedStaticCollisionsEvenWhenArgumentsOmitted(array $params, array $arguments): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'fixture', 'method' => 'POST', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
    }

    public function testFirstUnsuffixedMapsCheckActualKeysAndDisjointFamiliesStayValid(): void
    {
        $params = ['rows' => ['name' => 'rows', 'encoding' => 'records', 'records' => self::PAIRS],
            'other' => ['name' => 'other', 'encoding' => 'collection', 'collection' => ['kind' => 'map',
                'item_type' => 'string', 'empty' => 'allow', 'allow_scalar' => false, 'prefix' => '']]];
        SpecChecker::check(['function' => 'fixture', 'method' => 'POST', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
        foreach (['group', 'backend-1'] as $name) {
            foreach ([false, true] as $reverse) {
                try {
                    ArgumentEncoder::encode(
                        $reverse ? array_reverse($params) : $params,
                        ['rows' => [['group' => 'Mail', 'backend' => 'mx1'], ['backend' => 'mx2']],
                        'other' => [$name => 'private']],
                    );
                    self::fail('Map collision accepted.');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringNotContainsString('private', $exception->getMessage());
                }
            }
        }
        $params['other'] = ['name' => 'group*', 'encoding' => 'suffix-numbered'];
        SpecChecker::check(['function' => 'fixture', 'method' => 'POST', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
        self::assertSame('group=Mail&backend=mx1&group1=tail', ArgumentEncoder::encode(
            $params,
            ['rows' => [['group' => 'Mail', 'backend' => 'mx1']], 'other' => ['tail']],
        )->encode());
    }

    public function testInvalidPrivatePairValueIsRedactedFromExceptionFrames(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            ArgumentEncoder::encode(['rows' => ['name' => 'rows', 'encoding' => 'records',
                'records' => self::PAIRS]], ['rows' => [['backend' => ['private-pair-value']]]]);
            self::fail('Invalid row accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('private-pair-value', $exception->getMessage());
            self::assertStringNotContainsString('private-pair-value', print_r($exception->getTrace(), true));
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return iterable<array{mixed}> */
    public static function invalidPairs(): iterable
    {
        foreach (
            [null, [], 'private', [1 => ['backend' => 'mx']], ['row' => ['backend' => 'mx']],
            [[]], [1], [['mx']], [['group' => 'Mail']], [['backend' => null]], [['backend' => 1]],
            [['backend' => false]], [['backend' => 1.5]], [['backend' => new \stdClass()]],
            [['backend' => 'mx', 'unknown' => 'private']]] as $value
        ) {
            yield [$value];
        }
    }

    #[DataProvider('invalidPairs')]
    public function testFirstUnsuffixedRejectsMalformedListsRowsAndScalars(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode(['rows' => ['name' => 'rows', 'encoding' => 'records',
            'records' => self::PAIRS]], ['rows' => $value]);
    }

    public function testFirstUnsuffixedRejectsIdenticalFieldPrefixes(): void
    {
        $descriptor = self::PAIRS;
        $descriptor['fields']['backend']['prefix'] = 'group';
        $this->expectException(LogicException::class);
        RecordEncoder::check($descriptor);
    }

    public function testFirstUnsuffixedBaseCollidesWithLegacySuffixTailInBothOrders(): void
    {
        $descriptor = self::PAIRS;
        $descriptor['fields']['group']['prefix'] = 'group1';
        $params = ['rows' => ['name' => 'rows', 'encoding' => 'records', 'records' => $descriptor],
            'other' => ['name' => 'group*', 'encoding' => 'suffix-numbered']];
        foreach ([false, true] as $reverse) {
            $ordered = $reverse ? array_reverse($params) : $params;
            try {
                SpecChecker::check(['function' => 'fixture', 'method' => 'POST', 'outcome' => 'flag',
                    'params' => $ordered, 'returns' => null]);
                self::fail('Static suffix collision accepted.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('Overlapping', $exception->getMessage());
            }
            try {
                ArgumentEncoder::encode(
                    $ordered,
                    ['rows' => [['group' => 'Mail', 'backend' => 'mx']], 'other' => ['Mail']],
                );
                self::fail('Concrete suffix collision accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('overlap', $exception->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private static function params(): array
    {
        return ['settings' => ['name' => 'settings', 'encoding' => 'records', 'records' => self::RECORDS]];
    }
}
