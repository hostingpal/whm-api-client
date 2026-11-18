<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Api\Engine\OrderedFields;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormRecordTest extends TestCase
{
    private const SPEC = [
        'function' => 'addzonerecord',
        'method' => 'POST',
        'outcome' => 'flag',
        'params' => ['zone' => ['name' => 'domain', 'encoding' => 'scalar']],
        'form_record' => [
            'php' => 'record',
            'discriminator' => 'type',
            'required' => true,
            'variants' => [
                'TXT' => ['txtdata' => ['type' => 'string', 'required' => true]],
                'MX' => [
                    'preference' => ['type' => 'int', 'required' => false],
                    'exchange' => ['type' => 'string', 'required' => true],
                ],
            ],
        ],
        'returns' => null,
    ];

    public function testExpandsOnlyTheSelectedBranchInDeclarationOrder(): void
    {
        self::assertSame('domain=example.test&type=MX&preference=0&exchange=a%26b%2520%2B', self::encode(
            self::SPEC,
            ['record' => ['exchange' => 'a&b%20+', 'preference' => 0, 'type' => 'MX'], 'zone' => 'example.test'],
        ));
        self::assertSame('type=MX&exchange=', self::encode(
            self::SPEC,
            ['record' => ['type' => 'MX', 'exchange' => '']],
        ));
    }

    public function testAnOptionalRecordCanBeOmittedOrNull(): void
    {
        $spec = self::SPEC;
        $spec['form_record']['required'] = false;
        self::assertSame('domain=example.test', self::encode($spec, ['zone' => 'example.test']));
        self::assertSame('domain=example.test', self::encode($spec, ['zone' => 'example.test', 'record' => null]));
    }

    public function testUntaggedEditOmitsTheDiscriminatorAndAcceptsPartOfOneBranch(): void
    {
        $spec = self::editSpec();
        self::assertSame('', self::encode($spec, ['record' => []]));
        self::assertSame('preference=0', self::encode($spec, ['record' => ['preference' => 0]]));
        self::assertSame('type=MX', self::encode($spec, ['record' => ['type' => 'MX']]));
        self::assertSame('exchange=x', self::encode($spec, ['record' => ['exchange' => 'x']]));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidUntagged(): iterable
    {
        yield 'mixed branches' => [['txtdata' => 'x', 'exchange' => 'y']];
        yield 'invalid native type' => [['preference' => '0']];
        yield 'explicit null field' => [['exchange' => null]];
        yield 'explicit null discriminator' => [['type' => null, 'txtdata' => 'x']];
        yield 'unknown explicit discriminator' => [['type' => 'UNKNOWN', 'txtdata' => 'x']];
    }

    /** @param array<string, mixed> $record */
    #[DataProvider('invalidUntagged')]
    public function testUntaggedEditsStillRequireACompatibleVariant(array $record): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationRunner::run(self::editSpec(), ['record' => $record], static fn () => self::fail('Dispatched.'));
    }

    /** @return array<mixed> */
    private static function editSpec(): array
    {
        $spec = self::SPEC;
        $spec['function'] = 'editzonerecord';
        $spec['form_record']['required'] = false;
        $spec['form_record']['untagged'] = true;
        $spec['form_record']['variants']['TXT']['txtdata']['required'] = false;
        $spec['form_record']['variants']['MX']['exchange']['required'] = false;
        return $spec;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidArguments(): iterable
    {
        yield 'omitted required record' => [[]];
        yield 'null required record' => [['record' => null]];
        yield 'scalar' => [['record' => 'private-marker']];
        yield 'empty' => [['record' => []]];
        yield 'numeric keys' => [['record' => ['private-marker']]];
        yield 'null tag' => [['record' => ['type' => null]]];
        yield 'array tag' => [['record' => ['type' => ['private-marker']]]];
        yield 'unknown tag' => [['record' => ['type' => 'private-marker']]];
        yield 'missing required field' => [['record' => ['type' => 'TXT']]];
        yield 'missing tag' => [['record' => ['txtdata' => 'private-marker']]];
        yield 'wrong branch' => [['record' => ['type' => 'TXT', 'txtdata' => 'private-marker', 'exchange' => 'a']]];
        yield 'unknown key' => [['record' => ['type' => 'TXT', 'txtdata' => 'a', 'private-marker' => 's']]];
        yield 'common field' => [['record' => ['type' => 'TXT', 'txtdata' => 'a', 'domain' => 'private-marker']]];
        yield 'routing field' => [['record' => ['type' => 'TXT', 'txtdata' => 'a', 'api.version' => 1]]];
        yield 'explicit null' => [['record' => ['type' => 'MX', 'exchange' => '', 'preference' => null]]];
        yield 'integer string' => [['record' => ['type' => 'MX', 'exchange' => 'private-marker', 'preference' => '0']]];
        yield 'native integer as string field' => [['record' => ['type' => 'TXT', 'txtdata' => 42]]];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidArguments')]
    public function testInvalidRecordFailsBeforeDispatchWithRedactedDiagnostics(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            OperationRunner::run(self::SPEC, $arguments, static fn () => self::fail('Invalid input dispatched.'));
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('private-marker', $exception->getMessage());
            foreach ($exception->getTrace() as $frame) {
                if (($frame['class'] ?? '') === self::class) {
                    break;
                }
                self::assertStringNotContainsString('private-marker', print_r($frame['args'] ?? [], true));
            }
            throw $exception;
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedSpecs(): iterable
    {
        $form = self::SPEC['form_record'];
        $line = ['name' => 'line', 'encoding' => 'scalar', 'positive_int' => true];
        yield 'positive line on add' => [['params' => ['line' => $line]]];
        yield 'positive policy on unrelated field' => [['function' => 'editzonerecord',
            'params' => ['ttl' => ['name' => 'ttl'] + $line]]];
        yield 'non-true positive policy' => [['function' => 'editzonerecord',
            'params' => ['line' => ['positive_int' => false] + $line]]];
        yield 'GET' => [['method' => 'GET']];
        yield 'UAPI' => [['module' => 'DNS']];
        yield 'multipart' => [['body' => 'multipart']];
        yield 'JSON' => [['body' => 'json']];
        yield 'nonscalar companion' => [['params' => ['zone' => ['name' => 'domain', 'encoding' => 'numbered']]]];
        yield 'colliding PHP name' => [['form_record' => ['php' => 'zone'] + $form]];
        yield 'reserved tag wire' => [['form_record' => ['discriminator' => 'api.version'] + $form]];
        yield 'tag collides with common field' => [['form_record' => ['discriminator' => 'domain'] + $form]];
        yield 'invalid required' => [['form_record' => ['required' => 1] + $form]];
        yield 'unexpected descriptor key' => [['form_record' => ['unknown' => true] + $form]];
        yield 'malformed optional untagged policy' => [['form_record' => ['untagged' => false] + $form]];
        yield 'untagged policy with required variant fields' => [['form_record' => ['untagged' => true] + $form]];
        yield 'null form descriptor' => [['form_record' => null]];
        yield 'absent variants' => [['form_record' => ['variants' => []] + $form]];
        foreach (['type', 'domain', 'api.version'] as $wire) {
            yield 'colliding field ' . $wire => [['form_record' => ['variants' => [
                'TXT' => [$wire => ['type' => 'string', 'required' => true]],
            ]] + $form]];
        }
        $invalidFields = [
            ['type' => 'float', 'required' => true],
            ['type' => 'string'],
            ['type' => 'string', 'required' => true, 'unrecognized' => true],
        ];
        foreach ($invalidFields as $i => $invalid) {
            yield 'invalid unused branch ' . $i => [['form_record' => ['variants' => [
                'TXT' => $form['variants']['TXT'], 'MALFORMED' => ['value' => $invalid],
            ]] + $form]];
        }
    }

    /** @param array<mixed> $changes */
    #[DataProvider('malformedSpecs')]
    public function testRejectsMalformedDescriptorsEvenForOmittedBranches(array $changes): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check($changes + self::SPEC);
    }

    /**
     * @param array<mixed> $spec
     * @param array<string, mixed> $arguments
     */
    private static function encode(array $spec, array $arguments): string
    {
        $body = null;
        OperationRunner::run(
            $spec,
            $arguments,
            static function (array $spec, OrderedFields $params) use (&$body): ApiResponse {
                $body = $params->encode();
                return new ApiResponse(null, new ResponseMetadata(200, ['result' => 1]), false);
            },
        );
        self::assertIsString($body);
        return $body;
    }
}
