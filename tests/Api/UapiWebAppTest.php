<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\WebApp;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use JsonSerializable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/**
 * Literal source-derived fixtures independent of the generated specs.
 *
 * @phpstan-type WebAppCall Closure(WebApp): mixed
 */
final class UapiWebAppTest extends TestCase
{
    public function testWebAppGroupIsDiscoverableAndCached(): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi('npr');
        self::assertInstanceOf(WebApp::class, $account->webApp());
        self::assertSame($account->webApp(), $account->webApp());
        self::assertCount(0, $http->getRequests());
    }

    /** @return array<string, array{string, WebAppCall, string, string}> */
    private static function operations(): array
    {
        return [
            'configure' => ['POST', static fn (WebApp $c): mixed => $c->configure(
                'app',
                '',
                '',
                '',
                '',
                "{ \"N\": 0.00, \"Q\":\"a\\n+&\" }",
                "X=\" a=b \"\nX=last",
                'production',
                '',
                '',
                '',
                false,
            ),
                'name=app&appdir=&build_command=&deployname=&domain=&env=%7B%20%22N%22%3A%200.00%'
                    . '2C%20%22Q%22%3A%22a%5Cn%2B%26%22%20%7D'
                    . '&env_text=X%3D%22%20a%3Db%20%22%0AX%3Dlast&mode=production&output_dir=&runtime_t'
                    . 'ag=&startup_command=&use_existing_domain=0',
                self::application('configure')],
            'configureStructured' => ['POST', static fn (WebApp $c): mixed => $c->configureStructured(
                'app',
                env: ['FALSE' => false, 'ZERO' => 0, 'S' => '0', 'EMPTY' => '', 'N' => null, 'F' => 1.5,
                'JSON' => '{"keep":"text"}'],
                useExistingDomain: true,
            ),
                'name=app&env=%7B%22FALSE%22%3Afalse%2C%22ZERO%22%3A0%2C%22S%22%3A%220%22%2C%22EM'
                    . 'PTY%22%3A%22%22%2C%22N%22%3Anull%2C%22F%22%3A1.5%2C%22JSON%22%3A%22%7B%5C%22keep'
                    . '%5C%22%3A%5C%22text%5C%22%7D%22%7D&use_existing_domain=1',
                self::application('configure')],
            'delete' => ['POST', static fn (WebApp $c): mixed => $c->delete('app', false),
                'name=app&verify=0', '{"removed":1}'],
            'deploy' => ['POST', static fn (WebApp $c): mixed => $c->deploy('app'), 'name=app', self::dispatch()],
            'fetch_logs' => ['GET', static fn (WebApp $c): mixed => $c->fetchLogs('build', 'app', 'dp+&', 0),
                'log_type=build&name=app&deploy_id=dp%2B%26&lines=0', '{"lines":["first\nprivate","last"]}'],
            'get_available' => ['GET', static fn (WebApp $c): mixed => $c->getAvailable(), '',
                '{"account":{"apps":{"available":0,"limit":4,"used":4}},"runtimes":{"node+custom":{"apps":{"used":0},'
                    . '"default_tag":"22","display_name":"Node","tags":{"22":["npm","pnpm"],"next":[]}}'
                    . ',"empty":{}}}'],
            'has_feature' => ['GET', static fn (WebApp $c): mixed => $c->hasFeature(), '', '{"has_feature":0}'],
            'list' => ['GET', static fn (WebApp $c): mixed => $c->listApplications(), '',
                '[' . self::application('list') . ',{}]'],
            'redeploy' => ['POST', static fn (WebApp $c): mixed => $c->redeploy('app', 'p a+&=%雪'),
                'name=app&sshkeypass=p%20a%2B%26%3D%25%E9%9B%AA', self::dispatch()],
            'restart' => ['POST', static fn (WebApp $c): mixed => $c->restart('app'),
                'name=app', '{"status":"running"}'],
            'set_mode' => ['POST', static fn (WebApp $c): mixed => $c->setMode('development', 'app'),
                'mode=development&name=app', '{"mode":"development","restarted":0}'],
            'stage' => ['POST', static fn (WebApp $c): mixed => $c->stage(
                'app',
                'git@example.test:a+&雪',
                'git',
                'packages/a b',
                'feature/a',
                'production',
                'sub.example.test',
                'nodejs',
                'key+&',
                'p a+&=%雪',
            ),
                'name=app&source=git%40example.test%3Aa%2B%26%E9%9B%AA&source_type=git&appdir=packages%2Fa%20b'
                    . '&branch=feature%2Fa&mode=production&parent=sub.example.test&runtime=nodejs&ssh_k'
                    . 'ey_name=key%2B%26'
                    . '&sshkeypass=p%20a%2B%26%3D%25%E9%9B%AA', self::application('stage')],
            'start' => ['POST', static fn (WebApp $c): mixed => $c->start('app'), 'name=app', '{"status":"running"}'],
            'stop' => ['POST', static fn (WebApp $c): mixed => $c->stop('app'), 'name=app', '{"status":"stopped"}'],
        ];
    }

    private static function dispatch(): string
    {
        return '{"deploy_id":"dp-01","sse_url":"https://never-fetch.example.test/events","task_id":"task/01"}';
    }

    private static function application(string $kind): string
    {
        $common = '"name":"app-2","build_command":null,"category":"server","container_name":null,'
            . '"deployed":false,"domain":"app-2.example.test","env":{"A":"0","B":""},"env_editable":true,'
            . '"env_status":"ok","framework":null,"mode":null,"package_manager":"npm","runtime":"nodejs",'
            . '"runtime_tag":"22","staged":true,"startup_command":null,"status":"created",'
            . '"url":"https://never-fetch.example.test","use_existing_domain":0,'
            . '"last_deploy":{"deploy_id":"dp-01","result":"failed","timestamp":"2026-01-01"'
            . ($kind === 'list' ? ',"error_category":{"opaque":[null,false,7]}' : '') . '},'
            . '"source":{"branch":null,"type":"zip","url":null'
            . ($kind === 'stage' ? ',"ssh_key_name":null' : '') . '}';
        $json = '{' . $common . ($kind !== 'stage' ? ',"deployname":null' : '')
            . ($kind !== 'list' ? ',"confidence":"low","defaults":{"build_command":null,"output_dir":null,'
                . '"runtime_tag":"22","startup_command":null}' : '') . '}';
        $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        ksort($record);
        ksort($record['last_deploy']);
        ksort($record['source']);
        return json_encode($record, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string, string, string, WebAppCall, string, string}> */
    public static function successes(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::operations() as $function => [$method, $call, $wire, $payload]) {
                yield "$route $function populated" => [$route, $function, $method, $call, $wire, $payload];
                yield "$route $function empty" => [$route, $function, $method, $call, $wire,
                    $function === 'list' ? '[]' : '{}'];
            }
            foreach ([null, ''] as $i => $pass) {
                yield "$route redeploy pass $i" => [$route, 'redeploy', 'POST',
                    static fn (WebApp $c): mixed => $c->redeploy('app', $pass),
                    'name=app' . ($pass === null ? '' : '&sshkeypass='), '{}'];
                yield "$route stage pass $i" => [$route, 'stage', 'POST',
                    static fn (WebApp $c): mixed => $c->stage('app', 'uploads/a +&.tar.gz', 'zip', sshKeyPass: $pass),
                    'name=app&source=uploads%2Fa%20%2B%26.tar.gz&source_type=zip'
                        . ($pass === null ? '' : '&sshkeypass='), '{}'];
            }
            foreach ([null, []] as $i => $env) {
                yield "$route structured empty $i" => [$route, 'configureStructured', 'POST',
                    static fn (WebApp $c): mixed => $c->configureStructured('app', env: $env),
                    'name=app' . ($env === null ? '' : '&env=%7B%7D'), '{}'];
            }
            yield "$route configure omitted" => [$route, 'configure', 'POST',
                static fn (WebApp $c): mixed => $c->configure('app'), 'name=app', '{}'];
            yield "$route raw clear" => [$route, 'configure', 'POST',
                static fn (WebApp $c): mixed => $c->configure('app', env: '{}'), 'name=app&env=%7B%7D', '{}'];
            yield "$route both structured and text" => [$route, 'configureStructured', 'POST',
                static fn (WebApp $c): mixed => $c->configureStructured('app', env: [], envText: 'A=1'),
                'name=app&env=%7B%7D&env_text=A%3D1', '{}'];
            yield "$route raw env is opaque" => [$route, 'configure', 'POST',
                static fn (WebApp $c): mixed => $c->configure('app', env: 'not JSON', envText: 'no equals'),
                'name=app&env=not%20JSON&env_text=no%20equals', '{}'];
            foreach (['configure', 'configureStructured', 'stage', 'list'] as $function) {
                foreach (
                    ['{"env":{},"last_deploy":null}',
                    '{"deployed":true,"env_editable":false,"source":{"branch":"main",'
                        . '"type":"git","url":"git@example.test:a"},"staged":false}',
                    '{"framework":"custom","last_deploy":{},"mode":"production"}'] as $i => $payload
                ) {
                    yield "$route $function optional variants $i" => [$route, $function,
                        self::operations()[$function][0], self::operations()[$function][1],
                        self::operations()[$function][2], $function === 'list' ? "[$payload]" : $payload];
                }
            }
            yield "$route has feature available" => [$route, 'has_feature', 'GET',
                static fn (WebApp $c): mixed => $c->hasFeature(), '', '{"has_feature":1}'];
            yield "$route restart completed" => [$route, 'set_mode', 'POST',
                static fn (WebApp $c): mixed => $c->setMode('production', 'app'),
                'mode=production&name=app', '{"mode":"production","restarted":1}'];
            yield "$route scalar restrictions left to server" => [$route, 'configureStructured', 'POST',
                static fn (WebApp $c): mixed => $c->configureStructured('app', env: ['not a server name' => null]),
                'name=app&env=%7B%22not%20a%20server%20name%22%3Anull%7D', '{}'];
            yield "$route enums remain documentation" => [$route, 'set_mode', 'POST',
                static fn (WebApp $c): mixed => self::invoke($c, 'setMode', ['custom', 'app']),
                'mode=custom&name=app', '{"mode":"custom","restarted":0}'];
            yield "$route fetch defaults" => [$route, 'fetch_logs', 'GET',
                static fn (WebApp $c): mixed => $c->fetchLogs('app', 'app'), 'log_type=app&name=app', '{"lines":[]}'];
        }
    }

    /** @param WebAppCall $call */
    #[DataProvider('successes')]
    public function testSingleRequestMatchesIndependentWireAndPayload(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $wire,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $call($account->webApp()));
        $wireFunction = $function === 'configureStructured' ? 'configure' : $function;
        self::assertRequest($http, $route, $wireFunction, $method, $wire);
        self::assertCount(1, $http->getRequests());
        $meta = $account->responseMeta();
        self::assertNotNull($meta);
        self::assertSame(1, $meta->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $meta->whmResult());
        self::assertSame(['Fixture warning'], $meta->uapiWarnings());
        self::assertIsArray($meta->uapi);
        $metadata = $meta->uapi['metadata'] ?? null;
        self::assertIsArray($metadata);
        self::assertSame('immediate_only', $metadata['error_category']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (self::operations() as $function => $_) {
                foreach (['null', 'false', '7', '"text"'] as $i => $bad) {
                    yield "$route $function root $i" => [$route, $function, $bad];
                }
            }
            foreach (['configure', 'configureStructured', 'stage', 'list'] as $function) {
                foreach (
                    ['{"staged":1}', '{"deployed":"0"}', '{"env_editable":null}', '{"env":{"A":0}}',
                    '{"last_deploy":false}', '{"last_deploy":{"timestamp":null}}', '{"source":{"branch":0}}',
                    '{"source":null}', '{"mode":0}', '{"container_name":false}'] as $i => $bad
                ) {
                    yield "$route $function nested $i" => [$route, $function, $function === 'list' ? "[$bad]" : $bad];
                }
            }
            foreach (
                ['{"runtimes":{"nodejs":{"apps":{}}}}', '{"runtimes":{"nodejs":{"apps":{"used":null}}}}',
                '{"runtimes":{"nodejs":{"apps":{"used":"0"}}}}', '{"runtimes":{"nodejs":{"tags":{"22":[7]}}}}',
                '{"account":{"apps":{"limit":null}}}', '{"runtimes":null}'] as $i => $bad
            ) {
                yield "$route availability $i" => [$route, 'get_available', $bad];
            }
            foreach (['"0"', 'false', 'null', '2'] as $i => $flag) {
                yield "$route has flag $i" => [$route, 'has_feature', '{"has_feature":' . $flag . '}'];
                yield "$route restart flag $i" => [$route, 'set_mode', '{"restarted":' . $flag . '}'];
            }
            yield "$route logs" => [$route, 'fetch_logs', '{"lines":[null]}'];
            yield "$route delete" => [$route, 'delete', '{"removed":"1"}'];
            yield "$route dispatch" => [$route, 'deploy', '{"deploy_id":null}'];
            yield "$route stage nullable key" => [$route, 'stage', '{"source":{"ssh_key_name":7}}'];
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testStrictSourcePayloadContracts(string $route, string $function, string $payload): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            self::operations()[$function][1]($account->webApp());
            self::fail('The malformed source payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function categories(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['null', '"build_failed"', '7', 'false', '["opaque"]', '{"x":null}'] as $category) {
                yield "$route $category" => [$route, $category];
            }
        }
    }

    #[DataProvider('categories')]
    public function testOnlyListPreservesOpaqueDeployCategory(string $route, string $category): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        foreach (['list', 'configure', 'stage'] as $function) {
            $payload = '{"last_deploy":{"error_category":' . $category . ',"unlisted":7},'
                . '"source":{"ssh_key_name":null},"confidence":"high","deployname":null,"unlisted":7}';
            $response = self::envelope($route, $function === 'list' ? "[$payload]" : $payload);
            $http->addResponse(new Response(200, [], $response));
            $data = self::operations()[$function][1]($account->webApp());
            self::assertIsArray($data);
            $row = $function === 'list' ? $data[0] : $data;
            self::assertIsArray($row);
            $expected = $function === 'list' ? ['error_category' => json_decode($category, true)] : [];
            self::assertSame($expected, $row['last_deploy']);
            self::assertArrayNotHasKey('unlisted', $row);
            self::assertSame($function === 'stage' ? ['ssh_key_name' => null] : [], $row['source']);
            self::assertSame($function !== 'list', array_key_exists('confidence', $row));
            self::assertSame($function !== 'stage', array_key_exists('deployname', $row));
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidEnvironments(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (
                [
                ['nested' => []], ['object' => new \stdClass()], [0 => 'integer key'],
                ['float' => NAN], ['float' => INF], ['float' => -INF], ['bad' => "\xff"], ["\xff" => 'bad'],
                ['callback' => new class implements JsonSerializable {
                    public function jsonSerialize(): mixed
                    {
                        throw new RuntimeException('Must not invoke serialization.');
                    }
                }]] as $i => $bad
            ) {
                yield "$route $i" => [$route, $bad];
            }
        }
    }

    /** @param array<mixed> $bad */
    #[DataProvider('invalidEnvironments')]
    public function testBadLastMapValueFailsBeforeIOWithValueFreeTrace(
        string $route,
        #[SensitiveParameter] array $bad,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->webApp()->hasFeature();
        $secret = 'private-env-marker';
        $env = [$secret . '-key' => $secret . '-value', ...$bad];
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($account->webApp(), 'configureStructured', ['app', 'env' => $env]);
            self::fail('Invalid structured input must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($secret, $error->getMessage());
            self::assertNull($account->responseMeta());
            self::assertRedactedTrace($error, $secret);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testStructuredResourceRejectedWithoutReadingItAndFloatPrecisionRestored(): void
    {
        [$client, $http] = self::client('direct');
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        try {
            $arguments = ['app', 'env' => ['SECRET' => $resource]];
            self::invoke($client->uapi('npr')->webApp(), 'configureStructured', $arguments);
            self::fail('A resource must fail locally.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, ftell($resource));
            self::assertCount(0, $http->getRequests());
        } finally {
            fclose($resource);
        }
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '3');
        try {
            $http->addResponse(new Response(200, [], self::envelope('direct', '{}')));
            $client->uapi('npr')->webApp()->configureStructured('app', env: ['F' => 1.2345678901234567, 'ONE' => 1.0]);
            self::assertSame('3', ini_get('serialize_precision'));
            self::assertRequest(
                $http,
                'direct',
                'configure',
                'POST',
                'name=app&env=%7B%22F%22%3A1.2345678901234567%2C%22ONE%22%3A1.0%7D',
            );
        } finally {
            if ($before !== false) {
                ini_set('serialize_precision', $before);
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (array_keys(self::operations()) as $function) {
                $failures = ['api', 'http', 'json', 'transport', 'missing',
                    ...($route === 'bridge' ? ['outer'] : [])];
                foreach ($failures as $failure) {
                    yield "$route $function $failure" => [$route, $function, $failure];
                }
            }
        }
    }

    #[DataProvider('failures')]
    public function testFailureOrderingAndMetadataLifecycle(string $route, string $function, string $failure): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->webApp()->hasFeature();
        $before = $account->responseMeta();
        if ($failure === 'transport') {
            $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
            });
        } else {
            $http->addResponse(match ($failure) {
                'api' => new Response(200, [], self::envelope($route, 'null', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0},"data":{"uapi":{"status":1,"data":null}}}'),
                'http' => new Response(503, [], 'not json'),
                'missing' => new Response(200, [], self::envelope($route, null)),
                default => new Response(200, [], 'not json'),
            });
        }
        try {
            self::operations()[$function][1]($account->webApp());
            self::fail('The failed attempt must throw.');
        } catch (ApiException $error) {
            self::assertNotSame($before, $account->responseMeta());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame($failure === 'transport', $error instanceof TransportException);
            if (in_array($failure, ['api', 'outer'], true)) {
                self::assertInstanceOf(ApiErrorException::class, $error);
                self::assertSame($failure === 'api' ? 0 : 1, $error->metadata?->uapiStatus());
            } elseif ($failure === 'missing') {
                self::assertInstanceOf(PayloadMismatchException::class, $error);
            } else {
                self::assertNull($account->responseMeta());
            }
            if ($failure === 'api') {
                self::assertNotNull($error->metadata);
                self::assertIsArray($error->metadata->uapi);
                self::assertArrayHasKey('metadata', $error->metadata->uapi);
                $immediate = $error->metadata->uapi['metadata'];
                self::assertIsArray($immediate);
                self::assertSame('immediate_only', $immediate['error_category']);
            }
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function sensitiveFailures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['stage', 'redeploy', 'configure', 'configureStructured'] as $method) {
                foreach (['api', 'http', 'json', 'payload'] as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    #[DataProvider('sensitiveFailures')]
    public function testSensitiveActualRunnerFrames(string $route, string $method, string $failure): void
    {
        [$client, $http] = self::client($route);
        $secret = 'private-webapp-marker';
        $http->addResponse(match ($failure) {
            'api' => new Response(200, [], self::envelope($route, '"' . $secret . '"', 0)),
            'http' => new Response(503, [], $secret),
            'json' => new Response(200, [], $secret),
            default => new Response(200, [], self::envelope($route, '"' . $secret . '"')),
        });
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            self::sensitiveCall($client->uapi('npr')->webApp(), $method, $secret);
            self::fail('Synthetic failure must throw.');
        } catch (ApiException $error) {
            self::assertStringNotContainsString($secret, $error->getMessage());
            self::assertRedactedTrace($error, $secret);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    private static function sensitiveCall(WebApp $group, string $method, #[SensitiveParameter] string $secret): mixed
    {
        return match ($method) {
            'stage' => $group->stage('app', $secret, 'git', sshKeyPass: $secret),
            'redeploy' => $group->redeploy('app', $secret),
            'configure' => $group->configure('app', buildCommand: $secret, env: $secret,
                envText: $secret, startupCommand: $secret),
            default => $group->configureStructured(
                'app',
                buildCommand: $secret,
                env: [$secret => $secret],
                envText: $secret,
                startupCommand: $secret,
            ),
        };
    }

    private static function assertRedactedTrace(#[SensitiveParameter] \Throwable $error, string $secret): void
    {
        $frames = 0;
        foreach ($error->getTrace() as $frame) {
            if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                $frames++;
                $seen = [];
                self::assertFalse(self::hasSecret($frame['args'] ?? [], $secret, $seen));
            }
        }
        self::assertGreaterThan(0, $frames);
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, string $secret, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, $secret);
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
                if (str_contains((string) $key, $secret) || self::hasSecret($item, $secret, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function testPublicSignaturesSensitivityAndRequiredDeleteConfirmation(): void
    {
        $configure = ['name', 'appdir', 'buildCommand', 'deployname', 'domain', 'env', 'envText', 'mode',
            'outputDir', 'runtimeTag', 'startupCommand', 'useExistingDomain'];
        foreach (
            [
            'configure' => $configure, 'configureStructured' => $configure,
            'delete' => ['name', 'verify'], 'deploy' => ['name'],
            'fetchLogs' => ['logType', 'name', 'deployId', 'lines'], 'getAvailable' => [], 'hasFeature' => [],
            'listApplications' => [], 'redeploy' => ['name', 'sshKeyPass'], 'restart' => ['name'],
            'setMode' => ['mode', 'name'], 'stage' => ['name', 'source', 'sourceType', 'appdir', 'branch', 'mode',
                'parent', 'runtime', 'sshKeyName', 'sshKeyPass'], 'start' => ['name'], 'stop' => ['name'],
            ] as $method => $names
        ) {
            $reflection = new ReflectionMethod(WebApp::class, $method);
            self::assertSame('array', (string) $reflection->getReturnType());
            self::assertSame($names, array_map(static fn ($p): string => $p->getName(), $reflection->getParameters()));
            foreach ($reflection->getParameters() as $parameter) {
                $name = $parameter->getName();
                $private = ['buildCommand', 'startupCommand', 'env', 'envText', 'source', 'sshKeyPass'];
                $sensitive = in_array($name, $private, true);
                self::assertCount($sensitive ? 1 : 0, $parameter->getAttributes(SensitiveParameter::class));
                $type = match ($name) {
                    'verify' => 'bool', 'useExistingDomain' => '?bool', 'lines' => '?int',
                    'env' => $method === 'configureStructured' ? '?array' : '?string',
                    default => $parameter->isOptional() ? '?string' : 'string',
                };
                self::assertSame($type, (string) $parameter->getType());
                if ($parameter->isOptional()) {
                    self::assertNull($parameter->getDefaultValue());
                }
            }
        }
        [$client, $http] = self::client('direct');
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope('direct', '{}')));
        $account->webApp()->hasFeature();
        $before = $account->responseMeta();
        foreach ([['app'], ['app', null], ['app', 1], ['app', '1']] as $bad) {
            try {
                self::invoke($account->webApp(), 'delete', $bad);
                self::fail('Required native bool must fail before entry.');
            } catch (TypeError) {
                self::assertSame($before, $account->responseMeta());
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @param array<int|string, mixed> $arguments */
    private static function invoke(WebApp $group, string $method, #[SensitiveParameter] array $arguments): mixed
    {
        return $group->$method(...$arguments);
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $wire,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=WebApp&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/WebApp/' . $function;
        if ($method === 'GET' && $wire !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $wire;
        }
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method, $request->getMethod());
        self::assertSame($method === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertStringNotContainsString('sshkeypass', (string) $request->getUri());
    }

    private static function envelope(string $route, #[SensitiveParameter] ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"warnings":["Fixture warning"],"messages":null,"metadata":{"error_category":"immediate_only"}}';
        return $route === 'bridge'
            ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
                'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
