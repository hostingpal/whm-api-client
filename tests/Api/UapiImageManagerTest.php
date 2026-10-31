<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\ImageManager;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use TypeError;

/**
 * Literal requests and fixtures independently derived from the pinned ImageManager source.
 *
 * @phpstan-type ImageCall Closure(ImageManager): mixed
 * @phpstan-type Operation array{string, string, ImageCall, string, string, mixed}
 */
final class UapiImageManagerTest extends TestCase
{
    /** @param ImageCall $call */
    #[DataProvider('successfulCalls')]
    public function testExactRequestsAndPayloads(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $call($account->imageManager()));
        self::assertRequest($http, $route, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Fixture warning'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, ImageCall, string, string, mixed}> */
    public static function successfulCalls(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => $operation) {
                yield "$route $name" => [$route, ...$operation];
            }
            foreach (self::optionalCalls() as $name => $operation) {
                yield "$route $name" => [$route, ...$operation];
            }
        }
    }

    /** @return iterable<string, Operation> */
    private static function operations(): iterable
    {
        yield 'convert' => [
            'convert_file', 'POST', static fn (ImageManager $i): mixed => $i->convertFile(
                imageFile: './images/a +%26雪.jpg',
                type: 'png',
            ), 'image_file=.%2Fimages%2Fa%20%2B%2526%E9%9B%AA.jpg&type=png',
            '{"converted_file":"/home/npr/images/a +%26雪.png","future":true}',
            ['converted_file' => '/home/npr/images/a +%26雪.png'],
        ];
        yield 'thumbnails retain partial failure' => [
            'create_thumbnails', 'POST', static fn (ImageManager $i): mixed => $i->createThumbnails(
                dir: '/home/npr/images +%26雪',
                heightPercentage: 25,
                widthPercentage: 50,
            ), 'dir=%2Fhome%2Fnpr%2Fimages%20%2B%2526%E9%9B%AA&height_percentage=25&width_percentage=50',
            '[{"file":"/home/npr/images/a.jpg","thumbnail_file":"/home/npr/images/thumbnails/tn_a.jpg"},'
                . '{"failed":1,"file":"/home/npr/images/b.jpg","reason":"too many pixels"},{}]',
            [
        ['file' => '/home/npr/images/a.jpg', 'thumbnail_file' => '/home/npr/images/thumbnails/tn_a.jpg'],
                ['failed' => 1, 'file' => '/home/npr/images/b.jpg', 'reason' => 'too many pixels'], []],
        ];
        yield 'dimensions' => [
            'get_dimensions', 'GET', static fn (ImageManager $i): mixed => $i->getDimensions(
                imageFile: '/home/npr/images/a +%26雪.jpg',
            ), 'image_file=%2Fhome%2Fnpr%2Fimages%2Fa%20%2B%2526%E9%9B%AA.jpg',
            '{"height":300,"width":261}', ['height' => 300, 'width' => 261],
        ];
        yield 'resize' => [
            'resize_image', 'POST', static fn (ImageManager $i): mixed => $i->resizeImage(
                height: 300,
                imageFile: './images/a +%26雪.jpg',
                width: 200,
                saveOriginalAs: 'images/original +%26雪.jpg',
            ), 'height=300&image_file=.%2Fimages%2Fa%20%2B%2526%E9%9B%AA.jpg&width=200'
                . '&save_original_as=images%2Foriginal%20%2B%2526%E9%9B%AA.jpg',
            '"/home/npr/images/a +%26雪.jpg"', '/home/npr/images/a +%26雪.jpg',
        ];
    }

    /** @return iterable<string, Operation> */
    private static function optionalCalls(): iterable
    {
        yield 'convert omitted fields' => [
            'convert_file', 'POST', static fn (ImageManager $i): mixed => $i->convertFile('', ''),
            'image_file=&type=', '{}', [],
        ];
        yield 'convert legacy empty object representation' => [
            'convert_file', 'POST', static fn (ImageManager $i): mixed => $i->convertFile('image.jpg', 'gif'),
            'image_file=image.jpg&type=gif', '[]', [],
        ];
        yield 'dimensions omitted fields' => [
            'get_dimensions', 'GET', static fn (ImageManager $i): mixed => $i->getDimensions(''),
            'image_file=', '{}', [],
        ];
        yield 'dimensions one optional field' => [
            'get_dimensions', 'GET', static fn (ImageManager $i): mixed => $i->getDimensions('image.jpg'),
            'image_file=image.jpg', '{"width":0}', ['width' => 0],
        ];
        yield 'dimensions legacy empty object representation' => [
            'get_dimensions', 'GET', static fn (ImageManager $i): mixed => $i->getDimensions('image.jpg'),
            'image_file=image.jpg', '[]', [],
        ];
        yield 'empty thumbnail list and forwarded zero negative percentages' => [
            'create_thumbnails', 'POST', static fn (ImageManager $i): mixed => $i->createThumbnails('', 0, -1),
            'dir=&height_percentage=0&width_percentage=-1', '[]', [],
        ];
        yield 'failed integer is not widened to a bool flag' => [
            'create_thumbnails', 'POST', static fn (ImageManager $i): mixed => $i->createThumbnails('images', 1, 99),
            'dir=images&height_percentage=1&width_percentage=99', '[{"failed":2}]', [['failed' => 2]],
        ];
        yield 'resize omitted backup' => [
            'resize_image', 'POST', static fn (ImageManager $i): mixed => $i->resizeImage(300, 'image.jpg', 200),
            'height=300&image_file=image.jpg&width=200', '"/home/npr/image.jpg"', '/home/npr/image.jpg',
        ];
        yield 'resize null backup and forwarded zero negative dimensions' => [
            'resize_image', 'POST', static fn (ImageManager $i): mixed => $i->resizeImage(0, 'image.jpg', -1, null),
            'height=0&image_file=image.jpg&width=-1', '"/home/npr/image.jpg"', '/home/npr/image.jpg',
        ];
        yield 'resize explicit empty backup' => [
            'resize_image', 'POST', static fn (ImageManager $i): mixed => $i->resizeImage(1, '', 1, ''),
            'height=1&image_file=&width=1&save_original_as=', '""', '',
        ];
    }

    /** @param ImageCall $call */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadRetainsNewConfirmedMetadata(string $route, Closure $call, ?string $payload): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->imageManager()->getDimensions('seed.jpg');
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account->imageManager());
            self::fail('Invalid payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(['Fixture warning'], $error->metadata->uapiWarnings());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, ImageCall, ?string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [$function, , $call]) {
                $invalid = [null, 'null', 'false', '1', '1.5'];
                if ($function !== 'resize_image') {
                    $invalid[] = '"path"';
                }
                $invalid = [...$invalid, ...match ($function) {
                    'convert_file' => ['[{}]', '{"converted_file":null}', '{"converted_file":1}'],
                    'get_dimensions' => ['[{}]', '{"height":null}', '{"height":"300"}', '{"width":false}',
                        '{"width":2.5}'],
                    'create_thumbnails' => ['{}', '{"0":{}}', '[null]', '[1]', '["path"]',
                        '[{"failed":true}]', '[{"failed":"1"}]', '[{"failed":null}]', '[{"file":1}]',
                        '[{"file":null}]', '[{"reason":false}]', '[{"reason":null}]', '[{"thumbnail_file":[]}]',
                        '[{"thumbnail_file":null}]'],
                    default => ['{}', '[]', '["path"]'],
                }];
                foreach ($invalid as $index => $payload) {
                    yield "$route $name invalid $index" => [$route, $call, $payload];
                }
            }
        }
    }

    /** @param ImageCall $call */
    #[DataProvider('invalidArguments')]
    public function testStrictScalarsFailBeforeIoAndKeepPreviousMetadata(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->imageManager()->getDimensions('seed.jpg');
        $metadata = $account->responseMeta();
        try {
            $call($account->imageManager());
            self::fail('A wrong native scalar must fail.');
        } catch (TypeError) {
            self::assertSame($metadata, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ImageCall}> */
    public static function invalidArguments(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            $cases = [
                // Intentionally invalid scalars exercise the public native signature before metadata reset.
                // @phpstan-ignore argument.type
                'convert path' => static fn (ImageManager $i): mixed => $i->convertFile(1, 'png'),
                // @phpstan-ignore argument.type
                'convert type' => static fn (ImageManager $i): mixed => $i->convertFile('image.jpg', false),
                // @phpstan-ignore argument.type
                'thumbnail dir' => static fn (ImageManager $i): mixed => $i->createThumbnails(null, 25, 25),
                // @phpstan-ignore argument.type
                'thumbnail height' => static fn (ImageManager $i): mixed => $i->createThumbnails('images', '25', 25),
                // @phpstan-ignore argument.type
                'thumbnail width' => static fn (ImageManager $i): mixed => $i->createThumbnails('images', 25, 2.5),
                // @phpstan-ignore argument.type
                'dimensions path' => static fn (ImageManager $i): mixed => $i->getDimensions([]),
                // @phpstan-ignore argument.type
                'resize height' => static fn (ImageManager $i): mixed => $i->resizeImage('300', 'image.jpg', 200),
                // @phpstan-ignore argument.type
                'resize path' => static fn (ImageManager $i): mixed => $i->resizeImage(300, false, 200),
                // @phpstan-ignore argument.type
                'resize width' => static fn (ImageManager $i): mixed => $i->resizeImage(300, 'image.jpg', null),
                // @phpstan-ignore argument.type
                'resize backup' => static fn (ImageManager $i): mixed => $i->resizeImage(300, 'image.jpg', 200, 1),
            ];
            foreach ($cases as $name => $call) {
                yield "$route $name" => [$route, $call];
            }
        }
    }

    /** @param ImageCall $call */
    #[DataProvider('operationRoutes')]
    public function testApiFailurePrecedesPayloadAndReplacesMetadata(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->imageManager()->getDimensions('seed.jpg');
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));
        try {
            $call($account->imageManager());
            self::fail('Status zero must fail before payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @param ImageCall $call */
    #[DataProvider('operationRoutes')]
    public function testHttpAndTransportFailuresClearMetadataWithoutRetry(string $route, Closure $call): void
    {
        foreach (['http', 'transport'] as $failure) {
            [$client, $http] = self::client($route);
            $account = $client->uapi('npr');
            $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
            $account->imageManager()->getDimensions('seed.jpg');
            self::assertNotNull($account->responseMeta());
            if ($failure === 'http') {
                $http->addResponse(new Response(503, [], 'unavailable'));
            } else {
                $http->addException(new class extends RuntimeException implements ClientExceptionInterface {
                });
            }
            try {
                $call($account->imageManager());
                self::fail('The failed request must throw.');
            } catch (ApiException $error) {
                self::assertSame($failure === 'transport', $error instanceof TransportException);
                self::assertNull($error->metadata);
                self::assertNull($account->responseMeta());
            }
            self::assertCount(2, $http->getRequests());
        }
    }

    /** @param ImageCall $call */
    #[DataProvider('bridgeOperations')]
    public function testOuterBridgeFailureWins(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->imageManager()->getDimensions('seed.jpg');
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Outer failure"},'
            . '"data":{"uapi":{"status":1,"data":null}}}'));
        try {
            $call($account->imageManager());
            self::fail('Outer WHM failure must win.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(['Outer failure'], $error->errors());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, ImageCall}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [, , $call]) {
                yield "$route $name" => [$route, $call];
            }
        }
    }

    /** @return iterable<string, array{string, ImageCall}> */
    public static function bridgeOperations(): iterable
    {
        foreach (self::operations() as $name => [, , $call]) {
            yield $name => ['bridge', $call];
        }
    }

    public function testPublicSignaturesPreserveNamedArgumentsAndDefaults(): void
    {
        $signatures = [
            'convertFile' => ['array', ['imageFile' => 'string', 'type' => 'string']],
            'createThumbnails' => ['array', [
                'dir' => 'string', 'heightPercentage' => 'int', 'widthPercentage' => 'int',
            ]],
            'getDimensions' => ['array', ['imageFile' => 'string']],
            'resizeImage' => ['string', ['height' => 'int', 'imageFile' => 'string', 'width' => 'int',
                'saveOriginalAs' => '?string']],
        ];
        foreach ($signatures as $name => [$returnType, $parameters]) {
            $method = new ReflectionMethod(ImageManager::class, $name);
            self::assertSame($returnType, (string) $method->getReturnType());
            self::assertSame(array_keys($parameters), array_map(
                static fn ($p): string => $p->getName(),
                $method->getParameters(),
            ));
            foreach ($method->getParameters() as $parameter) {
                self::assertSame($parameters[$parameter->getName()], (string) $parameter->getType());
                self::assertSame(
                    $parameter->getName() === 'saveOriginalAs',
                    $parameter->isDefaultValueAvailable(),
                );
                if ($parameter->isDefaultValueAvailable()) {
                    self::assertNull($parameter->getDefaultValue());
                }
            }
        }
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=ImageManager&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/ImageManager/' . $function;
        if ($method === 'GET') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        self::assertSame($method, $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic failure"]')
            . ',"warnings":["Fixture warning"],"messages":null,"metadata":{}}';
        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
