<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Branding;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Message\RequestMatcher;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Independent inert fixtures: no returned path, URL, HTML, script or image is used. */
final class UapiBrandingTest extends TestCase
{
    public function testCachedAccountAccessorAndNativeSignaturesWithoutIo(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        self::assertTrue((new ReflectionClass($account))->hasMethod('branding'), 'Branding accessor must exist.');
        self::assertSame($account->branding(), $account->branding());
        self::assertNotSame($account->branding(), $client->uapi('other')->branding());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
        $signatures = [
            'getApplicationInformation' => ['array', ['appKey' => ['string', false, false]]],
            'getApplications' => ['array', ['appKeys' => ['?string', true, false]]],
            'getAvailableApplications' => ['array', ['nvarglist' => ['?string', true, false]]],
            'getInformationForApplications' => ['array', [
                'docroot' => ['string', false, true], 'appKeys' => ['?string', true, false],
            ]],
            'includeFile' => ['?string', [
                'file' => ['string', false, true], 'data' => ['?string', true, true],
                'raw' => ['?bool', true, false], 'skipDefault' => ['?bool', true, false],
            ]],
        ];
        foreach ($signatures as $name => [$result, $params]) {
            $method = new ReflectionMethod(Branding::class, $name);
            self::assertSame($result, (string) $method->getReturnType());
            self::assertCount(count($params), $method->getParameters());
            foreach ($method->getParameters() as $parameter) {
                self::assertArrayHasKey($parameter->getName(), $params);
                [$type, $optional, $sensitive] = $params[$parameter->getName()];
                self::assertSame($type, (string) $parameter->getType());
                self::assertSame($optional, $parameter->isOptional());
                if ($optional) {
                    self::assertNull($parameter->getDefaultValue());
                }
                self::assertCount($sensitive ? 1 : 0, $parameter->getAttributes(SensitiveParameter::class));
            }
        }
    }

    /** @param array<string, string|bool|null> $arguments */
    #[DataProvider('requests')]
    public function testLiteralRequestsEncodeSuppliedBytesOnce(
        string $route,
        string $method,
        string $function,
        array $arguments,
        string $fields,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, $method === 'includeFile' ? '""' : '{}')));
        self::assertSame($method === 'includeFile' ? '' : [], $account->branding()->$method(...$arguments));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $post = in_array($method, ['includeFile', 'getInformationForApplications'], true);
        self::assertSame($post ? 'POST' : 'GET', $request->getMethod());
        $url = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=Branding&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Branding/' . $function;
        self::assertSame(
            $url . (!$post && $fields !== '' ? ($route === 'bridge' ? '&' : '?') . $fields : ''),
            (string) $request->getUri(),
        );
        self::assertSame($post ? $fields : '', (string) $request->getBody());
        self::assertSame($post ? 'application/x-www-form-urlencoded' : '', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
    }

    /** @return iterable<string, array{string, string, string, array<string, string|bool|null>, string}> */
    public static function requests(): iterable
    {
        $cases = [
            ['getApplicationInformation', 'get_application_information', ['appKey' => 'boxtrapper'],
                'app_key=boxtrapper'],
            ['getApplicationInformation', 'get_application_information', ['appKey' => ''], 'app_key='],
            ['getApplicationInformation', 'get_application_information', ['appKey' => '%,+š'],
                'app_key=%25%2C%2B%C5%A1'],
            ['getApplications', 'get_applications', [], ''],
            ['getApplications', 'get_applications', ['appKeys' => null], ''],
            ['getApplications', 'get_applications', ['appKeys' => ''], 'app_keys='],
            ['getApplications', 'get_applications', ['appKeys' => 'a,b %2F+š'],
                'app_keys=a%2Cb%20%252F%2B%C5%A1'],
            ['getAvailableApplications', 'get_available_applications', [], ''],
            ['getAvailableApplications', 'get_available_applications', ['nvarglist' => null], ''],
            ['getAvailableApplications', 'get_available_applications', ['nvarglist' => ''], 'nvarglist='],
            ['getAvailableApplications', 'get_available_applications', ['nvarglist' => 'z|a %2F+š'],
                'nvarglist=z%7Ca%20%252F%2B%C5%A1'],
            ['getInformationForApplications', 'get_information_for_applications', ['docroot' => '/theme'],
                'docroot=%2Ftheme'],
            ['getInformationForApplications', 'get_information_for_applications',
                ['docroot' => '', 'appKeys' => null], 'docroot='],
            ['getInformationForApplications', 'get_information_for_applications',
                ['appKeys' => 'a,b %2F+š', 'docroot' => '/theme/../ %2F+š'],
                'docroot=%2Ftheme%2F..%2F%20%252F%2B%C5%A1&app_keys=a%2Cb%20%252F%2B%C5%A1'],
            ['getInformationForApplications', 'get_information_for_applications',
                ['docroot' => '/theme', 'appKeys' => ''], 'docroot=%2Ftheme&app_keys='],
            ['includeFile', 'include', ['file' => 'header.tt'], 'file=header.tt'],
            ['includeFile', 'include', ['file' => '', 'data' => null, 'raw' => null, 'skipDefault' => null], 'file='],
            ['includeFile', 'include', ['file' => 'header.html', 'data' => '', 'raw' => false, 'skipDefault' => true],
                'file=header.html&data=&raw=0&skip_default=1'],
            ['includeFile', 'include', ['skipDefault' => false, 'raw' => true, 'data' => '{"a":1}', 'file' => 'h.tt'],
                'file=h.tt&data=%7B%22a%22%3A1%7D&raw=1&skip_default=0'],
            ['includeFile', 'include', ['file' => '../ %2F+š', 'data' => '{broken%2F+š'],
                'file=..%2F%20%252F%2B%C5%A1&data=%7Bbroken%252F%2B%C5%A1'],
            ['includeFile', 'include', ['file' => 'h.tt', 'data' => 'null'], 'file=h.tt&data=null'],
            ['includeFile', 'include', ['file' => 'h.tt', 'data' => '[1,"0"]'],
                'file=h.tt&data=%5B1%2C%220%22%5D'],
            ['includeFile', 'include', ['file' => 'h.tt', 'data' => " {\n\"a\":1,\"a\":2} "],
                'file=h.tt&data=%20%7B%0A%22a%22%3A1%2C%22a%22%3A2%7D%20'],
            ['includeFile', 'include', ['file' => 'h.tt', 'raw' => false], 'file=h.tt&raw=0'],
            ['includeFile', 'include', ['file' => 'h.tt', 'skipDefault' => false], 'file=h.tt&skip_default=0'],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route $index" => [$route, ...$case];
            }
        }
    }

    public function testUsefulApplicationFieldsSurviveAndOnlyKeyedIntegersNormalize(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope(
            'direct',
            '{"z":{"acontent":"<span>inert</span>","base64_png_image":"inert-image",'
            . '"feature":"f","file":"icon","group":"g","height":"48","if":"$inert",'
            . '"imgtype":"open","implements":"Module_Name","itemdesc":"Display","itemorder":"0",'
            . '"key":"z","onclick":"inert()","plainitemdesc":"Plain","searchtext":"a b",'
            . '"subtype":"open","target":"new-target","touch":"ftp","type":"open",'
            . '"url":"https://inert.example.test/a","width":"35","unknown":"discard"},'
            . '"0":{"height":32,"width":0,"itemorder":-1},"01":{}}',
        )));
        self::assertSame([
            'z' => ['acontent' => '<span>inert</span>', 'base64_png_image' => 'inert-image',
                'feature' => 'f', 'file' => 'icon', 'group' => 'g', 'height' => 48, 'if' => '$inert',
                'imgtype' => 'open', 'implements' => 'Module_Name', 'itemdesc' => 'Display', 'itemorder' => 0,
                'key' => 'z', 'onclick' => 'inert()', 'plainitemdesc' => 'Plain', 'searchtext' => 'a b',
                'subtype' => 'open', 'target' => 'new-target', 'touch' => 'ftp', 'type' => 'open',
                'url' => 'https://inert.example.test/a', 'width' => 35],
            0 => ['height' => 32, 'itemorder' => -1, 'width' => 0], '01' => [],
        ], $client->uapi()->branding()->getApplications());
    }

    public function testAllFormalSingleApplicationFieldsAndInertStringsSurvive(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], '{"status":1,"data":{'
            . '"feature":"f","file":"i","group":"g","height":32,"if":"$inert",'
            . '"imgtype":"future","itemdesc":"D","itemorder":0,"module":"M","searchtext":"a b",'
            . '"subtype":"future","type":"future","url":"../inert","width":35}}'));
        self::assertSame(
            ['feature' => 'f', 'file' => 'i', 'group' => 'g', 'height' => 32, 'if' => '$inert',
            'imgtype' => 'future', 'itemdesc' => 'D', 'itemorder' => 0, 'module' => 'M', 'searchtext' => 'a b',
            'subtype' => 'future', 'type' => 'future', 'url' => '../inert', 'width' => 35],
            $client->uapi()->branding()->getApplicationInformation('f'),
        );
    }

    public function testAvailableNestedFieldsOrderingMapsAndExampleOnlyFieldsSurvive(): void
    {
        [$client, $http] = self::client('bridge');
        $http->addResponse(new Response(200, [], self::envelope(
            'bridge',
            '{"default_group_order":{"z":2,"0":0},"grouporder":["z","a"],"groups":['
            . '{"desc":"Z","group":"z","items":[{"acontent":"tag","base64_png_image":"inert",'
            . '"feature":"f","file":"i","group":"z","height":"48","if":"$inert",'
            . '"imgtype":"open","implements":"M","itemdesc":"D","itemorder":"1","module":"N",'
            . '"onclick":"inert()","searchtext":"a b","subtype":"open","target":"t","touch":"ftp",'
            . '"type":"open","url":"../inert","width":"48","key":"k","plainitemdesc":"P"},{}]},'
            . '{"group":"a","items":[]}],"implements":{"M":"k","0":"zero"},'
            . '"index":{"z":[],"a":[2],"k":[1,0,3]}}',
        )));
        self::assertSame([
            'default_group_order' => ['z' => 2, 0 => 0], 'grouporder' => ['z', 'a'],
            'groups' => [['desc' => 'Z', 'group' => 'z', 'items' => [[
                'acontent' => 'tag', 'base64_png_image' => 'inert', 'feature' => 'f', 'file' => 'i',
                'group' => 'z', 'height' => '48', 'if' => '$inert', 'imgtype' => 'open', 'implements' => 'M',
                'itemdesc' => 'D', 'itemorder' => '1', 'key' => 'k', 'module' => 'N', 'onclick' => 'inert()',
                'plainitemdesc' => 'P', 'searchtext' => 'a b', 'subtype' => 'open', 'target' => 't',
                'touch' => 'ftp', 'type' => 'open', 'url' => '../inert', 'width' => '48',
            ], []]], ['group' => 'a', 'items' => []]],
            'implements' => ['M' => 'k', 0 => 'zero'], 'index' => ['z' => [], 'a' => [2], 'k' => [1, 0, 3]],
        ], $client->uapi('fixture')->branding()->getAvailableApplications());
    }

    public function testSitemapNestedFieldsAndOrderedStringTermsSurvive(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope(
            'direct',
            '{"z":{"condition":{"feature":"f","if":"$inert","module":"M"},"description":"D",'
            . '"icon":{"height":32,"url":"../inert","width":35},"implements":"N","key":"k",'
            . '"name":"Name","order":0,"terms":["b","a","a"],"type":"open","url":"../x"},'
            . '"a":{"terms":[]},"0":{}}',
        )));
        self::assertSame(
            ['z' => ['condition' => ['feature' => 'f', 'if' => '$inert', 'module' => 'M'],
            'description' => 'D', 'icon' => ['height' => 32, 'url' => '../inert', 'width' => 35],
            'implements' => 'N', 'key' => 'k', 'name' => 'Name', 'order' => 0, 'terms' => ['b', 'a', 'a'],
            'type' => 'open', 'url' => '../x'], 'a' => ['terms' => []], 0 => []],
            $client->uapi()->branding()->getInformationForApplications('/PRIVATE theme'),
        );
    }

    #[DataProvider('payloads')]
    public function testPayloadContractsPreserveOptionalityAndRejectWrongTypes(
        string $method,
        ?string $json,
        bool $valid,
        mixed $expected,
    ): void {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', $json)));
        if (!$valid) {
            $this->expectException(PayloadMismatchException::class);
        }
        try {
            self::assertSame($expected, self::invoke($account->branding(), $method));
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $account->responseMeta()?->uapiStatus());
        }
    }

    /** @return iterable<string, array{string, ?string, bool, mixed}> */
    public static function payloads(): iterable
    {
        $methods = ['getApplicationInformation', 'getApplications', 'getAvailableApplications',
            'getInformationForApplications'];
        foreach ($methods as $method) {
            yield "$method empty object" => [$method, '{}', true, []];
            yield "$method inherited empty array" => [$method, '[]', true, []];
            foreach ([null, 'null', '0', '1.5', 'true', '"text"', '[{}]'] as $index => $wrong) {
                yield "$method root $index" => [$method, $wrong, false, null];
            }
        }
        foreach (['getApplications', 'getInformationForApplications'] as $method) {
            foreach (['null', '1', '"text"', '[1]'] as $wrong) {
                yield "$method row $wrong" => [$method, '{"a":' . $wrong . '}', false, null];
            }
        }
        foreach (['height', 'itemorder', 'width'] as $field) {
            foreach (['"48"', 'null', '1.5', 'true'] as $wrong) {
                yield "single $field $wrong" => ['getApplicationInformation', '{"' . $field . '":'
                    . $wrong . '}', false, null];
            }
            foreach (
                ['"01"', '"+1"', '" 1"', '"1 "', '"1.0"', '"1e2"', '"-0"',
                '"9223372036854775808"', '"-9223372036854775809"', '1.5', 'true', 'null'] as $wrong
            ) {
                yield "map $field $wrong" => ['getApplications', '{"a":{"' . $field . '":'
                    . $wrong . '}}', false, null];
            }
            yield "available dimension $field" => ['getAvailableApplications',
                '{"groups":[{"items":[{"' . $field . '":48}]}]}', false, null];
        }
        foreach (
            ['icon' => '{"height":"32"}', 'icon width' => '{"width":"35"}', 'order' => '"1"', 'condition' => 'null',
            'icon null' => 'null'] as $field => $wrong
        ) {
            yield "sitemap $field" => ['getInformationForApplications',
                '{"a":{"' . (str_starts_with($field, 'icon') ? 'icon' : $field) . '":' . $wrong . '}}', false, null];
        }
        foreach (['null', '"x"', '1', '[1]', '["a",1]', '{"1":"a"}', '{"a":"b"}'] as $wrong) {
            yield "terms $wrong" => ['getInformationForApplications', '{"a":{"terms":' . $wrong . '}}',
                false, null];
        }
        foreach (
            ['default_group_order' => '{"a":"1"}', 'grouporder' => '[1]', 'groups' => '{}',
            'implements' => '{"a":1}', 'index' => '{"a":["1"]}'] as $field => $wrong
        ) {
            yield "available $field" => ['getAvailableApplications', '{"' . $field . '":' . $wrong . '}',
                false, null];
            yield "available null $field" => ['getAvailableApplications', '{"' . $field . '":null}',
                false, null];
        }
        foreach (['acontent', 'base64_png_image', 'key', 'onclick', 'plainitemdesc', 'target'] as $field) {
            yield "added optional null $field" => ['getApplications', '{"a":{"' . $field . '":null}}',
                false, null];
        }
        foreach (['key', 'plainitemdesc'] as $field) {
            yield "nested added null $field" => ['getAvailableApplications',
                '{"groups":[{"items":[{"' . $field . '":null}]}]}', false, null];
        }
        yield 'canonical integer boundaries' => ['getApplications',
            '{"a":{"height":"9223372036854775807","itemorder":"-9223372036854775808","width":"0"}}',
            true, ['a' => ['height' => PHP_INT_MAX, 'itemorder' => PHP_INT_MIN, 'width' => 0]]];
        yield 'available empty containers' => ['getAvailableApplications',
            '{"default_group_order":{},"grouporder":[],"groups":[],"implements":{},"index":{}}', true,
            ['default_group_order' => [], 'grouporder' => [], 'groups' => [], 'implements' => [], 'index' => []]];
        foreach (
            ['null' => null, '""' => '', '"0"' => '0', '"00"' => '00',
            '"<div>š &amp; inert()</div>"' => '<div>š &amp; inert()</div>'] as $json => $expected
        ) {
            yield "include scalar $json" => ['includeFile', $json, true, $expected];
        }
        foreach ([null, '0', '1.5', 'true', '{}', '[]'] as $index => $wrong) {
            yield "include wrong $index" => ['includeFile', $wrong, false, null];
        }
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('nativeFailures')]
    public function testNativeFailuresRetainPriorMetadataWithoutAnAttempt(string $method, array $arguments): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', '{}')));
        $account->branding()->getApplications();
        $before = $account->responseMeta();
        try {
            $account->branding()->$method(...$arguments);
            self::fail('Native argument failure must occur before request entry.');
        } catch (TypeError) {
            self::assertSame($before, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<array{string, list<mixed>}> */
    public static function nativeFailures(): iterable
    {
        yield ['getApplicationInformation', []];
        yield ['getApplicationInformation', [null]];
        yield ['getApplications', [[]]];
        yield ['getAvailableApplications', [42]];
        yield ['getInformationForApplications', [false]];
        yield ['includeFile', [null]];
        yield ['includeFile', ['file', []]];
        yield ['includeFile', ['file', null, '1']];
        yield ['includeFile', ['file', null, null, 1]];
    }

    #[DataProvider('failures')]
    public function testFailurePrecedenceOneAttemptMetadataResetAtIoAndActualLibraryTracePrivacy(
        string $route,
        string $method,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->branding()->getApplications();
        $previous = $account->responseMeta();
        $clearedAtIo = false;
        $http->on(new class implements RequestMatcher {
            public function matches(RequestInterface $request): bool
            {
                return true;
            }
        }, static function () use ($account, $route, $failure, &$clearedAtIo): Response {
            $clearedAtIo = $account->responseMeta() === null;
            if ($failure === 'transport') {
                throw new class ('PRIVATE transport') extends RuntimeException implements ClientExceptionInterface {
                };
            }
            return match ($failure) {
                'http' => new Response(503, [], 'PRIVATE non-json'),
                'json' => new Response(200, [], 'PRIVATE non-json'),
                'api' => new Response(200, [], self::envelope($route, '42', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"PRIVATE outer"},'
                    . '"data":{"uapi":{"status":1,"data":{"PRIVATE":null}}}}'),
                'status' => new Response(200, [], $route === 'bridge'
                    ? '{"metadata":{"result":1},"data":{"uapi":{"status":"1","data":{"PRIVATE":null}}}}'
                    : '{"result":{"status":"1","data":{"PRIVATE":null}}}'),
                default => new Response(
                    200,
                    [],
                    self::envelope($route, '{"PRIVATE successful mismatch":null}', 1, true),
                ),
            };
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            self::invoke($account->branding(), $method);
            self::fail('The invocation must fail without retry.');
        } catch (ApiException $error) {
            self::assertSame(match ($failure) {
                'transport' => TransportException::class,
                'http' => HttpException::class,
                'json', 'status' => InvalidResponseException::class,
                'api', 'outer' => ApiErrorException::class,
                default => PayloadMismatchException::class,
            }, $error::class);
            if (in_array($failure, ['transport', 'http', 'json'], true)) {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            } else {
                self::assertNotNull($error->metadata);
                self::assertNotSame($previous, $account->responseMeta());
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(match ($failure) {
                    'api' => 0, 'status' => null, default => 1,
                }, $error->metadata->uapiStatus());
                self::assertSame(
                    $failure === 'outer' ? 0 : ($route === 'bridge' ? 1 : null),
                    $error->metadata->whmResult(),
                );
            }
            if ($failure === 'outer') {
                self::assertStringContainsString('WHM', $error->getMessage());
                self::assertSame(['PRIVATE outer'], $error->metadata?->errors());
            }
            if ($error instanceof PayloadMismatchException) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertSame(['PRIVATE successful mismatch' => null], $error->payload);
                self::assertSame(['PRIVATE warning'], $error->metadata?->uapiWarnings());
                self::assertSame(
                    ['transformed' => 1, 'private' => 'PRIVATE metadata'],
                    $error->metadata?->uapi['metadata'] ?? null,
                );
            }
            if ($error instanceof TransportException) {
                self::assertNull($error->getPrevious());
            }
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            $frames = 0;
            $groupFrames = 0;
            foreach ($error->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (
                    str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                    && !str_starts_with($class, __NAMESPACE__ . '\\')
                ) {
                    $frames++;
                    $seen = [];
                    self::assertFalse(
                        self::hasPrivateValue($frame['args'] ?? [], $seen),
                        $class . '::' . $frame['function'],
                    );
                    if ($class === Branding::class) {
                        $groupFrames++;
                        self::assertInstanceOf(SensitiveParameterValue::class, ($frame['args'] ?? [])[0]);
                    }
                }
            }
            self::assertGreaterThan(0, $frames);
            self::assertSame(1, $groupFrames);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertTrue($clearedAtIo);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (['includeFile', 'getInformationForApplications'] as $method) {
                foreach (
                    ['transport', 'http', 'json', 'api', 'status', 'payload',
                    ...($route === 'bridge' ? ['outer'] : [])] as $failure
                ) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    private static function invoke(Branding $group, string $method): mixed
    {
        return match ($method) {
            'getApplicationInformation' => $group->getApplicationInformation('f'),
            'getApplications' => $group->getApplications(),
            'getAvailableApplications' => $group->getAvailableApplications(),
            'getInformationForApplications' => $group->getInformationForApplications('PRIVATE docroot', 'f'),
            default => $group->includeFile('PRIVATE file', 'PRIVATE data', false, true),
        };
    }

    /** @param array<int, true> $seen */
    private static function hasPrivateValue(mixed $value, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, 'PRIVATE');
        }
        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (str_contains((string) $key, 'PRIVATE') || self::hasPrivateValue($item, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function envelope(string $route, ?string $payload, int $status = 1, bool $warning = false): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["PRIVATE error"]')
            . ',"messages":null,"warnings":' . ($warning ? '["PRIVATE warning"]' : 'null')
            . ',"metadata":' . ($warning ? '{"transformed":1,"private":"PRIVATE metadata"}' : '{}') . '}';
        return $route === 'bridge' ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}'
            : '{"result":' . $result . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'fixture', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
