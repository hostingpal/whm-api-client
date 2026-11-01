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

/**
 * Regressions for the no-match forms the 2026-11-01 audit observed on 11.138.0.19.
 *
 * Bodies repeat only the recorded structure with synthetic values; the upstream error text was
 * never retained. They pin which absence stays an empty result and which stays a failure.
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
        yield 'an absent PHP package is an empty vhost list, not a failure' => [
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
        yield 'Bandwidth query with a past window is an empty object' => [
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
    public function testConfirmedWhmNoMatchReturnsItsEmptyResult(Closure $invoke, string $data, mixed $expected): void
    {
        $client = self::client('{"metadata":{"result":1,"reason":"OK","command":"synthetic","version":1},"data":'
            . $data . '}');

        self::assertSame($expected, $invoke($client));
    }

    /** @param Closure(Client): mixed $invoke */
    #[DataProvider('emptyUapiResults')]
    public function testConfirmedUapiNoMatchReturnsItsEmptyResult(Closure $invoke, string $data, mixed $expected): void
    {
        $client = self::client('{"metadata":{"result":1,"reason":"OK"},"data":{"uapi":{"status":1,"data":'
            . $data . ',"errors":null,"messages":null,"metadata":{},"warnings":null}}}');

        self::assertSame($expected, $invoke($client));
    }

    public function testBandwidthDomainOutsideTheAccountStaysAnApiError(): void
    {
        $client = self::client('{"metadata":{"result":1,"reason":"OK"},"data":{"uapi":{"status":0,"data":null,'
            . '"errors":["synthetic failure"],"messages":null,"metadata":{},"warnings":null}}}');

        try {
            $client->uapi('npr')->bandwidth()->query(grouping: 'domain', domains: 'absent.invalid');
            self::fail('An unknown domain selector must not read as an empty report.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame(['synthetic failure'], $error->errors());
        }
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

    private static function client(string $body): Client
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $http->addResponse(new Response(200, [], $body));

        return new Client(
            ['host' => 'server.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'synthetic-token'],
            new HttpTransport($http, $factory, $factory),
        );
    }
}
