<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Monitoring;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Client\Exception\NetworkException;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Source-bound synthetic requests; no authentication flow or server contact. */
final class WhmMonitoringTest extends TestCase
{
    protected function setUp(): void
    {
        [$client] = self::client();
        self::assertContains('monitoring', get_class_methods($client->whm()));
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('requests')]
    public function testNamespacedFormPostPreservesExactInputsAndMakesOneRequest(
        string $leaf,
        string $method,
        array $arguments,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($leaf, $payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->monitoring()->{$method}(...$arguments),
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/360Monitoring/' . $leaf . '?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('api.version=1', $request->getUri()->getQuery());
        self::assertSame($wire, (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertSame($leaf, $client->whm()->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string}> */
    public static function requests(): iterable
    {
        yield 'register string identifier' => ['register_server_with_360_monitoring',
            'registerServerWith360Monitoring', [], '', '{"user_id":"0012345"}'];
        yield 'poll all source ordered fields' => ['set_360_agent_polling', 'set360AgentPolling',
            ['global,agent', 300, 120, 'cpu,network'],
            'global_sections=global%2Cagent&interval=300&max_data_span=120&plugin_sections=cpu%2Cnetwork',
            '{"status":1}'];
        yield 'poll omitted values' => ['set_360_agent_polling', 'set360AgentPolling', [], '', '{}'];
        yield 'poll explicit null' => ['set_360_agent_polling', 'set360AgentPolling',
            [null, null, null, null], '', '{}'];
        yield 'poll empty and zero supplied' => ['set_360_agent_polling', 'set360AgentPolling', ['', 0, 0, ''],
            'global_sections=&interval=0&max_data_span=0&plugin_sections=', '{"status":0}'];
        yield 'poll literal commas duplicates spaces Unicode punctuation' => ['set_360_agent_polling',
            'set360AgentPolling', ['agent, global,agent,+&=%雪', -1, null, 'network,cpu,network'],
            'global_sections=agent%2C%20global%2Cagent%2C%2B%26%3D%25%E9%9B%AA&interval=-1'
                . '&plugin_sections=network%2Ccpu%2Cnetwork', '{"status":2}'];
        yield 'poll max span only' => ['set_360_agent_polling', 'set360AgentPolling', ['maxDataSpan' => 120],
            'max_data_span=120', '{}'];
        yield 'poll interval only' => ['set_360_agent_polling', 'set360AgentPolling', ['interval' => 300],
            'interval=300', '{}'];
        yield 'poll filters without interval forwarded for server validation' => ['set_360_agent_polling',
            'set360AgentPolling', ['pluginSections' => 'cpu'], 'plugin_sections=cpu', '{}'];
        yield 'poll both lists in source order' => ['set_360_agent_polling', 'set360AgentPolling',
            [['global', 'agent'], 300, 120, ['cpu', 'network']],
            'global_sections=global%2Cagent&interval=300&max_data_span=120&plugin_sections=cpu%2Cnetwork',
            '{"status":1}'];
        yield 'poll named arguments retain source wire order' => ['set_360_agent_polling', 'set360AgentPolling',
            ['pluginSections' => ['network', 'cpu'], 'maxDataSpan' => 0,
                'globalSections' => ['agent', 'global'], 'interval' => -1],
            'global_sections=agent%2Cglobal&interval=-1&max_data_span=0&plugin_sections=network%2Ccpu', '{}'];
        yield 'poll global singleton list independently' => ['set_360_agent_polling', 'set360AgentPolling',
            ['globalSections' => ['global']], 'global_sections=global', '{}'];
        yield 'poll plugin singleton list independently' => ['set_360_agent_polling', 'set360AgentPolling',
            ['pluginSections' => ['cpu']], 'plugin_sections=cpu', '{}'];
        yield 'poll global list plugin scalar' => ['set_360_agent_polling', 'set360AgentPolling',
            ['globalSections' => ['global', 'global'], 'pluginSections' => 'cpu, network,cpu'],
            'global_sections=global%2Cglobal&plugin_sections=cpu%2C%20network%2Ccpu', '{}'];
        yield 'poll global scalar plugin list' => ['set_360_agent_polling', 'set360AgentPolling',
            ['globalSections' => 'global, agent,global', 'pluginSections' => ['network', 'network']],
            'global_sections=global%2C%20agent%2Cglobal&plugin_sections=network%2Cnetwork', '{}'];
        yield 'poll empty list items remain supplied' => ['set_360_agent_polling', 'set360AgentPolling',
            ['globalSections' => [''], 'pluginSections' => ['', 'cpu', '']],
            'global_sections=&plugin_sections=%2Ccpu%2C', '{}'];
        yield 'poll list bytes form encoded exactly once' => ['set_360_agent_polling', 'set360AgentPolling',
            ['globalSections' => [' +&=%雪', "\x00\xff"], 'pluginSections' => ['a%20', ' /|']],
            'global_sections=%20%2B%26%3D%25%E9%9B%AA%2C%00%FF&plugin_sections=a%2520%2C%20%2F%7C', '{}'];
        yield 'callback nested summary' => ['webpros_auth_callback', 'webProsAuthCallback', ['{"code":"synthetic"}'],
            'json=%7B%22code%22%3A%22synthetic%22%7D',
            '{"success":1,"token_data":{"email":"user@example.test","expires_in":900}}'];
        yield 'callback bytes and unknown keys encoded once' => ['webpros_auth_callback', 'webProsAuthCallback',
            [" {\n\"code\":\"a+&=%/雪\",\"state\":\"s\",\"unknown\":true }\n"],
            'json=%20%7B%0A%22code%22%3A%22a%2B%26%3D%25%2F%E9%9B%AA%22%2C'
                . '%22state%22%3A%22s%22%2C%22unknown%22%3Atrue%20%7D%0A',
            '{"error":"private error","reason":"future_reason","success":0}'];
        yield 'callback invalid JSON left for server' => ['webpros_auth_callback', 'webProsAuthCallback',
            ['not JSON +%&'], 'json=not%20JSON%20%2B%25%26', '{}'];
        yield 'callback scalar JSON left for server' => ['webpros_auth_callback', 'webProsAuthCallback',
            ['42'], 'json=42', '{}'];
        yield 'callback empty string supplied' => ['webpros_auth_callback', 'webProsAuthCallback', [''], 'json=', '{}'];
        yield 'callback binary bytes preserved' => ['webpros_auth_callback', 'webProsAuthCallback', ["\x00\xff"],
            'json=%00%FF', '{}'];
        yield 'clear zero is result' => ['webpros_auth_clear', 'webProsAuthClear', [], '',
            '{"error":"private","reason":"removal_failed","success":0}'];
        yield 'start existing session leading zeros' => ['webpros_auth_start', 'webProsAuthStart', ['0012345678'],
            'session_id=0012345678',
            '{"auth_url":"https://auth.example.test/?state=synthetic+a&x=雪","state":"private-state"}'];
        yield 'start omitted session' => ['webpros_auth_start', 'webProsAuthStart', [], '',
            '{"error":"private session detail","reason":"missing_parameter"}'];
        yield 'start explicit null' => ['webpros_auth_start', 'webProsAuthStart', [null], '', '{}'];
        yield 'start empty session' => ['webpros_auth_start', 'webProsAuthStart', [''], 'session_id=', '{}'];
        yield 'start punctuation remains server validation' => ['webpros_auth_start', 'webProsAuthStart', ['00+&=%/雪'],
            'session_id=00%2B%26%3D%25%2F%E9%9B%AA', '{}'];
        yield 'status full zero result' => ['webpros_auth_status', 'webProsAuthStatus', [], '',
            '{"email":"user@example.test","error":"","expired_at":0,"expires_in":-1,'
                . '"reason":"future_reason","valid":0}'];
        yield 'status usable' => ['webpros_auth_status', 'webProsAuthStatus', [], '', '{"valid":1}'];
        yield 'token bearer and unknown expiry' => ['webpros_auth_token', 'webProsAuthToken', [], '',
            '{"access_token":"synthetic-private-bearer","error":"","expires_in":0,"reason":"future_reason"}'];
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function operations(): iterable
    {
        yield 'register' => ['register_server_with_360_monitoring', 'registerServerWith360Monitoring', []];
        yield 'polling' => ['set_360_agent_polling', 'set360AgentPolling', []];
        yield 'callback' => ['webpros_auth_callback', 'webProsAuthCallback', ['synthetic-private-code']];
        yield 'clear' => ['webpros_auth_clear', 'webProsAuthClear', []];
        yield 'start' => ['webpros_auth_start', 'webProsAuthStart', []];
        yield 'status' => ['webpros_auth_status', 'webProsAuthStatus', []];
        yield 'token' => ['webpros_auth_token', 'webProsAuthToken', []];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testEmptyObjectsFilteringAndFreshCalls(string $leaf, string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        foreach (['{}', '[]', '{"unknown":"private"}'] as $payload) {
            $http->addResponse(new Response(200, [], self::envelope($leaf, $payload)));
            self::assertSame([], $client->whm()->monitoring()->{$method}(...$arguments));
        }
        self::assertCount(3, $http->getRequests());
    }

    public function testNestedSummaryPreservesPresenceAndFiltersUnknownFields(): void
    {
        [$client, $http] = self::client();
        foreach (
            [
            ['{"token_data":{}}', ['token_data' => []]],
            ['{"token_data":[]}', ['token_data' => []]],
            ['{"token_data":{"email":"","expires_in":0,"access_token":"private"},"extra":true}',
                ['token_data' => ['email' => '', 'expires_in' => 0]]]] as [$payload, $expected]
        ) {
            $http->addResponse(new Response(200, [], self::envelope('webpros_auth_callback', $payload)));
            self::assertSame($expected, $client->whm()->monitoring()->webProsAuthCallback('{}'));
        }
        self::assertCount(3, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operations() as $name => [$leaf, $method, $arguments]) {
            foreach ([null, 'null', 'false', '1', '1.5', '"object"', '[1]'] as $i => $bad) {
                yield "$name root $i" => [$leaf, $method, $arguments, $bad];
            }
        }
        // Independent field inventory: deleting or weakening any generated leaf loses one of these checks.
        $fields = [
            ['register_server_with_360_monitoring', 'registerServerWith360Monitoring', [], 'user_id', 'string'],
            ['set_360_agent_polling', 'set360AgentPolling', [], 'status', 'int'],
            ['webpros_auth_callback', 'webProsAuthCallback', ['{}'], 'error', 'string'],
            ['webpros_auth_callback', 'webProsAuthCallback', ['{}'], 'reason', 'string'],
            ['webpros_auth_callback', 'webProsAuthCallback', ['{}'], 'success', 'flag'],
            ['webpros_auth_callback', 'webProsAuthCallback', ['{}'], 'token_data', 'object'],
            ['webpros_auth_callback', 'webProsAuthCallback', ['{}'], 'token_data.email', 'string'],
            ['webpros_auth_callback', 'webProsAuthCallback', ['{}'], 'token_data.expires_in', 'int'],
            ['webpros_auth_clear', 'webProsAuthClear', [], 'error', 'string'],
            ['webpros_auth_clear', 'webProsAuthClear', [], 'reason', 'string'],
            ['webpros_auth_clear', 'webProsAuthClear', [], 'success', 'flag'],
            ['webpros_auth_start', 'webProsAuthStart', [], 'auth_url', 'string'],
            ['webpros_auth_start', 'webProsAuthStart', [], 'error', 'string'],
            ['webpros_auth_start', 'webProsAuthStart', [], 'reason', 'string'],
            ['webpros_auth_start', 'webProsAuthStart', [], 'state', 'string'],
            ['webpros_auth_status', 'webProsAuthStatus', [], 'email', 'string'],
            ['webpros_auth_status', 'webProsAuthStatus', [], 'expired_at', 'int'],
            ['webpros_auth_status', 'webProsAuthStatus', [], 'error', 'string'],
            ['webpros_auth_status', 'webProsAuthStatus', [], 'expires_in', 'int'],
            ['webpros_auth_status', 'webProsAuthStatus', [], 'reason', 'string'],
            ['webpros_auth_status', 'webProsAuthStatus', [], 'valid', 'flag'],
            ['webpros_auth_token', 'webProsAuthToken', [], 'access_token', 'string'],
            ['webpros_auth_token', 'webProsAuthToken', [], 'error', 'string'],
            ['webpros_auth_token', 'webProsAuthToken', [], 'expires_in', 'int'],
            ['webpros_auth_token', 'webProsAuthToken', [], 'reason', 'string'],
        ];
        foreach ($fields as [$leaf, $method, $arguments, $field, $type]) {
            $badValues = match ($type) {
                'string' => ['null', '1', '1.5', 'false', '[]', '{}'],
                'int' => ['null', '"1"', '1.0', 'true', '[]', '{}'],
                'flag' => ['null', '"0"', '"1"', 'true', '0.0', '2', '-1', '[]', '{}'],
                default => ['null', 'false', '1', '"object"', '[1]'],
            };
            foreach ($badValues as $bad) {
                $parts = explode('.', $field);
                $payload = count($parts) === 1 ? '{"' . $field . '":' . $bad . '}'
                    : '{"token_data":{"' . $parts[1] . '":' . $bad . '}}';
                yield "$leaf $field $bad" => [$leaf, $method, $arguments, $payload];
            }
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('invalidPayloads')]
    public function testStrictMalformedFieldsAndRootsRetainConfirmedOutcome(
        string $leaf,
        string $method,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($leaf, $payload)));
        try {
            $client->whm()->monitoring()->{$method}(...$arguments);
            self::fail('Invalid known payload must mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($error->metadata, $client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testWhmFailurePrecedesMalformedPayloadAndDoesNotRetry(
        string $leaf,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($leaf, 'false', 0)));
        try {
            $client->whm()->monitoring()->{$method}(...$arguments);
            self::fail('WHM failure must precede validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($error->metadata, $client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testLazyCachedIsolatedSelectorSignaturesSensitivityAndRetainedMetadata(): void
    {
        [$client, $http] = self::client();
        [$other] = self::client();
        $group = $client->whm()->monitoring();
        self::assertInstanceOf(Monitoring::class, $group);
        self::assertSame($group, $client->whm()->monitoring());
        self::assertNotSame($group, $other->whm()->monitoring());
        self::assertNull($client->whm()->responseMeta());
        self::assertSame([], $http->getRequests());
        foreach (self::operations() as [, $method]) {
            $reflection = new ReflectionMethod($group, $method);
            self::assertSame('array', (string) $reflection->getReturnType());
            self::assertSame(match ($method) {
                'set360AgentPolling' => 4,
                'webProsAuthCallback', 'webProsAuthStart' => 1,
                default => 0,
            }, $reflection->getNumberOfParameters());
            self::assertSame($method === 'webProsAuthCallback' ? 1 : 0, $reflection->getNumberOfRequiredParameters());
        }
        foreach (['webProsAuthCallback' => 'json', 'webProsAuthStart' => 'sessionId'] as $method => $name) {
            self::assertCount(1, (new ReflectionParameter([$group, $method], $name))
                ->getAttributes(SensitiveParameter::class));
        }
        self::assertSame(
            'string',
            (string) (new ReflectionParameter([$group, 'webProsAuthCallback'], 'json'))->getType(),
        );
        $session = new ReflectionParameter([$group, 'webProsAuthStart'], 'sessionId');
        self::assertSame('?string', (string) $session->getType());
        self::assertNull($session->getDefaultValue());
        $polling = (new ReflectionMethod($group, 'set360AgentPolling'))->getParameters();
        self::assertSame(
            ['globalSections', 'interval', 'maxDataSpan', 'pluginSections'],
            array_map(static fn (ReflectionParameter $p): string => $p->getName(), $polling),
        );
        self::assertSame(
            ['array|string|null', '?int', '?int', 'array|string|null'],
            array_map(static fn (ReflectionParameter $p): string => (string) $p->getType(), $polling),
        );
        foreach (['globalSections', 'pluginSections'] as $name) {
            self::assertCount(1, (new ReflectionParameter([$group, 'set360AgentPolling'], $name))
                ->getAttributes(SensitiveParameter::class));
        }
        foreach ($polling as $parameter) {
            self::assertNull($parameter->getDefaultValue());
        }
        $http->addResponse(new Response(200, [], self::envelope('webpros_auth_status', '{"valid":0}')));
        $group->webProsAuthStatus();
        $snapshot = $client->whm()->responseMeta();
        self::assertSame($group, $client->whm()->monitoring());
        self::assertSame($snapshot, $client->whm()->responseMeta());
        self::assertNull($other->whm()->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function invalidArguments(): iterable
    {
        yield 'callback null' => ['webProsAuthCallback', [null]];
        yield 'callback array' => ['webProsAuthCallback', [['code' => 'synthetic']]];
        yield 'callback integer' => ['webProsAuthCallback', [1]];
        yield 'callback absent' => ['webProsAuthCallback', []];
        yield 'session integer' => ['webProsAuthStart', [123]];
        yield 'poll interval string' => ['set360AgentPolling', ['interval' => '300']];
        yield 'poll span float' => ['set360AgentPolling', ['maxDataSpan' => 1.5]];
        yield 'poll global integer' => ['set360AgentPolling', ['globalSections' => 1]];
        yield 'poll plugin boolean' => ['set360AgentPolling', ['pluginSections' => false]];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('invalidArguments')]
    public function testStrictNativeArgumentFailurePrecedesMetadataReset(string $method, array $arguments): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('webpros_auth_status', '{"valid":0}')));
        $client->whm()->monitoring()->webProsAuthStatus();
        $snapshot = $client->whm()->responseMeta();
        try {
            $client->whm()->monitoring()->{$method}(...$arguments);
            self::fail('Native argument failure expected.');
        } catch (TypeError) {
            self::assertSame($snapshot, $client->whm()->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function invalidSectionLists(): iterable
    {
        $invalid = [
            'empty' => [],
            'sparse' => [1 => 'synthetic-private-section'],
            'associative' => ['synthetic-private-key' => 'synthetic-private-section'],
            'null item' => ['synthetic-private-section', null],
            'integer item' => ['synthetic-private-section', 1],
            'boolean item' => ['synthetic-private-section', false],
            'nested item' => ['synthetic-private-section', ['synthetic-private-nested']],
            'comma item' => ['synthetic-private,section'],
            'late comma item' => ['synthetic-private-first', 'synthetic-private-second', 'synthetic-private,late'],
        ];
        foreach (['globalSections', 'pluginSections'] as $parameter) {
            foreach ($invalid as $name => $value) {
                yield "$parameter $name" => ['set360AgentPolling', $parameter, $value];
            }
        }
    }

    /** @param array<array-key, mixed> $value */
    #[DataProvider('invalidSectionLists')]
    public function testInvalidSectionListClearsMetadataWithoutRequestAndConcealsValues(
        string $method,
        string $parameter,
        array $value,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('webpros_auth_status', '{"valid":0}')));
        $client->whm()->monitoring()->webProsAuthStatus();
        self::assertNotNull($client->whm()->responseMeta());
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                $client->whm()->monitoring()->{$method}(...[$parameter => $value]);
                self::fail('Invalid section list must fail before dispatch.');
            } catch (InvalidArgumentException $error) {
                self::assertStringNotContainsString('synthetic-private-', $error->getMessage());
                self::assertNull($client->whm()->responseMeta());
                $seen = 0;
                $seenMethod = false;
                foreach ($error->getTrace() as $frame) {
                    $class = $frame['class'] ?? '';
                    if (
                        str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                        && !str_contains($class, '\\Tests\\')
                    ) {
                        $seen++;
                        self::assertPrivateValueAbsent($frame['args'] ?? []);
                        if ($class === Monitoring::class && $frame['function'] === 'set360AgentPolling') {
                            $seenMethod = true;
                            $traceArguments = $frame['args'] ?? [];
                            self::assertInstanceOf(
                                SensitiveParameterValue::class,
                                $traceArguments[$parameter === 'globalSections' ? 0 : 3] ?? null,
                            );
                        }
                    }
                }
                self::assertGreaterThan(0, $seen);
                self::assertTrue($seenMethod);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{int, string, class-string<\Throwable>, bool}> */
    public static function failedResponses(): iterable
    {
        yield 'HTTP before invalid JSON' => [503, 'not json', HttpException::class, false];
        yield 'JSON before envelope' => [200, 'not json', InvalidResponseException::class, false];
        yield 'missing metadata before payload' => [200, '{"data":false}', InvalidResponseException::class, false];
        yield 'quoted metadata result' => [200, '{"metadata":{"result":"1"},"data":false}',
            InvalidResponseException::class, true];
        yield 'invalid metadata result' => [200, '{"metadata":{"result":2},"data":false}',
            InvalidResponseException::class, true];
    }

    /** @param class-string<\Throwable> $expected */
    #[DataProvider('failedResponses')]
    public function testFailedAttemptResetsMetadataAndDoesNotRetry(
        int $status,
        string $body,
        string $expected,
        bool $retainsMetadata,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('webpros_auth_status', '{}')));
        $client->whm()->monitoring()->webProsAuthStatus();
        $snapshot = $client->whm()->responseMeta();
        $http->addResponse(new Response($status, [], $body));
        try {
            $client->whm()->monitoring()->webProsAuthCallback('synthetic-private-code');
            self::fail('Failed attempt expected.');
        } catch (InvalidResponseException | HttpException $error) {
            self::assertInstanceOf($expected, $error);
            self::assertSame($error->metadata, $client->whm()->responseMeta());
            self::assertSame($retainsMetadata, $client->whm()->responseMeta() !== null);
            self::assertNotSame($snapshot, $client->whm()->responseMeta());
            self::assertSame(1, $snapshot?->whmResult());
        }
        self::assertCount(2, $http->getRequests());
    }

    public function testTransportFailureClearsMetadataAndNeverRetriesSingleUseCallback(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('webpros_auth_status', '{}')));
        $client->whm()->monitoring()->webProsAuthStatus();
        $http->addException(new NetworkException(
            'synthetic transport failure',
            new Request('POST', 'https://fixture.test'),
        ));
        try {
            $client->whm()->monitoring()->webProsAuthCallback('{"code":"synthetic","state":"single-use"}');
            self::fail('Transport failure expected.');
        } catch (TransportException) {
            self::assertNull($client->whm()->responseMeta());
        }
        self::assertCount(2, $http->getRequests());
    }

    public function testFreshPayloadsWarningsAndRawMetadataAreAvailableWithoutImplicitStatusRead(): void
    {
        [$client, $http] = self::client();
        $body = '{"metadata":{"result":1,"reason":"Synthetic",'
            . '"private_detail":"retained","warnings":["synthetic warning"]},'
            . '"data":{"access_token":"first","expires_in":0}}';
        $http->addResponse(new Response(200, [], $body));
        self::assertSame(
            ['access_token' => 'first', 'expires_in' => 0],
            $client->whm()->monitoring()->webProsAuthToken(),
        );
        $snapshot = $client->whm()->responseMeta();
        self::assertSame(['synthetic warning'], $snapshot?->whm['warnings'] ?? null);
        self::assertSame('retained', $snapshot?->whm['private_detail'] ?? null);
        $http->addResponse(new Response(200, [], self::envelope('webpros_auth_token', '{"reason":"no_valid_token"}')));
        self::assertSame(['reason' => 'no_valid_token'], $client->whm()->monitoring()->webProsAuthToken());
        self::assertNotSame($snapshot, $client->whm()->responseMeta());
        self::assertSame(['synthetic warning'], $snapshot->whm['warnings']);
        self::assertCount(2, $http->getRequests());
    }

    public function testMismatchSnapshotAndWarningsRemainStableAfterLaterSuccess(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":1,"warnings":["private warning"]},"data":{"valid":"0"}}',
        ));
        try {
            $client->whm()->monitoring()->webProsAuthStatus();
            self::fail('Quoted flag must mismatch.');
        } catch (PayloadMismatchException $error) {
            $snapshot = $error->metadata;
            $http->addResponse(new Response(200, [], self::envelope('webpros_auth_status', '{"valid":1}')));
            self::assertSame(['valid' => 1], $client->whm()->monitoring()->webProsAuthStatus());
            self::assertNotSame($snapshot, $client->whm()->responseMeta());
            self::assertSame(['private warning'], $error->metadata?->whm['warnings'] ?? null);
            self::assertSame(['valid' => '0'], $error->payload);
        }
        self::assertCount(2, $http->getRequests());
    }

    public function testSensitiveInputsAndResponseFramesConcealPrivateContentsButSnapshotIsCallerReadable(): void
    {
        [$client, $http] = self::client();
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach (['webProsAuthCallback', 'webProsAuthStart', 'webProsAuthToken'] as $method) {
                $http->addResponse(new Response(200, [], self::envelope(
                    'synthetic',
                    '{"access_token":1,"state":1,"success":"1","private":"synthetic-private-response"}',
                )));
                try {
                    if ($method === 'webProsAuthToken') {
                        $client->whm()->monitoring()->webProsAuthToken();
                    } else {
                        $client->whm()->monitoring()->{$method}('synthetic-private-input');
                    }
                    self::fail('Malformed private response expected.');
                } catch (PayloadMismatchException $error) {
                    self::assertIsArray($error->payload);
                    self::assertSame('synthetic-private-response', $error->payload['private']);
                    $seen = 0;
                    foreach ($error->getTrace() as $frame) {
                        $class = $frame['class'] ?? '';
                        if (
                            str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                            && !str_contains($class, '\\Tests\\')
                        ) {
                            $seen++;
                            self::assertPrivateValueAbsent($frame['args'] ?? []);
                        }
                    }
                    self::assertGreaterThan(0, $seen);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
        self::assertCount(3, $http->getRequests());
    }

    private static function assertPrivateValueAbsent(mixed $value): void
    {
        if ($value instanceof SensitiveParameterValue) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertPrivateValueAbsent($item);
            }
        }
        if (is_string($value)) {
            self::assertStringNotContainsString('synthetic-private-', $value);
        }
    }

    private static function envelope(string $leaf, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"' . $leaf . '","namespace":"360Monitoring","result":' . $result
            . ',"reason":"Synthetic"}' . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'fixture-token'], new HttpTransport($http, $factory, $factory)), $http];
    }
}
