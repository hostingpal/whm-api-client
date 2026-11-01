<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Chkservd;
use HostingPal\WhmApiClient\Api\Uapi\Resellers;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Message\RequestMatcher;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameterValue;

/** Independent synthetic fixtures only; no returned identity, host or port is contacted. */
final class UapiDiscoveryTest extends TestCase
{
    #[DataProvider('routes')]
    public function testAccountGroupsAreLazyCachedAndExposeExactZeroArgumentArrayMethods(string $route): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $other = $route === 'bridge' ? $client->uapi('other') : self::client('direct')[0]->uapi('fixture');
        foreach (['chkservd' => Chkservd::class, 'resellers' => Resellers::class] as $accessor => $class) {
            self::assertTrue((new ReflectionClass($account))->hasMethod($accessor), "$accessor must exist.");
            $selector = new ReflectionMethod($account, $accessor);
            self::assertCount(0, $selector->getParameters());
            self::assertSame($class, (string) $selector->getReturnType());
            self::assertSame($account->$accessor(), $account->$accessor());
            self::assertNotSame($account->$accessor(), $other->$accessor());
            foreach ($accessor === 'chkservd' ? ['getEximPorts', 'getEximPortsSsl'] : ['listAccounts'] as $name) {
                $method = new ReflectionMethod($class, $name);
                self::assertSame('array', (string) $method->getReturnType());
                self::assertCount(0, $method->getParameters());
            }
        }
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    /** @return iterable<array{string}> */
    public static function routes(): iterable
    {
        yield ['direct'];
        yield ['bridge'];
    }

    #[DataProvider('requests')]
    public function testOneLiteralGetHasNoOperationQueryBodyOrContentType(
        string $route,
        string $method,
        string $url,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        self::assertSame([], self::invoke($account, $method));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame($url, (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame('', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        $before = $account->responseMeta();
        $account->chkservd();
        $account->resellers();
        self::assertSame($before, $account->responseMeta());
        self::assertSame(1, $before?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $before->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function requests(): iterable
    {
        yield 'direct SMTP' => ['direct', 'getEximPorts',
            'https://api.example.test:2083/execute/Chkservd/get_exim_ports'];
        yield 'direct SSL' => ['direct', 'getEximPortsSsl',
            'https://api.example.test:2083/execute/Chkservd/get_exim_ports_ssl'];
        yield 'direct reseller' => ['direct', 'listAccounts',
            'https://api.example.test:2083/execute/Resellers/list_accounts'];
        yield 'bridge SMTP' => ['bridge', 'getEximPorts',
            'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=Chkservd&cpanel.function=get_exim_ports'];
        yield 'bridge SSL' => ['bridge', 'getEximPortsSsl',
            'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=Chkservd&cpanel.function=get_exim_ports_ssl'];
        yield 'bridge reseller' => ['bridge', 'listAccounts',
            'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=Resellers&cpanel.function=list_accounts'];
    }

    public function testAccountSelectionAndFreshServerResultsStayIsolated(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('fixture');
        $second = $client->uapi('other');
        foreach (['[{"user":"first"}]', '[{"user":"second"}]', '[]'] as $payload) {
            $http->addResponse(new Response(200, [], self::envelope('bridge', $payload)));
        }
        self::assertSame([['user' => 'first']], self::invoke($first, 'listAccounts'));
        self::assertNull($second->responseMeta());
        $snapshot = $first->responseMeta();
        self::assertSame([['user' => 'second']], self::invoke($second, 'listAccounts'));
        self::assertSame($snapshot, $first->responseMeta());
        self::assertSame([], self::invoke($first, 'listAccounts'));
        self::assertCount(3, $http->getRequests());
        self::assertSame(
            'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=other'
                . '&cpanel.module=Resellers&cpanel.function=list_accounts',
            (string) $http->getRequests()[1]->getUri(),
        );
    }

    /** @param array<mixed> $expected */
    #[DataProvider('payloads')]
    public function testPayloadsPreserveOptionalFieldsListOrderAndExactNativeBytes(
        string $route,
        string $method,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 1, true)));
        self::assertSame($expected, self::invoke($account, $method));
        self::assertSame(['PRIVATE warning'], $account->responseMeta()?->uapiWarnings());
        self::assertSame(['private' => 'PRIVATE metadata'], $account->responseMeta()?->uapi['metadata'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<mixed>}> */
    public static function payloads(): iterable
    {
        $cases = [
            ['getEximPorts', '{"ports":[587,25,65535,1],"unknown":"discard"}',
                ['ports' => [587, 25, 65535, 1]]],
            ['getEximPortsSsl', '{"ports":[465,0,65535,465]}', ['ports' => [465, 0, 65535, 465]]],
            // General bounds and uniqueness remain source constraints, not runtime checks.
            ['getEximPorts', '{"ports":[0,-1,65536,25,25]}', ['ports' => [0, -1, 65536, 25, 25]]],
            ['getEximPortsSsl', '{"ports":[-1,65536]}', ['ports' => [-1, 65536]]],
            ['listAccounts', '[{"domain":" š%2F+&\\n","select":"1","user":"PRIVATE user",'
                . '"unknown":"discard"},{"select":"","domain":"","user":""},{"user":"third"},{}]',
                [
                    ['domain' => " š%2F+&\n", 'select' => '1', 'user' => 'PRIVATE user'],
                    ['domain' => '', 'select' => '', 'user' => ''], ['user' => 'third'], []]],
            // Ordinary string enums are deliberately not enforced or normalized.
            ['listAccounts', '[{"select":"0"},{"select":"future"},{"select":" 1"}]',
                [['select' => '0'], ['select' => 'future'], ['select' => ' 1']]],
            ['listAccounts', '[]', []],
            ['listAccounts', '[{"0":"row"}]', [[]]], // Unknown object fields are filtered.
            ['listAccounts', '[[]]', [[]]], // Existing empty-array object compatibility also applies to rows.
        ];
        foreach (['getEximPorts', 'getEximPortsSsl'] as $method) {
            $cases[] = [$method, '{}', []];
            $cases[] = [$method, '{"ports":[]}', ['ports' => []]];
            $cases[] = [$method, '[]', []]; // Compatibility is not proof of a strict JSON object root.
            $cases[] = [$method, '{"unknown":true}', []];
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route $index" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('mismatches')]
    public function testRequiredRootsAndStrictLeafTypesRejectNullMissingAndCoercions(
        string $route,
        string $method,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 1, true)));
        try {
            self::invoke($account, $method);
            self::fail('Expected required nonnull roots and strict optional field types.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(['PRIVATE warning'], $error->metadata->uapiWarnings());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function mismatches(): iterable
    {
        $cases = [];
        foreach (['getEximPorts', 'getEximPortsSsl', 'listAccounts'] as $method) {
            foreach ([null, 'null', '"root"', '1', '1.0', 'true'] as $wrong) {
                $cases[] = [$method, $wrong];
            }
            if ($method === 'listAccounts') {
                foreach (['{}', '{"0":{}}', '[null]', '[1]', '["row"]', '[true]'] as $wrong) {
                    $cases[] = [$method, $wrong];
                }
                foreach (['domain', 'select', 'user'] as $field) {
                    foreach (['null', '0', '1', '1.0', 'true', 'false', '[]', '{}'] as $wrong) {
                        $cases[] = [$method, '[{"' . $field . '":' . $wrong . '}]'];
                    }
                }
            } else {
                foreach (
                    ['[{}]', '{"ports":null}', '{"ports":25}', '{"ports":{}}',
                    '{"ports":{"0":25}}'] as $wrong
                ) {
                    $cases[] = [$method, $wrong];
                }
                foreach (['"25"', '25.0', 'true', 'false', 'null', '[]', '{}'] as $wrong) {
                    $cases[] = [$method, '{"ports":[25,' . $wrong . ']}'];
                }
            }
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route $index" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('failures')]
    public function testEveryOperationResetsMetadataChecksOutcomeFirstAndProtectsLibraryResponseFrames(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]', 1, true)));
        self::invoke($account, $method);
        $before = $account->responseMeta();
        $clearedAtIo = false;
        $http->on(new class implements RequestMatcher {
            public function matches(RequestInterface $request): bool
            {
                return true;
            }
        }, static function () use ($account, $route, $failure, &$clearedAtIo): Response {
            $clearedAtIo = $account->responseMeta() === null;
            if ($failure === 'transport') {
                throw new class ('PRIVATE transport') extends RuntimeException implements ClientExceptionInterface {
                };
            }
            return match ($failure) {
                'http' => new Response(503, [], 'PRIVATE non-json'),
                'json' => new Response(200, [], 'PRIVATE non-json'),
                'api' => new Response(200, [], self::envelope($route, 'null', 0, true)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"PRIVATE outer"},'
                    . '"data":{"uapi":{"status":1,"data":null,"warnings":["PRIVATE warning"]}}}'),
                'status' => new Response(200, [], $route === 'bridge'
                    ? '{"metadata":{"result":1},"data":{"uapi":{"status":"1","data":null}}}'
                    : '{"result":{"status":"1","data":null}}'),
                default => new Response(200, [], self::envelope($route, '"PRIVATE payload"', 1, true)),
            };
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($account, $method);
            self::fail('The invocation must fail without retrying.');
        } catch (ApiException $error) {
            self::assertSame(match ($failure) {
                'transport' => TransportException::class, 'http' => HttpException::class,
                'json', 'status' => InvalidResponseException::class,
                'api', 'outer' => ApiErrorException::class, default => PayloadMismatchException::class,
            }, $error::class);
            if (in_array($failure, ['transport', 'http', 'json'], true)) {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            } else {
                self::assertNotSame($before, $account->responseMeta());
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(match ($failure) {
                    'api' => 0, 'status' => null, default => 1,
                }, $error->metadata?->uapiStatus());
                self::assertSame(
                    $failure === 'outer' ? 0 : ($route === 'bridge' ? 1 : null),
                    $error->metadata?->whmResult(),
                );
            }
            if (in_array($failure, ['api', 'outer', 'payload'], true)) {
                self::assertSame(['PRIVATE warning'], $error->metadata?->uapiWarnings());
            }
            if ($error instanceof PayloadMismatchException) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertSame('PRIVATE payload', $error->payload);
                self::assertSame(['private' => 'PRIVATE metadata'], $error->metadata?->uapi['metadata'] ?? null);
            }
            if ($error instanceof TransportException) {
                self::assertNull($error->getPrevious());
            }
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            $frames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    && !str_starts_with($class, __NAMESPACE__ . '\\')
                ) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(
                        self::hasPrivateValue($frame['args'] ?? [], $seen),
                        $class . '::' . $frame['function'],
                    );
                }
            }
            self::assertGreaterThan(0, $frames);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertTrue($clearedAtIo);
        self::assertSame(1, $before?->uapiStatus());
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['getEximPorts', 'getEximPortsSsl', 'listAccounts'] as $method) {
                foreach (
                    ['transport', 'http', 'json', 'api', 'status', 'payload',
                    ...($route === 'bridge' ? ['outer'] : [])] as $failure
                ) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    /** @return array<mixed> */
    private static function invoke(UapiClient $account, string $method): array
    {
        $accessor = $method === 'listAccounts' ? 'resellers' : 'chkservd';
        self::assertTrue((new ReflectionClass($account))->hasMethod($accessor), "$accessor must exist.");
        return match ($method) {
            'getEximPorts' => $account->chkservd()->getEximPorts(),
            'getEximPortsSsl' => $account->chkservd()->getEximPortsSsl(),
            default => $account->resellers()->listAccounts(),
        };
    }

    /** @param array<int, true> $seen */
    private static function hasPrivateValue(mixed $value, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, 'PRIVATE');
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (str_contains((string) $key, 'PRIVATE') || self::hasPrivateValue($item, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function envelope(string $route, ?string $payload, int $status = 1, bool $warning = false): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["PRIVATE error"]')
            . ',"warnings":' . ($warning ? '["PRIVATE warning"]' : 'null')
            . ',"metadata":' . ($warning ? '{"private":"PRIVATE metadata"}' : '{}') . '}';
        return $route === 'bridge' ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'fixture', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
