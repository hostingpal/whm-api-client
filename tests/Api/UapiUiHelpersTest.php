<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\Chrome;
use HostingPal\WhmApiClient\Api\Uapi\InProductSurvey;
use HostingPal\WhmApiClient\Api\Uapi\Integration;
use HostingPal\WhmApiClient\Api\Uapi\Parser;
use HostingPal\WhmApiClient\Api\Uapi\Plugins;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
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

/** Synthetic fixtures only: returned markup, scripts, URLs and paths remain inert. */
final class UapiUiHelpersTest extends TestCase
{
    public function testAccountSelectorsAreLazyCachedAndHaveExactNativeSignatures(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('fixture');
        $expected = [
            'chrome' => [Chrome::class, 'getDom', 'pageTitle'],
            'parser' => [Parser::class, 'getFirstFileRelativeUri', null],
            'integration' => [Integration::class, 'fetchUrl', 'app'],
            'inProductSurvey' => [InProductSurvey::class, 'getInProductSurveyUrl', null],
            'plugins' => [Plugins::class, 'canShowPromotions', 'plugin'],
        ];
        foreach ($expected as $accessor => [$class, $name, $parameterName]) {
            self::assertTrue((new ReflectionClass($account))->hasMethod($accessor), "$accessor must exist.");
            self::assertSame($account->$accessor(), $account->$accessor());
            self::assertNotSame($account->$accessor(), $client->uapi('other')->$accessor());
            $method = new ReflectionMethod($class, $name);
            self::assertSame('array', (string) $method->getReturnType());
            self::assertCount($parameterName === null ? 0 : 1, $method->getParameters());
            foreach ($method->getParameters() as $parameter) {
                self::assertSame($parameterName, $parameter->getName());
                self::assertSame('string', (string) $parameter->getType());
                self::assertFalse($parameter->isOptional());
                self::assertCount(0, $parameter->getAttributes(SensitiveParameter::class));
            }
        }
        self::assertFalse((new ReflectionClass(Plugins::class))->hasMethod('createUser'));
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    #[DataProvider('requests')]
    public function testLiteralRequestsKeepRoutingAndEncodeRequiredInputsExactlyOnce(
        string $route,
        string $group,
        string $function,
        string $verb,
        string $input,
        string $fields,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        self::assertSame([], self::invoke($account, $group, $input));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        $url = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=' . $group . '&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/' . $group . '/' . $function;
        self::assertSame(
            $url . ($verb === 'GET' && $fields !== '' ? ($route === 'bridge' ? '&' : '?') . $fields : ''),
            (string) $request->getUri(),
        );
        self::assertSame($verb === 'POST' ? $fields : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel fixture:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        $before = $account->responseMeta();
        $account->chrome();
        $account->parser();
        $account->integration();
        $account->inProductSurvey();
        $account->plugins();
        self::assertSame($before, $account->responseMeta());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function requests(): iterable
    {
        $cases = [
            ['Chrome', 'get_dom', 'GET', 'Title', 'page_title=Title'],
            ['Chrome', 'get_dom', 'GET', '', 'page_title='],
            ['Chrome', 'get_dom', 'GET', " š%2F&+\n ", 'page_title=%20%C5%A1%252F%26%2B%0A%20'],
            ['Parser', 'firstfile_relative_uri', 'GET', '', ''],
            ['Integration', 'fetch_url', 'POST', 'applicationname', 'app=applicationname'],
            ['Integration', 'fetch_url', 'POST', '', 'app='],
            ['Integration', 'fetch_url', 'POST', ' š%2F&+ ', 'app=%20%C5%A1%252F%26%2B%20'],
            ['InProductSurvey', 'get_in_product_survey_url', 'POST', '', ''],
            ['Plugins', 'can_show_promotions', 'POST', 'koality', 'plugin=koality'],
            ['Plugins', 'can_show_promotions', 'POST', '', 'plugin='],
            ['Plugins', 'can_show_promotions', 'POST', ' š%2F&+ ', 'plugin=%20%C5%A1%252F%26%2B%20'],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route $index" => [$route, ...$case];
            }
        }
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('payloads')]
    public function testFullMinimalEmptyPayloadsPreserveKnownFieldsAndInertBytes(
        string $route,
        string $group,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 1, true)));
        $account = $client->uapi('fixture');
        self::assertSame($expected, self::invoke($account, $group));
        self::assertSame(['PRIVATE warning'], $account->responseMeta()?->uapiWarnings());
        self::assertSame(['private' => 'PRIVATE metadata'], $account->responseMeta()?->uapi['metadata'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>}> */
    public static function payloads(): iterable
    {
        $cases = [
            ['Chrome', '{"footer":"\n</html>\n","header":"<script>inert() </script> š &amp;\n",'
                . '"unknown":"discard"}', ['footer' => "\n</html>\n",
                    'header' => "<script>inert() </script> š &amp;\n"]],
            ['Chrome', '{"header":""}', ['header' => '']],
            ['Parser', '{"uri":"/usr/local/cpanel/base/favicon.ico","unknown":1}',
                ['uri' => '/usr/local/cpanel/base/favicon.ico']],
            ['Parser', '{"uri":"../ %2F+š\n"}', ['uri' => "../ %2F+š\n"]],
            ['Integration', '{"redirect_url":"not a URL %2F+š","url":"javascript:inert()","unknown":true}',
                ['redirect_url' => 'not a URL %2F+š', 'url' => 'javascript:inert()']],
            ['Integration', '{"url":""}', ['url' => '']],
            ['InProductSurvey', '{"display":"1","link":"https://survey.example.tld/some/generated/link",'
                . '"max_dismiss":"3","new_user":"0","submit_event":"survey_submitted",'
                . '"server_type":"cpanel","user_type":"cpanel"}',
                ['display' => 1, 'link' => 'https://survey.example.tld/some/generated/link', 'max_dismiss' => '3',
                    'new_user' => 0, 'server_type' => 'cpanel', 'submit_event' => 'survey_submitted',
                    'user_type' => 'cpanel']],
            ['InProductSurvey', '{"display":0,"new_user":1,"max_dismiss":"00","link":"",'
                . '"submit_event":"","server_type":"future","user_type":"future","unknown":1}',
                ['display' => 0, 'link' => '', 'max_dismiss' => '00', 'new_user' => 1,
                    'server_type' => 'future', 'submit_event' => '', 'user_type' => 'future']],
            ['InProductSurvey', '{"new_user":"1","display":"0"}', ['display' => 0, 'new_user' => 1]],
            ['Plugins', '{"can_show_promotions":0,"unknown":"discard"}', ['can_show_promotions' => 0]],
            ['Plugins', '{"can_show_promotions":1}', ['can_show_promotions' => 1]],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($cases as $index => $case) {
                yield "$route $index" => [$route, ...$case];
            }
            foreach (['Chrome', 'Parser', 'Integration', 'InProductSurvey', 'Plugins'] as $group) {
                yield "$route $group empty object" => [$route, $group, '{}', []];
                yield "$route $group empty array compatibility" => [$route, $group, '[]', []];
            }
        }
    }

    #[DataProvider('mismatches')]
    public function testRequiredObjectRootsAndStrictOptionalFieldsRejectWrongRepresentations(
        string $group,
        ?string $payload,
    ): void {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', $payload)));
        try {
            self::invoke($account, $group);
            self::fail('Expected a required nonnull object or strict field mismatch.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function mismatches(): iterable
    {
        $fields = [
            'Chrome' => ['header', 'footer'], 'Parser' => ['uri'],
            'Integration' => ['redirect_url', 'url'],
            'InProductSurvey' => ['display', 'new_user', 'max_dismiss', 'link', 'submit_event',
                'server_type', 'user_type'], 'Plugins' => ['can_show_promotions'],
        ];
        foreach ($fields as $group => $names) {
            foreach ([null, 'null', '"object"', '1', 'true', '[{}]'] as $index => $wrong) {
                yield "$group root $index" => [$group, $wrong];
            }
            foreach ($names as $field) {
                yield "$group $field null" => [$group, '{"' . $field . '":null}'];
                yield "$group $field wrong type" => [$group, '{"' . $field . '":true}'];
            }
        }
        foreach (['display', 'new_user'] as $flag) {
            foreach (['"00"', '" 1"', '"2"', '2', '0.0', '1.0', 'false'] as $wrong) {
                yield "survey $flag $wrong" => ['InProductSurvey', '{"' . $flag . '":' . $wrong . '}'];
            }
        }
        foreach (['"0"', '"1"', '2', '0.0', '1.0', 'false'] as $wrong) {
            yield "plugins strict flag $wrong" => ['Plugins', '{"can_show_promotions":' . $wrong . '}'];
        }
        yield 'max_dismiss integer' => ['InProductSurvey', '{"max_dismiss":3}'];
        yield 'max_dismiss float' => ['InProductSurvey', '{"max_dismiss":3.0}'];
        yield 'uri object' => ['Parser', '{"uri":{}}'];
        yield 'url list' => ['Integration', '{"url":[]}'];
    }

    #[DataProvider('operationGroups')]
    public function testEveryMethodChecksApiOutcomeBeforePayloadValidation(string $group): void
    {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', 'null', 0)));
        try {
            self::invoke($account, $group);
            self::fail('API status zero must fail before the invalid payload.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<array{string}> */
    public static function operationGroups(): iterable
    {
        foreach (['Chrome', 'Parser', 'Integration', 'InProductSurvey', 'Plugins'] as $group) {
            yield [$group];
        }
    }

    public function testFlagSuccessWithErrorsRetainsExistingOutcomeInterpretation(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$client, $http] = self::client($route);
            $body = str_replace(
                '"errors":null',
                '"errors":["PRIVATE contradictory error"]',
                self::envelope($route, '{"can_show_promotions":0}'),
            );
            $http->addResponse(new Response(200, [], $body));
            $account = $client->uapi('fixture');
            self::assertSame(['can_show_promotions' => 0], $account->plugins()->canShowPromotions('koality'));
            self::assertSame(['PRIVATE contradictory error'], $account->responseMeta()?->errors());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testRepeatedInvocationsReturnFreshPayloadsWithoutFollowingEitherUrl(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope(
            'direct',
            '{"redirect_url":"https://inert.example.test/first","url":"../fallback"}',
        )));
        $http->addResponse(new Response(200, [], self::envelope('direct', '{"url":"second"}')));
        $group = $client->uapi()->integration();
        self::assertSame(
            ['redirect_url' => 'https://inert.example.test/first', 'url' => '../fallback'],
            $group->fetchUrl('app'),
        );
        self::assertSame(['url' => 'second'], $group->fetchUrl('app'));
        self::assertCount(2, $http->getRequests());
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('nativeFailures')]
    public function testNativeInputFailuresRetainPriorMetadataBeforeEntry(
        string $selector,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client('direct');
        $account = $client->uapi();
        $http->addResponse(new Response(200, [], self::envelope('direct', '{}')));
        $account->parser()->getFirstFileRelativeUri();
        $before = $account->responseMeta();
        $group = match ($selector) {
            'chrome' => $account->chrome(),
            'integration' => $account->integration(),
            default => $account->plugins(),
        };
        try {
            $group->$method(...$arguments);
            self::fail('Required native strings must reject before operation entry.');
        } catch (TypeError) {
            self::assertSame($before, $account->responseMeta());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<array{string, string, list<mixed>}> */
    public static function nativeFailures(): iterable
    {
        foreach (
            ['chrome' => 'getDom', 'integration' => 'fetchUrl', 'plugins' => 'canShowPromotions'] as $group => $method
        ) {
            foreach ([[], [null], [1], [[]]] as $arguments) {
                yield [$group, $method, $arguments];
            }
        }
    }

    #[DataProvider('failures')]
    public function testFailurePrecedenceResetsMetadataKeepsSnapshotsAndNeverRetries(
        string $route,
        string $failure,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('fixture');
        $http->addResponse(new Response(200, [], self::envelope($route, '{}')));
        $account->parser()->getFirstFileRelativeUri();
        $before = $account->responseMeta();
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
                'api' => new Response(200, [], self::envelope($route, '{"can_show_promotions":1}', 0)),
                'outer' => new Response(200, [], '{"metadata":{"result":0,"reason":"PRIVATE outer"},'
                    . '"data":{"uapi":{"status":1,"data":{"can_show_promotions":1}}}}'),
                'outer-status' => new Response(200, [], '{"metadata":{"result":"1"},'
                    . '"data":{"uapi":{"status":1,"data":null}}}'),
                'status' => new Response(200, [], $route === 'bridge'
                    ? '{"metadata":{"result":1},"data":{"uapi":{"status":"1","data":null}}}'
                    : '{"result":{"status":"1","data":null}}'),
                default => new Response(200, [], self::envelope($route, '{"can_show_promotions":"PRIVATE"}', 1, true)),
            };
        });
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $account->plugins()->canShowPromotions('koality');
            self::fail('The failing invocation must not retry.');
        } catch (ApiException $error) {
            self::assertSame(match ($failure) {
                'transport' => TransportException::class, 'http' => HttpException::class,
                'json', 'status', 'outer-status' => InvalidResponseException::class,
                'api', 'outer' => ApiErrorException::class, default => PayloadMismatchException::class,
            }, $error::class);
            if (in_array($failure, ['transport', 'http', 'json'], true)) {
                self::assertNull($account->responseMeta());
                self::assertNull($error->metadata);
            } else {
                self::assertNotSame($before, $account->responseMeta());
                self::assertSame($account->responseMeta(), $error->metadata);
                self::assertSame(match ($failure) {
                    'api' => 0, 'status' => null, default => 1,
                }, $error->metadata?->uapiStatus());
                self::assertSame(
                    $failure === 'outer' ? 0 : ($route === 'bridge' && $failure !== 'outer-status' ? 1 : null),
                    $error->metadata?->whmResult(),
                );
            }
            if ($error instanceof PayloadMismatchException) {
                self::assertTrue($error->outcomeConfirmed);
                self::assertSame(['can_show_promotions' => 'PRIVATE'], $error->payload);
                self::assertSame(['PRIVATE warning'], $error->metadata?->uapiWarnings());
                self::assertSame(['private' => 'PRIVATE metadata'], $error->metadata?->uapi['metadata'] ?? null);
            }
            if ($error instanceof TransportException) {
                self::assertNull($error->getPrevious());
            }
            self::assertStringNotContainsString('PRIVATE', $error->getMessage());
            $frames = 0;
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
                }
            }
            self::assertGreaterThan(0, $frames);
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertTrue($clearedAtIo);
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach (
                ['transport', 'http', 'json', 'api', 'status', 'payload',
                ...($route === 'bridge' ? ['outer', 'outer-status'] : [])] as $failure
            ) {
                yield "$route $failure" => [$route, $failure];
            }
        }
    }

    /** @return array<string, mixed> */
    private static function invoke(UapiClient $account, string $group, string $input = 'selector'): array
    {
        return match ($group) {
            'Chrome' => $account->chrome()->getDom($input),
            'Parser' => $account->parser()->getFirstFileRelativeUri(),
            'Integration' => $account->integration()->fetchUrl($input),
            'InProductSurvey' => $account->inProductSurvey()->getInProductSurveyUrl(),
            default => $account->plugins()->canShowPromotions($input),
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
            . ',"warnings":' . ($warning ? '["PRIVATE warning"]' : 'null')
            . ',"metadata":' . ($warning ? '{"private":"PRIVATE metadata"}' : '{}') . '}';
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
