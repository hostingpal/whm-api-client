<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use ArgumentCountError;
use Closure;
use HostingPal\WhmApiClient\Api\Whm\Security;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;

/**
 * Offline synthetic fixtures; no root access changes reach a server.
 * Source-success examples and effective-shape compatibility cases are separate.
 * Failure reasons are fixture text, not captured server exception serialization.
 *
 * @phpstan-type SecurityCall Closure(Security): mixed
 * @phpstan-type Operation array{string, SecurityCall, string, ?string, mixed}
 */
final class WhmSecurityTest extends TestCase
{
    /** @param SecurityCall $call */
    #[DataProvider('sourceSuccessOperations')]
    #[DataProvider('effectiveShapeCompatibility')]
    public function testExactPostRequestsAndEffectivePayloads(
        string $function,
        Closure $call,
        string $body,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $security = $whm->security();
        self::assertSame($security, $whm->security());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, $call($security));
        self::assertRequest($http, $function, $body);
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertNull($client->uapi('alice')->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, Operation> */
    public static function sourceSuccessOperations(): iterable
    {
        yield 'remove all restrictions has no data payload' => ['allow_all_whm_root_access',
            static function (Security $s): mixed {
                $s->allowAllWhmRootAccess();
                return null;
            }, '', null, null];
        yield 'scalar CIDR source-success shape' => ['restrict_whm_root_access',
            static fn (Security $s): mixed => $s->restrictWhmRootAccess('10.2.0.0/16'),
            'cidr=10.2.0.0%2F16', '{"cidr":["10.2.0.0/16"]}',
            ['cidr' => ['10.2.0.0/16']]];
        yield 'repeated CIDR fields source-success shape' => ['restrict_whm_root_access',
            static fn (Security $s): mixed => $s->restrictWhmRootAccess(['10.2.0.0/16', '2001:db8::/32']),
            'cidr=10.2.0.0%2F16&cidr=2001%3Adb8%3A%3A%2F32',
            '{"cidr":["10.2.0.0/16","2001:db8::/32"]}',
            ['cidr' => ['10.2.0.0/16', '2001:db8::/32']]];
        yield 'adjacent ranges may be reduced' => ['restrict_whm_root_access',
            static fn (Security $s): mixed => $s->restrictWhmRootAccess(['10.0.0.0/25', '10.0.0.128/25']),
            'cidr=10.0.0.0%2F25&cidr=10.0.0.128%2F25', '{"cidr":["10.0.0.0/24"]}',
            ['cidr' => ['10.0.0.0/24']]];
    }

    /**
     * Synthetic validator boundaries, not examples of source-success responses.
     *
     * @return iterable<string, Operation>
     */
    public static function effectiveShapeCompatibility(): iterable
    {
        yield 'offline empty object with optional cidr omitted' => ['restrict_whm_root_access',
            static fn (Security $s): mixed => $s->restrictWhmRootAccess('10.1.6.7'),
            'cidr=10.1.6.7', '{}', []];
        yield 'offline empty cidr list' => ['restrict_whm_root_access',
            static fn (Security $s): mixed => $s->restrictWhmRootAccess('10.1.6.7'),
            'cidr=10.1.6.7', '{"cidr":[]}', ['cidr' => []]];
        yield 'offline string item without CIDR response validation' => ['restrict_whm_root_access',
            static fn (Security $s): mixed => $s->restrictWhmRootAccess('10.1.6.7'),
            'cidr=10.1.6.7', '{"cidr":[""]}', ['cidr' => ['']]];
        yield 'offline optional cidr omitted and unknown field removed' => ['restrict_whm_root_access',
            static fn (Security $s): mixed => $s->restrictWhmRootAccess('10.1.6.7'),
            'cidr=10.1.6.7', '{"unreviewed":"fixture"}', []];
        yield 'offline existing empty array compatibility for optional object' => ['restrict_whm_root_access',
            static fn (Security $s): mixed => $s->restrictWhmRootAccess('10.1.6.7'),
            'cidr=10.1.6.7', '[]', []];
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'absent data' => [null];
        foreach (
            ['null', 'false', 'true', '0', '1.5', '"fixture"', '[{}]',
                '{"cidr":null}', '{"cidr":{}}', '{"cidr":"10.2.0.0/16"}',
                '{"cidr":[0]}', '{"cidr":[false]}', '{"cidr":[null]}',
                '{"cidr":[{}]}', '{"cidr":[[]]}'] as $payload
        ) {
            yield $payload => [$payload];
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedRestrictionPayloadKeepsConfirmedOutcomeWithoutRetry(?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            $client->whm()->security()->restrictWhmRootAccess('10.2.0.0/16');
            self::fail('Malformed or absent restriction data must fail after the successful outcome.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame(1, $exception->metadata?->whmResult());
            self::assertCount(1, $http->getRequests());
            self::assertRequest($http, 'restrict_whm_root_access', 'cidr=10.2.0.0%2F16');
        }
    }

    /** @param SecurityCall $call */
    #[DataProvider('sourceSuccessOperations')]
    public function testApiFailureReplacesMetadataAndNeverRetries(
        string $function,
        Closure $call,
        string $body,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"cidr":["10.1.6.7"]}')));
        $client->whm()->security()->restrictWhmRootAccess('10.1.6.7');
        $previous = $client->whm()->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Fixture denied"}}'));
        try {
            $call($client->whm()->security());
            self::fail('A failed WHM result must raise an API error.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Fixture denied'], $exception->errors());
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertNotSame($previous, $exception->metadata);
            self::assertSame(0, $exception->metadata?->whmResult());
            self::assertCount(2, $http->getRequests());
            self::assertRequest($http, $function, $body);
        }
    }

    /** @return iterable<string, array{string|list<string>, string, string}> */
    public static function rejectedCidrValues(): iterable
    {
        yield 'empty scalar' => ['', 'cidr=', 'Fixture blank input'];
        yield 'empty list member' => [['', '10.1.6.7'], 'cidr=&cidr=10.1.6.7', 'Fixture blank list member'];
        yield 'whitespace scalar' => [" \t", 'cidr=%20%09', 'Fixture whitespace input'];
        yield 'whitespace list member' => [
            ['10.1.6.7', ' '], 'cidr=10.1.6.7&cidr=%20',
            'Fixture whitespace list member'];
        yield 'malformed bytes encoded exactly once' => [' +&=%20/::', 'cidr=%20%2B%26%3D%2520%2F%3A%3A',
            'Fixture invalid CIDR'];
        yield 'mixed valid and malformed values' => [
            ['10.1.6.7', 'bad/999'],
            'cidr=10.1.6.7&cidr=bad%2F999', 'Fixture invalid CIDR list member'];
    }

    /**
     * Source-based rejection categories with synthetic failure envelopes.
     * This checks client bytes/outcome handling, not server file-write atomicity.
     *
     * @param string|list<string> $cidrs
     */
    #[DataProvider('rejectedCidrValues')]
    public function testRejectedValuesAreForwardedAndApiFailureReplacesMetadata(
        string|array $cidrs,
        string $body,
        string $reason,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"cidr":["10.1.6.7"]}')));
        $client->whm()->security()->restrictWhmRootAccess('10.1.6.7');
        $previous = $client->whm()->responseMeta();
        $http->addResponse(new Response(200, [], json_encode([
            'metadata' => ['result' => 0, 'reason' => $reason],
        ], JSON_THROW_ON_ERROR)));
        try {
            $client->whm()->security()->restrictWhmRootAccess($cidrs);
            self::fail('Source-rejected inputs must retain API failure handling.');
        } catch (ApiErrorException $exception) {
            self::assertSame([$reason], $exception->errors());
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertNotSame($previous, $exception->metadata);
            self::assertSame(0, $exception->metadata?->whmResult());
            self::assertCount(2, $http->getRequests());
            self::assertRequest($http, 'restrict_whm_root_access', $body);
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidCidrLists(): iterable
    {
        yield 'empty list' => [[]];
        yield 'sparse list' => [[1 => '10.2.0.0/16']];
        yield 'named keys' => [['cidr' => '10.2.0.0/16']];
        yield 'integer item' => [[0]];
        yield 'boolean item' => [[false]];
        yield 'null item' => [[null]];
        yield 'nested list' => [[['10.2.0.0/16']]];
    }

    /** @param array<array-key, mixed> $invalid */
    #[DataProvider('invalidCidrLists')]
    public function testInvalidListsResetMetadataBeforeRejectingWithoutDispatch(array $invalid): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"cidr":["10.1.6.7"]}')));
        $client->whm()->security()->restrictWhmRootAccess('10.1.6.7');
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        try {
            // @phpstan-ignore argument.type (exercise malformed callers at the public boundary)
            $client->whm()->security()->restrictWhmRootAccess($invalid);
            self::fail('Repeated CIDRs require a nonempty sequential list of strings.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->whm()->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testCidrArgumentIsRequiredBeforeDispatch(): void
    {
        [$client, $http] = self::client();
        $method = new ReflectionMethod(Security::class, 'restrictWhmRootAccess');
        try {
            $method->invokeArgs($client->whm()->security(), []);
            self::fail('The pinned signature requires a CIDR argument.');
        } catch (ArgumentCountError) {
            self::assertSame([], $http->getRequests());
            self::assertNull($client->whm()->responseMeta());
        }
    }

    private static function assertRequest(MockClient $http, string $function, string $body): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame($body, (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    private static function envelope(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK","version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
