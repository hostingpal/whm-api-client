<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Api\Engine\OperationRunner;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ShapeValidator;
use HostingPal\WhmApiClient\Tools\LiveVerify\Fingerprint;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ObjectValuesTest extends TestCase
{
    private const TYPE = [
        'type' => 'object',
        'fields' => ['known' => ['type' => 'number'], 'nullable' => ['type' => 'string', 'nullable' => true]],
        'required' => ['known'],
        'values' => ['type' => 'list', 'items' => ['type' => 'string']],
    ];

    public function testExtrasAreValidatedAndPreservedAfterKnownFieldsInWireOrder(): void
    {
        $payload = self::decode('{"private-second":["b"],"known":5.1,"nullable":null,"0":[],"private-first":["a"]}');
        self::assertSame(['known' => 5.1, 'nullable' => null, 'private-second' => ['b'], 0 => [],
            'private-first' => ['a']], ShapeValidator::value($payload, self::TYPE, self::reader()));
    }

    public function testClosedObjectsStillDropExtrasAndEmptyObjectsKeepExistingSemantics(): void
    {
        $closed = self::TYPE;
        unset($closed['values']);
        self::assertSame(['known' => 1], ShapeValidator::value(
            self::decode('{"known":1,"custom":[2]}'),
            $closed,
            self::reader(),
        ));
        $open = ['type' => 'object', 'fields' => [], 'values' => ['type' => 'mixed']];
        self::assertSame([], ShapeValidator::value([], $open, self::reader()));
        self::assertSame([], ShapeValidator::value(self::decode('{}'), $open, self::reader()));
        self::assertSame(['custom' => ['nested' => null]], ShapeValidator::value(
            self::decode('{"custom":{"nested":null}}'),
            $open,
            self::reader(),
        ));
    }

    #[DataProvider('invalidPayloads')]
    public function testKnownAndExtraFieldsRemainStrict(string $json): void
    {
        $this->expectException(InvalidResponseException::class);
        ShapeValidator::value(self::decode($json), self::TYPE, self::reader());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'known cannot use permissive extra spec' => ['{"known":["a"]}'];
        yield 'known number does not become numeric string' => ['{"known":"5.1"}'];
        yield 'required known field remains required' => ['{"custom":["a"]}'];
        yield 'extra scalar rejected' => ['{"known":1,"custom":"all"}'];
        yield 'extra list item rejected' => ['{"known":1,"custom":[0]}'];
        yield 'extra null rejected' => ['{"known":1,"custom":null}'];
        yield 'root null rejected' => ['null'];
    }

    #[DataProvider('malformedSpecs')]
    public function testMalformedExtraChildSpecFailsBeforeDispatch(mixed $values): void
    {
        $spec = ['function' => 'example', 'method' => 'POST', 'outcome' => 'flag', 'params' => [],
            'returns' => ['type' => 'object', 'fields' => [], 'values' => $values]];
        $dispatched = false;
        try {
            OperationRunner::run($spec, [], static function () use (&$dispatched): ApiResponse {
                $dispatched = true;
                return new ApiResponse(null, new ResponseMetadata(200, ['result' => 1]));
            });
            self::fail('Expected invalid extra-values spec rejection before I/O.');
        } catch (LogicException) {
            self::assertFalse($dispatched);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedSpecs(): iterable
    {
        yield 'null is present and invalid' => [null];
        yield 'scalar is invalid' => ['string'];
        yield 'missing type' => [[]];
        yield 'list missing child' => [['type' => 'list']];
        yield 'nested invalid accept' => [['type' => 'list', 'items' => ['type' => 'string', 'accept' => ['bool']]]];
    }

    public function testMalformedExtraSpecClearsHandleMetadataWithoutAnotherRequest(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(['host' => 'api.example.test', 'authType' => 'cpanel', 'username' => 'npr',
            'token' => 'fixture-token'], new HttpTransport($http, $factory, $factory));
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], '{"result":{"status":1,"data":{}}}'));
        $account->call('SpamAssassin', 'get_user_preferences');
        self::assertNotNull($account->responseMeta());
        $run = new ReflectionMethod($account, 'run');
        try {
            $run->invoke($account, ['module' => 'SpamAssassin', 'function' => 'update_user_preference',
                'method' => 'POST', 'outcome' => 'flag', 'params' => [],
                'returns' => ['type' => 'object', 'fields' => [], 'values' => ['type' => 'list']]], []);
            self::fail('Malformed values must be checked before request dispatch.');
        } catch (LogicException) {
            self::assertNull($account->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testFingerprintMasksExtraKeysAndPreservesTypeDiscrepancies(): void
    {
        $result = Fingerprint::analyze(self::decode(
            '{"known":"private-value","private@domain.invalid":[1],"private.other":["secret"]}',
        ), true, self::TYPE);
        self::assertSame([
            'paths' => ['$' => ['object'], '$.*' => ['list'], '$.*[]' => ['int', 'string'],
                '$.known' => ['string'], '$.nullable' => ['missing']],
            'discrepancies' => [
            ['path' => '$.*[]', 'kind' => 'type_mismatch'],
                ['path' => '$.known', 'kind' => 'type_mismatch']],
        ], $result);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private', $serialized);
        self::assertStringNotContainsString('secret', $serialized);
        self::assertStringNotContainsString('domain.invalid', $serialized);
    }

    public function testFingerprintMixedExtraDescendantsStayWildcarded(): void
    {
        $result = Fingerprint::analyze(
            self::decode('{"known":1,"private":{"known":{"secret":null}}}'),
            true,
            ['type' => 'object', 'fields' => ['known' => ['type' => 'int']], 'values' => ['type' => 'mixed']],
        );
        self::assertSame(['paths' => ['$' => ['object'], '$.*' => ['object'], '$.*.*' => ['object'],
            '$.*.*.*' => ['null'], '$.known' => ['int']], 'discrepancies' => []], $result);
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    private static function reader(): PayloadReader
    {
        return new PayloadReader(new ResponseMetadata(200), 'test payload');
    }
}
