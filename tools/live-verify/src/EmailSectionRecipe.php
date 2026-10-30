<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\EmailSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use LogicException;
use SensitiveParameter;

/**
 * Exact npr mailbox recipe; no outbound-mail operation or caller-selected target is accepted.
 *
 * Quota creation and changes use MB; unlimited readback checks MB and byte units. Local traces
 * inspect only run-bound recipients and send no message. Welcome mail
 * is explicitly disabled. Each route has a distinct new address and an in-memory random password.
 * The fixture owns state and cleanup; the guard independently matches this fixed wire plan.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Step array{probe: Probe, method: string, body: string}
 */
final readonly class EmailSectionRecipe
{
    private const PREFLIGHT = [
        'initial_mailboxes' => 'list_pops', 'count_pops' => 'count_pops',
        'list_mail_domains' => 'list_mail_domains', 'list_domain_forwarders' => 'list_domain_forwarders',
        'list_forwarders' => 'list_forwarders', 'list_mxs' => 'list_mxs',
        'get_default_email_quota' => 'get_default_email_quota', 'get_max_email_quota' => 'get_max_email_quota',
    ];

    private const PHASES = [
        'ready_mailboxes' => 'list_pops', 'create_mailbox' => 'add_pop', 'created_mailboxes' => 'list_pops',
        'initial_quota' => 'get_pop_quota', 'change_password' => 'passwd_pop',
        'verify_password' => 'verify_password', 'change_quota' => 'edit_pop_quota',
        'changed_quota' => 'get_pop_quota', 'make_unlimited' => 'edit_pop_quota',
        'unlimited_quota' => 'get_pop_quota', 'unlimited_bytes' => 'get_pop_quota',
        'trace_mailbox' => 'trace_delivery', 'trace_missing' => 'trace_delivery',
        'suspend_login' => 'suspend_login',
        'suspended_mailboxes' => 'list_pops', 'unsuspend_login' => 'unsuspend_login',
        'resumed_mailboxes' => 'list_pops', 'cleanup_mailbox' => 'delete_pop', 'final_mailboxes' => 'list_pops',
    ];

    /** @var array<Route, string> */
    private array $addresses;

    public function __construct(
        #[SensitiveParameter] string $suffix,
        #[SensitiveParameter] private string $password,
    ) {
        if (preg_match('/\A[a-f0-9]{6}\z/', $suffix) !== 1 || $password === '') {
            throw new LogicException('Invalid Email fixture identity.');
        }
        $this->addresses = [
            'direct-uapi' => 'wem' . $suffix . 'd@npr.rs',
            'bridged-uapi' => 'wem' . $suffix . 'b@npr.rs',
        ];
    }

    /** @param Route $route */
    public function address(string $route): string
    {
        return $this->addresses[$route];
    }

    /** @return list<string> Exact run identities; never serialized into evidence. */
    public function candidates(): array
    {
        return array_values($this->addresses);
    }

    /** @return array<string, string> Both routes complete all preflight reads before creating either mailbox. */
    public static function preflight(): array
    {
        return self::PREFLIGHT;
    }

    /** @return array<string, string> Ordered route-local lifecycle; cleanup also runs after earlier failures. */
    public static function phases(): array
    {
        return self::PHASES;
    }

    /** @return array<mixed> Effective specs are used only for evidence attribution and structural comparison. */
    public static function spec(string $function): array
    {
        return match ($function) {
            'version' => ServerSpec::VERSION,
            'list_pops' => EmailSpec::LIST_POPS,
            'count_pops' => EmailSpec::COUNT_POPS,
            'list_mail_domains' => EmailSpec::LIST_MAIL_DOMAINS,
            'list_domain_forwarders' => EmailSpec::LIST_DOMAIN_FORWARDERS,
            'list_forwarders' => EmailSpec::LIST_FORWARDERS,
            'list_mxs' => EmailSpec::LIST_MXS,
            'get_default_email_quota' => EmailSpec::GET_DEFAULT_EMAIL_QUOTA,
            'get_max_email_quota' => EmailSpec::GET_MAX_EMAIL_QUOTA,
            'add_pop' => EmailSpec::ADD_POP,
            'get_pop_quota' => EmailSpec::GET_POP_QUOTA,
            'passwd_pop' => EmailSpec::PASSWD_POP,
            'verify_password' => EmailSpec::VERIFY_PASSWORD,
            'edit_pop_quota' => EmailSpec::EDIT_POP_QUOTA,
            'suspend_login' => EmailSpec::SUSPEND_LOGIN,
            'unsuspend_login' => EmailSpec::UNSUSPEND_LOGIN,
            'delete_pop' => EmailSpec::DELETE_POP,
            'trace_delivery' => EmailSpec::TRACE_DELIVERY,
            default => throw new LogicException('Unknown Email fixture operation.'),
        };
    }

    /** @param 'whm'|Route $route
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
            ), 'method' => 'GET', 'body' => ''];
        }
        $function = self::PREFLIGHT[$phase] ?? self::PHASES[$phase] ?? null;
        if ($route === 'whm' || $function === null) {
            throw new LogicException('Unknown Email fixture phase.');
        }
        $address = $this->addresses[$route];
        $local = explode('@', $address, 2)[0];
        $password = $this->password;
        $changedPassword = $password . 'Changed!9';
        $asBytes = $phase === 'unlimited_bytes';
        $quotaChange = $phase === 'make_unlimited' ? '0' : '64';
        $recipient = $phase === 'trace_missing' ? str_replace('@', '-missing@', $address) : $address;
        $parameters = match ($function) {
            'list_pops' => ['skip_main' => 1],
            'list_domain_forwarders', 'list_forwarders', 'list_mxs' => ['domain' => 'npr.rs'],
            'add_pop' => ['email' => $local, 'password' => $password, 'domain' => 'npr.rs',
                'quota' => 32, 'send_welcome_email' => 0],
            'get_pop_quota' => ['email' => $local, 'as_bytes' => $asBytes ? 1 : 0, 'domain' => 'npr.rs'],
            'passwd_pop' => ['email' => $local, 'password' => $changedPassword, 'domain' => 'npr.rs'],
            'verify_password' => ['email' => $address, 'password' => $changedPassword],
            'edit_pop_quota' => ['domain' => 'npr.rs', 'email' => $local, 'quota' => $quotaChange],
            'suspend_login', 'unsuspend_login' => ['email' => $address],
            'delete_pop' => ['email' => $local, 'domain' => 'npr.rs'],
            'trace_delivery' => ['recipient' => $recipient],
            default => [],
        };
        $invoke = static function (#[SensitiveParameter] Client $client) use (
            $function,
            $address,
            $local,
            $password,
            $changedPassword,
            $asBytes,
            $quotaChange,
            $recipient,
        ): mixed {
            $email = $client->uapi('npr')->email();

            return match ($function) {
                'list_pops' => $email->listPops(skipMain: true),
                'count_pops' => $email->countPops(),
                'list_mail_domains' => $email->listMailDomains(),
                'list_domain_forwarders' => $email->listDomainForwarders(domain: 'npr.rs'),
                'list_forwarders' => $email->listForwarders(domain: 'npr.rs'),
                'list_mxs' => $email->listMxs(domain: 'npr.rs'),
                'get_default_email_quota' => $email->getDefaultEmailQuota(),
                'get_max_email_quota' => $email->getMaxEmailQuota(),
                'add_pop' => $email->addPop(email: $local, password: $password, domain: 'npr.rs',
                    quota: 32, sendWelcomeEmail: false),
                'get_pop_quota' => $email->getPopQuota(email: $local, asBytes: $asBytes, domain: 'npr.rs'),
                'passwd_pop' => $email->passwdPop(email: $local, password: $changedPassword, domain: 'npr.rs'),
                'verify_password' => $email->verifyPassword(email: $address, password: $changedPassword),
                'edit_pop_quota' => $email->editPopQuota(domain: 'npr.rs', email: $local, quota: $quotaChange),
                'suspend_login' => $email->suspendLogin(email: $address),
                'unsuspend_login' => $email->unsuspendLogin(email: $address),
                'delete_pop' => $email->deletePop(email: $local, domain: 'npr.rs'),
                'trace_delivery' => $email->traceDelivery(recipient: $recipient),
            };
        };
        $method = in_array($function, ['add_pop', 'passwd_pop', 'verify_password', 'edit_pop_quota',
            'suspend_login', 'unsuspend_login', 'delete_pop'], true) ? 'POST' : 'GET';
        $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $uri = $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/Email/' . $function
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Email&cpanel.function=' . $function;
        if ($method === 'GET' && $encoded !== '') {
            $uri .= ($route === 'direct-uapi' ? '?' : '&') . $encoded;
        }

        return [
            'probe' => new Probe('uapi:Email/' . $function, $route, self::spec($function), $uri, $invoke),
            'method' => $method, 'body' => $method === 'POST' ? $encoded : '',
        ];
    }
}
