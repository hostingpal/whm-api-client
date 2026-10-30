<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Fileman;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionParameter;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * Independent literals reviewed against pinned Fileman parameter prose and response fields.
 *
 * @phpstan-type FileCall Closure(Fileman): mixed
 * @phpstan-type Operation array{string, string, FileCall, string, string, mixed}
 */
final class UapiFilemanTest extends TestCase
{
    /** @param FileCall $call */
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
        self::assertSame($expected, $call($account->fileman()));
        self::assertRequest($http, $route, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        $metadata = $account->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->uapiStatus());
        self::assertSame($route === 'bridge' ? 1 : null, $metadata->whmResult());
        self::assertSame(['Fixture warning'], $metadata->uapiWarnings());
        self::assertArrayNotHasKey('data', $metadata->uapi ?? []);
    }

    /** @return iterable<string, array{string, string, string, FileCall, string, string, mixed}> */
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
        yield 'autocomplete' => [
            'autocompletedir', 'GET', static fn (Fileman $f): mixed => $f->autocompleteDirectory(
                path: './p +&',
                dirsOnly: false,
                html: false,
                listAll: true,
            ), 'path=.%2Fp%20%2B%26&dirsonly=0&html=0&list_all=1',
            '[{"file":"public_html","extra":true},{"file":""},{}]',
            [['file' => 'public_html'], ['file' => ''], []],
        ];
        $pathOperations = ['copy_file' => 'copyFile', 'move_file' => 'moveFile', 'rename_file' => 'renameFile'];
        foreach ($pathOperations as $wire => $php) {
            yield $wire => [
                $wire, 'POST', static fn (Fileman $f): mixed => $f->$php('a +&/old.txt', './new%20.txt'),
                'source=a%20%2B%26%2Fold.txt&destination=.%2Fnew%2520.txt',
                '{"src":"/home/npr/a +&/old.txt","dest":"/home/npr/new%20.txt","extra":true}',
                ['dest' => '/home/npr/new%20.txt', 'src' => '/home/npr/a +&/old.txt'],
            ];
        }
        yield 'delete_file' => [
            'delete_file', 'POST', static fn (Fileman $f): mixed => $f->deleteFile('./a +&'),
            'path=.%2Fa%20%2B%26', '{"path":"/home/npr/a +&"}', ['path' => '/home/npr/a +&'],
        ];
        yield 'empty_trash' => [
            'empty_trash', 'POST', static fn (Fileman $f): mixed => $f->emptyTrash(olderThan: 0),
            'older_than=0', 'null', null,
        ];
        yield 'get_file_content' => [
            'get_file_content', 'GET', static fn (Fileman $f): mixed => $f->getFileContent(
                dir: '/home/npr/private +&',
                file: 'a%20.txt',
                fromCharset: 'ISO-8859-1',
                toCharset: 'UTF-8',
                updateHtmlDocumentEncoding: false,
            ), 'dir=%2Fhome%2Fnpr%2Fprivate%20%2B%26&file=a%2520.txt&from_charset=ISO-8859-1'
                . '&to_charset=UTF-8&update_html_document_encoding=0',
            '{"content":"hi +&=雪\\n","dir":"/home/npr/private +&","filename":"a%20.txt",'
                . '"from_char":"ISO-8859-1","from_charset":{"opaque":null},"path":"/home/npr/private +&/a%20.txt",'
                . '"to_char":"UTF-8","to_charset":[0,false,null],"extra":true}',
            ['content' => "hi +&=雪\n", 'dir' => '/home/npr/private +&', 'filename' => 'a%20.txt',
                'from_char' => 'ISO-8859-1', 'from_charset' => ['opaque' => null],
                'path' => '/home/npr/private +&/a%20.txt', 'to_char' => 'UTF-8', 'to_charset' => [0, false, null]],
        ];
        yield 'get_file_information' => [
            'get_file_information', 'GET', static fn (Fileman $f): mixed => $f->getFileInformation(
                path: './private/a +&',
                checkForLeafDirectories: true,
                includeMime: false,
                includePermissions: true,
                showHidden: false,
            ), 'path=.%2Fprivate%2Fa%20%2B%26&check_for_leaf_directories=1&include_mime=0'
                . '&include_permissions=1&show_hidden=0',
            json_encode(self::informationRow(), JSON_THROW_ON_ERROR), self::informationRow(),
        ];
        $file = self::informationRow();
        $file['isparent'] = 0;
        ksort($file);
        $directory = $file;
        $directory['type'] = 'dir';
        $directory['isparent'] = 1;
        $listing = ['dirs' => [$directory, []], 'files' => [$file, []]];
        yield 'list_files' => [
            'list_files', 'GET', static fn (Fileman $f): mixed => $f->listFiles(
                dir: './private',
                checkForLeafDirectories: false,
                includeMime: false,
                includePermissions: true,
                limitToList: false,
                mimeTypes: 'text-plain',
                onlyTheseFiles: 'a +&.txt,b.txt',
                rawMimeTypes: 'text/plain',
                showHidden: true,
                types: 'file|dir',
            ), 'dir=.%2Fprivate&check_for_leaf_directories=0&include_mime=0&include_permissions=1&limit_to_list=0'
                . '&mime_types=text-plain&only_these_files=a%20%2B%26.txt%2Cb.txt&raw_mime_types=text%2Fplain'
                . '&show_hidden=1&types=file%7Cdir',
            json_encode($listing, JSON_THROW_ON_ERROR), $listing,
        ];
        foreach (['restore_from_trash' => 'restoreFromTrash', 'trash_file' => 'trashFile'] as $wire => $php) {
            yield $wire => [
                $wire, 'POST', static fn (Fileman $f): mixed => $f->$php(path: 'private/a +&.txt'),
                'path=private%2Fa%20%2B%26.txt', '{"path":"/home/npr/private/a +&.txt","trash_leaf":"a +&.txt"}',
                ['path' => '/home/npr/private/a +&.txt', 'trash_leaf' => 'a +&.txt'],
            ];
        }
        yield 'save_file_content' => [
            'save_file_content', 'POST', static fn (Fileman $f): mixed => $f->saveFileContent(
                file: 'a.txt',
                content: "a +&=%雪\n",
                dir: './private',
                fallback: false,
                fromCharset: 'UTF-8',
                toCharset: 'UTF-8',
            ), 'file=a.txt&content=a%20%2B%26%3D%25%E9%9B%AA%0A&dir=.%2Fprivate&fallback=0'
                . '&from_charset=UTF-8&to_charset=UTF-8',
            '{"from_charset":"UTF-8","path":"/home/npr/private/a.txt","to_charset":"UTF-8"}',
            ['from_charset' => 'UTF-8', 'path' => '/home/npr/private/a.txt', 'to_charset' => 'UTF-8'],
        ];
        yield 'transcode' => [
            'transcode', 'POST', static fn (Fileman $f): mixed => $f->transcode(
                content: 'hi +&=%',
                discardIllegal: false,
                fromCharset: 'UTF-8',
                toCharset: 'US-ASCII',
                transliterate: true,
            ), 'content=hi%20%2B%26%3D%25&discard_illegal=0&from_charset=UTF-8&to_charset=US-ASCII&transliterate=1',
            '{"charset":"US-ASCII","content":"hi +&=%"}', ['charset' => 'US-ASCII', 'content' => 'hi +&=%'],
        ];
    }

    /** @return array<string, int|string> */
    private static function informationRow(): array
    {
        return ['absdir' => '/home/npr', 'ctime' => 1400573272, 'exists' => 1, 'file' => 'a.txt',
            'fullpath' => '/home/npr/private/a.txt', 'gid' => 503, 'humansize' => '0 KB', 'isleaf' => 0,
            'mimename' => 'text-plain', 'mimetype' => 'text/plain', 'mode' => '33152', 'mtime' => 1400573273,
            'nicemode' => 600, 'path' => '/home/npr/private', 'rawmimename' => 'text-plain',
            'rawmimetype' => 'text/plain', 'read' => 1, 'size' => 0, 'type' => 'file', 'uid' => 502, 'write' => 0];
    }

    /** @return iterable<string, Operation> */
    private static function optionalCalls(): iterable
    {
        yield 'autocomplete omits defaults' => [
            'autocompletedir', 'GET', static fn (Fileman $f): mixed => $f->autocompleteDirectory(''),
            'path=', '[]', [],
        ];
        yield 'content omits encoding defaults' => [
            'get_file_content', 'GET', static fn (Fileman $f): mixed => $f->getFileContent('.', 'a.txt'),
            'dir=.&file=a.txt', '{}', [],
        ];
        yield 'information omits flags' => [
            'get_file_information', 'GET', static fn (Fileman $f): mixed => $f->getFileInformation('.'),
            'path=.', '{}', [],
        ];
        yield 'listing omits filters and flags' => [
            'list_files', 'GET', static fn (Fileman $f): mixed => $f->listFiles('.'), 'dir=.',
            '{"dirs":[],"files":[]}', ['dirs' => [], 'files' => []],
        ];
        yield 'listing preserves empty filters' => [
            'list_files', 'GET', static fn (Fileman $f): mixed => $f->listFiles(
                '.',
                mimeTypes: '',
                onlyTheseFiles: '',
                rawMimeTypes: '',
                types: '',
            ), 'dir=.&mime_types=&only_these_files=&raw_mime_types=&types=', '{}', [],
        ];
        yield 'save omitted content creates blank file by server contract' => [
            'save_file_content', 'POST', static fn (Fileman $f): mixed => $f->saveFileContent('a.txt'),
            'file=a.txt', '{}', [],
        ];
        yield 'save explicit empty content and directory' => [
            'save_file_content', 'POST', static fn (Fileman $f): mixed => $f->saveFileContent(
                'a.txt',
                content: '',
                dir: '',
                fallback: true,
                fromCharset: '',
                toCharset: '',
            ), 'file=a.txt&content=&dir=&fallback=1&from_charset=&to_charset=', '{}', [],
        ];
        yield 'transcode empty buffer omits flags and encodings' => [
            'transcode', 'POST', static fn (Fileman $f): mixed => $f->transcode(''),
            'content=', '{"charset":"","content":""}', ['charset' => '', 'content' => ''],
        ];
        yield 'empty trash omitted threshold retains destructive server default' => [
            'empty_trash', 'POST', static fn (Fileman $f): mixed => $f->emptyTrash(), '', 'null', null,
        ];
        yield 'empty trash retains opaque payload fields' => [
            'empty_trash', 'POST', static fn (Fileman $f): mixed => $f->emptyTrash(31), 'older_than=31',
            '{"opaque":{"zero":0,"empty":[],"null":null}}',
            ['opaque' => ['zero' => 0, 'empty' => [], 'null' => null]],
        ];
        yield 'mixed charset fields preserve explicit null' => [
            'get_file_content', 'GET', static fn (Fileman $f): mixed => $f->getFileContent('.', 'a.txt'),
            'dir=.&file=a.txt', '{"from_charset":null,"to_charset":false}',
            ['from_charset' => null, 'to_charset' => false],
        ];
    }

    /** @param FileCall $call */
    #[DataProvider('emptyAndPartialPayloads')]
    public function testOptionalFieldsAndEmptyResults(
        string $route,
        Closure $call,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $call($client->uapi('npr')->fileman()));
    }

    /** @return iterable<string, array{string, FileCall, string, mixed}> */
    public static function emptyAndPartialPayloads(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [$function, , $call]) {
                $cases = $function === 'autocompletedir'
                    ? [['[]', []], ['[{}]', [[]]]]
                    : [['{}', []], ['[]', []]];
                foreach ($cases as $index => [$payload, $expected]) {
                    yield "$route $name empty $index" => [$route, $call, $payload, $expected];
                }
            }
        }
    }

    /** @param FileCall $call */
    #[DataProvider('inventoryRepresentations')]
    #[DataProvider('flatRowRepresentations')]
    public function testInventoryRepresentationCompatibility(
        string $route,
        Closure $call,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        self::assertSame($expected, $call($account->fileman()));
        self::assertSame(1, $account->responseMeta()?->uapiStatus());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, FileCall, string, mixed}> */
    public static function inventoryRepresentations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            $information = static fn (Fileman $f): mixed => $f->getFileInformation('private/a.txt');
            foreach ([0, 33152] as $mode) {
                yield "$route integer mode $mode" => [
                    $route, $information, '{"mode":' . $mode . '}', ['mode' => $mode],
                ];
            }
            foreach (['0600', '0', '755', ''] as $mode) {
                yield "$route preserved nicemode $mode" => [
                    $route, $information, '{"nicemode":"' . $mode . '"}', ['nicemode' => $mode],
                ];
            }
            foreach (['0' => 0, '4096' => 4096] as $wire => $expected) {
                yield "$route normalized size $wire" => [
                    $route, $information, '{"size":"' . $wire . '"}', ['size' => $expected],
                ];
            }
            $row = self::informationRow();
            $row['isparent'] = 0;
            ksort($row);
            $list = static fn (Fileman $f): mixed => $f->listFiles('private');
            yield "$route flat list retains pinned fields" => [
                $route, $list, json_encode([$row, []], JSON_THROW_ON_ERROR), [$row, []],
            ];
            yield "$route flat singleton preserves optional fields" => [
                $route, $list, '[{"file":"a.txt","size":0,"future":true}]', [['file' => 'a.txt', 'size' => 0]],
            ];
        }
    }

    /** @return iterable<string, array{string, FileCall, string, mixed}> */
    public static function flatRowRepresentations(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            $list = static fn (Fileman $f): mixed => $f->listFiles('private');
            foreach ([0, 33152] as $mode) {
                yield "$route round2 flat integer mode $mode" => [
                    $route, $list, '[{"mode":' . $mode . '}]', [['mode' => $mode]],
                ];
            }
            foreach (['0600', '0', '755', ''] as $mode) {
                yield "$route round2 flat preserved nicemode $mode" => [
                    $route, $list, '[{"nicemode":"' . $mode . '"}]', [['nicemode' => $mode]],
                ];
            }
            foreach (['0' => 0, '4096' => 4096] as $wire => $expected) {
                yield "$route round2 flat normalized size $wire" => [
                    $route, $list, '[{"size":"' . $wire . '"}]', [['size' => $expected]],
                ];
            }
        }
    }

    /** @param FileCall $call */
    #[DataProvider('payloadBoundaries')]
    public function testPayloadPresenceAndTypesRetainTheConfirmedOutcome(
        string $route,
        Closure $call,
        ?string $payload,
    ): void {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, null)));
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        try {
            $call($account->fileman());
            self::fail('An absent payload must fail.');
        } catch (PayloadMismatchException $error) {
            $previous = $error->metadata;
        }
        try {
            $call($account->fileman());
            self::fail('The malformed payload must fail.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertNotSame($previous, $error->metadata);
            self::assertSame(1, $error->metadata?->uapiStatus());
            self::assertSame($route === 'bridge' ? 1 : null, $error->metadata->whmResult());
            self::assertSame(
                $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                $error->payload,
            );
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, FileCall, ?string}> */
    public static function payloadBoundaries(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [$function, , $call]) {
                $invalid = [null, 'false', '1', '1.5', '"text"'];
                if ($function !== 'empty_trash') {
                    $invalid[] = 'null';
                }
                if ($function === 'autocompletedir') {
                    array_push($invalid, '{}', '[null]', '[{"file":1}]', '[{"file":null}]');
                } elseif ($function === 'list_files') {
                    $invalid[] = '[null]';
                } else {
                    $invalid[] = '[{}]';
                }
                foreach ($invalid as $index => $payload) {
                    yield "$route $name root $index" => [$route, $call, $payload];
                }
                foreach (self::invalidFields($function) as $index => $payload) {
                    yield "$route $name field $index" => [$route, $call, $payload];
                }
            }
        }
    }

    /** @return iterable<int, string> */
    private static function invalidFields(string $function): iterable
    {
        $strings = match ($function) {
            'copy_file', 'move_file', 'rename_file' => ['src', 'dest'],
            'delete_file' => ['path'],
            'trash_file', 'restore_from_trash' => ['path', 'trash_leaf'],
            'save_file_content' => ['from_charset', 'path', 'to_charset'],
            'transcode' => ['charset', 'content'],
            'get_file_content' => ['content', 'dir', 'filename', 'from_char', 'path', 'to_char'],
            default => [],
        };
        foreach ($strings as $field) {
            foreach (['null', '1', 'true', '[]'] as $bad) {
                yield '{"' . $field . '":' . $bad . '}';
            }
        }
        if ($function !== 'get_file_information' && $function !== 'list_files') {
            return;
        }
        $contexts = $function === 'list_files' ? ['dirs', 'files', 'flat'] : [''];
        foreach ($contexts as $context) {
            foreach (self::informationRow() as $field => $value) {
                $badValues = is_int($value) ? ['null', '"0"', 'true', '[]'] : ['null', '0', 'true', '[]'];
                if (
                    ($function === 'get_file_information' || $context === 'flat')
                    && in_array($field, ['mode', 'nicemode'], true)
                ) {
                    $badValues = ['null', 'true', '[]', '1.5'];
                }
                if (($function === 'get_file_information' || $context === 'flat') && $field === 'size') {
                    $badValues = ['null', 'true', '[]', '"00"', '"1.0"', '"+1"', '" 1"',
                        '"9223372036854775808"'];
                }
                if (in_array($field, ['exists', 'isleaf', 'read', 'write'], true)) {
                    array_push($badValues, '2', '-1', '1.0');
                }
                foreach ($badValues as $bad) {
                    $row = '{"' . $field . '":' . $bad . '}';
                    yield match ($context) {
                        '' => $row,
                        'flat' => '[' . $row . ']',
                        default => '{"' . $context . '":[' . $row . ']}',
                    };
                }
            }
            if ($context !== '' && $context !== 'flat') {
                foreach (['null', '{}', '[null]'] as $bad) {
                    yield '{"' . $context . '":' . $bad . '}';
                }
            }
            if ($context !== '') {
                foreach (['null', '"0"', 'true', '2'] as $bad) {
                    $row = '{"isparent":' . $bad . '}';
                    yield $context === 'flat' ? '[' . $row . ']' : '{"' . $context . '":[' . $row . ']}';
                }
            }
        }
    }

    /** @param FileCall $call */
    #[DataProvider('operationRoutes')]
    public function testApiFailuresPrecedePayloadHandlingAndReplaceMetadata(string $route, Closure $call): void
    {
        [$client, $http] = self::client($route);
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));
        try {
            $call($account->fileman());
            self::fail('Status zero must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->uapiStatus());
            self::assertSame($account->responseMeta(), $error->metadata);
            self::assertSame(['Synthetic failure'], $error->errors());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, FileCall}> */
    public static function operationRoutes(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (self::operations() as $name => [, , $call]) {
                yield "$route $name" => [$route, $call];
            }
        }
    }

    public function testOuterWhmFailurePrecedesSuccessfulFilePayload(): void
    {
        [$client, $http] = self::client('bridge');
        $account = $client->uapi('npr');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Synthetic failure"},'
            . '"data":{"uapi":{"status":1,"data":{"path":"/home/npr/a.txt"}}}}'));
        try {
            $account->fileman()->deleteFile('a.txt');
            self::fail('Outer WHM failure must fail.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testSelectorCachesWithoutIoOrMetadataResetAndBindsAccounts(): void
    {
        [$client, $http] = self::client('bridge');
        $first = $client->uapi('npr');
        $second = $client->uapi('other');
        self::assertSame($first->fileman(), $first->fileman());
        self::assertNotSame($first->fileman(), $second->fileman());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{}')));
        $first->fileman()->getFileInformation('a.txt');
        $metadata = $first->responseMeta();
        $first->fileman();
        self::assertSame($metadata, $first->responseMeta());
        self::assertNull($second->responseMeta());
        $http->addResponse(new Response(200, [], self::envelope('bridge', '{}')));
        $second->fileman()->saveFileContent('other.txt');
        self::assertRequest($http, 'bridge', 'save_file_content', 'POST', 'file=other.txt', 'other');
        self::assertSame($metadata, $first->responseMeta());
        self::assertNotSame($metadata, $second->responseMeta());
    }

    public function testDirectCallsDefaultToAuthenticatedAccount(): void
    {
        [$client, $http] = self::client('direct');
        $http->addResponse(new Response(200, [], self::envelope('direct', '{}')));
        self::assertSame([], $client->uapi()->fileman()->getFileInformation('a.txt'));
        self::assertRequest($http, 'direct', 'get_file_information', 'GET', 'path=a.txt');
    }

    public function testWhmDoesNotDefaultToRootAsCpanelTarget(): void
    {
        [$client, $http] = self::client('bridge');
        try {
            $client->uapi()->fileman()->getFileInformation('a.txt');
            self::fail('The WHM username is not a cPanel target.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $http->getRequests());
        }
    }

    #[DataProvider('contentFailures')]
    public function testContentArgumentsAndResponsesAreSensitiveInFailureTraces(
        string $route,
        string $method,
        string $failure,
    ): void {
        $secret = 'private-content-marker';
        [$client, $http] = self::client($route);
        $files = $client->uapi('npr')->fileman();
        $parameter = new ReflectionParameter([$files, $method], 'content');
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        $response = match ($failure) {
            'api' => new Response(200, [], self::envelope($route, 'null', 0)),
            'http' => new Response(500, [], $secret),
            'json' => new Response(200, [], $secret),
            default => new Response(200, [], self::envelope(
                $route,
                $method === 'saveFileContent'
                    ? '{"path":false,"from_charset":"' . $secret . '"}'
                    : '{"charset":false,"content":"' . $secret . '"}',
            )),
        };
        $http->addResponse($response);
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            if ($method === 'saveFileContent') {
                $files->saveFileContent('a.txt', content: $secret);
            } else {
                $files->transcode($secret);
            }
            self::fail('The synthetic failure must throw.');
        } catch (ApiException $error) {
            foreach ($error->getTrace() as $frame) {
                if (!str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\')) {
                    continue;
                }
                $seen = [];
                self::assertFalse(self::hasSecret($frame['args'] ?? [], $secret, $seen));
            }
            self::assertStringNotContainsString($secret, $error->getMessage());
        } finally {
            if ($old !== false) {
                ini_set('zend.exception_ignore_args', $old);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function contentFailures(): iterable
    {
        foreach (['direct', 'bare', 'bridge'] as $route) {
            foreach (['saveFileContent', 'transcode'] as $method) {
                foreach (['api', 'http', 'json', 'payload'] as $failure) {
                    yield "$route $method $failure" => [$route, $method, $failure];
                }
            }
        }
    }

    /** @param array<int, true> $seen */
    private static function hasSecret(mixed $value, string $secret, array &$seen): bool
    {
        if ($value instanceof SensitiveParameterValue || $value instanceof MockClient) {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, $secret);
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
                if (str_contains((string) $key, $secret) || self::hasSecret($item, $secret, $seen)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $function,
        string $method,
        string $parameters,
        string $account = 'npr',
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'bridge'
            ? 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . $account
                . '&cpanel.module=Fileman&cpanel.function=' . $function
            : 'https://api.example.test:2083/execute/Fileman/' . $function;
        if ($method === 'GET' && $parameters !== '') {
            $uri .= ($route === 'bridge' ? '&' : '?') . $parameters;
        }
        self::assertSame($method, $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $route === 'bridge' ? 'whm root:fixture-token' : 'cpanel npr:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
        if ($method === 'POST') {
            self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        }
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
