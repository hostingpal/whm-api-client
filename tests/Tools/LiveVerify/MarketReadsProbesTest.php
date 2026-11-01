<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\MarketReadsProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** @phpstan-import-type Context from EvidenceRunner */
final class MarketReadsProbesTest extends TestCase
{
    private const LOGIN_QUERY = 'provider=cPStore'
        . '&url_after_login=http%3A%2F%2Fhostname.example.com%2Fredirectionlocation.cgi%3Fstate';

    private const PROVIDERS = '[{"display_name":"Synthetic Store","name":"synthetic"}]';

    private const PRODUCTS = '[{"product":"Synthetic Certificate","product_id":"176","license_term":"1",'
        . '"requires_ip":"0","enabled":1,"x_price_per_wildcard_domain":"123.45",'
        . '"x_identity_verification":{"label":"Synthetic label","name":"synthetic",'
        . '"private-extra-key":"private-identity-value"}}]';

    private const PENDING = '[{"csr":"-----BEGIN CERTIFICATE REQUEST-----PRIVATECSR","order_item_id":"8675309",'
        . '"domains":["private-domain.example"],"identity_verification":{"private-identity-key":"private-person"}}]';

    private const DCV = '{"dcv_file_allowed_characters":["a",0],"dcv_file_extension":"txt",'
        . '"dcv_file_random_character_count":32,"dcv_file_relative_path":".well-known/pki-validation",'
        . '"dcv_max_redirects":0,"dcv_user_agent_string":"Synthetic DCV"}';

    private const LOGIN_URL = '"https://synthetic.example/login?access_token=PRIVATETOKEN"';

    public function testCatalogExecutesOnlyElevenExactAccountBoundGetRequests(): void
    {
        $probes = MarketReadsProbes::load();
        self::assertCount(11, $probes);
        $cases = [
            ['get_providers_list', '', self::PROVIDERS],
            ['get_all_products', '', self::PRODUCTS],
            ['get_pending_ssl_certificates', 'provider=cPStore', '[]'],
            ['get_provider_specific_dcv_constraints', 'provider=cPStore', self::DCV],
            ['get_login_url', self::LOGIN_QUERY, self::LOGIN_URL],
        ];
        $expected = [['whm1:version', 'whm', 'https://test.oblak.host:2087/json-api/version?api.version=1',
            '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}']];
        foreach ($cases as [$function, $query, $payload]) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $uri = $route === 'direct-uapi'
                    ? 'https://test.oblak.host:2083/execute/Market/' . $function . ($query === '' ? '' : '?' . $query)
                    : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                        . '&cpanel.module=Market&cpanel.function=' . $function . ($query === '' ? '' : '&' . $query);
                $response = '{"status":1,"errors":null,"data":' . $payload . '}';
                $expected[] = ['uapi:Market/' . $function, $route, $uri,
                    $route === 'direct-uapi'
                        ? $response : '{"metadata":{"result":1},"data":{"uapi":' . $response . '}}'];
            }
        }
        foreach ($probes as $index => $probe) {
            [$operation, $route, $uri, $response] = $expected[$index];
            self::assertSame($operation, $probe->operation);
            self::assertSame($route, $probe->route);
            self::assertSame($uri, $probe->uri);
            self::assertNull($probe->unavailable);
            self::assertSame('GET', $probe->spec['method']);
            $factory = new Psr17Factory();
            $http = new MockClient($factory);
            $http->addResponse(new Response(200, [], $response));
            $client = new Client([
                'host' => 'test.oblak.host',
                'authType' => $route === 'direct-uapi' ? 'cpanel' : 'whm',
                'username' => $route === 'direct-uapi' ? 'npr' : 'root',
                'token' => 'synthetic-token',
            ], new HttpTransport(new RequestGuard($http, $uri), $factory, $factory));
            ($probe->invoke)($client);
            self::assertCount(1, $http->getRequests());
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('GET', $request->getMethod());
            self::assertSame($uri, (string) $request->getUri());
            self::assertSame('', (string) $request->getBody());
            foreach (['POST', 'DELETE'] as $method) {
                try {
                    (new RequestGuard($http, $uri))->sendRequest(new Request($method, $uri));
                    self::fail('Catalog requests must stay read-only.');
                } catch (LogicException) {
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    public function testLoginRedirectIsThePinnedExampleEncodedOnce(): void
    {
        $query = [];
        parse_str(self::LOGIN_QUERY, $query);

        self::assertSame([
            'provider' => 'cPStore',
            'url_after_login' => 'http://hostname.example.com/redirectionlocation.cgi?state',
        ], $query);
        self::assertSame($query['url_after_login'], MarketReadsProbes::URL_AFTER_LOGIN);
        self::assertSame($query['provider'], MarketReadsProbes::PROVIDER);
    }

    public function testBeadReadsPartitionIntoCapturedAndNotRunOperations(): void
    {
        $captured = [];
        foreach (MarketReadsProbes::load() as $probe) {
            if ($probe->operation !== 'whm1:version') {
                $captured[$probe->operation] = true;
            }
        }

        self::assertSame([
            'uapi:Market/get_all_products',
            'uapi:Market/get_login_url',
            'uapi:Market/get_pending_ssl_certificates',
            'uapi:Market/get_provider_specific_dcv_constraints',
            'uapi:Market/get_providers_list',
        ], self::sorted(array_keys($captured)));
        self::assertSame([
            'uapi:Market/get_certificate_status_details' => 'no-pending-order',
            'uapi:Market/get_completion_url' => 'no-known-product-name',
            'uapi:Market/get_product_info' => 'no-known-product-name',
            'uapi:Market/get_ssl_certificate_if_available' => 'no-pending-order',
        ], MarketReadsProbes::NOT_RUN);
        self::assertSame([], array_intersect_key($captured, MarketReadsProbes::NOT_RUN));
    }

    public function testNoCatalogEntryCarriesAnInventedSelector(): void
    {
        $forbidden = ['order_item_id', 'orderItemId', 'product_name', 'cpanel-ssl', 'access_token', 'login_token'];
        foreach (MarketReadsProbes::load() as $probe) {
            foreach ($forbidden as $word) {
                self::assertStringNotContainsString($word, $probe->uri);
            }
        }
    }

    public function testPopulatedSensitivePayloadsPassWithoutPersistingValuesOrUnknownKeys(): void
    {
        $http = self::mock(self::PENDING);
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            MarketReadsProbes::load(),
            'market-reads-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );

        self::assertSame('passed', $report['status']);
        self::assertCount(11, $http->getRequests());
        self::assertSame(['numeric-string'], $report['probes'][3]['payload_paths']['$[].product_id']);
        self::assertSame(['string'], $report['probes'][3]['payload_paths']['$[].x_identity_verification.*']);
        self::assertSame(['string'], $report['probes'][5]['payload_paths']['$[].csr']);
        self::assertSame(['int', 'string'], $report['probes'][7]['payload_paths']['$.dcv_file_allowed_characters[]']);
        self::assertSame(['$' => ['string']], $report['probes'][9]['payload_paths']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        $privateValues = [
            'PRIVATETOKEN', 'PRIVATECSR', 'BEGIN CERTIFICATE', 'synthetic.example', 'private-identity-value',
            'private-extra-key', 'private-identity-key', 'private-person', 'private-domain.example', '8675309',
            'Synthetic Certificate', 'private-whm-token', 'private-cpanel-token',
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testEmptyPendingListPassesButObservesNoRowFields(): void
    {
        $report = (new ReadOnlySectionRunner(self::mock('[]'), self::context()))->run(
            MarketReadsProbes::load(),
            'market-reads-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );

        self::assertSame('passed', $report['status']);
        foreach ([5, 6] as $index) {
            self::assertSame(['$' => ['empty-list']], $report['probes'][$index]['payload_paths']);
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    /** Direct and bridged responses for all five operations, after the WHM version response. */
    private static function mock(string $pending): MockClient
    {
        $http = new MockClient();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        foreach ([self::PROVIDERS, self::PRODUCTS, $pending, self::DCV, self::LOGIN_URL] as $payload) {
            $response = '{"status":1,"errors":null,"data":' . $payload . '}';
            $http->addResponse(new Response(200, [], $response));
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":' . $response . '}}'));
        }

        return $http;
    }

    /** @return Context */
    private static function context(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'revision' => str_repeat('b', 40),
            'source_hashes' => ['whm1' => $hash, 'uapi' => $hash],
            'map_sha256' => $hash,
            'generator_tree' => str_repeat('c', 40),
            'overlay_sha256' => $hash,
        ];
    }
}
