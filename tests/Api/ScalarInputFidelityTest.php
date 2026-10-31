<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TypeError;

final class ScalarInputFidelityTest extends TestCase
{
    /** @param array<string, mixed> $arguments */
    #[DataProvider('strictStringInputs')]
    public function testStrictCallerIntegersFailBeforeDispatch(
        string $group,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $handle = $client->whm();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"fixture"}}'));
        $handle->call('version');
        $metadata = $handle->responseMeta();
        self::assertNotNull($metadata);
        $api = $group === 'wordPressBackup' || $group === 'versionControl' ? $client->uapi('fixture') : $handle;
        $target = $api->{$group}();
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"errors":null,"data":{}}}}',
        ));
        try {
            $target->{$method}(...$arguments);
            self::fail('An integer has no source string contract.');
        } catch (TypeError) {
            self::assertCount(1, $http->getRequests());
            // Native PHP argument checks precede the generated method and its lifecycle reset.
            self::assertSame($metadata, $handle->responseMeta());
        }
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function strictStringInputs(): iterable
    {
        yield 'provider display' => ['authentication', 'setProviderDisplayConfigurations',
            ['configurations' => 1, 'providerId' => 'fixture', 'serviceName' => 'cpaneld']];
        yield 'add overrides' => ['hostingPlans', 'addOverrideFeaturesForUser', ['user' => 'fixture', 'features' => 1]];
        yield 'remove overrides' => ['hostingPlans', 'removeOverrideFeaturesForUser',
            ['user' => 'fixture', 'features' => 1]];
        yield 'autossl metadata' => ['ssl', 'setAutoSslMetadata', ['metadataJson' => 1]];
        yield 'profile roles' => ['server', 'startProfileActivation', ['code' => 'STANDARD', 'optional' => 1]];
        yield 'pool parms' => ['webServer', 'phpSetVhostVersions',
            ['version' => 'inherit', 'vhosts' => 'fixture.test', 'phpFpmPoolParms' => 1]];
        yield 'repository create' => ['versionControl', 'create',
            ['repositoryRoot' => '/repo', 'name' => 'fixture', 'type' => 'git', 'sourceRepository' => 1]];
        yield 'repository update' => ['versionControl', 'update',
            ['repositoryRoot' => '/repo', 'sourceRepository' => 1]];
        yield 'wordpress cancel' => ['wordPressBackup', 'cancel', ['site' => 1]];
        yield 'wordpress start' => ['wordPressBackup', 'start', ['site' => 1]];
    }

    #[DataProvider('serializedStrings')]
    public function testProviderTextIsFormEncodedOnceWithoutJSONValidation(string $text, string $encoded): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"reason":"fixture"}}'));
        $client->whm()->authentication()->setProviderDisplayConfigurations($text, '{"ordinary":1}', 'cpaneld');
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/set_provider_display_configurations?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            'configurations=' . $encoded . '&provider_id=%7B%22ordinary%22%3A1%7D&service_name=cpaneld',
            (string) $request->getBody(),
        );
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function serializedStrings(): iterable
    {
        yield 'duplicate keys and huge integer' => [' {"x":1,"x":123456789012345678901234567890,"v":" +&=%20"} ',
            '%20%7B%22x%22%3A1%2C%22x%22%3A123456789012345678901234567890%2C%22v%22%3A%22%20%2B%26%3D%2520%22%7D%20'];
        yield 'unparsed malformed text' => [' {"x": ', '%20%7B%22x%22%3A%20'];
        yield 'empty text' => ['', ''];
    }

    public function testOptionalSerializedTextKeepsNullOmissionAndSuppliedBytes(): void
    {
        [$client, $http] = self::client();
        $repository = $client->uapi('fixture')->versionControl();
        foreach ([null, ' {"remote_name":" +&=%20","n":999999999999999999999999} '] as $source) {
            $http->addResponse(new Response(
                200,
                [],
                '{"metadata":{"result":1},"data":{"uapi":{"status":1,"errors":null,"data":{}}}}',
            ));
            self::assertSame([], $repository->update('/repo', sourceRepository: $source));
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('POST', $request->getMethod());
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
            self::assertSame(
                'repository_root=%2Frepo' . ($source === null ? '' :
                '&source_repository=%20%7B%22remote_name%22%3A%22%20%2B%26%3D%2520%22%2C%22n%22%3A'
                    . '999999999999999999999999%7D%20'),
                (string) $request->getBody(),
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    public function testGenericCallRetainsIntegerRepresentation(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1}}'));
        $client->whm()->call('set_provider_display_configurations', ['configurations' => 1], RequestMethod::Post);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('configurations=1', (string) $request->getBody());
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
