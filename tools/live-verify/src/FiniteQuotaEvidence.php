<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\QuotaSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * Capture a separate finite-Quota scenario for the disposable wqfinite account configured with 64 MB.
 *
 * Normalized values remain in memory for fixture expectations; persisted records contain only the same-response
 * structural evidence. The local-byte comparison describes this run and does not establish a universal unit contract.
 * This scenario cannot inherit the npr baseline's historical discrepancy acknowledgments.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-type UnitRelationship 'binary-mib'|'decimal-mb'|'other'|'unavailable'
 * @phpstan-type Reason 'unexpected_probe'|'missing_probe'|'duplicate_probe'|'unsuccessful_probe'|'unusable_server_version'|'unacknowledged_discrepancy'
 * @phpstan-type Assessment array{
 *     policy: 'finite-quota-no-acknowledgments-v1', status: 'passed'|'failed', acknowledged: array{},
 *     unexpected: list<array{operation: string, variant: string, route: string, reason: Reason}>
 * }
 * @phpstan-type Result array{
 *     probes: list<Record>, expectations: 'passed'|'failed',
 *     unit_relationships: array{'direct-uapi': UnitRelationship, 'bridged-uapi': UnitRelationship},
 *     discrepancy_review: Assessment
 * }
 */
final readonly class FiniteQuotaEvidence
{
    /** @param Context $context */
    public function __construct(private ClientInterface $guardedHttp, private array $context)
    {
    }

    /**
     * Execute one version probe and four Quota probes through the lifecycle's guarded transport.
     *
     * The caller supplies the fixture transport, which enforces both live opt-ins, exact account designation and token
     * scope on every request. Rebuild fixed generated calls after checking the baseline recipes; never invoke callbacks
     * supplied by a caller. No retained payload value or credential is returned, and no request is replayed.
     *
     * @param list<Probe> $baselineProbes
     * @return Result
     */
    public function run(
        array $baselineProbes,
        #[SensitiveParameter] string $whmToken,
        #[SensitiveParameter] string $cpanelToken,
    ): array {
        $recipes = self::recipes();
        foreach ($recipes as $recipe) {
            $matches = array_values(array_filter($baselineProbes, static fn (Probe $probe): bool =>
                $probe->operation === $recipe->operation && $probe->route === $recipe->route));
            if (
                count($matches) !== 1 || $matches[0]->unavailable !== null || $matches[0]->spec !== $recipe->spec
                || $matches[0]->uri !== str_replace('cpanel.user=wqfinite', 'cpanel.user=npr', $recipe->uri)
            ) {
                throw new RuntimeException('Finite Quota evidence requires the five reviewed catalog recipes.');
            }
        }

        $values = [];
        $probes = [];
        foreach ($recipes as $recipe) {
            $probes[] = new Probe(
                $recipe->operation,
                $recipe->route,
                $recipe->spec,
                $recipe->uri,
                static function (#[SensitiveParameter] Client $client) use ($recipe, &$values): mixed {
                    $result = ($recipe->invoke)($client);
                    if (is_array($result)) {
                        $values[$recipe->operation . '|' . $recipe->route] = $result;
                    }

                    return $result;
                },
            );
        }
        $records = (new EvidenceRunner($this->guardedHttp, $this->context, 'wqfinite'))->run(
            $probes,
            getenv('WHM_API_LIVE'),
            ['whm' => $whmToken, 'cpanel' => $cpanelToken],
        );
        $passed = true;
        foreach ($records as &$record) {
            if ($record['route'] !== 'whm' && $record['probe_outcome'] === 'passed') {
                $payload = $values[$record['operation'] . '|' . $record['route']] ?? [];
                $expected = $record['operation'] === 'uapi:Quota/get_quota_info'
                    ? self::totalExpected($payload) : self::localExpected($payload);
                if (!$expected) {
                    $record['probe_outcome'] = 'failed';
                    $record['failure_kind'] = 'finite_quota_expectation_failed';
                }
            }
            $passed = $passed && $record['probe_outcome'] === 'passed';
        }

        return [
            'probes' => $records,
            'expectations' => $passed ? 'passed' : 'failed',
            'unit_relationships' => [
                'direct-uapi' => self::unitRelationship($values['uapi:Quota/get_local_quota_info|direct-uapi'] ?? []),
                'bridged-uapi' => self::unitRelationship($values['uapi:Quota/get_local_quota_info|bridged-uapi'] ?? []),
            ],
            'discrepancy_review' => self::assess($records),
        ];
    }

    /**
     * Require the exact five successful GET observations, one usable server version, and no raw discrepancies.
     *
     * This independent assessment never changes evidence records and has no acknowledgment mechanism. An empty set,
     * including a setup failure before capture, fails with the five missing identities.
     *
     * @param list<Record> $records
     * @return Assessment
     */
    public static function assess(array $records): array
    {
        $expected = [];
        foreach (self::recipes() as $recipe) {
            $expected[$recipe->operation . '|' . $recipe->operation . '#GET|' . $recipe->route] = [
                'operation' => $recipe->operation, 'variant' => $recipe->operation . '#GET', 'route' => $recipe->route,
            ];
        }
        $counts = [];
        $version = null;
        foreach ($records as $record) {
            $id = $record['operation'] . '|' . $record['variant'] . '|' . $record['route'];
            $counts[$id] = ($counts[$id] ?? 0) + 1;
            if ($id === 'whm1:version|whm1:version#GET|whm' && self::usableVersion($record['server_version'])) {
                $version = $record['server_version'];
            }
        }
        $unexpected = [];
        foreach ($expected as $id => $identity) {
            if (!isset($counts[$id])) {
                $unexpected[] = [...$identity, 'reason' => 'missing_probe'];
            } elseif ($counts[$id] !== 1) {
                $unexpected[] = [...$identity, 'reason' => 'duplicate_probe'];
            }
        }
        foreach ($records as $record) {
            $identity = [
                'operation' => $record['operation'], 'variant' => $record['variant'], 'route' => $record['route'],
            ];
            $id = $record['operation'] . '|' . $record['variant'] . '|' . $record['route'];
            if (!isset($expected[$id])) {
                $unexpected[] = [...$identity, 'reason' => 'unexpected_probe'];
            }
            if (
                $record['method'] !== 'GET' || $record['http_status'] === null
                || $record['http_status'] < 200 || $record['http_status'] >= 300
                || $record['api_outcome'] !== 'success' || $record['payload_validation'] !== 'passed'
                || $record['probe_outcome'] !== 'passed' || $record['failure_kind'] !== null
            ) {
                $unexpected[] = [...$identity, 'reason' => 'unsuccessful_probe'];
            }
            if (!self::usableVersion($record['server_version']) || $record['server_version'] !== $version) {
                $unexpected[] = [...$identity, 'reason' => 'unusable_server_version'];
            }
            if ($record['discrepancies'] !== []) {
                $unexpected[] = [...$identity, 'reason' => 'unacknowledged_discrepancy'];
            }
        }

        return [
            'policy' => 'finite-quota-no-acknowledgments-v1',
            'status' => $unexpected === [] ? 'passed' : 'failed',
            'acknowledged' => [],
            'unexpected' => $unexpected,
        ];
    }

    /** @return list<Probe> */
    private static function recipes(): array
    {
        $probes = [new Probe(
            'whm1:version',
            'whm',
            ServerSpec::VERSION,
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            static fn (#[SensitiveParameter] Client $client): mixed => $client->whm()->server()->version(),
        )];
        foreach (['get_quota_info', 'get_local_quota_info'] as $function) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $probes[] = new Probe(
                    'uapi:Quota/' . $function,
                    $route,
                    $function === 'get_quota_info' ? QuotaSpec::GET_QUOTA_INFO : QuotaSpec::GET_LOCAL_QUOTA_INFO,
                    $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/Quota/' . $function
                        : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wqfinite'
                            . '&cpanel.module=Quota&cpanel.function=' . $function,
                    static fn (#[SensitiveParameter] Client $client): mixed => $function === 'get_quota_info'
                        ? $client->uapi($route === 'direct-uapi' ? null : 'wqfinite')->quota()->getQuotaInfo()
                        : $client->uapi($route === 'direct-uapi' ? null : 'wqfinite')->quota()->getLocalQuotaInfo(),
                );
            }
        }

        return $probes;
    }

    /** @param array<mixed> $payload */
    private static function totalExpected(#[SensitiveParameter] array $payload): bool
    {
        $limit = $payload['megabyte_limit'] ?? null;
        $used = $payload['megabytes_used'] ?? null;
        $remain = $payload['megabytes_remain'] ?? null;
        $inode = $payload['under_inode_limit'] ?? null;

        return (is_int($limit) || is_float($limit)) && (float) $limit === 64.0
            && (is_int($used) || is_float($used)) && $used >= 0 && $used < $limit
            && (is_int($remain) || is_float($remain)) && $remain > 0 && $remain <= $limit
            && ($payload['under_megabyte_limit'] ?? null) === 1
            && ($inode === 0 || $inode === 1)
            && ($payload['under_quota_overall'] ?? null) === $inode;
    }

    /** @param array<mixed> $payload */
    private static function localExpected(#[SensitiveParameter] array $payload): bool
    {
        $limit = $payload['byte_limit'] ?? null;
        $used = $payload['bytes_used'] ?? null;

        return is_int($limit) && $limit > 0 && is_int($used) && $used >= 0 && $used <= $limit;
    }

    /**
     * @param array<mixed> $payload
     * @return UnitRelationship
     */
    private static function unitRelationship(#[SensitiveParameter] array $payload): string
    {
        $limit = $payload['byte_limit'] ?? null;
        if (!is_int($limit) || $limit <= 0) {
            return 'unavailable';
        }

        return match ($limit) {
            64 * 1024 * 1024 => 'binary-mib',
            64 * 1000 * 1000 => 'decimal-mb',
            default => 'other',
        };
    }

    private static function usableVersion(?string $version): bool
    {
        return $version !== null && preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) === 1;
    }
}
