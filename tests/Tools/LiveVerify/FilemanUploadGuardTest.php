<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Http\StreamingMultipartBody;
use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanUploadGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanUploadRecipe;
use HostingPal\WhmApiClient\Tools\LiveVerify\StreamingTransport;
use HostingPal\WhmApiClient\Upload;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FilemanUploadGuardTest extends TestCase
{
    private const ENTROPY = '0123456789abcdef0123456789abcdef';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const BRIDGE = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wlupload&cpanel.module=Tokens'
        . '&cpanel.function=';

    /** @var array<string, string|false> */
    private array $environment = [];

    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @var list<RequestInterface> */
    private array $streamed = [];

    private FilemanUploadRecipe $recipe;

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlupload',
                'WHM_API_LIVE_DOMAIN' => 'wlupload.example.com'] as $key => $value
        ) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        $this->recipe = new FilemanUploadRecipe(self::ENTROPY, 1790000000);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testPermitsEveryExactReadAndControlRequest(): void
    {
        $guard = $this->guard(true);
        foreach ($this->approvedReads() as $request) {
            $guard->sendRequest($request);
        }
        foreach ($this->approvedControlPosts() as $request) {
            $guard->sendRequest($request);
        }
        self::assertCount(17, $this->sent);
        self::assertSame([], $this->streamed);
    }

    /** Each control mutation can pass this guard once, so nothing below it can retry one. */
    public function testEveryControlMutationIsSingleUse(): void
    {
        foreach ($this->approvedControlPosts() as $request) {
            $guard = $this->guard(true);
            $guard->sendRequest($request);
            $this->assertRefused($guard, $request);
        }
    }

    #[DataProvider('environmentRefusals')]
    public function testEveryRequestRechecksTheExactOptInsAndTarget(string $key, string $value): void
    {
        $guard = $this->guard(true);
        $reads = $this->approvedReads();
        putenv($key . '=' . $value);
        $count = count($this->sent);
        try {
            $guard->sendRequest($reads[0]);
            self::fail('Environment was not rechecked.');
        } catch (LogicException) {
            self::assertCount($count, $this->sent);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function environmentRefusals(): iterable
    {
        yield 'live disabled' => ['WHM_API_LIVE', '0'];
        yield 'mutations disabled' => ['WHM_API_LIVE_MUTATIONS', '0'];
        yield 'npr account' => ['WHM_API_LIVE_ACCOUNT', 'npr'];
        yield 'npr domain' => ['WHM_API_LIVE_DOMAIN', 'npr.rs'];
        yield 'similar account' => ['WHM_API_LIVE_ACCOUNT', 'wlupload2'];
    }

    /** @return list<RequestInterface> */
    private function approvedReads(): array
    {
        $direct = ['Host' => 'test.oblak.host:2083', 'Authorization' => 'cpanel wlupload:TEMPORARY',
            'Accept' => 'application/json'];
        $requests = [];
        foreach (
            ['version?api.version=1', 'listaccts?api.version=1&want=user%2Cdomain%2Cemail', 'listzones?api.version=1',
                'get_domain_info?api.version=1'] as $path
        ) {
            $requests[] = new Request('GET', self::WHM . $path, self::whmHeaders());
        }
        $requests[] = new Request('GET', self::BRIDGE . 'list', self::whmHeaders());
        $requests[] = new Request('GET', $this->recipe->homeListUri(), $direct);
        $requests[] = new Request('GET', $this->recipe->directoryListUri(), $direct);
        foreach (FilemanUploadRecipe::LABELS as $label) {
            $requests[] = new Request('GET', $this->recipe->readUri($label), $direct);
        }

        return $requests;
    }

    /** @return array{Host: string, Authorization: string, Accept: string} */
    private static function whmHeaders(): array
    {
        return ['Host' => 'test.oblak.host:2087', 'Authorization' => 'whm root:WHMTOKEN',
            'Accept' => 'application/json'];
    }

    /** @return list<RequestInterface> */
    private function approvedControlPosts(): array
    {
        $form = self::whmHeaders() + ['Content-Type' => 'application/x-www-form-urlencoded'];
        $create = 'username=wlupload&domain=wlupload.example.com&contactemail=upload-' . self::ENTROPY
            . '%40wlupload.example.com&quota=64&forcedns=0&hasshell=0&ip=n';

        return [
            new Request('POST', self::WHM . 'createacct?api.version=1', $form, $create),
            new Request('POST', self::WHM . 'removeacct?api.version=1', $form, 'username=wlupload&keepdns=0'),
            new Request(
                'POST',
                self::BRIDGE . 'create_full_access',
                $form,
                'name=wlupload-upload-fixture&expires_at=1790000000',
            ),
            new Request('POST', self::BRIDGE . 'revoke', $form, 'name=wlupload-upload-fixture'),
        ];
    }

    #[DataProvider('refusedRequests')]
    public function testRefusesRequestsOutsideTheRecipeBeforeReachingAnyTransport(
        string $method,
        string $uri,
        string $body,
        string $authorization,
    ): void {
        $host = (string) parse_url($uri, PHP_URL_HOST) . ':' . (string) parse_url($uri, PHP_URL_PORT);
        $headers = ['Host' => $host, 'Authorization' => $authorization, 'Accept' => 'application/json'];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $this->assertRefused($this->guard(true), new Request($method, $uri, $headers, $body));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function refusedRequests(): iterable
    {
        $root = 'whm root:WHMTOKEN';
        $temporary = 'cpanel wlupload:TEMPORARY';
        $direct = 'https://test.oblak.host:2083/execute/';
        yield 'other account create' => ['POST', self::WHM . 'createacct?api.version=1',
            'username=npr&domain=npr.rs&contactemail=x&quota=64&forcedns=0&hasshell=0&ip=n', $root];
        yield 'remove npr' => ['POST', self::WHM . 'removeacct?api.version=1', 'username=npr&keepdns=0', $root];
        yield 'keep dns on remove' => ['POST', self::WHM . 'removeacct?api.version=1',
            'username=wlupload&keepdns=1', $root];
        yield 'extra create parameter' => ['POST', self::WHM . 'createacct?api.version=1',
            'username=wlupload&domain=wlupload.example.com&contactemail=upload-' . self::ENTROPY
                . '%40wlupload.example.com&quota=64&forcedns=1&hasshell=0&ip=n', $root];
        yield 'other marker' => ['POST', self::WHM . 'createacct?api.version=1',
            'username=wlupload&domain=wlupload.example.com&contactemail=x%40wlupload.example.com'
                . '&quota=64&forcedns=0&hasshell=0&ip=n', $root];
        yield 'bridge to npr' => ['POST', str_replace('wlupload', 'npr', self::BRIDGE) . 'revoke',
            'name=wlupload-upload-fixture', $root];
        yield 'revoke control token' => ['POST', self::BRIDGE . 'revoke', 'name=other-token', $root];
        yield 'readonly token' => ['POST', self::BRIDGE . 'create_full_access',
            'name=wlupload-upload-fixture&expires_at=1790000000&readonly=1', $root];
        yield 'token expiry' => ['POST', self::BRIDGE . 'create_full_access',
            'name=wlupload-upload-fixture&expires_at=1999999999', $root];
        yield 'other WHM function' => ['GET', self::WHM . 'accountsummary?api.version=1&user=npr', '', $root];
        yield 'filtered accounts' => ['GET', self::WHM . 'listaccts?api.version=1&search=wlupload', '', $root];
        yield 'extra WHM parameter' => ['GET', self::WHM . 'version?api.version=1&x=1', '', $root];
        yield 'GET with body' => ['GET', self::WHM . 'version?api.version=1', 'x=1', $root];
        yield 'WHM with temporary token' => ['GET', self::WHM . 'version?api.version=1', '', $temporary];
        yield 'direct with root token' => ['GET',
            $direct . 'Fileman/list_files?dir=%2Fhome%2Fwlupload&types=dir%7Cfile', '', $root];
        yield 'other account home' => ['GET',
            $direct . 'Fileman/list_files?dir=%2Fhome%2Fnpr&types=dir%7Cfile', '', $temporary];
        yield 'public_html listing' => ['GET', $direct . 'Fileman/list_files?dir=public_html', '', $temporary];
        yield 'read another file' => ['GET',
            $direct . 'Fileman/get_file_content?dir=%2Fhome%2Fwlupload&file=.my.cnf', '', $temporary];
        yield 'unlisted direct delete' => ['POST', $direct . 'Fileman/delete_file', 'path=public_html', $temporary];
        yield 'empty trash' => ['POST', $direct . 'Fileman/empty_trash', '', $temporary];
        yield 'other host' => ['GET', 'https://other.example:2087/json-api/version?api.version=1', '', $root];
        yield 'plain http' => ['GET', 'http://test.oblak.host:2087/json-api/version?api.version=1', '', $root];
        yield 'put method' => ['PUT', self::WHM . 'version?api.version=1', '', $root];
    }

    public function testDirectRequestsRequireTheBoundTemporaryToken(): void
    {
        $this->assertRefused($this->guard(false), new Request('GET', $this->recipe->homeListUri(), [
            'Host' => 'test.oblak.host:2083', 'Authorization' => 'cpanel wlupload:', 'Accept' => 'application/json',
        ]));
    }

    public function testExtraHeadersAreRefused(): void
    {
        $headers = self::whmHeaders() + ['X-Other' => 'value'];
        $this->assertRefused($this->guard(true), new Request('GET', self::WHM . 'version?api.version=1', $headers));
    }

    public function testTemporaryTokenBindsOnceAndRejectsHeaderControlCharacters(): void
    {
        $guard = $this->guard(false);
        foreach (['', "A\r\nX: 1", 'A B'] as $token) {
            try {
                $guard->bindToken($token);
                self::fail('Unusable token was bound.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
        $guard->bindToken('TEMPORARY');
        $this->expectException(LogicException::class);
        $guard->bindToken('ANOTHER');
    }

    public function testDeleteNeedsAnExactOwnedSingleUseAuthorization(): void
    {
        $guard = $this->guard(true);
        $path = $this->recipe->relativePath('bytes');
        $request = $this->deleteRequest($path);
        $this->assertRefused($guard, $request);
        $guard->authorizeDelete($path);
        $guard->sendRequest($request);
        self::assertCount(1, $this->sent);
        $this->assertRefused($guard, $request);
        $this->expectException(LogicException::class);
        $guard->authorizeDelete($path);
    }

    public function testDeleteAuthorizationCoversOnlyItsOwnPath(): void
    {
        $guard = $this->guard(true);
        $guard->authorizeDelete($this->recipe->relativePath('bytes'));
        $this->assertRefused($guard, $this->deleteRequest($this->recipe->relativePath('raw')));
    }

    #[DataProvider('foreignPaths')]
    public function testDeleteAuthorizationRefusesEveryPathOutsideTheOwnedSet(string $path): void
    {
        $this->expectException(LogicException::class);
        $this->guard(true)->authorizeDelete($path);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignPaths(): iterable
    {
        yield 'home' => ['/home/wlupload'];
        yield 'dot' => ['.'];
        yield 'webroot' => ['public_html'];
        yield 'parent traversal' => ['wlupload-fixture-' . self::ENTROPY . '/../public_html'];
        yield 'nested guess' => ['wlupload-fixture-' . self::ENTROPY . '/other.bin'];
        yield 'other run' => ['wlupload-fixture-00000000000000000000000000000000'];
        yield 'empty' => [''];
    }

    public function testExactUploadStreamsThroughTheStreamingTransportOnly(): void
    {
        $guard = $this->guard(true);
        $guard->authorizeUpload('upload_raw');
        $guard->sendRequest($this->uploadRequest('upload_raw', [['file-0', $this->recipe->names['raw'],
            FilemanUploadRecipe::contents('raw')]]));
        self::assertCount(1, $this->streamed);
        self::assertSame([], $this->sent);
        self::assertSame(
            hash('sha256', $this->recipe->expectedBody('upload_raw', 'guard-test-boundary-000000')),
            hash('sha256', $this->lastStreamedBody),
        );
        $transfer = $guard->transfer('upload_raw');
        self::assertNotNull($transfer);
        self::assertTrue($transfer['transfer']['body_exact']);
        self::assertFalse($transfer['transfer']['buffered']);
        self::assertFalse($transfer['transfer']['sought']);
        self::assertGreaterThan(0, $transfer['transfer']['read_calls']);
    }

    public function testUploadIsSingleUseAndNeedsAuthorization(): void
    {
        $guard = $this->guard(true);
        $parts = [['file-0', $this->recipe->names['raw'], FilemanUploadRecipe::contents('raw')]];
        $this->assertRefused($guard, $this->uploadRequest('upload_raw', $parts));
        $guard->authorizeUpload('upload_raw');
        $guard->sendRequest($this->uploadRequest('upload_raw', $parts));
        $this->assertRefused($guard, $this->uploadRequest('upload_raw', $parts));
        $this->expectException(LogicException::class);
        $guard->authorizeUpload('upload_raw');
    }

    /** @param list<array{string, string, string}> $parts */
    #[DataProvider('wrongBodies')]
    public function testDifferingBodyAbortsWhileStreamingBeforeItsClosingDelimiter(array $parts): void
    {
        $guard = $this->guard(true);
        $guard->authorizeUpload('upload_raw');
        try {
            $guard->sendRequest($this->uploadRequest('upload_raw', $parts));
            self::fail('A differing body passed.');
        } catch (LogicException) {
            self::assertStringEndsNotWith("--\r\n", $this->lastStreamedBody);
        }
    }

    /** @return iterable<string, array{list<array{string, string, string}>}> */
    public static function wrongBodies(): iterable
    {
        $name = 'raw-0123456789abcdef.bin';
        $same = str_repeat("raw\x00\xc3\x28", 50);
        yield 'one byte differs, same length' => [[['file-0', $name, substr($same, 0, -1) . 'X']]];
        yield 'other filename, same length' => [[['file-0', 'XXX-0123456789abcdef.bin', $same]]];
        yield 'other part name, same length' => [[['file-1', $name, $same]]];
    }

    /** @param list<array{string, string, string}> $parts */
    #[DataProvider('wrongLengths')]
    public function testDifferentLengthIsRefusedBeforeAnyByteIsSent(array $parts): void
    {
        $guard = $this->guard(true);
        $guard->authorizeUpload('upload_raw');
        $this->assertRefused($guard, $this->uploadRequest('upload_raw', $parts));
        self::assertSame([], $this->streamed);
    }

    /** @return iterable<string, array{list<array{string, string, string}>}> */
    public static function wrongLengths(): iterable
    {
        $name = 'raw-0123456789abcdef.bin';
        yield 'truncated' => [[['file-0', $name, 'raw']]];
        yield 'extra part' => [[
            ['file-0', $name, str_repeat("raw\x00\xc3\x28", 50)],
            ['file-1', $name . '2', 'more'],
        ]];
    }

    public function testRefusesUploadQueryThatEnablesOverwriteOrChangesDirectory(): void
    {
        $parts = [['file-0', $this->recipe->names['raw'], FilemanUploadRecipe::contents('raw')]];
        $exact = $this->recipe->uploadUri('upload_raw');
        foreach (
            [
                str_replace('overwrite=0', 'overwrite=1', $exact),
                str_replace('permissions=0600', 'permissions=0777', $exact),
                str_replace('dir=' . $this->recipe->directory, 'dir=public_html', $exact),
                $exact . '&extra=1',
            ] as $uri
        ) {
            $guard = $this->guard(true);
            $guard->authorizeUpload('upload_raw');
            $this->assertRefused($guard, $this->uploadRequest('upload_raw', $parts, $uri));
        }
    }

    public function testRefusesPlainStringAndChunkedMultipartBodies(): void
    {
        $parts = [['file-0', $this->recipe->names['raw'], FilemanUploadRecipe::contents('raw')]];
        $guard = $this->guard(true);
        $guard->authorizeUpload('upload_raw');
        $request = $this->uploadRequest('upload_raw', $parts);
        $string = Stream::create($this->recipe->expectedBody('upload_raw', 'guard-test-boundary-000000'));
        $this->assertRefused($guard, $request->withBody($string));

        $guard = $this->guard(true);
        $guard->authorizeUpload('upload_raw');
        $chunked = $this->uploadRequest('upload_raw', $parts)->withHeader('Transfer-Encoding', 'chunked');
        $this->assertRefused($guard, $chunked);
        self::assertSame([], $this->streamed);
    }

    public function testRefusesPassingAWrongCredentialOnAnUpload(): void
    {
        $parts = [['file-0', $this->recipe->names['raw'], FilemanUploadRecipe::contents('raw')]];
        $guard = $this->guard(true);
        $guard->authorizeUpload('upload_raw');
        $this->assertRefused(
            $guard,
            $this->uploadRequest('upload_raw', $parts)->withHeader('Authorization', 'whm root:WHMTOKEN'),
        );
    }

    public function testPendingAuthorizationCannotBeSpentOnAnotherKindOfRequest(): void
    {
        $guard = $this->guard(true);
        $guard->authorizeUpload('upload_raw');
        $this->assertRefused($guard, $this->approvedReads()[0]);
    }

    public function testDiagnosticsExposeNoCredentialsOrIdentifiers(): void
    {
        $guard = $this->guard(true);
        self::assertSame([], (array) $guard->__debugInfo());
        self::assertSame([], (array) $this->recipe->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($guard);
    }

    private string $lastStreamedBody = '';

    private function guard(bool $bound): FilemanUploadGuard
    {
        $test = $this;
        $http = new class ($test) implements ClientInterface {
            public function __construct(private readonly FilemanUploadGuardTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->recordOrdinary($request);

                return new Response(200);
            }
        };
        $streaming = new class ($test) implements StreamingTransport {
            public function __construct(private readonly FilemanUploadGuardTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->recordStreamed($request);

                return new Response(200);
            }

            public function lastFraming(): ?array
            {
                return null;
            }
        };
        $guard = new FilemanUploadGuard($http, $streaming, 'WHMTOKEN', $this->recipe);
        if ($bound) {
            $guard->bindToken('TEMPORARY');
        }

        return $guard;
    }

    public function recordOrdinary(RequestInterface $request): void
    {
        $this->sent[] = $request;
    }

    /** Pull the body the way a transport does: incrementally, keeping what crossed the wire. */
    public function recordStreamed(RequestInterface $request): void
    {
        $this->streamed[] = $request;
        $this->lastStreamedBody = '';
        $body = $request->getBody();
        while (!$body->eof()) {
            $bytes = $body->read(7);
            if ($bytes === '') {
                break;
            }
            $this->lastStreamedBody .= $bytes;
        }
    }

    private function assertRefused(FilemanUploadGuard $guard, RequestInterface $request): void
    {
        $sent = count($this->sent);
        try {
            $guard->sendRequest($request);
            self::fail('The guard passed a request outside its boundary.');
        } catch (LogicException $exception) {
            self::assertSame('Request outside the Fileman upload fixture boundary.', $exception->getMessage());
        }
        self::assertCount($sent, $this->sent);
    }

    private function deleteRequest(string $path): RequestInterface
    {
        return new Request('POST', $this->recipe->deleteUri(), [
            'Host' => 'test.oblak.host:2083', 'Authorization' => 'cpanel wlupload:TEMPORARY',
            'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded',
        ], 'path=' . rawurlencode($path));
    }

    /**
     * Build the request HttpTransport would produce from a real streaming body.
     *
     * @param 'upload_raw' $phase
     * @param list<array{string, string, string}> $parts
     */
    private function uploadRequest(string $phase, array $parts, ?string $uri = null): RequestInterface
    {
        $records = [];
        foreach ($parts as [$field, $filename, $bytes]) {
            $records[] = ['name' => $field, 'value' => Upload::fromString($filename, $bytes)];
        }
        $body = StreamingMultipartEncoder::encode($records, static fn (): string => 'guard-test-boundary-000000');
        self::assertInstanceOf(StreamingMultipartBody::class, $body);

        return (new Request('POST', $uri ?? $this->recipe->uploadUri($phase), [
            'Host' => 'test.oblak.host:2083', 'Authorization' => 'cpanel wlupload:TEMPORARY',
            'Accept' => 'application/json',
        ]))->withBody($body)
            ->withHeader('Content-Type', $body->contentType())
            ->withHeader('Content-Length', (string) $body->getSize());
    }
}
