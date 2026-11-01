<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Dcv;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Message\RequestMatcher;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Synthetic offline fixtures; no domain, challenge, path, URL or header is accessed. */
final class UapiDcvTest extends TestCase
{
    public function testAccountAccessorIsCachedLazyAndHasProtectedNativeSignatures(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('dcv'), 'DCV accessor must exist.');
        self::assertSame($account->dcv(), $account->dcv());
        self::assertNotSame($account->dcv(), $client->uapi('other')->dcv());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
        self::assertFalse((new ReflectionClass(Dcv::class))->hasMethod('ensureDomainsCanPassDcv'));
        foreach (['checkDomainsViaDns', 'checkDomainsViaHttp'] as $name) {
            $method = new ReflectionMethod(Dcv::class, $name);
            self::assertSame('array', (string) $method->getReturnType());
            $expected = ['domain' => ['array|string', true]];
            if ($name === 'checkDomainsViaHttp') {
                $expected += [
                    'dcvFileAllowedCharacters' => ['?array', true], 'dcvFileExtension' => ['?string', true],
                    'dcvFileRandomCharacterCount' => ['?int', false], 'dcvFileRelativePath' => ['?string', true],
                    'dcvMaxRedirects' => ['?int', false], 'dcvUserAgentString' => ['?string', true],
                ];
            }
            self::assertSame(
                array_keys($expected),
                array_map(static fn ($p) => $p->getName(), $method->getParameters()),
            );
            foreach ($method->getParameters() as $index => $parameter) {
                [$type, $private] = $expected[$parameter->getName()];
                self::assertSame($type, (string) $parameter->getType());
                self::assertSame($index > 0, $parameter->isOptional());
                if ($index > 0) {
                    self::assertNull($parameter->getDefaultValue());
                }
                self::assertCount($private ? 1 : 0, $parameter->getAttributes(SensitiveParameter::class));
            }
        }
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('requests')]
    public function testLiteralFormBodiesKeepDistinctDomainIndicesAndExactBytes(
        string $route,
        string $method,
        string $function,
        array $arguments,
        string $body,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        self::assertSame([], $client->uapi('fixture')->dcv()->$method(...$arguments));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame($route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=DCV&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/DCV/' . $function, (string) $request->getUri());
        self::assertSame($body, (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>, string}> */
    public static function requests(): iterable
    {
        $cases = [
            ['checkDomainsViaDns', 'check_domains_via_dns', ['domain' => 'EXAMPLE.test'], 'domain=EXAMPLE.test'],
            ['checkDomainsViaDns', 'check_domains_via_dns', ['domain' => ''], 'domain='],
            ['checkDomainsViaDns', 'check_domains_via_dns', ['domain' => ['a.test']], 'domain-0=a.test'],
            ['checkDomainsViaDns', 'check_domains_via_dns', ['domain' => ['a.test', '', 'a.test']],
                'domain-0=a.test&domain-1=&domain-2=a.test'],
            ['checkDomainsViaDns', 'check_domains_via_dns', ['domain' => [' %2F+&š', 'A.test']],
                'domain-0=%20%252F%2B%26%C5%A1&domain-1=A.test'],
            ['checkDomainsViaHttp', 'check_domains_via_http', ['domain' => 'EXAMPLE.test'], 'domain=EXAMPLE.test'],
            ['checkDomainsViaHttp', 'check_domains_via_http', ['domain' => ''], 'domain='],
            ['checkDomainsViaHttp', 'check_domains_via_http', ['domain' => ['a.test']], 'domain-1=a.test'],
            ['checkDomainsViaHttp', 'check_domains_via_http', ['domain' => ['a.test', '', 'a.test']],
                'domain-1=a.test&domain-2=&domain-3=a.test'],
            ['checkDomainsViaHttp', 'check_domains_via_http', ['domain' => 'a', 'dcvFileAllowedCharacters' => null,
                'dcvFileExtension' => null, 'dcvFileRandomCharacterCount' => null, 'dcvFileRelativePath' => null,
                'dcvMaxRedirects' => null, 'dcvUserAgentString' => null], 'domain=a'],
            ['checkDomainsViaHttp', 'check_domains_via_http', ['domain' => [' %2F+&š'],
                'dcvFileAllowedCharacters' => ['AB', '', '+&š', 'AB'], 'dcvFileExtension' => ' %2F+&š',
                'dcvFileRandomCharacterCount' => 0, 'dcvFileRelativePath' => '../ %2F+&š', 'dcvMaxRedirects' => 0,
                'dcvUserAgentString' => 'agent %2F+&š'],
                'domain-1=%20%252F%2B%26%C5%A1&dcv_file_allowed_characters=AB&dcv_file_allowed_characters='
                . '&dcv_file_allowed_characters=%2B%26%C5%A1&dcv_file_allowed_characters=AB'
                . '&dcv_file_extension=%20%252F%2B%26%C5%A1&dcv_file_random_character_count=0'
                . '&dcv_file_relative_path=..%2F%20%252F%2B%26%C5%A1&dcv_max_redirects=0'
                . '&dcv_user_agent_string=agent%20%252F%2B%26%C5%A1'],
            ['checkDomainsViaHttp', 'check_domains_via_http', ['dcvUserAgentString' => '', 'dcvMaxRedirects' => -1,
                'dcvFileRelativePath' => '', 'dcvFileRandomCharacterCount' => -1, 'dcvFileExtension' => '',
                'dcvFileAllowedCharacters' => [''], 'domain' => 'a'],
                'domain=a&dcv_file_allowed_characters=&dcv_file_extension=&dcv_file_random_character_count=-1'
                . '&dcv_file_relative_path=&dcv_max_redirects=-1&dcv_user_agent_string='],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route $index" => [$route, ...$case];
            }
        }
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidCollections')]
    public function testMalformedCollectionsClearMetadataWithoutDispatch(
        string $route,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->dcv()->checkDomainsViaDns('a');
        self::assertNotNull($account->responseMeta());
        try {
            $account->dcv()->$method(...$arguments);
            self::fail('Invalid collections must fail before I/O.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            self::assertNull($account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function invalidCollections(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            $malformed = [[], [1 => 'PRIVATE'], ['PRIVATE' => 'value'], ['ok', 1], [null], [false], [1.5], [[]]];
            foreach ($malformed as $i => $bad) {
                foreach (['checkDomainsViaDns', 'checkDomainsViaHttp'] as $method) {
                    yield "$route domain $method $i" => [$route, $method, ['domain' => $bad]];
                }
                yield "$route characters $i" => [$route, 'checkDomainsViaHttp',
                    ['domain' => 'a', 'dcvFileAllowedCharacters' => $bad]];
            }
        }
    }

    /** @param array<mixed> $arguments */
    #[DataProvider('nativeFailures')]
    public function testAllowedCharactersRejectNativeScalarBeforeReset(
        string $route,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->dcv()->checkDomainsViaDns('a');
        $previous = $account->responseMeta();
        try {
            $account->dcv()->$method(...$arguments);
            self::fail('The array-only native parameter must reject scalar input.');
        } catch (TypeError) {
            self::assertSame($previous, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<array{string, string, array<mixed>}> */
    public static function nativeFailures(): iterable
    {
        yield ['direct', 'checkDomainsViaHttp', ['a', 'PRIVATE']];
        yield ['bridge', 'checkDomainsViaHttp', ['a', 'PRIVATE']];
    }

    /** @param mixed $expected */
    #[DataProvider('payloads')]
    public function testIndependentPayloadContractsAndMismatchSnapshots(
        string $route,
        string $method,
        ?string $payload,
        bool $valid,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 1, true)));
        try {
            $actual = $account->dcv()->$method(['a', 'b']);
            self::assertTrue($valid, 'Malformed payload unexpectedly accepted.');
            self::assertSame($expected, $actual);
        } catch (PayloadMismatchException $error) {
            self::assertFalse($valid, 'Valid payload unexpectedly rejected.');
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($payload === null ? null : json_decode($payload, true), $error->payload);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame(['PRIVATE warning'], $metadata->uapiWarnings());
        self::assertSame(
            ['transformed' => 1, 'private' => 'PRIVATE metadata'],
            $metadata->uapi['metadata'] ?? null,
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, ?string, bool, mixed}> */
    public static function payloads(): iterable
    {
        $dns = 'checkDomainsViaDns';
        $http = 'checkDomainsViaHttp';
        $cases = [
            [$dns, '[{"dcv_string":"PRIVATE challenge","domain":"a","failure_reason":"",'
                . '"query_results":["second","first","second"],"succeeded":1,"zone":"z","unknown":1},'
                . '{"domain":"b","failure_reason":"PRIVATE update failure","query_results":[],"succeeded":0},{}]', true,
                [
        ['dcv_string' => 'PRIVATE challenge', 'domain' => 'a', 'failure_reason' => '',
                    'query_results' => ['second', 'first', 'second'], 'succeeded' => 1, 'zone' => 'z'],
                ['domain' => 'b', 'failure_reason' => 'PRIVATE update failure',
                    'query_results' => [], 'succeeded' => 0], []]],
            [$http, '[{"failure_reason":null,"redirects":[{"content":"PRIVATE <html>",'
                . '"headers":{"PRIVATE-header":[1,false,null,{"x":"y"}]},"protocol":"HTTP/1.1",'
                . '"reason":"Moved","redirects":[{"arbitrary":{"nested":[1,"PRIVATE"]}},{"success":false}],'
                . '"status":301,"success":"","url":"https://inert.test/PRIVATE","unknown":1},'
                . '{"redirects":null,"success":0},{"success":1},{}],"redirects_count":2,"domain":"discard"},'
                . '{"failure_reason":"PRIVATE failed","redirects":[],"redirects_count":0},{}]', true,
                [
            ['failure_reason' => null, 'redirects' => [
            ['content' => 'PRIVATE <html>',
                    'headers' => ['PRIVATE-header' => [1, false, null, ['x' => 'y']]], 'protocol' => 'HTTP/1.1',
                    'reason' => 'Moved',
                    'redirects' => [['arbitrary' => ['nested' => [1, 'PRIVATE']]], ['success' => false]],
                    'status' => 301, 'success' => '', 'url' => 'https://inert.test/PRIVATE'],
                    ['redirects' => null, 'success' => 0], ['success' => 1], []], 'redirects_count' => 2],
                ['failure_reason' => 'PRIVATE failed', 'redirects' => [], 'redirects_count' => 0], []]],
        ];
        foreach ([$dns, $http] as $method) {
            foreach ([['[]', []], ['[{},[]]', [[], []]]] as [$payload, $expected]) {
                $cases[] = [$method, $payload, true, $expected];
            }
            foreach ([null, 'null', '{}', '0', '"PRIVATE"', '[null]', '[1]', '["PRIVATE"]'] as $payload) {
                $cases[] = [$method, $payload, false, null];
            }
        }
        foreach (['"0"', '"1"', '"PRIVATE"', '" "', '2', '-1', '0.0', '1.0', 'true', 'false', 'null'] as $flag) {
            $cases[] = [$http, '[{"redirects":[{"success":' . $flag . '}]}]', false, null];
        }
        foreach (
            [
            '{"failure_reason":null}', '{"dcv_string":1}', '{"domain":false}', '{"zone":null}',
            '{"query_results":null}', '{"query_results":{}}', '{"query_results":[0]}',
            '{"succeeded":"1"}', '{"succeeded":true}', '{"succeeded":2}',
            ] as $row
        ) {
            $cases[] = [$dns, '[' . $row . ']', false, null];
        }
        foreach (
            [
            '{"failure_reason":0}', '{"redirects_count":"0"}', '{"redirects_count":1.5}',
            '{"redirects_count":null}', '{"redirects":null}', '{"redirects":{}}', '{"redirects":[null]}',
            ] as $row
        ) {
            $cases[] = [$http, '[' . $row . ']', false, null];
        }
        foreach (
            [
            '{"content":null}', '{"protocol":1}', '{"reason":false}', '{"url":null}',
            '{"headers":null}', '{"headers":[1]}', '{"status":"301"}', '{"status":true}',
            '{"redirects":{}}', '{"redirects":[null]}', '{"redirects":["PRIVATE"]}',
            ] as $redirect
        ) {
            $cases[] = [$http, '[{"redirects":[' . $redirect . ']}]', false, null];
        }
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route $index" => [$route, ...$case];
            }
        }
    }

    #[DataProvider('failures')]
    public function testFailurePrecedenceSingleAttemptAndLibraryTracePrivacy(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '[]')));
        $account->dcv()->checkDomainsViaDns('a');
        $previous = $account->responseMeta();
        $body = match ($failure) {
            'api' => self::envelope($route, 'null', 0),
            'outer' => '{"metadata":{"result":0,"reason":"PRIVATE outer"},'
                . '"data":{"uapi":{"status":0,"errors":["PRIVATE inner"],"data":null}}}',
            'status' => $route === 'bridge'
                ? '{"metadata":{"result":1},"data":{"uapi":{"status":"1","data":null}}}'
                : '{"status":"1","data":null}',
            'json' => '{PRIVATE',
            default => self::envelope($route, '[{"redirects":"PRIVATE","succeeded":"PRIVATE"}]', 1, true),
        };
        $clearedAtIo = false;
        $http->on(new class implements RequestMatcher {
            public function matches(RequestInterface $request): bool
            {
                return true;
            }
        }, static function () use ($account, &$clearedAtIo, $body, $failure): Response {
            $clearedAtIo = $account->responseMeta() === null;
            if ($failure === 'transport') {
                throw new class ('PRIVATE transport') extends RuntimeException implements ClientExceptionInterface {
                };
            }
            return new Response($failure === 'http' ? 503 : 200, [], $body);
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invokePrivate($account->dcv(), $method);
            self::fail('The request must fail once without retry.');
        } catch (ApiException $error) {
            self::assertSame(match ($failure) {
                'transport' => TransportException::class,
                'http' => HttpException::class,
                'json', 'status' => InvalidResponseException::class,
                'api', 'outer' => ApiErrorException::class,
                default => PayloadMismatchException::class,
            }, $error::class);
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            if (in_array($failure, ['transport', 'json'], true)) {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            } else {
                self::assertNotNull($error->metadata);
                self::assertNotSame($previous, $account->responseMeta());
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(match ($failure) {
                    'api', 'outer' => 0, 'status' => null, default => 1,
                }, $error->metadata->uapiStatus());
            }
            if ($failure === 'http') {
                $metadata = $error->metadata;
                self::assertNotNull($metadata);
                self::assertSame(503, $metadata->httpStatus);
                self::assertSame(['PRIVATE warning'], $metadata->uapiWarnings());
            }
            if ($failure === 'outer') {
                self::assertSame(0, $error->metadata?->whmResult());
                self::assertStringContainsString('WHM', $error->getMessage());
            }
            if ($error instanceof PayloadMismatchException) {
                self::assertSame([['redirects' => 'PRIVATE', 'succeeded' => 'PRIVATE']], $error->payload);
                self::assertSame(['PRIVATE warning'], $error->metadata?->uapiWarnings());
            }
            if ($error instanceof TransportException) {
                self::assertNull($error->getPrevious());
            }
            $groupFrames = 0;
            $responseFrames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    !str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    || str_starts_with($class, __NAMESPACE__)
                ) {
                    continue;
                }
                $seen = [];
                self::assertFalse(
                    self::hasPrivateValue($frame['args'] ?? [], $seen),
                    $class . '::' . $frame['function'],
                );
                if ($class === Dcv::class) {
                    $groupFrames++;
                    self::assertInstanceOf(SensitiveParameterValue::class, ($frame['args'] ?? [])[0]);
                    if ($method === 'checkDomainsViaHttp') {
                        foreach ([1, 2, 4, 6] as $index) {
                            self::assertInstanceOf(SensitiveParameterValue::class, ($frame['args'] ?? [])[$index]);
                        }
                    }
                }
                if ($frame['function'] === 'result') {
                    $responseFrames++;
                    self::assertInstanceOf(SensitiveParameterValue::class, ($frame['args'] ?? [])[1]);
                }
            }
            self::assertSame(1, $groupFrames);
            if ($failure === 'payload') {
                self::assertGreaterThan(0, $responseFrames);
            }
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertTrue($clearedAtIo);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['checkDomainsViaDns', 'checkDomainsViaHttp'] as $method) {
                $failures = ['transport', 'http', 'json', 'api', 'status', 'payload',
                    ...($route === 'bridge' ? ['outer'] : [])];
                foreach ($failures as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    private static function invokePrivate(Dcv $group, string $method): mixed
    {
        return $method === 'checkDomainsViaDns' ? $group->checkDomainsViaDns(['PRIVATE domain'])
            : $group->checkDomainsViaHttp(
                ['PRIVATE domain'],
                ['PRIVATE alphabet'],
                'PRIVATE extension',
                0,
                'PRIVATE path',
                0,
                'PRIVATE agent',
            );
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
            . ',"metadata":' . ($warning ? '{"transformed":1,"private":"PRIVATE metadata"}' : '{}') . '}';
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
