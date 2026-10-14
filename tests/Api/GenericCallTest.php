<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\CallResult;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class GenericCallTest extends TestCase
{
    private const WHM = '{"metadata":{"command":"listaccts","reason":"OK","result":1,"version":1},'
        . '"data":{"acct":[{"user":"npr","7":"numeric key","nested":{"list":[1,"2",null]}}]}}';

    private const DIRECT = '{"apiversion":3,"module":"Email","func":"list_pops","result":'
        . '{"data":[{"email":"a@npr.rs"}],"status":1,"errors":null,"messages":null,"warnings":null,'
        . '"metadata":{"transformed":1}}}';

    private const BRIDGED = '{"metadata":{"command":"uapi_cpanel","reason":"OK","result":1,"version":1},'
        . '"data":{"uapi":{"data":{"domain":"npr.rs"},"status":1,"errors":null,"messages":null,"warnings":null,'
        . '"metadata":{}}}}';

    /** @return iterable<string, array{RequestMethod, string, string}> */
    public static function methods(): iterable
    {
        yield 'get' => [RequestMethod::Get, '?api.version=1&searchtype=user&search=npr', ''];
        yield 'post' => [RequestMethod::Post, '?api.version=1', 'searchtype=user&search=npr'];
    }

    #[DataProvider('methods')]
    public function testWhmCallRoutesParametersByMethodAndConvertsThePayload(
        RequestMethod $method,
        string $query,
        string $body,
    ): void {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::WHM));

        $result = $whm->call('listaccts', ['searchtype' => 'user', 'search' => 'npr', 'api.version' => 9], $method);

        self::assertInstanceOf(CallResult::class, $result);
        self::assertTrue($result->hasData);
        self::assertSame(
            ['acct' => [['user' => 'npr', 7 => 'numeric key', 'nested' => ['list' => [1, '2', null]]]]],
            $result->data,
        );
        self::assertSame($result->metadata, $whm->responseMeta());
        self::assertSame(1, $result->metadata->whmResult());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method->value, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/listaccts' . $query, (string) $request->getUri());
        self::assertSame($body, (string) $request->getBody());
        self::assertSame(
            $method === RequestMethod::Post ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    public function testWhmCallAcceptsNamespacedFunctionsAndRejectsOtherSeparators(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1}}'));

        $result = $client->whm()->call('360Monitoring/webpros_auth_callback', [], RequestMethod::Post);

        self::assertFalse($result->hasData);
        self::assertNull($result->data);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2087/json-api/360Monitoring/webpros_auth_callback?api.version=1',
            (string) $request->getUri(),
        );

        foreach (['../version', 'list accts', '', 'a/', '/version', 'x?y=1'] as $invalid) {
            try {
                $client->whm()->call($invalid);
                self::fail('Invalid function names must be rejected before I/O: ' . $invalid);
            } catch (InvalidArgumentException) {
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    #[DataProvider('methods')]
    public function testDirectUapiCallUsesTheExecuteRoute(RequestMethod $method, string $query, string $body): void
    {
        [$client, $http] = $this->client(true);
        $uapi = $client->uapi();
        $http->addResponse(new Response(200, [], self::DIRECT));

        $result = $uapi->call('Email', 'list_pops', ['searchtype' => 'user', 'search' => 'npr'], $method);

        self::assertSame([['email' => 'a@npr.rs']], $result->data);
        self::assertSame(['transformed' => 1], $result->metadata->uapi['metadata'] ?? null);
        self::assertNull($result->metadata->whm);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method->value, $request->getMethod());
        $expectedQuery = $method === RequestMethod::Get ? '?searchtype=user&search=npr' : '';
        self::assertSame(
            'https://api.example.test:2083/execute/Email/list_pops' . $expectedQuery,
            (string) $request->getUri(),
        );
        self::assertSame($body, (string) $request->getBody());
        self::assertSame('cpanel npr:fixture-token', $request->getHeaderLine('Authorization'));
    }

    #[DataProvider('methods')]
    public function testBridgedUapiCallKeepsRoutingFieldsInTheQueryAndStripsThemFromTheBody(
        RequestMethod $method,
        string $query,
        string $body,
    ): void {
        [$client, $http] = $this->client();
        $npr = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::BRIDGED));

        $result = $npr->call('DomainInfo', 'primary_domain', [
            'cpanel.user' => 'root',
            'cpanel.function' => 'list_domains',
            'searchtype' => 'user',
            'search' => 'npr',
        ], $method);

        self::assertSame(['domain' => 'npr.rs'], $result->data);
        self::assertSame(1, $result->metadata->whmResult());
        self::assertSame(1, $result->metadata->uapiStatus());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $routing = '?api.version=1&cpanel.user=npr&cpanel.module=DomainInfo&cpanel.function=primary_domain';
        $expectedQuery = $method === RequestMethod::Get ? $routing . '&searchtype=user&search=npr' : $routing;
        self::assertSame(
            'https://api.example.test:2087/json-api/uapi_cpanel' . $expectedQuery,
            (string) $request->getUri(),
        );
        self::assertSame($body, (string) $request->getBody());
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    public function testUapiCallRejectsInvalidModuleOrFunctionNamesBeforeIo(): void
    {
        [$client, $http] = $this->client(true);

        $invalid = [['Email', 'list pops'], ['../Email', 'list_pops'], ['', 'x'], ['Email', '']];
        foreach ($invalid as [$module, $function]) {
            try {
                $client->uapi()->call($module, $function);
                self::fail('Invalid names must be rejected before I/O.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $http->getRequests());
            }
        }
    }

    public function testFlagPolicyRaisesOnFailureWhileUncheckedRecordsTheEnvelope(): void
    {
        [$client, $http] = $this->client(true);
        $uapi = $client->uapi();
        $failed = '{"result":{"data":{"has_feature":0},"status":0,"errors":["Feature is disabled."],'
            . '"messages":null,"warnings":null,"metadata":{}}}';
        $http->addResponse(new Response(200, [], $failed));
        $http->addResponse(new Response(200, [], $failed));

        try {
            $uapi->call('Features', 'has_feature', ['name' => 'ssh']);
            self::fail('The Flag policy must raise on a failed status.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Feature is disabled.'], $exception->errors());
        }

        $result = $uapi->call('Features', 'has_feature', ['name' => 'ssh'], outcome: OutcomePolicy::Unchecked);

        self::assertSame(['has_feature' => 0], $result->data);
        self::assertSame(0, $result->metadata->uapiStatus());
        self::assertSame(['Feature is disabled.'], $result->metadata->uapiErrors());
        self::assertSame($result->metadata, $uapi->responseMeta());
        self::assertCount(2, $http->getRequests());
    }

    public function testUncheckedPolicyStillRequiresAUsableEnvelope(): void
    {
        [$client, $http] = $this->client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], '{"data":{"version":"1"}}'));
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"no"},"data":null}'));

        try {
            $whm->call('version', outcome: OutcomePolicy::Unchecked);
            self::fail('A response without metadata is invalid under every policy.');
        } catch (InvalidResponseException $exception) {
            self::assertNull($exception->metadata);
            self::assertNull($whm->responseMeta());
        }

        $result = $whm->call('version', outcome: OutcomePolicy::Unchecked);

        self::assertTrue($result->hasData);
        self::assertNull($result->data);
        self::assertSame(0, $result->metadata->whmResult());
        self::assertSame(['no'], $result->metadata->errors());
    }

    public function testBridgedUncheckedCallStillRequiresTheInnerEnvelope(): void
    {
        [$client, $http] = $this->client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"reason":"OK"},"data":{}}'));

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('Missing UAPI response envelope.');

        $client->uapi('npr')->call('DomainInfo', 'primary_domain', outcome: OutcomePolicy::Unchecked);
    }

    /** @return array{Client, MockClient} */
    private function client(bool $cpanel = false): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $options = $cpanel
            ? ['host' => 'api.example.test', 'authType' => 'cpanel', 'username' => 'npr', 'token' => 'fixture-token']
            : ['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token'];

        return [new Client($options, new HttpTransport($http, $factory, $factory)), $http];
    }
}
