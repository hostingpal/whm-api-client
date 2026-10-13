<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;

final class UapiInventoryTest extends TestCase
{
    #[DataProvider('successfulOperations')]
    public function testInventoryOperationsPreservePayloadAndRequestAccount(
        string $route,
        string $operation,
        string $module,
        string $function,
        string $query,
        string $data,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $uapi = $client->uapi('npr');
        $uapi->domains();
        $uapi->php();
        $uapi->features();
        $uapi->dns();
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::success($route, $data)));

        self::assertSame($expected, self::invoke($uapi, $operation));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function . ($query === '' ? '' : '&' . $query)
            : 'https://api.example.test:2083/execute/' . $module . '/' . $function
                . ($query === '' ? '' : '?' . $query);
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        $metadata = $uapi->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapi['status'] ?? null);
        self::assertSame(['inventory notice'], $metadata->uapi['warnings'] ?? null);
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whm['result'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, string, string, string, mixed}> */
    public static function successfulOperations(): iterable
    {
        $cases = [
            'primary' => ['DomainInfo', 'primary_domain', '', '{"primary_domain":"example.test"}', 'example.test'],
            'primary false' => [
                'DomainInfo', 'primary_domain', 'hide_temporary_domains=0', '{"primary_domain":"example.test"}',
                'example.test',
            ],
            'primary true' => [
                'DomainInfo', 'primary_domain', 'hide_temporary_domains=1', '{"primary_domain":null}', null,
            ],
            'aliases' => [
                'DomainInfo', 'main_domain_builtin_subdomain_aliases', '', '["mail","www","mail"]',
                ['mail', 'www', 'mail'],
            ],
            'aliases false' => [
                'DomainInfo', 'main_domain_builtin_subdomain_aliases', 'hide_temporary_domains=0', '[]', [],
            ],
            'aliases true' => [
                'DomainInfo', 'main_domain_builtin_subdomain_aliases', 'hide_temporary_domains=1', '[]', [],
            ],
            'default' => [
                'LangPHP', 'php_get_system_default_version', '', '{"version":"custom-php-8.4+vendor"}',
                'custom-php-8.4+vendor',
            ],
            'default null' => ['LangPHP', 'php_get_system_default_version', '', '{"version":null}', null],
            'handler home' => ['LangPHP', 'php_get_domain_handler', 'type=home', '{"php_handler":"cgi"}', 'cgi'],
            'handler vhost' => [
                'LangPHP', 'php_get_domain_handler', 'type=vhost&vhost=example.test', '{"php_handler":"suphp"}',
                'suphp',
            ],
            'impacted domain' => [
                'LangPHP', 'php_get_impacted_domains', 'domain=example.test', '{"domains":["example.test"]}',
                ['example.test'],
            ],
            'impacted default' => [
                'LangPHP', 'php_get_impacted_domains', 'system_default=1', '{"domains":[]}', [],
            ],
            'impacted both' => [
                'LangPHP', 'php_get_impacted_domains', 'domain=example.test&system_default=0',
                '{"domains":["sub.example.test","sub.example.test"]}', ['sub.example.test', 'sub.example.test'],
            ],
            'impacted list' => [
                'LangPHP', 'php_get_impacted_domains', 'domain=example.test&domain=sub.example.test',
                '{"domains":["example.test","sub.example.test"]}', ['example.test', 'sub.example.test'],
            ],
            'impacted list false' => [
                'LangPHP', 'php_get_impacted_domains', 'domain=example.test&domain=sub.example.test&system_default=0',
                '{"domains":[]}', [],
            ],
            'impacted list true' => [
                'LangPHP', 'php_get_impacted_domains', 'domain=example.test&domain=sub.example.test&system_default=1',
                '{"domains":["inherited.test"]}', ['inherited.test'],
            ],
            'impacted encoded list' => [
                'LangPHP', 'php_get_impacted_domains',
                'domain=example.test&domain=other%26system_default%3D1.test&system_default=0',
                '{"domains":[]}', [],
            ],
            'features' => ['Features', 'list_features', '', '{"php":1,"custom_feature":0}', [
                'php' => 1,
                'custom_feature' => 0,
            ]],
            'features empty' => ['Features', 'list_features', '', '{}', []],
            'features numeric name' => ['Features', 'list_features', '', '{"123":1,"001":0}', [123 => 1, '001' => 0]],
            'lookup' => ['DNS', 'lookup', 'domain=example.test', '["example.test has address 192.0.2.1"]', [
                'example.test has address 192.0.2.1',
            ]],
            'lookup null' => ['DNS', 'lookup', 'domain=example.test', 'null', null],
            'lookup empty' => ['DNS', 'lookup', 'domain=example.test', '[]', []],
            'generated' => [
                'DNS', 'fetch_cpanel_generated_domains', 'domain=example.test',
                '[{"domain":"example.test."},{},{"domain":"mail.example.test."}]',
                [['domain' => 'example.test.'], [], ['domain' => 'mail.example.test.']],
            ],
            'generated empty' => ['DNS', 'fetch_cpanel_generated_domains', 'domain=example.test', '[]', []],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $operation => [$module, $function, $query, $data, $expected]) {
                yield $route . ' ' . $operation => [$route, $operation, $module, $function, $query, $data, $expected];
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadRetainsCurrentEnvelopeMetadata(
        string $route,
        string $operation,
        string $data,
    ): void {
        [$client, $http] = self::client($route);
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::success($route, $data)));

        try {
            self::invoke($uapi, $operation);
            self::fail('Expected malformed inventory data to be rejected.');
        } catch (InvalidResponseException $exception) {
            self::assertNotNull($uapi->responseMeta());
            self::assertSame($uapi->responseMeta(), $exception->metadata);
            self::assertSame(1, $exception->metadata?->uapi['status'] ?? null);
            self::assertSame($route === 'bridge' ? 1 : null, $exception->metadata?->whm['result'] ?? null);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [
            'primary' => ['{}', 'null', '[]', '{"primary_domain":false}', '{"primary_domain":12}'],
            'aliases' => ['null', '{}', '[null]', '[1]', '{"0":"www"}'],
            'default' => ['{}', 'null', '[]', '{"version":8.4}', '{"version":false}'],
            'handler home' => ['{}', 'null', '[]', '{"php_handler":null}', '{"php_handler":1}'],
            'impacted domain' => [
                '{}', 'null', '[]', '{"domains":null}', '{"domains":{}}', '{"domains":[false]}',
            ],
            'features' => ['null', '[]', '{"php":true}', '{"php":"1"}', '{"php":2}', '{"php":null}'],
            'lookup' => ['{}', 'false', '"example.test"', '[null]', '[1]', '{"0":"record"}'],
            'generated' => ['null', '{}', '[[]]', '[null]', '[{"domain":null}]', '[{"domain":1}]'],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $operation => $payloads) {
                foreach ($payloads as $index => $data) {
                    yield $route . ' ' . $operation . ' ' . $index => [$route, $operation, $data];
                }
            }
        }
    }

    public function testBridgeGroupsKeepTargetsAndMetadataIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $npr = $client->uapi('npr');
        $other = $client->uapi('other');
        $http->addResponse(new Response(200, [], self::success('bridge', '{"php":1}')));
        $http->addResponse(new Response(200, [], self::success('bridge', '[{"domain":"other.test."}]')));

        self::assertSame(['php' => 1], $npr->features()->list());
        $retained = $npr->responseMeta();
        self::assertNull($other->responseMeta());
        self::assertSame([['domain' => 'other.test.']], $other->dns()->generatedDomains('other.test'));
        self::assertSame($retained, $npr->responseMeta());
        self::assertNotSame($retained, $other->responseMeta());
        self::assertNull($client->whm()->responseMeta());
        $requests = $http->getRequests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('cpanel.user=npr&cpanel.module=Features', (string) $requests[0]->getUri());
        self::assertStringContainsString('cpanel.user=other&cpanel.module=DNS', (string) $requests[1]->getUri());
    }

    #[DataProvider('invalidArguments')]
    public function testSemanticArgumentFailuresAreReportedByApiWithNewMetadata(
        string $operation,
        string $expectedQuery,
    ): void {
        [$client, $http] = self::client('direct');
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::success('direct', '{"version":"ea-php84"}')));
        $uapi->php()->systemDefaultVersion();
        $retained = $uapi->responseMeta();
        $http->addResponse(new Response(200, [], '{"result":{"status":0,"errors":["Invalid parameters"]}}'));

        try {
            self::invoke($uapi, $operation);
            self::fail('Expected upstream parameter error.');
        } catch (ApiErrorException $exception) {
            self::assertNotSame($retained, $uapi->responseMeta());
            self::assertSame($uapi->responseMeta(), $exception->metadata);
            self::assertSame(0, $exception->metadata?->uapi['status'] ?? null);
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($expectedQuery, $request->getUri()->getQuery());
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'vhost requires name' => ['handler missing vhost', 'type=vhost'];
        yield 'unknown handler type' => ['handler unknown type', 'type=unknown'];
        yield 'impacted requires selection' => ['impacted no selection', ''];
        yield 'false default requires domain' => ['impacted false only', 'system_default=0'];
    }

    /** @param array<array-key, mixed> $domains Deliberately violate the documented list contract. */
    #[DataProvider('invalidDomainLists')]
    public function testInvalidImpactedDomainListClearsMetadataBeforeIo(string $route, array $domains): void
    {
        [$client, $http] = self::client($route);
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::success($route, '{"version":"ea-php84"}')));
        $uapi->php()->systemDefaultVersion();
        $retained = $uapi->responseMeta();

        try {
            (new ReflectionMethod($uapi->php(), 'impactedDomains'))->invoke($uapi->php(), $domains);
            self::fail('Expected an invalid repeated domain list to be rejected before I/O.');
        } catch (InvalidArgumentException) {
            self::assertNull($uapi->responseMeta());
            self::assertSame(1, $retained?->uapi['status'] ?? null);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function invalidDomainLists(): iterable
    {
        $cases = [
            'empty' => [],
            'associative' => ['first' => 'example.test'],
            'sparse' => [1 => 'example.test'],
            'integer member' => ['example.test', 2],
            'null member' => ['example.test', null],
            'nested member' => ['example.test', ['sub.example.test']],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $name => $domains) {
                yield $route . ' ' . $name => [$route, $domains];
            }
        }
    }

    public function testInvalidBridgedDomainListClearsOnlyOwningHandleMetadata(): void
    {
        [$client, $http] = self::client('bridge');
        $whm = $client->whm();
        $npr = $client->uapi('npr');
        $other = $client->uapi('other');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.15"}}'));
        $http->addResponse(new Response(200, [], self::success('bridge', '{"php":1}')));
        $http->addResponse(new Response(200, [], self::success('bridge', '{"version":"ea-php84"}')));
        $whm->server()->version();
        $other->features()->list();
        $npr->php()->systemDefaultVersion();
        $whmMetadata = $whm->responseMeta();
        $otherMetadata = $other->responseMeta();
        $retained = $npr->responseMeta();
        self::assertNotNull($whmMetadata);
        self::assertNotNull($otherMetadata);
        self::assertNotNull($retained);

        try {
            $npr->php()->impactedDomains([]);
            self::fail('Expected an invalid repeated domain list to be rejected before I/O.');
        } catch (InvalidArgumentException) {
            self::assertNull($npr->responseMeta());
            self::assertSame(1, $retained->whm['result'] ?? null);
            self::assertSame(1, $retained->uapi['status'] ?? null);
            self::assertSame($whmMetadata, $whm->responseMeta());
            self::assertSame($otherMetadata, $other->responseMeta());
        }
        self::assertCount(3, $http->getRequests());
    }

    #[DataProvider('routes')]
    public function testTransportFailureClearsMetadataWithoutRetry(string $route): void
    {
        [$client, $http] = self::client($route);
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::success($route, '{"php":1}')));
        $uapi->features()->list();
        $retained = $uapi->responseMeta();
        $http->addException(new class ('private-token') extends RuntimeException implements ClientExceptionInterface {
        });

        try {
            $uapi->dns()->lookup('example.test');
            self::fail('Expected transport failure.');
        } catch (TransportException $exception) {
            self::assertNull($uapi->responseMeta());
            self::assertNull($exception->metadata);
            self::assertSame(1, $retained?->uapi['status'] ?? null);
            self::assertStringNotContainsString('private-token', $exception->getMessage());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    #[DataProvider('missingLookupData')]
    public function testDnsLookupRejectsMissingDataInsteadOfReturningExplicitNull(string $route, string $body): void
    {
        [$client, $http] = self::client($route);
        $uapi = $client->uapi('npr');
        $http->addResponse(new Response(200, [], $body));

        try {
            $uapi->dns()->lookup('example.test');
            self::fail('Expected missing DNS lookup data to be rejected.');
        } catch (InvalidResponseException $exception) {
            self::assertNotNull($uapi->responseMeta());
            self::assertSame($uapi->responseMeta(), $exception->metadata);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingLookupData(): iterable
    {
        yield 'wrapped' => ['direct', '{"result":{"status":1}}'];
        yield 'bare' => ['bare', '{"status":1}'];
        yield 'bridge' => ['bridge', '{"metadata":{"result":1},"data":{"uapi":{"status":1}}}'];
    }

    private static function invoke(UapiClient $uapi, string $operation): mixed
    {
        return match ($operation) {
            'primary' => $uapi->domains()->primary(),
            'primary false' => $uapi->domains()->primary(false),
            'primary true' => $uapi->domains()->primary(true),
            'aliases' => $uapi->domains()->builtinAliases(),
            'aliases false' => $uapi->domains()->builtinAliases(false),
            'aliases true' => $uapi->domains()->builtinAliases(true),
            'default', 'default null' => $uapi->php()->systemDefaultVersion(),
            'handler home' => $uapi->php()->domainHandler('home'),
            'handler vhost' => $uapi->php()->domainHandler('vhost', 'example.test'),
            'handler missing vhost' => $uapi->php()->domainHandler('vhost'),
            'handler unknown type' => $uapi->php()->domainHandler('unknown'),
            'impacted domain' => $uapi->php()->impactedDomains('example.test'),
            'impacted default' => $uapi->php()->impactedDomains(null, true),
            'impacted both' => $uapi->php()->impactedDomains('example.test', false),
            'impacted list' => $uapi->php()->impactedDomains(['example.test', 'sub.example.test']),
            'impacted list false' => $uapi->php()->impactedDomains(['example.test', 'sub.example.test'], false),
            'impacted list true' => $uapi->php()->impactedDomains(['example.test', 'sub.example.test'], true),
            'impacted encoded list' => $uapi->php()->impactedDomains(
                ['example.test', 'other&system_default=1.test'],
                false,
            ),
            'impacted no selection' => $uapi->php()->impactedDomains(),
            'impacted false only' => $uapi->php()->impactedDomains(null, false),
            'features', 'features empty', 'features numeric name' => $uapi->features()->list(),
            'lookup', 'lookup null', 'lookup empty' => $uapi->dns()->lookup('example.test'),
            'generated', 'generated empty' => $uapi->dns()->generatedDomains('example.test'),
            default => throw new RuntimeException('Unknown inventory test operation.'),
        };
    }

    private static function success(string $route, string $data): string
    {
        $result = '{"status":1,"errors":null,"messages":null,"warnings":["inventory notice"],"data":' . $data . '}';

        return match ($route) {
            'direct' => '{"result":' . $result . '}',
            'bare' => $result,
            'bridge' => '{"metadata":{"result":1,"command":"uapi_cpanel"},"data":{"uapi":' . $result . '}}',
            default => throw new RuntimeException('Unknown inventory test route.'),
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
