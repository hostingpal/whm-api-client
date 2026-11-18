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
 * One-shot API token lifecycle through the generated client on both UAPI routes, with structural evidence only.
 *
 * Each route creates its own read-only, expiring throwaway token, lists it, renames it, revokes it, revokes it again
 * and confirms it is gone. The created secret is never read into this fixture's state: the original response is
 * checked in place and dropped before the next request. A route's token counts as possibly present from the moment
 * its creation is dispatched until an absence check passes. After any failure the fixture revokes both of that
 * route's names once each, which is harmless when they do not exist, confirms absence, and stops before the next
 * route. Cleanup requests are separate phases, never retries, and a run that needed them cannot pass.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Assessment from TokensSectionEvidence
 * @phpstan-type Observation array{
 *   phase:string,expectation:'passed'|'failed'|'not_checked',
 *   operation:string,variant:string,route:string,method:string|null,server_version:string|null,
 *   observed_at:string,revision:string,source_sha256:string,map_sha256:string,generator_tree:string,
 *   overlay_sha256:string,effective_contract_sha256:string,http_status:int|null,
 *   api_outcome:string,payload_validation:string,probe_outcome:string,failure_kind:string|null,
 *   response_paths:array<string,list<string>>,payload_paths:array<string,list<string>>,
 *   discrepancies:list<array{path:string,kind:string}>,payload_alternatives?:list<Alternative>
 * }
 * @phpstan-type State 'not_started'|'uncertain'|'absent'
 * @phpstan-type Report array{
 *   recipe:string,status:'passed'|'failed',probes:list<Observation>,assessment:Assessment,
 *   tokens:array{direct-uapi:State,bridged-uapi:State},failures:list<string>,limitations:list<string>
 * }
 */
final class TokensSectionFixture
{
    private readonly TokensSectionRecipe $recipe;

    private readonly TokensSectionGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    /** @var array<string,Client> */
    private readonly array $clients;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array{direct-uapi:State,bridged-uapi:State} */
    private array $tokens = ['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'];

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
        string $suffix,
        int $expiresAt,
        private readonly array $context,
    ) {
        $this->recipe = new TokensSectionRecipe($suffix, $expiresAt);
        $this->guard = new TokensSectionGuard($http, $tokens, $this->recipe);
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
        try {
            $this->observe('version');
        } catch (Throwable) {
            $this->failures[] = 'version_failure';

            return $this->report();
        }
        foreach (['direct', 'bridge'] as $name) {
            if (!$this->lifecycle($name)) {
                break;
            }
        }

        return $this->report();
    }

    /**
     * Returns false after any failed phase, even when cleanup then confirms the route's token is gone.
     *
     * @param 'direct'|'bridge' $name
     */
    private function lifecycle(string $name): bool
    {
        $route = $name === 'direct' ? 'direct-uapi' : 'bridged-uapi';
        try {
            $this->observe('preflight_' . $name);
            // From here the creation may reach the server, whatever this process learns about its outcome.
            $this->tokens[$route] = 'uncertain';
            foreach (['create', 'listed', 'rename', 'renamed', 'revoke', 'revoke_again', 'absent'] as $stage) {
                $this->observe($stage . '_' . $name);
            }
            $this->tokens[$route] = 'absent';

            return true;
        } catch (Throwable) {
            $this->failures[] = $route . ':phase_failure';
            if ($this->tokens[$route] === 'uncertain') {
                $this->cleanup($name);
            }

            return false;
        }
    }

    /**
     * Revoke both names of one route once each and accept only a confirmed absence.
     *
     * @param 'direct'|'bridge' $name
     */
    private function cleanup(string $name): void
    {
        $route = $name === 'direct' ? 'direct-uapi' : 'bridged-uapi';
        foreach (['cleanup_original', 'cleanup_renamed'] as $stage) {
            try {
                $this->observe($stage . '_' . $name);
            } catch (Throwable) {
                $this->failures[] = $route . ':cleanup_failure';
            }
        }
        try {
            $this->observe('cleanup_check_' . $name);
            $this->tokens[$route] = 'absent';
        } catch (Throwable) {
            $this->failures[] = $route . ':cleanup_unconfirmed';
        }
    }

    /** Capture exactly one original response, check it in place and drop it; raw details never escape. */
    private function observe(string $phase): void
    {
        $step = $this->recipe->step($phase);
        $probe = $step['probe'];
        $this->guard->authorize($phase);
        try {
            $record = $this->runner->capture($probe, $this->capture, $this->clients[$probe->route]);
            $raw = $this->capture->rawBody();
        } finally {
            $this->guard->clearPending();
            $this->capture->clear();
        }
        $usable = $record['probe_outcome'] === 'passed' && $record['discrepancies'] === [];
        $held = $usable && $raw !== null && $this->recipe->holds($phase, $raw);
        $this->records[] = [...$record, 'phase' => $phase,
            'expectation' => $usable ? ($held ? 'passed' : 'failed') : 'not_checked'];
        if (!$held) {
            throw new RuntimeException('Token observation failed.');
        }
    }

    /** @return Report */
    private function report(): array
    {
        $version = $this->records[0]['server_version'] ?? null;
        $records = $this->records;
        foreach ($records as &$record) {
            $record['server_version'] ??= $version;
        }
        unset($record);
        $assessment = TokensSectionEvidence::finish($records, $this->context);
        $absent = $this->tokens === ['direct-uapi' => 'absent', 'bridged-uapi' => 'absent'];

        return ['recipe' => TokensSectionRecipe::RECIPE,
            'status' => $this->failures === [] && $absent && $assessment['status'] === 'passed' ? 'passed' : 'failed',
            'probes' => $records, 'assessment' => $assessment, 'tokens' => $this->tokens,
            'failures' => array_values(array_unique($this->failures)),
            'limitations' => [
                'each_route_used_one_throwaway_read_only_expiring_token_and_touched_no_other_token',
                'created_secrets_were_never_used_to_authenticate_and_are_not_recorded',
                'expiry_and_read_only_enforcement_not_exercised',
                'whitelist_ips_observed_only_as_null',
                'rename_and_revoke_failures_for_missing_tokens_not_part_of_this_capture',
                'designated_account_has_no_contact_address_so_no_creation_notification_was_observable',
                'only_recorded_functions_routes_versions_and_revision_observed',
            ]];
    }
}
