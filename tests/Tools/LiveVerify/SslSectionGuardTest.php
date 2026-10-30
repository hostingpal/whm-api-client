<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\SslSectionGuard;
use HostingPal\WhmApiClient\Tools\LiveVerify\SslSectionMaterial;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SslSectionGuardTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'] as $name => $value
        ) {
            $this->environment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    #[DataProvider('attacks')]
    public function testRejectsAlteredMultipartWithoutNetworkAndConsumesAuthorization(string $attack): void
    {
        $material = SslSectionMaterial::generate();
        $http = new MockClient(new Psr17Factory());
        $guard = new SslSectionGuard(
            $http,
            ['whm' => 'fixture', 'cpanel' => 'fixture'],
            ['direct-uapi' => $material, 'bridged-uapi' => $material],
        );
        $fields = ['key' => $material->key, 'friendly_name' => SslSectionGuard::name($material)];
        $uri = $guard->authorize('upload_key', 'direct-uapi', 'upload_key', $fields);
        $boundary = 'whm-api-fixture-boundary';
        $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"key\"\r\n\r\n" . $material->key
            . "\r\n--" . $boundary . "\r\nContent-Disposition: form-data; name=\"friendly_name\"\r\n\r\n"
            . SslSectionGuard::name($material) . "\r\n--" . $boundary . "--\r\n";
        $request = new Request('POST', $uri, ['Authorization' => 'cpanel npr:fixture', 'Accept' => 'application/json',
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
        $request = match ($attack) {
            'field' => $request->withBody((new Psr17Factory())->createStream(
                str_replace('name="key"', 'name="crt"', $body),
            )),
            'query' => $request->withUri($request->getUri()->withQuery('key=private')),
            'account' => $request->withHeader('Authorization', 'cpanel other:fixture'),
            'boundary' => $request->withHeader(
                'Content-Type',
                'multipart/form-data; boundary=unmatched-boundary-value',
            ),
            'header' => $request->withHeader('X-Extra', 'private'),
            'method' => $request->withMethod('GET'),
            'duplicate' => $request->withBody((new Psr17Factory())->createStream($body . $body)),
            default => throw new LogicException('Unknown guard test.'),
        };
        try {
            $guard->sendRequest($request);
            self::fail('Altered multipart must fail.');
        } catch (LogicException $error) {
            self::assertSame('Request outside SSL fixture boundary.', $error->getMessage());
            self::assertSame([], $http->getRequests());
        }
        $this->expectException(LogicException::class);
        $guard->authorize('upload_key', 'direct-uapi', 'upload_key', $fields);
    }

    /** @return iterable<string,array{string}> */
    public static function attacks(): iterable
    {
        foreach (['field', 'query', 'account', 'boundary', 'header', 'method', 'duplicate'] as $attack) {
            yield $attack => [$attack];
        }
    }

    public function testGenerationCannotBeReauthorizedUnderAnotherPhase(): void
    {
        $material = SslSectionMaterial::generate();
        $http = new MockClient(new Psr17Factory());
        $guard = new SslSectionGuard($http, ['whm' => 'fixture', 'cpanel' => 'fixture'], [
            'direct-uapi' => $material, 'bridged-uapi' => $material,
        ]);
        $this->expectException(LogicException::class);
        try {
            $guard->authorize('second_generation', 'direct-uapi', 'generate_key', [
                'friendly_name' => SslSectionGuard::name($material) . '-generated', 'keytype' => 'rsa-2048',
            ]);
        } finally {
            self::assertSame([], $http->getRequests());
        }
    }

    public function testGeneratedKeyDeleteCannotUseTheUploadedKeyIdentity(): void
    {
        $material = SslSectionMaterial::generate();
        $http = new MockClient(new Psr17Factory());
        $guard = new SslSectionGuard($http, ['whm' => 'fixture', 'cpanel' => 'fixture'], [
            'direct-uapi' => $material, 'bridged-uapi' => $material,
        ]);
        $guard->own('direct-uapi', 'key', 'uploaded-key');
        $this->expectException(LogicException::class);
        try {
            $guard->authorize('delete_generated_key', 'direct-uapi', 'delete_key', ['id' => 'uploaded-key']);
        } finally {
            self::assertSame([], $http->getRequests());
        }
    }

    public function testDeletionNeedsConfirmedExactIdentityAndNeverUsesFriendlyName(): void
    {
        $material = SslSectionMaterial::generate();
        $http = new MockClient(new Psr17Factory());
        $guard = new SslSectionGuard(
            $http,
            ['whm' => 'fixture', 'cpanel' => 'fixture'],
            ['direct-uapi' => $material, 'bridged-uapi' => $material],
        );
        try {
            $guard->authorize('unowned', 'direct-uapi', 'delete_key', ['id' => 'other']);
            self::fail('Unowned key deletion must fail.');
        } catch (LogicException) {
            self::assertSame([], $http->getRequests());
        }
        $guard->own('direct-uapi', 'key', 'exact-owned');
        $uri = $guard->authorize('delete', 'direct-uapi', 'delete_key', ['id' => 'exact-owned']);
        $http->addResponse(new Response(200, [], '{}'));
        $guard->sendRequest(new Request('POST', $uri, ['Authorization' => 'cpanel npr:fixture',
            'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'], 'id=exact-owned'));
        self::assertCount(1, $http->getRequests());
    }
}
