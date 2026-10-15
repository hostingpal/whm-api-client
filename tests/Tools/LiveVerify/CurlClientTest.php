<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\CurlClient;
use LogicException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
}
