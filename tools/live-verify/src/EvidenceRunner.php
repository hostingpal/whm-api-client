<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Capture reviewed generated calls, persisting only structural evidence from each original response.
 *
 * @phpstan-type Context array{
 *     revision: string, source_hashes: array{whm1: string, uapi: string}, map_sha256: string,
 *     generator_tree: string, overlay_sha256: string
 * }
 * @phpstan-type Record array{
 *     operation: string, variant: string, route: string, method: string|null, server_version: string|null,
 *     observed_at: string, revision: string, source_sha256: string, map_sha256: string, generator_tree: string,
 *     overlay_sha256: string, effective_contract_sha256: string, http_status: int|null,
 *     api_outcome: string, payload_validation: string, probe_outcome: string, failure_kind: string|null,
 *     response_paths: array<string, list<string>>, payload_paths: array<string, list<string>>,
 *     discrepancies: list<array{path: string, kind: string}>
 * }
 */
final readonly class EvidenceRunner
{
    /**
     * Bind direct authentication explicitly for a designated fixture; the baseline remains npr.
     *
     * @param Context $context
     */
    public function __construct(
        private ClientInterface $http,
        private array $context,
        private string $directUsername = 'npr',
    ) {
    }

    /**
     * Run each selected probe once. No raw values, credentials, request details or exception messages escape.
     *
     * @param list<Probe> $probes
     * @param array{whm: string, cpanel: string} $tokens
     * @return list<Record>
     */
    public function run(array $probes, string|false $optIn, #[SensitiveParameter] array $tokens): array
    {
        if ($optIn !== '1' || $tokens['whm'] === '' || $tokens['cpanel'] === '') {
            throw new RuntimeException('Evidence capture requires WHM_API_LIVE=1 and both test tokens.');
        }
        $blocked = false;
        foreach ($probes as $probe) {
            $blocked = $blocked || $probe->unavailable === 'unsupported';
        }
        $records = [];
        $version = null;
        foreach ($probes as $probe) {
            $record = $this->record($probe);
            if ($blocked || $probe->unavailable !== null) {
                $record['probe_outcome'] = $probe->unavailable ?? 'skipped';
                $record['failure_kind'] = $blocked ? 'unsupported_recipe' : 'missing_recipe';
                $records[] = $record;
                continue;
            }
            $capture = new CaptureClient(new RequestGuard($this->http, $probe->uri));
            $factory = new Psr17Factory();
            $direct = $probe->route === 'direct-uapi';
            $client = new Client([
                'host' => 'test.oblak.host',
                'port' => $direct ? 2083 : 2087,
                'authType' => $direct ? 'cpanel' : 'whm',
                'username' => $direct ? $this->directUsername : 'root',
                'token' => $tokens[$direct ? 'cpanel' : 'whm'],
            ], new HttpTransport($capture, $factory, $factory));
            $record = $this->capture($probe, $capture, $client);
            $version = $record['server_version'] ?? $version;
            $records[] = $record;
        }
        foreach ($records as &$record) {
            $record['server_version'] = $version;
        }

        return $records;
    }

    /**
     * Invoke one lifecycle stage and retain its original response, including accepted-outcome payload failures.
     *
     * The caller must supply a client using this capture transport and its own exact request guard. The baseline
     * run() retains its GET-only RequestGuard; mutation fixtures own their opt-ins, account and body guards.
     * Clear prior capture before invocation, never retry, and return failure records for the fixture to retain
     * before deciding whether to continue cleanup. Server version is established separately by a version probe.
     *
     * @return Record
     */
    public function capture(
        #[SensitiveParameter] Probe $probe,
        #[SensitiveParameter] CaptureClient $capture,
        #[SensitiveParameter] Client $client,
    ): array {
        $capture->clear();
        $record = $this->record($probe);
        if ($probe->unavailable !== null) {
            $record['probe_outcome'] = $probe->unavailable;
            $record['failure_kind'] = $probe->unavailable === 'unsupported' ? 'unsupported_recipe' : 'missing_recipe';

            return $record;
        }
        try {
            $result = ($probe->invoke)($client);
            $record['api_outcome'] = 'success';
            $record['payload_validation'] = 'passed';
            $record['probe_outcome'] = 'passed';
            if ($probe->operation === 'whm1:version') {
                if (is_string($result) && preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $result) === 1) {
                    $record['server_version'] = $result;
                } else {
                    $record['probe_outcome'] = 'failed';
                    $record['failure_kind'] = 'unusable_server_version';
                }
            }
        } catch (Throwable $error) {
            $record['probe_outcome'] = 'failed';
            $record['failure_kind'] = match (true) {
                $error instanceof PayloadMismatchException => 'payload_mismatch',
                $error instanceof ApiErrorException => 'api_failure',
                $error instanceof ApiException => 'request_failure',
                default => 'probe_failure',
            };
            if ($error instanceof PayloadMismatchException) {
                $record['api_outcome'] = $error->outcomeConfirmed ? 'success' : 'unavailable';
                $record['payload_validation'] = 'failed';
            } elseif ($error instanceof ApiErrorException) {
                $record['api_outcome'] = 'failure';
            }
        }
        $record['method'] = $capture->method();
        $record['http_status'] = $capture->httpStatus();
        $raw = $capture->rawBody();
        if ($raw !== null) {
            $this->observe($record, $raw, $probe);
        }

        return $record;
    }

    /** @return Record */
    private function record(Probe $probe): array
    {
        return [
            'operation' => $probe->operation,
            'variant' => $probe->operation . '#GET',
            'route' => $probe->route,
            'method' => null,
            'server_version' => null,
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'revision' => $this->context['revision'],
            'source_sha256' => $this->context['source_hashes'][$probe->route === 'whm' ? 'whm1' : 'uapi'],
            'map_sha256' => $this->context['map_sha256'],
            'generator_tree' => $this->context['generator_tree'],
            'overlay_sha256' => $this->context['overlay_sha256'],
            'effective_contract_sha256' => hash('sha256', json_encode($probe->spec, JSON_THROW_ON_ERROR)),
            'http_status' => null,
            'api_outcome' => 'unavailable',
            'payload_validation' => 'not_checked',
            'probe_outcome' => 'skipped',
            'failure_kind' => null,
            'response_paths' => [],
            'payload_paths' => [],
            'discrepancies' => [],
        ];
    }

    /**
     * Observe payload bytes independently of generated normalization; present malformed wrappers never fall back.
     *
     * @param Record $record
     */
    private function observe(array &$record, #[SensitiveParameter] string $raw, Probe $probe): void
    {
        try {
            $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }
        $type = $probe->spec['returns'] ?? null;
        if (!is_array($type)) {
            return;
        }
        $envelope = $document;
        if ($probe->route === 'direct-uapi' && $document instanceof stdClass) {
            $envelope = property_exists($document, 'result') ? $document->result
                : (property_exists($document, 'status') ? $document : null);
        } elseif ($probe->route === 'bridged-uapi') {
            $envelope = $document instanceof stdClass && ($document->data ?? null) instanceof stdClass
                ? ($document->data->uapi ?? null) : null;
        }
        $present = $envelope instanceof stdClass && property_exists($envelope, 'data');
        $payload = $envelope instanceof stdClass ? ($envelope->data ?? null) : null;
        $analysis = Fingerprint::analyze($payload, $present, $type);
        $record['payload_paths'] = $analysis['paths'];
        $record['discrepancies'] = $record['api_outcome'] === 'success' ? $analysis['discrepancies'] : [];
        $record['response_paths'] = Fingerprint::analyze($document, true, self::envelopeType($probe, $type))['paths'];
    }

    /**
     * Explicit static envelope-key allowlist; all other keys and all values are sanitized by Fingerprint.
     *
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private static function envelopeType(Probe $probe, array $payload): array
    {
        $mixed = ['type' => 'mixed'];
        $uapi = ['type' => 'object', 'fields' => [
            'status' => $mixed, 'data' => $payload, 'errors' => $mixed,
            'messages' => $mixed, 'warnings' => $mixed, 'metadata' => $mixed,
        ]];
        if ($probe->route === 'direct-uapi') {
            $fields = $uapi['fields'];
            $fields['result'] = $uapi;

            return ['type' => 'object', 'fields' => $fields];
        }

        return ['type' => 'object', 'fields' => [
            'metadata' => ['type' => 'object', 'fields' => [
                'result' => $mixed, 'reason' => $mixed, 'command' => $mixed, 'version' => $mixed,
            ]],
            'data' => $probe->route === 'whm' ? $payload : ['type' => 'object', 'fields' => ['uapi' => $uapi]],
        ]];
    }
}
