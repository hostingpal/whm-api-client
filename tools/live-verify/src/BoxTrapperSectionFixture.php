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
 * One-shot fixture restricted to two new, disabled mailboxes; uncertainty never creates cleanup authority.
 *
 * Ownership requires the original successful validated add_pop identity AND a successful inventory
 * containing that exact login. Lost, failed or malformed creation/inventory stays uncertain. No later
 * observation can adopt such a mailbox. Cleanup is attempted once only for confirmed owned mailboxes.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Route from BoxTrapperSectionRecipe
 * @phpstan-type Observation array{
 *   phase:string,expectation:'passed'|'failed'|'not_checked',
 *   operation:string,variant:string,route:string,method:string|null,server_version:string|null,
 *   observed_at:string,revision:string,source_sha256:string,map_sha256:string,generator_tree:string,
 *   overlay_sha256:string,effective_contract_sha256:string,http_status:int|null,
 *   api_outcome:string,payload_validation:string,probe_outcome:string,failure_kind:string|null,
 *   response_paths:array<string,list<string>>,payload_paths:array<string,list<string>>,
 *   discrepancies:list<array{path:string,kind:string}>,payload_alternatives?:list<Alternative>
 * }
 * @phpstan-type State 'not_started'|'uncertain'|'owned'|'cleaned'
 * @phpstan-type Report array{
 *   recipe:string,status:'passed'|'failed',probes:list<Observation>,resources:array<Route,State>,
 *   failures:list<string>,limitations:list<string>,assessment:array{status:'passed'|'failed',findings:list<string>}
 * }
 */
final class BoxTrapperSectionFixture
{
    private readonly BoxTrapperSectionRecipe $recipe;

    private readonly BoxTrapperSectionGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    /** @var array<'whm'|Route,Client> */
    private readonly array $clients;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<Route,State> */
    private array $resources = ['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'];

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
        #[SensitiveParameter] string $suffix,
        private readonly array $context,
    ) {
        $this->recipe = new BoxTrapperSectionRecipe($suffix, 'Bt!9' . bin2hex(random_bytes(20)));
        $this->guard = new BoxTrapperSectionGuard($http, $tokens, $this->recipe);
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
            $this->require('version', 'whm');
            $this->preflight();
        } catch (Throwable) {
            $this->failures[] = 'preflight_failure';

            return $this->report();
        }
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            try {
                $this->lifecycle($route);
            } catch (Throwable) {
                $this->failures[] = $route . ':lifecycle_failure';
            } finally {
                $this->cleanup($route);
            }
            if ($this->resources[$route] === 'uncertain') {
                $this->failures[] = 'unresolved_mutation';
                break;
            }
        }

        return $this->report();
    }

    /** Complete both-route inventories and domain checks before the first create. */
    private function preflight(): void
    {
        $passed = true;
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach (BoxTrapperSectionRecipe::preflight() as $phase => $function) {
                try {
                    if ($function === 'list_pops') {
                        $this->absent($this->inventory($phase, $route));
                    } else {
                        $rows = $this->require($phase, $route);
                        $this->expect(is_array($rows) && in_array('npr.rs', array_column($rows, 'domain'), true));
                    }
                    $passed = $passed && $this->last()['discrepancies'] === [];
                } catch (Throwable) {
                    $passed = false;
                }
            }
        }
        if (!$passed) {
            throw new RuntimeException('BoxTrapper preflight failed.');
        }
    }

    /** @param Route $route */
    private function lifecycle(string $route): void
    {
        $address = $this->recipe->address($route);
        $this->absent($this->inventory('ready_mailboxes', $route));
        $this->resources[$route] = 'uncertain';
        $created = $this->require('create_mailbox', $route);
        $this->expect($created === str_replace('@', '+', $address));
        $identities = $this->inventory('created_mailboxes', $route);
        $this->expect(in_array($address, $identities, true));
        $this->resources[$route] = 'owned';
        // Never enable BoxTrapper; unexpected enabled defaults abort settings and still clean the owned mailbox.
        $this->expect($this->require('initial_status', $route) === 0);
        $this->require('disable', $route);
        $this->require('configure', $route);
        $configuration = $this->require('configuration', $route);
        $this->expect(is_array($configuration)
            && ($configuration['enable_auto_whitelist'] ?? null) === 0
            && ($configuration['whitelist_by_association'] ?? null) === 0
            && ($configuration['from_addresses'] ?? null) === $address
            && ($configuration['queue_days'] ?? null) === 1
            && ($configuration['from_name'] ?? null) === 'BoxTrapper fixture'
            && ($configuration['spam_score'] ?? null) === 2.5);
        foreach (['allowlist', 'blocklist', 'ignorelist'] as $list) {
            $this->require('set_' . $list, $route);
            $rules = $this->require($list, $route);
            $this->expect(is_array($rules) && $rules === [$this->recipe->rule($route)]);
        }
        $templates = $this->require('templates', $route);
        $this->expect(is_array($templates) && in_array('verify', $templates, true));
        $this->expect(is_string($this->require('template', $route)));
        // Both operations document usable empty states; never seed or process a real message.
        $log = $this->require('log', $route);
        $this->expect(is_array($log) && ($log['lines'] ?? null) === []);
        $this->expect($this->require('queue', $route) === []);
        $this->expect($this->require('final_status', $route) === 0);
    }

    /** @param Route $route */
    private function cleanup(string $route): void
    {
        $deleted = false;
        if ($this->resources[$route] === 'owned') {
            $this->resources[$route] = 'uncertain';
            try {
                $this->require('cleanup_mailbox', $route);
                $deleted = true;
            } catch (Throwable) {
                $this->failures[] = $route . ':cleanup_failure';
            }
        }
        try {
            $this->absent($this->inventory('final_mailboxes', $route));
            if ($deleted) {
                $this->resources[$route] = 'cleaned';
            } elseif ($this->resources[$route] !== 'not_started') {
                $this->failures[] = $route . ':cleanup_unconfirmed';
            }
        } catch (Throwable) {
            if ($this->resources[$route] !== 'not_started') {
                $this->resources[$route] = 'uncertain';
            }
            $this->failures[] = $route . ':cleanup_unconfirmed';
        }
    }

    /** @param 'whm'|Route $route */
    private function require(string $phase, string $route): mixed
    {
        $step = $this->recipe->step($phase, $route);
        $probe = $step['probe'];
        $result = null;
        $wrapped = new Probe(
            $probe->operation,
            $probe->route,
            $probe->spec,
            $probe->uri,
            static function (#[SensitiveParameter] Client $client) use ($probe, &$result): mixed {
                return $result = ($probe->invoke)($client);
            },
        );
        $this->guard->authorize($phase, $route);
        try {
            $record = $this->runner->capture($wrapped, $this->capture, $this->clients[$route]);
        } finally {
            $this->guard->clearPending();
        }
        $this->records[] = [...$record, 'phase' => $phase,
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked'];
        if ($record['probe_outcome'] !== 'passed') {
            throw new RuntimeException('BoxTrapper observation failed.');
        }

        return $result;
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $index = array_key_last($this->records);
            if ($index !== null) {
                $this->records[$index]['expectation'] = 'failed';
            }
            throw new RuntimeException('BoxTrapper expectation failed.');
        }
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('BoxTrapper observation unavailable.');
        }

        return $this->records[$index];
    }

    /**
     * @param Route $route
     * @return list<string>
     */
    private function inventory(string $phase, string $route): array
    {
        $rows = $this->require($phase, $route);
        $this->expect(is_array($rows) && array_is_list($rows));
        if (!is_array($rows)) {
            throw new RuntimeException('Invalid mailbox inventory.');
        }
        $identities = [];
        foreach ($rows as $row) {
            $identity = is_array($row) ? ($row['login'] ?? null) : null;
            $this->expect(is_string($identity) && str_contains($identity, '@')
                && !in_array($identity, $identities, true));
            if (!is_string($identity)) {
                throw new RuntimeException('Invalid mailbox identity.');
            }
            $identities[] = $identity;
        }

        return $identities;
    }

    /** @param list<string> $identities */
    private function absent(#[SensitiveParameter] array $identities): void
    {
        $this->expect(array_intersect($this->recipe->candidates(), $identities) === []);
    }

    /** @return Report */
    private function report(): array
    {
        $finished = BoxTrapperSectionEvidence::finish($this->records, $this->context);
        $cleaned = $this->resources === ['direct-uapi' => 'cleaned', 'bridged-uapi' => 'cleaned'];

        return ['recipe' => 'boxtrapper-npr-disabled-owned-mailboxes-v1',
            'status' => $this->failures === [] && $cleaned && $finished['assessment']['status'] === 'passed'
                ? 'passed' : 'failed',
            ...$finished, 'resources' => $this->resources, 'failures' => array_values(array_unique($this->failures)),
            'limitations' => [
                'no_mail_sent_welcome_email_disabled_no_queue_actions',
                'boxtrapper_never_enabled_and_only_owned_mailboxes_configured',
                'no_forwarders_template_writes_accountwide_settings_or_spam_clearing',
                'settings_readback_does_not_establish_delivery_or_filter_enforcement',
                'empty_queue_and_log_do_not_establish_populated_item_contracts',
                'unknown_fields_are_unacknowledged_and_strictly_fail',
                'unconfirmed_creations_are_not_adopted_and_require_manual_review',
                'only_recorded_functions_routes_versions_and_revision_observed',
            ]];
    }
}
