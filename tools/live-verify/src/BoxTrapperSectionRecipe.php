<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\BoxTrapperSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\EmailSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use LogicException;
use SensitiveParameter;

/**
 * Fixed two-mailbox recipe: no enable operation, message action, template write or forwarding.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Step array{probe: Probe, method: string, body: string}
 */
final readonly class BoxTrapperSectionRecipe
{
    private const PREFLIGHT = ['initial_mailboxes' => 'list_pops', 'mail_domain' => 'list_mail_domains'];

    private const PHASES = [
        'ready_mailboxes' => 'list_pops', 'create_mailbox' => 'add_pop', 'created_mailboxes' => 'list_pops',
        'initial_status' => 'get_status', 'disable' => 'set_status',
        'configure' => 'save_configuration', 'configuration' => 'get_configuration',
        'set_allowlist' => 'set_allowlist', 'allowlist' => 'get_allowlist',
        'set_blocklist' => 'set_blocklist', 'blocklist' => 'get_blocklist',
        'set_ignorelist' => 'set_ignorelist', 'ignorelist' => 'get_ignorelist',
        'templates' => 'list_email_templates', 'template' => 'get_email_template',
        'log' => 'get_log', 'queue' => 'list_queued_messages', 'final_status' => 'get_status',
        'cleanup_mailbox' => 'delete_pop', 'final_mailboxes' => 'list_pops',
    ];

    public function __construct(
        #[SensitiveParameter] private string $suffix,
        #[SensitiveParameter] private string $password,
    ) {
        if (preg_match('/\A[a-f0-9]{6}\z/', $suffix) !== 1 || $password === '') {
            throw new LogicException('Invalid BoxTrapper fixture identity.');
        }
    }

    /** @param Route $route */
    public function address(string $route): string
    {
        return 'wbt' . $this->suffix . ($route === 'direct-uapi' ? 'd' : 'b') . '@npr.rs';
    }

    /** @return list<string> */
    public function candidates(): array
    {
        return [$this->address('direct-uapi'), $this->address('bridged-uapi')];
    }

    /** @param Route $route */
    public function rule(string $route): string
    {
        return 'from ' . str_replace(['@', '.'], ['\@', '\.'], $this->address($route));
    }

    /** @return array<string, string> */
    public static function preflight(): array
    {
        return self::PREFLIGHT;
    }

    /** @return array<string, string> */
    public static function phases(): array
    {
        return self::PHASES;
    }

    public static function operation(string $function): string
    {
        return $function === 'version' ? 'whm1:version' : 'uapi:'
            . (in_array($function, ['list_pops', 'list_mail_domains', 'add_pop', 'delete_pop'], true)
                ? 'Email/' : 'BoxTrapper/') . $function;
    }

    /** @return array<mixed> */
    public static function spec(string $function): array
    {
        return match ($function) {
            'version' => ServerSpec::VERSION,
            'list_pops' => EmailSpec::LIST_POPS,
            'list_mail_domains' => EmailSpec::LIST_MAIL_DOMAINS,
            'add_pop' => EmailSpec::ADD_POP,
            'delete_pop' => EmailSpec::DELETE_POP,
            'get_status' => BoxTrapperSpec::GET_STATUS,
            'set_status' => BoxTrapperSpec::SET_STATUS,
            'save_configuration' => BoxTrapperSpec::SAVE_CONFIGURATION,
            'get_configuration' => BoxTrapperSpec::GET_CONFIGURATION,
            'set_allowlist' => BoxTrapperSpec::SET_ALLOWLIST,
            'get_allowlist' => BoxTrapperSpec::GET_ALLOWLIST,
            'set_blocklist' => BoxTrapperSpec::SET_BLOCKLIST,
            'get_blocklist' => BoxTrapperSpec::GET_BLOCKLIST,
            'set_ignorelist' => BoxTrapperSpec::SET_IGNORELIST,
            'get_ignorelist' => BoxTrapperSpec::GET_IGNORELIST,
            'list_email_templates' => BoxTrapperSpec::LIST_EMAIL_TEMPLATES,
            'get_email_template' => BoxTrapperSpec::GET_EMAIL_TEMPLATE,
            'get_log' => BoxTrapperSpec::GET_LOG,
            'list_queued_messages' => BoxTrapperSpec::LIST_QUEUED_MESSAGES,
            default => throw new LogicException('Unknown BoxTrapper fixture operation.'),
        };
    }

    /**
     * Exact wire plan is independent of generated argument encoding; generated methods execute the requests.
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
            ), 'method' => 'GET', 'body' => ''];
        }
        $function = self::PREFLIGHT[$phase] ?? self::PHASES[$phase] ?? null;
        if ($route === 'whm' || $function === null) {
            throw new LogicException('Unknown BoxTrapper fixture phase.');
        }
        $address = $this->address($route);
        $local = explode('@', $address)[0];
        $password = $this->password;
        $rule = $this->rule($route);
        $module = str_contains(self::operation($function), 'Email/') ? 'Email' : 'BoxTrapper';
        $parameters = match ($function) {
            'list_pops' => ['skip_main' => 1],
            'list_mail_domains' => [],
            'add_pop' => ['email' => $local, 'password' => $password, 'domain' => 'npr.rs',
                'quota' => 32, 'send_welcome_email' => 0],
            'delete_pop' => ['email' => $local, 'domain' => 'npr.rs'],
            'list_email_templates' => [],
            'set_status' => ['email' => $address, 'enabled' => 0],
            'save_configuration' => ['email' => $address, 'enable_auto_whitelist' => 0,
                'from_addresses' => $address, 'queue_days' => 1, 'whitelist_by_association' => 0,
                'from_name' => 'BoxTrapper fixture', 'spam_score' => 2.5],
            'set_allowlist', 'set_blocklist', 'set_ignorelist' => ['email' => $address, 'rules' => $rule],
            'get_email_template' => ['email' => $address, 'template' => 'verify'],
            default => ['email' => $address],
        };
        $method = in_array($function, [
            'add_pop', 'delete_pop', 'set_status', 'save_configuration',
            'set_allowlist', 'set_blocklist', 'set_ignorelist',
        ], true) ? 'POST' : 'GET';
        $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $uri = $route === 'direct-uapi'
            ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $function;
        if ($method === 'GET' && $encoded !== '') {
            $uri .= ($route === 'direct-uapi' ? '?' : '&') . $encoded;
        }
        $invoke = static function (#[SensitiveParameter] Client $client) use (
            $function,
            $address,
            $local,
            $password,
            $rule,
        ): mixed {
            $account = $client->uapi('npr');
            $email = $account->email();
            $box = $account->boxTrapper();

            return match ($function) {
                'list_pops' => $email->listPops(skipMain: true),
                'list_mail_domains' => $email->listMailDomains(),
                'add_pop' => $email->addPop($local, $password, domain: 'npr.rs', quota: 32, sendWelcomeEmail: false),
                'delete_pop' => $email->deletePop($local, domain: 'npr.rs'),
                'get_status' => $box->getStatus($address),
                'set_status' => $box->setStatus($address, false),
                'save_configuration' => $box->saveConfiguration(
                    $address,
                    false,
                    $address,
                    1,
                    false,
                    'BoxTrapper fixture',
                    2.5,
                ),
                'get_configuration' => $box->getConfiguration($address),
                'set_allowlist' => $box->setAllowlist($address, $rule),
                'get_allowlist' => $box->getAllowlist($address),
                'set_blocklist' => $box->setBlocklist($address, $rule),
                'get_blocklist' => $box->getBlocklist($address),
                'set_ignorelist' => $box->setIgnorelist($address, $rule),
                'get_ignorelist' => $box->getIgnorelist($address),
                'list_email_templates' => $box->listEmailTemplates(),
                'get_email_template' => $box->getEmailTemplate($address, 'verify'),
                'get_log' => $box->getLog($address),
                'list_queued_messages' => $box->listQueuedMessages($address),
            };
        };

        return ['probe' => new Probe(self::operation($function), $route, self::spec($function), $uri, $invoke),
            'method' => $method, 'body' => $method === 'POST' ? $encoded : ''];
    }
}
