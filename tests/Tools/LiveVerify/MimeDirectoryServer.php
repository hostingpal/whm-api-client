<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Tools\LiveVerify\StreamingTransport;
use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;

/**
 * Independent account, token, Mime and directory state double for the disposable Mime and directory fixture.
 *
 * Behavior follows the server source inspected on cPanel 11.138.0.19: mutations return null data, add_redirect adds a
 * message, list_hotlinks reports defaults while disabled, directory protection returns null auth fields on disable,
 * and a directory must exist before its settings change. Both UAPI routes reach the same state. Failures can fire
 * before or after a request's real effect, so a lost response can follow a change.
 *
 * @phpstan-import-type Framing from StreamingTransport
 * @phpstan-type Directory array{files: array<string, string>, index: string, auth: string|null, users: list<string>}
 */
final class MimeDirectoryServer implements ClientInterface
{
    public const HOME = '/home/wlmime';

    public const DOCROOT = '/home/wlmime/public_html';

    public const TOKEN = 'PRIVATEFIXTURETOKEN';

    public bool $account = false;

    public bool $zone = false;

    /** @var list<string> */
    public array $tokens = [];

    /** @var array<string, string> MIME type to extension. */
    public array $mimeTypes = [];

    /** @var array<string, string> Extension to handler; the PHP handler is present on a fresh account. */
    public array $handlers = ['.php .php8 .phtml' => 'application/x-httpd-ea-php82'];

    /** @var list<array{src: string, target: string, code: string}> */
    public array $redirects = [];

    /** @var array{extensions: string, target: string, urls: list<string>, allow_null: int}|null */
    public ?array $hotlink = null;

    /** @var array<string, Directory> */
    public array $directories = [];

    /** Whether directory listings send an empty `children` as `{}`, which decodes like an empty list. */
    public bool $childrenAsObject = false;

    /** Whether a mutation really changes state; false models a server that accepts and ignores it. */
    public bool $applyDeleteMime = true;

    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> Mutations that reached this server, in order, with their route. */
    public array $mutations = [];

    /**
     * Step failures by function:count, each firing `_before` or `_after` the request's effect: `throw` loses the
     * response, `api` is an explicit refusal, `http` a 502 gateway error and `invalid` an unreadable 200 body.
     *
     * @var array<string, string>
     */
    public array $failures = [];

    /** @var array<string, string> Raw replacement data JSON by function:count. */
    public array $overrides = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public int $streamed = 0;

    /** @var Framing|null */
    public ?array $framing = [
        'http_1_1' => true, 'content_length_exact' => true, 'transfer_encoding_absent' => true,
        'expect_absent' => true, 'bytes_sent_exact' => true,
    ];

    private string $contactEmail = '';

    private int $expiresAt = 0;

    /** Return the transport that alone receives multipart bodies. */
    public function streaming(): StreamingTransport
    {
        return new class ($this) implements StreamingTransport {
            public function __construct(private readonly MimeDirectoryServer $server)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->server->receiveUpload($request);
            }

            public function lastFraming(): ?array
            {
                return $this->server->framing;
            }
        };
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/')) {
            throw new LogicException('A multipart body reached the ordinary client.');
        }
        $this->requests[] = $request;
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $path = $request->getUri()->getPath();
        $function = basename($path);
        $route = 'whm';
        if ($function === 'uapi_cpanel') {
            if (!is_string($query['cpanel_module'] ?? null) || !is_string($query['cpanel_function'] ?? null)) {
                throw new LogicException('Synthetic bridge request is malformed.');
            }
            if (($query['cpanel_user'] ?? null) !== 'wlmime') {
                throw new LogicException('Synthetic bridge request names another account.');
            }
            $function = $query['cpanel_module'] . '/' . $query['cpanel_function'];
            $route = 'bridged';
        } elseif (str_contains($path, '/execute/')) {
            $function = 'direct:' . basename(dirname($path)) . '/' . $function;
            $route = 'direct';
            $this->requireLiveToken($request);
        }
        $step = $this->step($function);
        $failure = $this->failures[$step] ?? '';
        $before = $this->failBefore($failure, $route);
        if ($before !== null) {
            return $before;
        }
        $parameters = [];
        parse_str((string) $request->getBody(), $parameters);
        if ($request->getMethod() === 'POST') {
            $this->mutations[] = $function;
            $fields = $parameters;
        } else {
            $fields = $query;
        }
        $data = $this->apply(str_replace('direct:', '', $function), $fields);
        $after = $this->failAfter($failure, $route);
        if ($after !== null) {
            return $after;
        }
        $payload = $this->overrides[$step] ?? json_encode($data, JSON_THROW_ON_ERROR);
        $messages = str_ends_with($function, 'Mime/add_redirect') ? '["private upstream text"]' : 'null';

        return match ($route) {
            'direct' => new Response(200, [], '{"status":1,"data":' . $payload
                . ',"errors":null,"messages":' . $messages . ',"warnings":null,"metadata":{}}'),
            'bridged' => new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":'
                . $payload . ',"errors":null,"messages":' . $messages . ',"warnings":null,"metadata":{}}}}'),
            default => new Response(200, [], '{"metadata":{"result":1,"reason":"private upstream text"},"data":'
                . $payload . '}'),
        };
    }

    /** Handle one multipart POST as Fileman/upload_files on the direct route. */
    public function receiveUpload(RequestInterface $request): ResponseInterface
    {
        if ($request->getBody()->isSeekable()) {
            throw new LogicException('Synthetic streaming transport requires a forward-only body.');
        }
        $this->requireLiveToken($request);
        $this->requests[] = $request;
        ++$this->streamed;
        $step = $this->step('direct:Fileman/upload_files');
        $failure = $this->failures[$step] ?? '';
        $before = $this->failBefore($failure, 'direct');
        if ($before !== null) {
            return $before;
        }
        $body = '';
        while (!$request->getBody()->eof()) {
            $body .= $request->getBody()->read(8192);
        }
        $this->mutations[] = 'direct:Fileman/upload_files';
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $directory = $query['dir'] ?? null;
        $header = '/\r\nContent-Disposition: form-data; name="file-0"; filename="([^"]+)"\r\n'
            . 'Content-Type: [^\r\n]+\r\n\r\n(.*)\r\n--/s';
        if (!is_string($directory) || !str_starts_with($directory, 'public_html/') || !preg_match($header, $body, $m)) {
            throw new LogicException('Synthetic upload is malformed.');
        }
        $name = substr($directory, strlen('public_html/'));
        $this->directories[$name] ??= ['files' => [], 'index' => 'inherit', 'auth' => null, 'users' => []];
        $this->directories[$name]['files'][$m[1]] = $m[2];
        $after = $this->failAfter($failure, 'direct');
        if ($after !== null) {
            return $after;
        }
        $payload = $this->overrides[$step] ?? json_encode(['uploads' => [[
            'file' => $m[1], 'reason' => 'private upstream success', 'size' => strlen($m[2]), 'status' => 1,
            'warnings' => [],
        ]], 'succeeded' => 1, 'failed' => 0, 'warned' => 0], JSON_THROW_ON_ERROR);

        return new Response(200, [], '{"status":1,"data":' . $payload
            . ',"errors":null,"messages":null,"warnings":null,"metadata":{}}');
    }

    /** A revoked or wrong credential authenticates nothing, so cleanup order is real here. */
    private function requireLiveToken(RequestInterface $request): void
    {
        if ($this->tokens === [] || $request->getHeaderLine('Authorization') !== 'cpanel wlmime:' . self::TOKEN) {
            throw new LogicException('Synthetic direct request without a live temporary token.');
        }
    }

    private function step(string $function): string
    {
        $this->counts[$function] = ($this->counts[$function] ?? 0) + 1;

        return $function . ':' . $this->counts[$function];
    }

    /** @param array<mixed> $fields */
    private function apply(string $function, array $fields): mixed
    {
        $string = static fn (string $name): string => is_string($fields[$name] ?? null)
            ? $fields[$name] : throw new LogicException('Synthetic request lacks ' . $name . '.');

        return match ($function) {
            'version' => ['version' => '11.138.0.19'],
            'createacct' => $this->create($string('contactemail')),
            'removeacct' => $this->remove(),
            'listaccts' => ['acct' => array_merge([
                ['user' => 'existing', 'domain' => 'existing.example', 'email' => 'private-existing@example.com'],
                ['user' => 'wlmimeextra', 'domain' => 'wlmime.example.com.other'],
            ], $this->account
                ? [['user' => 'wlmime', 'domain' => 'wlmime.example.com', 'email' => $this->contactEmail]] : [])],
            'listzones' => ['zone' => array_merge(
                [['domain' => 'existing.example', 'zonefile' => 'private.db']],
                $this->zone ? [['domain' => 'wlmime.example.com', 'zonefile' => 'private.db']] : [],
            )],
            'get_domain_info' => ['domains' => array_merge(
                [['domain' => 'existing.example', 'user' => 'existing']],
                $this->account ? [['domain' => 'wlmime.example.com', 'user' => 'wlmime']] : [],
            )],
            'Tokens/list' => array_map(fn (string $name): array => [
                'name' => $name, 'expires_at' => (string) $this->expiresAt, 'has_full_access' => 1,
            ], $this->tokens),
            'Tokens/create_full_access' => $this->createToken($string('expires_at')),
            'Tokens/revoke' => $this->revoke(),
            'Mime/add_mime' => $this->addMime($string('type'), $string('extension')),
            'Mime/delete_mime' => $this->deleteMime($string('type')),
            'Mime/list_mime' => self::sortedRows($this->mimeTypes, 'type', 'extension'),
            'Mime/add_handler' => $this->addHandler($string('extension'), $string('handler')),
            'Mime/delete_handler' => $this->unset($this->handlers, $string('extension')),
            'Mime/list_handlers' => self::sortedRows($this->handlers, 'extension', 'handler'),
            'Mime/add_redirect' => $this->addRedirect($fields, $string('src'), $string('redirect')),
            'Mime/delete_redirect' => $this->deleteRedirect($string('src')),
            'Mime/list_redirects' => array_map(self::redirectRow(...), $this->redirects),
            'Mime/get_redirect' => $this->redirects === [] ? ['redirection_enabled' => 0, 'url' => null]
                : ['redirection_enabled' => 1, 'url' => $this->redirects[array_key_last($this->redirects)]['target']],
            'Mime/add_hotlink' => $this->addHotlink($fields, $string('extensions'), $string('urls')),
            'Mime/delete_hotlink' => $this->hotlink = null,
            'Mime/list_hotlinks' => $this->hotlinks(),
            'Fileman/list_files' => $this->listFiles($string('dir')),
            'DirectoryIndexes/get_indexing' => $this->directory($string('dir'))['index'],
            'DirectoryIndexes/set_indexing' => $this->setIndexing($string('dir'), $string('type')),
            'DirectoryIndexes/list_directories' => $this->listing($string('dir'), fn (array $d): array =>
                ['index_type' => $d['index']]),
            'DirectoryProtection/list_directories' => $this->listing($string('dir'), static fn (): array =>
                ['has_leech_protection' => 0]),
            'DirectoryPrivacy/list_directories' => $this->listing($string('dir'), self::privacy(...)),
            'DirectoryPrivacy/is_directory_protected' => self::privacy($this->directory($string('dir'))),
            'DirectoryPrivacy/configure_directory_protection' => $this->configure($fields, $string('dir')),
            'DirectoryPrivacy/add_user' => $this->addUser($string('dir'), $string('user'), $string('password')),
            'DirectoryPrivacy/delete_user' => $this->deleteUser($string('dir'), $string('user')),
            'DirectoryPrivacy/list_users' => $this->directory($string('dir'))['users'],
            default => throw new LogicException('Synthetic server has no ' . $function . '.'),
        };
    }

    private function create(string $email): mixed
    {
        $this->account = true;
        $this->zone = true;
        $this->contactEmail = $email;

        return null;
    }

    private function remove(): mixed
    {
        $this->account = false;
        $this->zone = false;
        $this->tokens = [];
        $this->directories = [];

        return null;
    }

    /** @return array{token: string, create_time: int} */
    private function createToken(string $expiresAt): array
    {
        $this->expiresAt = (int) $expiresAt;
        $this->tokens[] = 'wlmime-fixture';

        return ['token' => self::TOKEN, 'create_time' => 1790000000];
    }

    private function revoke(): int
    {
        $this->tokens = [];

        return 1;
    }

    private function addMime(string $type, string $extension): mixed
    {
        $this->mimeTypes[$type] = $extension;

        return null;
    }

    private function addHandler(string $extension, string $handler): mixed
    {
        $this->handlers[$extension] = $handler;

        return null;
    }

    /** @param array<string, string> $map */
    private function unset(array &$map, string $key): mixed
    {
        unset($map[$key]);

        return null;
    }

    private function deleteMime(string $type): mixed
    {
        if ($this->applyDeleteMime) {
            unset($this->mimeTypes[$type]);
        }

        return null;
    }

    /**
     * @param array<string, string> $map
     * @return list<array<string, string>>
     */
    private static function sortedRows(array $map, string $key, string $value): array
    {
        ksort($map);
        $rows = [];
        foreach ($map as $name => $item) {
            $rows[] = [$key => $name, $value => $item, 'origin' => 'user'];
        }

        return $rows;
    }

    /** @param array<mixed> $fields */
    private function addRedirect(array $fields, string $src, string $target): mixed
    {
        $this->deleteRedirect($src);
        $this->redirects[] = ['src' => $src, 'target' => $target,
            'code' => ($fields['type'] ?? 'permanent') === 'temp' ? '302' : '301'];

        return null;
    }

    private function deleteRedirect(string $src): mixed
    {
        $this->redirects = array_values(array_filter(
            $this->redirects,
            static fn (array $redirect): bool => $redirect['src'] !== $src,
        ));

        return null;
    }

    /**
     * @param array{src: string, target: string, code: string} $redirect
     * @return array<string, mixed>
     */
    private static function redirectRow(array $redirect): array
    {
        return [
            'destination' => $redirect['target'], 'displaydomain' => 'wlmime.example.com',
            'displaysourceurl' => $redirect['src'], 'docroot' => self::DOCROOT, 'domain' => 'wlmime.example.com',
            'kind' => 'rewrite', 'matchwww' => 1, 'matchwww_text' => 'checked', 'opts' => 'L',
            'source' => $redirect['src'], 'sourceurl' => $redirect['src'], 'statuscode' => $redirect['code'],
            'targeturl' => $redirect['target'], 'type' => $redirect['code'] === '301' ? 'permanent' : 'temporary',
            'urldomain' => 'wlmime.example.com', 'wildcard' => 0, 'wildcard_text' => '',
        ];
    }

    /** @param array<mixed> $fields */
    private function addHotlink(array $fields, string $extensions, string $urls): mixed
    {
        $list = explode("\n", $urls);
        sort($list);
        $target = $fields['redirect_url'] ?? null;
        $this->hotlink = ['extensions' => $extensions, 'target' => is_string($target) ? $target : '',
            'urls' => $list, 'allow_null' => ($fields['allow_null'] ?? '0') === '1' ? 1 : 0];

        return null;
    }

    /** @return array<string, mixed> */
    private function hotlinks(): array
    {
        if ($this->hotlink === null) {
            return ['allow_null' => 0, 'extensions' => 'jpg,jpeg,gif,png,bmp', 'redirect_url' => null,
                'state' => 'disabled', 'urls' => ['http://wlmime.example.com', 'http://www.wlmime.example.com',
                    'https://wlmime.example.com', 'https://www.wlmime.example.com']];
        }

        return ['allow_null' => $this->hotlink['allow_null'], 'extensions' => $this->hotlink['extensions'],
            'redirect_url' => $this->hotlink['target'], 'state' => 'enabled', 'urls' => $this->hotlink['urls']];
    }

    /** @return list<array<string, mixed>> */
    private function listFiles(string $dir): array
    {
        if ($dir === self::DOCROOT) {
            $rows = [self::fileRow(self::DOCROOT, 'cgi-bin', 'dir', 4096)];
            foreach (array_keys($this->directories) as $name) {
                $rows[] = self::fileRow(self::DOCROOT, $name, 'dir', 4096);
            }

            return $rows;
        }
        $name = $this->name($dir);
        $rows = [];
        foreach ($this->directories[$name]['files'] as $file => $bytes) {
            $rows[] = self::fileRow($dir, $file, 'file', strlen($bytes));
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function fileRow(string $parent, string $name, string $type, int $size): array
    {
        return [
            'absdir' => $parent, 'exists' => 1, 'file' => $name, 'fullpath' => $parent . '/' . $name,
            'gid' => 1000, 'humansize' => '1 KB', 'mode' => 33188, 'mtime' => 1790000000, 'nicemode' => '644',
            'path' => $parent, 'read' => 1, 'size' => (string) $size, 'type' => $type, 'uid' => 1000, 'write' => 1,
        ];
    }

    private function name(string $dir): string
    {
        $name = str_starts_with($dir, self::DOCROOT . '/') ? substr($dir, strlen(self::DOCROOT) + 1) : '';
        if (!isset($this->directories[$name])) {
            throw new LogicException('Synthetic directory does not exist.');
        }

        return $name;
    }

    /** @return Directory */
    private function directory(string $dir): array
    {
        return $this->directories[$this->name($dir)];
    }

    private function setIndexing(string $dir, string $type): string
    {
        $this->directories[$this->name($dir)]['index'] = $type;

        return $type;
    }

    /**
     * @param Closure(Directory): array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function listing(string $dir, Closure $state): array
    {
        $directory = $this->directory($dir);
        $plain = ['files' => [], 'index' => 'inherit', 'auth' => null, 'users' => []];

        return [
            'children' => $this->childrenAsObject ? new stdClass() : [],
            'current' => ['path' => $dir, 'state' => $state($directory)],
            'home' => ['path' => self::HOME, 'state' => $state($plain)],
            'parent' => ['path' => self::DOCROOT, 'state' => $state($plain)],
        ];
    }

    /**
     * @param Directory $directory
     * @return array<string, mixed>
     */
    private static function privacy(array $directory): array
    {
        return $directory['auth'] === null
            ? ['auth_name' => '', 'auth_type' => 'None', 'passwd_file' => '', 'protected' => 0]
            : ['auth_name' => $directory['auth'], 'auth_type' => 'Basic',
                'passwd_file' => self::HOME . '/.htpasswds/private/passwd', 'protected' => 1];
    }

    /**
     * @param array<mixed> $fields
     * @return array<string, mixed>
     */
    private function configure(array $fields, string $dir): array
    {
        $name = $this->name($dir);
        $enabled = ($fields['enabled'] ?? null) === '1';
        $authname = $enabled ? ($fields['authname'] ?? null) : null;
        if ($enabled && !is_string($authname)) {
            throw new LogicException('Synthetic protection requires a realm name.');
        }
        $this->directories[$name]['auth'] = is_string($authname) ? $authname : null;

        return [
            'auth_name' => $authname, 'auth_type' => $enabled ? 'Basic' : null,
            'passwd_file' => self::HOME . '/.htpasswds/private/passwd', 'protected' => $enabled ? 1 : 0,
        ];
    }

    private function addUser(string $dir, string $user, string $password): mixed
    {
        if (strlen($password) < 20) {
            throw new LogicException('Synthetic password is too weak.');
        }
        $this->directories[$this->name($dir)]['users'][] = $user;

        return null;
    }

    private function deleteUser(string $dir, string $user): mixed
    {
        $name = $this->name($dir);
        $this->directories[$name]['users'] = array_values(array_diff($this->directories[$name]['users'], [$user]));

        return null;
    }

    private function failBefore(string $failure, string $route): ?ResponseInterface
    {
        return str_ends_with($failure, '_before') ? self::fail(substr($failure, 0, -7), $route) : null;
    }

    private function failAfter(string $failure, string $route): ?ResponseInterface
    {
        return str_ends_with($failure, '_after') ? self::fail(substr($failure, 0, -6), $route) : null;
    }

    private static function fail(string $kind, string $route): ResponseInterface
    {
        return match ($kind) {
            'throw' => throw new class ('private transport failure') extends RuntimeException implements
                ClientExceptionInterface
            {
            },
            'api' => new Response(200, [], self::refusal($route)),
            'http' => new Response(502, [], 'private upstream gateway text'),
            'invalid' => new Response(200, [], 'private unreadable upstream text'),
            default => throw new LogicException('Unknown synthetic failure.'),
        };
    }

    /** An explicit API refusal in the envelope of the request's route. */
    private static function refusal(string $route): string
    {
        return match ($route) {
            'direct' => '{"status":0,"data":null,"errors":["private upstream text"]}',
            'bridged' => '{"metadata":{"result":1},"data":{"uapi":{"status":0,"data":null,'
                . '"errors":["private upstream text"]}}}',
            default => '{"metadata":{"result":0,"reason":"private upstream text"},"data":null}',
        };
    }
}
