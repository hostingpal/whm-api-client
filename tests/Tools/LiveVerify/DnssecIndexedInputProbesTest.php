<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\DnssecIndexedInputProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** @phpstan-import-type Context from EvidenceRunner */
final class DnssecIndexedInputProbesTest extends TestCase
{
    public function testCatalogSendsExactlyThreeLiteralGetRequestsOnce(): void
    {
        $probes = DnssecIndexedInputProbes::load();
        self::assertCount(3, $probes);
        $uris = self::uris();
        foreach ($probes as $index => $probe) {
            $direct = $index === 1;
            self::assertSame($index === 0 ? 'whm1:version' : 'uapi:DNSSEC/fetch_ds_records', $probe->operation);
            self::assertSame(['whm', 'direct-uapi', 'bridged-uapi'][$index], $probe->route);
            self::assertSame($index === 0 ? null : 'singleton-domain-list', $probe->argumentVariant);
            self::assertSame($uris[$index], $probe->uri);
            self::assertSame('GET', $probe->spec['method']);
            self::assertNull($probe->unavailable);
            $factory = new Psr17Factory();
            $http = new MockClient($factory);
            $http->addResponse(new Response(200, [], self::responses()[$index]));
            $client = new Client(
                ['host' => 'test.oblak.host', 'authType' => $direct ? 'cpanel' : 'whm',
                'username' => $direct ? 'npr' : 'root', 'token' => 'synthetic-token'],
                new HttpTransport(new RequestGuard($http, $uris[$index]), $factory, $factory),
            );
            ($probe->invoke)($client);
            self::assertCount(1, $http->getRequests());
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame($uris[$index], (string) $request->getUri());
            self::assertSame('GET', $request->getMethod());
            self::assertSame('', (string) $request->getBody());
            self::assertSame(
                $direct ? 'cpanel npr:synthetic-token' : 'whm root:synthetic-token',
                $request->getHeaderLine('Authorization'),
            );
            $wrongRequests = [
                new Request('POST', $uris[$index]),
                new Request('DELETE', $uris[$index]),
                new Request('GET', $uris[$index], [], 'unexpected'),
                new Request('GET', $uris[$index] . '&extra=1'),
            ];
            if ($index > 0) {
                $wrongRequests[] = new Request('GET', str_replace('npr.rs', 'other.test', $uris[$index]));
                $wrongRequests[] = new Request('GET', str_replace('domain-0=', 'domain=', $uris[$index]));
                $wrongRequests[] = new Request('GET', str_replace('fetch_ds_records', 'enable_dnssec', $uris[$index]));
            }
            if ($index === 2) {
                $wrongRequests[] = new Request(
                    'GET',
                    str_replace('cpanel.user=npr', 'cpanel.user=other', $uris[$index]),
                );
            }
            foreach ($wrongRequests as $wrong) {
                try {
                    (new RequestGuard($http, $uris[$index]))->sendRequest($wrong);
                    self::fail('A changed recipe must be rejected before HTTP.');
                } catch (LogicException) {
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    public function testNewCatalogAssessesStrictlyAndKeepsLabelsAndSanitizedPayloads(): void
    {
        $http = new MockClient();
        foreach (self::responses() as $response) {
            $http->addResponse(new Response(200, [], $response));
        }
        $probes = DnssecIndexedInputProbes::load();
        $context = self::context();
        $report = (new ReadOnlySectionRunner($http, $context))->run(
            $probes,
            'dnssec-indexed-input-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );
        self::assertSame('passed', $report['status']);
        self::assertSame('dnssec-indexed-input-v1', $report['recipe']);
        self::assertCount(3, $http->getRequests());
        self::assertTrue(ReadOnlySectionRunner::passes($probes, $report['probes'], $context));
        foreach ([1, 2] as $index) {
            self::assertSame('singleton-domain-list', $report['probes'][$index]['argument_variant'] ?? null);
            foreach (
                ['argument_variant' => 'scalar-domain', 'route' => 'whm', 'method' => 'POST',
                'operation' => 'uapi:DNSSEC/enable_dnssec', 'discrepancies' => [['path' => '$.*',
                    'kind' => 'unknown_field']]] as $key => $wrong
            ) {
                $records = $report['probes'];
                $records[$index][$key] = $wrong;
                self::assertFalse(ReadOnlySectionRunner::passes($probes, $records, $context));
            }
            $records = $report['probes'];
            unset($records[$index]['argument_variant']);
            self::assertFalse(ReadOnlySectionRunner::passes($probes, $records, $context));
        }
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private-whm-token', 'private-cpanel-token', 'npr.rs'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testCatalogRequiresExplicitOptInBeforeAnyRequest(): void
    {
        $http = new MockClient();
        $caught = null;
        try {
            (new ReadOnlySectionRunner($http, self::context()))->run(
                DnssecIndexedInputProbes::load(),
                'dnssec-indexed-input-v1',
                false,
                ['whm' => 'test-whm', 'cpanel' => 'test-cpanel'],
            );
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $caught, 'No live opt-in must mean no request.');
        self::assertSame([], $http->getRequests());
    }

    /** @return list<string> */
    private static function uris(): array
    {
        return [
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            'https://test.oblak.host:2083/execute/DNSSEC/fetch_ds_records?domain-0=npr.rs',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DNSSEC&cpanel.function=fetch_ds_records&domain-0=npr.rs',
        ];
    }

    /** @return list<string> */
    private static function responses(): array
    {
        return [
            '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}',
            '{"status":1,"data":{"npr.rs":{}}}',
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{"npr.rs":{}}}}}',
        ];
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => 'synthetic-revision',
            'source_hashes' => ['whm1' => 'whm-source', 'uapi' => 'uapi-source'],
            'map_sha256' => 'map', 'generator_tree' => 'generator', 'overlay_sha256' => 'overlay'];
    }
}
