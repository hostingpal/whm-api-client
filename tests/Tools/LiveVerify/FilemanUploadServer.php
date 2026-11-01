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

/**
 * Independent account, token and file state double for the disposable upload fixture.
 *
 * Fileman behavior follows the pinned source and the reviewer reports recorded in the multipart design: named rows,
 * refused existing names without overwrite, status 1 for a partial batch and status 0 for an all-failed one. Multipart
 * bodies are read incrementally, never cast, and reach this double only through its streaming() transport. Failures
 * can fire before or after a request's real effect, so a lost response can follow a stored file.
 *
 * @phpstan-import-type Framing from StreamingTransport
 */
final class FilemanUploadServer implements ClientInterface
{
    public const HOME = '/home/wlupload';

    public bool $account = false;

    public bool $zone = false;

    public bool $retainZone = false;

    public ?string $concurrentContactEmail = null;

    /** @var list<string> */
    public array $tokens = [];

    /** @var array<string, mixed> */
    public array $tokenFields = [];

    /** @var array<string, array<string, string>> Directory name, then filename, then stored bytes. */
    public array $directories = [];

    /** @var array<string, list<string>> Directory name, then symbolic link names listed as type link. */
    public array $symlinks = [];

    /** How an upload row reports a stored zero-byte file; listings report it as an empty string on this server. */
    public mixed $emptyRowSize = 0;

    /** Label of another fixture file that a refused row names, in place of the file actually offered. */
    public ?string $refusedRowLabel = null;

    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $mutations = [];

    /**
     * Step failures by function:count, each firing `_before` or `_after` the request's effect: `throw` loses the
     * response, `api` is an explicit refusal, `http` a 502 gateway error, and `invalid` an unreadable 200 body.
     *
     * @var array<string, string>
     */
    public array $failures = [];

    /** @var array<string, string> Raw replacement data JSON, by function:count. */
    public array $overrides = [];

    /** @var array<string, int> Replacement upload envelope status, by function:count. */
    public array $statuses = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public int $streamed = 0;

    /** 'latin1', 'double', 'truncated', 'replacement' or 'equal-size'. */
    public string $transcoding = 'latin1';

    public bool $honorOverwrite = true;

    public bool $reportCharsets = true;

    /** Whether the documented from_char and to_char fields appear; cPanel 11.138.0.19 omits them. */
    public bool $documentedCharsetFields = true;

    /** @var array<string, string> Upstream charset echo overrides. */
    public array $charsetEcho = [];

    /** @var (Closure(self): void)|null Runs after any request's effect, to model concurrent change. */
    public ?Closure $afterEffect = null;

    /** @var Framing|null */
    public ?array $framing = [
        'http_1_1' => true, 'content_length_exact' => true, 'transfer_encoding_absent' => true,
        'expect_absent' => true, 'bytes_sent_exact' => true,
    ];

    private string $contactEmail = '';

    private int $expiresAt = 0;

    private string $deletedPath = '';

    /** Return the transport that alone receives multipart bodies. */
    public function streaming(): StreamingTransport
    {
        $server = $this;

        return new class ($server) implements StreamingTransport {
            public function __construct(private readonly FilemanUploadServer $server)
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
        $bridge = $function === 'uapi_cpanel';
        if ($bridge) {
            if (!is_string($query['cpanel_module'] ?? null) || !is_string($query['cpanel_function'] ?? null)) {
                throw new LogicException('Synthetic bridge request is malformed.');
            }
            $function = $query['cpanel_module'] . '/' . $query['cpanel_function'];
        } elseif (str_contains($path, '/execute/Fileman/')) {
            $function = 'direct:Fileman/' . $function;
            $this->requireLiveToken($request);
        }
        $step = $this->step($function);
        $failure = $this->failures[$step] ?? '';
        if ($function === 'createacct' && $this->concurrentContactEmail !== null) {
            $this->account = true;
            $this->zone = true;
            $this->contactEmail = $this->concurrentContactEmail;
        }
        if ($failure === 'throw_before') {
            self::failTransport();
        }
        if ($failure === 'api_before') {
            return self::failed($function, $bridge);
        }
        if ($failure === 'http_before' || $failure === 'invalid_before') {
            return self::unusable($failure);
        }
        if ($request->getMethod() === 'POST') {
            $this->mutations[] = $function;
        }
        $parameters = [];
        parse_str((string) $request->getBody(), $parameters);
        $this->apply($function, $parameters);
        if ($this->afterEffect !== null) {
            ($this->afterEffect)($this);
        }
        if ($failure === 'throw_after') {
            self::failTransport();
        }
        if ($failure === 'api_after') {
            return self::failed($function, $bridge);
        }
        if ($failure === 'http_after' || $failure === 'invalid_after') {
            return self::unusable($failure);
        }
        $data = $this->payload($function, $query);
        $payload = $this->overrides[$step] ?? json_encode($data, JSON_THROW_ON_ERROR);

        return str_starts_with($function, 'direct:')
            ? self::direct(1, $payload) : self::control($payload, $bridge);
    }

    /** Handle one multipart POST as Fileman/upload_files, with the same failure injection. */
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
        if ($failure === 'throw_before') {
            self::failTransport();
        }
        $body = '';
        while (!$request->getBody()->eof()) {
            $body .= $request->getBody()->read(8192);
        }
        if ($failure === 'api_before') {
            return self::failed('direct:Fileman/upload_files', false);
        }
        if ($failure === 'http_before' || $failure === 'invalid_before') {
            return self::unusable($failure);
        }
        $this->mutations[] = 'direct:Fileman/upload_files';
        $type = $request->getHeaderLine('Content-Type');
        $boundary = substr($type, strlen('multipart/form-data; boundary='));
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $directory = $query['dir'] ?? null;
        if (!is_string($directory)) {
            throw new LogicException('Synthetic upload has no directory.');
        }
        [$status, $data] = $this->store($directory, $body, $boundary, ($query['overwrite'] ?? '0') === '1');
        if (($query['get_disk_info'] ?? '0') === '1') {
            $data['diskinfo'] = [
                'spacelimit' => '67108864.00', 'spaceremain' => '67000000.00', 'spaceused' => '108864.00',
                'fileslimit' => 'unlimited', 'filesremain' => 'unlimited', 'filesused' => 30,
                'spacelimit_humansize' => '64 MB',
            ];
        }
        if ($this->afterEffect !== null) {
            ($this->afterEffect)($this);
        }
        if ($failure === 'throw_after') {
            self::failTransport();
        }
        if ($failure === 'api_after') {
            return self::failed('direct:Fileman/upload_files', false);
        }
        if ($failure === 'http_after' || $failure === 'invalid_after') {
            return self::unusable($failure);
        }
        $payload = $this->overrides[$step] ?? json_encode($data, JSON_THROW_ON_ERROR);

        return self::direct($this->statuses[$step] ?? $status, $payload);
    }

    /** A revoked or wrong credential authenticates nothing, so cleanup order is real here. */
    private function requireLiveToken(RequestInterface $request): void
    {
        $live = $request->getHeaderLine('Authorization') === 'cpanel wlupload:PRIVATEFIXTURETOKEN';
        if ($this->tokens === [] || !$live) {
            throw new LogicException('Synthetic direct request without a live temporary token.');
        }
    }

    private function step(string $function): string
    {
        $this->counts[$function] = ($this->counts[$function] ?? 0) + 1;

        return $function . ':' . $this->counts[$function];
    }

    /**
     * @return array{0: int, 1: array{uploads: list<array<string, mixed>>, succeeded: int, failed: int, warned: int}}
     */
    private function store(string $directory, string $body, string $boundary, bool $overwrite): array
    {
        $rows = [];
        $stored = 0;
        $header = '/\A\r\nContent-Disposition: form-data; name="file-[0-9]+"; filename="([^"]+)"\r\n'
            . 'Content-Type: [^\r\n]+\r\n\r\n/';
        foreach (explode('--' . $boundary, $body) as $segment) {
            if (preg_match($header, $segment, $match) !== 1) {
                continue;
            }
            $name = $match[1];
            $bytes = substr($segment, strlen($match[0]), -2);
            if (isset($this->directories[$directory][$name]) && !$overwrite && $this->honorOverwrite) {
                $reported = $this->refusedRowLabel === null ? $name
                    : $this->refusedRowLabel . substr($name, (int) strpos($name, '-'));
                $rows[] = ['file' => $reported, 'reason' => 'private upstream refusal', 'size' => null, 'status' => 0];
                continue;
            }
            $this->directories[$directory][$name] = $bytes;
            ++$stored;
            $rows[] = ['file' => $name, 'reason' => 'private upstream success',
                'size' => $bytes === '' ? $this->emptyRowSize : strlen($bytes), 'status' => 1, 'warnings' => []];
        }

        return [$stored > 0 ? 1 : 0, ['uploads' => $rows, 'succeeded' => $stored,
            'failed' => count($rows) - $stored, 'warned' => 0]];
    }

    /** @param array<mixed> $parameters */
    private function apply(string $function, array $parameters): void
    {
        switch ($function) {
            case 'createacct':
                $this->account = true;
                $this->zone = true;
                $email = $parameters['contactemail'] ?? '';
                if (!is_string($email)) {
                    throw new LogicException('Synthetic account ownership is malformed.');
                }
                $this->contactEmail = $email;
                break;
            case 'Tokens/create_full_access':
                $this->tokens[] = 'wlupload-upload-fixture';
                if (!is_string($parameters['expires_at'] ?? null)) {
                    throw new LogicException('Synthetic token expiration is missing.');
                }
                $this->expiresAt = (int) $parameters['expires_at'];
                break;
            case 'Tokens/revoke':
                $this->tokens = [];
                break;
            case 'removeacct':
                $this->account = false;
                $this->tokens = [];
                $this->directories = [];
                if (!$this->retainZone) {
                    $this->zone = false;
                }
                break;
            case 'direct:Fileman/delete_file':
                $path = $parameters['path'] ?? null;
                if (!is_string($path)) {
                    throw new LogicException('Synthetic delete has no path.');
                }
                $this->deletedPath = self::HOME . '/' . $path;
                [$directory, $file] = array_pad(explode('/', $path, 2), 2, null);
                if ($file === null) {
                    unset($this->directories[$directory], $this->symlinks[$directory]);
                } else {
                    unset($this->directories[$directory][$file]);
                }
                break;
        }
    }

    /** @param array<mixed> $query */
    private function payload(string $function, array $query): mixed
    {
        return match ($function) {
            'version' => ['version' => '11.138.0.19'],
            'listaccts' => ['acct' => array_merge([
                ['user' => 'existing', 'domain' => 'existing.example', 'email' => 'private-existing@example.com'],
                ['user' => 'wluploadextra', 'domain' => 'wlupload.example.com.other'],
            ], $this->account
                ? [['user' => 'wlupload', 'domain' => 'wlupload.example.com', 'email' => $this->contactEmail]] : [])],
            'listzones' => ['zone' => array_merge(
                [['domain' => 'existing.example', 'zonefile' => 'private.db']],
                $this->zone ? [['domain' => 'wlupload.example.com', 'zonefile' => 'private.db']] : [],
            )],
            'get_domain_info' => ['domains' => array_merge(
                [['domain' => 'existing.example', 'user' => 'existing']],
                $this->account ? [['domain' => 'wlupload.example.com', 'user' => 'wlupload']] : [],
            )],
            'Tokens/list' => array_map(fn (string $name): array => array_replace([
                'name' => $name, 'expires_at' => (string) $this->expiresAt, 'has_full_access' => 1,
            ], $this->tokenFields), $this->tokens),
            'Tokens/create_full_access' => ['token' => 'PRIVATEFIXTURETOKEN', 'create_time' => 1790000000],
            'Tokens/revoke' => 1,
            'direct:Fileman/list_files' => $this->listing($query),
            'direct:Fileman/get_file_content' => $this->content($query),
            'direct:Fileman/delete_file' => ['path' => $this->deletedPath],
            default => null,
        };
    }

    /**
     * @param array<mixed> $query
     * @return list<array<string, mixed>>
     */
    private function listing(array $query): array
    {
        $directory = $query['dir'] ?? null;
        $rows = [];
        if ($directory === self::HOME) {
            $rows[] = self::row(self::HOME, 'public_html', 'dir', 4096);
            foreach (array_keys($this->directories) as $name) {
                $rows[] = self::row(self::HOME, $name, 'dir', 4096);
            }
        } elseif (is_string($directory) && isset($this->directories[$directory])) {
            foreach ($this->directories[$directory] as $name => $bytes) {
                $rows[] = self::row(self::HOME . '/' . $directory, $name, 'file', strlen($bytes));
            }
            foreach ($this->symlinks[$directory] ?? [] as $name) {
                $rows[] = self::row(self::HOME . '/' . $directory, $name, 'link', 4096);
            }
        } else {
            throw new LogicException('Synthetic listing of an unknown directory.');
        }
        $types = is_string($query['types'] ?? null) ? explode('|', $query['types']) : null;
        $hidden = ($query['show_hidden'] ?? '0') === '1';

        return array_values(array_filter($rows, static fn (array $row): bool =>
            ($types === null || in_array($row['type'], $types, true))
            && ($hidden || !is_string($row['file']) || !str_starts_with($row['file'], '.'))));
    }

    /** @return array<string, mixed> */
    private static function row(string $parent, string $name, string $type, int $size): array
    {
        return [
            'absdir' => $parent, 'exists' => 1, 'file' => $name, 'fullpath' => $parent . '/' . $name,
            'gid' => 1000, 'humansize' => '1 KB', 'mode' => 33152, 'mtime' => 1790000000, 'nicemode' => '600',
            'path' => $parent, 'read' => 1, 'size' => $size === 0 ? '' : (string) $size, 'type' => $type,
            'uid' => 1000, 'write' => 1,
        ];
    }

    /**
     * @param array<mixed> $query
     * @return array<string, mixed>
     */
    private function content(array $query): array
    {
        $absolute = $query['dir'] ?? null;
        $file = $query['file'] ?? null;
        if (
            !is_string($absolute) || !is_string($file) || !str_starts_with($absolute, self::HOME . '/')
            || ($query['from_charset'] ?? null) !== 'ISO-8859-1' || ($query['to_charset'] ?? null) !== 'UTF-8'
            || ($query['update_html_document_encoding'] ?? null) !== '0'
        ) {
            throw new LogicException('Synthetic readback requires the explicit reversible charset options.');
        }
        $bytes = $this->directories[substr($absolute, strlen(self::HOME) + 1)][$file]
            ?? throw new LogicException('Synthetic readback of an unknown file.');
        $utf8 = mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        $content = match ($this->transcoding) {
            'double' => mb_convert_encoding($utf8, 'UTF-8', 'ISO-8859-1'),
            'truncated' => explode("\x00", $utf8)[0],
            'replacement' => preg_replace('/\xC2[\x80-\x9F]/', '?', $utf8),
            'equal-size' => strtr($utf8, 'a', 'b'),
            default => $utf8,
        };
        $charsets = ['from_char' => 'ISO-8859-1', 'to_char' => 'UTF-8',
            'from_charset' => 'ISO-8859-1', 'to_charset' => 'UTF-8'];
        $charsets = array_replace($charsets, $this->charsetEcho);
        if (!$this->documentedCharsetFields) {
            unset($charsets['from_char'], $charsets['to_char']);
        }

        return ['content' => $content, 'dir' => $absolute, 'filename' => $file, 'path' => $absolute . '/' . $file]
            + ($this->reportCharsets ? $charsets : []);
    }

    private static function direct(int $status, string $payload): ResponseInterface
    {
        $errors = $status === 1 ? 'null' : '["private upstream text"]';

        return new Response(200, [], '{"status":' . $status . ',"data":' . $payload . ',"errors":' . $errors
            . ',"messages":null,"warnings":null,"metadata":{}}');
    }

    private static function control(string $payload, bool $bridge): ResponseInterface
    {
        if ($bridge) {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":' . $payload
                . ',"errors":null,"messages":null,"warnings":null,"metadata":{}}}}');
        }

        return new Response(200, [], '{"metadata":{"result":1,"reason":"private upstream text"},"data":'
            . $payload . '}');
    }

    private static function failed(string $function, bool $bridge): ResponseInterface
    {
        if (str_starts_with($function, 'direct:')) {
            return self::direct(0, 'null');
        }
        if ($bridge) {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":0,"data":null,'
                . '"errors":["private upstream text"]}}}');
        }

        return new Response(200, [], '{"metadata":{"result":0,"reason":"private upstream text"},"data":null}');
    }

    /** A reply that says nothing about the request's outcome, as a gateway or a truncated response does. */
    private static function unusable(string $failure): ResponseInterface
    {
        return str_starts_with($failure, 'http')
            ? new Response(502, [], 'private upstream gateway text')
            : new Response(200, [], 'private unreadable upstream text');
    }

    private static function failTransport(): never
    {
        throw new class ('private transport failure') extends RuntimeException implements ClientExceptionInterface {
        };
    }
}
