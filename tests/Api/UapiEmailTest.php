<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class UapiEmailTest extends TestCase
{
    #[DataProvider('routes')]
    public function testListPopsPreservesMailboxAndMainAccountRecords(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $email = $account->email();
        self::assertSame($email, $account->email());
        self::assertSame([], $http->getRequests());
        $initialMetadata = $account->responseMeta();
        self::assertNull($initialMetadata);
        $records = [
            ['email' => 'exampleuser', 'login' => 'Main Account', 'suspended_incoming' => 0, 'suspended_login' => 0],
            ['email' => 'mailbox@example.test', 'login' => 'mailbox@example.test',
                'suspended_incoming' => 1, 'suspended_login' => 0],
            ['email' => 'other@example.test', 'suspended_login' => 1],
        ];
        $http->addResponse(new Response(200, [], self::envelope($route, json_encode($records, JSON_THROW_ON_ERROR))));

        self::assertSame($records, $email->listPops());

        self::assertRequest($http, $route, '');
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['synthetic notice'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('parameterCases')]
    public function testParametersDistinguishOmissionFromFalseAndPreserveLiteralRegex(
        string $route,
        ?bool $noValidate,
        ?string $regex,
        ?bool $skipMain,
        string $query,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));

        self::assertSame([], $client->uapi('exampleuser')->email()->listPops(
            noValidate: $noValidate,
            regex: $regex,
            skipMain: $skipMain,
        ));

        self::assertRequest($http, $route, $query);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?bool, ?string, ?bool, string}> */
    public static function parameterCases(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route . ' omitted' => [$route, null, null, null, ''];
            yield $route . ' false' => [$route, false, null, false, 'no_validate=0&skip_main=0'];
            yield $route . ' true' => [$route, true, null, true, 'no_validate=1&skip_main=1'];
            yield $route . ' mixed' => [$route, false, '', true, 'no_validate=0&regex=&skip_main=1'];
            yield $route . ' literal filter' => [
                $route, null, '^mailbox+tag@example.test&skip_main=1 /?#%', null,
                'regex=%5Emailbox%2Btag%40example.test%26skip_main%3D1%20%2F%3F%23%25',
            ];
        }
    }

    #[DataProvider('routes')]
    public function testAllFieldsMayBeOmitted(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '[{}]')));

        self::assertSame([[]], $client->uapi('exampleuser')->email()->listPops());
    }

    #[DataProvider('invalidPayloads')]
    public function testPayloadMismatchRetainsConfirmedOutcomeAndMetadata(string $route, string $payload): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->email()->listPops();
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        try {
            $account->email()->listPops();
            self::fail('Invalid mailbox payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $error->payload);
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (
                ['null', '{}', '[null]', '[{"email":null}]', '[{"login":null}]',
                '[{"suspended_incoming":null}]', '[{"suspended_login":null}]',
                '[{"suspended_incoming":"1"}]', '[{"suspended_login":true}]',
                '[{"suspended_login":2}]'] as $payload
            ) {
                yield $route . ' ' . $payload => [$route, $payload];
            }
        }
    }

    #[DataProvider('routes')]
    public function testApiFailureRetainsMetadataWithoutRetry(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]', 0)));

        try {
            $account->email()->listPops();
            self::fail('API failure must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(['Synthetic unavailable role'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield $route => [$route];
        }
    }

    private static function assertRequest(MockClient $http, string $route, string $query): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $base = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Email&cpanel.function=list_pops'
            : 'https://api.example.test:2083/execute/Email/list_pops';
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

    private static function envelope(string $route, string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ',"data":' . $payload
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic unavailable role"]')
            . ',"warnings":["synthetic notice"]}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Email","func":"list_pops","result":' . $result . '}',
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
