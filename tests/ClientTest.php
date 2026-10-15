<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests;

use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\CommonPsr17ClassesStrategy;
use Http\Discovery\Strategy\MockClientStrategy;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use SensitiveParameter;

final class ClientTest extends TestCase
{
    public function testSelectsStableWhmAndAccountHandlesWithoutMakingRequests(): void
    {
        $http = new MockClient(new Psr17Factory());
        $client = $this->client($http);

        $whm = $client->whm();
        $npr = $client->uapi('npr');
        $other = $client->uapi('other');

        self::assertInstanceOf(WhmClient::class, $whm);
        self::assertInstanceOf(UapiClient::class, $npr);
        self::assertSame($whm, $client->whm());
        self::assertSame($npr, $client->uapi('npr'));
        self::assertNotSame($npr, $other);
        self::assertSame('npr', $npr->username());
        self::assertSame('other', $other->username());
        self::assertTrue($npr->isBridged());
        self::assertNull($whm->responseMeta());
        self::assertNull($npr->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    public function testUsesConfiguredDefaultWhmTargetWithoutChangingAuthentication(): void
    {
        $http = new MockClient(new Psr17Factory());
        $client = $this->client($http, ['username' => 'reseller', 'defaultCpanelUser' => 'npr']);

        self::assertSame($client->uapi('npr'), $client->uapi());
        self::assertSame($client->uapi(), $client->uapi(null));
        self::assertSame('npr', $client->uapi()->username());
        self::assertTrue($client->uapi()->isBridged());

        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},'
            . '"data":{"uapi":{"status":1,"data":["www"]}}}'));
        $client->uapi()->domainInfo()->mainDomainBuiltinSubdomainAliases();
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('whm reseller:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertStringStartsWith(
            'https://whm.example.test:2087/json-api/uapi_cpanel?',
            (string) $request->getUri(),
        );
        self::assertStringContainsString('cpanel.user=npr', $request->getUri()->getQuery());
    }

    public function testHandlesDoNotExposeConfigurationOrCredentials(): void
    {
        foreach ([WhmClient::class, UapiClient::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
                self::assertNotContains(
                    $name,
                    [ClientConfig::class, TokenCredentials::class],
                    $class . '::' . $method->getName() . ' exposes credentials.',
                );
            }
        }
    }

    public function testWhmRootCredentialsDoNotSupplyAnImplicitCpanelTarget(): void
    {
        $client = $this->client();

        $this->expectException(InvalidArgumentException::class);
        $client->uapi();
    }

    public function testDirectUapiUsesItsTokenAccountAndCpanelPort(): void
    {
        $client = $this->client(options: ['authType' => 'cpanel', 'username' => 'npr']);

        self::assertSame($client->uapi('npr'), $client->uapi());
        self::assertSame('npr', $client->uapi()->username());
        self::assertFalse($client->uapi()->isBridged());
    }

    public function testCpanelCredentialsCannotSelectAnotherAccount(): void
    {
        $http = new MockClient(new Psr17Factory());
        $client = $this->client($http, ['authType' => 'cpanel', 'username' => 'npr']);
        $npr = $client->uapi();

        try {
            $client->uapi('other');
            self::fail('Expected the account mismatch to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame($npr, $client->uapi());
            self::assertSame('npr', $npr->username());
            self::assertSame([], $http->getRequests());
        }
    }

    public function testCpanelCredentialsCannotSelectWhm(): void
    {
        $client = $this->client(options: ['authType' => 'cpanel', 'username' => 'npr']);

        $this->expectException(LogicException::class);
        $client->whm();
    }

    #[DataProvider('invalidTargets')]
    public function testRejectsInvalidExplicitTargetsEvenWithADefault(string $target): void
    {
        $client = $this->client(options: ['defaultCpanelUser' => 'npr']);

        $this->expectException(InvalidArgumentException::class);
        $client->uapi($target);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTargets(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [' '];
        yield 'uppercase' => ['Npr'];
        yield 'path' => ['../npr'];
        yield 'header injection' => ["npr\r\nInjected: value"];
    }

    public function testDifferentFacadesDoNotShareAccountHandles(): void
    {
        $first = $this->client();
        $second = $this->client();

        self::assertNotSame($first->whm(), $second->whm());
        self::assertNotSame($first->uapi('npr'), $second->uapi('npr'));
    }

    public function testGeneratedHandleFramesRedactSensitiveArguments(): void
    {
        $frames = [
            [WhmClient::class, 'run', 'arguments'],
            [WhmClient::class, 'dispatch', 'parameters'],
            [WhmClient::class, 'request', 'parameters'],
            [WhmClient::class, 'call', 'parameters'],
            [UapiClient::class, 'run', 'arguments'],
            [UapiClient::class, 'dispatch', 'parameters'],
            [UapiClient::class, 'request', 'parameters'],
            [UapiClient::class, 'call', 'parameters'],
        ];

        foreach ($frames as [$class, $method, $name]) {
            $matches = array_filter(
                (new ReflectionMethod($class, $method))->getParameters(),
                static fn (ReflectionParameter $parameter): bool => $parameter->getName() === $name,
            );

            self::assertCount(1, $matches, $class . '::' . $method);
            $parameter = reset($matches);
            self::assertInstanceOf(ReflectionParameter::class, $parameter);
            self::assertNotSame(
                [],
                $parameter->getAttributes(SensitiveParameter::class),
                $class . '::' . $method . '($' . $name . ')',
            );
        }
    }

    public function testRequiresAnExplicitTransport(): void
    {
        $parameter = new ReflectionParameter([Client::class, '__construct'], 'transport');

        self::assertFalse($parameter->allowsNull());
        self::assertFalse($parameter->isOptional());
    }

    public function testAcceptsAnExplicitlyDiscoveredTransportWithoutExecutingAnOperation(): void
    {
        $strategies = array_values([...Psr18ClientDiscovery::getStrategies()]);
        Psr18ClientDiscovery::setStrategies([MockClientStrategy::class, CommonPsr17ClassesStrategy::class]);

        try {
            $client = new Client(
                ['host' => 'whm.example.test', 'token' => 'fixture-token', 'authType' => 'whm'],
                HttpTransport::discover(),
            );
            self::assertNull($client->whm()->responseMeta());
            self::assertSame('npr', $client->uapi('npr')->username());
        } finally {
            Psr18ClientDiscovery::setStrategies($strategies);
        }
    }

    /**
     * @param array{
     *     host?: string,
     *     token?: string,
     *     authType?: 'whm'|'cpanel',
     *     username?: string,
     *     port?: int,
     *     defaultCpanelUser?: string
     * } $options
     */
    private function client(?MockClient $http = null, array $options = []): Client
    {
        $factory = new Psr17Factory();

        return new Client(
            array_replace(['host' => 'whm.example.test', 'token' => 'fixture-token', 'authType' => 'whm'], $options),
            new HttpTransport($http ?? new MockClient($factory), $factory, $factory),
        );
    }
}
