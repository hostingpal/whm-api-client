<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\WhmTransfersReviewProbes;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmTransfersReviewProbesTest extends TestCase
{
    public function testCatalogExecutesExactlyVersionAndReviewedFeatureListSchema(): void
    {
        $probes = WhmTransfersReviewProbes::load();
        self::assertCount(2, $probes);
        $expected = [
            ['whm1:version', 'https://test.oblak.host:2087/json-api/version?api.version=1', null,
                '{"version":"11.138.0.19"}'],
            ['whm1:transfer_module_schema',
                'https://test.oblak.host:2087/json-api/transfer_module_schema?api.version=1'
                . '&module=FeatureListRemoteRoot',
                'feature-list-remote-root', '{"schema":{"keys":{"name":{"def":"Synthetic SQL-like text"}},'
                    . '"primary":["name"],"required":["name"]}}'],
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
                    new Request('GET', $uri . '&module=AccountLocal'),
                    ...($operation === 'whm1:transfer_module_schema' ? [
                        new Request('GET', str_replace('FeatureListRemoteRoot', 'FeaturesListRemoteRoot', $uri)),
                        new Request('GET', str_replace('&module=FeatureListRemoteRoot', '', $uri)),
                    ] : []),
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

    public function testReviewedCatalogCapturesSanitizedStructureWithoutInterpretingSchemaStrings(): void
    {
        $http = new MockClient();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"schema":{"keys":'
            . '{"private-dynamic-key":{"def":"private-definition; DROP TABLE users"}},'
            . '"primary":["private-primary"],"required":["private-required"]},'
            . '"private-unknown-key":"private-unknown-value"}}'));
        $context = [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
        $probes = WhmTransfersReviewProbes::load();
        $report = (new ReadOnlySectionRunner($http, $context))->run(
            $probes,
            'whm-transfers-review-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );
        self::assertSame('failed', $report['status']);
        self::assertCount(2, $http->getRequests());
        self::assertCount(2, $report['probes']);
        $record = $report['probes'][1];
        self::assertSame('passed', $record['payload_validation']);
        self::assertSame('feature-list-remote-root', $record['argument_variant'] ?? null);
        self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $record['discrepancies']);
        self::assertSame(['string'], $record['payload_paths']['$.schema.keys.*.def'] ?? null);
        self::assertSame(['string'], $record['payload_paths']['$.schema.primary[]'] ?? null);
        self::assertSame(['string'], $record['payload_paths']['$.schema.required[]'] ?? null);
        self::assertSame($context['revision'], $record['revision']);
        self::assertSame($context['source_hashes']['whm1'], $record['source_sha256']);
        self::assertSame($context['map_sha256'], $record['map_sha256']);
        self::assertSame($context['generator_tree'], $record['generator_tree']);
        self::assertSame($context['overlay_sha256'], $record['overlay_sha256']);
        self::assertSame(
            hash('sha256', json_encode($probes[1]->spec, JSON_THROW_ON_ERROR)),
            $record['effective_contract_sha256'],
        );
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (
            ['private-dynamic-key', 'private-definition', 'DROP TABLE', 'private-primary', 'private-required',
            'private-unknown-key', 'private-unknown-value', 'private-whm-token', 'private-cpanel-token'] as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertFalse(ReadOnlySectionRunner::passes($probes, $report['probes'], $context));
    }

    public function testEntrypointHelpAndSelectorRejectionNeedNoLiveCredentials(): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--help']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('--output=PATH', $stdout);
        self::assertStringNotContainsString('MUTATIONS', $stdout);
        self::assertSame('', $stderr);
        [$exit, $stdout, $stderr] = self::execute(['--module=AccountLocal']);
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
            [PHP_BINARY, $root . '/tools/live-verify/whm-transfers-review.php', ...$arguments],
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
