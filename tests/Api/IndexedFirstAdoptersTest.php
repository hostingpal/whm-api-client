<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

/** Literal public-method wire contracts independent of generated collection specs. */
final class IndexedFirstAdoptersTest extends TestCase
{
    /** @param string|non-empty-list<string>|null $input */
    #[DataProvider('validInputs')]
    public function testScalarAndListSpellingsPreserveBytesAndResults(
        string $route,
        string $operation,
        string|array|null $input,
        string $fields,
    ): void {
        [$account, $http] = self::account($route);
        [$payload, $expected] = self::payload($operation);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, self::invoke($account, $operation, $input));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $spam = $operation === 'update_user_preference';
        $module = $spam ? 'SpamAssassin' : 'DNSSEC';
        $get = $operation === 'fetch_ds_records';
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=' . $module . '&cpanel.function=' . $operation
            : 'https://api.example.test:2083/execute/' . $module . '/' . $operation;
        self::assertSame(
            $uri . ($get ? ($route === 'bridge' ? '&' : '?') . $fields : ''),
            (string) $request->getUri(),
        );
        self::assertSame($get ? 'GET' : 'POST', $request->getMethod());
        self::assertSame($get ? '' : $fields, (string) $request->getBody());
        if (!$get) {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string, string, string|non-empty-list<string>|null, string}> */
    public static function validInputs(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['enable_dnssec', 'disable_dnssec', 'fetch_ds_records', 'update_user_preference'] as $op) {
                $spam = $op === 'update_user_preference';
                $prefix = $spam ? 'value' : 'domain';
                $before = $spam ? 'preference=score&' : '';
                $after = $op === 'enable_dnssec' ? '&active=0&nsec3_iterations=0&use_nsec3=0' : '';
                foreach (
                    [
                    ['one.test', $before . $prefix . '=one.test' . $after],
                    [['one.test'], $before . $prefix . '-0=one.test' . $after],
                    [['one.test', 'two.test'], $before . $prefix . '-0=one.test&' . $prefix . '-1=two.test' . $after],
                    [
                    ['', '0', 'a%&', 'a%&'], $before . $prefix . '-0=&' . $prefix . '-1=0&'
                        . $prefix . '-2=a%25%26&' . $prefix . '-3=a%25%26' . $after],
                    ] as $index => [$input, $wire]
                ) {
                    yield "$route $op $index" => [$route, $op, $input, $wire];
                }
                if ($spam) {
                    yield "$route null" => [$route, $op, null, 'preference=score'];
                    yield "$route scalar empty" => [$route, $op, '', 'preference=score&value='];
                    yield "$route scalar zero" => [$route, $op, '0', 'preference=score&value=0'];
                }
            }
        }
    }

    #[DataProvider('routes')]
    public function testSpamOmittedValueStillRemovesSettings(string $route): void
    {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::assertSame([], $account->spamAssassin()->updateUserPreference(preference: 'score'));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('preference=score', (string) $request->getBody());
    }

    /** @param array<mixed> $input */
    #[DataProvider('invalidInputs')]
    public function testBadCollectionsClearStaleMetadataAndNeverDispatch(
        string $route,
        string $operation,
        array $input,
    ): void {
        [$account, $http] = self::account($route);
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::invoke($account, $operation, 'one.test');
        self::assertNotNull($account->responseMeta());
        $group = $operation === 'update_user_preference' ? $account->spamAssassin() : $account->dnssec();
        $method = self::method($operation);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            (new ReflectionMethod($group, $method))->invokeArgs(
                $group,
                $operation === 'update_user_preference' ? ['score', $input] : [$input],
            );
            self::fail('Invalid collection must fail before HTTP.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($account->responseMeta());
            self::assertCount(1, $http->getRequests());
            $publicFrames = 0;
            foreach ($error->getTrace() as $frame) {
                if (($frame['class'] ?? null) === $group::class) {
                    ++$publicFrames;
                    self::assertStringNotContainsString('late-secret', print_r($frame['args'] ?? [], true));
                }
            }
            self::assertSame(1, $publicFrames);
            self::assertStringNotContainsString('late-secret', $error->getMessage());
            self::assertStringNotContainsString('late-secret', $error->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return iterable<string, array{string, string, array<mixed>}> */
    public static function invalidInputs(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['enable_dnssec', 'disable_dnssec', 'fetch_ds_records', 'update_user_preference'] as $op) {
                foreach (
                    [
                    [], [1 => 'one.test'], ['name' => 'one.test'], ['late-secret', 1],
                    ['late-secret', false], ['late-secret', null], ['late-secret', []],
                    ['first', 'late-secret', 1.5]] as $index => $input
                ) {
                    yield "$route $op $index" => [$route, $op, $input];
                }
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'bridge' => ['bridge'];
    }

    /** @param string|non-empty-list<string>|null $input
     * @return array<mixed>
     */
    private static function invoke(
        UapiClient $account,
        string $operation,
        #[SensitiveParameter] string|array|null $input,
    ): array {
        if ($operation === 'update_user_preference') {
            return $account->spamAssassin()->updateUserPreference(preference: 'score', value: $input);
        }
        self::assertNotNull($input);
        return match ($operation) {
            'enable_dnssec' => $account->dnssec()->enableDnssec(domain: $input, active: false,
                nsec3Iterations: 0, useNsec3: false),
            'disable_dnssec' => $account->dnssec()->disableDnssec(domain: $input),
            default => $account->dnssec()->fetchDsRecords(domain: $input),
        };
    }

    private static function method(string $operation): string
    {
        return match ($operation) {
            'enable_dnssec' => 'enableDnssec', 'disable_dnssec' => 'disableDnssec',
            'fetch_ds_records' => 'fetchDsRecords', default => 'updateUserPreference',
        };
    }

    /** @return array{string, array<mixed>} */
    private static function payload(string $operation): array
    {
        return match ($operation) {
            'enable_dnssec' => ['{"enabled":{"one.test":{"new_key_id":"12"},"two.test":{"enabled":0}}}',
                ['enabled' => ['one.test' => ['new_key_id' => 12], 'two.test' => ['enabled' => 0]]]],
            'disable_dnssec' => ['{"disabled":{"one.test":1,"two.test":0}}',
                ['disabled' => ['one.test' => 1, 'two.test' => 0]]],
            'fetch_ds_records' => ['{"one.test":{"keys":{"007":{"key_id":12}}},"two.test":{}}',
                ['one.test' => ['keys' => ['007' => ['key_id' => 12]]], 'two.test' => []]],
            default => ['{"score":["A 1"],"custom":{"opaque":null}}',
                ['score' => ['A 1'], 'custom' => ['opaque' => null]]],
        };
    }

    private static function envelope(string $route, string $payload): string
    {
        $result = '{"status":1,"data":' . $payload . '}';
        return $route === 'bridge' ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : $result;
    }

    /** @return array{UapiClient, MockClient} */
    private static function account(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'synthetic-token'],
            new HttpTransport($http, $factory, $factory),
        );
        return [$client->uapi('npr'), $http];
    }
}
