<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EmailSectionFixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @phpstan-import-type Report from EmailSectionFixture */
final class EmailQuotaEncodingTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'] as $key => $value
        ) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function encodings(): iterable
    {
        $cases = [
            'native integer' => ['32', 'canonical-integer'],
            'string integer' => ['"32"', 'canonical-integer'],
            'zero integer string' => ['"0"', 'canonical-integer'],
            'largest integer string' => ['"' . PHP_INT_MAX . '"', 'canonical-integer'],
            'overflowing integer string' => ['"' . PHP_INT_MAX . '0"', 'other-numeric'],
            'zero fraction' => ['"32.000000"', 'integral-fixed-point'],
            'zero integer and fraction' => ['"0.0"', 'integral-fixed-point'],
            'negative integral fixed point' => ['"-32.000"', 'integral-fixed-point'],
            'negative zero fixed point' => ['"-0.000"', 'integral-fixed-point'],
            'nonzero fraction' => ['"32.0001"', 'fractional-fixed-point'],
            'negative nonzero fraction' => ['"-0.001"', 'fractional-fixed-point'],
            'exponent string' => ['"32e0"', 'other-numeric'],
            'leading positive sign' => ['"+32"', 'other-numeric'],
            'leading whitespace' => ['" 32"', 'other-numeric'],
            'trailing whitespace' => ['"32 "', 'other-numeric'],
            'leading zero integer' => ['"032"', 'other-numeric'],
            'leading zero fixed point' => ['"0032.000"', 'other-numeric'],
            'negative zero integer' => ['"-0"', 'other-numeric'],
            'missing fractional digits' => ['"32."', 'other-numeric'],
            'missing integer digits' => ['".0"', 'other-numeric'],
            'native float' => ['32.0', 'other-numeric'],
            'arbitrary string' => ['"private-value"', 'other'],
            'null' => ['null', 'other'],
            'boolean' => ['true', 'other'],
            'object' => ['{"private-key":"private-value"}', 'other'],
            'list' => ['["private-value"]', 'other'],
            'empty string' => ['""', 'other'],
        ];
        foreach (['wrapped', 'bare', 'bridge'] as $form) {
            foreach ($cases as $name => [$payload, $encoding]) {
                yield $form . ' ' . $name => [$form, $payload, $encoding];
            }
        }
    }

    #[DataProvider('encodings')]
    public function testDiagnosticUsesTheOriginalResponseAndOnlyFixedCategories(
        string $form,
        string $payload,
        string $expected,
    ): void {
        $server = new EmailSectionServer();
        $server->overrides['get_pop_quota/' . ($form === 'bridge' ? 'bridge' : 'direct') . '/1'] = $payload;
        $report = self::runFixture($server, $form);
        $route = $form === 'bridge' ? 'bridged-uapi' : 'direct-uapi';
        $records = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'initial_quota' && $record['route'] === $route));
        self::assertCount(1, $records);
        self::assertSame($expected, $records[0]['scalar_encoding'] ?? null);
        self::assertCount(
            count($report['probes']),
            $server->requests,
            'Classification must never repeat an HTTP call.',
        );
        self::assertSame([], $server->mailboxes);
        foreach ($report['probes'] as $record) {
            if ($record['operation'] !== 'uapi:Email/get_pop_quota') {
                self::assertArrayNotHasKey('scalar_encoding', $record);
            }
        }
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testFractionalFixedPointDiagnosticCannotChangeThePrimaryFailureOrItsGate(): void
    {
        $server = new EmailSectionServer();
        $server->overrides['get_pop_quota/direct/1'] = '"32.000000000000000001"';
        $server->overrides['get_pop_quota/bridge/1'] = '"32.000000000000000001"';
        $report = self::runFixture($server, 'wrapped');
        self::assertSame('failed', $report['status']);
        self::assertSame('failed', $report['assessment']['status']);
        self::assertSame(['direct-uapi' => 'cleaned', 'bridged-uapi' => 'cleaned'], $report['resources']);
        self::assertCount(29, $report['probes']);
        self::assertCount(29, $server->requests);
        foreach ($report['probes'] as $record) {
            if ($record['operation'] === 'uapi:Email/get_pop_quota') {
                self::assertSame('fractional-fixed-point', $record['scalar_encoding'] ?? null);
                self::assertSame('success', $record['api_outcome']);
                self::assertSame('failed', $record['payload_validation']);
                self::assertSame('failed', $record['probe_outcome']);
                self::assertSame('not_checked', $record['expectation']);
                self::assertSame(['$' => ['numeric-string']], $record['payload_paths']);
                self::assertSame([['path' => '$', 'kind' => 'type_mismatch']], $record['discrepancies']);
            }
        }
    }

    public function testAcceptedZeroFractionRetainsOriginalFingerprintAndDiagnostic(): void
    {
        $server = new EmailSectionServer();
        $server->overrides['get_pop_quota/direct/1'] = '"32.000000000000000000"';
        $server->overrides['get_pop_quota/bridge/1'] = '"32.000000000000000000"';
        $report = self::runFixture($server, 'wrapped');
        self::assertSame('passed', $report['status']);
        self::assertSame('passed', $report['assessment']['status']);
        self::assertCount(45, $report['probes']);
        self::assertCount(45, $server->requests);
        foreach ($report['probes'] as $record) {
            if ($record['phase'] === 'initial_quota') {
                self::assertSame('integral-fixed-point', $record['scalar_encoding'] ?? null);
                self::assertSame('success', $record['api_outcome']);
                self::assertSame('passed', $record['payload_validation']);
                self::assertSame('passed', $record['expectation']);
                self::assertSame(['$' => ['numeric-string']], $record['payload_paths']);
                self::assertSame([], $record['discrepancies']);
            }
        }
    }

    public function testDiagnosticRetainsNoValuesOrDigitCounts(): void
    {
        $server = new EmailSectionServer();
        $marker = '314159265358979323846264338327950288419716939937510.000000000000000';
        $server->overrides['get_pop_quota/direct/1'] = '"' . $marker . '"';
        $report = self::runFixture($server, 'bare');
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($marker, $json);
        self::assertStringNotContainsString('private', $json);
        foreach ($report['probes'] as $record) {
            self::assertSame([], array_diff(array_keys($record), [
                'operation', 'variant', 'route', 'method', 'server_version', 'observed_at', 'revision',
                'source_sha256', 'map_sha256', 'generator_tree', 'overlay_sha256', 'effective_contract_sha256',
                'http_status', 'api_outcome', 'payload_validation', 'probe_outcome', 'failure_kind',
                'response_paths', 'payload_paths', 'discrepancies', 'phase', 'expectation', 'scalar_encoding',
            ]));
        }
    }

    public function testPresentMalformedWrapperPreventsFallbackForTheDiagnostic(): void
    {
        $server = new EmailSectionServer();
        $report = self::runFixture(
            $server,
            'wrapped',
            '{"status":1,"data":"32.000","result":null}',
        );
        $quota = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'initial_quota' && $record['route'] === 'direct-uapi'))[0];
        self::assertSame('other', $quota['scalar_encoding'] ?? null);
        self::assertSame('unavailable', $quota['api_outcome']);
        self::assertSame('failed', $quota['probe_outcome']);
        self::assertCount(count($report['probes']), $server->requests);
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedResponses(): iterable
    {
        yield 'invalid direct JSON' => ['wrapped', '{private-invalid-json'];
        yield 'missing direct payload' => ['bare', '{"status":1}'];
        yield 'missing bare status' => ['bare', '{"data":"32.000"}'];
        yield 'present direct result list blocks fallback' => [
            'wrapped', '{"status":1,"data":"32.000","result":[]}',
        ];
        yield 'present bridge UAPI null blocks fallback' => [
            'bridge', '{"metadata":{"result":1},"data":{"uapi":null,"data":"32.000","status":1},'
                . '"result":{"status":1,"data":"32.000"}}',
        ];
    }

    #[DataProvider('malformedResponses')]
    public function testMalformedOrMissingPayloadsClassifyWithoutFallbackOrTextLeaks(string $form, string $raw): void
    {
        $server = new EmailSectionServer();
        $report = self::runFixture($server, $form, $raw);
        $route = $form === 'bridge' ? 'bridged-uapi' : 'direct-uapi';
        $quota = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'initial_quota' && $record['route'] === $route))[0];
        self::assertSame('other', $quota['scalar_encoding'] ?? null);
        self::assertSame('failed', $quota['probe_outcome']);
        self::assertCount(count($report['probes']), $server->requests);
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testMissingCapturedResponseCannotReuseThePreviousPayload(): void
    {
        $server = new EmailSectionServer();
        $server->failures['get_pop_quota/direct/1'] = 'lost';
        $report = self::runFixture($server, 'bare');
        $quota = array_values(array_filter($report['probes'], static fn (array $record): bool =>
            $record['phase'] === 'initial_quota' && $record['route'] === 'direct-uapi'))[0];
        self::assertArrayNotHasKey('scalar_encoding', $quota);
        self::assertSame([], $quota['payload_paths']);
        self::assertCount(count($report['probes']), $server->requests);
    }

    /** @return Report */
    private static function runFixture(EmailSectionServer $server, string $form, ?string $quotaRaw = null): array
    {
        $http = new class ($server, $form, $quotaRaw) implements ClientInterface {
            public function __construct(
                private readonly EmailSectionServer $server,
                private readonly string $form,
                private readonly ?string $quotaRaw,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $response = $this->server->sendRequest($request);
                if ($request->getUri()->getPort() !== 2083) {
                    if (
                        $this->form === 'bridge' && $this->quotaRaw !== null
                        && str_contains($request->getUri()->getQuery(), 'cpanel.function=get_pop_quota')
                    ) {
                        return $response->withBody((new Psr17Factory())->createStream($this->quotaRaw));
                    }

                    return $response;
                }
                $body = (string) $response->getBody();
                if ($this->quotaRaw !== null && str_ends_with($request->getUri()->getPath(), '/get_pop_quota')) {
                    $body = $this->quotaRaw;
                } elseif ($this->form === 'wrapped') {
                    $body = '{"result":' . $body . '}';
                }

                return $response->withBody((new Psr17Factory())->createStream($body));
            }
        };

        return (new EmailSectionFixture($http, ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'], 'a1b2c3', [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64), 'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]))->run();
    }
}
