<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmSystemPackagesReviewProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmSystemPackagesReviewProbesTest extends TestCase
{
    public function testCatalogExecutesExactlyVersionAndFourReviewedReads(): void
    {
        $probes = WhmSystemPackagesReviewProbes::load();
        self::assertCount(5, $probes);
        $expected = [
            ['whm1:version', 'https://test.oblak.host:2087/json-api/version?api.version=1', null,
                '{"version":"11.138.0.19"}'],
            ['whm1:package_manager_get_package_info',
                'https://test.oblak.host:2087/json-api/package_manager_get_package_info?api.version=1'
                . '&package=ea-apache24',
                'ea-apache24', '{"packages":[{"package":"private-package",'
                    . '"pkg_dep":{"requires":[["private",">=","1"]]},'
                    . '"vendor":"private-vendor","version_installed":""}]}'],
            ['whm1:get_rpm_version_data',
                'https://test.oblak.host:2087/json-api/get_rpm_version_data?api.version=1&section=target_settings',
                'target-settings', '{"private-target":"private-setting"}'],
            ['whm1:get_rpm_version_data',
                'https://test.oblak.host:2087/json-api/get_rpm_version_data?api.version=1&section=rpm_groups',
                'rpm-groups', '{"private-group":["private-rpm"]}'],
            ['whm1:package_manager_resolve_actions',
                'https://test.oblak.host:2087/json-api/package_manager_resolve_actions?api.version=1'
                . '&package=ea-apache24&ns=ea', 'ea-apache24-ea',
                '{"install":["private-install"],"unaffected":["private-unaffected"],'
                    . '"uninstall":["private-uninstall"],"upgrade":["private-upgrade"]}'],
        ];
        foreach ($probes as $index => $probe) {
            [$operation, $uri, $variant, $payload] = $expected[$index];
            self::assertSame($operation, $probe->operation);
            self::assertSame('whm', $probe->route);
            self::assertSame('GET', $probe->spec['method']);
            self::assertSame($uri, $probe->uri);
            self::assertSame($variant, $probe->argumentVariant);
            self::assertSame($operation . '#GET', $probe->sourceVariant ?? $operation . '#GET');
            self::assertNull($probe->unavailable);
            $factory = new Psr17Factory();
            $http = new MockClient($factory);
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":' . $payload . '}'));
            $client = new Client([
                'host' => 'test.oblak.host', 'authType' => 'whm',
                'username' => 'root', 'token' => 'synthetic-token',
            ], new HttpTransport(new RequestGuard($http, $uri), $factory, $factory));

            ($probe->invoke)($client);

            self::assertCount(1, $http->getRequests());
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('GET', $request->getMethod());
            self::assertSame($uri, (string) $request->getUri());
            self::assertSame('', (string) $request->getBody());
            foreach (
                [
                    new Request('POST', $uri),
                    new Request('DELETE', $uri),
                    new Request('GET', $uri, [], 'unexpected body'),
                    new Request('GET', str_replace('test.oblak.host', 'other.example', $uri)),
                    new Request('GET', $uri . '&unreviewed=value'),
                    new Request('GET', str_replace('ea-apache24', 'other-package', $uri) . '&changed=1'),
                    new Request('GET', 'https://test.oblak.host:2087/json-api/list_rpms?api.version=1'),
                    new Request(
                        'GET',
                        'https://test.oblak.host:2087/json-api/package_manager_list_packages?api.version=1',
                    ),
                    new Request('GET', str_replace('api.version=1', 'api.version=2', $uri)),
                    new Request('GET', 'https://test.oblak.host:2087/json-api/matchpkgs?api.version=1'),
                    new Request('GET', 'https://test.oblak.host:2087/json-api/addpkg?api.version=1'),
                ] as $forbidden
            ) {
                try {
                    (new RequestGuard($http, $uri))->sendRequest($forbidden);
                    self::fail('The reviewed read catalog must reject any changed request before I/O.');
                } catch (LogicException) {
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    public function testReviewedCatalogCapturesOnlyTypesAndStrictlyRejectsUnknownFields(): void
    {
        foreach ([false, true] as $unknown) {
            $http = new MockClient();
            $payloads = [
                '{"version":"11.138.0.19"}',
                '{"packages":[{"package":"private-package","pkg_dep":{"requires":[["private",">=","1"]]},'
                    . '"vendor":"private-vendor","version_installed":""}]'
                    . ($unknown ? ',"private-extra":"private-value"' : '') . '}',
                '{"private-target":"private-setting"}', '{"private-group":["private-rpm"]}',
                '{"install":["private-install"],"unaffected":["private-unaffected"],'
                    . '"uninstall":["private-uninstall"],"upgrade":["private-upgrade"]}',
            ];
            foreach ($payloads as $payload) {
                $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":' . $payload . '}'));
            }
            $context = [
                'revision' => str_repeat('a', 40),
                'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
                'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
                'overlay_sha256' => str_repeat('f', 64),
            ];
            $probes = WhmSystemPackagesReviewProbes::load();
            $report = (new ReadOnlySectionRunner($http, $context))->run(
                $probes,
                'whm-system-packages-review-v1',
                '1',
                ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
            );
            self::assertSame($unknown ? 'failed' : 'passed', $report['status']);
            self::assertCount(5, $http->getRequests());
            self::assertCount(5, $report['probes']);
            foreach ($report['probes'] as $index => $record) {
                self::assertSame('passed', $record['payload_validation']);
                self::assertSame($context['revision'], $record['revision']);
                self::assertSame($context['source_hashes']['whm1'], $record['source_sha256']);
                self::assertSame($context['map_sha256'], $record['map_sha256']);
                self::assertSame($context['generator_tree'], $record['generator_tree']);
                self::assertSame($context['overlay_sha256'], $record['overlay_sha256']);
                self::assertSame(
                    hash('sha256', json_encode($probes[$index]->spec, JSON_THROW_ON_ERROR)),
                    $record['effective_contract_sha256'],
                );
                self::assertSame(
                    $index === 1 && $unknown ? [['path' => '$.*', 'kind' => 'unknown_field']] : [],
                    $record['discrepancies'],
                );
            }
            self::assertSame(
                ['numeric-string', 'string'],
                $report['probes'][1]['payload_paths']['$.packages[].pkg_dep.requires[][]'],
            );
            self::assertSame(['string'], $report['probes'][2]['payload_paths']['$.*']);
            self::assertSame(['string'], $report['probes'][3]['payload_paths']['$.*[]']);
            self::assertStringNotContainsString('private-', json_encode($report, JSON_THROW_ON_ERROR));
            self::assertSame(!$unknown, ReadOnlySectionRunner::passes($probes, $report['probes'], $context));
            $changed = $report['probes'];
            $changed[2]['argument_variant'] = 'rpm-groups';
            self::assertFalse(ReadOnlySectionRunner::passes($probes, $changed, $context));
        }
    }

    public function testEntrypointHelpAndSelectorRejectionNeedNoLiveCredentials(): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--help']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('--output=PATH', $stdout);
        self::assertStringNotContainsString('MUTATIONS', $stdout);
        self::assertSame('', $stderr);
        [$exit, $stdout, $stderr] = self::execute(['--package=other-package']);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertSame("Read-only section verification failed (configuration_failure).\n", $stderr);
    }

    /**
     * Run only the help or invalid-argument path with every live opt-in and credential cleared.
     *
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private static function execute(array $arguments): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            [PHP_BINARY, $root . '/tools/live-verify/whm-system-packages-review.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['WHM_API_LIVE' => '', 'WHM_API_LIVE_ACCOUNT' => '', 'WHM_API_LIVE_DOMAIN' => '',
                'OBLAK_TEST_WHM_KEY' => '', 'OBLAK_TEST_NPR_KEY' => ''],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [proc_close($process), $stdout, $stderr];
    }
}
