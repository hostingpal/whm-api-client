<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
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
        try {
            $whm->modSecurity()->updateSettings([
                ['settingId' => 1, 'state' => 'On'], ['settingId' => 2, 'state' => 'Off'],
            ]);
            self::fail('Expected response failure.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($exception, $error);
        }
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
