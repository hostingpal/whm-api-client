<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Email;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class UapiEmailInventoryTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, mixed}> */
    public static function results(): iterable
    {
        $cases = [
            'domains retain order' => [
                'list_mail_domains', '[{"domain":"primary.test"},{"domain":"addon.test","select":1}]',
                [['domain' => 'primary.test'], ['domain' => 'addon.test', 'select' => 1]],
            ],
            'domain fields omitted' => ['list_mail_domains', '[{}, {"select":0}]', [[], ['select' => 0]]],
            'domains empty' => ['list_mail_domains', '[]', []],
            'unknown domain field' => ['list_mail_domains', '[{"domain":"primary.test","extra":"ignored"}]',
                [['domain' => 'primary.test']]],
            'forwarding preserves wire names' => ['list_domain_forwarders',
                '[{"dest":"source.test","forward":"target.test"}]',
                [['dest' => 'source.test', 'forward' => 'target.test']]],
            'forwarding fields omitted' => ['list_domain_forwarders', '[{}, {"dest":"source.test"}]',
                [[], ['dest' => 'source.test']]],
            'forwarding empty' => ['list_domain_forwarders', '[]', []],
            'unknown forwarding field' => ['list_domain_forwarders', '[{"forward":"target.test","extra":3}]',
                [['forward' => 'target.test']]],
            'zero count' => ['count_pops', '0', 0],
            'nonzero count' => ['count_pops', '7', 7],
            'zero string count' => ['count_pops', '"0"', 0],
            'nonzero string count' => ['count_pops', '"7"', 7],
            'largest canonical integer string count' => ['count_pops', '"' . PHP_INT_MAX . '"', PHP_INT_MAX],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $name => [$operation, $payload, $expected]) {
                yield $route . ' ' . $name => [$route, $operation, $payload, $expected];
            }
        }
    }

    #[DataProvider('results')]
    public function testInventoryUsesAccountBoundGetAndPreservesDocumentedPayload(
        string $route,
        string $operation,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $email = $account->email();
        self::assertSame([], $http->getRequests());
        $initialMetadata = $account->responseMeta();
        self::assertNull($initialMetadata);
        $http->addResponse(new Response(200, [], self::envelope($route, $operation, $payload)));

        self::assertSame($expected, self::invoke($email, $operation));

        self::assertRequest($http, $route, $operation);
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['synthetic notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertSame($email, $account->email());
        self::assertSame($metadata, $account->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?bool, ?bool, ?bool, ?string, string}> */
    public static function domainArguments(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route . ' omitted' => [$route, null, null, null, null, ''];
            yield $route . ' false' => [$route, false, false, false, null,
                'add_www=0&include_wildcard=0&return_temporary_domain=0'];
            yield $route . ' true' => [$route, true, true, true, 'primary.test',
                'add_www=1&include_wildcard=1&return_temporary_domain=1&select=primary.test'];
            yield $route . ' mixed with empty select' => [$route, false, null, true, '',
                'add_www=0&return_temporary_domain=1&select='];
            yield $route . ' literal select' => [$route, null, true, null, 'primary.test&cpanel.user=other /+#%',
                'include_wildcard=1&select=primary.test%26cpanel.user%3Dother%20%2F%2B%23%25'];
        }
    }

    #[DataProvider('domainArguments')]
    public function testMailDomainArgumentsPreserveOmissionFalseAndLiteralSelect(
        string $route,
        ?bool $addWww,
        ?bool $includeWildcard,
        ?bool $returnTemporaryDomain,
        ?string $select,
        string $query,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, 'list_mail_domains', '[]')));

        self::assertSame([], $client->uapi('exampleuser')->email()->listMailDomains(
            addWww: $addWww,
            includeWildcard: $includeWildcard,
            returnTemporaryDomain: $returnTemporaryDomain,
            select: $select,
        ));

        self::assertRequest($http, $route, 'list_mail_domains', $query);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function forwarderArguments(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route . ' omitted' => [$route, null, ''];
            yield $route . ' empty' => [$route, '', 'domain='];
            yield $route . ' literal domain' => [$route, 'source.test&cpanel.user=other /+#%',
                'domain=source.test%26cpanel.user%3Dother%20%2F%2B%23%25'];
        }
    }

    #[DataProvider('forwarderArguments')]
    public function testForwarderDomainFilterPreservesOmissionAndLiteralText(
        string $route,
        ?string $domain,
        string $query,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, 'list_domain_forwarders', '[]')));

        self::assertSame([], $client->uapi('exampleuser')->email()->listDomainForwarders(domain: $domain));

        self::assertRequest($http, $route, 'list_domain_forwarders', $query);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidPayloads(): iterable
    {
        $cases = [
            'list_mail_domains' => [null, 'null', '{}', '[null]', '[{"domain":null}]',
                '[{"select":null}]', '[{"select":"1"}]', '[{"select":true}]'],
            'list_domain_forwarders' => [null, 'null', '{}', '[null]', '[{"dest":null}]',
                '[{"forward":null}]', '[{"dest":1}]', '[{"forward":false}]'],
            'count_pops' => [
                null, 'null', '[]', '{}', 'false', 'true', '0.5', '"0.5"', '"1e2"', '" 7"', '"7 "',
                '"07"', '"+7"', '"unlimited"', '""', '"' . PHP_INT_MAX . '0"',
            ],
        ];
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach ($cases as $operation => $payloads) {
                foreach ($payloads as $payload) {
                    yield $route . ' ' . $operation . ' ' . ($payload ?? 'missing') => [$route, $operation, $payload];
                }
            }
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testPayloadFailureRetainsThisAttemptMetadataAndConfirmedOutcome(
        string $route,
        string $operation,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, 'count_pops', '1')));
        $account->email()->countPops();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $operation, $payload)));

        try {
            self::invoke($account->email(), $operation);
            self::fail('A missing or incompatible inventory payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['list_mail_domains', 'list_domain_forwarders', 'count_pops'] as $operation) {
                yield $route . ' ' . $operation => [$route, $operation];
            }
        }
    }

    #[DataProvider('operations')]
    public function testUnavailableMailRoleReportsApiFailureWithFreshMetadataWithoutRetry(
        string $route,
        string $operation,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, 'count_pops', '1')));
        $account->email()->countPops();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $operation, 'null', 0)));

        try {
            self::invoke($account->email(), $operation);
            self::fail('An unsuccessful API outcome must fail before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(['Synthetic unavailable role'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    private static function invoke(Email $email, string $operation): mixed
    {
        return match ($operation) {
            'list_mail_domains' => $email->listMailDomains(),
            'list_domain_forwarders' => $email->listDomainForwarders(),
            'count_pops' => $email->countPops(),
            default => throw new LogicException('Unsupported inventory fixture operation.'),
        };
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $operation,
        string $query = '',
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $base = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Email&cpanel.function=' . $operation
            : 'https://api.example.test:2083/execute/Email/' . $operation;
        self::assertSame(
            $base . ($query === '' ? '' : ($route === 'bridge' ? '&' : '?') . $query),
            (string) $request->getUri(),
        );
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, string $operation, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic unavailable role"]')
            . ',"warnings":["synthetic notice"]}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Email","func":"' . $operation . '","result":' . $result . '}',
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
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
