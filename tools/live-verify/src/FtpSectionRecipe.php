<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\FtpSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use LogicException;
use SensitiveParameter;

/**
 * Fixed FTP recipe on npr/npr.rs with independent per-route identities and memory-only credentials.
 *
 * Both directory spellings identify the existing account home. Cleanup always preserves that directory.
 * The request method and parameter expectations are independent of generated operation specifications.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Step array{probe: Probe, method: string, body: string}
 */
final readonly class FtpSectionRecipe
{
    private const PHASES = [
        'initial_accounts' => 'list_ftp', 'daemon' => 'get_ftp_daemon_info', 'server' => 'server_name',
        'anonymous' => 'allows_anonymous_ftp', 'anonymous_incoming' => 'allows_anonymous_ftp_incoming',
        'port' => 'get_port', 'welcome' => 'get_welcome_message', 'sessions' => 'list_sessions',
        'create_account' => 'add_ftp', 'created_accounts' => 'list_ftp', 'exists' => 'ftp_exists',
        'initial_quota' => 'get_quota', 'change_password' => 'passwd', 'set_home' => 'set_homedir',
        'home_accounts' => 'list_ftp', 'set_quota' => 'set_quota', 'finite_quota' => 'get_quota',
        'disk_accounts' => 'list_ftp_with_disk', 'cleanup_account' => 'delete_ftp', 'final_accounts' => 'list_ftp',
    ];

    /** @var array<Route, string> */
    private array $users;

    /** @param array<Route, string> $suffixes */
    public function __construct(
        #[SensitiveParameter] array $suffixes,
        #[SensitiveParameter] private string $password,
    ) {
        if ($password === '' || $suffixes['direct-uapi'] === $suffixes['bridged-uapi']) {
            throw new LogicException('Invalid FTP fixture identity.');
        }
        $users = [];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            if (preg_match('/\A[a-f0-9]{16}\z/', $suffixes[$route]) !== 1) {
                throw new LogicException('Invalid FTP fixture identity.');
            }
            $users[$route] = 'wftp' . $suffixes[$route];
        }
        $this->users = $users;
    }

    /** @param Route $route */
    public function user(string $route): string
    {
        return $this->users[$route];
    }

    /** @return array<string, string> Ordered normal-run phases. */
    public static function phases(): array
    {
        return self::PHASES;
    }

    /** @return array<mixed> Effective generated contract, never a fixture-authored schema. */
    public static function spec(string $function): array
    {
        return match ($function) {
            'version' => ServerSpec::VERSION,
            'list_ftp' => FtpSpec::LIST_FTP,
            'list_ftp_with_disk' => FtpSpec::LIST_FTP_WITH_DISK,
            'get_ftp_daemon_info' => FtpSpec::GET_FTP_DAEMON_INFO,
            'server_name' => FtpSpec::SERVER_NAME,
            'allows_anonymous_ftp' => FtpSpec::ALLOWS_ANONYMOUS_FTP,
            'allows_anonymous_ftp_incoming' => FtpSpec::ALLOWS_ANONYMOUS_FTP_INCOMING,
            'get_port' => FtpSpec::GET_PORT,
            'get_welcome_message' => FtpSpec::GET_WELCOME_MESSAGE,
            'list_sessions' => FtpSpec::LIST_SESSIONS,
            'add_ftp' => FtpSpec::ADD_FTP,
            'ftp_exists' => FtpSpec::FTP_EXISTS,
            'get_quota' => FtpSpec::GET_QUOTA,
            'passwd' => FtpSpec::PASSWD,
            'set_homedir' => FtpSpec::SET_HOMEDIR,
            'set_quota' => FtpSpec::SET_QUOTA,
            'delete_ftp' => FtpSpec::DELETE_FTP,
            default => throw new LogicException('Unreviewed FTP operation.'),
        };
    }

    /**
     * Bind exact method, URI, body and generated invocation for one phase. Construction performs no I/O.
     *
     * @param 'whm'|Route $route
     * @return Step
     */
    public function step(string $phase, string $route): array
    {
        if ($phase === 'version' && $route === 'whm') {
            return ['probe' => new Probe(
                'whm1:version',
                'whm',
                self::spec('version'),
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (#[SensitiveParameter] Client $client): mixed => $client->whm()->server()->version(),
            ),
                'method' => 'GET', 'body' => ''];
        }
        if ($route === 'whm' || !isset(self::PHASES[$phase])) {
            throw new LogicException('Unreviewed FTP phase.');
        }
        $function = self::PHASES[$phase];
        $user = $this->users[$route];
        $password = $this->password;
        $method = in_array($function, ['add_ftp', 'passwd', 'set_homedir', 'set_quota', 'delete_ftp'], true)
            ? 'POST' : 'GET';
        $parameters = match ($function) {
            'list_ftp', 'list_ftp_with_disk' => ['include_acct_types' => 'sub'],
            'add_ftp' => ['user' => $user, 'pass' => $password, 'domain' => 'npr.rs',
                'homedir' => '.', 'quota' => 0, 'disallowdot' => 1],
            'passwd' => ['user' => $user, 'pass' => $password . 'Changed!9', 'domain' => 'npr.rs'],
            'set_homedir' => ['user' => $user, 'domain' => 'npr.rs', 'homedir' => './'],
            'set_quota' => ['user' => $user, 'domain' => 'npr.rs', 'kill' => 0, 'quota' => 17],
            'delete_ftp' => ['user' => $user, 'destroy' => 0, 'domain' => 'npr.rs'],
            'ftp_exists' => ['user' => $user, 'domain' => 'npr.rs'],
            'get_quota' => ['account' => $user, 'domain' => 'npr.rs'],
            default => [],
        };
        $invoke = static function (#[SensitiveParameter] Client $client) use ($function, $user, $password): mixed {
            $ftp = $client->uapi('npr')->ftp();

            return match ($function) {
                'list_ftp' => $ftp->listFtp(includeAcctTypes: 'sub'),
                'list_ftp_with_disk' => $ftp->listFtpWithDisk(includeAcctTypes: 'sub'),
                'get_ftp_daemon_info' => $ftp->getFtpDaemonInfo(),
                'server_name' => $ftp->serverName(),
                'allows_anonymous_ftp' => $ftp->allowsAnonymousFtp(),
                'allows_anonymous_ftp_incoming' => $ftp->allowsAnonymousFtpIncoming(),
                'get_port' => $ftp->getPort(),
                'get_welcome_message' => $ftp->getWelcomeMessage(),
                'list_sessions' => $ftp->listSessions(),
                'add_ftp' => $ftp->addFtp(user: $user, password: $password, domain: 'npr.rs',
                    homedir: '.', quota: 0, disallowdot: true),
                'ftp_exists' => $ftp->ftpExists(user: $user, domain: 'npr.rs'),
                'get_quota' => $ftp->getQuota(account: $user, domain: 'npr.rs'),
                'passwd' => $ftp->changePassword(user: $user, password: $password . 'Changed!9', domain: 'npr.rs'),
                'set_homedir' => $ftp->setHomedir(user: $user, domain: 'npr.rs', homedir: './'),
                'set_quota' => $ftp->setQuota(user: $user, domain: 'npr.rs', kill: false, quota: 17),
                'delete_ftp' => $ftp->deleteFtp(user: $user, destroy: false, domain: 'npr.rs'),
            };
        };
        $uri = $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/Ftp/' . $function
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Ftp&cpanel.function=' . $function;
        $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        if ($method === 'GET' && $encoded !== '') {
            $uri .= ($route === 'direct-uapi' ? '?' : '&') . $encoded;
        }

        return ['probe' => new Probe('uapi:Ftp/' . $function, $route, self::spec($function), $uri, $invoke),
            'method' => $method, 'body' => $method === 'POST' ? $encoded : ''];
    }
    /** Keep passwords, raw captures and resource identities out of ordinary diagnostic dumps.
     * @return array{}
     */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Fixture state contains credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('FTP section state cannot be serialized.');
    }
}
