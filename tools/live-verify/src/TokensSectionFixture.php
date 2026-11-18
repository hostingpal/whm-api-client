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
 * Each route first shows that neither of its names exists and that the account has no notification target, then
 * creates its own read-only, expiring throwaway token, lists it, renames it, revokes it and confirms it is gone.
 * The created secret is never read into this fixture's state: the original response is checked in place and dropped
 * before the next request.
 *
 * The account is shared, so the run tracks which of its two names it holds. An accepted creation or rename makes it
 * hold the new name; an accepted rename or revoke releases the old one, which may be anyone's from then on; a refused
 * mutation changes nothing. After an outcome this process never learned, the run may hold either name. Cleanup after
 * a failed phase revokes only a held name, and only when a list shows it with exactly the settings this run creates
 * its token with. Everything ambiguous is left untouched and reported. Cleanup requests are separate phases, never
 * retries, and a run that needed them cannot pass.
 *
 * A route's token is reported absent only on evidence. In particular, an empty list after a creation whose outcome is
 * unknown settles nothing, because that request can still take effect later: the route then stays uncertain.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Assessment from TokensSectionEvidence
 * @phpstan-import-type Inventory from TokensSectionRecipe
 * @phpstan-type Observation array{
 *   phase:string,expectation:'passed'|'failed'|'not_checked',
 *   operation:string,variant:string,route:string,method:string|null,server_version:string|null,
 *   observed_at:string,revision:string,source_sha256:string,map_sha256:string,generator_tree:string,
 *   overlay_sha256:string,effective_contract_sha256:string,http_status:int|null,
 *   api_outcome:string,payload_validation:string,probe_outcome:string,failure_kind:string|null,
 *   response_paths:array<string,list<string>>,payload_paths:array<string,list<string>>,
 *   discrepancies:list<array{path:string,kind:string}>,payload_alternatives?:list<Alternative>
 * }
 * @phpstan-type State 'not_started'|'not_created'|'uncertain'|'absent'
 * @phpstan-type Targets 'not_checked'|'not_established'|'none_configured'
 * @phpstan-type Holding 'never'|'held'|'released'
 * @phpstan-type Report array{
 *   recipe:string,status:'passed'|'failed',probes:list<Observation>,assessment:Assessment,
 *   tokens:array{direct-uapi:State,bridged-uapi:State},
 *   notification_targets:array{direct-uapi:Targets,bridged-uapi:Targets},
 *   failures:list<string>,limitations:list<string>
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

    /** @var array{direct-uapi:Targets,bridged-uapi:Targets} */
    private array $targets = ['direct-uapi' => 'not_checked', 'bridged-uapi' => 'not_checked'];

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
        /** @var array{original:Holding,renamed:Holding} $names */
        $names = ['original' => 'never', 'renamed' => 'never'];
        $creation = 'not_dispatched';
        try {
            $this->observe('preflight_' . $name);
            // The server announces a created token to the account's contacts, so none may be configured.
            $this->targets[$route] = 'not_established';
            $this->observe('targets_' . $name);
            $this->targets[$route] = 'none_configured';
            // From here the creation may reach the server, whatever this process learns about its outcome.
            $creation = 'unknown';
            $this->tokens[$route] = 'uncertain';
            try {
                $this->observe('create_' . $name);
            } finally {
                $creation = $this->outcome('create_' . $name);
                if ($creation !== 'refused') {
                    $names['original'] = 'held';
                }
            }
            $this->observe('listed_' . $name);
            try {
                $this->observe('rename_' . $name);
            } finally {
                $renaming = $this->outcome('rename_' . $name);
                if ($renaming === 'accepted') {
                    // The first name is free again and may be anyone's from here on.
                    $names = ['original' => 'released', 'renamed' => 'held'];
                } elseif ($renaming === 'unknown') {
                    // The token is under one of the two names; only a list can say which.
                    $names['renamed'] = 'held';
                }
            }
            $this->observe('renamed_' . $name);
            // Passing means the server answered 1: it removed the token and the name is free again.
            $this->observe('revoke_' . $name);
            $names['renamed'] = 'released';
            $this->observe('absent_' . $name);
            $this->tokens[$route] = 'absent';

            return true;
        } catch (Throwable) {
            $this->failures[] = $route . ':phase_failure';
            if ($creation === 'refused') {
                // The server refused the creation, so this run holds nothing under either name.
                $this->tokens[$route] = 'not_created';
            } elseif ($creation !== 'not_dispatched') {
                $this->cleanup($name, $names, $creation === 'unknown');
            }

            return false;
        }
    }

    /**
     * Revoke what this run still holds on one route and accept only evidence that nothing of it is left.
     *
     * Only a name the run holds is revoked, and only when a list shows it with exactly the run's settings. A name the
     * run never had is someone else's and is ignored. Any other presence is ambiguous: a held name with other
     * settings, or a released name that is listed again, may be this run's token or another party's. It is left
     * untouched and the route stays uncertain for a person to check.
     *
     * @param 'direct'|'bridge' $name
     * @param array{original:Holding,renamed:Holding} $names
     */
    private function cleanup(string $name, array $names, bool $creationUnknown): void
    {
        $route = $name === 'direct' ? 'direct-uapi' : 'bridged-uapi';
        $before = $this->inspect('cleanup_inspect_' . $name, $name);
        if ($before === null) {
            $this->failures[] = $route . ':cleanup_unconfirmed';

            return;
        }
        foreach (['original', 'renamed'] as $identity) {
            if ($names[$identity] === 'held' && $before[$identity] === 'settled') {
                try {
                    $this->observe('cleanup_' . $identity . '_' . $name);
                } catch (Throwable) {
                    $this->failures[] = $route . ':cleanup_failure';
                }
            }
        }
        $after = $this->inspect('cleanup_check_' . $name, $name);
        if ($after === null) {
            $this->failures[] = $route . ':cleanup_unconfirmed';

            return;
        }
        $unsettled = [];
        foreach (['original', 'renamed'] as $identity) {
            if ($names[$identity] === 'never' || $after[$identity] === 'absent') {
                continue;
            }
            $unsettled[] = $names[$identity] === 'held' && $after[$identity] === 'settled'
                ? $route . ':cleanup_unconfirmed'
                : $route . ':unproven_token_under_run_name';
        }
        if ($creationUnknown && $before['original'] !== 'settled') {
            // The creation never showed itself, so it may still take effect after these lists.
            $unsettled[] = $route . ':creation_outcome_unknown';
        }
        if ($unsettled === []) {
            $this->tokens[$route] = 'absent';
        }
        array_push($this->failures, ...$unsettled);
    }

    /**
     * The API outcome of a phase, read from its own record; anything this process did not learn is unknown.
     *
     * @return 'accepted'|'refused'|'unknown'
     */
    private function outcome(string $phase): string
    {
        $index = array_key_last($this->records);
        $record = $index === null ? null : $this->records[$index];
        if ($record === null || $record['phase'] !== $phase) {
            return 'unknown';
        }

        return match ($record['api_outcome']) {
            'success' => 'accepted',
            'failure' => 'refused',
            default => 'unknown',
        };
    }

    /** Capture exactly one original response, check it in place and drop it; raw details never escape. */
    private function observe(string $phase): void
    {
        $raw = $this->capture($phase);
        if (!$this->settle($raw !== null && $this->recipe->holds($phase, $raw))) {
            throw new RuntimeException('Token observation failed.');
        }
    }

    /**
     * Capture one list during cleanup and read what it shows for the route's names, or null when it cannot be used.
     *
     * @param 'direct'|'bridge' $name
     * @return Inventory|null
     */
    private function inspect(string $phase, string $name): ?array
    {
        try {
            $raw = $this->capture($phase);
        } catch (Throwable) {
            return null;
        }
        $inventory = $raw === null ? null : $this->recipe->inventory($name, $raw);
        $this->settle($inventory !== null);

        return $inventory;
    }

    /** Record one phase's observation and return its original body only when the probe passed cleanly. */
    private function capture(string $phase): ?string
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
        $this->records[] = [...$record, 'phase' => $phase, 'expectation' => 'not_checked'];

        return $record['probe_outcome'] === 'passed' && $record['discrepancies'] === [] ? $raw : null;
    }

    /** Mark the latest record's expectation, which stays unchecked for an observation that could not be used. */
    private function settle(bool $held): bool
    {
        $index = array_key_last($this->records);
        if ($index !== null) {
            $record = $this->records[$index];
            if ($record['probe_outcome'] === 'passed' && $record['discrepancies'] === []) {
                $this->records[$index]['expectation'] = $held ? 'passed' : 'failed';
            }
        }

        return $held;
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
            'notification_targets' => $this->targets,
            'failures' => array_values(array_unique($this->failures)),
            'limitations' => [
                'scope_is_one_throwaway_read_only_expiring_token_per_route',
                'created_secrets_are_never_used_to_authenticate_and_never_recorded',
                'expiry_and_read_only_enforcement_not_exercised',
                'populated_whitelist_ips_not_exercised',
                'api_error_responses_are_not_part_of_passing_evidence',
                'notification_targets_are_read_only_as_contact_email_contact_email_2_and_pushbullet_access_token',
                'creation_notification_delivery_not_observed',
                'only_recorded_functions_routes_versions_and_revision_observed',
            ]];
    }
}
