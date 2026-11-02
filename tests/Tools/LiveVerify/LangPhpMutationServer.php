<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;

/**
 * Independent account, token and LangPHP state double for the disposable PHP mutation fixture.
 *
 * LangPHP behavior follows the cPanel 11.138.0.19 handler as reviewed for this recipe: the INI type must be home or
 * vhost, the content setter decodes HTML entities and the content getter encodes them, every write prepends a comment
 * block unless one is present, directive values arrive as bare or numbered `directive` fields, and a vhost read with a
 * vhost selector returns one object. Each behavior can be switched off to model a server that differs. Failures fire
 * `_before` or `_after` a request's real effect: `throw` loses the response, `api` is an explicit refusal, `http` a
 * 502 gateway error and `invalid` an unreadable 200 body.
 */
final class LangPhpMutationServer implements ClientInterface
{
    public const TOKEN = 'PRIVATEPHPTOKEN';

    private const HEADER = "; private cPanel-generated block, do not edit\n"
        . "; private editor path (Home >> Software >> MultiPHP INI Editor)\n\n";

    public bool $account = false;

    public bool $zone = false;

    public bool $retainZone = false;

    public ?string $concurrentContactEmail = null;

    /** @var list<string> */
    public array $tokens = [];

    /** @var array<string, mixed> */
    public array $tokenFields = [];

    /** @var list<string> */
    public array $installed = ['ea-php82', 'ea-php83', 'ea-php84', 'ea-php85'];

    public string $vhostVersion = 'ea-php83';

    /** Stored home php.ini text; an empty string is an absent file, which the getter reads as empty. */
    public string $homeIni = '';

    /** @var array<string, string> Directive values the system php.ini supplies when the home file has none. */
    public array $systemDirectives = [
        'max_execution_time' => '30', 'max_input_time' => '60', 'max_input_vars' => '1000', 'memory_limit' => '128M',
    ];

    /** Whether the content setter treats type=host as home; the reviewed handler refuses it. */
    public bool $acceptHost = false;

    /** Whether the content setter decodes entities and the getter encodes them. */
    public bool $entities = true;

    /** Whether writes prepend the server's comment block. */
    public bool $header = true;

    /** Whether the getter URL-decodes stored content a second time, as a broken server might. */
    public bool $doubleDecode = false;

    /** Whether directive writes reach the file at all. */
    public bool $applyDirectives = true;

    /** Whether numbered `directive-N` fields are read alongside the bare field. */
    public bool $readNumbered = true;

    /** Whether version writes reach the vhost. */
    public bool $applyVersion = true;

    /** Whether a vhost-selected read returns one object rather than a one-row list. */
    public bool $vhostObject = true;

    /** Whether a read without a vhost selector returns the account's one row as an object, a form the union admits. */
    public bool $allVhostsAsObject = false;

    /** @var list<string>|null Replacement vhosts list a version write reports. */
    public ?array $reportedVhosts = null;

    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $mutations = [];

    /** @var array<string, string> Step failures by function:count. */
    public array $failures = [];

    /** @var array<string, string> Raw replacement data JSON by function:count. */
    public array $overrides = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var (Closure(self): void)|null Runs after any request's effect, to model concurrent change. */
    public ?Closure $afterEffect = null;

    private string $contactEmail = '';

    private int $expiresAt = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $query = self::fields($request->getUri()->getQuery());
        $path = $request->getUri()->getPath();
        $function = basename($path);
        $bridge = $function === 'uapi_cpanel';
        $direct = str_contains($path, '/execute/LangPHP/');
        if ($bridge) {
            $module = self::first($query, 'cpanel.module');
            $name = self::first($query, 'cpanel.function');
            if ($module === null || $name === null || self::first($query, 'cpanel.user') !== 'wlphpset') {
                throw new LogicException('Synthetic bridge request is malformed or names another account.');
            }
            $function = $module . '/' . $name;
        } elseif ($direct) {
            $function = 'direct:LangPHP/' . $function;
            $this->requireLiveToken($request);
        }
        $this->counts[$function] = ($this->counts[$function] ?? 0) + 1;
        $step = $function . ':' . $this->counts[$function];
        $failure = $this->failures[$step] ?? '';
        if ($function === 'createacct' && $this->concurrentContactEmail !== null) {
            $this->account = true;
            $this->zone = true;
            $this->contactEmail = $this->concurrentContactEmail;
        }
        $langPhp = $direct || str_starts_with($function, 'LangPHP/');
        if (str_ends_with($failure, '_before')) {
            return self::fail(substr($failure, 0, -7), $bridge, $direct);
        }
        if ($request->getMethod() === 'POST') {
            $this->mutations[] = $function;
        }
        $fields = [...$query, ...self::fields((string) $request->getBody())];
        try {
            $data = $langPhp ? $this->langPhp(substr($function, strrpos($function, '/') + 1), $fields)
                : $this->control($function, $fields);
        } catch (RuntimeException) {
            if ($this->afterEffect !== null) {
                ($this->afterEffect)($this);
            }

            return self::fail('api', $bridge, $direct);
        }
        if ($this->afterEffect !== null) {
            ($this->afterEffect)($this);
        }
        if (str_ends_with($failure, '_after')) {
            return self::fail(substr($failure, 0, -6), $bridge, $direct);
        }
        $payload = $this->overrides[$step] ?? json_encode($data, JSON_THROW_ON_ERROR);

        return self::success($payload, $bridge, $direct);
    }

    /** The stored home php.ini as the content getter reports it. */
    public function contentReply(): string
    {
        $content = $this->entities ? self::encode($this->homeIni) : $this->homeIni;

        return $this->doubleDecode ? urldecode($content) : $content;
    }

    /**
     * @param list<array{string, string}> $fields
     */
    private function langPhp(string $function, array $fields): mixed
    {
        return match ($function) {
            'php_get_installed_versions' => ['versions' => $this->installed],
            'php_get_vhost_versions' => $this->vhosts(self::first($fields, 'vhost')),
            'php_ini_get_user_basic_directives' => $this->directives(self::first($fields, 'type')),
            'php_ini_get_user_content' => $this->content(self::first($fields, 'type')),
            'php_ini_set_user_basic_directives' => $this->setDirectives($fields),
            'php_ini_set_user_content' => $this->setContent($fields),
            'php_set_vhost_versions' => $this->setVersion($fields),
            default => throw new LogicException('Synthetic LangPHP function is not modelled.'),
        };
    }

    private function vhosts(?string $vhost): mixed
    {
        $row = [
            'account' => 'wlphpset', 'account_owner' => 'root', 'documentroot' => '/home/wlphpset/public_html',
            'homedir' => '/home/wlphpset', 'main_domain' => 1, 'php_fpm' => 0, 'php_fpm_pool_parms' => new stdClass(),
            'phpversion_source' => ['domain' => 'private-source.example'], 'version' => $this->vhostVersion,
            'vhost' => 'wlphpset.example.com',
        ];
        if ($vhost === null) {
            return $this->allVhostsAsObject ? $row : [$row];
        }
        if ($vhost !== 'wlphpset.example.com') {
            throw new RuntimeException('Cannot find vhost.');
        }

        return $this->vhostObject ? $row : [$row];
    }

    /** @return array{directives: list<array<string, mixed>>} */
    private function directives(?string $type): array
    {
        self::requireIniType($type);
        $values = $this->systemDirectives;
        foreach (explode("\n", $this->homeIni) as $line) {
            if (preg_match('/\A\s*([A-Za-z0-9._]+)\s*=\s*(.*)\z/', $line, $match) === 1) {
                if (isset($values[$match[1]])) {
                    $values[$match[1]] = $match[2];
                }
            }
        }
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = ['default_value' => $this->systemDirectives[$key], 'info' => 'private note', 'key' => $key,
                'php_ini_mode' => 'PHP_INI_ALL', 'type' => 'integer', 'value' => $value];
        }

        return ['directives' => $rows];
    }

    /** @return array{content: string} */
    private function content(?string $type): array
    {
        self::requireIniType($type);

        return ['content' => $this->contentReply()];
    }

    /** @param list<array{string, string}> $fields */
    private function setDirectives(array $fields): mixed
    {
        self::requireIniType(self::first($fields, 'type'));
        $assignments = [];
        foreach ($fields as [$name, $value]) {
            if ($name === 'directive' || ($this->readNumbered && preg_match('/\Adirective-[0-9]+\z/', $name) === 1)) {
                [$key, $setting] = explode(':', $value, 2) + [1 => ''];
                $assignments[$key] = $setting;
            }
        }
        if ($assignments === []) {
            throw new RuntimeException('Provide the directive argument.');
        }
        if (!$this->applyDirectives) {
            return null;
        }
        $lines = $this->homeIni === '' ? [] : explode("\n", rtrim($this->homeIni, "\n"));
        foreach ($lines as $index => $line) {
            if (preg_match('/\A[;\s]*([A-Za-z0-9._]+)\s*=/', $line, $match) === 1 && isset($assignments[$match[1]])) {
                $lines[$index] = $match[1] . ' = ' . $assignments[$match[1]];
                unset($assignments[$match[1]]);
            }
        }
        foreach ($assignments as $key => $setting) {
            $lines[] = $key . ' = ' . $setting;
        }
        $this->store(implode("\n", $lines) . "\n");

        return null;
    }

    /** @param list<array{string, string}> $fields */
    private function setContent(array $fields): mixed
    {
        $type = self::first($fields, 'type');
        if (!($this->acceptHost && $type === 'host')) {
            self::requireIniType($type);
        }
        $content = self::first($fields, 'content');
        if ($content === null || $content === '') {
            throw new RuntimeException('The php.ini file is empty.');
        }
        $this->store($this->entities ? html_entity_decode($content, ENT_QUOTES | ENT_HTML5) : $content);

        return null;
    }

    /** @param list<array{string, string}> $fields */
    private function setVersion(array $fields): mixed
    {
        $version = self::first($fields, 'version');
        if ($version === null || !in_array($version, $this->installed, true)) {
            throw new RuntimeException('The version is not installed.');
        }
        if (self::first($fields, 'vhost') !== 'wlphpset.example.com') {
            throw new RuntimeException('The vhost is not this account.');
        }
        if ($this->applyVersion) {
            $this->vhostVersion = $version;
        }

        return ['vhosts' => $this->reportedVhosts ?? ['wlphpset.example.com']];
    }

    private function store(string $text): void
    {
        $lines = '';
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            $lines .= $line . "\n";
        }
        $this->homeIni = ($this->header && !str_contains($lines, 'cPanel-generated') ? self::HEADER : '') . $lines;
    }

    private static function requireIniType(?string $type): void
    {
        if ($type !== 'home' && $type !== 'vhost') {
            throw new RuntimeException('The type argument must be vhost or home.');
        }
    }

    private static function encode(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '"' => '&quot;', "'" => '&#39;', '<' => '&lt;', '>' => '&gt;']);
    }

    /**
     * @param list<array{string, string}> $fields
     */
    private function control(string $function, array $fields): mixed
    {
        switch ($function) {
            case 'createacct':
                $this->account = true;
                $this->zone = true;
                $this->contactEmail = self::first($fields, 'contactemail') ?? '';
                break;
            case 'Tokens/create_full_access':
                $this->tokens[] = 'wlphpset-php-fixture';
                $this->expiresAt = (int) self::first($fields, 'expires_at');
                break;
            case 'Tokens/revoke':
                $this->tokens = [];
                break;
            case 'removeacct':
                $this->account = false;
                $this->tokens = [];
                $this->zone = $this->retainZone;
                break;
        }

        return match ($function) {
            'version' => ['version' => '11.138.0.19'],
            'listaccts' => ['acct' => array_merge([
                ['user' => 'existing', 'domain' => 'existing.example', 'email' => 'private-existing@example.com'],
                ['user' => 'wlphpsetx', 'domain' => 'wlphpset.example.com.other'],
            ], $this->account
                ? [['user' => 'wlphpset', 'domain' => 'wlphpset.example.com', 'email' => $this->contactEmail]] : [])],
            'listzones' => ['zone' => array_merge(
                [['domain' => 'existing.example', 'zonefile' => 'private.db']],
                $this->zone ? [['domain' => 'wlphpset.example.com', 'zonefile' => 'private.db']] : [],
            )],
            'get_domain_info' => ['domains' => array_merge(
                [['domain' => 'existing.example', 'user' => 'existing']],
                $this->account ? [['domain' => 'wlphpset.example.com', 'user' => 'wlphpset']] : [],
            )],
            'Tokens/list' => array_map(fn (string $name): array => array_replace([
                'name' => $name, 'expires_at' => (string) $this->expiresAt, 'has_full_access' => 1,
            ], $this->tokenFields), $this->tokens),
            'Tokens/create_full_access' => ['token' => self::TOKEN, 'create_time' => 1790000000],
            'Tokens/revoke' => 1,
            default => null,
        };
    }

    /** A revoked or wrong credential authenticates nothing, so cleanup order is real here. */
    private function requireLiveToken(RequestInterface $request): void
    {
        if ($this->tokens === [] || $request->getHeaderLine('Authorization') !== 'cpanel wlphpset:' . self::TOKEN) {
            throw new LogicException('Synthetic direct request without a live temporary token.');
        }
    }

    /**
     * Ordered form fields, so repeated and numbered names stay distinct as the server sees them.
     *
     * @return list<array{string, string}>
     */
    private static function fields(string $encoded): array
    {
        $fields = [];
        foreach ($encoded === '' ? [] : explode('&', $encoded) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            $fields[] = [urldecode($name), urldecode($value)];
        }

        return $fields;
    }

    /** @param list<array{string, string}> $fields */
    private static function first(array $fields, string $name): ?string
    {
        foreach ($fields as [$field, $value]) {
            if ($field === $name) {
                return $value;
            }
        }

        return null;
    }

    private static function success(string $payload, bool $bridge, bool $direct): ResponseInterface
    {
        if ($direct) {
            return new Response(200, [], '{"status":1,"data":' . $payload
                . ',"errors":null,"messages":null,"warnings":null,"metadata":{}}');
        }
        if ($bridge) {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":' . $payload
                . ',"errors":null,"messages":null,"warnings":null,"metadata":{}}}}');
        }

        return new Response(200, [], '{"metadata":{"result":1,"reason":"private upstream text"},"data":'
            . $payload . '}');
    }

    private static function fail(string $kind, bool $bridge, bool $direct): ResponseInterface
    {
        return match ($kind) {
            'throw' => throw new class ('private transport failure') extends RuntimeException implements
                ClientExceptionInterface
            {
            },
            'api' => match (true) {
                $direct => new Response(200, [], '{"status":0,"data":null,"errors":["private upstream text"],'
                    . '"messages":null,"warnings":null,"metadata":{}}'),
                $bridge => new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":0,"data":null,'
                    . '"errors":["private upstream text"],"messages":null,"warnings":null,"metadata":{}}}}'),
                default => new Response(200, [], '{"metadata":{"result":0,"reason":"private upstream text"},'
                    . '"data":null}'),
            },
            'http' => new Response(502, [], 'private upstream gateway text'),
            'invalid' => new Response(200, [], 'private unreadable upstream text'),
            default => throw new LogicException('Unknown synthetic failure.'),
        };
    }
}
