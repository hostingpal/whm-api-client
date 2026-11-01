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
    private const ROOT = __DIR__ . '/../../..';

    private const ARTIFACT = '2026-11-01-market-reads.json';

    private const CAPTURE_REVISION = 'eba1711a62e71d614a50cb5eeb2259aa00adbbea';

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

    /** @var array<mixed>|null */
    private static ?array $uapi = null;

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
        self::assertSame(
            'url_after_login',
            self::pinned('paths', '/Market/get_login_url', 'get', 'parameters', 1, 'name'),
        );
        $example = self::pinned('paths', '/Market/get_login_url', 'get', 'parameters', 1, 'schema', 'example');
        $query = [];
        parse_str(self::LOGIN_QUERY, $query);

        self::assertSame(['provider' => MarketReadsProbes::PROVIDER, 'url_after_login' => $example], $query);
        self::assertSame($example, MarketReadsProbes::URL_AFTER_LOGIN);
    }

    public function testPinnedReadOnlyMarketOperationsAreExactlyTheCapturedAndNotRunOperations(): void
    {
        $paths = self::pinned('paths');
        self::assertIsArray($paths);
        $readOnly = [];
        foreach (array_keys($paths) as $path) {
            self::assertIsString($path);
            if (str_starts_with($path, '/Market/') && self::pinned('paths', $path, 'get', 'x-readonly') === true) {
                $readOnly[] = 'uapi:Market/' . substr($path, strlen('/Market/'));
            }
        }

        $catalogued = array_keys(MarketReadsProbes::NOT_RUN);
        foreach (MarketReadsProbes::load() as $probe) {
            if ($probe->operation !== 'whm1:version') {
                $catalogued[] = $probe->operation;
            }
        }
        self::assertCount(9, $readOnly);
        self::assertSame(self::sorted($readOnly), self::sorted(array_values(array_unique($catalogued))));
        foreach (['get_build_cart_url', 'get_license_info'] as $function) {
            self::assertFalse(self::pinned('paths', '/Market/' . $function, 'get', 'x-readonly'));
        }
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
            'uapi:Market/get_certificate_status_details' => 'no-enumerated-order-item',
            'uapi:Market/get_completion_url' => 'no-known-product-name-and-reported-token-url-failure',
            'uapi:Market/get_product_info' => 'no-known-product-name-and-reported-missing-product-files',
            'uapi:Market/get_ssl_certificate_if_available' => 'no-enumerated-order-item',
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
            'Synthetic Certificate', 'private-whm-token', 'private-cpanel-token', 'Synthetic label', 'Synthetic Store',
            'Synthetic DCV', '.well-known/pki-validation', '123.45', 'hostname.example.com', 'synthetic',
            'access_token',
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        $leaves = [];
        array_walk_recursive($report, static function (mixed $leaf) use (&$leaves): void {
            $leaves[] = $leaf;
        });
        // These could match inside a recorded hash, so they are compared with whole values instead.
        foreach (['176', '1', '0', 'a', 'txt', '32', 1, 0, 32] as $short) {
            self::assertNotContains($short, $leaves);
        }
    }

    /**
     * Bind the immutable artifact to the committed catalog by identity and to the hash the documentation records.
     *
     * The artifact stays a valid historical record when a catalogued spec changes, so no current spec hash is compared.
     * This proves only artifact-to-catalog identity and the recorded hashes, not compatibility with the current
     * contract, and it stays offline: no ordinary spec change needs a new capture to keep it green.
     */
    public function testCommittedArtifactMatchesTheCatalogIdentityAndTheRecordedHash(): void
    {
        $bytes = file_get_contents(self::ROOT . '/docs/api/evidence/' . self::ARTIFACT);
        self::assertIsString($bytes);
        $hash = hash('sha256', $bytes);
        $audit = file_get_contents(self::ROOT . '/docs/api/source-audit.md');
        self::assertIsString($audit);
        self::assertStringContainsString(
            '| `market-reads-v1` | [Market reads](evidence/' . self::ARTIFACT . ') | 11 | `' . $hash . '` |',
            $audit,
        );
        self::assertStringContainsString('`' . self::CAPTURE_REVISION . '`', $audit);
        foreach (['docs/api/evidence/README.md', 'tools/live-verify/README.md'] as $document) {
            $contents = file_get_contents(self::ROOT . '/' . $document);
            self::assertIsString($contents);
            self::assertStringContainsString('`' . $hash . '`', $contents);
        }

        $artifact = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($artifact);
        $section = $artifact['read_only_section'] ?? null;
        self::assertIsArray($section);
        self::assertSame('market-reads-v1', $section['recipe'] ?? null);
        self::assertSame('passed', $section['status'] ?? null);
        $records = $section['probes'] ?? null;
        self::assertIsList($records);

        // A probe exposes its method only through its spec, so the catalog's fixed GET is stated here instead.
        $catalog = [];
        foreach (MarketReadsProbes::load() as $probe) {
            $catalog[] = [$probe->operation, $probe->route, 'GET'];
        }
        // This dated snapshot is immutable: `sources refresh` writes a new directory and never replaces this one.
        $whm = hash('sha256', self::source('whm.openapi.json.gz'));
        $uapi = hash('sha256', self::source('cpanel.openapi.json.gz'));
        $identity = [];
        foreach ($records as $record) {
            self::assertIsArray($record);
            $route = $record['route'] ?? null;
            $identity[] = [$record['operation'] ?? null, $route, $record['method'] ?? null];
            self::assertSame('passed', $record['probe_outcome'] ?? null);
            self::assertSame([], $record['discrepancies'] ?? null);
            self::assertSame(self::CAPTURE_REVISION, $record['revision'] ?? null);
            self::assertSame($route === 'whm' ? $whm : $uapi, $record['source_sha256'] ?? null);
        }
        self::assertSame($catalog, $identity);
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

    /** Read one pointer from the pinned UAPI source, which is what the catalog constants claim to follow. */
    private static function pinned(string|int ...$pointer): mixed
    {
        if (self::$uapi === null) {
            $document = json_decode(self::source('cpanel.openapi.json.gz'), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($document);
            self::$uapi = $document;
        }
        $node = self::$uapi;
        foreach ($pointer as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }

    /** Decompress one pinned source. Its hash covers these bytes, as evidence provenance does. */
    private static function source(string $file): string
    {
        $compressed = file_get_contents(self::ROOT . '/docs/api/sources/2026-10-13/' . $file);
        self::assertIsString($compressed);
        $json = gzdecode($compressed);
        self::assertIsString($json);

        return $json;
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
