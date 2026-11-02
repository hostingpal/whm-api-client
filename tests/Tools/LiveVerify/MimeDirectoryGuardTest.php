<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Http\StreamingMultipartBody;
use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeDirectoryGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeDirectoryRecipe;
use HostingPal\WhmApiClient\Tools\LiveVerify\StreamingTransport;
use HostingPal\WhmApiClient\Upload;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MimeDirectoryGuardTest extends TestCase
{
    private const ENTROPY = '0123456789abcdef0123456789abcdef';

    /** @var array<string, string|false> */
    private array $environment = [];

    private MimeDirectoryRecipe $recipe;

    /** @var list<RequestInterface> */
    private array $sent = [];

    private int $streamed = 0;

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlmime',
                'WHM_API_LIVE_DOMAIN' => 'wlmime.example.com'] as $key => $value
        ) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        $this->recipe = new MimeDirectoryRecipe(self::ENTROPY, DisposableAccount::fresh(
            'wlmime',
            'wlmime.example.com',
            'wlmime-fixture',
            900,
            false,
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testEveryPhaseAdmitsExactlyItsOwnRequestOnce(): void
    {
        $guard = $this->guard();
        foreach (array_keys(MimeDirectoryRecipe::PHASES) as $phase) {
            if ($phase === 'create_directory') {
                continue;
            }
            $guard->authorize($phase);
            $guard->sendRequest($this->request($phase));
        }
        self::assertCount(count(MimeDirectoryRecipe::PHASES) - 1, $this->sent);
        $this->expectException(LogicException::class);
        $guard->authorize('add_mime_direct');
    }

    public function testAPhaseRequestWithoutItsAuthorizationIsRefused(): void
    {
        $this->assertRefused($this->guard(), $this->request('add_mime_direct'));
    }

    public function testAuthorizationCoversOnlyTheNextRequest(): void
    {
        $guard = $this->guard();
        $guard->authorize('mime_types_baseline');
        $guard->sendRequest($this->request('mime_types_baseline'));
        $this->assertRefused($guard, $this->request('mime_types_baseline'));
    }

    /** A refused request still consumes its phase, so nothing below the guard can try it again. */
    public function testARefusedPhaseCannotBeAuthorizedAgain(): void
    {
        $guard = $this->guard();
        $guard->authorize('add_mime_direct');
        $this->assertRefused($guard, $this->request('add_mime_direct', body: 'extension=.other&type=text%2Fother'));
        $this->expectException(LogicException::class);
        $guard->authorize('add_mime_direct');
    }

    public function testAnUnknownPhaseIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->guard()->authorize('add_mime_elsewhere');
    }

    /** @param array{method?: string, uri?: string, body?: string, authorization?: string} $change */
    #[DataProvider('alterations')]
    public function testAnyDifferenceFromTheAuthorizedRequestIsRefused(string $phase, array $change): void
    {
        $guard = $this->guard();
        $guard->authorize($phase);
        $this->assertRefused($guard, $this->request($phase, ...$change));
    }

    /** @return iterable<string, array{string, array{method?: string, uri?: string, body?: string, authorization?: string}}> */
    public static function alterations(): iterable
    {
        yield 'other method' => ['add_mime_direct', ['method' => 'GET']];
        yield 'other body' => ['delete_mime_bridged', ['body' => 'type=text%2Fplain']];
        yield 'extra field' => ['delete_hotlink_direct', ['body' => 'domain=other.example.com']];
        yield 'other account by bridge' => ['mime_types_after_delete_bridged', ['uri' =>
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr&cpanel.module=Mime'
                . '&cpanel.function=list_mime&type=user']];
        yield 'other direct account' => ['mime_types_baseline', ['authorization' => 'cpanel npr:TEMPORARY']];
        yield 'other directory' => ['set_indexing_direct', ['body' => 'dir=%2Fhome%2Fwlmime&type=disabled']];
        yield 'other user' => ['delete_user_direct', ['body' => 'dir=x&user=other']];
    }

    public function testControlRequestsPassOutsideEveryPhaseAndEachControlMutationOnlyOnce(): void
    {
        $guard = $this->guard();
        foreach ($this->recipe->account->controlReads() as $uri) {
            $guard->sendRequest(self::root('GET', $uri));
        }
        $posts = $this->recipe->account->controlPosts();
        foreach ($posts as $uri => $body) {
            $guard->sendRequest(self::root('POST', $uri, $body));
        }
        self::assertCount(count($posts) + 4, $this->sent);
        foreach ($posts as $uri => $body) {
            $this->assertRefused($guard, self::root('POST', $uri, $body));
        }
    }

    public function testADirectRequestNeedsTheBoundToken(): void
    {
        $guard = $this->guard(false);
        $guard->authorize('mime_types_baseline');
        $this->assertRefused($guard, $this->request('mime_types_baseline'));
    }

    public function testTheTokenBindsOnceAndRefusesHeaderCharacters(): void
    {
        $guard = $this->guard(false);
        try {
            $guard->bindToken("TEMP\nORARY");
            $refused = false;
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        $guard->bindToken('TEMPORARY');
        $this->expectException(LogicException::class);
        $guard->bindToken('TEMPORARY');
    }

    public function testAMissingOptInRefusesEvenAnAuthorizedRequest(): void
    {
        $guard = $this->guard();
        $guard->authorize('mime_types_baseline');
        putenv('WHM_API_LIVE_ACCOUNT=npr');
        $refused = false;
        try {
            $guard->sendRequest($this->request('mime_types_baseline'));
        } catch (LogicException) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertSame([], $this->sent);
    }

    public function testTheUploadStreamsOnlyInItsPhaseAndOnlyToTheStreamingTransport(): void
    {
        $guard = $this->guard();
        $this->assertRefused($guard, $this->upload($this->recipe->uploadName, MimeDirectoryRecipe::UPLOAD_BYTES));
        $guard->authorize('create_directory');
        $guard->sendRequest($this->upload($this->recipe->uploadName, MimeDirectoryRecipe::UPLOAD_BYTES));
        self::assertSame(1, $this->streamed);
        self::assertSame([], $this->sent);
        $transfer = $guard->transfer();
        self::assertNotNull($transfer);
        self::assertTrue($transfer['transfer']['body_exact']);
    }

    public function testAnUploadOfOtherBytesStopsBeforeItsClosingDelimiter(): void
    {
        $guard = $this->guard();
        $guard->authorize('create_directory');
        $other = 'other bytes of the same length here!!!!!!';
        self::assertSame(strlen(MimeDirectoryRecipe::UPLOAD_BYTES), strlen($other));
        $stopped = false;
        try {
            $guard->sendRequest($this->upload($this->recipe->uploadName, $other));
        } catch (LogicException) {
            $stopped = true;
        }
        self::assertTrue($stopped);
        $transfer = $guard->transfer();
        self::assertNotNull($transfer);
        self::assertFalse($transfer['transfer']['body_exact']);
    }

    public function testAMultipartBodyOutsideTheUploadPhaseIsRefused(): void
    {
        $guard = $this->guard();
        $guard->authorize('add_mime_direct');
        $this->assertRefused($guard, $this->upload($this->recipe->uploadName, MimeDirectoryRecipe::UPLOAD_BYTES));
        self::assertSame(0, $this->streamed);
    }

    public function recordOrdinary(RequestInterface $request): void
    {
        $this->sent[] = $request;
    }

    public function recordStreamed(RequestInterface $request): void
    {
        ++$this->streamed;
        $body = $request->getBody();
        while (!$body->eof()) {
            $body->read(8192);
        }
    }

    private function assertRefused(MimeDirectoryGuard $guard, RequestInterface $request): void
    {
        $before = count($this->sent);
        $message = null;
        try {
            $guard->sendRequest($request);
        } catch (LogicException $refusal) {
            $message = $refusal->getMessage();
        }
        self::assertSame('Request outside the Mime directory fixture boundary.', $message);
        self::assertCount($before, $this->sent);
    }

    private function request(
        string $phase,
        ?string $method = null,
        ?string $uri = null,
        ?string $body = null,
        ?string $authorization = null,
    ): RequestInterface {
        $wire = $this->recipe->wire($phase);
        $direct = $wire['route'] === 'direct-uapi';
        $method ??= $wire['method'];
        $headers = [
            'Host' => 'test.oblak.host:' . ($direct ? '2083' : '2087'),
            'Authorization' => $authorization ?? ($direct ? 'cpanel wlmime:TEMPORARY' : 'whm root:WHMTOKEN'),
            'Accept' => 'application/json',
        ];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return new Request($method, $uri ?? $wire['uri'], $headers, $body ?? $wire['body']);
    }

    private static function root(string $method, string $uri, string $body = ''): RequestInterface
    {
        $headers = ['Host' => 'test.oblak.host:2087', 'Authorization' => 'whm root:WHMTOKEN',
            'Accept' => 'application/json'];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return new Request($method, $uri, $headers, $body);
    }

    private function upload(string $filename, string $bytes): RequestInterface
    {
        $body = StreamingMultipartEncoder::encode(
            [['name' => 'file-0', 'value' => Upload::fromString($filename, $bytes)]],
            static fn (): string => 'guard-test-boundary-000000',
        );
        self::assertInstanceOf(StreamingMultipartBody::class, $body);

        return (new Request('POST', $this->recipe->wire('create_directory')['uri'], [
            'Host' => 'test.oblak.host:2083', 'Authorization' => 'cpanel wlmime:TEMPORARY',
            'Accept' => 'application/json',
        ]))->withBody($body)
            ->withHeader('Content-Type', $body->contentType())
            ->withHeader('Content-Length', (string) $body->getSize());
    }

    private function guard(bool $bound = true): MimeDirectoryGuard
    {
        $http = new class ($this) implements ClientInterface {
            public function __construct(private readonly MimeDirectoryGuardTest $test)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->recordOrdinary($request);

                return new Response(200);
            }
        };
        $streaming = new class ($this) implements StreamingTransport {
            public function __construct(private readonly MimeDirectoryGuardTest $test)
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
        $guard = new MimeDirectoryGuard($http, $streaming, 'WHMTOKEN', $this->recipe);
        if ($bound) {
            $guard->bindToken('TEMPORARY');
        }

        return $guard;
    }
}
