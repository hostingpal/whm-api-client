<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use ArgumentCountError;
use HostingPal\WhmApiClient\Api\Uapi\Bandwidth;
use HostingPal\WhmApiClient\Api\Uapi\Branding;
use HostingPal\WhmApiClient\Api\Uapi\Fileman;
use HostingPal\WhmApiClient\Api\Uapi\StatsBar;
use HostingPal\WhmApiClient\Api\Uapi\VersionControl;
use HostingPal\WhmApiClient\Api\Whm\HostingPlans;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Public-method request fixtures only: no filesystem, server or external API access. */
final class InventoryDelimitedInputsTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string, string, string}> */
    public static function selectors(): iterable
    {
        $positions = [
            ['bandwidth', 'query', 'domains', 'domains', '|', 'Bandwidth/query'],
            ['bandwidth', 'query', 'protocols', 'protocols', '|', 'Bandwidth/query'],
            ['branding', 'getApplications', 'appKeys', 'app_keys', ',', 'Branding/get_applications'],
            ['branding', 'getAvailableApplications', 'nvarglist', 'nvarglist', '|',
                'Branding/get_available_applications'],
            ['branding', 'getInformationForApplications', 'appKeys', 'app_keys', ',',
                'Branding/get_information_for_applications'],
            ['fileman', 'listFiles', 'onlyTheseFiles', 'only_these_files', ',', 'Fileman/list_files'],
            ['statsBar', 'getStats', 'display', 'display', '|', 'StatsBar/get_stats'],
            ['versionControl', 'retrieve', 'fields', 'fields', ',', 'VersionControl/retrieve'],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($positions as $position) {
                yield "$route {$position[5]} {$position[2]}" => [$route, ...$position];
            }
        }
        yield 'whm applicationsList' => ['whm', 'hostingPlans', 'getAvailableApplications',
            'applicationsList', 'applications_list', ',', 'get_available_applications'];
    }

    #[DataProvider('selectors')]
    public function testListsAndScalarsUseOneOriginalFieldWithoutChangingTransportOrResult(
        string $route,
        string $group,
        string $method,
        string $argument,
        string $wire,
        string $delimiter,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $operation);
        $cases = $delimiter === '|'
            ? [
                [['z', 'a'], 'z%7Ca'], [['', 'z', 'z', ''], '%7Cz%7Cz%7C'],
                [[" a+&=%7C%2C/雪\0\xFF", ','], '%20a%2B%26%3D%257C%252C%2F%E9%9B%AA%00%FF%7C%2C'],
                ['z|a', 'z%7Ca'], ['|%7C%2C,', '%7C%257C%252C%2C']]
            : [
                [['z', 'a'], 'z%2Ca'], [['', 'z', 'z', ''], '%2Cz%2Cz%2C'],
                [[" a+&=%7C%2C/雪\0\xFF", '|'], '%20a%2B%26%3D%257C%252C%2F%E9%9B%AA%00%FF%2C%7C'],
                ['z,a', 'z%2Ca'], [',%7C%2C|', '%2C%257C%252C%7C']];
        $cases = [...$cases, [[''], ''], ['', ''], [['*'], '%2A'], ['*', '%2A']];
        foreach ($cases as [$value, $encoded]) {
            self::assertSame(
                self::fixtureResult($operation),
                self::invoke($handle, $group, $method, [$argument => $value]),
            );
            $fields = self::prefix($operation) . $wire . '=' . $encoded;
            self::assertRequest($http, $route, $operation, $fields);
            self::assertSame(1, substr_count($fields, $wire . '='));
            self::assertNotNull($handle->responseMeta());
        }
        self::assertCount(count($cases), $http->getRequests());
    }

    #[DataProvider('selectors')]
    public function testOptionalSelectorsOmitAndCollectionSignaturesProtectTraces(
        string $route,
        string $group,
        string $method,
        string $argument,
        string $wire,
        string $delimiter,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $operation);
        $reflection = new ReflectionMethod(self::group($handle, $group), $method);
        $parameter = new ReflectionParameter([self::group($handle, $group), $method], $argument);
        self::assertSame(
            $argument === 'display' ? 'array|string' : 'array|string|null',
            (string) $parameter->getType(),
        );
        self::assertSame($argument !== 'display', $parameter->isOptional());
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        self::assertStringContainsString('non-empty-list<string>', (string) $reflection->getDocComment());
        if ($argument === 'display') {
            return;
        }
        self::assertNull($parameter->getDefaultValue());
        foreach ([[], [$argument => null]] as $arguments) {
            self::assertSame(self::fixtureResult($operation), self::invoke($handle, $group, $method, $arguments));
            self::assertRequest($http, $route, $operation, rtrim(self::prefix($operation), '&'));
        }
        self::assertCount(2, $http->getRequests());
    }

    #[DataProvider('selectors')]
    public function testInvalidListsClearMetadataBeforeIoAndHideTheirValues(
        string $route,
        string $group,
        string $method,
        string $argument,
        string $wire,
        string $delimiter,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $operation);
        $invalid = [
        [], [1 => 'synthetic-private-sparse'], ['key' => 'synthetic-private-keyed'],
            ['synthetic-private-first', null], ['synthetic-private-first', 1],
            ['synthetic-private-first', false], ['synthetic-private-first', 1.5],
            ['synthetic-private-first', ['nested']], ['synthetic-private-first', new \stdClass()],
            ['synthetic-private-first', 'synthetic-private-late' . $delimiter]];
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ($invalid as $value) {
                self::invoke($handle, $group, $method, [$argument => 'seed']);
                self::assertNotNull($handle->responseMeta());
                $count = count($http->getRequests());
                try {
                    self::invoke($handle, $group, $method, [$argument => $value]);
                    self::fail('Malformed list accepted for ' . $wire);
                } catch (InvalidArgumentException $error) {
                    self::assertNull($handle->responseMeta());
                    self::assertCount($count, $http->getRequests());
                    self::assertStringNotContainsString('synthetic-private-', $error->getMessage());
                    $seen = false;
                    foreach ($error->getTrace() as $frame) {
                        $class = $frame['class'] ?? '';
                        if ($class === self::group($handle, $group)::class) {
                            self::assertSame($method, $frame['function']);
                            $index = (new ReflectionParameter(
                                [self::group($handle, $group), $method],
                                $argument,
                            ))->getPosition();
                            self::assertInstanceOf(
                                SensitiveParameterValue::class,
                                ($frame['args'] ?? [])[$index] ?? null,
                            );
                            $seen = true;
                        }
                        if (
                            str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                            && !str_contains($class, '\\Tests\\')
                        ) {
                            self::assertFalse(self::containsPrivateValue($frame['args'] ?? []));
                        }
                    }
                    self::assertTrue($seen);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testRequiredDisplayNativeErrorsRetainPriorMetadataAndMakeNoRequest(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$handle, $http] = self::client($route, 'StatsBar/get_stats');
            self::assertInstanceOf(UapiClient::class, $handle);
            $handle->statsBar()->getStats('diskusage');
            $metadata = $handle->responseMeta();
            self::assertNotNull($metadata);
            foreach ([[], ['display' => null]] as $arguments) {
                try {
                    self::invoke($handle, 'statsBar', 'getStats', $arguments);
                    self::fail('Required display must fail before method entry.');
                } catch (ArgumentCountError | TypeError) {
                    self::assertSame($metadata, $handle->responseMeta());
                }
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testCompanionOptionsAndTwoBandwidthSelectorsRetainOrderAndZeroValues(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$handle, $http] = self::client($route, 'Bandwidth/query');
            self::assertInstanceOf(UapiClient::class, $handle);
            $handle->bandwidth()->query('domain|protocol', ['UNKNOWN', 'x'], 0, null, ['http', 'ftp'], 0);
            self::assertRequest(
                $http,
                $route,
                'Bandwidth/query',
                'grouping=domain%7Cprotocol&domains=UNKNOWN%7Cx&end=0&protocols=http%7Cftp&start=0',
            );
            [$handle, $http] = self::client($route, 'Fileman/list_files');
            self::assertInstanceOf(UapiClient::class, $handle);
            $handle->fileman()->listFiles(
                '/synthetic',
                includeMime: false,
                onlyTheseFiles: ['a', 'b'],
                showHidden: false,
                types: 0,
            );
            self::assertRequest(
                $http,
                $route,
                'Fileman/list_files',
                'dir=%2Fsynthetic&include_mime=0&only_these_files=a%2Cb&show_hidden=0&types=0',
            );
            [$handle, $http] = self::client($route, 'StatsBar/get_stats');
            self::assertInstanceOf(UapiClient::class, $handle);
            $handle->statsBar()->getStats(['diskusage', 'future'], warningLevel: 0, warnings: false, warnOut: false);
            self::assertRequest(
                $http,
                $route,
                'StatsBar/get_stats',
                'display=diskusage%7Cfuture&warninglevel=0&warnings=0&warnout=0',
            );
        }
        [$handle, $http] = self::client('whm', 'get_available_applications');
        self::assertInstanceOf(WhmClient::class, $handle);
        $handle->hostingPlans()->getAvailableApplications(['a', 'b'], 'synthetic.conf');
        self::assertRequest(
            $http,
            'whm',
            'get_available_applications',
            'applications_list=a%2Cb&file=synthetic.conf',
        );
    }

    /** @param array<string, mixed> $arguments
     * @return array<array-key, mixed>
     */
    private static function invoke(WhmClient|UapiClient $handle, string $group, string $method, array $arguments): array
    {
        $base = match ($group) {
            'bandwidth' => ['grouping' => 'domain'],
            'branding' => $method === 'getInformationForApplications' ? ['docroot' => '/synthetic'] : [],
            'fileman' => ['dir' => '/synthetic'],
            default => [],
        };
        $result = self::group($handle, $group)->$method(...($arguments + $base));
        self::assertIsArray($result);
        return $result;
    }

    private static function group(
        WhmClient|UapiClient $handle,
        string $group,
    ): Bandwidth|Branding|Fileman|StatsBar|VersionControl|HostingPlans {
        if ($handle instanceof WhmClient) {
            return $handle->hostingPlans();
        }
        return match ($group) {
            'bandwidth' => $handle->bandwidth(),
            'branding' => $handle->branding(),
            'fileman' => $handle->fileman(),
            'statsBar' => $handle->statsBar(),
            'versionControl' => $handle->versionControl(),
            default => throw new LogicException('Unknown fixture group.'),
        };
    }

    private static function prefix(string $operation): string
    {
        return match ($operation) {
            'Bandwidth/query' => 'grouping=domain&',
            'Branding/get_information_for_applications' => 'docroot=%2Fsynthetic&',
            'Fileman/list_files' => 'dir=%2Fsynthetic&',
            default => '',
        };
    }

    /** @return array<array-key, mixed> */
    private static function fixtureResult(string $operation): array
    {
        return match ($operation) {
            'Bandwidth/query' => ['UNKNOWN' => 17],
            'Branding/get_applications' => ['fixture' => ['feature' => 'f']],
            'Branding/get_available_applications' => ['grouporder' => ['z', 'a']],
            'Branding/get_information_for_applications' => ['fixture' => ['terms' => ['z', 'a']]],
            'Fileman/list_files' => [['file' => 'a']],
            'StatsBar/get_stats' => [['id' => 'diskusage']],
            'VersionControl/retrieve' => [['name' => 'Repo']],
            default => ['groups' => []],
        };
    }

    /** @return array{WhmClient|UapiClient, MockClient} */
    private static function client(string $route, string $operation): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $payload = json_encode(self::fixtureResult($operation), JSON_THROW_ON_ERROR);
        $http->setDefaultResponse(new Response(200, [], match ($route) {
            'whm' => '{"metadata":{"result":1},"data":' . $payload . '}',
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":' . $payload . '}}}',
            default => '{"result":{"status":1,"data":' . $payload . '}}',
        }));
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'direct' ? 'cpanel' : 'whm',
            'username' => $route === 'direct' ? 'fixture' : 'root', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
        return [$route === 'whm' ? $client->whm() : $client->uapi('fixture'), $http];
    }

    private static function assertRequest(MockClient $http, string $route, string $operation, string $fields): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $post = in_array($operation, ['Branding/get_information_for_applications', 'VersionControl/retrieve'], true);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        $url = match ($route) {
            'whm' => 'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            'bridge' => 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=' . explode('/', $operation)[0] . '&cpanel.function=' . explode('/', $operation)[1],
            default => 'https://api.example.test:2083/execute/' . $operation,
        };
        self::assertSame(
            $url . (!$post && $fields !== '' ? ($route === 'direct' ? '?' : '&') . $fields : ''),
            (string) $request->getUri(),
        );
        self::assertSame($post ? $fields : '', (string) $request->getBody());
        self::assertSame($post ? 'application/x-www-form-urlencoded' : '', $request->getHeaderLine('Content-Type'));
    }

    private static function containsPrivateValue(mixed $value): bool
    {
        if ($value instanceof SensitiveParameterValue) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (self::containsPrivateValue($key) || self::containsPrivateValue($item)) {
                    return true;
                }
            }
        }
        return is_string($value) && str_contains($value, 'synthetic-private-');
    }
}
