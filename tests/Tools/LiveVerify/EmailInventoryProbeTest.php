<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use Http\Mock\Client;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Exercise the catalog's actual generated calls; a wrong operation, account, route or payload spec must fail. */
final class EmailInventoryProbeTest extends TestCase
{
    /** @param array<string, list<string>> $paths */
    #[DataProvider('probes')]
    public function testEmailInventoryPreservesRouteAndSanitizesRawEvidence(
        string $operation,
        string $route,
        string $uri,
        string $payload,
        array $paths,
    ): void {
        $selected = [];
        foreach (ProbeCatalog::load(dirname(__DIR__, 3)) as $probe) {
            if ($probe->operation === 'uapi:Email/' . $operation && $probe->route === $route) {
                $selected[] = $probe;
            }
        }
        self::assertCount(1, $selected, 'The reviewed operation must have exactly one probe per route.');
        $http = new Client();
        $envelope = '{"status":1,"data":' . $payload . '}';
        $body = $route === 'direct-uapi' ? $envelope
            : '{"metadata":{"result":1},"data":{"uapi":' . $envelope . '}}';
        $http->addResponse(new Response(200, [], $body));
        $records = (new EvidenceRunner($http, [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]))->run($selected, '1', ['whm' => 'private-whm-marker', 'cpanel' => 'private-cpanel-marker']);

        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'direct-uapi' ? 'cpanel npr:private-cpanel-marker' : 'whm root:private-whm-marker',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame('passed', $records[0]['probe_outcome']);
        self::assertSame('success', $records[0]['api_outcome']);
        self::assertSame('passed', $records[0]['payload_validation']);
        self::assertSame([], $records[0]['discrepancies']);
        self::assertSame($paths, $records[0]['payload_paths']);
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        $privateValues = ['private-whm-marker', 'private-cpanel-marker', 'source.example.test', 'target.example.test'];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    /** @return iterable<string, array{string, string, string, string, array<string, list<string>>}> */
    public static function probes(): iterable
    {
        $cases = [
            ['list_mail_domains', '[{"domain":"source.example.test","select":1}]', [
                '$' => ['list'], '$[]' => ['object'], '$[].domain' => ['string'], '$[].select' => ['int'],
            ]],
            ['list_domain_forwarders', '[{"dest":"source.example.test","forward":"target.example.test"}]', [
                '$' => ['list'], '$[]' => ['object'], '$[].dest' => ['string'], '$[].forward' => ['string'],
            ]],
            ['count_pops', '0', ['$' => ['int']]],
            ['list_mail_domains', '[]', ['$' => ['empty-list']]],
            ['list_domain_forwarders', '[]', ['$' => ['empty-list']]],
        ];
        foreach ($cases as $index => [$operation, $payload, $paths]) {
            yield $operation . ' direct ' . $index => [
                $operation, 'direct-uapi', 'https://test.oblak.host:2083/execute/Email/' . $operation, $payload, $paths,
            ];
            yield $operation . ' bridge ' . $index => [
                $operation, 'bridged-uapi',
                'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Email&cpanel.function=' . $operation,
                $payload, $paths,
            ];
        }
    }
}
