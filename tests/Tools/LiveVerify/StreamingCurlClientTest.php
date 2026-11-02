<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Tools\LiveVerify\StreamingCurlClient;
use HostingPal\WhmApiClient\Upload;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Exercise real libcurl framing against a real loopback socket; no credential or live service is involved.
 *
 * The client's target boundary is fixed, so an isolated child process replaces only curl_init() and the protocol
 * option, through this client's namespace, to reach the loopback server. Everything else is the production path.
 */
final class StreamingCurlClientTest extends TestCase
{
    private const URI = 'https://test.oblak.host:2083/execute/Fileman/upload_files?dir=fixture&overwrite=0';

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS'] as $key) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=1');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    #[RequiresPhpExtension('curl')]
    public function testSendsExactLengthPostIncrementallyWithoutChunkingOrExpect(): void
    {
        $source = random_bytes(3000000);
        $result = self::exchange('complete', strlen($source), $source);

        self::assertSame(0, $result['client']['exit']);
        self::assertTrue($result['client']['responded']);
        self::assertSame(1, $result['server']['connections']);
        $request = $result['server']['requests'][0];
        self::assertStringStartsWith(
            'POST /execute/Fileman/upload_files?dir=fixture&overwrite=0 HTTP/1.1',
            $request['head'],
        );
        self::assertStringContainsString('Authorization: cpanel account:private-token', $request['head']);
        self::assertStringNotContainsStringIgnoringCase('transfer-encoding', $request['head']);
        self::assertStringNotContainsStringIgnoringCase('expect:', $request['head']);
        self::assertSame(
            1,
            preg_match_all('/^content-length: ' . $result['client']['size'] . '\r?$/mi', $request['head']),
        );
        self::assertSame($result['client']['size'], $request['bytes']);
        self::assertSame($result['client']['sha256'], $request['sha256']);
        self::assertSame([
            'http_1_1' => true, 'content_length_exact' => true, 'transfer_encoding_absent' => true,
            'expect_absent' => true, 'bytes_sent_exact' => true,
        ], $result['client']['framing']);
        self::assertGreaterThan(300, $result['client']['reads']);
        self::assertLessThanOrEqual(8192, $result['client']['largest_read']);
        self::assertSame(0, $result['client']['casts']);
        self::assertSame(0, $result['client']['rewinds']);
    }

    /** @param 'short'|'throwing' $mode */
    #[DataProvider('failingSources')]
    #[RequiresPhpExtension('curl')]
    public function testSourceFailureAbortsOnceWithoutRetryAndWithoutPrivateDetail(string $mode): void
    {
        $result = self::exchange($mode, 200000, random_bytes(200000));

        self::assertSame(0, $result['client']['exit']);
        self::assertFalse($result['client']['responded']);
        self::assertSame('Live HTTPS transport failed.', $result['client']['message']);
        self::assertFalse($result['client']['previous']);
        self::assertTrue($result['client']['client_exception']);
        self::assertNull($result['client']['framing']);
        self::assertSame(1, $result['server']['connections']);
        self::assertLessThan(200000, $result['server']['requests'][0]['bytes']);
    }

    /** @return iterable<string, array{'short'|'throwing'}> */
    public static function failingSources(): iterable
    {
        yield 'early end of source' => ['short'];
        yield 'source exception' => ['throwing'];
    }

    /** The whole upload path at once: typed client, exact guard, body check, real libcurl, real socket. */
    #[RequiresPhpExtension('curl')]
    public function testGuardBodyCheckAndClientCooperateOnARealUpload(): void
    {
        $result = self::exchange('guarded', 0, '');

        self::assertSame(0, $result['client']['exit']);
        self::assertTrue($result['client']['responded'], $result['client']['message']);
        self::assertSame(1, $result['server']['connections']);
        $request = $result['server']['requests'][0];
        self::assertSame($result['client']['sha256'], $request['sha256']);
        self::assertSame($result['client']['size'], $request['bytes']);
        self::assertSame([
            'http_1_1' => true, 'content_length_exact' => true, 'transfer_encoding_absent' => true,
            'expect_absent' => true, 'bytes_sent_exact' => true,
        ], $result['client']['framing']);
        self::assertStringContainsString('Authorization: cpanel wlupload:TEMPORARY', $request['head']);
        self::assertStringNotContainsStringIgnoringCase('transfer-encoding', $request['head']);
    }

    public function testRefusesEveryRequestOutsideItsBoundaryBeforeInitializingCurl(): void
    {
        foreach (self::outsideBoundary() as $label => $request) {
            try {
                (new StreamingCurlClient())->sendRequest($request);
                self::fail($label . ' reached the network.');
            } catch (LogicException $exception) {
                self::assertSame(
                    'Request outside the streaming transport boundary.',
                    $exception->getMessage(),
                    $label,
                );
            }
        }
    }

    public function testRefusesWithoutBothOptIns(): void
    {
        foreach (['WHM_API_LIVE', 'WHM_API_LIVE_MUTATIONS'] as $key) {
            putenv($key . '=0');
            try {
                (new StreamingCurlClient())->sendRequest(self::request());
                self::fail($key . ' was not required.');
            } catch (LogicException $exception) {
                self::assertSame('Streaming transport requires both live opt-ins.', $exception->getMessage());
            }
            putenv($key . '=1');
        }
    }

    /** @return iterable<string, RequestInterface> */
    private static function outsideBoundary(): iterable
    {
        $base = self::request();
        yield 'http' => $base->withUri($base->getUri()->withScheme('http'));
        yield 'host' => $base->withUri($base->getUri()->withHost('private.test'));
        yield 'bridge port' => $base->withUri($base->getUri()->withPort(2087));
        yield 'other path' => $base->withUri($base->getUri()->withPath('/execute/Backup/restore_files'));
        yield 'userinfo' => $base->withUri($base->getUri()->withUserInfo('private', 'secret'));
        yield 'fragment' => $base->withUri($base->getUri()->withFragment('private'));
        yield 'method' => $base->withMethod('PUT');
        yield 'form type' => $base->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        yield 'wrong length' => $base->withHeader('Content-Length', '1');
        yield 'no length' => $base->withoutHeader('Content-Length');
        yield 'duplicate length' => $base->withHeader('Content-Length', [$base->getHeaderLine('Content-Length'), '1']);
        yield 'chunked' => $base->withHeader('Transfer-Encoding', 'chunked');
        yield 'expect' => $base->withHeader('Expect', '100-continue');
        yield 'seekable body' => $base->withBody(Stream::create('private'))->withHeader('Content-Length', '7');
    }

    private static function request(): RequestInterface
    {
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'file-0', 'value' => Upload::fromString('a.bin', 'bytes')],
        ]);

        return (new Request('POST', self::URI))
            ->withBody($body)
            ->withHeader('Content-Type', $body->contentType())
            ->withHeader('Content-Length', (string) $body->getSize());
    }

    /**
     * Run a loopback server and an isolated client against it, one source mode at a time.
     *
     * @return array{
     *     client: array{exit: int, responded: bool, size: int, sha256: string, reads: int, largest_read: int,
     *         casts: int, rewinds: int, framing: array<string, bool>|null, message: string, previous: bool,
     *         client_exception: bool},
     *     server: array{connections: int, requests: list<array{head: string, bytes: int, sha256: string}>}
     * }
     */
    private static function exchange(string $mode, int $declared, string $source): array
    {
        $directory = sys_get_temp_dir() . '/streaming-curl-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory . '/source', $source);
        file_put_contents($directory . '/server.php', self::SERVER);
        file_put_contents($directory . '/client.php', self::CLIENT);
        $root = dirname(__DIR__, 3);
        $server = proc_open(
            [PHP_BINARY, $directory . '/server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $serverPipes,
        );
        self::assertIsResource($server);
        $port = fgets($serverPipes[1]);
        self::assertIsString($port);
        $client = proc_open(
            [PHP_BINARY, $directory . '/client.php', $root . '/vendor/autoload.php', trim($port), $mode,
                (string) $declared, $directory . '/source'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $clientPipes,
            $root,
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlupload',
                'WHM_API_LIVE_DOMAIN' => 'wlupload.example.com'],
        );
        self::assertIsResource($client);
        $clientOutput = stream_get_contents($clientPipes[1]);
        $clientError = stream_get_contents($clientPipes[2]);
        fclose($clientPipes[1]);
        fclose($clientPipes[2]);
        $exit = proc_close($client);
        $serverOutput = stream_get_contents($serverPipes[1]);
        $serverError = stream_get_contents($serverPipes[2]);
        fclose($serverPipes[1]);
        fclose($serverPipes[2]);
        proc_close($server);
        foreach (['source', 'server.php', 'client.php'] as $file) {
            unlink($directory . '/' . $file);
        }
        rmdir($directory);
        self::assertSame('', $clientError);
        self::assertSame('', $serverError);
        self::assertIsString($clientOutput);
        self::assertIsString($serverOutput);
        $clientResult = json_decode($clientOutput, true, 512, JSON_THROW_ON_ERROR);
        $serverResult = json_decode($serverOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($clientResult);
        self::assertIsArray($serverResult);
        $clientResult['exit'] = $exit;
        /** @var array{
         *     client: array{exit: int, responded: bool, size: int, sha256: string, reads: int, largest_read: int,
         *         casts: int, rewinds: int, framing: array<string, bool>|null, message: string, previous: bool,
         *         client_exception: bool},
         *     server: array{connections: int, requests: list<array{head: string, bytes: int, sha256: string}>}
         * } $result
         */
        $result = ['client' => $clientResult, 'server' => $serverResult];

        return $result;
    }

    /** Accept every connection in a short window, so that any retry would be counted. */
    private const SERVER = <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $number, $text);
if ($server === false) {
    exit(3);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1) . "\n");
$requests = [];
$deadline = microtime(true) + 6.0;
while (microtime(true) < $deadline) {
    $connection = @stream_socket_accept($server, $requests === [] ? 5.0 : 0.4);
    if ($connection === false) {
        if ($requests !== []) {
            break;
        }
        continue;
    }
    stream_set_timeout($connection, 2);
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n")) {
        $chunk = fread($connection, 65536);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $raw .= $chunk;
    }
    [$head, $rest] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $length = preg_match('/^content-length:\s*(\d+)/mi', $head, $match) === 1 ? (int) $match[1] : 0;
    while (strlen($rest) < $length) {
        $chunk = fread($connection, 65536);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $rest .= $chunk;
    }
    $requests[] = ['head' => $head, 'bytes' => strlen($rest), 'sha256' => hash('sha256', $rest)];
    if (strlen($rest) === $length) {
        $body = '{"status":1,"data":{}}';
        @fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . $body);
    }
    fclose($connection);
}
echo json_encode(['connections' => count($requests), 'requests' => $requests], JSON_THROW_ON_ERROR);
PHP;

    /** Replace only the target and protocol boundary; the option set under test stays the production one. */
    private const CLIENT = <<<'PHP'
<?php
namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Upload;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\StreamInterface;

require $argv[1];
$port = (int) $argv[2];
$mode = $argv[3];
$declared = (int) $argv[4];
$source = (string) file_get_contents($argv[5]);

function curl_init($uri) {
    global $port;
    $parts = parse_url($uri);
    return \curl_init('http://127.0.0.1:' . $port . $parts['path'] . '?' . $parts['query']);
}
function curl_setopt_array($handle, $options) {
    $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP;
    // Keep what the client actually sends, for comparison with what the server received.
    $read = $options[CURLOPT_READFUNCTION];
    $GLOBALS['lastBody'] = '';
    $options[CURLOPT_READFUNCTION] = static function ($curl, $stream, int $length) use ($read): string {
        $bytes = $read($curl, $stream, $length);
        $GLOBALS['lastBody'] .= $bytes;
        return $bytes;
    };
    return \curl_setopt_array($handle, $options);
}

final class ProbeStream implements StreamInterface {
    public int $reads = 0;
    public int $largest = 0;
    public int $casts = 0;
    public int $rewinds = 0;
    private int $offset = 0;
    public function __construct(private string $bytes, private int $declared, private string $mode) {}
    public function __toString(): string { ++$this->casts; return $this->bytes; }
    public function close(): void {}
    public function detach() { return null; }
    public function getSize(): int { return $this->declared; }
    public function tell(): int { return $this->offset; }
    public function eof(): bool { return $this->offset >= strlen($this->bytes); }
    public function isSeekable(): bool { return false; }
    public function seek($offset, $whence = SEEK_SET): void {
        ++$this->rewinds;
        throw new \RuntimeException('seek');
    }
    public function rewind(): void {
        ++$this->rewinds;
        throw new \RuntimeException('rewind');
    }
    public function isWritable(): bool { return false; }
    public function write($string): int { throw new \RuntimeException('write'); }
    public function isReadable(): bool { return true; }
    public function read($length): string {
        ++$this->reads;
        $this->largest = max($this->largest, $length);
        if ($this->mode === 'throwing' && $this->offset >= 65536) {
            throw new \RuntimeException('private source detail');
        }
        $bytes = substr($this->bytes, $this->offset, $length);
        $this->offset += strlen($bytes);
        return $bytes;
    }
    public function getContents(): string { ++$this->casts; return ''; }
    public function getMetadata($key = null): mixed { return $key === null ? [] : null; }
}

if ($mode === 'guarded') {
    $recipe = new FilemanUploadRecipe(
        '0123456789abcdef0123456789abcdef',
        DisposableAccount::fresh('wlupload', 'wlupload.example.com', 'wlupload-upload-fixture', 900, false),
    );
    $streaming = new StreamingCurlClient();
    $ordinary = new class implements \Psr\Http\Client\ClientInterface {
        public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
            throw new \LogicException('A multipart request reached the ordinary client.');
        }
    };
    $guard = new FilemanUploadGuard($ordinary, $streaming, 'WHMTOKEN', $recipe);
    $guard->bindToken('TEMPORARY');
    $guard->authorizeUpload('upload_raw');
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    $client = new \HostingPal\WhmApiClient\Client([
        'host' => 'test.oblak.host', 'port' => 2083, 'authType' => 'cpanel',
        'username' => 'wlupload', 'token' => 'TEMPORARY',
    ], new \HostingPal\WhmApiClient\Http\HttpTransport($guard, $factory, $factory));
    $result = ['responded' => false, 'message' => '', 'previous' => false, 'client_exception' => false];
    try {
        $call = $client->uapi()->callMultipart(
            'Fileman',
            'upload_files',
            [['name' => 'file-0', 'value' => Upload::fromString(
                $recipe->names['raw'],
                FilemanUploadRecipe::contents('raw'),
            )]],
            ['dir' => $recipe->directory, 'overwrite' => 0, 'permissions' => '0600'],
        );
        $result['responded'] = $call->metadata->uapiStatus() === 1;
    } catch (\Throwable $exception) {
        $result['message'] = $exception::class . ': ' . $exception->getMessage();
    }
    $transfer = $guard->transfer('upload_raw');
    // The boundary is random, so rebuild the expectation from the bytes the server hashed against.
    echo json_encode($result + [
        'size' => $transfer['transfer']['bytes_read'] ?? 0,
        'sha256' => $transfer === null ? '' : hash('sha256', $GLOBALS['lastBody'] ?? ''),
        'reads' => $transfer['transfer']['read_calls'] ?? 0,
        'largest_read' => $transfer['transfer']['largest_read'] ?? 0,
        'casts' => ($transfer['transfer']['buffered'] ?? true) ? 1 : 0,
        'rewinds' => ($transfer['transfer']['sought'] ?? true) ? 1 : 0,
        'framing' => $transfer['framing'] ?? null,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}
$bytes = $mode === 'short' ? substr($source, 0, 70000) : $source;
if ($mode === 'complete') {
    $multipart = StreamingMultipartEncoder::encode([
        ['name' => 'file-0', 'value' => Upload::fromString('a.bin', $bytes)],
    ]);
    $type = $multipart->contentType();
    $wire = (string) $multipart;
    $body = new ProbeStream($wire, strlen($wire), $mode);
} else {
    $type = 'multipart/form-data; boundary=loopbackboundaryvalue01';
    $wire = $bytes;
    $body = new ProbeStream($bytes, $declared, $mode);
}
$target = 'https://test.oblak.host:2083/execute/Fileman/upload_files?dir=fixture&overwrite=0';
$request = (new \Nyholm\Psr7\Request('POST', $target))
    ->withBody($body)
    ->withHeader('Authorization', 'cpanel account:private-token')
    ->withHeader('Content-Type', $type)
    ->withHeader('Content-Length', (string) $body->getSize());
$client = new StreamingCurlClient();
$result = ['responded' => false, 'message' => '', 'previous' => false, 'client_exception' => false];
try {
    $response = $client->sendRequest($request);
    $result['responded'] = $response->getStatusCode() === 200
        && (string) $response->getBody() === '{"status":1,"data":{}}';
} catch (\Throwable $exception) {
    $result['message'] = $exception->getMessage();
    $result['previous'] = $exception->getPrevious() !== null;
    $result['client_exception'] = $exception instanceof ClientExceptionInterface;
}
echo json_encode($result + [
    'size' => strlen($wire), 'sha256' => hash('sha256', $wire), 'reads' => $body->reads,
    'largest_read' => $body->largest, 'casts' => $body->casts, 'rewinds' => $body->rewinds,
    'framing' => $client->lastFraming(),
], JSON_THROW_ON_ERROR);
PHP;
}
