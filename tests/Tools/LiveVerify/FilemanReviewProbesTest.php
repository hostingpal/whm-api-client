<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanReviewProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Context from \HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner */
final class FilemanReviewProbesTest extends TestCase
{
    public function testSevenExactMetadataGetsObserveExistingZeroByteFileWithoutPersistingNames(): void
    {
        $http = self::responses();
        $probes = FilemanReviewProbes::load('mail/zero-byte.fixture');
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            $probes,
            'fileman-zero-byte-review-v1',
            '1',
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
        );
        self::assertSame('passed', $report['status']);
        self::assertCount(7, $report['probes']);
        self::assertCount(7, $http->getRequests());
        $expected = [
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            'https://test.oblak.host:2083/execute/Fileman/autocompletedir'
                . '?path=public&dirsonly=0&html=0&list_all=0',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Fileman&cpanel.function=autocompletedir'
                . '&path=public&dirsonly=0&html=0&list_all=0',
            'https://test.oblak.host:2083/execute/Fileman/get_file_information'
                . '?path=mail%2Fzero-byte.fixture&check_for_leaf_directories=1'
                . '&include_mime=1&include_permissions=1&show_hidden=0',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Fileman&cpanel.function=get_file_information'
                . '&path=mail%2Fzero-byte.fixture&check_for_leaf_directories=1'
                . '&include_mime=1&include_permissions=1&show_hidden=0',
            'https://test.oblak.host:2083/execute/Fileman/list_files'
                . '?dir=mail&check_for_leaf_directories=1&include_mime=1'
                . '&include_permissions=1&show_hidden=0&types=dir%7Cfile',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Fileman&cpanel.function=list_files'
                . '&dir=mail&check_for_leaf_directories=1&include_mime=1'
                . '&include_permissions=1&show_hidden=0&types=dir%7Cfile',
        ];
        foreach ($http->getRequests() as $index => $request) {
            self::assertSame($expected[$index], (string) $request->getUri());
            self::assertSame('GET', $request->getMethod());
            self::assertSame('', (string) $request->getBody());
            self::assertSame(
                $request->getUri()->getPort() === 2083 ? 'cpanel npr:private-cpanel' : 'whm root:private-whm',
                $request->getHeaderLine('Authorization'),
            );
        }
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['zero-byte.fixture', '/home/npr', 'mail/', 'private-whm', 'private-cpanel'] as $value) {
            self::assertStringNotContainsString($value, $json);
        }
        self::assertSame(['empty-string'], $report['probes'][3]['payload_paths']['$.size']);
        self::assertSame(['int'], $report['probes'][4]['payload_paths']['$.size']);
        self::assertSame(['empty-string'], $report['probes'][5]['payload_paths']['$[].size']);
    }

    /** @return iterable<string,array{string}> */
    public static function unsafePaths(): iterable
    {
        foreach (
            ['', 'mail', 'mail/', 'other/file', '/home/npr/mail/file', 'mail/../file', 'mail/.', 'mail/..',
                'mail/.hidden-file', 'mail/nested/file', 'mail//file', 'mail/%2e%2e',
                'mail/a?b', 'mail/a&b', 'mail/a#b',
                'mail/a b', "mail/a\nb", "mail/a\0b", 'mail/a\\b', 'mail/' . str_repeat('a', 256)] as $path
        ) {
            yield bin2hex($path) => [$path];
        }
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafeTargetCannotProduceAnExecutableCatalog(string $path): void
    {
        $this->expectException(LogicException::class);
        FilemanReviewProbes::load($path);
    }

    /** @return iterable<string,array{int,string}> */
    public static function failedExpectations(): iterable
    {
        yield 'missing information size' => [3, '{"file":"zero-byte.fixture","exists":1,"type":"file"}'];
        yield 'nonzero information size' => [3, self::row('1')];
        yield 'different file' => [3, str_replace('zero-byte.fixture', 'other.fixture', self::row('0'))];
        yield 'missing file' => [3, str_replace('"exists":1', '"exists":0', self::row('0'))];
        yield 'directory' => [3, str_replace('"type":"file"', '"type":"dir"', self::row('0'))];
        yield 'list misses target' => [5, '[]'];
        yield 'nonzero listing size' => [5, '[' . self::row('1') . ']'];
        yield 'duplicate target' => [5, '[' . self::row('0') . ',' . self::row('0') . ']'];
        yield 'wrong listed target' => [
            5, '[' . str_replace('zero-byte.fixture', 'other.fixture', self::row('0')) . ']',
        ];
        yield 'outside fullpath' => [3, str_replace('/home/npr/mail/', '/home/other/mail/', self::row('0'))];
        yield 'listing outside fullpath' => [
            5, '[' . str_replace('/home/npr/mail/', '/home/other/mail/', self::row('0')) . ']',
        ];
    }

    #[DataProvider('failedExpectations')]
    public function testZeroFileExpectationsFailStrictlyWithoutRetryAndKeepSameResponseStructure(
        int $index,
        string $payload,
    ): void {
        $http = self::responses($index, $payload);
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            FilemanReviewProbes::load('mail/zero-byte.fixture'),
            'fileman-zero-byte-review-v1',
            '1',
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
        );
        self::assertSame('failed', $report['status']);
        self::assertCount(7, $http->getRequests());
        self::assertSame('probe_failure', $report['probes'][$index]['failure_kind']);
        self::assertSame('unavailable', $report['probes'][$index]['api_outcome']);
        self::assertSame('not_checked', $report['probes'][$index]['payload_validation']);
        self::assertSame(200, $report['probes'][$index]['http_status']);
        self::assertArrayHasKey('$', $report['probes'][$index]['payload_paths']);
        self::assertStringNotContainsString('zero-byte.fixture', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testGuardRejectsPathAccountMethodAndBodyChangesBeforeIo(): void
    {
        $probes = FilemanReviewProbes::load('mail/zero-byte.fixture');
        foreach ([$probes[3], $probes[4]] as $probe) {
            $http = new Client();
            $guard = new RequestGuard($http, $probe->uri);
            foreach (
                [new Request('POST', $probe->uri), new Request('GET', $probe->uri, [], 'body'),
                    new Request('GET', $probe->uri . '&cpanel.user=other'),
                    new Request(
                        'GET',
                        str_replace('mail%2Fzero-byte.fixture', 'other%2Ffile', $probe->uri),
                    )] as $request
            ) {
                try {
                    $guard->sendRequest($request);
                    self::fail('Changed read escaped the exact URI guard.');
                } catch (LogicException) {
                    self::assertSame([], $http->getRequests());
                }
            }
        }
    }

    public function testPinnedListingObjectStillRequiresAndAcceptsExactlyOneZeroFile(): void
    {
        $http = self::responses(5, '{"dirs":[],"files":[' . self::row('0') . ']}');
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            FilemanReviewProbes::load('mail/zero-byte.fixture'),
            'fileman-zero-byte-review-v1',
            '1',
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
        );
        self::assertSame('passed', $report['status']);
        self::assertCount(7, $http->getRequests());
        self::assertSame(['int'], $report['probes'][5]['payload_paths']['$.files[].size']);
    }

    public function testUnknownFieldsRemainStrictFailuresAndDynamicNamesStayMasked(): void
    {
        $payload = substr(self::row('0'), 0, -1) . ',"private_dynamic_name":"private_content"}';
        $http = self::responses(3, $payload);
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            FilemanReviewProbes::load('mail/zero-byte.fixture'),
            'fileman-zero-byte-review-v1',
            '1',
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
        );
        self::assertSame('failed', $report['status']);
        self::assertSame('passed', $report['probes'][3]['probe_outcome']);
        self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $report['probes'][3]['discrepancies']);
        self::assertCount(7, $http->getRequests());
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private_dynamic_name', $json);
        self::assertStringNotContainsString('private_content', $json);
        self::assertStringNotContainsString('zero-byte.fixture', $json);
    }

    private static function responses(?int $override = null, string $payload = ''): Client
    {
        $http = new Client();
        $data = ['{"version":"11.138.0.19"}', '[]', '[]', self::row('""'), self::row('0'),
            '[' . self::row('""') . ']', '[' . self::row('0') . ']'];
        if ($override !== null) {
            $data[$override] = $payload;
        }
        foreach ($data as $index => $row) {
            if ($index === 0) {
                $raw = '{"metadata":{"result":1},"data":' . $row . '}';
            } else {
                $result = '{"status":1,"errors":null,"data":' . $row . '}';
                $raw = in_array($index, [2, 4, 6], true)
                    ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : $result;
            }
            $http->addResponse(new Response(200, [], $raw));
        }

        return $http;
    }

    private static function row(string $size): string
    {
        return '{"file":"zero-byte.fixture","fullpath":"/home/npr/mail/zero-byte.fixture",'
            . '"absdir":"/home/npr/mail","exists":1,"type":"file","size":' . $size . '}';
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => str_repeat('a', 40), 'source_hashes' => ['whm1' => str_repeat('b', 64),
            'uapi' => str_repeat('c', 64)], 'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40), 'overlay_sha256' => str_repeat('f', 64)];
    }
}
