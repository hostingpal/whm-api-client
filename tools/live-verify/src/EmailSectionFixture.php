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
 * Single-use mailbox fixture with global preflight and exact run-owned cleanup through both routes.
 *
 * All read preflights finish before writes. Only the two fresh addresses may be changed. A confirmed
 * API creation establishes ownership even when its payload mismatches; uncertain creations are never
 * adopted or deleted, and a later absence observation cannot resolve an in-flight create. No mail is
 * sent: welcome mail is disabled and no delivery, forwarding, autoresponder or outbound queue action
 * belongs to the recipe. Password verification is API readback, not an IMAP/SMTP authentication claim.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Route from EmailSectionRecipe
 * @phpstan-type Observation array{
 *     phase: string, expectation: 'passed'|'failed'|'not_checked',
 *     operation: string, variant: string, route: string, method: string|null, server_version: string|null,
 *     observed_at: string, revision: string, source_sha256: string, map_sha256: string, generator_tree: string,
 *     overlay_sha256: string, effective_contract_sha256: string, http_status: int|null,
 *     api_outcome: string, payload_validation: string, probe_outcome: string, failure_kind: string|null,
 *     response_paths: array<string, list<string>>, payload_paths: array<string, list<string>>,
 *     discrepancies: list<array{path: string, kind: string}>
 * }
 * @phpstan-type State 'not_started'|'owned'|'cleaned'|'uncertain'
 * @phpstan-type Report array{
 *     recipe: string, status: 'passed'|'failed', probes: list<Observation>, resources: array<Route, State>,
 *     failures: list<string>, limitations: list<string>,
 *     assessment: array{status: 'passed'|'failed', findings: list<string>}
 * }
 */
final class EmailSectionFixture
{
    private readonly EmailSectionRecipe $recipe;

    private readonly EmailSectionGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    /** @var array<'whm'|Route, Client> */
    private readonly array $clients;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<Route, State> */
    private array $resources = ['direct-uapi' => 'not_started', 'bridged-uapi' => 'not_started'];

    /** @var list<string> */
    private array $failures = [];

    private bool $used = false;

    /**
     * Bind a fresh password and exact npr addresses; construction sends no request.
     *
     * @param array{whm: string, cpanel: string} $tokens
     * @param Context $context Committed provenance from RepositoryContext::load(), or explicit offline test provenance.
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] array $tokens,
        #[SensitiveParameter] string $suffix,
        private readonly array $context,
    ) {
        $this->recipe = new EmailSectionRecipe($suffix, 'Ws!9' . bin2hex(random_bytes(20)));
        $this->guard = new EmailSectionGuard($http, $tokens, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $factory = new Psr17Factory();
        $transport = new HttpTransport($this->capture, $factory, $factory);
        $direct = new Client([
            'host' => 'test.oblak.host', 'port' => 2083, 'authType' => 'cpanel',
            'username' => 'npr', 'token' => $tokens['cpanel'],
        ], $transport);
        $bridge = new Client([
            'host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm',
            'username' => 'root', 'token' => $tokens['whm'],
        ], $transport);
        $this->clients = ['whm' => $bridge, 'direct-uapi' => $direct, 'bridged-uapi' => $bridge];
        $this->runner = new EvidenceRunner($this->guard, $context);
    }

    /**
     * Capture each original response once and return structural evidence, including failures and cleanup.
     *
     * Both opt-ins and exact account/domain designation are required before even the version request.
     * No raw mailbox address, password, upstream text or response value leaves the returned report.
     *
     * @return Report
     */
    public function run(): array
    {
        if (
            $this->used || getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1'
            || getenv('WHM_API_LIVE_ACCOUNT') !== 'npr' || getenv('WHM_API_LIVE_DOMAIN') !== 'npr.rs'
        ) {
            $this->failures[] = 'configuration_failure';

            return $this->report();
        }
        $this->used = true;
        try {
            $this->require('version', 'whm');
        } catch (Throwable) {
            $this->failures[] = 'version_failure';

            return $this->report();
        }
        if (!$this->preflight()) {
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

    /** Check both candidate addresses absent and finish all safe reads across both routes before any mutation. */
    private function preflight(): bool
    {
        $passed = true;
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach (EmailSectionRecipe::preflight() as $phase => $function) {
                try {
                    if ($phase === 'initial_mailboxes') {
                        $this->absent($this->inventory($phase, $route), $this->recipe->candidates());
                    } else {
                        $result = $this->require($phase, $route);
                        if (
                            in_array(
                                $function,
                                ['count_pops', 'get_default_email_quota', 'get_max_email_quota'],
                                true,
                            )
                        ) {
                            $this->expect(is_int($result) && $result >= 0);
                        }
                        if ($function === 'list_mail_domains') {
                            $this->expect(is_array($result)
                                && in_array('npr.rs', array_column($result, 'domain'), true));
                        }
                    }
                    $passed = $passed && $this->last()['discrepancies'] === [];
                } catch (Throwable) {
                    $passed = false;
                }
            }
        }

        return $passed;
    }

    /** @param Route $route */
    private function lifecycle(string $route): void
    {
        $address = $this->recipe->address($route);
        $this->absent($this->inventory('ready_mailboxes', $route), [$address]);
        $this->resources[$route] = 'uncertain';
        $result = $this->invoke('create_mailbox', $route);
        if ($this->last()['api_outcome'] === 'success') {
            $this->resources[$route] = 'owned';
        }
        $this->successful();
        // The pinned add_pop result uses username+domain; inventory login identities use username@domain.
        $this->expect($result === str_replace('@', '+', $address));
        $this->suspended($this->inventory('created_mailboxes', $route), $address, 0);
        $this->expect($this->require('initial_quota', $route) === 32);
        $this->require('change_password', $route);
        $this->expect($this->require('verify_password', $route) === 1);
        $this->require('change_quota', $route);
        $this->expect($this->require('changed_quota', $route) === 64);
        $this->require('suspend_login', $route);
        $this->suspended($this->inventory('suspended_mailboxes', $route), $address, 1);
        $this->require('unsuspend_login', $route);
        $this->suspended($this->inventory('resumed_mailboxes', $route), $address, 0);
    }

    /**
     * Delete only a confirmed owned address once; retain all uncertain outcomes for manual review.
     *
     * @param Route $route
     */
    private function cleanup(string $route): void
    {
        $createdUncertain = $this->resources[$route] === 'uncertain';
        $deleted = false;
        if ($this->resources[$route] === 'owned') {
            $this->resources[$route] = 'uncertain';
            try {
                $this->invoke('cleanup_mailbox', $route);
                $deleted = $this->last()['api_outcome'] === 'success';
                $this->successful();
            } catch (Throwable) {
                $this->failures[] = $route . ':cleanup_failure';
            }
        }
        try {
            $this->absent($this->inventory('final_mailboxes', $route), [$this->recipe->address($route)]);
            if ($this->resources[$route] === 'not_started') {
                return;
            }
            if ($createdUncertain || !$deleted) {
                $this->failures[] = $route . ':cleanup_unconfirmed';

                return;
            }
            $this->resources[$route] = 'cleaned';
        } catch (Throwable) {
            if ($this->resources[$route] !== 'not_started') {
                $this->resources[$route] = 'uncertain';
            }
            $this->failures[] = $route . ':cleanup_unconfirmed';
        }
    }

    /**
     * Preserve raw capture outcomes; semantic checks have their own field.
     *
     * @param 'whm'|Route $route
     */
    private function invoke(string $phase, string $route): mixed
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

        return $result;
    }

    /** @param 'whm'|Route $route */
    private function require(string $phase, string $route): mixed
    {
        $result = $this->invoke($phase, $route);
        $this->successful();

        return $result;
    }

    private function successful(): void
    {
        if ($this->last()['probe_outcome'] !== 'passed') {
            throw new RuntimeException('Email observation failed.');
        }
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $index = array_key_last($this->records);
            if ($index !== null) {
                $this->records[$index]['expectation'] = 'failed';
            }
            throw new RuntimeException('Email expectation failed.');
        }
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('Email observation unavailable.');
        }

        return $this->records[$index];
    }

    /**
     * Require unique complete login identities, preserving optional row fields for targeted semantic checks.
     *
     * @param Route $route
     * @return array<string, array<array-key, mixed>>
     */
    private function inventory(string $phase, string $route): array
    {
        $rows = $this->require($phase, $route);
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->expect(false);
            throw new RuntimeException('Email inventory unavailable.');
        }
        $identities = [];
        foreach ($rows as $row) {
            $identity = is_array($row) ? ($row['login'] ?? null) : null;
            if (!is_string($identity) || !str_contains($identity, '@') || isset($identities[$identity])) {
                $this->expect(false);
                throw new RuntimeException('Email inventory unavailable.');
            }
            $identities[$identity] = $row;
        }

        return $identities;
    }

    /** @param array<string, array<array-key, mixed>> $rows
     * @param list<string> $addresses
     */
    private function absent(#[SensitiveParameter] array $rows, #[SensitiveParameter] array $addresses): void
    {
        $this->expect(array_intersect($addresses, array_keys($rows)) === []);
    }

    /** @param array<string, array<array-key, mixed>> $rows */
    private function suspended(
        #[SensitiveParameter] array $rows,
        #[SensitiveParameter] string $address,
        int $expected,
    ): void {
        $this->expect(isset($rows[$address]) && ($rows[$address]['suspended_login'] ?? null) === $expected);
    }

    /** @return Report */
    private function report(): array
    {
        $finished = EmailSectionEvidence::finish($this->records, $this->context);
        $cleaned = $this->resources === ['direct-uapi' => 'cleaned', 'bridged-uapi' => 'cleaned'];

        return [
            'recipe' => 'email-npr-owned-mailboxes-v1',
            'status' => $this->failures === [] && $cleaned && $finished['assessment']['status'] === 'passed'
                ? 'passed' : 'failed',
            ...$finished, 'resources' => $this->resources, 'failures' => array_values(array_unique($this->failures)),
            'limitations' => [
                'no_messages_sent_welcome_mail_explicitly_disabled',
                'password_api_readback_does_not_establish_imap_or_smtp_authentication',
                'suspension_api_readback_does_not_establish_login_enforcement',
                'quota_api_readback_does_not_establish_delivery_or_disk_limit_enforcement',
                'only_catalogued_functions_and_actual_routes_are_live_observed',
                'empty_lists_do_not_observe_item_fields',
                'unconfirmed_creations_require_manual_review_and_are_not_adopted',
                'preflight_failure_is_failed_evidence_not_successful_skip',
            ],
        ];
    }
}
