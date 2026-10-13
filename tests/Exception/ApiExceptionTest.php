<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Exception;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase
{
    public function testExposesUpstreamErrorsFromItsMetadata(): void
    {
        $exception = new ApiErrorException('failed', new ResponseMetadata(200, ['result' => 0, 'reason' => 'Denied']));

        self::assertSame(['Denied'], $exception->errors());
    }

    public function testReportsNoErrorsWithoutMetadata(): void
    {
        self::assertSame([], (new ApiException('failed'))->errors());
    }

    public function testBridgedFailureSurfacesTheInnerUapiErrors(): void
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => 'whm', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"reason":"OK"},'
            . '"data":{"uapi":{"status":0,"errors":["The domain does not exist."],"data":null}}}'));

        try {
            $client->uapi('npr')->dns()->lookup('missing.example.test');
            self::fail('Expected an API error.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['The domain does not exist.'], $exception->errors());
            self::assertStringNotContainsString('The domain does not exist.', $exception->getMessage());
        }
    }
}
