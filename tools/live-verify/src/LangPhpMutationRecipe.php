<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * The fixed LangPHP mutation plan for one disposable account: every request spelled out, plus the content oracle.
 *
 * Each read URI and mutation body is spelled here independently of the client's encoder, so the guard compares two
 * representations of every request. Content texts carry 128 bits of run entropy and stay in memory; nothing here is
 * ever persisted.
 *
 * The content oracle follows the cPanel 11.138.0.19 LangPHP handler and its INI module as reviewed for this
 * recipe: the setter HTML-entity-decodes the supplied text before writing it, a write prepends the server's own
 * comment block unless the text already holds it, and the getter HTML-entity-encodes the stored file. The classifier
 * does not depend on that block's wording: it accepts only comment and blank lines in front of the sent content. Each
 * content text holds `+`, `%41`, a quoted word and `&amp;`, so a second URL decode, a missing entity decode on write
 * and a missing encode on read each produce a different reply.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type ContentLabel 'direct'|'bridged'|'host-direct'|'host-bridged'
 * @phpstan-type ContentClass 'verbatim'|'entity_model'|'verbatim_after_server_comments'
 *     |'entity_model_after_server_comments'|'other'|'absent'
 * @phpstan-type ContentFinding array{class: ContentClass, server_comment_lines: int, server_blank_lines: int}
 */
final readonly class LangPhpMutationRecipe
{
    public const ACCOUNT = 'wlphpset';

    public const DOMAIN = 'wlphpset.example.com';

    public const TOKEN_NAME = 'wlphpset-php-fixture';

    /** Directive keys this recipe changes or watches; all three are basic integer directives in the reviewed source. */
    public const DIRECTIVE_KEYS = ['max_execution_time', 'max_input_time', 'max_input_vars'];

    /** The directive values each route writes: direct one scalar, bridged a numbered pair. */
    public const DIRECTIVES = [
        'direct-uapi' => ['max_execution_time' => '37'],
        'bridged-uapi' => ['max_execution_time' => '41', 'max_input_time' => '43'],
    ];

    /** Each content text sets its own max_input_vars, so the directive getter can confirm which text is in effect. */
    public const CONTENT_INPUT_VARS = [
        'direct' => '1357', 'bridged' => '2468', 'host-direct' => '3579', 'host-bridged' => '4680',
    ];

    /** @var list<ContentLabel> */
    public const CONTENT_LABELS = ['direct', 'bridged', 'host-direct', 'host-bridged'];

    /** Every mutation phase, with its function and route; each is sent at most once. */
    public const MUTATIONS = [
        'set_directives_direct' => ['php_ini_set_user_basic_directives', 'direct-uapi'],
        'set_directives_bridged' => ['php_ini_set_user_basic_directives', 'bridged-uapi'],
        'set_content_direct' => ['php_ini_set_user_content', 'direct-uapi'],
        'set_content_bridged' => ['php_ini_set_user_content', 'bridged-uapi'],
        'set_content_host_direct' => ['php_ini_set_user_content', 'direct-uapi'],
        'set_content_host_bridged' => ['php_ini_set_user_content', 'bridged-uapi'],
        'set_version_direct' => ['php_set_vhost_versions', 'direct-uapi'],
        'set_version_bridged' => ['php_set_vhost_versions', 'bridged-uapi'],
    ];

    /** A PHP package name as the installed-versions read reports it; nothing that could add a form field. */
    public const VERSION_PATTERN = '/\A[a-z0-9][a-z0-9._-]{0,63}\z/';

    private const DIRECT = 'https://test.oblak.host:2083/execute/LangPHP/';

    private const BRIDGE = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wlphpset'
        . '&cpanel.module=LangPHP&cpanel.function=';

    /** Bind 128 bits of run entropy and this recipe's own writable account; performs no I/O. */
    public function __construct(#[SensitiveParameter] private string $entropy, public DisposableAccount $account)
    {
        if (
            preg_match('/\A[0-9a-f]{32}\z/', $entropy) !== 1 || $account->user !== self::ACCOUNT
            || $account->domain !== self::DOMAIN || $account->tokenName !== self::TOKEN_NAME || $account->readOnlyToken
        ) {
            throw new InvalidArgumentException('LangPHP mutation recipe needs 128-bit entropy and its own account.');
        }
    }

    /**
     * One read on one route, with its fixed query.
     *
     * @param Route $route
     */
    public static function readUri(string $function, string $route, string $query = ''): string
    {
        return $route === 'direct-uapi'
            ? self::DIRECT . $function . ($query === '' ? '' : '?' . $query)
            : self::BRIDGE . $function . ($query === '' ? '' : '&' . $query);
    }

    /**
     * Every LangPHP read this recipe may send.
     *
     * @return list<string>
     */
    public static function readUris(): array
    {
        $uris = [];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            $uris[] = self::readUri('php_get_installed_versions', $route);
            $uris[] = self::readUri('php_get_vhost_versions', $route);
            $uris[] = self::readUri('php_get_vhost_versions', $route, 'vhost=' . self::DOMAIN);
            $uris[] = self::readUri('php_ini_get_user_basic_directives', $route, 'type=home');
            $uris[] = self::readUri('php_ini_get_user_content', $route, 'type=home');
        }

        return $uris;
    }

    /**
     * A mutation's URI: routing stays in the query, every operation field goes in the form body.
     *
     * @param Route $route
     */
    public static function mutationUri(string $function, string $route): string
    {
        return self::readUri($function, $route);
    }

    /**
     * The typed client argument for a route's directive write.
     *
     * @param Route $route
     * @return string|list<string>
     */
    public static function directiveArgument(string $route): string|array
    {
        $values = [];
        foreach (self::DIRECTIVES[$route] as $key => $value) {
            $values[] = $key . ':' . $value;
        }

        return count($values) === 1 ? $values[0] : $values;
    }

    /**
     * A route's directive body: the bare field first, then directive-1 onwards, then the home scope.
     *
     * @param Route $route
     */
    public static function directiveBody(string $route): string
    {
        $fields = [];
        $index = 0;
        foreach (self::DIRECTIVES[$route] as $key => $value) {
            $fields[] = 'directive' . ($index === 0 ? '' : '-' . $index) . '=' . rawurlencode($key . ':' . $value);
            ++$index;
        }

        return implode('&', $fields) . '&type=home';
    }

    /**
     * The INI text one content write sends; it holds run entropy and stays in memory.
     *
     * @param ContentLabel $label
     */
    public function contentText(string $label): string
    {
        return '[PHP]' . "\n" . '; wlphpset ' . $label . ' ' . $this->entropy . ' plus+sign %41 "quoted" &amp;' . "\n"
            . 'max_input_vars = ' . self::CONTENT_INPUT_VARS[$label] . "\n";
    }

    /**
     * What the reviewed handler returns for this text, without the server's comment block: `&amp;` is decoded on write,
     * and the quotes and the decoded ampersand are encoded again on read.
     *
     * @param ContentLabel $label
     */
    public function entityModel(string $label): string
    {
        return '[PHP]' . "\n" . '; wlphpset ' . $label . ' ' . $this->entropy
            . ' plus+sign %41 &quot;quoted&quot; &amp;' . "\n"
            . 'max_input_vars = ' . self::CONTENT_INPUT_VARS[$label] . "\n";
    }

    /**
     * The INI type a content write sends: home, except for the two attempts with the pinned enum's `host`.
     *
     * @param ContentLabel $label
     * @return 'home'|'host'
     */
    public static function contentType(string $label): string
    {
        return str_starts_with($label, 'host-') ? 'host' : 'home';
    }

    /** @param ContentLabel $label */
    public function contentBody(string $label): string
    {
        return 'content=' . rawurlencode($this->contentText($label)) . '&type=' . self::contentType($label);
    }

    /** A version write's body for this recipe's one domain. */
    public static function versionBody(string $version): string
    {
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new InvalidArgumentException('LangPHP mutation version is not a safe package name.');
        }

        return 'version=' . rawurlencode($version) . '&vhost=' . rawurlencode(self::DOMAIN);
    }

    /**
     * The exact request one mutation phase must send.
     *
     * @return array{uri: string, body: string}
     */
    public function mutation(string $phase, ?string $version = null): array
    {
        [$function, $route] = self::MUTATIONS[$phase] ?? throw new LogicException('Unknown LangPHP mutation phase.');
        $versioned = $function === 'php_set_vhost_versions';
        if ($versioned !== ($version !== null)) {
            throw new LogicException('LangPHP mutation phase and version do not match.');
        }
        $body = match (true) {
            $versioned => self::versionBody((string) $version),
            $function === 'php_ini_set_user_basic_directives' => self::directiveBody($route),
            default => $this->contentBody(self::contentLabel($phase)),
        };

        return ['uri' => self::mutationUri($function, $route), 'body' => $body];
    }

    /**
     * The content label a content-write phase sends.
     *
     * @return ContentLabel
     */
    public static function contentLabel(string $phase): string
    {
        return match ($phase) {
            'set_content_direct' => 'direct',
            'set_content_bridged' => 'bridged',
            'set_content_host_direct' => 'host-direct',
            'set_content_host_bridged' => 'host-bridged',
            default => throw new LogicException('Not a LangPHP content phase.'),
        };
    }

    /**
     * Classify a content reply against the text this label sent; only counts and a fixed class leave memory.
     *
     * The classes are checked in order: the exact text, the reviewed entity model, and either of those after lines the
     * server put in front. Only comment and blank lines may stand there, and the reply must end with the whole text.
     *
     * @param ContentLabel $label
     * @return ContentFinding
     */
    public function classifyContent(string $label, #[SensitiveParameter] mixed $returned): array
    {
        $finding = ['class' => 'absent', 'server_comment_lines' => 0, 'server_blank_lines' => 0];
        if (!is_string($returned)) {
            return $finding;
        }
        $candidates = [
            'verbatim' => $this->contentText($label),
            'entity_model' => $this->entityModel($label),
        ];
        foreach ($candidates as $class => $expected) {
            if ($returned === $expected) {
                return ['class' => $class, 'server_comment_lines' => 0, 'server_blank_lines' => 0];
            }
        }
        foreach ($candidates as $class => $expected) {
            if (!str_ends_with($returned, $expected)) {
                continue;
            }
            $prefix = self::prefixLines(substr($returned, 0, -strlen($expected)));
            if ($prefix !== null) {
                return ['class' => $class . '_after_server_comments', ...$prefix];
            }
        }

        return ['class' => 'other', 'server_comment_lines' => 0, 'server_blank_lines' => 0];
    }

    /**
     * Count the comment and blank lines in a prefix, or null when anything else is there.
     *
     * @return array{server_comment_lines: int, server_blank_lines: int}|null
     */
    private static function prefixLines(#[SensitiveParameter] string $prefix): ?array
    {
        if ($prefix === '' || !str_ends_with($prefix, "\n")) {
            return null;
        }
        $comments = 0;
        $blank = 0;
        foreach (explode("\n", substr($prefix, 0, -1)) as $line) {
            if ($line === '') {
                ++$blank;
            } elseif (str_starts_with($line, ';')) {
                ++$comments;
            } else {
                return null;
            }
        }

        return ['server_comment_lines' => $comments, 'server_blank_lines' => $blank];
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The recipe holds run entropy and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('LangPHP mutation recipe cannot be serialized.');
    }
}
