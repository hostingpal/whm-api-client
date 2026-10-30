<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * One-shot required_score write/read/restore evidence with private original-response state.
 *
 * Restoration is a distinct cleanup request, never a retry. A lost set response can complete later,
 * so even successful restoration/readbacks cannot clear its uncertainty. Stop the next route's
 * mutation on every failure; the final catalog stays read-only and retains its independent findings.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Assessment from SpamPreferenceEvidence
 * @phpstan-type Observation array{
 *   phase:string,expectation:'passed'|'failed'|'not_checked',
 *   operation:string,variant:string,route:string,method:string|null,server_version:string|null,
 *   observed_at:string,revision:string,source_sha256:string,map_sha256:string,generator_tree:string,
 *   overlay_sha256:string,effective_contract_sha256:string,http_status:int|null,
 *   api_outcome:string,payload_validation:string,probe_outcome:string,failure_kind:string|null,
 *   response_paths:array<string,list<string>>,payload_paths:array<string,list<string>>,
 *   discrepancies:list<array{path:string,kind:string}>,payload_alternatives?:list<Alternative>
 * }
 * @phpstan-type State 'not_started'|'uncertain'|'restored'
 * @phpstan-type Report array{
 *   recipe:string,status:'passed'|'failed',probes:list<Observation>,
 *   restoration:array{direct-uapi:State,bridged-uapi:State},failures:list<string>,limitations:list<string>,
 *   assessment:Assessment,preference_assessment:Assessment,catalog_assessment:Assessment
 * }
 */
final class SpamPreferenceFixture
{
    private readonly SpamPreferenceRecipe $recipe;

    private readonly SpamPreferenceGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    /** @var array<string,Client> */
    private readonly array $clients;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array{direct-uapi:State,bridged-uapi:State} */
    private array $restoration = ['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'];

    /** @var list<string> */
    private array $failures = [];

    private bool $used = false;

    /**
     * @param array{whm:string,cpanel:string} $tokens
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] array $tokens,
        private readonly array $context,
    ) {
        $this->recipe = new SpamPreferenceRecipe();
        $this->guard = new SpamPreferenceGuard($http, $tokens, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $factory = new Psr17Factory();
        $transport = new HttpTransport($this->capture, $factory, $factory);
        $direct = new Client(['host' => 'test.oblak.host', 'port' => 2083, 'authType' => 'cpanel',
            'username' => 'npr', 'token' => $tokens['cpanel']], $transport);
        $bridge = new Client(['host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm',
            'username' => 'root', 'token' => $tokens['whm']], $transport);
        $this->clients = ['whm' => $bridge, 'direct-uapi' => $direct, 'bridged-uapi' => $bridge];
        $this->runner = new EvidenceRunner($this->guard, $context);
    }

    /** @return Report */
    public function run(): array
    {
        if ($this->used) {
            $this->failures[] = 'fixture_already_used';

            return $this->report();
        }
        $this->used = true;
        if (
            getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'npr' || getenv('WHM_API_LIVE_DOMAIN') !== 'npr.rs'
        ) {
            $this->failures[] = 'configuration_failure';

            return $this->report();
        }
        $state = null;
        try {
            $this->require('preflight_direct');
            $state = new SpamPreferenceState($this->raw(), 'direct-uapi');
            $this->require('preflight_bridge');
            $this->expect($state->matches($this->raw(), 'bridged-uapi'));
            $this->recipe->bind($state);
        } catch (Throwable) {
            $this->failures[] = 'preflight_failure';
            $state = null;
        }
        if ($state !== null) {
            foreach (['direct', 'bridge'] as $name) {
                if (!$this->lifecycle($name, $state)) {
                    break;
                }
            }
        }
        // The reviewed read catalog runs once, regardless of the preference phase's strict result.
        foreach (MailProtectionProbes::load() as $index => $probe) {
            try {
                $this->require('catalog_' . $index);
                if ($state !== null && $probe->operation === 'uapi:SpamAssassin/get_user_preferences') {
                    $this->expect($state->matches($this->raw(), $probe->route));
                }
            } catch (Throwable) {
                $this->failures[] = 'catalog_failure';
            }
        }

        return $this->report();
    }

    /** Returns false after any failed phase, even when confirmed restoration succeeds. */
    private function lifecycle(string $name, #[SensitiveParameter] SpamPreferenceState $state): bool
    {
        $route = $name === 'direct' ? 'direct-uapi' : 'bridged-uapi';
        $this->restoration[$route] = 'uncertain';
        $passed = true;
        $settled = false;
        try {
            $this->require('set_' . $name);
            $settled = true;
            $this->expect($state->matches($this->raw(), $route, true));
            $this->require('read_' . $name);
            $this->expect($state->matches($this->raw(), $route, true));
        } catch (Throwable) {
            // An accepted-outcome mismatch has completed; an unavailable outcome may still be pending.
            $set = $this->find('set_' . $name);
            $settled = $set !== null && in_array($set['api_outcome'], ['success', 'failure'], true);
            $passed = false;
            $this->failures[] = $route . ':phase_failure';
        } finally {
            if (!$this->restore($name, $state, $settled)) {
                $passed = false;
                $this->failures[] = 'unresolved_mutation';
            }
        }

        return $passed;
    }

    /** Restore once and compare both routes, retaining uncertainty from either dispatched POST. */
    private function restore(string $name, #[SensitiveParameter] SpamPreferenceState $state, bool $settled): bool
    {
        $route = $name === 'direct' ? 'direct-uapi' : 'bridged-uapi';
        $restored = true;
        try {
            $this->require('restore_' . $name);
            $this->expect($state->matches($this->raw(), $route));
        } catch (Throwable) {
            $restored = false;
            $this->failures[] = $route . ':restore_failure';
        }
        foreach (['direct', 'bridge'] as $readName) {
            try {
                $this->require('restored_' . $name . '_' . $readName);
                $this->expect($state->matches($this->raw(), $readName === 'direct' ? 'direct-uapi' : 'bridged-uapi'));
            } catch (Throwable) {
                $restored = false;
                $this->failures[] = $route . ':restoration_unconfirmed';
            }
        }
        if ($restored && $settled) {
            $this->restoration[$route] = 'restored';

            return true;
        }

        return false;
    }

    /** Capture and validate exactly one original response; never expose raw response/error details. */
    private function require(string $phase): void
    {
        $step = $this->recipe->step($phase);
        $probe = $step['probe'];
        $this->guard->authorize($phase);
        try {
            $record = $this->runner->capture($probe, $this->capture, $this->clients[$probe->route]);
        } finally {
            $this->guard->clearPending();
        }
        $this->records[] = [...$record, 'phase' => $phase,
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked'];
        if ($record['probe_outcome'] !== 'passed' || $record['discrepancies'] !== []) {
            throw new RuntimeException('Preference observation failed.');
        }
    }

    private function raw(): string
    {
        return $this->capture->rawBody() ?? throw new RuntimeException('Preference response unavailable.');
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $index = array_key_last($this->records);
            if ($index !== null) {
                $this->records[$index]['expectation'] = 'failed';
            }
            throw new RuntimeException('Preference expectation failed.');
        }
    }

    /** @return Observation|null */
    private function find(string $phase): ?array
    {
        foreach ($this->records as $record) {
            if ($record['phase'] === $phase) {
                return $record;
            }
        }

        return null;
    }

    /** @return Report */
    private function report(): array
    {
        $version = $this->find('catalog_0')['server_version'] ?? null;
        $records = $this->records;
        foreach ($records as &$record) {
            $record['server_version'] ??= $version;
        }
        unset($record);
        $assessment = SpamPreferenceEvidence::finish($records, $this->context);
        $restored = $this->restoration === ['direct-uapi' => 'restored', 'bridged-uapi' => 'restored'];

        return ['recipe' => 'spam-required-score-restoration-v1',
            'status' => $this->failures === [] && $restored && $assessment['assessment']['status'] === 'passed'
                ? 'passed' : 'failed',
            'probes' => $records, ...$assessment, 'restoration' => $this->restoration,
            'failures' => array_values(array_unique($this->failures)),
            'limitations' => [
                'only_required_score_changed_other_preferences_compared_without_rewriting',
                'exact_original_or_original_absence_restored_once_on_each_completed_route',
                'uncertain_writes_require_manual_review_even_after_matching_readbacks',
                'no_mail_delivered_and_no_spam_filter_enforcement_established',
                'catalog_findings_remain_unwaived_and_separate_from_preference_phase',
                'only_recorded_functions_routes_versions_and_revision_observed',
            ]];
    }
}
