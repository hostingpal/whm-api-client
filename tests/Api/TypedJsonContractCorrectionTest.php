<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Engine\StructuredJsonEncoder;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/** Source and wire oracles authored independently from generated StatsManager descriptors. */
final class TypedJsonContractCorrectionTest extends TestCase
{
    public function testWhmTypedObjectInsertsVersionFirstWithoutQuery(): void
    {
        foreach ([[], ['value' => 'literal']] as $input) {
            [$client, $http] = self::client(false);
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":7}'));
            self::assertSame(7, (new ReflectionMethod($client->whm(), 'run'))->invoke(
                $client->whm(),
                self::spec(false),
                ['configuration' => $input],
            ));
            self::assertCount(1, $http->getRequests());
            $request = $http->getRequests()[0];
            self::assertSame('', $request->getUri()->getQuery());
            self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
            self::assertSame(
                $input === [] ? '{"api.version":1}' : '{"api.version":1,"value":"literal"}',
                (string) $request->getBody(),
            );
        }
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedSchemas(): iterable
    {
        foreach (['string', 'int', 'number', 'bool', 'flag', 'null'] as $type) {
            yield $type => [['type' => $type]];
        }
        yield 'list' => [['type' => 'list', 'items' => ['type' => 'string']]];
        yield 'nullable object' => [['type' => 'object', 'fields' => [], 'nullable' => true]];
        foreach (['api.future', 'api.', 'cpanel.user', 'cpanel.module', 'cpanel.function'] as $name) {
            foreach ([false, true] as $required) {
                yield $name . ($required ? ' required' : ' optional') => [[
                    'type' => 'object', 'fields' => [$name => ['type' => 'string']],
                    'required' => $required ? [$name] : [],
                ]];
            }
        }
    }

    /** @param array<mixed> $schema */
    #[DataProvider('malformedSchemas')]
    public function testMalformedWholeBodySchemaIsExactLogicErrorBeforeIo(array $schema): void
    {
        [$client, $http] = self::client(true);
        $handle = $client->uapi();
        $http->addResponse(new Response(200, [], '{"status":1,"data":[]}'));
        $handle->statsManager()->getConfiguration();
        self::assertNotNull($handle->responseMeta());
        $spec = self::spec(true);
        $spec['json_body']['schema'] = $schema;
        self::assertExactError(LogicException::class, static fn () => (new ReflectionMethod($handle, 'run'))->invoke(
            $handle,
            $spec,
            ['configuration' => []],
        ));
        self::assertCount(1, $http->getRequests());
        self::assertNull($handle->responseMeta());
    }

    public function testOptionalWhmBodySpecRejectsBeforeIoAndClearsMetadata(): void
    {
        foreach ([[], ['configuration' => null], ['configuration' => []]] as $arguments) {
            [$client, $http] = self::client(false);
            $handle = $client->whm();
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":7}'));
            $handle->call('version');
            self::assertNotNull($handle->responseMeta());
            $spec = self::spec(false);
            $spec['json_body']['required'] = false;
            $run = new ReflectionMethod($handle, 'run');
            self::assertExactError(LogicException::class, static fn () => $run->invoke(
                $handle,
                $spec,
                $arguments,
            ));
            self::assertCount(1, $http->getRequests());
            self::assertNull($handle->responseMeta());
        }
    }

    public function testAnyJsonQueryDescriptorIsAWholeSpecError(): void
    {
        foreach ([false, true] as $direct) {
            [$client, $http] = self::client($direct);
            $handle = $direct ? $client->uapi() : $client->whm();
            $spec = self::spec($direct);
            $spec['params'] = ['query' => ['name' => 'ordinary', 'encoding' => 'scalar']];
            $run = new ReflectionMethod($handle, 'run');
            self::assertExactError(LogicException::class, static fn () => $run->invoke(
                $handle,
                $spec,
                ['configuration' => [], 'query' => 'secret'],
            ));
            self::assertCount(0, $http->getRequests());
            self::assertNull($handle->responseMeta());
        }
    }

    public function testNonemptyStringPolicyAndDefaultOffAreExplicit(): void
    {
        foreach (['0', '00', ' ', "\t", 'x'] as $value) {
            self::assertSame(json_encode($value), StructuredJsonEncoder::encode($value, [
                'type' => 'string', 'nonempty' => true,
            ]));
        }
        self::assertSame('""', StructuredJsonEncoder::encode('', ['type' => 'string', 'nonempty' => false]));
        self::assertSame('[]', StructuredJsonEncoder::encode([], [
            'type' => 'list', 'items' => ['type' => 'string'], 'nonempty' => false,
        ]));
        self::assertExactError(InvalidArgumentException::class, static fn () => StructuredJsonEncoder::encode('', [
            'type' => 'string', 'nonempty' => true,
        ]), 'must not be empty');
        foreach ([['type' => 'string', 'nonempty' => 1], ['type' => 'int', 'nonempty' => false]] as $bad) {
            self::assertExactError(LogicException::class, static fn () => StructuredJsonEncoder::encode('', $bad));
        }
    }

    public function testEmptyDomainRejectedButEmptyAnalyzerNameAndAnalyzersRemainPermitted(): void
    {
        [$client, $http] = self::client(true);
        $handle = $client->uapi();
        $http->addResponse(new Response(200, [], '{"status":1,"data":[]}'));
        $handle->statsManager()->getConfiguration();
        $save = new ReflectionMethod($handle->statsManager(), 'saveConfiguration');
        self::assertExactError(InvalidArgumentException::class, static fn () => $save->invoke(
            $handle->statsManager(),
            ['changes' => [['domain' => '', 'analyzers' => []]]],
        ), 'must not be empty');
        self::assertCount(1, $http->getRequests());
        self::assertNull($handle->responseMeta());
        foreach (['0', ' ', 'example.test'] as $domain) {
            $http->addResponse(new Response(200, [], '{"status":1,"data":[]}'));
            $handle->statsManager()->saveConfiguration(['changes' => [
                ['domain' => $domain, 'analyzers' => [['name' => '', 'enabled' => false]]],
                ['domain' => 'empty.test', 'analyzers' => []],
            ]]);
        }
        self::assertCount(4, $http->getRequests());
    }

    public function testSaveRowsEqualGetRowsAndRoundtripUsesUserChoice(): void
    {
        [$client, $http] = self::client(true);
        $json = '{"status":1,"metadata":{"locked":1,"analyzers":[{"name":"awstats","available_for_user":1}]},'
            . '"data":[{"domain":"example.test","analyzers":[{"name":"awstats","enabled":0,'
            . '"enabled_by_user":1,"available":1,"locked":0}]}]}';
        $http->addResponse(new Response(200, [], $json));
        $read = $client->uapi()->statsManager()->getConfiguration();
        $row = $read[0];
        $analyzers = $row['analyzers'] ?? null;
        self::assertIsArray($analyzers);
        $analyzer = $analyzers[0] ?? null;
        self::assertIsArray($analyzer);
        $name = $analyzer['name'] ?? null;
        $userChoice = $analyzer['enabled_by_user'] ?? null;
        self::assertIsString($name);
        self::assertIsInt($userChoice);
        $http->addResponse(new Response(200, [], $json));
        $saved = $client->uapi()->statsManager()->saveConfiguration(['changes' => [[
            'domain' => 'example.test', 'analyzers' => [[
                'name' => $name,
                'enabled' => $userChoice === 1,
            ]],
        ]]]);
        self::assertSame([['analyzers' => [[
            'enabled' => 0, 'enabled_by_user' => 1, 'name' => 'awstats',
        ]], 'domain' => 'example.test']], $read);
        self::assertSame($read, $saved);
        $metadata = $client->uapi()->responseMeta()?->uapi['metadata'] ?? null;
        self::assertIsArray($metadata);
        self::assertSame(1, $metadata['locked'] ?? null);
        self::assertSame(
            '{"changes":[{"domain":"example.test","analyzers":[{"name":"awstats","enabled":1}]}]}',
            (string) $http->getRequests()[1]->getBody(),
        );
        self::assertCount(2, $http->getRequests());
    }

    public function testActualStatsMethodDoesNotReadOrModifyIniSettings(): void
    {
        $code = <<<'PHP_CODE'
require 'vendor/autoload.php';
$http = new Http\Mock\Client();
$factory = new Nyholm\Psr7\Factory\Psr17Factory();
$client = new HostingPal\WhmApiClient\Client([
    'host' => 'api.example.test', 'authType' => 'cpanel', 'username' => 'alpha', 'token' => 'fixture-token',
], new HostingPal\WhmApiClient\Http\HttpTransport($http, $factory, $factory));
$http->addResponse(new Nyholm\Psr7\Response(200, [], '{"status":1,"data":[]}'));
$result = $client->uapi()->statsManager()->saveConfiguration([
    'changes' => [['domain' => 'example.test', 'analyzers' => [['name' => 'awstats', 'enabled' => false]]]],
]);
if ($result !== [] || count($http->getRequests()) !== 1) {
    exit(1);
}
echo (string) $http->getRequests()[0]->getBody();
PHP_CODE;
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=ini_get,ini_set', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr === false ? '' : $stderr);
        self::assertSame('', $stderr);
        self::assertSame(
            '{"changes":[{"domain":"example.test","analyzers":[{"name":"awstats","enabled":0}]}]}',
            $stdout,
        );
    }

    public function testChangedWholeSpecCannotUseAnEarlierSuccessfulCheck(): void
    {
        [$client, $http] = self::client(true);
        $handle = $client->uapi();
        $spec = self::spec(true);
        $http->addResponse(new Response(200, [], '{"status":1,"data":7}'));
        (new ReflectionMethod($handle, 'run'))->invoke($handle, $spec, ['configuration' => []]);
        self::assertNotNull($handle->responseMeta());
        $spec['json_body']['schema'] = [
            'type' => 'object', 'fields' => ['api.optional' => ['type' => 'string']],
        ];
        self::assertExactError(LogicException::class, static fn () => (new ReflectionMethod($handle, 'run'))->invoke(
            $handle,
            $spec,
            ['configuration' => []],
        ));
        self::assertCount(1, $http->getRequests());
        self::assertNull($handle->responseMeta());
    }

    /** @param class-string<Throwable> $class */
    private static function assertExactError(string $class, Closure $call, ?string $message = null): void
    {
        $caught = null;
        try {
            $call();
        } catch (Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught, 'Expected rejection before I/O.');
        self::assertSame($class, $caught::class);
        if ($message !== null) {
            self::assertStringContainsString($message, $caught->getMessage());
        }
    }

    /**
     * @return array{function: string, module?: string, method: string, body: string, outcome: string,
     *     params: array{}, json_body: array{php: string, required: bool, routes: string, schema: array<mixed>},
     *     returns: array{type: string}}
     */
    private static function spec(bool $direct): array
    {
        return [
            'function' => 'save', ...($direct ? ['module' => 'Example'] : []),
            'method' => 'POST', 'body' => 'json', 'outcome' => 'flag', 'params' => [],
            'json_body' => ['php' => 'configuration', 'required' => true, 'routes' => 'direct',
                'schema' => ['type' => 'object', 'fields' => [], 'values' => ['type' => 'string']]],
            'returns' => ['type' => 'int'],
        ];
    }

    /** @return array{Client, MockClient} */
    private static function client(bool $direct): array
    {
        $http = new MockClient();
        $factory = new Psr17Factory();
        return [new Client([
            'host' => 'api.example.test', 'port' => 2443, 'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'alpha' : 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
