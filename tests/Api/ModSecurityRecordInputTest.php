<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Spec\ModSecuritySpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
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

final class ModSecurityRecordInputTest extends TestCase
{
    /** @return iterable<array{non-empty-list<array{settingId: int, state: string|int, remove?: bool}>, string}> */
    public static function requests(): iterable
    {
        yield [
        [['settingId' => 1, 'state' => 'On'], ['settingId' => 2, 'state' => 'Off', 'remove' => false]],
            'setting_id1=1&state1=On&setting_id2=2&state2=Off&remove2=0'];
        yield [
        [['remove' => true, 'state' => 'a%&=secret', 'settingId' => 1]],
            'setting_id1=1&state1=a%25%26%3Dsecret&remove1=1'];
        yield [[
        ['settingId' => 1, 'state' => ''], ['settingId' => 1, 'state' => 0],
            ['settingId' => 1, 'state' => '0']], 'setting_id1=1&state1=&setting_id2=1&state2=0&setting_id3=1&state3=0'];
    }

    /** @param non-empty-list<array{settingId: int, state: string|int, remove?: bool}> $settings */
    #[DataProvider('requests')]
    public function testOneLiteralWhmPost(array $settings, string $body): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"updated_settings":[]}}'));
        self::assertSame(['updated_settings' => []], $whm->modSecurity()->updateSettings($settings));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/modsec_batch_settings?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame($body, (string) $request->getBody());
    }

    public function testLateInvalidRowClearsMetadataWithoutDispatchAndProtectsTrace(): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        $whm->modSecurity()->batchSettings(1, 'On');
        self::assertNotNull($whm->responseMeta());
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            (new ReflectionMethod($whm->modSecurity(), 'updateSettings'))->invoke($whm->modSecurity(), [
                ['settingId' => 1, 'state' => 'private-configuration-secret'],
                ['settingId' => 2, 'state' => 'private-second-secret', 'remove' => null],
            ]);
            self::fail('Invalid late field must fail locally.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('private-', $error->getMessage());
            self::assertStringNotContainsString('private-', $error->getTraceAsString());
            foreach ($error->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    self::assertStringNotContainsString('private-', var_export($frame['args'] ?? [], true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
        self::assertNull($whm->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<array{string, class-string<\Throwable>}> */
    public static function failures(): iterable
    {
        yield ['{"metadata":{"result":0,"reason":"failed"},"data":{}}', ApiException::class];
        yield ['{"metadata":{"result":1},"data":{"updated_settings":"bad"}}', PayloadMismatchException::class];
        yield ['{"metadata":{"result":1}}', PayloadMismatchException::class];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('failures')]
    public function testOneAttemptOnApiAndPayloadErrors(string $response, string $exception): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], $response));
        $caught = null;
        try {
            $whm->modSecurity()->updateSettings([
                ['settingId' => 1, 'state' => 'On'], ['settingId' => 2, 'state' => 'Off'],
            ]);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught);
        self::assertInstanceOf($exception, $caught);
        self::assertCount(1, $http->getRequests());
    }

    public function testPartialFailureKeepsSnapshotAndRequiresExplicitSeparateReread(): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":0,"reason":"Some settings failed"},"data":{"failures":[{"setting_id":2}]}}',
        ));
        $caught = null;
        try {
            $whm->modSecurity()->updateSettings([
                ['settingId' => 1, 'state' => 'On'], ['settingId' => 2, 'state' => 'Off'],
            ]);
        } catch (ApiErrorException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(ApiErrorException::class, $caught);
        $snapshot = $caught->metadata;
        self::assertNotNull($snapshot);
        self::assertSame(0, $snapshot->whmResult());
        self::assertSame($snapshot, $whm->responseMeta());
        self::assertCount(1, $http->getRequests());
        self::assertSame('POST', $http->getRequests()[0]->getMethod());
        self::assertSame(['result' => 0, 'reason' => 'Some settings failed'], $snapshot->whm);

        // Only this explicit caller action reads the staged state; the write did not retry or read automatically.
        $http->addResponse(new Response(
            200,
            [],
            '{"metadata":{"result":1},"data":{"settings":['
                . '{"setting_id":1,"state":"On"},{"setting_id":2,"state":"On"}]}}',
        ));
        self::assertSame(['settings' => [
        ['setting_id' => 1, 'state' => 'On'],
            ['setting_id' => 2, 'state' => 'On']]], $whm->modSecurity()->getSettings());
        self::assertCount(2, $http->getRequests());
        self::assertSame('GET', $http->getRequests()[1]->getMethod());
        self::assertStringContainsString('/json-api/modsec_get_settings?', (string) $http->getRequests()[1]->getUri());
        self::assertSame(0, $snapshot->whmResult());
        self::assertNotSame($snapshot, $whm->responseMeta());
    }

    public function testKnownRecordOverlapClearsMetadataWithPrivateWeakCallerArguments(): void
    {
        [$whm, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{}}'));
        $whm->modSecurity()->batchSettings(1, 'On');
        self::assertNotNull($whm->responseMeta());
        $spec = ModSecuritySpec::UPDATE_SETTINGS;
        $spec['params']['fixed'] = ['name' => 'state1', 'encoding' => 'scalar'];
        $previous = ini_set('zend.exception_ignore_args', '0');
        $caught = null;
        try {
            // Reflection exercises an uncoerced, weak-caller boundary; even an omitted fixed control is owned.
            (new ReflectionMethod($whm, 'run'))->invoke($whm, $spec, [
                'settings' => [['settingId' => 1, 'state' => 'private-record-secret']],
            ]);
        } catch (\Throwable $error) {
            $caught = $error;
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
        self::assertNotNull($caught);
        self::assertSame(LogicException::class, $caught::class);
        self::assertNull($whm->responseMeta());
        self::assertCount(1, $http->getRequests());
        self::assertStringNotContainsString('private-record-secret', $caught->getMessage());
        foreach ($caught->getTrace() as $frame) {
            if (str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                self::assertStringNotContainsString('private-record-secret', var_export($frame['args'] ?? [], true));
            }
        }
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
