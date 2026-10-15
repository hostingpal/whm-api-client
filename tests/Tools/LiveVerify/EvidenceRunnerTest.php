<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use Http\Mock\Client;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @phpstan-import-type Context from EvidenceRunner */
final class EvidenceRunnerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testFourProbesUseGeneratedClientAndRawSameResponse(): void
    {
        $http = new Client();
        foreach (
            [
            '{"metadata":{"result":1},"data":{"version":"11.138.0.15"}}',
            '{"metadata":{"result":1},"data":{"one":"0.50","private@example.test":"secret"}}',
            '{"status":1,"data":{"main_domain":"example.test","sub_domains":[]}}',
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{"main_domain":"example.test"}}}}',
            ] as $body
        ) {
            $http->addResponse(new Response(200, [], $body));
        }
        $records = (new EvidenceRunner($http, self::context()))->run(
            ProbeCatalog::load(self::ROOT),
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );
        self::assertCount(4, $http->getRequests());
        self::assertSame(['whm', 'whm', 'direct-uapi', 'bridged-uapi'], array_column($records, 'route'));
        self::assertSame(['passed', 'passed', 'passed', 'passed'], array_column($records, 'probe_outcome'));
        self::assertSame(['numeric-string'], $records[1]['payload_paths']['$.one']);
        self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $records[1]['discrepancies']);
        self::assertSame(['empty-list'], $records[2]['payload_paths']['$.sub_domains']);
        self::assertArrayNotHasKey('$.sub_domains[]', $records[2]['payload_paths']);
        $versions = array_values(array_unique(array_column($records, 'server_version')));
        self::assertSame(['11.138.0.15'], $versions);
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        $privateValues = [
            'private@example.test', 'example.test', 'secret', 'synthetic-whm', 'synthetic-cpanel', '0.50',
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testPayloadFailureIsSeparateFromApiOutcome(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":7}}'));
        $records = (new EvidenceRunner($http, self::context()))->run(
            [ProbeCatalog::load(self::ROOT)[0]],
            '1',
            ['whm' => 'synthetic', 'cpanel' => 'synthetic'],
        );
        self::assertSame('success', $records[0]['api_outcome']);
        self::assertSame('failed', $records[0]['payload_validation']);
        self::assertSame('failed', $records[0]['probe_outcome']);
        self::assertSame([['path' => '$.version', 'kind' => 'type_mismatch']], $records[0]['discrepancies']);
        self::assertCount(1, $http->getRequests());
    }

    public function testApiFailureDoesNotLeakUpstreamErrors(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"private error token"}}'));
        $records = (new EvidenceRunner($http, self::context()))->run(
            [ProbeCatalog::load(self::ROOT)[0]],
            '1',
            ['whm' => 'synthetic', 'cpanel' => 'synthetic'],
        );
        self::assertSame('failure', $records[0]['api_outcome']);
        self::assertSame('not_checked', $records[0]['payload_validation']);
        self::assertSame([], $records[0]['discrepancies']);
        self::assertStringNotContainsString('private error token', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function testOptInFailsBeforeAnyRequest(): void
    {
        $http = new Client();
        try {
            (new EvidenceRunner($http, self::context()))->run(
                ProbeCatalog::load(self::ROOT),
                false,
                ['whm' => 'synthetic', 'cpanel' => 'synthetic'],
            );
            self::fail('Missing opt-in must fail.');
        } catch (RuntimeException) {
            self::assertCount(0, $http->getRequests());
        }
    }

    /** @param array{api: string, payload: string, probe: string} $expected */
    #[DataProvider('uapiEnvelopes')]
    public function testEnvelopeOutcomesRemainRouteScoped(string $body, int $index, array $expected): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], $body));
        $records = (new EvidenceRunner($http, self::context()))->run(
            [ProbeCatalog::load(self::ROOT)[$index]],
            '1',
            ['whm' => 'synthetic', 'cpanel' => 'synthetic'],
        );
        self::assertSame($expected['api'], $records[0]['api_outcome']);
        self::assertSame($expected['payload'], $records[0]['payload_validation']);
        self::assertSame($expected['probe'], $records[0]['probe_outcome']);
        if ($expected['api'] !== 'success') {
            self::assertSame([], $records[0]['discrepancies']);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, int, array{api: string, payload: string, probe: string}}> */
    public static function uapiEnvelopes(): iterable
    {
        yield 'wrapped direct' => [
            '{"result":{"status":1,"data":{"main_domain":"example.test"}}}', 2,
            ['api' => 'success', 'payload' => 'passed', 'probe' => 'passed'],
        ];
        yield 'present malformed wrapper wins' => [
            '{"result":null,"status":1,"data":{}}', 2,
            ['api' => 'unavailable', 'payload' => 'not_checked', 'probe' => 'failed'],
        ];
        yield 'bridge outer failure wins' => [
            '{"metadata":{"result":0},"data":{"uapi":{"status":1,"data":{}}}}', 3,
            ['api' => 'failure', 'payload' => 'not_checked', 'probe' => 'failed'],
        ];
        yield 'bridge inner failure' => [
            '{"metadata":{"result":1},"data":{"uapi":{"status":0,"data":{}}}}', 3,
            ['api' => 'failure', 'payload' => 'not_checked', 'probe' => 'failed'],
        ];
        yield 'missing payload' => [
            '{"status":1}', 2,
            ['api' => 'success', 'payload' => 'failed', 'probe' => 'failed'],
        ];
        yield 'invalid json' => [
            'private not JSON', 2,
            ['api' => 'unavailable', 'payload' => 'not_checked', 'probe' => 'failed'],
        ];
    }

    /** @return Context */
    private static function context(): array
    {
        return [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
    }
}
