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
        $group = $whm->accounts();
        self::assertSame($group, $whm->accounts());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::RESPONSE));

        $domains = $group->getDomainInfo();

        self::assertCount(1, $domains['domains'] ?? []);
        self::assertSame(['domains' => [[
            'domain' => 'example.test',
            'domain_type' => 'main',
            'ipv6' => null,
            'port' => 80,
            'user' => 'account',
        ]]], $domains);
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

        $whm->accounts()->getDomainInfo();

        self::assertSame('get_domain_info', $whm->responseMeta()?->whm['command'] ?? null);
        self::assertSame('version', $retained?->whm['command'] ?? null);
        self::assertNotSame($retained, $whm->responseMeta());
        self::assertNull($other->whm()->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    public function testNormalizesObservedStringPortsToIntegers(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"command":"get_domain_info"},'
            . '"data":{"domains":[{"docroot":"/home/account/public_html","domain":"example.test",'
            . '"domain_type":"main","ipv4":"192.0.2.1","ipv4_ssl":"192.0.2.1","ipv6":null,'
            . '"ipv6_is_dedicated":0,"modsecurity_enabled":1,"parent_domain":"","php_version":"ea-php83",'
            . '"port":"80","port_ssl":"443","user":"account","user_owner":"root"}]}}'));

        $domains = $client->whm()->accounts()->getDomainInfo();

        self::assertCount(1, $domains['domains'] ?? []);
        self::assertSame(80, $domains['domains'][0]['port'] ?? null);
        self::assertSame(443, $domains['domains'][0]['port_ssl'] ?? null);
        self::assertSame(1, $client->whm()->responseMeta()?->whm['result'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    public function testMapsEveryReviewedFieldWithoutNarrowingTheDomainTypeAndDropsUnknownFields(): void
    {
        $record = [
            'docroot' => '/home/account/public_html',
            'domain' => 'example.test',
            'domain_type' => 'future-domain-kind',
            'ipv4' => '192.0.2.10',
            'ipv4_ssl' => '192.0.2.11',
            'ipv6' => '2001:db8::1',
            'ipv6_is_dedicated' => 1,
            'modsecurity_enabled' => 0,
            'parent_domain' => 'parent.test',
            'php_version' => 'ea-php83',
            'port' => 80,
            'port_ssl' => 443,
            'user' => 'account',
            'user_owner' => 'reseller',
        ];

        self::assertSame(['domains' => [$record]], $this->domains(json_encode(
            ['domains' => [[...$record, 'extension' => ['future' => true]]], 'extension' => true],
            JSON_THROW_ON_ERROR,
        )));
    }

    public function testRetainsOmittedFieldsSeparatelyFromExplicitNullZeroAndBlankValues(): void
    {
        $explicit = [
            'docroot' => '',
            'domain' => '',
            'domain_type' => '',
            'ipv4' => '',
            'ipv4_ssl' => '',
            'ipv6' => null,
            'ipv6_is_dedicated' => 0,
            'modsecurity_enabled' => 1,
            'parent_domain' => '',
            'php_version' => '',
            'port' => 0,
            'port_ssl' => 0,
            'user' => '',
            'user_owner' => '',
        ];

        self::assertSame(['domains' => [[], $explicit]], $this->domains(json_encode(
            ['domains' => [(object) [], $explicit]],
            JSON_THROW_ON_ERROR,
        )));
    }

    public function testOmittedAndEmptyDomainListsRemainDistinct(): void
    {
        self::assertSame([], $this->domains('{}'));
        self::assertSame(['domains' => []], $this->domains('{"domains":[]}'));
        self::assertSame(['domains' => [[]]], $this->domains('{"domains":[{}]}'));
        self::assertSame(
            ['domains' => [['domain' => 'one.test'], ['domain' => 'two.test']]],
            $this->domains('{"domains":[{"domain":"one.test"},{"domain":"two.test"}]}'),
        );
    }

    public function testEmptyJsonArraysAreEmptyObjects(): void
    {
        // The engine reads an empty JSON array as an empty object when no field is required.
        self::assertSame([], $this->domains('[]'));
        self::assertSame(['domains' => [[]]], $this->domains('{"domains":[[]]}'));
    }

    #[DataProvider('validPorts')]
    public function testNormalizesAcceptedPortRepresentations(int|string $wire, int $expected): void
    {
        self::assertSame(['domains' => [['port' => $expected, 'port_ssl' => $expected]]], $this->domains(json_encode(
            ['domains' => [['port' => $wire, 'port_ssl' => $wire]]],
            JSON_THROW_ON_ERROR,
        )));
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function validPorts(): iterable
    {
        yield 'observed HTTP port' => ['80', 80];
        yield 'observed HTTPS port' => ['443', 443];
        yield 'string zero' => ['0', 0];
        yield 'string above network port range' => ['65536', 65536];
        yield 'largest representable string' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'documented negative integer' => [-1, -1];
        yield 'documented smallest integer' => [PHP_INT_MIN, PHP_INT_MIN];
    }

    public function testSignedDecimalPortStringsAreAcceptedPendingRangeEnforcement(): void
    {
        // The deleted DomainInfo DTO accepted only nonnegative decimal strings; minimum/range enforcement is
        // deferred to bead whm-api-client-8x0.18, so the generated engine normalizes "-1" to -1.
        self::assertSame(
            ['domains' => [['port' => -1, 'port_ssl' => -1]]],
            $this->domains('{"domains":[{"port":"-1","port_ssl":"-1"}]}'),
        );
    }

    #[DataProvider('invalidRecords')]
    public function testRejectsMalformedContainersAndRecordsWithTheResponseSnapshot(string $data): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::envelope($data)));

        try {
            $client->whm()->accounts()->getDomainInfo();
            self::fail('Malformed domain information must not produce a result.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($client->whm()->responseMeta(), $exception->metadata);
            self::assertSame('get_domain_info', $exception->metadata?->whm['command'] ?? null);
            self::assertStringNotContainsString('sensitive-response-value', $exception->getMessage());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRecords(): iterable
    {
        $record = static fn (string $field, mixed $value): string => json_encode(
            ['domains' => [[$field => $value]]],
            JSON_THROW_ON_ERROR,
        );
        yield 'scalar payload' => ['"sensitive-response-value"'];
        yield 'null domains' => ['{"domains":null}'];
        yield 'scalar domains' => ['{"domains":"example.test"}'];
        yield 'object domains' => ['{"domains":{}}'];
        yield 'keyed domains' => ['{"domains":{"1":{}}}'];
        yield 'null record' => ['{"domains":[null]}'];
        yield 'scalar record' => ['{"domains":["sensitive-response-value"]}'];
        yield 'invalid second record' => ['{"domains":[{},{"port":"8e1"}]}'];
        foreach (
            ['docroot', 'domain', 'domain_type', 'ipv4', 'ipv4_ssl', 'parent_domain', 'php_version',
                'user', 'user_owner'] as $field
        ) {
            yield $field . ' null' => [$record($field, null)];
            yield $field . ' integer' => [$record($field, 1)];
        }
        yield 'ipv6 integer' => [$record('ipv6', 1)];
        yield 'ipv6 object' => [$record('ipv6', (object) [])];
        foreach (['ipv6_is_dedicated', 'modsecurity_enabled'] as $field) {
            foreach ([null, false, '0', 1.5, -1, 2] as $index => $value) {
                yield $field . ' invalid flag ' . $index => [$record($field, $value)];
            }
            yield $field . ' float flag' => ['{"domains":[{"' . $field . '":1.0}]}'];
        }
        foreach (['port', 'port_ssl'] as $field) {
            foreach ([null, false, '80.0', (object) [], [1]] as $index => $value) {
                yield $field . ' invalid integer ' . $index => [$record($field, $value)];
            }
            yield $field . ' float' => ['{"domains":[{"' . $field . '":80.0}]}'];
            foreach (
                ['', ' ', ' 80', '80 ', "80\n", "80\0", '00', '080', '+80', '-0', '8e1',
                    '0x50', '８０', '80tail'] as $index => $value
            ) {
                yield $field . ' noncanonical decimal ' . $index => [$record($field, $value)];
            }
            yield $field . ' integer overflow' => [$record(
                $field,
                PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648',
            )];
            yield $field . ' long integer overflow' => [$record($field, (string) PHP_INT_MAX . '0')];
        }
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
        $whm->accounts()->getDomainInfo();
        $retained = $whm->responseMeta();
        $http->addResponse(new Response($status, [], $body));

        try {
            $whm->accounts()->getDomainInfo();
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
        yield 'list payload' => [200, '{"metadata":{"result":1},"data":[{"domains":[]}]}',
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
        $whm->accounts()->getDomainInfo();
        $retained = $whm->responseMeta();
        $http->addException(new class ('whm root:fixture-token') extends RuntimeException implements
            ClientExceptionInterface
        {
        });

        try {
            $whm->accounts()->getDomainInfo();
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

    /**
     * Execute get_domain_info against one successful envelope carrying the given payload.
     *
     * @return array<string, mixed>
     */
    private function domains(string $data): array
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], self::envelope($data)));

        return $client->whm()->accounts()->getDomainInfo();
    }

    private static function envelope(string $data): string
    {
        return '{"metadata":{"result":1,"command":"get_domain_info"},"data":' . $data . '}';
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
