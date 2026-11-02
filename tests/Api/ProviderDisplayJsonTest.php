<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;
use TypeError;

final class ProviderDisplayJsonTest extends TestCase
{
    /** @param array<mixed> $configuration */
    #[DataProvider('validInputs')]
    public function testOneStructuredFormField(array $configuration, string $wire): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1,"reason":"fixture"}}'));
        $group = $client->whm()->authentication();
        self::assertNull((new ReflectionMethod($group, 'configureProviderDisplay'))->invoke(
            $group,
            'provider',
            'cpaneld',
            $configuration,
        ));
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/set_provider_display_configurations?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            'provider_id=provider&service_name=cpaneld&configurations=' . $wire,
            (string) $request->getBody(),
        );
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function validInputs(): iterable
    {
        yield 'empty object' => [[], '%7B%7D'];
        yield 'all six strings' => [
        [
            'color' => '', 'display_name' => 'Login', 'icon' => 'raw', 'icon_type' => 'anything',
            'label' => 'Text', 'textcolor' => '000',
        ], '%7B%22color%22%3A%22%22%2C%22display_name%22%3A%22Login%22%2C%22icon%22%3A%22raw%22'
            . '%2C%22icon_type%22%3A%22anything%22%2C%22label%22%3A%22Text%22'
            . '%2C%22textcolor%22%3A%22000%22%7D'];
        yield 'insertion order and string JSON' => [
            ['label' => '{"api.version":1}', 'color' => '+/&%="é'],
            '%7B%22label%22%3A%22%7B%5C%22api.version%5C%22%3A1%7D%22%2C%22color%22%3A%22'
            . '%2B%5C%2F%26%25%3D%5C%22%5Cu00e9%22%7D',
        ];
    }

    /** @param array<mixed> $configuration */
    #[DataProvider('invalidInputs')]
    public function testInvalidValueClearsMetadataBeforeIO(array $configuration): void
    {
        [$client, $http] = self::client();
        $handle = $client->whm();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1}}'));
        $handle->call('version');
        self::assertNotNull($handle->responseMeta());
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            (new ReflectionMethod($handle->authentication(), 'configureProviderDisplay'))->invoke(
                $handle->authentication(),
                'provider',
                'cpaneld',
                $configuration,
            );
            self::fail('Invalid structured value accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secret', (string) $exception);
            self::assertNull($exception->getPrevious());
            self::assertNull($handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'null' => [['label' => null]];
        yield 'unknown' => [['unexpected' => 'secret']];
        yield 'integer key' => [[1 => 'secret']];
        yield 'list' => [['secret']];
        yield 'wrong scalar' => [['color' => 1]];
        yield 'late invalid' => [['color' => 'secret', 'label' => false]];
    }

    public function testRequiredSignatureAndSensitivity(): void
    {
        $method = new ReflectionMethod(
            \HostingPal\WhmApiClient\Api\Whm\Authentication::class,
            'configureProviderDisplay',
        );
        self::assertSame(
            ['providerId', 'serviceName', 'configurations'],
            array_map(static fn ($p) => $p->getName(), $method->getParameters()),
        );
        self::assertSame(3, $method->getNumberOfRequiredParameters());
        self::assertSame('array', (string) $method->getParameters()[2]->getType());
        self::assertCount(1, $method->getParameters()[2]->getAttributes(SensitiveParameter::class));
    }

    public function testAPIErrorRetainsMetadataAndMakesOneAttemptWithSensitiveEncodedValue(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"denied"}}'));
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $client->whm()->authentication()->configureProviderDisplay(
                'provider',
                'cpaneld',
                ['label' => 'secret-value'],
            );
            self::fail('API failure accepted.');
        } catch (ApiErrorException $exception) {
            self::assertStringNotContainsString('secret-value', (string) $exception);
            self::assertNull($exception->getPrevious());
            self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    public function testNativeArrayTypeErrorPrecedesMetadataReset(): void
    {
        [$client, $http] = self::client();
        $handle = $client->whm();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1}}'));
        $handle->call('version');
        $metadata = $handle->responseMeta();
        try {
            (new ReflectionMethod($handle->authentication(), 'configureProviderDisplay'))->invoke(
                $handle->authentication(),
                'provider',
                'cpaneld',
                null,
            );
            self::fail('Native array type accepted null.');
        } catch (TypeError) {
            self::assertSame($metadata, $handle->responseMeta());
            self::assertCount(1, $http->getRequests());
        }
    }

    #[DataProvider('disabledIniFunctions')]
    public function testActualFieldPathDoesNotReadOrModifyIniSettings(string $disabled): void
    {
        $code = <<<'PHP_CODE'
require 'vendor/autoload.php';
$http = new Http\Mock\Client();
$factory = new Nyholm\Psr7\Factory\Psr17Factory();
$client = new HostingPal\WhmApiClient\Client([
    'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
], new HostingPal\WhmApiClient\Http\HttpTransport($http, $factory, $factory));
$group = $client->whm()->authentication();
foreach ([[], ['label' => '+/"&=é', 'color' => '#fff', 'icon' => '{"label":"data"}']] as $configuration) {
    $http->addResponse(new Nyholm\Psr7\Response(200, [], '{"metadata":{"result":1}}'));
    $group->configureProviderDisplay('provider', 'cpaneld', $configuration);
    if ($client->whm()->responseMeta()?->whmResult() !== 1) {
        exit(1);
    }
}
if (count($http->getRequests()) !== 2) {
    exit(2);
}
$expectedUri = 'https://api.example.test:2087/json-api/'
    . 'set_provider_display_configurations?api.version=1';
foreach ($http->getRequests() as $request) {
    if ($request->getMethod() !== 'POST'
        || (string) $request->getUri() !== $expectedUri
        || $request->getHeaderLine('Content-Type') !== 'application/x-www-form-urlencoded') {
        exit(3);
    }
    echo (string) $request->getBody(), "\n";
}
PHP_CODE;
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=' . $disabled, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr === false ? '' : $stderr);
        self::assertSame('', $stderr);
        self::assertSame(
            "provider_id=provider&service_name=cpaneld&configurations=%7B%7D\n"
            . 'provider_id=provider&service_name=cpaneld&configurations='
            . '%7B%22label%22%3A%22%2B%5C%2F%5C%22%26%3D%5Cu00e9%22%2C%22color%22%3A%22%23fff%22'
            . '%2C%22icon%22%3A%22%7B%5C%22label%5C%22%3A%5C%22data%5C%22%7D%22%7D' . "\n",
            $stdout,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function disabledIniFunctions(): iterable
    {
        yield 'no ini_get' => ['ini_get'];
        yield 'no ini_set' => ['ini_set'];
        yield 'neither' => ['ini_get,ini_set'];
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
