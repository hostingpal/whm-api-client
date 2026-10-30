<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Api\RepeatedList;
use HostingPal\WhmApiClient\Api\SuffixNumberedList;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;

final class SuffixNumberedParametersTest extends TestCase
{
    private const SPEC = [
        'module' => 'Email', 'function' => 'store_filter', 'method' => 'POST', 'outcome' => 'flag',
        'params' => [
            'filters' => ['name' => 'filter*', 'encoding' => 'suffix-numbered'],
            'values' => ['name' => 'val*', 'encoding' => 'suffix-numbered-scalar'],
        ],
        'returns' => null,
    ];

    /** @return iterable<string, array{bool}> */
    public static function routes(): iterable
    {
        yield 'direct' => [true];
        yield 'bridge' => [false];
    }

    #[DataProvider('routes')]
    public function testSuffixListsUseOneBasedNamesAndPreserveWireOrder(bool $direct): void
    {
        [$uapi, $http] = $this->client($direct);
        $this->invoke($uapi, ['filters' => ['a b', '&'], 'values' => [0, '007', -2]]);
        $request = $this->request($http);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('filter1=a%20b&filter2=%26&val1=0&val2=007&val3=-2', (string) $request->getBody());
        self::assertStringNotContainsString('filter1=', $request->getUri()->getQuery());
        self::assertSame(
            $direct ? '' : 'api.version=1&cpanel.user=npr&cpanel.module=Email&cpanel.function=store_filter',
            $request->getUri()->getQuery(),
        );
        self::assertCount(1, $http->getRequests());
    }

    public function testScalarSuffixValuesBecomeTheFirstElementAndNullOmits(): void
    {
        [$uapi, $http] = $this->client(true);
        $this->invoke($uapi, ['filters' => 'one', 'values' => 0]);
        self::assertSame('filter1=one&val1=0', (string) $this->request($http)->getBody());
        $this->invoke($uapi, ['filters' => null, 'values' => null]);
        self::assertSame('', (string) $this->request($http)->getBody());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'empty string list' => ['filters', []];
        yield 'empty scalar list' => ['values', []];
        yield 'associative' => ['filters', ['key' => 'secret-fixture']];
        yield 'sparse' => ['filters', [1 => 'secret-fixture']];
        yield 'reordered' => ['values', [1 => 'secret-fixture', 0 => 'other']];
        yield 'integer string-only scalar' => ['filters', 2];
        yield 'integer string-only entry' => ['filters', [2]];
        yield 'float scalar' => ['values', 2.5];
        yield 'float entry' => ['values', [2.5]];
        yield 'bool scalar' => ['values', false];
        yield 'bool entry' => ['values', [false]];
        yield 'null entry' => ['values', [null]];
        yield 'nested' => ['values', [['secret-fixture']]];
        yield 'object' => ['values', (object) ['secret' => 'secret-fixture']];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidSuffixInputClearsMetadataAndNeverSends(string $argument, mixed $value): void
    {
        [$uapi, $http] = $this->client(true);
        $uapi->call('Email', 'store_filter');
        self::assertNotNull($uapi->responseMeta());
        try {
            $this->invoke($uapi, [$argument => $value]);
            self::fail('Expected invalid suffix input.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secret-fixture', $exception->getMessage());
            self::assertNull($uapi->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{array<string, array{name:string, encoding:string}>}> */
    public static function invalidSpecs(): iterable
    {
        foreach (['filter', '*', 'filter**', 'filter*x', 'filter-1*', '1filter*'] as $name) {
            yield $name => [['filters' => ['name' => $name, 'encoding' => 'suffix-numbered']]];
        }
        foreach (['scalar', 'repeat', 'numbered'] as $encoding) {
            yield 'unreviewed ' . $encoding => [['filters' => ['name' => 'filter*', 'encoding' => $encoding]]];
        }
        foreach (['filter1', 'filter12', 'filter1*'] as $name) {
            yield 'overlap ' . $name => [[
                'filters' => ['name' => 'filter*', 'encoding' => 'suffix-numbered'],
                'other' => ['name' => $name, 'encoding' => str_ends_with($name, '*') ? 'suffix-numbered' : 'scalar'],
            ]];
        }
    }

    /** @param array<string, array{name:string, encoding:string}> $params */
    #[DataProvider('invalidSpecs')]
    public function testMalformedOrOverlappingPatternsFailEvenWhenArgumentsAreOmitted(array $params): void
    {
        $this->expectException(LogicException::class);
        SpecChecker::check(['params' => $params] + self::SPEC);
    }

    public function testWireEncoderRejectsCollisionsWithSuffixExpansionsInEitherOrder(): void
    {
        foreach ([false, true] as $reverse) {
            [$uapi, $http] = $this->client(true);
            $parameters = ['filter*' => new SuffixNumberedList(['one', 'two']), 'filter2' => 'collision'];
            try {
                $uapi->call('Email', 'store_filter', $reverse ? array_reverse($parameters, true) : $parameters);
                self::fail('Expected an expanded wire collision.');
            } catch (InvalidArgumentException) {
                self::assertCount(0, $http->getRequests());
            }
        }
    }

    public function testExistingWhmNumberedAndRepeatedValuesKeepTheirWireConventions(): void
    {
        [$uapi, $http] = $this->client(true);
        $uapi->call('Email', 'store_filter', [
            'user' => new NumberedList(['one', 'two']),
            'domain' => new RepeatedList(['a.test', 'b.test']),
            'filter*' => new SuffixNumberedList(['one', 'two']),
        ]);
        self::assertSame(
            'user=one&user-1=two&domain=a.test&domain=b.test&filter1=one&filter2=two',
            (string) $this->request($http)->getBody(),
        );
    }

    public function testInvalidSuffixInputNeverAppearsInLibraryTraceArguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            [$uapi] = $this->client(true);
            try {
                $this->invoke($uapi, ['values' => [['suffix-secret-fixture']]]);
                self::fail('Expected invalid nested suffix value.');
            } catch (InvalidArgumentException $exception) {
                foreach ($exception->getTrace() as $frame) {
                    $class = $frame['class'] ?? '';
                    if (
                        str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                        && !str_contains($class, '\\Tests\\')
                    ) {
                        self::assertStringNotContainsString(
                            'suffix-secret-fixture',
                            print_r($frame['args'] ?? [], true),
                        );
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testMarkerConstructorValuesAreSensitive(): void
    {
        $parameter = (new ReflectionMethod(SuffixNumberedList::class, '__construct'))->getParameters()[0];
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(UapiClient $uapi, array $arguments): void
    {
        (new ReflectionMethod(UapiClient::class, 'run'))->invoke($uapi, self::SPEC, $arguments);
    }

    private function request(MockClient $http): RequestInterface
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    /** @return array{UapiClient, MockClient} */
    private function client(bool $direct): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $envelope = '{"data":null,"status":1,"errors":null}';
        $body = $direct ? '{"result":' . $envelope . '}'
            : '{"metadata":{"result":1},"data":{"uapi":' . $envelope . '}}';
        $http->setDefaultResponse(new Response(200, [], $body));
        $client = new Client([
            'host' => 'api.example.test', 'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'npr' : 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory));

        return [$client->uapi('npr'), $http];
    }
}
