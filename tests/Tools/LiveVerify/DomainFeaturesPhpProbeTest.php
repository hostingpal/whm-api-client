<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use Http\Mock\Client;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Independent wire and privacy oracles for the three completed modules' new reads. */
final class DomainFeaturesPhpProbeTest extends TestCase
{
    #[DataProvider('calls')]
    public function testReviewedProbeUsesExactRequestAndMasksResponseValues(
        string $module,
        string $function,
        string $query,
        string $payload,
        string $route,
    ): void {
        $probes = array_values(array_filter(
            ProbeCatalog::load(dirname(__DIR__, 3)),
            static fn (Probe $probe): bool =>
                $probe->operation === 'uapi:' . $module . '/' . $function && $probe->route === $route,
        ));
        self::assertCount(1, $probes);
        self::assertNull($probes[0]->unavailable);
        $http = new Client();
        $result = '{"status":1,"data":' . $payload . '}';
        $http->addResponse(new Response(200, [], $route === 'direct-uapi'
            ? $result : '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'));
        $records = (new EvidenceRunner($http, [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]))->run($probes, '1', ['whm' => 'synthetic', 'cpanel' => 'synthetic']);
        self::assertSame('passed', $records[0]['probe_outcome']);
        self::assertSame([], $records[0]['discrepancies']);
        self::assertCount(1, $http->getRequests());
        $request = $http->getRequests()[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        $uri = $route === 'direct-uapi'
            ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
                . ($query === '' ? '' : '?' . $query)
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function
                . ($query === '' ? '' : '&' . $query);
        self::assertSame($uri, (string) $request->getUri());
        self::assertStringNotContainsString('private-', json_encode($records, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function calls(): iterable
    {
        $cases = [
            [
                'DomainInfo',
                'domains_data',
                'format=hash&hide_temporary_domains=0&return_https_redirects_status=0',
                '{}',
            ],
            [
                'DomainInfo',
                'single_domain_data',
                'domain=npr.rs&hide_temporary_domains=0&return_https_redirect_status=0',
                '{}',
            ],
            [
                'Features',
                'get_feature_metadata',
                '',
                '[{"id":"private-feature","name":"private-name","is_plugin":0}]',
            ],
            [
                'Features',
                'has_feature',
                'name=autossl',
                'null',
            ],
            [
                'Features',
                'has_features_like',
                'pattern=mail&is_regex=0',
                '{"has_matching_features":1}',
            ],
            [
                'Features',
                'list_features_like',
                'pattern=mail&is_regex=0',
                '["private-feature"]',
            ],
            [
                'LangPHP',
                'php_get_vhost_versions',
                '',
                '[]',
            ],
            [
                'LangPHP',
                'php_ini_get_user_basic_directives',
                'type=home',
                '[]',
            ],
            [
                'LangPHP',
                'php_ini_get_user_content',
                'type=home',
                '{"content":"private-ini-secret"}',
            ],
            [
                'LangPHP',
                'php_ini_get_user_paths',
                '',
                '{"paths":[]}',
            ],
        ];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach ($cases as [$module, $function, $query, $payload]) {
                yield $route . ' ' . $function => [$module, $function, $query, $payload, $route];
            }
        }
    }
}
