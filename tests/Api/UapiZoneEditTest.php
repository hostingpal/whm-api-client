<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Independent wire and rebuild-result fixtures for the account-scoped zone reset. */
final class UapiZoneEditTest extends TestCase
{
    /** @return iterable<string, array{bool, string, int, class-string<\Throwable>|null}> */
    public static function cases(): iterable
    {
        $cases = [
            'rebuilt' => [
                '[{"result":{"checkmx":{"warnings":[],"local":1},"status":1,"statusmsg":"rebuilt"}}]', 1, null],
            'rebuild failed' => ['[{"result":{"status":0,"statusmsg":"rebuild failed"}}]', 1, null],
            'explicit null MX result' => ['[{"result":{"checkmx":null,"status":0}}]', 1, null],
            'optional fields' => ['[{},{"result":{}}]', 1, null],
            'dispatch failed' => ['null', 0, ApiErrorException::class],
            'no rebuild data' => ['null', 1, PayloadMismatchException::class],
            'wrong inner status' => ['[{"result":{"status":"0"}}]', 1, PayloadMismatchException::class],
            'wrong MX result' => ['[{"result":{"checkmx":"bad"}}]', 1, PayloadMismatchException::class],
        ];
        foreach ([false, true] as $bridge) {
            foreach ($cases as $name => $case) {
                yield ($bridge ? 'bridge ' : 'direct ') . $name => [$bridge, ...$case];
            }
        }
    }

    /** @param class-string<\Throwable>|null $failure */
    #[DataProvider('cases')]
    public function testZoneReset(bool $bridge, string $data, int $status, ?string $failure): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $account = (new Client(
            ['host' => 'api.example.test', 'authType' => $bridge ? 'whm' : 'cpanel',
                'username' => $bridge ? 'root' : 'fixture', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ))->uapi('fixture');
        $result = '{"status":' . $status . ',"errors":' . ($status === 1 ? 'null' : '["denied"]')
            . ',"warnings":null,"messages":null,"metadata":{},"data":' . $data . '}';
        $http->addResponse(new Response(200, [], $bridge
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}'));
        if ($failure !== null) {
            $this->expectException($failure);
        }
        try {
            $rows = $account->zoneEdit()->resetZone(domain: 'fixture.example.test');
            self::assertSame(json_decode($data, true, flags: JSON_THROW_ON_ERROR), $rows);
        } finally {
            self::assertCount(1, $http->getRequests());
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('POST', $request->getMethod());
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
            self::assertSame('domain=fixture.example.test', (string) $request->getBody());
            self::assertSame($bridge
                ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                    . '&cpanel.module=ZoneEdit&cpanel.function=resetzone'
                : 'https://api.example.test:2083/execute/ZoneEdit/resetzone', (string) $request->getUri());
            self::assertSame($status, $account->responseMeta()?->uapiStatus());
        }
    }
}
