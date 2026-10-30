<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Psr\Http\Client\ClientInterface;
use SensitiveParameter;

/**
 * Run one reviewed GET catalog without repeating the baseline or accepting discrepancy acknowledgments.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-type Report array{recipe: string, status: 'passed'|'failed', probes: list<Record>}
 */
final readonly class ReadOnlySectionRunner
{
    /** @param Context $context */
    public function __construct(private ClientInterface $http, private array $context)
    {
    }

    /**
     * Capture each selected response once using the existing exact-URI GET guard and sanitizer.
     *
     * Catalogs contain fixed account-bound arguments, with WHM version first. The strict assessment
     * keeps every original discrepancy and requires complete ordered provenance and successful outcomes.
     * Mutations, retries and historical acknowledgment policies are outside this runner.
     *
     * @param list<Probe> $probes
     * @param array{whm: string, cpanel: string} $tokens
     * @return Report
     */
    public function run(
        #[SensitiveParameter] array $probes,
        string $recipe,
        string|false $optIn,
        #[SensitiveParameter] array $tokens,
    ): array {
        $records = (new EvidenceRunner($this->http, $this->context))->run($probes, $optIn, $tokens);

        return ['recipe' => $recipe, 'status' => self::passes($probes, $records, $this->context)
            ? 'passed' : 'failed', 'probes' => $records];
    }

    /**
     * Check an untrusted evidence document against the exact reviewed catalog and committed context.
     *
     * The input records intentionally accept arbitrary values so missing/malformed evidence fails
     * closed rather than relying on a PHPDoc assertion. This does not validate upstream payloads again.
     *
     * @param list<Probe> $probes
     * @param list<array<string, mixed>> $records
     * @param Context $context
     */
    public static function passes(array $probes, array $records, array $context): bool
    {
        if (
            $probes === [] || count($probes) !== count($records)
            || $probes[0]->operation !== 'whm1:version' || $probes[0]->route !== 'whm'
        ) {
            return false;
        }
        $version = $records[0]['server_version'] ?? null;
        if (!is_string($version) || preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) !== 1) {
            return false;
        }
        $seen = [];
        foreach ($probes as $index => $probe) {
            $record = $records[$index];
            $identity = $probe->operation . '|' . $probe->route;
            if (isset($seen[$identity]) || $probe->unavailable !== null) {
                return false;
            }
            $seen[$identity] = true;
            $expected = [
                'operation' => $probe->operation, 'route' => $probe->route,
                'variant' => $probe->sourceVariant ?? $probe->operation . '#GET',
                'method' => 'GET', 'http_status' => 200, 'server_version' => $version,
                'api_outcome' => 'success', 'payload_validation' => 'passed', 'probe_outcome' => 'passed',
                'failure_kind' => null, 'discrepancies' => [],
                'revision' => $context['revision'], 'map_sha256' => $context['map_sha256'],
                'generator_tree' => $context['generator_tree'], 'overlay_sha256' => $context['overlay_sha256'],
                'source_sha256' => $context['source_hashes'][$probe->route === 'whm' ? 'whm1' : 'uapi'],
                'effective_contract_sha256' => hash('sha256', json_encode($probe->spec, JSON_THROW_ON_ERROR)),
            ];
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $record) || $record[$key] !== $value) {
                    return false;
                }
            }
        }

        return true;
    }
}
