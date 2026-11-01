<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Regressions for the structural forms the 2026-11-01 audit recorded on 11.138.0.19.
 *
 * Bodies repeat only the recorded structure with synthetic values; the upstream error text was
 * never retained. They pin how the client reads each recorded form on the route that returned it.
 * They set no contract for these operations and prove no no-match: matchpkgs and listsubdomains are
 * not confirmed no-matches, and the source audit records which other results have no baseline.
 */
final class EmptySearchResultsTest extends TestCase
{
    /** @return iterable<string, array{Closure(Client): mixed, string, mixed}> */
    public static function emptyWhmResults(): iterable
    {
        yield 'showbw keeps its report fields around an empty account list' => [
            static fn (Client $client): mixed => $client->whm()->accounts()->showBandwidth(
                search: 'absent',
                searchtype: 'user',
            ),
            '{"acct":[],"month":11,"reseller":"root","totalused":"0","year":2026}',
            ['acct' => [], 'month' => 11, 'reseller' => 'root', 'totalused' => 0, 'year' => 2026],
        ];
        yield 'matchpkgs keeps an empty pkg list' => [
            static fn (Client $client): mixed => $client->whm()->hostingPlans()->matchPackages(featurelist: 'absent'),
            '{"pkg":[]}',
            ['pkg' => []],
        ];
        yield 'emailtrack_search keeps an empty records list' => [
            static fn (Client $client): mixed => $client->whm()->mail()->emailtrackSearch(
                defer: false,
                failure: false,
                inProgress: false,
                maxResultsByType: 1,
                success: false,
            ),
            '{"records":[]}',
            ['records' => []],
        ];
        yield 'emailtrack_user_stats keeps an empty records list' => [
            static fn (Client $client): mixed => $client->whm()->mail()->emailtrackUserStats(
                endTime: 2,
                sender: 'nobody@absent.invalid',
                startTime: 1,
            ),
            '{"records":[]}',
            ['records' => []],
        ];
        yield 'unique recipient counts keep an empty payload list' => [
            static fn (Client $client): mixed => $client->whm()->mail()->getUniqueSenderRecipientCountPerUser(
                startTime: 1,
                endTime: 2,
            ),
            '{"payload":[]}',
            ['payload' => []],
        ];
        yield 'php_get_vhosts_by_version keeps an empty vhost list' => [
            static fn (Client $client): mixed => $client->whm()->webServer()->phpGetVhostsByVersion(
                version: 'ea-php00',
            ),
            '{"vhosts":[]}',
            [],
        ];
    }

    /** @return iterable<string, array{Closure(Client): mixed, string, mixed}> */
    public static function emptyUapiResults(): iterable
    {
        yield 'list_pops' => [
            static fn (Client $client): mixed => $client->uapi('npr')->email()->listPops(regex: 'absent'),
            '[]',
            [],
        ];
        yield 'list_pops_with_disk' => [
            static fn (Client $client): mixed => $client->uapi('npr')->email()->listPopsWithDisk(
                domain: 'absent.invalid',
            ),
            '[]',
            [],
        ];
        yield 'listsubdomains' => [
            static fn (Client $client): mixed => $client->uapi('npr')->subDomain()->listSubdomains(regex: 'absent'),
            '[]',
            [],
        ];
        yield 'list_features_like stays a list, not its nullable alternative' => [
            static fn (Client $client): mixed => $client->uapi('npr')->features()->listFeaturesLike(
                pattern: 'absent',
                isRegex: false,
            ),
            '[]',
            [],
        ];
        yield 'autocompletedir' => [
            static fn (Client $client): mixed => $client->uapi('npr')->fileman()->autocompleteDirectory(
                path: 'absent',
            ),
            '[]',
            [],
        ];
        yield 'list_ssl_items' => [
            static fn (Client $client): mixed => $client->uapi('npr')->ssl()->listSslItems(domains: 'absent.invalid'),
            '[]',
            [],
        ];
        yield 'Bandwidth query with a past window keeps an empty object' => [
            static fn (Client $client): mixed => $client->uapi('npr')->bandwidth()->query(
                grouping: 'domain',
                end: 2,
                start: 1,
            ),
            '{}',
            [],
        ];
    }

    /** @param Closure(Client): mixed $invoke */
    #[DataProvider('emptyWhmResults')]
    public function testRecordedWhmEmptyFormStaysAnEmptyResult(Closure $invoke, string $data, mixed $expected): void
    {
        $client = self::client('{"metadata":{"result":1,"reason":"OK","command":"synthetic","version":1},"data":'
            . $data . '}');

        self::assertSame($expected, $invoke($client));
    }

    /** @param Closure(Client): mixed $invoke */
    #[DataProvider('emptyUapiResults')]
    public function testRecordedBridgedUapiEmptyFormStaysAnEmptyResult(
        Closure $invoke,
        string $data,
        mixed $expected,
    ): void {
        $http = self::http(self::bridged(self::envelope(1, $data, 'null')));

        self::assertSame($expected, $invoke(self::client($http)));
        self::assertSame('/json-api/uapi_cpanel', self::requestPath($http));
    }

    /** @param Closure(Client): mixed $invoke */
    #[DataProvider('emptyUapiResults')]
    public function testRecordedDirectUapiEmptyFormStaysAnEmptyResult(
        Closure $invoke,
        string $data,
        mixed $expected,
    ): void {
        $http = self::http(self::envelope(1, $data, 'null'));

        self::assertSame($expected, $invoke(self::direct($http)));
        self::assertStringStartsWith('/execute/', self::requestPath($http));
    }

    public function testBridgedBandwidthDomainOutsideTheAccountStaysAnApiError(): void
    {
        $http = self::http(self::bridged(self::envelope(0, 'null', '["synthetic failure"]')));

        try {
            self::client($http)->uapi('npr')->bandwidth()->query(grouping: 'domain', domains: 'absent.invalid');
            self::fail('The recorded status-0 form must not read as an empty report.');
        } catch (ApiErrorException $error) {
            $metadata = $error->metadata;
            self::assertNotNull($metadata);
            self::assertSame(1, $metadata->whmResult());
            self::assertSame(0, $metadata->uapiStatus());
            self::assertSame(['synthetic failure'], $error->errors());
        }
        self::assertSame('/json-api/uapi_cpanel', self::requestPath($http));
    }

    public function testDirectBandwidthDomainOutsideTheAccountStaysAnApiError(): void
    {
        $http = self::http(self::envelope(0, 'null', '["synthetic failure"]'));

        try {
            self::direct($http)->uapi('npr')->bandwidth()->query(grouping: 'domain', domains: 'absent.invalid');
            self::fail('The recorded status-0 form must not read as an empty report.');
        } catch (ApiErrorException $error) {
            $metadata = $error->metadata;
            self::assertNotNull($metadata);
            self::assertNull($metadata->whmResult());
            self::assertSame(0, $metadata->uapiStatus());
            self::assertSame(['synthetic failure'], $error->errors());
        }
        self::assertSame('/execute/Bandwidth/query', self::requestPath($http));
    }

    public function testAccountSummaryOfAnAbsentUserStaysAnApiError(): void
    {
        $client = self::client('{"metadata":{"result":0,"reason":"synthetic failure","command":"accountsummary",'
            . '"version":1},"data":{"acct":null}}');

        try {
            $client->whm()->accounts()->accountSummary(user: 'absent');
            self::fail('An entity lookup must not read as an empty result.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
        }
    }

    /** The bare UAPI object the capture recorded; only direct access returns it unwrapped. */
    private static function envelope(int $status, string $data, string $errors): string
    {
        return '{"status":' . $status . ',"data":' . $data . ',"errors":' . $errors
            . ',"messages":null,"metadata":{},"warnings":null}';
    }

    private static function bridged(string $envelope): string
    {
        return '{"metadata":{"result":1,"reason":"OK"},"data":{"uapi":' . $envelope . '}}';
    }

    private static function http(string $body): MockClient
    {
        $http = new MockClient(new Psr17Factory());
        $http->addResponse(new Response(200, [], $body));

        return $http;
    }

    /** Build WHM credentials, so uapi('npr') takes the bridge. */
    private static function client(string|MockClient $response): Client
    {
        $factory = new Psr17Factory();

        return new Client(
            ['host' => 'server.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'synthetic-token'],
            new HttpTransport(is_string($response) ? self::http($response) : $response, $factory, $factory),
        );
    }

    /** Build cPanel credentials for npr, so uapi('npr') takes the direct route and parses the bare object. */
    private static function direct(MockClient $http): Client
    {
        $factory = new Psr17Factory();

        return new Client(
            ['host' => 'server.example.test', 'authType' => 'cpanel', 'username' => 'npr',
                'token' => 'synthetic-token'],
            new HttpTransport($http, $factory, $factory),
        );
    }

    /** Report the route a client really took, so a test cannot pass on the wrong one. */
    private static function requestPath(MockClient $http): string
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request->getUri()->getPath();
    }
}
