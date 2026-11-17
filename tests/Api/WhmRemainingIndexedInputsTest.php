<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Accounts;
use HostingPal\WhmApiClient\Api\Whm\Authentication;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Independent literal request fixtures; all responses and identities are synthetic. */
final class WhmRemainingIndexedInputsTest extends TestCase
{
    #[DataProvider('wireInputs')]
    public function testScalarAndIndexedInputsKeepWireBytesAndOneRequest(
        bool $tfa,
        mixed $input,
        string $fields,
    ): void {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        self::assertSame([], self::invoke($whm, $tfa, $input));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($tfa ? 'POST' : 'GET', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . self::operation($tfa)
                . '?api.version=1' . ($tfa ? '' : '&' . $fields),
            (string) $request->getUri(),
        );
        self::assertSame($tfa ? $fields : '', (string) $request->getBody());
        self::assertSame(
            $tfa ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame(1, $whm->responseMeta()?->whmResult());
    }

    /** @return iterable<string, array{bool, mixed, string}> */
    public static function wireInputs(): iterable
    {
        yield 'TFA scalar' => [true, 'alice', 'user=alice'];
        yield 'TFA empty scalar' => [true, '', 'user='];
        yield 'TFA scalar bytes' => [true, 'a +&%20ž', 'user=a%20%2B%26%2520%C5%BE'];
        yield 'TFA singleton starts one' => [true, ['alice'], 'user-1=alice'];
        yield 'TFA order duplicates empty and bytes' => [true, ['bob', '', 'a +&%20ž', 'bob'],
            'user-1=bob&user-2=&user-3=a%20%2B%26%2520%C5%BE&user-4=bob'];
        yield 'integer scalar' => [false, 12, 'job_id=12'];
        yield 'integer singleton starts zero' => [false, [12], 'job_id-0=12'];
        yield 'integer order and duplicates' => [false, [33, 10, 33], 'job_id-0=33&job_id-1=10&job_id-2=33'];
        // Offline preservation of the existing lack of a local range policy does not imply server acceptance.
        yield 'integer zero scalar remains encodable' => [false, 0, 'job_id=0'];
        yield 'integer negative scalar remains encodable' => [false, -1, 'job_id=-1'];
        yield 'integer bounds scalar max' => [false, PHP_INT_MAX, 'job_id=' . PHP_INT_MAX];
        yield 'integer bounds scalar min' => [false, PHP_INT_MIN, 'job_id=' . PHP_INT_MIN];
        yield 'integer list preserves all native bounds' => [false, [PHP_INT_MAX, 0, PHP_INT_MIN],
            'job_id-0=' . PHP_INT_MAX . '&job_id-1=0&job_id-2=' . PHP_INT_MIN];
    }

    /** @param array<mixed> $input */
    #[DataProvider('invalidCollections')]
    public function testInvalidCollectionsResetMetadataBeforeAnyNewIo(bool $tfa, array $input): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        self::invoke($whm, $tfa, $tfa ? 'alice' : 12);
        self::assertNotNull($whm->responseMeta());
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($whm, $tfa, $input);
            self::fail('Invalid collection must fail before HTTP.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($whm->responseMeta());
            self::assertCount(1, $http->getRequests());
            self::assertStringNotContainsString('nested-fixture-secret', $error->getMessage());
            self::assertStringNotContainsString('nested-fixture-secret', $error->getTraceAsString());
            $publicFrames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (str_starts_with($class, 'HostingPal\\WhmApiClient\\') && !str_contains($class, '\\Tests\\')) {
                    self::assertStringNotContainsString('nested-fixture-secret', print_r($frame['args'] ?? [], true));
                    if ($class === ($tfa ? Authentication::class : Accounts::class)) {
                        ++$publicFrames;
                        self::assertInstanceOf(SensitiveParameterValue::class, $frame['args'][0] ?? null);
                    }
                }
            }
            self::assertSame(1, $publicFrames);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** @return iterable<string, array{bool, array<mixed>}> */
    public static function invalidCollections(): iterable
    {
        foreach ([true, false] as $tfa) {
            $valid = $tfa ? 'alice' : 12;
            foreach (
                [
                [], ['name' => $valid], [1 => $valid], [0 => $valid, 2 => $valid],
                [1 => $valid, 0 => $valid], [-1 => $valid]] as $index => $input
            ) {
                yield ($tfa ? 'TFA' : 'jobs') . ' shape ' . $index => [$tfa, $input];
            }
            foreach (
                [null, true, false, 1.5, ['nested-fixture-secret'], (object) ['secret' => 'nested-fixture-secret'],
                ...($tfa ? [1, 0] : ['12', '0', 'nested-fixture-secret'])] as $index => $item
            ) {
                yield ($tfa ? 'TFA' : 'jobs') . ' late item ' . $index => [$tfa, [$valid, $item]];
            }
        }
    }

    #[DataProvider('wrongScalars')]
    public function testNativeTypeFailuresHappenBeforeEntryAndPreservePriorMetadata(bool $tfa, mixed $input): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        self::invoke($whm, $tfa, $tfa ? 'alice' : 12);
        $previous = $whm->responseMeta();
        self::assertNotNull($previous);
        try {
            self::invoke($whm, $tfa, $input);
            self::fail('Native wrong scalar must fail at the public method boundary.');
        } catch (TypeError) {
            self::assertSame($previous, $whm->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{bool, mixed}> */
    public static function wrongScalars(): iterable
    {
        foreach ([true, false] as $tfa) {
            foreach ([null, true, false, 1.5, ...($tfa ? [12] : ['12', ''])] as $index => $input) {
                yield ($tfa ? 'TFA' : 'jobs') . ' scalar ' . $index => [$tfa, $input];
            }
        }
    }

    public function testGeneratedSignaturesProtectCollectionsAndDocumentPreciseTypes(): void
    {
        foreach (
            [
            [Authentication::class, 'twoFactorAuthRemoveUserConfig', 'user', 'string|non-empty-list<string>'],
            [Accounts::class, 'convertAddonGetConversionStatus', 'jobId', 'int|non-empty-list<int>'],
            ] as [$class, $method, $name, $shape]
        ) {
            $reflection = new ReflectionMethod($class, $method);
            self::assertSame(1, $reflection->getNumberOfRequiredParameters());
            self::assertSame(1, $reflection->getNumberOfParameters());
            $parameter = $reflection->getParameters()[0];
            self::assertSame($name, $parameter->getName());
            self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
            self::assertFalse($parameter->allowsNull());
            self::assertStringContainsString('@param ' . $shape . ' $' . $name, (string) $reflection->getDocComment());
            self::assertStringContainsString('array', (string) $parameter->getType());
            self::assertSame('array', (string) $reflection->getReturnType());
        }
    }

    #[DataProvider('results')]
    public function testResultsKeepPerItemOutcomesSeparateFromApiSuccess(
        bool $tfa,
        string $payload,
        mixed $expected,
    ): void {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, self::invoke($whm, $tfa, $tfa ? ['alice', 'bob'] : [33, 10]));
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{bool, string, mixed}> */
    public static function results(): iterable
    {
        yield 'TFA failed map and modified users' => [true,
            '{"failed":{"alice":"denied","other":""},"users_modified":["bob","no-prior-config"]}',
            ['failed' => ['alice' => 'denied', 'other' => ''], 'users_modified' => ['bob', 'no-prior-config']]];
        yield 'TFA empty containers' => [true, '{"failed":{},"users_modified":[]}',
            ['failed' => [], 'users_modified' => []]];
        yield 'TFA empty object' => [true, '{}', []];
        yield 'job map and string job IDs' => [false,
            '{"33":{"job_id":"33","job_status":"FAILED","source_acct":"source","job_end_time":0},'
                . '"10":{"job_id":"10","job_status":"IN PROGRESS"},"007":{},"1":{"job_status":"DONE"}}',
            [33 => ['job_end_time' => 0, 'job_id' => '33', 'job_status' => 'FAILED', 'source_acct' => 'source'],
                10 => ['job_id' => '10', 'job_status' => 'IN PROGRESS'], '007' => [], 1 => ['job_status' => 'DONE']]];
        yield 'job status stays an open string' => [false, '{"12":{"job_status":"UNKNOWN"}}',
            [12 => ['job_status' => 'UNKNOWN']]];
        yield 'job empty map' => [false, '{}', []];
    }

    #[DataProvider('operations')]
    public function testApiFailureStillThrowsAndRetainsMetadata(bool $tfa): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"denied"},"data":{}}'));
        try {
            self::invoke($whm, $tfa, $tfa ? ['alice'] : [12]);
            self::fail('API failure must throw.');
        } catch (ApiErrorException) {
            self::assertSame(0, $whm->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('malformedResults')]
    public function testMalformedSuccessStillFailsWithoutRetry(bool $tfa, ?string $payload): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            self::invoke($whm, $tfa, $tfa ? ['alice'] : [12]);
            self::fail('Malformed successful payload must fail.');
        } catch (PayloadMismatchException) {
            self::assertSame(1, $whm->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{bool, ?string}> */
    public static function malformedResults(): iterable
    {
        foreach ([true, false] as $tfa) {
            foreach ([null, 'null', 'false', '1'] as $index => $payload) {
                yield ($tfa ? 'TFA' : 'jobs') . ' root ' . $index => [$tfa, $payload];
            }
        }
        yield 'TFA null failure reason' => [true, '{"failed":{"alice":null}}'];
        yield 'TFA nonstring modified user' => [true, '{"users_modified":[12]}'];
        yield 'job ID remains string' => [false, '{"12":{"job_id":12}}'];
        yield 'job status remains string' => [false, '{"12":{"job_status":false}}'];
    }

    /** @return iterable<string, array{bool}> */
    public static function operations(): iterable
    {
        yield 'TFA' => [true];
        yield 'jobs' => [false];
    }

    private static function invoke(WhmClient $whm, bool $tfa, #[SensitiveParameter] mixed $input): mixed
    {
        // Dynamic invocation retains strict PHP boundary checks while permitting deliberately invalid fixtures.
        $group = $tfa ? $whm->authentication() : $whm->accounts();
        $method = self::method($tfa);
        return $group->{$method}($input);
    }

    private static function method(bool $tfa): string
    {
        return $tfa ? 'twoFactorAuthRemoveUserConfig' : 'convertAddonGetConversionStatus';
    }

    private static function operation(bool $tfa): string
    {
        return $tfa ? 'twofactorauth_remove_user_config' : 'convert_addon_get_conversion_status';
    }

    private static function envelope(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK"}' . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{WhmClient, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'synthetic-token'],
            new HttpTransport($http, $factory, $factory),
        );
        return [$client->whm(), $http];
    }
}
