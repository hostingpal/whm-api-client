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

/** Offline response and encoding regressions; successful live unlinking remains unverified. */
final class UapiExternalAuthenticationReviewTest extends TestCase
{
    #[DataProvider('routes')]
    public function testProviderArgumentUsesCorrectedWireNameOnly(string $route): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, 'null')));

        self::assertNull($client->uapi('npr')->externalAuthentication()->removeAuthnLink(
            provider: 'provider +&=雪',
            subjectUniqueIdentifier: '123456789012345678901',
        ));

        self::assertUnlinkRequest($http, $route, '123456789012345678901');
        self::assertCount(1, $http->getRequests());
    }

    #[DataProvider('identifiers')]
    public function testGetterIdentifiersRoundTripWithoutNumericCoercion(
        string $route,
        string $wireValue,
        int|string $expected,
        string $encoded,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"provider_id":"provider +&=雪","subject_unique_identifier":' . $wireValue . '}]',
        )));
        $http->addResponse(new Response(200, [], self::envelope($route, 'null')));

        $links = $account->externalAuthentication()->getAuthnLinks();
        self::assertSame([['provider_id' => 'provider +&=雪', 'subject_unique_identifier' => $expected]], $links);
        self::assertArrayHasKey('subject_unique_identifier', $links[0]);
        self::assertArrayHasKey('provider_id', $links[0]);
        self::assertNull($account->externalAuthentication()->removeAuthnLink(
            provider: $links[0]['provider_id'],
            subjectUniqueIdentifier: $links[0]['subject_unique_identifier'],
        ));

        self::assertUnlinkRequest($http, $route, $encoded);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, int|string, string}> */
    public static function identifiers(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            yield "$route integer" => [$route, '123', 123, '123'];
            yield "$route zero" => [$route, '0', 0, '0'];
            yield "$route 21 digits" => [
                $route, '"123456789012345678901"', '123456789012345678901', '123456789012345678901',
            ];
            yield "$route leading zeros" => [$route, '"00123"', '00123', '00123'];
            yield "$route opaque string" => [$route, '"subject +&"', 'subject +&', 'subject%20%2B%26'];
        }
    }

    #[DataProvider('invalidIdentifiers')]
    public function testGetterRejectsNonIntegerNonStringIdentifiers(string $route, string $value): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"subject_unique_identifier":' . $value . '}]',
        )));
        try {
            $account->externalAuthentication()->getAuthnLinks();
            self::fail('Expected malformed identifier rejection.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIdentifiers(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['null', 'true', '1.5', '[]', '{}', '123456789012345678901'] as $value) {
                yield "$route $value" => [$route, $value];
            }
        }
    }

    #[DataProvider('routes')]
    public function testUnlinkApiFailureRetainsMetadataWithoutRetrying(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));
        try {
            $account->externalAuthentication()->removeAuthnLink('provider +&=雪', 'subject');
            self::fail('Expected an API failure.');
        } catch (ApiErrorException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['Synthetic unavailable module or invalid identifier'], $error->errors());
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

    private static function assertUnlinkRequest(MockClient $http, string $route, string $identifier): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            $route === 'bridge'
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=ExternalAuthentication&cpanel.function=remove_authn_link'
                : 'https://api.example.test:2083/execute/ExternalAuthentication/remove_authn_link',
            (string) $request->getUri(),
        );
        self::assertSame(
            'provider_id=provider%20%2B%26%3D%E9%9B%AA&subject_unique_identifier=' . $identifier,
            (string) $request->getBody(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
    }

    private static function envelope(string $route, string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ',"data":' . $payload . ',"errors":'
            . ($status === 1 ? 'null' : '["Synthetic unavailable module or invalid identifier"]') . '}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
