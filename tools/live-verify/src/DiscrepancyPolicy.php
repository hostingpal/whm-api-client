<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SensitiveParameter;
use SplFileInfo;
use Throwable;

/**
 * Acknowledge two reviewed structural observations without changing their recorded discrepancies.
 *
 * The immutable reference is checked before live I/O. Exact payload fingerprints deliberately
 * include documented fields too. Wildcards cannot establish unknown-key identities or counts.
 * This development-only gate never changes the generated client or its validation rules.
 *
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-type Assessment array{
 *     policy: string, tracking: string, evidence: string, evidence_sha256: string,
 *     status: 'passed'|'failed', acknowledged: list<string>,
 *     unexpected: list<array{operation: string, variant: string, route: string,
 *         reason: 'unacknowledged_discrepancy'|'duplicate_probe'|'unsuccessful_probe'|'empty_run'}>,
 *     stale: list<string>
 * }
 */
final readonly class DiscrepancyPolicy
{
    /**
     * Current immutable reference, captured at 5f9b001 on cPanel 11.138.0.19.
     *
     * Until 2026-11-01 the same two observations were bound to cPanel 11.138.0.15 through
     * docs/api/evidence/2026-10-16-quota-inventory.json (SHA-256
     * 5f65d66acc36673ee37be1b4e024dad3ac725e5313d45c208abb489d5c9f6811). That file stays
     * unchanged as history; only this rebinding moved the acknowledgments.
     */
    private const EVIDENCE = 'docs/api/evidence/2026-11-01-read-only-11.138.0.19.json';

    private const SHA256 = '4138dbf1d083ddfe6d109c86bb8beb449abde6937e93b2452693d47b2b792b81';

    private const ROUTES = [
        'direct-uapi' => 'quota-total-direct-11.138.0.19',
        'bridged-uapi' => 'quota-total-bridge-11.138.0.19',
    ];

    /** @param array<string, array{identity: string, signature: string}> $expected */
    private function __construct(private string $reference, private array $expected)
    {
    }

    /**
     * Verify the historical file once and select only the explicitly reviewed observations.
     *
     * No arbitrary reference, automatic baseline creation or unknown-key disclosure is supported.
     * All configuration failures are sanitized and must occur before sending live requests.
     *
     * @throws RuntimeException If the immutable reference cannot be verified.
     */
    public static function load(#[SensitiveParameter] string $root): self
    {
        try {
            $path = $root . '/' . self::EVIDENCE;
            $bytes = is_file($path) && !is_link($path) ? @file_get_contents($path) : false;
            if (!is_string($bytes) || hash('sha256', $bytes) !== self::SHA256) {
                throw new RuntimeException('Invalid reference.');
            }
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document) || ($document['schema_version'] ?? null) !== 2) {
                throw new RuntimeException('Invalid reference.');
            }
            $records = $document['probes'] ?? null;
            if (!is_array($records) || !array_is_list($records)) {
                throw new RuntimeException('Invalid reference.');
            }
            $expected = [];
            foreach (self::ROUTES as $route => $id) {
                $matches = array_values(array_filter($records, static fn (mixed $record): bool =>
                    is_array($record) && ($record['operation'] ?? null) === 'uapi:Quota/get_quota_info'
                    && ($record['variant'] ?? null) === 'uapi:Quota/get_quota_info#GET'
                    && ($record['route'] ?? null) === $route));
                if (count($matches) !== 1) {
                    throw new RuntimeException('Invalid reference.');
                }
                $expected[$id] = [
                    'identity' => self::identity($matches[0]),
                    'signature' => self::signature($matches[0]),
                ];
            }

            return new self($path, $expected);
        } catch (Throwable) {
            throw new RuntimeException('Discrepancy reference could not be verified.');
        }
    }

    /**
     * Refuse overwriting any existing evidence file, including symlink and hard-link aliases.
     *
     * Everything under docs/api/evidence/ is immutable history, and the current reference is only
     * one file there. Call before preparing an output file or sending a request. The CLI also
     * rejects symlink destinations generally. Comparing device and inode protects each file
     * independently of its name. New paths and files elsewhere stay allowed.
     *
     * @throws RuntimeException If the output identifies an existing evidence file.
     */
    public function assertOutputAllowed(#[SensitiveParameter] string $output): void
    {
        $destination = @stat($output);
        if ($destination === false) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname($this->reference), FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            $evidence = $file instanceof SplFileInfo ? @stat($file->getPathname()) : false;
            if (
                $evidence !== false
                && $evidence['dev'] === $destination['dev'] && $evidence['ino'] === $destination['ino']
            ) {
                throw new RuntimeException('Evidence output cannot replace an existing evidence file.');
            }
        }
    }

    /**
     * Assess current sanitized observations without modifying them or suppressing discrepancies.
     *
     * All expected observations must occur exactly once and still match. API, payload and probe
     * failures always fail independently, even when their structural paths match the reference.
     *
     * @param list<Record> $records
     * @return Assessment
     */
    public function assess(array $records): array
    {
        $assessment = [
            'policy' => 'quota-structural-observations-v1',
            'tracking' => 'whm-api-client-8x0.4.3.1',
            'evidence' => self::EVIDENCE,
            'evidence_sha256' => self::SHA256,
            'status' => 'failed',
            'acknowledged' => [],
            'unexpected' => [],
            'stale' => [],
        ];
        $counts = [];
        foreach ($records as $record) {
            $identity = self::identity($record);
            $counts[$identity] = ($counts[$identity] ?? 0) + 1;
        }
        $acknowledged = [];
        foreach ($records as $record) {
            $identity = self::identity($record);
            $successful = $record['probe_outcome'] === 'passed' && $record['api_outcome'] === 'success'
                && $record['payload_validation'] === 'passed' && $record['failure_kind'] === null
                && $record['http_status'] !== null && $record['http_status'] >= 200 && $record['http_status'] < 300;
            $reasons = [];
            if (!$successful) {
                $reasons[] = 'unsuccessful_probe';
            }
            if ($counts[$identity] !== 1) {
                $reasons[] = 'duplicate_probe';
            }
            $matched = false;
            if ($successful && $counts[$identity] === 1) {
                foreach ($this->expected as $id => $expected) {
                    if ($expected['identity'] === $identity && $expected['signature'] === self::signature($record)) {
                        $acknowledged[$id] = true;
                        $matched = true;
                    }
                }
            }
            if ($record['discrepancies'] !== [] && !$matched) {
                $reasons[] = 'unacknowledged_discrepancy';
            }
            foreach ($reasons as $reason) {
                $assessment['unexpected'][] = [
                    'operation' => $record['operation'], 'variant' => $record['variant'],
                    'route' => $record['route'], 'reason' => $reason,
                ];
            }
        }
        if ($records === []) {
            $assessment['unexpected'][] = [
                'operation' => '', 'variant' => '', 'route' => '', 'reason' => 'empty_run',
            ];
        }
        foreach (array_keys($this->expected) as $id) {
            $assessment[isset($acknowledged[$id]) ? 'acknowledged' : 'stale'][] = $id;
        }
        $assessment['status'] = $assessment['unexpected'] === [] && $assessment['stale'] === [] ? 'passed' : 'failed';

        return $assessment;
    }

    /** @param array<mixed> $record */
    private static function identity(array $record): string
    {
        return json_encode([
            $record['operation'] ?? null, $record['variant'] ?? null, $record['route'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $record */
    private static function signature(array $record): string
    {
        $fields = [];
        foreach (
            ['operation', 'variant', 'route', 'method', 'server_version', 'source_sha256',
                'effective_contract_sha256', 'payload_paths', 'discrepancies'] as $field
        ) {
            $fields[$field] = $record[$field] ?? null;
        }

        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }
}
