<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\CurlClient;
use LogicException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class CurlClientTest extends TestCase
{
    private string|false $originalOptIn;

    protected function setUp(): void
    {
        $this->originalOptIn = getenv('WHM_API_LIVE');
    }

    protected function tearDown(): void
    {
        putenv($this->originalOptIn === false ? 'WHM_API_LIVE' : 'WHM_API_LIVE=' . $this->originalOptIn);
    }

    public function testRefusesNetworkWithoutExplicitOptIn(): void
    {
        putenv('WHM_API_LIVE=0');
        $this->expectException(LogicException::class);

        (new CurlClient())->sendRequest(new Request('GET', 'https://test.oblak.host:2087/json-api/version'));
    }

    #[DataProvider('outsideBoundary')]
    public function testRejectsUnsafeTransportTargetsBeforeNetwork(string $method, string $uri, string $body): void
    {
        putenv('WHM_API_LIVE=1');
        $this->expectException(LogicException::class);

        (new CurlClient())->sendRequest(new Request($method, $uri, [], $body));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function outsideBoundary(): iterable
    {
        yield 'http' => ['GET', 'http://test.oblak.host:2087/json-api/version', ''];
        yield 'other host' => ['GET', 'https://private.test:2087/json-api/version', ''];
        yield 'other port' => ['GET', 'https://test.oblak.host:443/json-api/version', ''];
        yield 'no explicit port' => ['GET', 'https://test.oblak.host/json-api/version', ''];
        yield 'userinfo' => ['GET', 'https://private:secret@test.oblak.host:2087/json-api/version', ''];
        yield 'fragment' => ['GET', 'https://test.oblak.host:2087/json-api/version#private', ''];
        yield 'post' => ['POST', 'https://test.oblak.host:2087/json-api/version', ''];
        yield 'body' => ['GET', 'https://test.oblak.host:2087/json-api/version', 'private=secret'];
    }

    #[DataProvider('fixtureRoutes')]
    public function testSendsOptedInFormPostBytesWithUnchangedAccountTarget(string $uri): void
    {
        $result = self::sendWithCurlDouble(
            new Request('POST', $uri, [
                'Authorization' => 'cpanel npr:synthetic-token',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], 'name=npr_fixture&password=synthetic%2Bsecret%26%3D'),
            true,
        );

        self::assertSame([
            'initialized' => 1,
            'executed' => 1,
            'uri' => $uri,
            'get' => false,
            'post' => true,
            'body' => 'name=npr_fixture&password=synthetic%2Bsecret%26%3D',
            'headers' => [
                'Authorization: cpanel npr:synthetic-token',
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            'return_transfer' => true,
            'follow_redirects' => false,
            'verify_peer' => true,
            'verify_host' => 2,
            'connect_timeout' => 10,
            'timeout' => 30,
            'protocols' => CURLPROTO_HTTPS,
            'response' => [201, ['X-Probe' => ['first', 'second']], 'synthetic response', '1.1', 'Created'],
        ], $result);
    }

    public function testMultipartOptInPreservesBoundaryAndRawBodyWithoutFormEncoding(): void
    {
        $type = 'multipart/form-data; boundary=whm-api-fixture-boundary';
        $body = "--whm-api-fixture-boundary\r\nContent-Disposition: form-data; name=\"key\"\r\n\r\n"
            . "private+&=\r\n--whm-api-fixture-boundary--\r\n";
        $result = self::sendWithCurlDouble(
            new Request(
                'POST',
                'https://test.oblak.host:2083/execute/SSL/upload_key',
                ['Content-Type' => $type],
                $body,
            ),
            false,
            allowMultipartPost: true,
        );
        self::assertIsArray($result);
        self::assertSame(1, $result['executed']);
        self::assertSame($body, $result['body']);
        self::assertSame(['Authorization: ', 'Accept: application/json', 'Content-Type: ' . $type], $result['headers']);
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureRoutes(): iterable
    {
        yield 'direct' => ['https://test.oblak.host:2083/execute/Mysql/create_database'];
        yield 'bridge' => [
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Mysql&cpanel.function=create_database',
        ];
    }

    #[DataProvider('formPostRefusals')]
    public function testRejectsUnapprovedFormPostsBeforeInitializingCurl(
        ?bool $allowFormPost,
        string $live,
        string $mutations,
        string $contentType,
    ): void {
        $result = self::sendWithCurlDouble(
            new Request('POST', 'https://test.oblak.host:2083/execute/Mysql/create_database', [
                'Content-Type' => $contentType,
            ], 'name=npr_fixture'),
            $allowFormPost,
            $live,
            $mutations,
        );

        self::assertSame([
            'initialized' => 0,
            'executed' => 0,
            'exception' => LogicException::class,
            'message' => $live === '1'
                ? 'Request outside the live transport boundary.'
                : 'Live verification requires WHM_API_LIVE=1.',
            'previous' => false,
        ], $result);
    }

    /** @return iterable<string, array{?bool, string, string, string}> */
    public static function formPostRefusals(): iterable
    {
        yield 'constructor default' => [null, '1', '1', 'application/x-www-form-urlencoded'];
        yield 'constructor disabled' => [false, '1', '1', 'application/x-www-form-urlencoded'];
        yield 'live disabled' => [true, '0', '1', 'application/x-www-form-urlencoded'];
        yield 'mutations disabled' => [true, '1', '0', 'application/x-www-form-urlencoded'];
        yield 'mutations empty' => [true, '1', '', 'application/x-www-form-urlencoded'];
        yield 'mutations nonliteral opt-in' => [true, '1', 'true', 'application/x-www-form-urlencoded'];
        yield 'missing content type' => [true, '1', '1', ''];
        yield 'json content type' => [true, '1', '1', 'application/json'];
        yield 'multipart content type' => [true, '1', '1', 'multipart/form-data'];
        yield 'form with charset' => [true, '1', '1', 'application/x-www-form-urlencoded; charset=UTF-8'];
        yield 'duplicate content type' => [
            true,
            '1',
            '1',
            'application/x-www-form-urlencoded, application/x-www-form-urlencoded',
        ];
    }

    #[DataProvider('unsafeOptedInRequests')]
    public function testFormPostOptionDoesNotWidenTargetOrGetBodyBoundary(
        string $method,
        string $uri,
        string $body,
    ): void {
        self::assertSame([
            'initialized' => 0,
            'executed' => 0,
            'exception' => LogicException::class,
            'message' => 'Request outside the live transport boundary.',
            'previous' => false,
        ], self::sendWithCurlDouble(
            new Request($method, $uri, ['Content-Type' => 'application/x-www-form-urlencoded'], $body),
            true,
        ));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unsafeOptedInRequests(): iterable
    {
        yield 'post http' => ['POST', 'http://test.oblak.host:2087/json-api/uapi_cpanel', 'name=npr_fixture'];
        yield 'post other host' => ['POST', 'https://private.test:2087/json-api/uapi_cpanel', 'name=npr_fixture'];
        yield 'post other port' => ['POST', 'https://test.oblak.host:443/json-api/uapi_cpanel', 'name=npr_fixture'];
        yield 'post implicit port' => ['POST', 'https://test.oblak.host/json-api/uapi_cpanel', 'name=npr_fixture'];
        yield 'post userinfo' => [
            'POST',
            'https://private:secret@test.oblak.host:2087/json-api/uapi_cpanel',
            'name=npr_fixture',
        ];
        yield 'post fragment' => [
            'POST',
            'https://test.oblak.host:2087/json-api/uapi_cpanel#private',
            'name=npr_fixture',
        ];
        yield 'get body' => ['GET', 'https://test.oblak.host:2083/execute/Mysql/list_users', 'name=npr_fixture'];
        yield 'put' => ['PUT', 'https://test.oblak.host:2083/execute/Mysql/create_user', 'name=npr_fixture'];
        yield 'head' => ['HEAD', 'https://test.oblak.host:2083/execute/Mysql/list_users', ''];
    }

    #[DataProvider('getModes')]
    public function testGetNeedsNoMutationOptInAndSendsNoFormBody(bool $allowFormPost): void
    {
        $uri = 'https://test.oblak.host:2083/execute/Mysql/list_users';
        self::assertSame([
            'initialized' => 1,
            'executed' => 1,
            'uri' => $uri,
            'get' => true,
            'post' => false,
            'body' => null,
            'headers' => ['Authorization: cpanel npr:synthetic-token', 'Accept: application/json'],
            'return_transfer' => true,
            'follow_redirects' => false,
            'verify_peer' => true,
            'verify_host' => 2,
            'connect_timeout' => 10,
            'timeout' => 30,
            'protocols' => CURLPROTO_HTTPS,
            'response' => [201, ['X-Probe' => ['first', 'second']], 'synthetic response', '1.1', 'Created'],
        ], self::sendWithCurlDouble(
            new Request('GET', $uri, ['Authorization' => 'cpanel npr:synthetic-token']),
            $allowFormPost,
            '1',
            '0',
        ));
    }

    /** @return iterable<string, array{bool}> */
    public static function getModes(): iterable
    {
        yield 'default' => [false];
        yield 'fixture option enabled' => [true];
    }

    public function testPostTransportFailureIsNotRetriedAndExposesNoPrivateCause(): void
    {
        self::assertSame([
            'initialized' => 1,
            'executed' => 1,
            'exception' => ClientExceptionInterface::class,
            'message' => 'Live HTTPS transport failed.',
            'previous' => false,
        ], self::sendWithCurlDouble(
            new Request('POST', 'https://test.oblak.host:2083/execute/Mysql/create_user', [
                'Authorization' => 'cpanel npr:synthetic-token',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], 'name=npr_fixture&password=synthetic-secret'),
            true,
            failExecution: true,
        ));
    }

    /**
     * Isolate cURL boundary doubles so the suite never replaces global functions
     * or opens sockets. Exercise the actual transport and its response callback.
     *
     * @return mixed
     */
    private static function sendWithCurlDouble(
        Request $request,
        ?bool $allowFormPost,
        string $live = '1',
        string $mutations = '1',
        bool $failExecution = false,
        bool $allowMultipartPost = false,
    ): mixed {
        $code = <<<'PHP'
namespace HostingPal\WhmApiClient\Tools\LiveVerify;

require $argv[1];
$input = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$initialized = 0;
$executed = 0;
$options = [];
$target = null;
function curl_init($uri) {
    global $initialized, $target;
    ++$initialized;
    $target = $uri;
    return \curl_init();
}
function curl_setopt_array($handle, $settings) {
    global $options;
    $options = $settings;
    return true;
}
function curl_exec($handle) {
    global $executed, $options, $input;
    ++$executed;
    if ($input['fail_execution']) {
        throw new \RuntimeException('synthetic-token synthetic-secret private upstream diagnostic');
    }
    $header = $options[CURLOPT_HEADERFUNCTION];
    foreach (["HTTP/1.1 100 Continue\r\n", "X-Discard: interim\r\n", "\r\n",
        "HTTP/1.1 201 Created\r\n", "X-Probe: first\r\n", "X-Probe: second\r\n", "\r\n"] as $line) {
        $header($handle, $line);
    }
    return 'synthetic response';
}
function curl_getinfo($handle, $option) {
    return 201;
}
try {
    $client = $input['allow_form_post'] === null
        ? new CurlClient() : new CurlClient($input['allow_form_post'], $input['allow_multipart_post']);
    $response = $client->sendRequest(new \Nyholm\Psr7\Request(
        $input['method'], $input['uri'], $input['headers'], $input['body'],
    ));
    $result = [
        'initialized' => $initialized,
        'executed' => $executed,
        'uri' => $target,
        'get' => $options[CURLOPT_HTTPGET] ?? false,
        'post' => $options[CURLOPT_POST] ?? false,
        'body' => $options[CURLOPT_POSTFIELDS] ?? null,
        'headers' => $options[CURLOPT_HTTPHEADER],
        'return_transfer' => $options[CURLOPT_RETURNTRANSFER],
        'follow_redirects' => $options[CURLOPT_FOLLOWLOCATION],
        'verify_peer' => $options[CURLOPT_SSL_VERIFYPEER],
        'verify_host' => $options[CURLOPT_SSL_VERIFYHOST],
        'connect_timeout' => $options[CURLOPT_CONNECTTIMEOUT],
        'timeout' => $options[CURLOPT_TIMEOUT],
        'protocols' => $options[CURLOPT_PROTOCOLS],
        'response' => [$response->getStatusCode(), $response->getHeaders(), (string) $response->getBody(),
            $response->getProtocolVersion(), $response->getReasonPhrase()],
    ];
} catch (\Throwable $exception) {
    $result = [
        'initialized' => $initialized,
        'executed' => $executed,
        'exception' => $exception instanceof \Psr\Http\Client\ClientExceptionInterface
            ? \Psr\Http\Client\ClientExceptionInterface::class : $exception::class,
        'message' => $exception->getMessage(),
        'previous' => $exception->getPrevious() !== null,
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            [PHP_BINARY, '-r', $code, $root . '/vendor/autoload.php', json_encode([
                'allow_form_post' => $allowFormPost,
                'allow_multipart_post' => $allowMultipartPost,
                'fail_execution' => $failExecution,
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
                'body' => (string) $request->getBody(),
            ], JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['WHM_API_LIVE' => $live, 'WHM_API_LIVE_MUTATIONS' => $mutations],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame('', $stderr);
        self::assertIsString($stdout);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
