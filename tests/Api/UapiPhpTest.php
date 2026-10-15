<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class UapiPhpTest extends TestCase
{
    #[DataProvider('directEnvelopes')]
    public function testDirectListVersionsAuthenticatesAndPreservesPackageNames(string $body): void
    {
        [$client, $http] = $this->client(true);
        $uapi = $client->uapi();
        $php = $uapi->langPhp();
        self::assertSame($php, $uapi->langPhp());
        $initialRequests = $http->getRequests();
        $initialMetadata = $uapi->responseMeta();
        self::assertSame([], $initialRequests);
        self::assertNull($initialMetadata);
        $http->addResponse(new Response(200, [], $body));

        self::assertSame(['ea-php72', 'custom-php-8.4+vendor', 'ea-php72'], $php->phpGetInstalledVersions());

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2083/execute/LangPHP/php_get_installed_versions',
            (string) $request->getUri(),
        );
        self::assertSame('cpanel npr:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', (string) $request->getBody());
        $metadata = $uapi->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(200, $metadata->httpStatus);
        self::assertNull($metadata->whm);
        self::assertSame([
            'status' => 1,
            'errors' => null,
            'messages' => [],
            'warnings' => ['notice'],
            'metadata' => [],
        ], $metadata->uapi);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function directEnvelopes(): iterable
    {
        $result = '{"data":{"versions":["ea-php72","custom-php-8.4+vendor","ea-php72"]},'
            . '"status":1,"errors":null,"messages":[],"warnings":["notice"],"metadata":{}}';

        yield 'pinned result wrapper' => [
            '{"apiversion":3,"module":"LangPHP","func":"php_get_installed_versions","result":' . $result . '}',
        ];
        yield 'direct bare result' => [$result];
    }

    #[DataProvider('routes')]
    public function testEmptyVersionsRemainAnEmptyList(bool $direct): void
    {
        [$client, $http] = $this->client($direct);
        $http->addResponse(new Response(200, [], self::success($direct, ',"data":{"versions":[]}')));

        self::assertSame([], $client->uapi('npr')->langPhp()->phpGetInstalledVersions());
        self::assertCount(1, $http->getRequests());
    }

    public function testBridgeListVersionsKeepsAccountTargetsAndMetadataIsolated(): void
    {
        [$client, $http] = $this->client();
        $npr = $client->uapi('npr');
        $other = $client->uapi('other');
        self::assertSame($npr->langPhp(), $npr->langPhp());
        self::assertNotSame($npr->langPhp(), $other->langPhp());
        $initialRequests = $http->getRequests();
        self::assertSame([], $initialRequests);
        $http->addResponse(new Response(200, [], self::success(false, ',"data":{"versions":["ea-php72"]}')));
        $http->addResponse(new Response(200, [], self::success(false, ',"data":{"versions":["custom-php"]}')));

        self::assertSame(['ea-php72'], $npr->langPhp()->phpGetInstalledVersions());
        $retained = $npr->responseMeta();
        self::assertNotNull($retained);
        self::assertSame([
            'result' => 1,
            'command' => 'uapi_cpanel',
            'reason' => 'OK',
            'version' => 1,
        ], $retained->whm);
        self::assertSame([
            'status' => 1,
            'errors' => null,
            'messages' => null,
            'warnings' => null,
            'metadata' => [],
        ], $retained->uapi);
        self::assertNull($other->responseMeta());
        self::assertSame(['custom-php'], $other->langPhp()->phpGetInstalledVersions());
        self::assertSame($retained, $npr->responseMeta());
        self::assertNotSame($retained, $other->responseMeta());
        self::assertNull($client->whm()->responseMeta());

        $requests = $http->getRequests();
        self::assertCount(2, $requests);
        foreach (['npr', 'other'] as $index => $target) {
            self::assertSame('GET', $requests[$index]->getMethod());
            self::assertSame(
                'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $target
                . '&cpanel.module=LangPHP&cpanel.function=php_get_installed_versions',
                (string) $requests[$index]->getUri(),
            );
            self::assertSame('whm root:fixture-token', $requests[$index]->getHeaderLine('Authorization'));
            self::assertSame('application/json', $requests[$index]->getHeaderLine('Accept'));
            self::assertSame('', (string) $requests[$index]->getBody());
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadKeepsCurrentMetadata(bool $direct, string $payload): void
    {
        [$client, $http] = $this->client($direct);
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::success($direct, $payload)));

        try {
            $uapi->langPhp()->phpGetInstalledVersions();
            self::fail('Expected invalid PHP versions payload.');
        } catch (InvalidResponseException $exception) {
            $metadata = $uapi->responseMeta();
            self::assertNotNull($metadata);
            self::assertSame($metadata, $exception->metadata);
            self::assertSame(1, $metadata->uapi['status'] ?? null);
            self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
            self::assertSame($direct ? null : 1, $metadata->whm['result'] ?? null);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{bool, string}> */
    public static function invalidPayloads(): iterable
    {
        $payloads = [
            'omitted data' => '',
            'null data' => ',"data":null',
            'list data' => ',"data":[]',
            'scalar data' => ',"data":"versions"',
            'omitted versions' => ',"data":{}',
            'null versions' => ',"data":{"versions":null}',
            'scalar versions' => ',"data":{"versions":"ea-php72"}',
            'object versions' => ',"data":{"versions":{"0":"ea-php72"}}',
            'integer version' => ',"data":{"versions":["ea-php72",84]}',
            'null version' => ',"data":{"versions":[null]}',
            'boolean version' => ',"data":{"versions":[false]}',
            'nested version' => ',"data":{"versions":[["ea-php72"]]}',
        ];
        foreach ($payloads as $name => $payload) {
            yield 'direct ' . $name => [true, $payload];
            yield 'bridge ' . $name => [false, $payload];
        }
    }

    /** @param class-string<ApiException> $exceptionClass */
    #[DataProvider('failedResponses')]
    public function testFailuresReplaceOldMetadataAndRetainExceptionSnapshot(
        bool $direct,
        int $httpStatus,
        string $body,
        string $exceptionClass,
        ?int $whmResult,
        ?int $uapiStatus,
    ): void {
        [$client, $http] = $this->client($direct);
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::success($direct, ',"data":{"versions":["ea-php72"]}')));
        $uapi->langPhp()->phpGetInstalledVersions();
        $retained = $uapi->responseMeta();
        self::assertNotNull($retained);
        $http->addResponse(new Response($httpStatus, [], $body));

        try {
            $uapi->langPhp()->phpGetInstalledVersions();
            self::fail('Expected an API client exception.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($uapi->responseMeta(), $exception->metadata);
            self::assertNotSame($retained, $uapi->responseMeta());
            self::assertSame($whmResult, $uapi->responseMeta()?->whm['result'] ?? null);
            self::assertSame($uapiStatus, $uapi->responseMeta()?->uapi['status'] ?? null);
            self::assertSame(1, $retained->uapi['status'] ?? null);
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            if ($exception instanceof HttpException) {
                self::assertSame($httpStatus, $exception->httpStatus);
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{bool, int, string, class-string<ApiException>, ?int, ?int}> */
    public static function failedResponses(): iterable
    {
        yield 'direct failed outcome' => [
            true, 200,
            '{"result":{"status":0,"errors":["fixture-token"],"messages":null,"warnings":null,"data":null}}',
            ApiErrorException::class, null, 0,
        ];
        yield 'bridge outer failure despite inner success' => [
            false, 200,
            '{"metadata":{"result":0},"data":{"uapi":{"status":1,"data":{"versions":["ea-php72"]}}}}',
            ApiErrorException::class, 0, 1,
        ];
        yield 'bridge inner failure despite outer success' => [
            false, 200,
            '{"metadata":{"result":1},"data":{"uapi":{"status":0,"errors":["Denied"],"data":null}}}',
            ApiErrorException::class, 1, 0,
        ];
        yield 'malformed JSON clears metadata' => [
            true, 200, '{invalid', InvalidResponseException::class, null, null,
        ];
        yield 'missing versions replaces metadata' => [
            true, 200, self::success(true, ',"data":{}'), InvalidResponseException::class, null, 1,
        ];
        yield 'present malformed result takes precedence over bare success' => [
            true, 200, '{"result":null,"status":1,"data":{"versions":["ea-php72"]}}',
            InvalidResponseException::class, null, null,
        ];
        yield 'bare result requires status' => [
            true, 200, '{"data":{"versions":["ea-php72"]}}', InvalidResponseException::class, null, null,
        ];
        yield 'HTTP failure retains metadata' => [
            true, 403, '{"status":0,"errors":["Denied"],"data":null}', HttpException::class, null, 0,
        ];
        yield 'redirect has no second request' => [true, 302, '', HttpException::class, null, null];
    }

    #[DataProvider('routes')]
    public function testTransportFailureClearsMetadataWithoutRetry(bool $direct): void
    {
        [$client, $http] = $this->client($direct);
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::success($direct, ',"data":{"versions":["ea-php72"]}')));
        $uapi->langPhp()->phpGetInstalledVersions();
        $retained = $uapi->responseMeta();
        $http->addException(new class ('fixture-token') extends RuntimeException implements ClientExceptionInterface {
        });

        try {
            $uapi->langPhp()->phpGetInstalledVersions();
            self::fail('Expected transport failure.');
        } catch (TransportException $exception) {
            self::assertNull($uapi->responseMeta());
            self::assertNull($exception->metadata);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
            self::assertSame(1, $retained?->uapi['status'] ?? null);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{bool}> */
    public static function routes(): iterable
    {
        yield 'direct' => [true];
        yield 'bridge' => [false];
    }

    private static function success(bool $direct, string $payload): string
    {
        $result = '{"status":1,"errors":null,"messages":null,"warnings":null,"metadata":{}' . $payload . '}';

        return $direct
            ? '{"apiversion":3,"module":"LangPHP","func":"php_get_installed_versions","result":' . $result . '}'
            : '{"metadata":{"result":1,"command":"uapi_cpanel","reason":"OK","version":1},'
                . '"data":{"uapi":' . $result . '}}';
    }

    /** @return array{Client, MockClient} */
    private function client(bool $direct = false): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'npr' : 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
