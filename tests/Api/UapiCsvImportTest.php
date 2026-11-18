<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\CsvImport;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionParameter;
use SensitiveParameter;

final class UapiCsvImportTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function routes(): iterable
    {
        yield 'direct' => [false];
        yield 'WHM bridge' => [true];
    }

    #[DataProvider('routes')]
    public function testMixedRowsAndStatusZeroSurviveOneFormPost(bool $bridge): void
    {
        [$account, $http] = self::account($bridge);
        $http->addResponse(self::envelope($bridge, 0, '{"results":[{"email":"one","type":"email","status":1},'
            . '{"email":"two","fwd":null,"status":0,"reason":"exists"},{"status":1,"extra":"hidden"}]}'));
        self::assertSame(['results' => [
            ['email' => 'one', 'status' => 1, 'type' => 'email'],
            ['email' => 'two', 'fwd' => null, 'reason' => 'exists', 'status' => 0],
            ['status' => 1],
        ]], $account->csvImport()->doImport(domain: 'example.test', id: 'private&%20', type: 'email'));
        self::assertSame(0, $account->responseMeta()?->uapiStatus());
        self::assertSame(['synthetic rejection'], $account->responseMeta()->errors());
        self::assertSame($bridge ? 1 : null, $account->responseMeta()->whmResult());
        self::assertCount(1, $http->getRequests());
        $request = $http->getRequests()[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame($bridge
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=testacct'
                . '&cpanel.module=CSVImport&cpanel.function=doimport'
            : 'https://api.example.test:2083/execute/CSVImport/doimport', (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('domain=example.test&id=private%26%2520&type=email', (string) $request->getBody());
        self::assertNotEmpty((new ReflectionParameter([CsvImport::class, 'doImport'], 'id'))
            ->getAttributes(SensitiveParameter::class));
    }

    /** @param array<mixed>|class-string<ApiException> $expected */
    #[DataProvider('outcomes')]
    public function testDistinguishesBatchOutcomes(
        bool $bridge,
        int $status,
        ?string $data,
        array|string $expected,
    ): void {
        [$account, $http] = self::account($bridge);
        $http->addResponse(self::envelope($bridge, $status, $data));
        try {
            $result = $account->csvImport()->doImport('example.test', 'synthetic-id', 'fwd');
            self::assertSame($expected, $result);
        } catch (ApiException $error) {
            self::assertSame($expected, $error::class);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertSame($status, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{bool, int, ?string, array<mixed>|class-string<ApiException>}> */
    public static function outcomes(): iterable
    {
        foreach (self::routes() as $name => [$bridge]) {
            yield "$name failed row" => [$bridge, 0, '{"results":[{"status":0}]}', ['results' => [['status' => 0]]]];
            yield "$name success" => [$bridge, 1, '{"results":[{"fwd":"local","status":1}]}',
                ['results' => [['fwd' => 'local', 'status' => 1]]]];
            yield "$name empty import" => [$bridge, 1, '{"results":null}', ['results' => null]];
            yield "$name empty list" => [$bridge, 1, '{"results":[]}', ['results' => []]];
            yield "$name no results key" => [$bridge, 1, '{}', []];
            yield "$name null data" => [$bridge, 0, 'null', ApiErrorException::class];
            yield "$name failed null results" => [$bridge, 0, '{"results":null}', ApiErrorException::class];
            yield "$name failed empty list" => [$bridge, 0, '{"results":[]}', ApiErrorException::class];
            yield "$name failed missing results" => [$bridge, 0, '{}', PayloadMismatchException::class];
            yield "$name failed missing data" => [$bridge, 0, null, PayloadMismatchException::class];
            yield "$name null successful data" => [$bridge, 1, 'null', PayloadMismatchException::class];
            yield "$name missing successful data" => [$bridge, 1, null, PayloadMismatchException::class];
            yield "$name malformed row" => [$bridge, 0, '{"results":[{"status":2}]}', PayloadMismatchException::class];
        }
    }

    public function testOuterBridgeFailurePrecedesInnerSuccessfulRow(): void
    {
        [$account, $http] = self::account(true);
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"outer rejection"},"data":{"uapi":'
            . '{"status":1,"data":{"results":[{"status":1}]}}}}'));
        try {
            $account->csvImport()->doImport('example.test', 'synthetic-id', 'email');
            self::fail('The failed WHM envelope must stop the batch.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(['outer rejection'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    private static function envelope(bool $bridge, int $status, ?string $data): Response
    {
        $result = '{"status":' . $status . ',"errors":' . ($status === 0 ? '["synthetic rejection"]' : 'null')
            . ($data === null ? '' : ',"data":' . $data) . '}';
        return new Response(200, [], $bridge
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}');
    }

    /** @return array{UapiClient, MockClient} */
    private static function account(bool $bridge): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $bridge ? 'whm' : 'cpanel',
                'username' => $bridge ? 'root' : 'testacct', 'token' => 'synthetic-token'],
            new HttpTransport($http, $factory, $factory),
        );

        return [$client->uapi('testacct'), $http];
    }
}
