<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use WeakMap;

/**
 * Fixed identities, one owned mailbox and the exact wire requests of one disposable Webmail session run.
 *
 * The mailbox name comes from one high-entropy per-run value, so it is fresh and never contains the account name.
 * Its password is random, held in memory only, and kept off the recipe itself: it lives in a private map keyed by the
 * recipe, so no JSON encoding, var_export, array cast or dump of a recipe shows it, and a recipe cannot be cloned.
 * Every creator call carries the RFC 5737 documentation address, because the bridge needs an explicit one on the
 * reviewed build. Expected requests are spelled here by hand, independently of the client's encoder, so the guard
 * compares two representations of each request.
 *
 * @phpstan-type Phase 'version'|'sessions_initial_direct'|'sessions_initial_bridged'|'create_mailbox'
 *     |'self_direct'|'mail_user_direct'|'check_password_direct'|'self_bridged'|'mail_user_bridged'
 *     |'check_password_bridged'|'sessions_final_direct'|'sessions_final_bridged'
 * @phpstan-type Creator 'self'|'mail_user'|'check_password'
 * @phpstan-type Route 'whm'|'direct-uapi'|'bridged-uapi'
 */
final class WebmailSessionRecipe
{
    public const ACCOUNT = 'wlsession';

    public const DOMAIN = 'wlsession.example.com';

    public const TOKEN_NAME = 'wlsession-session-fixture';

    /** RFC 5737 TEST-NET-1: an address that belongs to no client, sent on every creator call. */
    public const REMOTE_ADDRESS = '192.0.2.10';

    /** Megabytes; well inside the disposable account's 64 MB quota. */
    public const MAILBOX_QUOTA = 16;

    /** Every phase in execution order. */
    public const PHASES = [
        'version', 'sessions_initial_direct', 'sessions_initial_bridged', 'create_mailbox',
        'self_direct', 'mail_user_direct', 'check_password_direct',
        'self_bridged', 'mail_user_bridged', 'check_password_bridged',
        'sessions_final_direct', 'sessions_final_bridged',
    ];

    /** Each creator's wire function. */
    public const CREATORS = [
        'self' => 'create_webmail_session_for_self',
        'mail_user' => 'create_webmail_session_for_mail_user',
        'check_password' => 'create_webmail_session_for_mail_user_check_password',
    ];

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    private const BRIDGE = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user='
        . self::ACCOUNT;

    /** @var WeakMap<self, string>|null Each recipe's password, kept off the recipe itself. */
    private static ?WeakMap $passwords = null;

    /** The mailbox local part; its address is this at DOMAIN. */
    public readonly string $mailbox;

    /**
     * Bind one 128-bit lowercase hexadecimal run value and a random password to this recipe's own account; no I/O.
     *
     * Sessions and the mailbox need a writable token, and every URI here names one account, so any other binding is
     * refused. The password allows letters, digits and `!` only, so its form encoding is spelled exactly by hand.
     */
    public function __construct(
        #[SensitiveParameter] string $entropy,
        #[SensitiveParameter] string $password,
        public readonly DisposableAccount $account,
    ) {
        if (
            preg_match('/\A[0-9a-f]{32}\z/', $entropy) !== 1 || preg_match('/\A[A-Za-z0-9!]{20,64}\z/', $password) !== 1
            || $account->user !== self::ACCOUNT || $account->domain !== self::DOMAIN
            || $account->tokenName !== self::TOKEN_NAME || $account->readOnlyToken
        ) {
            throw new InvalidArgumentException('Webmail session recipe needs entropy, a password and its own account.');
        }
        $this->mailbox = 'box' . substr($entropy, 0, 16);
        self::$passwords ??= new WeakMap();
        self::$passwords[$this] = $password;
    }

    /** The mailbox password, for the one mailbox creation and the password-checking creators only. */
    public function password(): string
    {
        return self::$passwords[$this] ?? throw new LogicException('Webmail session recipe has no password.');
    }

    /**
     * The exact request of one phase. POST bodies carry the password: compare them, never keep or print them.
     *
     * @return array{method: 'GET'|'POST', uri: string, body: string}
     */
    public function request(string $phase): array
    {
        if (!in_array($phase, self::PHASES, true)) {
            throw new LogicException('Unknown Webmail session phase.');
        }
        if ($phase === 'version') {
            return ['method' => 'GET', 'uri' => 'https://test.oblak.host:2087/json-api/version?api.version=1',
                'body' => ''];
        }
        $direct = self::route($phase) === 'direct-uapi';
        if (str_starts_with($phase, 'sessions_')) {
            return ['method' => 'GET', 'uri' => self::uri($direct, 'ActiveSessions', 'list_active_sessions'),
                'body' => ''];
        }
        $password = strtr($this->password(), ['!' => '%21']);
        if ($phase === 'create_mailbox') {
            return ['method' => 'POST', 'uri' => self::uri(true, 'Email', 'add_pop'),
                'body' => 'email=' . $this->mailbox . '&password=' . $password . '&domain=' . self::DOMAIN
                    . '&quota=' . self::MAILBOX_QUOTA . '&send_welcome_email=0'];
        }
        $creator = self::creator($phase) ?? throw new LogicException('Unknown Webmail session phase.');
        $mailbox = 'domain=' . self::DOMAIN . '&login=' . $this->mailbox;

        return ['method' => 'POST', 'uri' => self::uri($direct, 'Session', self::CREATORS[$creator]),
            'body' => match ($creator) {
                'self' => '',
                'mail_user' => $mailbox . '&',
                'check_password' => $mailbox . '&password=' . $password . '&',
            } . 'remote_address=' . self::REMOTE_ADDRESS];
    }

    /** @return Route */
    public static function route(string $phase): string
    {
        return match (true) {
            $phase === 'version' => 'whm',
            $phase === 'create_mailbox', str_ends_with($phase, '_direct') => 'direct-uapi',
            default => 'bridged-uapi',
        };
    }

    /** @return Creator|null */
    public static function creator(string $phase): ?string
    {
        foreach (array_keys(self::CREATORS) as $creator) {
            if ($phase === $creator . '_direct' || $phase === $creator . '_bridged') {
                return $creator;
            }
        }

        return null;
    }

    private static function uri(bool $direct, string $module, string $function): string
    {
        return $direct ? self::DIRECT . $module . '/' . $function
            : self::BRIDGE . '&cpanel.module=' . $module . '&cpanel.function=' . $function;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** A copy would carry no password, so a recipe is never cloned. */
    private function __clone(): void
    {
    }

    /** The recipe holds the mailbox password and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Webmail session recipe cannot be serialized.');
    }
}
