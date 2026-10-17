<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Create one explicitly designated disposable account with a finite 64 MB quota and temporary read-only token.
 *
 * Successful raw identity lists must establish initial account, manual-domain and zone absence. Only
 * attempted resources can be cleaned up, with a random per-run contact-email marker confirming ownership.
 * Ownership is reconciled before cleanup; a concurrent run's account cannot authorize deletion. Uncertain
 * writes are never retried. Token cleanup failure does not prevent account removal; final account,
 * domain and zone inventories are checked independently. Automatic service domains are outside the
 * get_domain_info inventory. Raw identities, credentials and response messages stay in memory.
 *
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-import-type Result from FiniteQuotaEvidence
 * @phpstan-import-type Assessment from FiniteQuotaEvidence
 * @phpstan-import-type UnitRelationship from FiniteQuotaEvidence
 * @phpstan-type CleanupOutcome 'not_required'|'confirmed_absent'|'present'|'unconfirmed'
 * @phpstan-type ResourceOutcome array{
 *     creation: 'not_attempted'|'confirmed'|'unconfirmed', cleanup: CleanupOutcome
 * }
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed'|'not_started',
 *     capture: 'passed'|'failed'|'not_run', expectations: 'passed'|'failed'|'not_run',
 *     cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     resources: array{account: ResourceOutcome, token: ResourceOutcome},
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome
 * }
 * @phpstan-type Scenario array{
 *     scenario: 'finite-quota-disposable-v1', target_role: 'disposable-test-account', probes: list<Record>,
 *     unit_relationships: array{'direct-uapi': UnitRelationship, 'bridged-uapi': UnitRelationship},
 *     discrepancy_review: Assessment, fixture: Report
 * }
 */
final class FiniteQuotaFixture
{
    private readonly FiniteQuotaGuard $guard;

    private readonly CaptureClient $capture;

    private readonly WhmClient $whm;

    private readonly UapiClient $account;

    private readonly int $expiresAt;

    private readonly string $contactEmail;

    private bool $used = false;

    /**
     * Bind the fixed disposable account and a token expiration one hour from construction, without I/O.
     *
     * The supplied HTTP client must support form POST, verified TLS and no redirects or retries. The
     * guard requires both live opt-ins and exact account/domain environment values on every request.
     */
    public function __construct(#[SensitiveParameter] ClientInterface $http, #[SensitiveParameter] string $whmToken)
    {
        $this->expiresAt = time() + 3600;
        $this->contactEmail = 'finite-' . bin2hex(random_bytes(16)) . '@' . FiniteQuotaGuard::DOMAIN;
        $this->guard = new FiniteQuotaGuard($http, $whmToken, $this->expiresAt, $this->contactEmail);
        $this->capture = new CaptureClient($this->guard);
        $factory = new Psr17Factory();
        $client = new Client([
            'host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm',
            'username' => 'root', 'token' => $whmToken,
        ], new HttpTransport($this->capture, $factory, $factory));
        $this->whm = $client->whm();
        $this->account = $client->uapi(FiniteQuotaGuard::ACCOUNT);
    }

    /**
     * Set up, capture through the guarded temporary credential, and clean up before returning.
     *
     * This instance is single-use. Setup failures skip capture but reconcile every attempted resource.
     * The capture callback preserves original probe outcomes and same-response structural evidence;
     * its strict discrepancy assessment stays separate from fixture and cleanup success. Exception
     * messages and returned credentials never enter this report.
     *
     * @param Closure(ClientInterface, string): Result $capture
     * @return Scenario
     */
    public function run(#[SensitiveParameter] Closure $capture): array
    {
        $report = self::report();
        $result = [
            'probes' => [],
            'unit_relationships' => ['direct-uapi' => 'unavailable', 'bridged-uapi' => 'unavailable'],
            'discrepancy_review' => FiniteQuotaEvidence::assess([]),
        ];
        try {
            if ($this->used) {
                throw new RuntimeException('Finite quota fixture is single-use.');
            }
            FiniteQuotaGuard::requireEnvironment();
        } catch (Throwable) {
            $report['failures'][] = 'configuration_failure';

            return self::scenario($result, $report);
        }
        $this->used = true;
        try {
            $report['setup'] = 'failed';
            $token = $this->setup($report);
            $report['setup'] = 'passed';
            $report['capture'] = 'failed';
            $result = $capture($this->guard, $token);
            $report['capture'] = 'passed';
            $report['expectations'] = $result['expectations'];
            if ($result['expectations'] !== 'passed') {
                $report['failures'][] = 'finite_quota_expectation_failure';
            }
        } catch (Throwable) {
            $report['failures'][] = $report['setup'] === 'passed' ? 'capture_failure' : 'setup_failure';
        } finally {
            $this->cleanup($report);
        }
        $report['failures'] = array_values(array_unique($report['failures']));
        if ($report['failures'] === [] && $report['expectations'] === 'passed' && $report['cleanup'] === 'passed') {
            $report['status'] = 'passed';
        }

        return self::scenario($result, $report);
    }

    /** @param Report $report */
    private function setup(array &$report): string
    {
        if ($this->accountExists() || $this->domainExists() || $this->zoneExists()) {
            throw new RuntimeException('Finite quota fixture identity is already present.');
        }
        $report['resources']['account']['creation'] = 'unconfirmed';
        $this->whm->call('createacct', [
            'username' => FiniteQuotaGuard::ACCOUNT, 'domain' => FiniteQuotaGuard::DOMAIN,
            'contactemail' => $this->contactEmail,
            'quota' => 64, 'forcedns' => 0, 'hasshell' => 0, 'ip' => 'n',
        ], RequestMethod::Post);
        $report['resources']['account']['creation'] = 'confirmed';
        if (!$this->accountExists() || $this->tokenExists()) {
            throw new RuntimeException('Finite quota fixture setup identities are unusable.');
        }
        $report['resources']['token']['creation'] = 'unconfirmed';
        $result = $this->account->call('Tokens', 'create_full_access', [
            'name' => FiniteQuotaGuard::TOKEN_NAME, 'expires_at' => $this->expiresAt, 'readonly' => 1,
        ], RequestMethod::Post);
        $report['resources']['token']['creation'] = 'confirmed';
        $token = is_array($result->data) ? ($result->data['token'] ?? null) : null;
        if (!is_string($token)) {
            throw new RuntimeException('Finite quota fixture token was not returned.');
        }
        $this->confirmTokenCapabilities();
        $this->guard->bindToken($token);

        return $token;
    }

    /**
     * Read exact account ownership; another primary domain cannot authorize account removal.
     *
     * @phpstan-impure
     */
    private function accountExists(): bool
    {
        $rows = $this->accounts('user');
        if ($rows === []) {
            return false;
        }
        if (
            count($rows) !== 1 || $rows[0]->domain !== FiniteQuotaGuard::DOMAIN
            || ($rows[0]->email ?? null) !== $this->contactEmail
        ) {
            throw new RuntimeException('Finite quota account ownership is unconfirmed.');
        }

        return true;
    }

    /**
     * Check main and manually configured addon/alias/subdomain identities, without persisting inventory.
     *
     * @phpstan-impure
     */
    private function domainExists(): bool
    {
        $accounts = $this->accounts('domain');
        $this->whm->call('get_domain_info', [], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->domains ?? null) : null, 'domain', 'user');

        return $accounts !== [] || in_array(FiniteQuotaGuard::DOMAIN, array_column($rows, 'domain'), true);
    }

    /** @return list<stdClass> */
    private function accounts(string $searchType): array
    {
        $search = $searchType === 'user' ? FiniteQuotaGuard::ACCOUNT : FiniteQuotaGuard::DOMAIN;
        $this->whm->call('listaccts', [
            'search' => $search, 'searchtype' => $searchType, 'searchmethod' => 'exact', 'want' => 'user,domain,email',
        ], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->acct ?? null) : null, 'user', 'domain');
        foreach ($rows as $row) {
            if ($row->{$searchType} !== $search) {
                throw new RuntimeException('Finite quota inventory did not respect the identity filter.');
            }
        }

        return $rows;
    }

    /** @phpstan-impure */
    private function zoneExists(): bool
    {
        $this->whm->call('listzones', [], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->zone ?? null) : null, 'domain');

        return in_array(FiniteQuotaGuard::DOMAIN, array_column($rows, 'domain'), true);
    }

    /** @phpstan-impure */
    private function tokenExists(): bool
    {
        $this->account->call('Tokens', 'list', [], RequestMethod::Get);
        $rows = self::identityRows($this->rawPayload(true), 'name');

        return in_array(FiniteQuotaGuard::TOKEN_NAME, array_column($rows, 'name'), true);
    }

    /** Confirm requested capabilities once during setup; cleanup needs usable identities only. */
    private function confirmTokenCapabilities(): void
    {
        $this->account->call('Tokens', 'list', [], RequestMethod::Get);
        $rows = self::identityRows($this->rawPayload(true), 'name');
        foreach ($rows as $row) {
            if ($row->name === FiniteQuotaGuard::TOKEN_NAME) {
                if (($row->readonly ?? null) === 1 && ($row->expires_at ?? null) === $this->expiresAt) {
                    return;
                }
                break;
            }
        }
        throw new RuntimeException('Finite quota token capabilities are unconfirmed.');
    }

    /**
     * Inspect the same successful generic response while retaining JSON object/list distinctions.
     *
     * The generic call has already validated the envelope and outcome. No typed optional-field
     * mismatch can prevent identity cleanup, and an empty JSON object cannot prove an empty list.
     */
    private function rawPayload(bool $bridge): mixed
    {
        $raw = $this->capture->rawBody();
        if ($raw === null) {
            throw new RuntimeException('Finite quota identity inventory was not captured.');
        }
        $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $payload = $document instanceof stdClass ? ($document->data ?? null) : null;
        if ($bridge) {
            $uapi = $payload instanceof stdClass ? ($payload->uapi ?? null) : null;

            return $uapi instanceof stdClass ? ($uapi->data ?? null) : null;
        }

        return $payload;
    }

    /** @return list<stdClass> */
    private static function identityRows(
        #[SensitiveParameter] mixed $rows,
        string $identity,
        ?string $related = null,
    ): array {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Finite quota identity inventory must be a list.');
        }
        $identities = [];
        foreach ($rows as $row) {
            $name = $row instanceof stdClass ? ($row->{$identity} ?? null) : null;
            $owner = $row instanceof stdClass && $related !== null ? ($row->{$related} ?? null) : '';
            if (
                !is_string($name) || $name === '' || in_array($name, $identities, true)
                || !is_string($owner) || ($related !== null && $owner === '')
            ) {
                throw new RuntimeException('Finite quota inventory identities are unusable.');
            }
            $identities[] = $name;
        }

        return $rows;
    }

    /** @param Report $report */
    private function cleanup(array &$report): void
    {
        if ($report['resources']['account']['creation'] === 'not_attempted') {
            return;
        }
        $report['cleanup'] = 'passed';
        $report['resources']['account']['cleanup'] = 'unconfirmed';
        if ($report['resources']['token']['creation'] !== 'not_attempted') {
            $report['resources']['token']['cleanup'] = 'unconfirmed';
        }
        try {
            if ($this->accountExists()) {
                $report['resources']['account']['creation'] = 'confirmed';
                $this->cleanupToken($report);
                if (!$this->accountExists()) {
                    throw new RuntimeException('Finite quota account ownership changed during cleanup.');
                }
                try {
                    $this->whm->call('removeacct', [
                        'username' => FiniteQuotaGuard::ACCOUNT, 'keepdns' => 0,
                    ], RequestMethod::Post);
                } catch (Throwable) {
                    self::cleanupFailure($report, 'account_remove_failure');
                }
                $report['resources']['account']['cleanup'] = $this->accountExists() ? 'present' : 'confirmed_absent';
            } else {
                $report['resources']['account']['cleanup'] = 'confirmed_absent';
                if ($report['resources']['token']['creation'] !== 'not_attempted') {
                    $report['resources']['token']['cleanup'] = 'unconfirmed';
                    self::cleanupFailure($report, 'token_absence_unconfirmed');
                }
            }
            if ($report['resources']['account']['cleanup'] === 'present') {
                self::cleanupFailure($report, 'account_still_present');
            }
        } catch (Throwable) {
            self::cleanupFailure($report, 'account_identity_unconfirmed');
        }
        try {
            $report['domain_cleanup'] = $this->domainExists() ? 'present' : 'confirmed_absent';
            if ($report['domain_cleanup'] === 'present') {
                self::cleanupFailure($report, 'domain_still_present');
            }
        } catch (Throwable) {
            $report['domain_cleanup'] = 'unconfirmed';
            self::cleanupFailure($report, 'domain_absence_unconfirmed');
        }
        try {
            $report['zone_cleanup'] = $this->zoneExists() ? 'present' : 'confirmed_absent';
            if ($report['zone_cleanup'] === 'present') {
                self::cleanupFailure($report, 'zone_still_present');
            }
        } catch (Throwable) {
            $report['zone_cleanup'] = 'unconfirmed';
            self::cleanupFailure($report, 'zone_absence_unconfirmed');
        }
    }

    /** @param Report $report */
    private function cleanupToken(array &$report): void
    {
        if ($report['resources']['token']['creation'] === 'not_attempted') {
            return;
        }
        $report['resources']['token']['cleanup'] = 'unconfirmed';
        try {
            if ($this->tokenExists()) {
                $report['resources']['token']['creation'] = 'confirmed';
                if (!$this->accountExists()) {
                    throw new RuntimeException('Finite quota token ownership changed during cleanup.');
                }
                try {
                    $this->account->call('Tokens', 'revoke', [
                        'name' => FiniteQuotaGuard::TOKEN_NAME,
                    ], RequestMethod::Post);
                } catch (Throwable) {
                    self::cleanupFailure($report, 'token_revoke_failure');
                }
                $report['resources']['token']['cleanup'] = $this->tokenExists() ? 'present' : 'confirmed_absent';
            } else {
                $report['resources']['token']['cleanup'] = 'confirmed_absent';
            }
            if ($report['resources']['token']['cleanup'] === 'present') {
                self::cleanupFailure($report, 'token_still_present');
            }
        } catch (Throwable) {
            self::cleanupFailure($report, 'token_absence_unconfirmed');
        }
    }

    /** @param Report $report */
    private static function cleanupFailure(array &$report, string $kind): void
    {
        $report['cleanup'] = 'failed';
        $report['failures'][] = $kind;
    }

    /** @return Report */
    private static function report(): array
    {
        $resource = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];

        return [
            'status' => 'failed', 'setup' => 'not_started', 'capture' => 'not_run',
            'expectations' => 'not_run', 'cleanup' => 'not_required', 'failures' => [],
            'resources' => ['account' => $resource, 'token' => $resource],
            'domain_cleanup' => 'not_required', 'zone_cleanup' => 'not_required',
        ];
    }

    /**
     * @param array{
     *     probes: list<Record>,
     *     unit_relationships: array{'direct-uapi': UnitRelationship, 'bridged-uapi': UnitRelationship},
     *     discrepancy_review: Assessment
     * } $result
     * @param Report $report
     * @return Scenario
     */
    private static function scenario(array $result, array $report): array
    {
        return [
            'scenario' => 'finite-quota-disposable-v1', 'target_role' => 'disposable-test-account',
            'probes' => $result['probes'], 'unit_relationships' => $result['unit_relationships'],
            'discrepancy_review' => $result['discrepancy_review'], 'fixture' => $report,
        ];
    }
}
