<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Create one explicitly designated disposable account with a finite 64 MB quota and temporary read-only token.
 *
 * DisposableAccountLifecycle owns identity, ownership, the token and cleanup, and states their rules; this fixture
 * holds only the quota observations and how they are reported. Automatic service domains are outside the
 * get_domain_info inventory.
 *
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-import-type Result from FiniteQuotaEvidence
 * @phpstan-import-type Assessment from FiniteQuotaEvidence
 * @phpstan-import-type UnitRelationship from FiniteQuotaEvidence
 * @phpstan-import-type CleanupOutcome from DisposableAccountLifecycle
 * @phpstan-import-type ResourceOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemOutcome from DisposableAccountLifecycle
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed'|'not_started',
 *     capture: 'passed'|'failed'|'not_run', expectations: 'passed'|'failed'|'not_run',
 *     cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     resources: array{account: ResourceOutcome, token: ResourceOutcome},
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome,
 *     filesystem_cleanup: FilesystemOutcome, filesystem_residue: array<string, int>
 * }
 * @phpstan-type Capture array{
 *     probes: list<Record>,
 *     unit_relationships: array{'direct-uapi': UnitRelationship, 'bridged-uapi': UnitRelationship},
 *     discrepancy_review: Assessment
 * }
 * @phpstan-type Scenario array{
 *     scenario: 'finite-quota-disposable-v2', target_role: 'disposable-test-account', probes: list<Record>,
 *     unit_relationships: array{'direct-uapi': UnitRelationship, 'bridged-uapi': UnitRelationship},
 *     discrepancy_review: Assessment, fixture: Report
 * }
 */
final class FiniteQuotaFixture
{
    /** Token expiry does not replace cleanup. */
    private const TOKEN_LIFETIME = 3600;

    private readonly FiniteQuotaGuard $guard;

    private readonly DisposableAccountLifecycle $lifecycle;

    /** @var Report */
    private array $report;

    /** @var Capture */
    private array $result;

    private bool $used = false;

    /**
     * Bind the fixed disposable account and a token expiration one hour from construction, without I/O.
     *
     * The supplied HTTP client must support form POST, verified TLS and no redirects or retries. The
     * guard requires both live opt-ins and exact account/domain environment values on every request.
     * Without a filesystem proof, nothing is claimed about the server's files.
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] string $whmToken,
        ?FilesystemAbsenceProof $filesystem = null,
    ) {
        $account = new DisposableAccount(
            FiniteQuotaGuard::ACCOUNT,
            FiniteQuotaGuard::DOMAIN,
            FiniteQuotaGuard::TOKEN_NAME,
            'finite-' . bin2hex(random_bytes(16)) . '@' . FiniteQuotaGuard::DOMAIN,
            time() + self::TOKEN_LIFETIME,
            readOnlyToken: true,
        );
        $this->guard = new FiniteQuotaGuard($http, $whmToken, $account->tokenExpiresAt, $account->contactEmail);
        $capture = new CaptureClient($this->guard);
        $factory = new Psr17Factory();
        $client = new Client([
            'host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm',
            'username' => 'root', 'token' => $whmToken,
        ], new HttpTransport($capture, $factory, $factory));
        $this->lifecycle = new DisposableAccountLifecycle($account, $capture, $client, $filesystem);
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
        $this->report = self::report();
        $this->result = [
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
            $this->report['failures'][] = 'configuration_failure';

            return $this->scenario();
        }
        $this->used = true;
        $this->report['setup'] = 'failed';
        $outcome = $this->lifecycle->run(function (#[SensitiveParameter] string $token) use ($capture): void {
            $this->capture($capture, $token);
        });
        $this->report['cleanup'] = $outcome['cleanup'];
        $this->report['failures'] = array_values(array_unique([...$this->report['failures'], ...$outcome['failures']]));
        $this->report['resources'] = ['account' => $outcome['account'], 'token' => $outcome['token']];
        $this->report['domain_cleanup'] = $outcome['domain_cleanup'];
        $this->report['zone_cleanup'] = $outcome['zone_cleanup'];
        $this->report['filesystem_cleanup'] = $outcome['filesystem_cleanup'];
        $this->report['filesystem_residue'] = $outcome['filesystem_residue'];
        if (
            $this->report['failures'] === [] && $this->report['expectations'] === 'passed'
            && $this->report['cleanup'] === 'passed'
        ) {
            $this->report['status'] = 'passed';
        }

        return $this->scenario();
    }

    /**
     * Bind the token, then record the quota observations; a failure here still reaches the lifecycle's cleanup.
     *
     * @param Closure(ClientInterface, string): Result $capture
     */
    private function capture(#[SensitiveParameter] Closure $capture, #[SensitiveParameter] string $token): void
    {
        try {
            $this->guard->bindToken($token);
            $this->report['setup'] = 'passed';
            $this->report['capture'] = 'failed';
            $result = $capture($this->guard, $token);
            $this->result = [
                'probes' => $result['probes'], 'unit_relationships' => $result['unit_relationships'],
                'discrepancy_review' => $result['discrepancy_review'],
            ];
            $this->report['capture'] = 'passed';
            $this->report['expectations'] = $result['expectations'];
            if ($result['expectations'] !== 'passed') {
                $this->report['failures'][] = 'finite_quota_expectation_failure';
            }
        } catch (Throwable) {
            $this->report['failures'][] = $this->report['setup'] === 'passed' ? 'capture_failure' : 'setup_failure';
        }
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
            'filesystem_cleanup' => 'not_claimed', 'filesystem_residue' => [],
        ];
    }

    /** @return Scenario */
    private function scenario(): array
    {
        return [
            'scenario' => 'finite-quota-disposable-v2', 'target_role' => 'disposable-test-account',
            'probes' => $this->result['probes'], 'unit_relationships' => $this->result['unit_relationships'],
            'discrepancy_review' => $this->result['discrepancy_review'], 'fixture' => $this->report,
        ];
    }
}
