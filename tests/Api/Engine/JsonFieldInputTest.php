<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\ArgumentEncoder;
use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class JsonFieldInputTest extends TestCase
{
    /** @param array<mixed> $schema */
    #[DataProvider('fieldRootSchemas')]
    public function testFormFieldRootsStayIndependentFromWholeBodyPolicy(
        array $schema,
        mixed $value,
        string $wire,
    ): void {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $client = new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory));
        $handle = $client->whm();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1}}'));
        $spec = ['function' => 'synthetic_field', 'method' => 'POST', 'body' => 'form', 'outcome' => 'flag',
            'params' => ['configuration' => ['name' => 'configuration', 'encoding' => 'json',
                'json' => ['required' => true, 'schema' => $schema]]], 'returns' => null];
        $result = (new ReflectionMethod($handle, 'run'))->getClosure($handle)($spec, ['configuration' => $value]);
        self::assertNull($result);
        self::assertCount(1, $http->getRequests());
        $request = $http->getRequests()[0];
        self::assertSame(
            'https://api.example.test:2087/json-api/synthetic_field?api.version=1',
            (string) $request->getUri(),
        );
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('configuration=' . $wire, (string) $request->getBody());
        self::assertSame(1, $handle->responseMeta()?->whmResult());
    }

    /** @return iterable<string, array{array<mixed>, mixed, string}> */
    public static function fieldRootSchemas(): iterable
    {
        yield 'string root' => [['type' => 'string'], '+/"&=é', '%22%2B%5C%2F%5C%22%26%3D%5Cu00e9%22'];
        yield 'empty list root' => [['type' => 'list', 'items' => ['type' => 'string']], [], '%5B%5D'];
        yield 'list root' => [
        ['type' => 'list', 'items' => ['type' => 'string']], ['one', 'two'],
            '%5B%22one%22%2C%22two%22%5D'];
        yield 'object routing names are data' => [
        ['type' => 'object', 'fields' => [
            'api.version' => ['type' => 'string'], 'cpanel.user' => ['type' => 'string'],
        ]], ['api.version' => 'data', 'cpanel.user' => 'data'],
            '%7B%22api.version%22%3A%22data%22%2C%22cpanel.user%22%3A%22data%22%7D'];
        yield 'required null root' => [['type' => 'string', 'nullable' => true], null, 'null'];
        yield 'scalar alternative null' => [['type' => 'union', 'anyOf' => [
            ['type' => 'string'], ['type' => 'null'],
        ]], null, 'null'];
    }

    public function testExplicitNullAndOmissionAreDistinct(): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json',
            'json' => ['required' => true, 'schema' => ['type' => 'string', 'nullable' => true]]]];
        self::assertSame('configuration=null', ArgumentEncoder::encode($params, ['value' => null])->encode());
        self::assertSame('configuration=%22null%22', ArgumentEncoder::encode($params, ['value' => 'null'])->encode());
        $this->expectException(InvalidArgumentException::class);
        ArgumentEncoder::encode($params, []);
    }

    public function testFieldRootRoutingNamesAreData(): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json', 'json' => ['required' => true,
            'schema' => ['type' => 'object', 'fields' => ['api.version' => ['type' => 'string']],
                'required' => ['api.version']]]]];
        SpecChecker::check(['function' => 'example', 'method' => 'POST', 'body' => 'form', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
        self::assertSame(
            'configuration=%7B%22api.version%22%3A%22data%22%7D',
            ArgumentEncoder::encode($params, ['value' => ['api.version' => 'data']])->encode(),
        );
    }

    public function testOptionalNonnullableFieldOmission(): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json',
            'json' => ['required' => false, 'schema' => ['type' => 'object', 'fields' => []]]]];
        self::assertSame('', ArgumentEncoder::encode($params, [])->encode());
        self::assertSame('', ArgumentEncoder::encode($params, ['value' => null])->encode());
        self::assertSame('configuration=%7B%7D', ArgumentEncoder::encode($params, ['value' => []])->encode());
    }

    public function testSerializedValueStaysSensitiveAtCollisionFailure(): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json', 'json' => ['required' => true,
            'schema' => ['type' => 'object', 'fields' => ['label' => ['type' => 'string']]]]],
            'mapped' => ['name' => 'mapped', 'encoding' => 'collection', 'collection' => [
                'kind' => 'map', 'item_type' => 'string', 'allow_scalar' => false, 'empty' => 'allow', 'prefix' => '',
            ]]];
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            ArgumentEncoder::encode($params, ['mapped' => ['configuration' => 'secret-value'],
                'value' => ['label' => 'secret-value']]);
            self::fail('Colliding field accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secret-value', (string) $exception);
            self::assertNull($exception->getPrevious());
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    /** @param array<mixed> $descriptor */
    #[DataProvider('malformedDescriptors')]
    public function testMalformedDescriptorsFailWithoutArguments(array $descriptor): void
    {
        $params = ['value' => ['name' => 'configuration', 'encoding' => 'json', 'json' => $descriptor]];
        $this->expectException(LogicException::class);
        SpecChecker::check(['function' => 'example', 'method' => 'POST', 'outcome' => 'flag',
            'params' => $params, 'returns' => null]);
    }

    /** @return iterable<array{array<mixed>}> */
    public static function malformedDescriptors(): iterable
    {
        yield [[]];
        yield [['required' => true, 'schema' => ['type' => 'mixed']]];
        yield [['required' => false, 'schema' => ['type' => 'string', 'nullable' => true]]];
        yield [['required' => 'yes', 'schema' => ['type' => 'string']]];
        yield [['required' => true, 'schema' => ['type' => 'string'], 'routes' => 'direct']];
    }
}
