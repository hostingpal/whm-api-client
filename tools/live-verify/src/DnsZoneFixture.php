<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Exercise the generated DNS writes on two independently identified zones belonging to a marked disposable account.
 *
 * The lifecycle removes the account and revokes its token even after a setup or DNS failure. Alias cleanup uses
 * independent server-wide inventories after that proven removal. A lost parking response leaves the creation
 * uncertain, and a subsequent absence cannot settle it. The report carries fixed labels and structural probes only.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-import-type ResourceOutcome from DisposableAccountLifecycle
 * @phpstan-import-type TokenOutcome from DisposableAccountLifecycle
 * @phpstan-import-type CleanupOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemControl from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemOutcome from DisposableAccountLifecycle
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed', cleanup: 'passed'|'failed'|'not_required',
 *     failures: list<string>, domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome,
 *     filesystem_cleanup: FilesystemOutcome, filesystem_residue: array<string, int>,
 *     filesystem_control: FilesystemControl, alias_setup: string, stopped_at: ?string,
 *     resources: array{account: ResourceOutcome, token: TokenOutcome, alias_domain: ResourceOutcome,
 *         alias_zone: ResourceOutcome}
 * }
 * @phpstan-type Scenario array{
 *     scenario: string, target_role: string, server_version: ?string, probes: list<Record>, bridge_outcome: string,
 *     fixture: Report
 * }
 */
final class DnsZoneFixture
{
    public const ACCOUNT = 'wldnsrecipe';
    public const DOMAIN = 'dns-recipe.example.test';
    public const ALIAS = 'dns-alias.example.test';
    public const TOKEN_NAME = 'dns-zone-recipe';

    private const UNTOUCHED = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];

    private readonly DnsZoneGuard $guard;
    private readonly CaptureClient $capture;
    private readonly EvidenceRunner $runner;
    private readonly Client $root;
    private readonly DisposableAccountLifecycle $lifecycle;
    private readonly HttpTransport $transport;

    /** @var array{alias_domain: ResourceOutcome, alias_zone: ResourceOutcome} */
    private array $alias = ['alias_domain' => self::UNTOUCHED, 'alias_zone' => self::UNTOUCHED];

    private bool $parkingUnknown = false;
    private string $aliasSetup = 'not_started';
    private ?string $phase = null;
    private ?string $version = null;
    private ?DnsZoneExercise $exercise = null;

    /**
     * Bind the fixed hosts and fresh run marker without I/O.
     *
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] string $whmToken,
        array $context,
    ) {
        $account = DisposableAccount::fresh(self::ACCOUNT, self::DOMAIN, self::TOKEN_NAME, 900, readOnlyToken: false);
        $this->guard = new DnsZoneGuard($http, $whmToken, $account);
        $this->capture = new CaptureClient($this->guard);
        $this->runner = new EvidenceRunner($this->guard, $context, self::ACCOUNT);
        $factory = new Psr17Factory();
        $this->transport = new HttpTransport($this->capture, $factory, $factory);
        $this->root = new Client([
            'host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm',
            'username' => 'root', 'token' => $whmToken,
        ], $this->transport);
        $this->lifecycle = new DisposableAccountLifecycle($account, $this->capture, $this->root);
    }

    /**
     * Provision, exercise and remove the disposable account once. A failed phase leaves its observations in the report.
     *
     * @throws LogicException For a missing opt-in or a second call, before I/O.
     * @return Scenario
     */
    public function run(): array
    {
        $outcome = $this->lifecycle->run($this->exercise(...), proveRemoved: $this->proveRemoved(...));
        $report = DisposableAccountLifecycle::reportFields($outcome);

        return [
            'scenario' => 'uapi-dns-zone-disposable-v1', 'target_role' => 'disposable-test-account',
            'server_version' => $this->version, 'bridge_outcome' => $this->exercise?->bridgeOutcome() ?? 'not_run',
            'probes' => $this->exercise?->observations() ?? [],
            'fixture' => [
                ...$report, 'resources' => [...$report['resources'], ...$this->alias],
                'alias_setup' => $this->aliasSetup, 'stopped_at' => $this->phase,
            ],
        ];
    }

    private function exercise(#[SensitiveParameter] string $token): void
    {
        $this->aliasSetup = 'failed';
        $this->phase = 'version';
        try {
            $this->guard->bindToken($token);
            $direct = new Client([
                'host' => 'test.oblak.host', 'port' => 2083, 'authType' => 'cpanel',
                'username' => self::ACCOUNT, 'token' => $token,
            ], $this->transport);
            $version = $this->root->whm()->server()->version();
            if (preg_match('/\A[0-9]+(?:\.[0-9]+){1,4}\z/', $version) !== 1) {
                throw new RuntimeException('Unusable server version.');
            }
            $this->version = $version;
            $this->phase = 'alias_preflight';
            if ($this->lifecycle->domainOwner(self::ALIAS) !== null || $this->lifecycle->zoneListed(self::ALIAS)) {
                throw new RuntimeException('Requested alias or zone already present.');
            }
            $this->phase = 'limits';
            $this->lifecycle->requireOwnership();
            $this->guard->arm('limits');
            $this->root->whm()->accounts()->modifyAccount(user: self::ACCOUNT, maxpark: 1);
            $this->phase = 'park_alias';
            $this->lifecycle->requireOwnership();
            $this->park($direct);
            $this->phase = 'confirm_zones';
            if ($this->lifecycle->domainOwner(self::ALIAS) !== self::ACCOUNT) {
                throw new RuntimeException('Alias owner unconfirmed.');
            }
            $this->alias['alias_domain']['creation'] = 'confirmed';
            $this->requireDistinctZones();
            $this->alias['alias_zone']['creation'] = 'confirmed';
            $this->lifecycle->requireOwnership();
            $this->aliasSetup = 'passed';
            $this->phase = 'dns_exercise';
            $this->exercise = new DnsZoneExercise(
                $this->runner,
                $this->capture,
                $direct,
                $this->root,
                $version,
                $this->guard,
            );
            $this->exercise->run();
        } catch (Throwable) {
            throw new ExerciseFailure([$this->phase . '_failure']);
        }
        $this->phase = null;
    }

    private function park(Client $direct): void
    {
        $this->guard->arm('park_alias');
        $attempted = ['creation' => 'unconfirmed', 'cleanup' => 'unconfirmed'];
        $this->alias = ['alias_domain' => $attempted, 'alias_zone' => $attempted];
        try {
            $direct->uapi()->park()->park(domain: self::ALIAS);
        } catch (ApiErrorException $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            $this->parkingUnknown = true;
            throw $failure;
        }
    }

    /** One inventory must identify both zone names uniquely and give them different nonempty zone files. */
    private function requireDistinctZones(): void
    {
        $files = [];
        foreach ($this->root->whm()->dns()->listZones()['zone'] ?? [] as $row) {
            $domain = $row['domain'] ?? null;
            if ($domain === self::DOMAIN || $domain === self::ALIAS) {
                $file = $row['zonefile'] ?? '';
                if ($file === '' || isset($files[$domain]) || in_array($file, $files, true)) {
                    throw new RuntimeException('Zone file identities are ambiguous.');
                }
                $files[$domain] = $file;
            }
        }
        if (count($files) !== 2) {
            throw new RuntimeException('Two distinct zones are required.');
        }
    }

    /**
     * Independently read each attempted resource after the marked account was confirmed removed.
     *
     * @return list<string>
     */
    private function proveRemoved(): array
    {
        $failures = [];
        foreach ($this->alias as $name => $resource) {
            if ($resource['creation'] === 'not_attempted') {
                continue;
            }
            try {
                $present = $name === 'alias_domain'
                    ? $this->lifecycle->domainOwner(self::ALIAS) !== null
                    : $this->lifecycle->zoneListed(self::ALIAS);
                $cleanup = $present ? 'present' : ($this->parkingUnknown ? 'unconfirmed' : 'confirmed_absent');
            } catch (Throwable) {
                $cleanup = 'unconfirmed';
            }
            $this->alias[$name]['cleanup'] = $cleanup;
            if ($cleanup !== 'confirmed_absent') {
                $failures[] = $name . '_' . $cleanup;
            }
        }

        return $failures;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    public function __serialize(): never
    {
        throw new LogicException('Disposable DNS fixture state cannot be serialized.');
    }
}
