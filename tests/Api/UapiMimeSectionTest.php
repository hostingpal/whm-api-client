<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Mime;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Literal oracles copied from the pinned Mime contracts, independently of generated specs.
 *
 * @phpstan-type Call Closure(Mime): mixed
 * @phpstan-type Case array{string, string, Call, string, string}
 */
final class UapiMimeSectionTest extends TestCase
{
    /** @param Call $call */
    #[DataProvider('requests')]
    public function testExactWireAndCompletePinnedFieldExamples(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $call($account->mime()));
        self::assertRequest($http, $route, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $account->responseMeta()->whmResult());
    }

    /** @return iterable<string, array{string, string, string, Call, string, string}> */
    public static function requests(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::cases() as $name => $case) {
                yield $route . ' ' . $name => [$route, ...$case];
            }
        }
    }

    /** @return iterable<string, Case> */
    public static function cases(): iterable
    {
        yield 'add handler' => ['add_handler', 'POST', static fn (Mime $m): mixed => $m->addHandler('.foo', 'txt-foo'),
            'extension=.foo&handler=txt-foo', 'null'];
        yield 'add mime' => ['add_mime', 'POST', static fn (Mime $m): mixed => $m->addMime('.foo', 'text/foo'),
            'extension=.foo&type=text%2Ffoo', 'null'];
        yield 'hotlink defaults omitted' => ['add_hotlink', 'POST', static fn (Mime $m): mixed => $m->addHotlink(
            extensions: 'foo,bar',
            redirectUrl: 'http://npr.rs/',
            urls: "http://npr.rs/\nhttp://npr.rs/other",
        ), 'extensions=foo%2Cbar&redirect_url=http%3A%2F%2Fnpr.rs%2F'
            . '&urls=http%3A%2F%2Fnpr.rs%2F%0Ahttp%3A%2F%2Fnpr.rs%2Fother',
            'null'];
        yield 'hotlink zero' => ['add_hotlink', 'POST', static fn (Mime $m): mixed => $m->addHotlink(
            extensions: '',
            redirectUrl: '',
            urls: '',
            allowNull: false,
        ), 'extensions=&redirect_url=&urls=&allow_null=0', '{"opaque":[0,null]}'];
        yield 'redirect defaults omitted' => ['add_redirect', 'POST', static fn (Mime $m): mixed => $m->addRedirect(
            domain: 'npr.rs',
            redirect: 'http://npr.rs/target?a=1&b=2',
        ), 'domain=npr.rs&redirect=http%3A%2F%2Fnpr.rs%2Ftarget%3Fa%3D1%26b%3D2', 'null'];
        yield 'redirect explicit zero and empty' => ['add_redirect', 'POST',
            static fn (Mime $m): mixed => $m->addRedirect(
                domain: 'npr.rs',
                redirect: 'http://npr.rs/',
                redirectWildcard: false,
                redirectWww: 0,
                src: '',
                type: 'temp',
            ), 'domain=npr.rs&redirect=http%3A%2F%2Fnpr.rs%2F&redirect_wildcard=0&redirect_www=0&src=&type=temp', '{}'];
        yield 'delete handler' => ['delete_handler', 'POST', static fn (Mime $m): mixed => $m->deleteHandler('.foo'),
            'extension=.foo', 'null'];
        yield 'delete mime by type' => ['delete_mime', 'POST', static fn (Mime $m): mixed => $m->deleteMime('text/foo'),
            'type=text%2Ffoo', 'null'];
        yield 'delete hotlink unspecified data' => ['delete_hotlink', 'POST',
            static fn (Mime $m): mixed => $m->deleteHotlink(),
            '', '{"opaque":[false,0,null]}'];
        yield 'delete redirect defaults omitted' => ['delete_redirect', 'POST',
            static fn (Mime $m): mixed => $m->deleteRedirect('npr.rs'),
            'domain=npr.rs', 'null'];
        yield 'delete redirect explicit empty' => ['delete_redirect', 'POST',
            static fn (Mime $m): mixed => $m->deleteRedirect(
                domain: 'npr.rs',
                args: '',
                docroot: '/home/npr/public_html',
                src: '',
            ), 'domain=npr.rs&args=&docroot=%2Fhome%2Fnpr%2Fpublic_html&src=', '{}'];
        yield 'get redirect example' => ['get_redirect', 'GET',
            static fn (Mime $m): array => $m->getRedirect('example.com'),
            'domain=example.com', '{"redirection_enabled":1,"url":"http://www.domain.com"}'];
        yield 'get redirect zero' => ['get_redirect', 'GET', static fn (Mime $m): array => $m->getRedirect('npr.rs'),
            'domain=npr.rs', '{"redirection_enabled":0,"url":""}'];
        yield 'list handlers example' => ['list_handlers', 'GET',
            static fn (Mime $m): array => $m->listHandlers('user'),
            'type=user', '[{"extension":".foo","handler":"text-foo","origin":"user"}]'];
        yield 'list hotlinks example' => ['list_hotlinks', 'GET', static fn (Mime $m): array => $m->listHotlinks(), '',
            '{"allow_null":1,"extensions":"jpg,jpeg,gif,png,bmp","redirect_url":"http://redirect.example.com/",'
            . '"state":"disabled","urls":["http://example.com/"]}'];
        yield 'list hotlinks empty lists and zero' => ['list_hotlinks', 'GET',
            static fn (Mime $m): array => $m->listHotlinks(), '',
            '{"allow_null":0,"extensions":"","redirect_url":"","state":"disabled","urls":[]}'];
        yield 'list mime example' => ['list_mime', 'GET', static fn (Mime $m): array => $m->listMime('user'),
            'type=user', '[{"extension":".foo","origin":"user","type":"application/foo"}]'];
        yield 'list redirects complete example' => ['list_redirects', 'GET',
            static fn (Mime $m): array => $m->listRedirects(), '',
            '[{"destination":"http://redirect.example.com/","displaydomain":"ALL","displaysourceurl":"/marceau.html",'
            . '"docroot":"/home/example/public_html","domain":"example.com","kind":"rewrite","matchwww":1,'
            . '"matchwww_text":"checked","opts":"L","source":"/marceau.html","sourceurl":"/marceau.html",'
            . '"statuscode":"301","targeturl":"http://redirect.example.com/","type":"permanent",'
            . '"urldomain":"example.com","wildcard":1,"wildcard_text":"checked"}]'];
        yield 'list redirect filters' => ['list_redirects', 'GET', static fn (Mime $m): array => $m->listRedirects(
            destination: '',
            regex: '^/a b$',
        ), 'destination=&regex=%5E%2Fa%20b%24', '[{"matchwww":0,"wildcard":0}]'];
        yield 'redirect info example' => ['redirect_info', 'GET', static fn (Mime $m): array => $m->redirectInfo(
            domain: 'example.com',
            url: 'http://redirect.example.com',
        ), 'domain=example.com&url=http%3A%2F%2Fredirect.example.com',
            '{"domain":"example.com","url":"http://redirect.example.com"}'];
        yield 'all public domains output' => ['redirect_info', 'GET', static fn (Mime $m): array => $m->redirectInfo(
            domain: 'npr.rs',
            url: '',
        ), 'domain=npr.rs&url=', '{"domain":"** All Public Domains **","url":""}'];
    }

    /** @param Call $call */
    #[DataProvider('payloadVariants')]
    public function testPresenceAndShapeBoundaries(string $route, Closure $call, ?string $payload, bool $valid): void
    {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $account = $client->uapi('exampleuser');
        try {
            $result = $call($account->mime());
            self::assertTrue($valid, 'Malformed payload must fail.');
            self::assertSame(json_decode($payload ?? 'null', true, 512, JSON_THROW_ON_ERROR), $result);
        } catch (PayloadMismatchException $error) {
            self::assertFalse($valid, 'Valid payload must pass.');
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame(json_decode($payload ?? 'null', true, 512, JSON_THROW_ON_ERROR), $error->payload);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, Call, ?string, bool}> */
    public static function payloadVariants(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::cases() as $name => [$function, , $call]) {
                $list = in_array($function, ['list_handlers', 'list_mime', 'list_redirects'], true);
                $mutation = str_starts_with($function, 'add_') || str_starts_with($function, 'delete_');
                // Empty schemas remain unconstrained; opaque nullable objects do not become void.
                $variants = [[null, false]];
                if ($function === 'delete_hotlink') {
                    $variants = [...$variants, ['null', true], ['0', true], ['false', true], ['[]', true]];
                } elseif ($mutation) {
                    $variants = [...$variants, ['null', true], ['{}', true], ['[]', true],
                        ['{"unknown":{"value":null}}', true], ['0', false], ['false', false], ['[{}]', false]];
                } elseif ($list) {
                    $variants = [...$variants, ['[]', true], ['[{}]', true], ['null', false], ['{}', false],
                        ['[null]', false], ['[0]', false], ['[{"extension":null}]', $function === 'list_redirects']];
                } else {
                    $variants = [...$variants, ['{}', true], ['null', false], ['false', false], ['[{}]', false]];
                }
                foreach ($variants as $index => [$payload, $valid]) {
                    // Unknown typed fields are dropped; avoid comparing a dropped value as retained data.
                    if ($function === 'list_redirects' && $payload === '[{"extension":null}]') {
                        continue;
                    }
                    yield $route . ' ' . $name . ' ' . $index => [$route, $call, $payload, $valid];
                }
            }
            // Every pinned field is independently named here: a known field with the wrong
            // structural type must fail even when its siblings are absent.
            $fields = [
                'get_redirect' => ['redirection_enabled', 'url'],
                'list_handlers' => ['extension', 'handler', 'origin'],
                'list_hotlinks' => ['allow_null', 'extensions', 'redirect_url', 'state', 'urls'],
                'list_mime' => ['extension', 'origin', 'type'],
                'list_redirects' => ['destination', 'displaydomain', 'displaysourceurl', 'docroot', 'domain',
                    'kind', 'matchwww', 'matchwww_text', 'opts', 'source', 'sourceurl', 'statuscode',
                    'targeturl', 'type', 'urldomain', 'wildcard', 'wildcard_text'],
                'redirect_info' => ['domain', 'url'],
            ];
            foreach ($fields as $function => $names) {
                foreach (self::cases() as [$candidate, , $call]) {
                    if ($candidate !== $function) {
                        continue;
                    }
                    foreach ($names as $field) {
                        $invalid = ['null', 'false', '2', '[]'];
                        if (in_array($field, ['redirection_enabled', 'allow_null', 'matchwww', 'wildcard'], true)) {
                            $invalid[] = '"0"';
                        }
                        foreach ($invalid as $value) {
                            $payload = '{"' . $field . '":' . $value . '}';
                            if (in_array($function, ['list_handlers', 'list_mime', 'list_redirects'], true)) {
                                $payload = '[' . $payload . ']';
                            }
                            if ($field === 'urls' && $value === '[]') {
                                continue;
                            }
                            yield $route . ' invalid ' . $function . ' ' . $field . ' ' . $value
                                => [$route, $call, $payload, false];
                        }
                    }
                    break;
                }
            }
        }
    }

    /** @param Call $call */
    #[DataProvider('requests')]
    public function testApiFailurePrecedesPayloadValidationAndDoesNotRetry(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $call($account->mime());
        $previous = $account->responseMeta();
        $http->addResponse(new Response(200, [], self::envelope($route, null, 0)));
        try {
            $call($account->mime());
            self::fail('UAPI failure must be checked.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
        }
        self::assertRequest($http, $route, $function, $method, $parameters);
        self::assertCount(2, $http->getRequests());
    }

    /** @param Call $call */
    #[DataProvider('requests')]
    public function testOuterWhmFailurePrecedesInnerSuccess(
        string $route,
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        string $payload,
    ): void {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic denied"},'
            . '"data":{"uapi":{"status":1,"data":' . $payload . '}}}'));
        try {
            $call($account->mime());
            self::fail('Outer WHM failure must be checked.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
            self::assertSame(1, $error->metadata->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['Synthetic denied'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testSelectorCachesWithoutIoAndBindsDistinctAccounts(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('exampleuser');
        self::assertSame($account->mime(), $account->mime());
        self::assertNotSame($account->mime(), $client->uapi('other')->mime());
        self::assertNull($account->responseMeta());
        self::assertSame([], $http->getRequests());
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
    ): void {
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=exampleuser'
                . '&cpanel.module=Mime&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Mime/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel exampleuser:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ($payload === null ? '' : ',"data":' . $payload)
            . ',"errors":' . ($status === 1 ? 'null' : '["Synthetic Mime failure"]') . '}';

        return match ($route) {
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            'bare' => $result,
            default => '{"apiversion":3,"module":"Mime","result":' . $result . '}',
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'exampleuser',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
