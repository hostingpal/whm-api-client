<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independent source-shaped fixtures: no generated spec supplies expectations. */
final class UapiDomainRecommendationsCompatibilityTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function injectedRows(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach ([['false', '0'], ['true', '1'], ['0', '0'], ['1', '1']] as [$wire, $normalized]) {
                yield "$route premium $wire" => [$route, $wire, $normalized];
            }
        }
    }

    #[DataProvider('injectedRows')]
    public function testInjectedSearchRowKeepsStoreRowsPricesAndOpenMetadata(
        string $route,
        string $wire,
        string $normalized,
    ): void {
        [$client, $http] = self::client($route);
        $payload = self::mixedRows($wire);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('npr');
        $result = $account->domainRecommendations()->domainSuggestions('searched.example');
        $expected = json_decode(self::mixedRows($normalized, false), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $result);
        $suggestions = $result['suggestions'] ?? null;
        self::assertNotNull($suggestions);
        self::assertSame(
            ['z-store.example', 'searched.example', 'a-store.example'],
            array_keys($suggestions),
        );
        $premiumCosts = $suggestions['z-store.example']['premium_cost_pricing'];
        self::assertNotNull($premiumCosts);
        $registration = $premiumCosts['register'] ?? null;
        self::assertNotNull($registration);
        self::assertSame(['term+02', 'term+01'], array_keys(
            $registration,
        ));
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame(['current'], $account->responseMeta()->uapiWarnings());
    }

    public function testOptionalSearchedFlagPreservesZeroAndOmission(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $http->addResponse(new Response(200, [], self::envelope(
                $route,
                '{"suggestions":{"plain":{"pricing":[],"is_premium":0,"premium_cost_pricing":null},'
                . '"tagged":{"is_searched_term":0,"pricing":[],"is_premium":1,"premium_cost_pricing":null}}}',
            )));
            self::assertSame(['suggestions' => [
                'plain' => ['is_premium' => 0, 'premium_cost_pricing' => null, 'pricing' => []],
                'tagged' => ['is_premium' => 1, 'is_searched_term' => 0,
                    'premium_cost_pricing' => null, 'pricing' => []],
            ]], $client->uapi('npr')->domainRecommendations()->domainSuggestions('term'));
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function malformedPayloads(): iterable
    {
        $rows = [
            'missing premium' => '{"pricing":[],"premium_cost_pricing":null}',
            'missing pricing' => '{"is_premium":false,"premium_cost_pricing":null}',
            'missing premium costs' => '{"pricing":[],"is_premium":false}',
            'null pricing' => '{"pricing":null,"is_premium":false,"premium_cost_pricing":null}',
            'bad price' => '{"pricing":[{"register":{"value":1}}],"is_premium":false,"premium_cost_pricing":null}',
            'bad costs' => '{"pricing":[],"is_premium":false,"premium_cost_pricing":{"register":{"x":null}}}',
        ];
        foreach (['null', '2', '-1', '1.0', '"1"', '"false"', '"true"', '[]', '{}'] as $bad) {
            $rows["premium $bad"] = '{"pricing":[],"is_premium":' . $bad . ',"premium_cost_pricing":null}';
        }
        foreach (['null', 'false', 'true', '2', '-1', '1.0', '"1"', '"false"', '[]', '{}'] as $bad) {
            $rows["searched $bad"] = '{"pricing":[],"is_premium":false,"premium_cost_pricing":null,'
                . '"is_searched_term":' . $bad . '}';
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($rows as $name => $row) {
                yield "$route $name" => [$route, 'suggestions', '{"suggestions":{'
                    . '"valid":{"pricing":[],"is_premium":0,"premium_cost_pricing":null},"invalid":' . $row . '}}'];
            }
            foreach (
                [null, 'null', '[{}]', '{"suggestions":[{}]}', '{"suggestions":{"x":null}}',
                '{"meta":{"page":"1"},"suggestions":{}}'] as $i => $payload
            ) {
                yield "$route container $i" => [$route, 'suggestions', $payload];
            }
            foreach (['is_premium', 'is_domain_available', 'is_tld_available'] as $field) {
                foreach (['0', '1'] as $integer) {
                    yield "$route availability $field $integer" => [$route, 'availability',
                        '{"' . $field . '":' . $integer . '}'];
                }
            }
        }
    }

    #[DataProvider('malformedPayloads')]
    public function testMalformedNeighborsFailOnceWithFreshMetadataAndUnvalidatedPayload(
        string $route,
        string $operation,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{"is_enabled":0}', 'old')));
        $account->domainRecommendations()->isEnabled();
        $prior = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $caught = null;
        try {
            if ($operation === 'availability') {
                $account->domainRecommendations()->domainAvailability('searched.example');
            } else {
                $account->domainRecommendations()->domainSuggestions('searched.example');
            }
        } catch (PayloadMismatchException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(PayloadMismatchException::class, $caught);
        self::assertTrue($caught->outcomeConfirmed);
        self::assertSame(
            $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $caught->payload,
        );
        self::assertNotSame($prior, $account->responseMeta());
        self::assertSame($account->responseMeta(), $caught->metadata);
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame(['current'], $account->responseMeta()->uapiWarnings());
        self::assertCount(2, $http->getRequests());
    }

    private static function mixedRows(string $premium, bool $unknown = true): string
    {
        return '{"meta":{"page":1,"future":{"opaque":[true,null," exact "]}},"suggestions":{'
            . '"z-store.example":{"is_premium":0,"premium_cost_pricing":{"register":{'
            . '"term+02":{"value":"9007199254740993.00000001"},"term+01":{"prefix":" $ ","suffix":" USD "}}},'
            . '"pricing":[{"period":2,"register":{"display_value":" exact ","value":"0001.2300"},"renew":null}]},'
            . '"searched.example":{"is_premium":' . $premium . ',"is_searched_term":1,'
            . '"premium_cost_pricing":null,"pricing":[]' . ($unknown ? ',"unreviewed":true' : '') . '},'
            . '"a-store.example":{"is_premium":1,"premium_cost_pricing":null,"pricing":[]}}}';
    }

    private static function envelope(string $route, ?string $payload, string $warning = 'current'): string
    {
        $result = '{"status":1,"warnings":["' . $warning . '"]'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
                'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
