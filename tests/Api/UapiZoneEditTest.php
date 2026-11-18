<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\Uapi\Spec\ZoneEditSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Independent wire and rebuild-result fixtures for the account-scoped zone reset. */
final class UapiZoneEditTest extends TestCase
{
    /** @return iterable<string, array{array<mixed>}> */
    public static function unsoundPolicies(): iterable
    {
        $spec = ZoneEditSpec::RESETZONE;
        yield 'wrong endpoint' => [['module' => 'DNS'] + $spec];
        yield 'optional payload' => [['optional' => true] + $spec];
        yield 'unchecked dispatch' => [['outcome' => 'unchecked'] + $spec];
        yield 'malformed opt-in' => [['single_result_status' => false] + $spec];
        $spec['returns']['items']['fields']['result']['fields']['status']['accept'] = ['string-flag'];
        yield 'coercive nested flag' => [$spec];
    }

    /** @param array<mixed> $spec */
    #[DataProvider('unsoundPolicies')]
    public function testRejectsUnsoundResultPolicies(array $spec): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check($spec);
    }

    /** @return iterable<string, array{bool, string, int, class-string<\Throwable>|null}> */
    public static function cases(): iterable
    {
        $rejected = ApiErrorException::class;
        $mismatch = PayloadMismatchException::class;
        $cases = [
            'observed success shape' => [
                '[{"result":{"checkmx":{"warnings":[],"local":1,"detected":"local"},"status":1,"statusmsg":""}}]',
                1,
                null,
            ],
            'optional MX omitted' => ['[{"result":{"status":1}}]', 1, null],
            'optional MX null' => ['[{"result":{"checkmx":null,"status":1}}]', 1, null],
            'inner failure' => ['[{"result":{"status":0,"statusmsg":"private-rebuild-text"}}]', 1, $rejected],
            'inner failure with null message' => ['[{"result":{"status":0,"statusmsg":null}}]', 1, $rejected],
            'inner failure without message' => ['[{"result":{"status":0}}]', 1, $rejected],
            'dispatch denied' => ['null', 0, $rejected],
            'omitted data' => ['', 1, $mismatch],
            'null data' => ['null', 1, $mismatch],
            'empty data' => ['[]', 1, $mismatch],
            'missing result' => ['[{}]', 1, $mismatch],
            'missing status' => ['[{"result":{}}]', 1, $mismatch],
            'string zero' => ['[{"result":{"status":"0"}}]', 1, $mismatch],
            'null status' => ['[{"result":{"status":null}}]', 1, $mismatch],
            'wrong status type' => ['[{"result":{"status":true}}]', 1, $mismatch],
            'mixed statuses' => ['[{"result":{"status":1}},{"result":{"status":0}}]', 1, $mismatch],
            'two successes' => ['[{"result":{"status":1}},{"result":{"status":1}}]', 1, $mismatch],
            'malformed MX after success' => ['[{"result":{"checkmx":"bad","status":1}}]', 1, $mismatch],
        ];
        yield 'outer WHM rejection' => [true, 'null', -1, $rejected];
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
            . ',"warnings":null,"messages":null,"metadata":{}' . ($data === '' ? '' : ',"data":' . $data) . '}';
        $envelope = $bridge
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : '{"result":' . $result . '}';
        $http->addResponse(new Response(200, [], $status === -1 ? '{"metadata":{"result":0}}' : $envelope));
        if ($failure !== null) {
            $this->expectException($failure);
        }
        try {
            $rows = $account->zoneEdit()->resetZone(domain: 'fixture.example.test');
            self::assertSame(json_decode($data, true, flags: JSON_THROW_ON_ERROR), $rows);
        } catch (ApiErrorException | PayloadMismatchException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertStringNotContainsString('private-rebuild-text', $error->getMessage());
            if ($failure === ApiErrorException::class && str_contains($data, '"status":0')) {
                self::assertSame('The DNS zone rebuild failed.', $error->getMessage());
            }
            if ($error instanceof PayloadMismatchException) {
                self::assertSame($data === '[{"result":{"checkmx":"bad","status":1}}]', $error->outcomeConfirmed);
            }
            throw $error;
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
            self::assertSame($status === -1 ? null : $status, $account->responseMeta()?->uapiStatus());
            self::assertSame($bridge ? ($status === -1 ? 0 : 1) : null, $account->responseMeta()?->whmResult());
        }
    }
}
