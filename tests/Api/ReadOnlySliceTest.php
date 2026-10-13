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

final class ReadOnlySliceTest extends TestCase
{
    private const VERSION = '{"data":{"version":"11.138.0.15"},"metadata":'
        . '{"command":"version","reason":"OK","result":1,"version":1}}';

    private const DOMAINS = '{"main_domain":"example.test","addon_domains":[],"parked_domains":[],'
        . '"sub_domains":["www.example.test"],"is_temporary":{"example.test":0}}';

    public function testWhmVersionAuthenticatesAndReturnsTheUnmodifiedVersion(): void
    {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $server = $whm->server();
        self::assertSame($server, $whm->server());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::VERSION));

        self::assertSame('11.138.0.15', $server->version());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/version?api.version=1', (string) $request->getUri());
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', (string) $request->getBody());
        $metadata = $whm->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(
            ['command' => 'version', 'reason' => 'OK', 'result' => 1, 'version' => 1],
            $metadata->whm,
        );
        self::assertNull($metadata->uapi);
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('filters')]
    public function testDirectDomainsUseCpanelCredentialsAndPreserveFilterOmission(?bool $filter, string $query): void
    {
        [$client, $http] = $this->client(true);
        $uapi = $client->uapi();
        self::assertSame($uapi->domains(), $uapi->domains());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], '{"apiversion":3,"module":"DomainInfo","func":"list_domains",'
            . '"result":{"data":' . self::DOMAINS
            . ',"status":1,"errors":null,"messages":[],"warnings":null,"metadata":{}}}'));

        $domains = $uapi->domains()->list($filter);
        self::assertSame('example.test', $domains->mainDomain);
        self::assertSame(['www.example.test'], $domains->subDomains);
        self::assertSame(['example.test' => 0], $domains->isTemporary);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2083/execute/DomainInfo/list_domains' . $query,
            (string) $request->getUri(),
        );
        self::assertSame('cpanel npr:fixture-token', $request->getHeaderLine('Authorization'));
        $metadata = $uapi->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapi['status'] ?? null);
        self::assertSame([], $metadata->uapi['messages'] ?? null);
        self::assertArrayHasKey('warnings', $metadata->uapi ?? []);
        self::assertNull($metadata->whm);
    }

    /** @return iterable<string, array{?bool, string}> */
    public static function filters(): iterable
    {
        yield 'omitted' => [null, ''];
        yield 'explicit false' => [false, '?hide_temporary_domains=0'];
        yield 'explicit true' => [true, '?hide_temporary_domains=1'];
    }

    public function testDirectDomainsAcceptObservedHttpEnvelope(): void
    {
        [$client, $http] = $this->client(true);
        // Synthetic account data in the bare envelope observed on direct HTTPS.
        $http->addResponse(new Response(200, [], '{"status":1,"data":' . self::DOMAINS
            . ',"errors":null,"messages":null,"warnings":null,"metadata":{}}'));
        $uapi = $client->uapi();

        $domains = $uapi->domains()->list();

        self::assertSame('example.test', $domains->mainDomain);
        self::assertFalse($uapi->isBridged());
        $metadata = $uapi->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(200, $metadata->httpStatus);
        self::assertNull($metadata->whm);
        self::assertSame([
            'status' => 1,
            'errors' => null,
            'messages' => null,
            'warnings' => null,
            'metadata' => [],
        ], $metadata->uapi);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('https://api.example.test:2083/execute/DomainInfo/list_domains', (string) $request->getUri());
        self::assertSame('cpanel npr:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertCount(1, $http->getRequests());
    }

    public function testBridgeRetainsBothEnvelopesAndSelectedAccountIsolation(): void
    {
        [$client, $http] = $this->client();
        $npr = $client->uapi('npr');
        $other = $client->uapi('other');
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":1,"command":"uapi_cpanel","reason":"OK","version":1},'
            . '"data":{"uapi":{"data":' . self::DOMAINS
            . ',"status":1,"warnings":["notice"],"errors":null,"messages":null,"metadata":{"transformed":0}}}}',
        ));

        self::assertSame('example.test', $npr->domains()->list(false)->mainDomain);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
            . '&cpanel.module=DomainInfo&cpanel.function=list_domains&hide_temporary_domains=0',
            (string) $request->getUri(),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        $metadata = $npr->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->whm['result'] ?? null);
        self::assertSame(1, $metadata->uapi['status'] ?? null);
        self::assertSame(['notice'], $metadata->uapi['warnings'] ?? null);
        self::assertSame(['transformed' => 0], $metadata->uapi['metadata'] ?? null);
        self::assertNull($other->responseMeta());
        self::assertNull($client->whm()->responseMeta());
    }

    /** @param class-string<ApiException> $exceptionClass */
    #[DataProvider('failedResponses')]
    public function testFailuresReplaceOldMetadataAndKeepExceptionSnapshot(
        bool $uapi,
        bool $direct,
        int $httpStatus,
        string $body,
        string $exceptionClass,
        int|string|null $whmResult,
        ?int $uapiStatus,
    ): void {
        [$client, $http] = $this->client($direct);
        $handle = $uapi ? $client->uapi('npr') : $client->whm();
        $http->addResponse(new Response(200, [], $uapi
            ? ($direct ? '{"result":{"status":1,"data":{}}}'
                : '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{}}}}')
            : self::VERSION));
        $uapi ? $client->uapi('npr')->domains()->list() : $client->whm()->server()->version();
        $retained = $handle->responseMeta();
        self::assertNotNull($retained);
        $http->addResponse(new Response($httpStatus, [], $body));

        try {
            $uapi ? $client->uapi('npr')->domains()->list() : $client->whm()->server()->version();
            self::fail('Expected an API client exception.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            if ($exception instanceof HttpException) {
                self::assertSame($httpStatus, $exception->httpStatus);
            }
            self::assertSame($handle->responseMeta(), $exception->metadata);
            self::assertNotSame($retained, $handle->responseMeta());
            self::assertSame($whmResult, $handle->responseMeta()?->whm['result'] ?? null);
            self::assertSame($uapiStatus, $handle->responseMeta()?->uapi['status'] ?? null);
            self::assertSame(200, $retained->httpStatus);
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{bool, bool, int, string, class-string<ApiException>, int|string|null, ?int}> */
    public static function failedResponses(): iterable
    {
        yield 'WHM API failure over HTTP 200' => [
            false,
            false,
            200,
            '{"metadata":{"result":0,"reason":"fixture-token"}}',
            ApiErrorException::class,
            0,
            null,
        ];
        yield 'WHM missing version' => [
            false,
            false,
            200,
            '{"metadata":{"result":1},"data":{}}',
            InvalidResponseException::class,
            1,
            null,
        ];
        yield 'WHM wrong version type' => [
            false,
            false,
            200,
            '{"metadata":{"result":1},"data":{"version":12}}',
            InvalidResponseException::class,
            1,
            null,
        ];
        yield 'unknown WHM outcome' => [
            false,
            false,
            200,
            '{"metadata":{"result":"1"},"data":{"version":"x"}}',
            InvalidResponseException::class,
            '1',
            null,
        ];
        yield 'direct UAPI API failure' => [
            true,
            true,
            200,
            '{"result":{"status":0,"errors":["Denied"]}}',
            ApiErrorException::class,
            null,
            0,
        ];
        yield 'direct bare UAPI failure' => [
            true, true, 200, '{"status":0,"errors":["Denied"],"data":null}',
            ApiErrorException::class, null, 0,
        ];
        yield 'direct bare HTTP failure with metadata' => [
            true, true, 403, '{"status":0,"errors":["Denied"],"data":null}',
            HttpException::class, null, 0,
        ];
        yield 'direct bare invalid outcome' => [
            true, true, 200, '{"status":2,"data":{}}',
            InvalidResponseException::class, null, 2,
        ];
        yield 'direct bare missing outcome' => [
            true, true, 200, '{"data":{}}',
            InvalidResponseException::class, null, null,
        ];
        yield 'direct null wrapper cannot fall back to bare success' => [
            true, true, 200, '{"result":null,"status":1,"data":{}}',
            InvalidResponseException::class, null, null,
        ];
        yield 'direct malformed wrapper cannot fall back to bare success' => [
            true, true, 200, '{"result":[],"status":1,"data":{}}',
            InvalidResponseException::class, null, null,
        ];
        yield 'direct wrapped failure takes precedence over bare success' => [
            true, true, 200, '{"result":{"status":0,"errors":["Denied"]},"status":1,"data":{}}',
            ApiErrorException::class, null, 0,
        ];
        yield 'bridge cannot use bare UAPI envelope' => [
            true, false, 200, '{"status":1,"data":{}}',
            InvalidResponseException::class, null, null,
        ];
        yield 'bridge outer failure' => [
            true,
            false,
            200,
            '{"metadata":{"result":0,"reason":"Denied"}}',
            ApiErrorException::class,
            0,
            null,
        ];
        yield 'bridge inner failure' => [
            true,
            false,
            200,
            '{"metadata":{"result":1},"data":{"uapi":{"status":0,"errors":["Denied"]}}}',
            ApiErrorException::class,
            1,
            0,
        ];
        yield 'bridge missing inner' => [
            true,
            false,
            200,
            '{"metadata":{"result":1},"data":{}}',
            InvalidResponseException::class,
            1,
            null,
        ];
        yield 'domain payload null' => [
            true,
            true,
            200,
            '{"result":{"status":1,"data":null}}',
            InvalidResponseException::class,
            null,
            1,
        ];
        yield 'missing outcome' => [
            true,
            true,
            200,
            '{"result":{"data":{}}}',
            InvalidResponseException::class,
            null,
            null,
        ];
        yield 'malformed JSON' => [false, false, 200, '{invalid', InvalidResponseException::class, null, null];
        yield 'list envelope' => [false, false, 200, '[]', InvalidResponseException::class, null, null];
        yield 'unrepresentable metadata number' => [
            false, false, 200, '{"metadata":{"result":1,"extension":1e999}}',
            InvalidResponseException::class, null, null,
        ];
        yield 'HTTP error with unrepresentable metadata number' => [
            false, false, 503, '{"metadata":{"result":1,"extension":1e999}}',
            HttpException::class, null, null,
        ];
        yield 'HTML HTTP failure' => [
            false,
            false,
            502,
            '<h1>fixture-token</h1>',
            HttpException::class,
            null,
            null,
        ];
        yield 'HTTP failure with WHM metadata' => [
            false,
            false,
            403,
            '{"metadata":{"result":0}}',
            HttpException::class,
            0,
            null,
        ];
        yield 'HTTP failure with bridge metadata' => [
            true,
            false,
            500,
            '{"metadata":{"result":1},"data":{"uapi":{"status":0}}}',
            HttpException::class,
            1,
            0,
        ];
        yield 'redirect without retry' => [false, false, 302, '', HttpException::class, null, null];
    }

    public function testTransportFailureClearsMetadataAndDoesNotExposeTheOriginalException(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::VERSION));
        $client->whm()->server()->version();
        $retained = $client->whm()->responseMeta();
        $http->addException(new class ('whm root:fixture-token') extends RuntimeException implements
            ClientExceptionInterface
        {
        });

        try {
            $client->whm()->server()->version();
            self::fail('Expected transport failure.');
        } catch (TransportException $exception) {
            self::assertNull($client->whm()->responseMeta());
            self::assertNull($exception->metadata);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
            self::assertSame(1, $retained?->whm['result'] ?? null);
        }
        self::assertCount(2, $http->getRequests());
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
