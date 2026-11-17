<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Fixtures\WeakInterfaceDataCaller;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TypeError;

/** Independent fixtures for review 5721307565; these tests make no live requests. */
final class WhmInterfaceDataCorrectionTest extends TestCase
{
    public function testRetrievalPreservesOpaqueNestedData(): void
    {
        [$client, $http] = self::client();
        $payload = '{"help":{"unknown":[0,false,null,{"future":"kept"}]},'
            . '"documentation":"","brand":{"logo":{"custom":true},"colors":["#123"]},"future":42}';
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->customizations()->retrieveCustomizations('cpanel', 'jupiter'),
        );
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<array{string}> */
    public static function opaquePayloads(): iterable
    {
        foreach (['null', 'false', '0', '"text"', '[]', '[{"extra":true}]'] as $payload) {
            yield [$payload];
        }
    }

    #[DataProvider('opaquePayloads')]
    public function testOpaqueRetrievalBoundaryPreservesPresentData(string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->customizations()->retrieveCustomizations('cpanel', 'jupiter'),
        );
    }

    public function testRetrievalStillRequiresDataPresenceAfterConfirmedOutcome(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(null)));
        try {
            $client->whm()->customizations()->retrieveCustomizations('cpanel', 'jupiter');
            self::fail('Missing data must fail even at the opaque boundary.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<array{string}> */
    public static function nullableSurveyFields(): iterable
    {
        foreach (['link', 'max_dismiss', 'submit_event'] as $field) {
            yield [$field];
        }
    }

    #[DataProvider('nullableSurveyFields')]
    public function testOnlyReviewedSurveyStringsPreserveNullEmptyAndOmission(string $field): void
    {
        foreach ([null, '', '0', '  exact  '] as $value) {
            [$client, $http] = self::client();
            $expected = ['display' => 0, $field => $value];
            $http->addResponse(new Response(200, [], self::envelope(json_encode($expected, JSON_THROW_ON_ERROR))));
            self::assertSame($expected, $client->whm()->inProductSurvey()->getInProductSurveyUrl());
            self::assertCount(1, $http->getRequests());
        }
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"display":0}')));
        self::assertSame(['display' => 0], $client->whm()->inProductSurvey()->getInProductSurveyUrl());
    }

    public function testSetPreservesArbitraryJsonValuesAndEncodesTextOnce(): void
    {
        [$client, $http] = self::client();
        $json = '{ "n":50,"zero":0,"flag":false,"nothing":null,"list":[1,{"deep":true}],'
            . '"object":{"unreviewed":{"value":"a&b=1+%"}},"text":"","empty":[] }';
        $http->addResponse(new Response(200, [], self::envelope($json)));
        self::assertSame(
            json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->userData()->setScopedUserdata('scope', json: $json),
        );
        $request = self::request($http);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('scope=scope&json=' . rawurlencode($json), (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertCount(1, $http->getRequests());
    }

    public function testPositionalSetTargetsKeyAndValueBeforeJson(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{"theme":"dark"}')));
        self::assertSame(['theme' => 'dark'], $client->whm()->userData()->setScopedUserdata('scope', 'theme', 'dark'));
        self::assertSame('scope=scope&key=theme&value=dark', (string) self::request($http)->getBody());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<array{string}> */
    public static function unsafePaths(): iterable
    {
        foreach (['', ' ', "\t\n", '0', ' 0 '] as $path) {
            yield [$path];
        }
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafeDeletePathClearsMetadataBeforeGuardWithoutHttp(string $path): void
    {
        [$client, $http] = self::client();
        self::primeMetadata($client, $http);
        $http->addResponse(new Response(200, [], self::envelope(null)));
        try {
            $client->whm()->customizations()->deleteCustomizations('cpanel', 'jupiter', $path);
            self::fail('Unsafe path must fail before HTTP.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->whm()->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testWeakCallerCoercedIntegerZeroHitsTheDeleteGuard(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(null)));
        try {
            WeakInterfaceDataCaller::deleteWithZero($client->whm()->customizations());
            self::fail('Weak PHP scalar coercion must not bypass the zero guard.');
        } catch (InvalidArgumentException) {
            self::assertNull($client->whm()->responseMeta());
            self::assertCount(0, $http->getRequests());
        }
    }

    public function testMissingAndNullDeletePathFailBeforeCallbackAndRetainPriorMetadata(): void
    {
        foreach ([false, true] as $explicitNull) {
            [$client, $http] = self::client();
            self::primeMetadata($client, $http);
            $before = $client->whm()->responseMeta();
            $http->addResponse(new Response(200, [], self::envelope(null)));
            try {
                if ($explicitNull) {
                    // @phpstan-ignore argument.type (Explicit invalid nonnullable path.)
                    $client->whm()->customizations()->deleteCustomizations('cpanel', 'jupiter', null);
                } else {
                    // @phpstan-ignore arguments.count (Missing required path.)
                    $client->whm()->customizations()->deleteCustomizations('cpanel', 'jupiter');
                }
                self::fail('Missing/null path must fail before callback.');
            } catch (TypeError) {
                self::assertSame($before, $client->whm()->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    public function testValidDeletePathIsNeverTrimmed(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(null)));
        $client->whm()->customizations()->deleteCustomizations('cpanel', 'jupiter', ' brand.logo.description ');
        self::assertSame(
            'application=cpanel&theme=jupiter&path=%20brand.logo.description%20',
            (string) self::request($http)->getBody(),
        );
        self::assertCount(1, $http->getRequests());
    }

    public function testDeliberateAllScopeGenericFormRemainsAvailableOffline(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(null)));
        $client->whm()->call('delete_customizations', ['application' => 'cpanel', 'theme' => 'jupiter', 'path' => '']);
        self::assertSame('POST', self::request($http)->getMethod());
        self::assertSame('application=cpanel&theme=jupiter&path=', (string) self::request($http)->getBody());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<array{array<string, string>, string}> */
    public static function invalidSetArguments(): iterable
    {
        yield [[], 'scope=scope'];
        yield [['key' => '', 'value' => 'dark'], 'scope=scope&key=&value=dark'];
        yield [['key' => 'theme', 'value' => ''], 'scope=scope&key=theme&value='];
        yield [['json' => ''], 'scope=scope&json='];
        yield [['key' => 'theme', 'value' => 'dark', 'json' => '{}'], 'scope=scope&key=theme&value=dark&json=%7B%7D'];
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('invalidSetArguments')]
    public function testInvalidSetCombinationsAreForwardedAndFailByApiOutcome(array $arguments, string $body): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Fixture InvalidParameter"}}'));
        try {
            $client->whm()->userData()->setScopedUserdata('scope', ...$arguments);
            self::fail('Invalid combinations remain upstream errors.');
        } catch (ApiErrorException $exception) {
            self::assertSame(0, $exception->metadata?->whmResult());
            self::assertSame($exception->metadata, $client->whm()->responseMeta());
            self::assertSame($body, (string) self::request($http)->getBody());
            self::assertCount(1, $http->getRequests());
        }
    }

    private static function request(MockClient $http): RequestInterface
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    private static function primeMetadata(Client $client, MockClient $http): void
    {
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        $client->whm()->userData()->getScopedUserdata('existing');
        self::assertNotNull($client->whm()->responseMeta());
    }

    private static function envelope(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK","version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
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
