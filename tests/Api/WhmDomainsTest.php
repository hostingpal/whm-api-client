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

final class WhmDomainsTest extends TestCase
{
    private const RESPONSE = '{"data":{"domains":[{"domain":"example.test","domain_type":"main",'
        . '"ipv6":null,"port":80,"user":"account"}]},"metadata":{"command":"get_domain_info",'
        . '"reason":"OK","result":1,"version":1,"extension":{"count":1}}}';

    public function testListsServerDomainsImmediatelyWithWhmCredentialsAndAnInertStableSelector(): void
    {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $group = $whm->domains();
        self::assertSame($group, $whm->domains());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::RESPONSE));

        $domains = $group->list();

        self::assertCount(1, $domains);
        self::assertSame(['domains' => [[
            'domain' => 'example.test',
            'domain_type' => 'main',
            'ipv6' => null,
            'port' => 80,
            'user' => 'account',
        ]]], $domains->toArray());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/get_domain_info?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', (string) $request->getBody());
        $metadata = $whm->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(200, $metadata->httpStatus);
        self::assertSame([
            'command' => 'get_domain_info',
            'reason' => 'OK',
            'result' => 1,
            'version' => 1,
            'extension' => ['count' => 1],
        ], $metadata->whm);
        self::assertNull($metadata->uapi);
        self::assertNull($client->uapi('account')->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    public function testDomainAndServerGroupsShareOnlyTheirOwningWhmMetadata(): void
    {
        [$client, $http] = $this->client();
        [$other] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"command":"version"},'
            . '"data":{"version":"11.138.0.15"}}'));
        $whm->server()->version();
        $retained = $whm->responseMeta();
        $http->addResponse(new Response(200, [], self::RESPONSE));

        $whm->domains()->list();

        self::assertSame('get_domain_info', $whm->responseMeta()?->whm['command'] ?? null);
        self::assertSame('version', $retained?->whm['command'] ?? null);
        self::assertNotSame($retained, $whm->responseMeta());
        self::assertNull($other->whm()->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    public function testAcceptsObservedStringPortsWithoutChangingTheirExportedWireTypes(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"command":"get_domain_info"},'
            . '"data":{"domains":[{"docroot":"/home/account/public_html","domain":"example.test",'
            . '"domain_type":"main","ipv4":"192.0.2.1","ipv4_ssl":"192.0.2.1","ipv6":null,'
            . '"ipv6_is_dedicated":0,"modsecurity_enabled":1,"parent_domain":"","php_version":"ea-php83",'
            . '"port":"80","port_ssl":"443","user":"account","user_owner":"root"}]}}'));

        $domains = $client->whm()->domains()->list();

        self::assertCount(1, $domains);
        self::assertSame(80, $domains->domains[0]->port ?? null);
        self::assertSame(443, $domains->domains[0]->portSsl ?? null);
        self::assertSame('80', $domains->toArray()['domains'][0]['port'] ?? null);
        self::assertSame('443', $domains->toArray()['domains'][0]['port_ssl'] ?? null);
        self::assertSame(1, $client->whm()->responseMeta()?->whm['result'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @param class-string<ApiException> $exceptionClass */
    #[DataProvider('failedResponses')]
    public function testFailuresReplaceStaleMetadataWithoutRetries(
        int $status,
        string $body,
        string $exceptionClass,
        int|string|null $result,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::RESPONSE));
        $whm->domains()->list();
        $retained = $whm->responseMeta();
        $http->addResponse(new Response($status, [], $body));

        try {
            $whm->domains()->list();
            self::fail('Expected a domain operation failure.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($whm->responseMeta(), $exception->metadata);
            self::assertNotSame($retained, $whm->responseMeta());
            self::assertSame($result, $exception->metadata?->whm['result'] ?? null);
            self::assertSame(1, $retained?->whm['result'] ?? null);
            if ($exception instanceof HttpException) {
                self::assertSame($status, $exception->httpStatus);
            }
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{int, string, class-string<ApiException>, int|string|null}> */
    public static function failedResponses(): iterable
    {
        yield 'API failure' => [200, '{"metadata":{"result":0,"reason":"fixture-token"}}',
            ApiErrorException::class, 0];
        yield 'missing payload' => [200, '{"metadata":{"result":1}}', InvalidResponseException::class, 1];
        yield 'null payload' => [200, '{"metadata":{"result":1},"data":null}',
            InvalidResponseException::class, 1];
        yield 'list payload' => [200, '{"metadata":{"result":1},"data":[]}',
            InvalidResponseException::class, 1];
        yield 'object domain list' => [200, '{"metadata":{"result":1},"data":{"domains":{}}}',
            InvalidResponseException::class, 1];
        yield 'invalid record' => [200, '{"metadata":{"result":1},"data":{"domains":[{"port":"8e1"}]}}',
            InvalidResponseException::class, 1];
        yield 'invalid outcome' => [200, '{"metadata":{"result":"1"},"data":{"domains":[]}}',
            InvalidResponseException::class, '1'];
        yield 'malformed JSON' => [200, '{invalid', InvalidResponseException::class, null];
        yield 'missing envelope' => [200, '{"data":{"domains":[]}}', InvalidResponseException::class, null];
        yield 'HTTP failure with metadata' => [403, '{"metadata":{"result":0}}', HttpException::class, 0];
        yield 'HTML HTTP failure' => [502, '<h1>fixture-token</h1>', HttpException::class, null];
        yield 'redirect' => [302, '', HttpException::class, null];
    }

    public function testTransportFailureClearsMetadataWithoutRetriesOrLeakingCredentials(): void
    {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::RESPONSE));
        $whm->domains()->list();
        $retained = $whm->responseMeta();
        $http->addException(new class ('whm root:fixture-token') extends RuntimeException implements
            ClientExceptionInterface
        {
        });

        try {
            $whm->domains()->list();
            self::fail('Expected transport failure.');
        } catch (TransportException $exception) {
            self::assertNull($whm->responseMeta());
            self::assertNull($exception->metadata);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('fixture-token', $exception->getMessage());
            self::assertSame(1, $retained?->whm['result'] ?? null);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return array{Client, MockClient} */
    private function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'username' => 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
