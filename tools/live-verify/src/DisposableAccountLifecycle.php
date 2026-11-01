<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Create one disposable account and token, hand the token to a recipe, then remove everything and prove it.
 *
 * run() is the whole surface, so cleanup cannot be forgotten: it always runs, every step of it. Every account-scoped
 * recipe builds on this and keeps only its own domain logic. The recipe's guard admits the binding's control requests.
 *
 * Ownership. Account, domain and zone inventories must prove initial absence. The server always hosts others, so an
 * empty list proves nothing and neither does `{}`. An existing account is never adopted. The random contact-email
 * marker, held only in memory, is the sole proof that an account is this run's; it is read again at every gate, and
 * nothing is mutated on an account it does not prove. Only an explicit API refusal is a known `createacct` outcome.
 * After a lost response, gateway error or unreadable body an absent read cannot rule out a creation still in flight,
 * so the account stays unconfirmed and is never reported absent.
 *
 * Token. A short-lived token lives only in memory and is confirmed before the recipe sees it. Its name is fixed and
 * proves nothing alone: a revoke needs the marker read again plus a listing that shows it, or this run's own confirmed
 * creation, since setup read the name absent just before its one create. An unreadable listing therefore cannot
 * withhold the revoke of a token this run created, while a lost creation that no listing can show is never revoked.
 *
 * Cleanup. No mutation repeats. Only the ownership read that gates a cleanup decision repeats, at most three reads and
 * only after a TransportException, so one lost read cannot abandon an account and its token. Anything the server
 * returned is final, however unusable. Failure at one resource never stops the next. Token, account, domain and zone
 * absence are each read independently, and an optional filesystem proof runs last. It is read-only and repeats nothing,
 * so its outcome only changes the report. A failed or uncertain step fails `cleanup`, and the fields still say what
 * each resource showed.
 *
 * Results carry fixed labels only. Credentials, names, raw payloads and exception text never leave memory.
 *
 * @phpstan-import-type Finding from FilesystemAbsenceProof
 * @phpstan-type CleanupOutcome 'not_required'|'confirmed_absent'|'present'|'unconfirmed'
 * @phpstan-type ResourceOutcome array{
 *     creation: 'not_attempted'|'confirmed'|'unconfirmed', cleanup: CleanupOutcome
 * }
 * @phpstan-type FilesystemOutcome 'not_claimed'|'confirmed_absent'|'present'|'unconfirmed'
 * @phpstan-type Outcome array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed', exercise: 'passed'|'failed'|'not_run',
 *     cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     account: ResourceOutcome, token: ResourceOutcome,
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome,
 *     filesystem_cleanup: FilesystemOutcome, filesystem_residue: array<string, int>
 * }
 */
final class DisposableAccountLifecycle
{
    private const IDENTITY_READS = 3;

    private const REREAD_PAUSE_MICROSECONDS = 250000;

    private readonly WhmClient $whm;

    private readonly UapiClient $tokens;

    /** @var Outcome */
    private array $outcome = [
        'status' => 'failed', 'setup' => 'failed', 'exercise' => 'not_run', 'cleanup' => 'not_required',
        'failures' => [],
        'account' => ['creation' => 'not_attempted', 'cleanup' => 'not_required'],
        'token' => ['creation' => 'not_attempted', 'cleanup' => 'not_required'],
        'domain_cleanup' => 'not_required', 'zone_cleanup' => 'not_required',
        'filesystem_cleanup' => 'not_claimed', 'filesystem_residue' => [],
    ];

    private bool $creationOutcomeUnknown = false;

    private bool $used = false;

    /**
     * Bind one account to the root client whose guarded transport is $capture; performs no I/O.
     *
     * The lifecycle reads each identity list from the capture of that same response, so a client on another transport
     * fails safely as unreadable. A null $filesystem leaves `filesystem_cleanup` at `not_claimed`.
     */
    public function __construct(
        private readonly DisposableAccount $account,
        private readonly CaptureClient $capture,
        Client $root,
        private readonly ?FilesystemAbsenceProof $filesystem = null,
    ) {
        $this->whm = $root->whm();
        $this->tokens = $root->uapi($account->user);
    }

    /**
     * Provision, call $exercise with the memory-only token, clean up, and report every resource. Single-use.
     *
     * $exercise runs only after setup fully passed. $cleanOwned, if given, removes what the exercise made inside the
     * account: it runs only once the marker proves the account, before the token is revoked so the token still works,
     * and even when the exercise failed. It returns failure labels for the report. Anything either callback throws is
     * reported, and it stops nothing. The result's `status` is `passed` only if setup, exercise and cleanup all passed.
     *
     * @param Closure(string): void $exercise
     * @param (Closure(): list<string>)|null $cleanOwned
     * @return Outcome
     * @throws LogicException A second call or a missing live opt-in, before any request.
     */
    public function run(#[SensitiveParameter] Closure $exercise, ?Closure $cleanOwned = null): array
    {
        if ($this->used) {
            throw new LogicException('Disposable account lifecycle is single-use.');
        }
        $this->account->requireLiveOptIn();
        $this->used = true;
        try {
            $token = $this->provision();
            $this->outcome['setup'] = 'passed';
            $this->outcome['exercise'] = 'failed';
        } catch (Throwable) {
            $this->outcome['failures'][] = 'setup_failure';
            $token = null;
        }
        if ($token !== null) {
            try {
                $exercise($token);
                $this->outcome['exercise'] = 'passed';
            } catch (Throwable) {
                $this->outcome['failures'][] = 'exercise_failure';
            }
        }
        unset($token);
        $this->cleanUp($cleanOwned);
        $this->outcome['failures'] = array_values(array_unique($this->outcome['failures']));
        if (
            $this->outcome['setup'] === 'passed' && $this->outcome['exercise'] === 'passed'
            && $this->outcome['cleanup'] === 'passed'
        ) {
            $this->outcome['status'] = 'passed';
        }

        return $this->outcome;
    }

    /** Prove absence, create the account and token once each, confirm both, and return the token. */
    private function provision(): string
    {
        if ($this->accountExists() || $this->domainExists() || $this->zoneExists()) {
            throw new RuntimeException('Disposable account identity is already present.');
        }
        $this->outcome['account']['creation'] = 'unconfirmed';
        try {
            $this->whm->call('createacct', [
                'username' => $this->account->user, 'domain' => $this->account->domain,
                'contactemail' => $this->account->contactEmail,
                'quota' => 64, 'forcedns' => 0, 'hasshell' => 0, 'ip' => 'n',
            ], RequestMethod::Post);
        } catch (ApiErrorException $refusal) {
            // The server answered and refused: a known outcome, so a later absent read is final.
            throw $refusal;
        } catch (Throwable $failure) {
            $this->creationOutcomeUnknown = true;
            throw $failure;
        }
        $this->outcome['account']['creation'] = 'confirmed';
        if (!$this->accountExists() || $this->tokenExists()) {
            throw new RuntimeException('Disposable account setup identities are unusable.');
        }
        $this->outcome['token']['creation'] = 'unconfirmed';
        $parameters = ['name' => $this->account->tokenName, 'expires_at' => $this->account->tokenExpiresAt];
        $result = $this->tokens->call(
            'Tokens',
            'create_full_access',
            $this->account->readOnlyToken ? [...$parameters, 'readonly' => 1] : $parameters,
            RequestMethod::Post,
        );
        $this->outcome['token']['creation'] = 'confirmed';
        $token = is_array($result->data) ? ($result->data['token'] ?? null) : null;
        if (!is_string($token)) {
            throw new RuntimeException('Disposable account token was not returned.');
        }
        $this->confirmTokenCapabilities();

        return $token;
    }

    /**
     * Read exact account ownership; another primary domain or marker cannot authorize anything.
     *
     * @phpstan-impure
     */
    private function accountExists(): bool
    {
        $rows = array_values(array_filter($this->accounts(), fn (stdClass $row): bool =>
            $row->user === $this->account->user));
        if ($rows === []) {
            return false;
        }
        if (
            count($rows) !== 1 || $rows[0]->domain !== $this->account->domain
            || ($rows[0]->email ?? null) !== $this->account->contactEmail
        ) {
            throw new RuntimeException('Disposable account ownership is unconfirmed.');
        }

        return true;
    }

    /**
     * Read account ownership for a cleanup decision, surviving a transient failed request.
     *
     * Only this read repeats, and only when the request itself failed: anything the server returned is final, so a
     * result-0, HTTP or unreadable reply, a foreign marker or unusable rows are never read twice.
     *
     * @phpstan-impure
     */
    private function accountOwned(): bool
    {
        for ($attempt = 1; $attempt < self::IDENTITY_READS; ++$attempt) {
            try {
                return $this->accountExists();
            } catch (TransportException) {
                usleep(self::REREAD_PAUSE_MICROSECONDS);
            }
        }

        return $this->accountExists();
    }

    /**
     * Check main and manually configured addon/alias/subdomain identities, without persisting inventory.
     *
     * @phpstan-impure
     */
    private function domainExists(): bool
    {
        $accounts = $this->accounts();
        $this->whm->call('get_domain_info', [], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->domains ?? null) : null, 'domain', 'user');
        if ($rows === []) {
            throw new RuntimeException('Disposable account domain inventory cannot prove absence.');
        }

        return in_array($this->account->domain, array_column($accounts, 'domain'), true)
            || in_array($this->account->domain, array_column($rows, 'domain'), true);
    }

    /**
     * Validate every row before exact local filtering; a failed, null or empty lookup cannot prove absence.
     *
     * @return list<stdClass>
     */
    private function accounts(): array
    {
        $this->whm->call('listaccts', ['want' => 'user,domain,email'], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->acct ?? null) : null, 'user', 'domain');
        if ($rows === []) {
            throw new RuntimeException('Disposable account inventory cannot prove absence.');
        }

        return $rows;
    }

    /** @phpstan-impure */
    private function zoneExists(): bool
    {
        $this->whm->call('listzones', [], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->zone ?? null) : null, 'domain');
        if ($rows === []) {
            throw new RuntimeException('Disposable account zone inventory cannot prove absence.');
        }

        return in_array($this->account->domain, array_column($rows, 'domain'), true);
    }

    /**
     * Read exact token presence; unlike the server-wide lists, a fresh account really can hold no tokens.
     *
     * @phpstan-impure
     */
    private function tokenExists(): bool
    {
        $this->tokens->call('Tokens', 'list', [], RequestMethod::Get);
        $rows = self::identityRows($this->rawPayload(true), 'name');

        return in_array($this->account->tokenName, array_column($rows, 'name'), true);
    }

    /**
     * Confirm the requested access and exact expiry once during setup; cleanup needs usable identities only.
     *
     * The source says readonly is absent or 0 on a writable token. A reviewed setup returned expires_at as the exact
     * requested decimal string, which is accepted without numeric coercion.
     */
    private function confirmTokenCapabilities(): void
    {
        $expected = $this->account->tokenExpiresAt;
        $this->tokens->call('Tokens', 'list', [], RequestMethod::Get);
        foreach (self::identityRows($this->rawPayload(true), 'name') as $row) {
            if ($row->name === $this->account->tokenName) {
                $expiresAt = $row->expires_at ?? null;
                $access = $this->account->readOnlyToken
                    ? ($row->readonly ?? null) === 1 : ($row->readonly ?? 0) === 0;
                if ($access && ($expiresAt === $expected || $expiresAt === (string) $expected)) {
                    return;
                }
                break;
            }
        }
        throw new RuntimeException('Disposable account token capabilities are unconfirmed.');
    }

    /**
     * Inspect the same successful generic response while retaining JSON object/list distinctions.
     *
     * The generic call has already validated the envelope and outcome, and no typed optional-field mismatch can
     * prevent identity cleanup. An empty JSON object cannot prove an empty list.
     */
    private function rawPayload(bool $bridge): mixed
    {
        $raw = $this->capture->rawBody();
        if ($raw === null) {
            throw new RuntimeException('Disposable account identity inventory was not captured.');
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
            throw new RuntimeException('Disposable account identity inventory must be a list.');
        }
        $identities = [];
        $checked = [];
        foreach ($rows as $row) {
            $name = $row instanceof stdClass ? ($row->{$identity} ?? null) : null;
            $owner = $row instanceof stdClass && $related !== null ? ($row->{$related} ?? null) : '';
            if (
                !$row instanceof stdClass || !is_string($name) || $name === '' || in_array($name, $identities, true)
                || !is_string($owner) || ($related !== null && $owner === '')
            ) {
                throw new RuntimeException('Disposable account inventory identities are unusable.');
            }
            $identities[] = $name;
            $checked[] = $row;
        }

        return $checked;
    }

    /**
     * Reconcile every attempted resource, removing only what this run proved it owns.
     *
     * @param (Closure(): list<string>)|null $cleanOwned
     */
    private function cleanUp(?Closure $cleanOwned): void
    {
        if ($this->outcome['account']['creation'] === 'not_attempted') {
            return;
        }
        $this->outcome['cleanup'] = 'passed';
        $this->outcome['account']['cleanup'] = 'unconfirmed';
        if ($this->outcome['token']['creation'] !== 'not_attempted') {
            $this->outcome['token']['cleanup'] = 'unconfirmed';
        }
        try {
            if ($this->accountOwned()) {
                $this->outcome['account']['creation'] = 'confirmed';
                $this->cleanOwned($cleanOwned);
                $this->cleanUpToken();
                if (!$this->accountOwned()) {
                    throw new RuntimeException('Disposable account ownership changed during cleanup.');
                }
                try {
                    $this->whm->call('removeacct', [
                        'username' => $this->account->user, 'keepdns' => 0,
                    ], RequestMethod::Post);
                } catch (Throwable) {
                    $this->cleanupFailure('account_remove_failure');
                }
                $this->outcome['account']['cleanup'] = $this->accountOwned() ? 'present' : 'confirmed_absent';
            } elseif ($this->creationOutcomeUnknown) {
                $this->cleanupFailure('account_creation_outcome_unknown');
            } else {
                $this->outcome['account']['cleanup'] = 'confirmed_absent';
                if ($this->outcome['token']['creation'] !== 'not_attempted') {
                    $this->cleanupFailure('token_absence_unconfirmed');
                }
            }
            if ($this->outcome['account']['cleanup'] === 'present') {
                $this->cleanupFailure('account_still_present');
            }
        } catch (Throwable) {
            $this->cleanupFailure('account_identity_unconfirmed');
        }
        try {
            $this->outcome['domain_cleanup'] = $this->domainExists() ? 'present' : 'confirmed_absent';
            if ($this->outcome['domain_cleanup'] === 'present') {
                $this->cleanupFailure('domain_still_present');
            }
        } catch (Throwable) {
            $this->outcome['domain_cleanup'] = 'unconfirmed';
            $this->cleanupFailure('domain_absence_unconfirmed');
        }
        try {
            $this->outcome['zone_cleanup'] = $this->zoneExists() ? 'present' : 'confirmed_absent';
            if ($this->outcome['zone_cleanup'] === 'present') {
                $this->cleanupFailure('zone_still_present');
            }
        } catch (Throwable) {
            $this->outcome['zone_cleanup'] = 'unconfirmed';
            $this->cleanupFailure('zone_absence_unconfirmed');
        }
        $this->proveFilesystemAbsence();
    }

    /**
     * Let the recipe remove its own resources; nothing it does or throws can stop token or account cleanup.
     *
     * @param (Closure(): list<string>)|null $cleanOwned
     */
    private function cleanOwned(?Closure $cleanOwned): void
    {
        if ($cleanOwned === null) {
            return;
        }
        try {
            foreach ($cleanOwned() as $failure) {
                $this->cleanupFailure($failure);
            }
        } catch (Throwable) {
            $this->cleanupFailure('owned_resource_cleanup_failure');
        }
    }

    /** Revoke this run's token once, then read its absence; only expiry would otherwise end it. */
    private function cleanUpToken(): void
    {
        if ($this->outcome['token']['creation'] === 'not_attempted') {
            return;
        }
        try {
            $listed = $this->tokenExists();
        } catch (Throwable) {
            $listed = null;
        }
        if ($listed === false) {
            $this->outcome['token']['cleanup'] = 'confirmed_absent';

            return;
        }
        if ($listed === true) {
            $this->outcome['token']['creation'] = 'confirmed';
        }
        if ($this->outcome['token']['creation'] !== 'confirmed') {
            $this->cleanupFailure('token_absence_unconfirmed');

            return;
        }
        try {
            if (!$this->accountOwned()) {
                throw new RuntimeException('Disposable account token ownership changed during cleanup.');
            }
            try {
                $this->tokens->call('Tokens', 'revoke', [
                    'name' => $this->account->tokenName,
                ], RequestMethod::Post);
            } catch (Throwable) {
                $this->cleanupFailure('token_revoke_failure');
            }
            $this->outcome['token']['cleanup'] = $this->tokenExists() ? 'present' : 'confirmed_absent';
            if ($this->outcome['token']['cleanup'] === 'present') {
                $this->cleanupFailure('token_still_present');
            }
        } catch (Throwable) {
            $this->cleanupFailure('token_absence_unconfirmed');
        }
    }

    /** Run the optional proof once, last; it only reports, and anything short of proven absence fails the claim. */
    private function proveFilesystemAbsence(): void
    {
        if ($this->filesystem === null) {
            return;
        }
        try {
            $finding = $this->filesystem->check($this->account->user, $this->account->domain);
        } catch (Throwable) {
            $finding = ['outcome' => 'unconfirmed', 'residue' => []];
        }
        $this->outcome['filesystem_cleanup'] = $finding['outcome'];
        $this->outcome['filesystem_residue'] = $finding['residue'];
        if ($finding['outcome'] === 'present') {
            $this->cleanupFailure('filesystem_residue_present');
        } elseif ($finding['outcome'] === 'unconfirmed') {
            $this->cleanupFailure('filesystem_absence_unconfirmed');
        }
    }

    private function cleanupFailure(string $kind): void
    {
        $this->outcome['cleanup'] = 'failed';
        $this->outcome['failures'][] = $kind;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Lifecycle state holds the run's ownership marker and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Disposable account lifecycle state cannot be serialized.');
    }
}
