<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class NamedMapReviewContractTest extends TestCase
{
    public function testFeatureFlagsNormalizeOnlyCanonicalQuotedFlagsAndKeepNameList(): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":'
            . '{"featurelist":"A","updated_features":{"a":"1","b":"0","c":0,"d":1},'
            . '"invalid_features":["unknown","0"]}}'));
        self::assertSame(
            ['featurelist' => 'A', 'invalid_features' => ['unknown', '0'],
            'updated_features' => ['a' => 1, 'b' => 0, 'c' => 0, 'd' => 1]],
            $whm->hostingPlans()->updateFeaturelist('A', ['a' => true]),
        );
        self::assertCount(1, $http->getRequests());
    }

    public function testMassMethodNameAndExcludedMxInput(): void
    {
        [$whm] = self::client();
        $names = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($whm->accounts()))->getMethods(),
        );
        self::assertContains('massModifyAccount', $names);
        self::assertNotContains('massmodifyacct', $names);
        $method = new ReflectionMethod($whm->accounts(), 'massModifyAccount');
        self::assertCount(50, $method->getParameters());
        foreach ($method->getParameters() as $param) {
            self::assertNotSame('mxPriorities', $param->getName());
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function massOutcomes(): iterable
    {
        yield 'partial' => [0, '{"payload":[{"extended":'
            . '{"cpuser":{"MXCHECK-a.test":"remote","LANG":null},"user":"real"},'
            . '"result":1,"user":"real","warnings":[]},'
            . '{"reason":"failed","result":0,"user":"missing"}]}'];
        yield 'all failed' => [0, '{"payload":[{"reason":"failed","result":0,"user":"missing"}]}'];
        yield 'success' => [1, '{"payload":[{"messages":[],"result":1,"user":"real","warnings":[]}]}'];
    }

    #[DataProvider('massOutcomes')]
    public function testMassReturnsStrictRowsUnderEitherIntegerOutcome(int $status, string $payload): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":' . $status
            . ',"reason":"bulk outcome"},"data":' . $payload . '}'));
        self::assertSame(json_decode($payload, true), $whm->accounts()->massModifyAccount(['real', 'missing']));
        $metadata = $whm->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame($status, $metadata->whmResult());
        self::assertSame($status === 0 ? ['bulk outcome'] : [], $metadata->errors());
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('user-1=real&user-2=missing', (string) $request->getBody());
    }

    /** @return iterable<string, array{string, class-string<Throwable>, mixed}> */
    public static function massFailures(): iterable
    {
        foreach (
            ['', ',"data":null', ',"data":"malformed"', ',"data":{"payload":null}',
            ',"data":{"payload":[{"result":"0"}]}'] as $i => $data
        ) {
            foreach ([0, 1] as $status) {
                yield "payload $i/$status" => ['{"metadata":{"result":' . $status . '}' . $data . '}',
                    PayloadMismatchException::class, $data === '' ? null : json_decode(substr($data, 8), true)];
            }
        }
        foreach (['{}', '{"result":null}', '{"result":"0"}', '{"result":false}', '{"result":2}'] as $i => $meta) {
            yield "metadata $i" => ['{"metadata":' . $meta . ',"data":{}}', InvalidResponseException::class, null];
        }
        yield 'absent metadata' => ['{"data":{}}', InvalidResponseException::class, null];
    }

    #[DataProvider('massFailures')]
    public function testMassFailuresRetainSnapshotAndNeverRetry(string $json, string $class, mixed $payload): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], $json));
        $error = null;
        try {
            $whm->accounts()->massModifyAccount('real');
        } catch (Throwable $caught) {
            $error = $caught;
        }
        self::assertNotNull($error);
        self::assertSame($class, $error::class);
        self::assertCount(1, $http->getRequests());
        self::assertInstanceOf(InvalidResponseException::class, $error);
        self::assertSame($whm->responseMeta(), $error->metadata);
        if ($error instanceof PayloadMismatchException) {
            self::assertFalse($error->outcomeConfirmed);
            self::assertSame($payload, $error->payload);
            self::assertNotNull($error->metadata);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badFeaturePayloads(): iterable
    {
        foreach (['2', '-1', '"01"', '"1.0"', '"true"', '""', 'true', 'false', 'null', '1.0'] as $value) {
            yield 'flag ' . $value => ['{"updated_features":{"a":' . $value . '}}'];
        }
        foreach (
            ['{"featurelist":null}', '{"updated_features":null}', '{"invalid_features":null}',
            '{"invalid_features":[{"bad":0}]}', '{"invalid_features":[1]}'] as $i => $payload
        ) {
            yield 'sibling ' . $i => [$payload];
        }
    }

    #[DataProvider('badFeaturePayloads')]
    public function testFeatureMismatchRetainsAppliedOutcomeWithoutRetry(string $payload): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":' . $payload . '}'));
        $error = null;
        try {
            $whm->hostingPlans()->updateFeaturelist('A', ['a' => true]);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        self::assertNotNull($error);
        self::assertSame(PayloadMismatchException::class, $error::class);
        self::assertInstanceOf(PayloadMismatchException::class, $error);
        self::assertTrue($error->outcomeConfirmed);
        self::assertSame(json_decode($payload, true), $error->payload);
        self::assertSame($whm->responseMeta(), $error->metadata);
        self::assertCount(1, $http->getRequests());
    }

    public function testFeatureOptionalFieldsRemainAbsent(): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        self::assertSame([], $whm->hostingPlans()->updateFeaturelist('A', ['a' => false]));
        self::assertCount(1, $http->getRequests());
    }

    /** @return array{WhmClient, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'synthetic-token'], new HttpTransport($http, $factory, $factory));

        return [$client->whm(), $http];
    }
}
