<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

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
use ReflectionMethod;
use SensitiveParameter;

/** Independent transport and payload fixtures for the twelve new Hosting Plans methods. */
final class WhmHostingPlanManagementTest extends TestCase
{
    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testExactRequestAndPinnedPayload(
        string $operation,
        string $method,
        array $arguments,
        string $verb,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));

        self::assertSame($expected, $client->whm()->hostingPlans()->{$method}(...$arguments));

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $operation . '?api.version=1'
                . ($verb === 'GET' && $parameters !== '' ? '&' . $parameters : ''),
            (string) $request->getUri(),
        );
        self::assertSame($verb === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        $metadata = $client->whm()->responseMeta();
        self::assertNotNull($metadata);
        self::assertSame(1, $metadata->whmResult());
        self::assertSame($operation, $metadata->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string, ?string, mixed}> */
    public static function operations(): iterable
    {
        yield 'extension HTML is returned as data' => [
            '_getpkgextensionform', 'getPackageExtensionForm', ['fixture & one'], 'GET', 'pkg=fixture%20%26%20one',
            '{"html":"<script>synthetic()</script>[% fixture %]"}',
            ['html' => '<script>synthetic()</script>[% fixture %]'],
        ];
        yield 'add overrides sends caller JSON text once in a form field' => [
            'add_override_features_for_user', 'addOverrideFeaturesForUser',
            ['fixture', '{"backup":"1","sslinstall":0}'], 'POST',
            'user=fixture&features=%7B%22backup%22%3A%221%22%2C%22sslinstall%22%3A0%7D', null, null,
        ];
        yield 'add package preserves every case sensitive field and flag' => [
            'addpkg', 'addPackage',
            ['name' => 'fixture', 'packageExtensions' => 'ExtOne ExtTwo', 'bwlimit' => 0, 'cgi' => false,
                'cpmod' => 'jupiter', 'digestauth' => false, 'featurelist' => 'default', 'frontpage' => false,
                'hasshell' => false, 'ip' => 'n', 'language' => 'en', 'maxDeferFailPercentage' => 'unlimited',
                'maxEmailPerHour' => 0, 'maxEmailacctQuota' => 'unlimited', 'maxTeamUsers' => 0,
                'maxaddon' => 1, 'maxftp' => 2, 'maxlst' => 3, 'maxpark' => 4, 'maxpop' => 5,
                'maxsql' => 6, 'maxsub' => 7, 'quota' => 0], 'POST',
            'name=fixture&_PACKAGE_EXTENSIONS=ExtOne%20ExtTwo&bwlimit=0&cgi=0&cpmod=jupiter&digestauth=0'
                . '&featurelist=default&frontpage=0&hasshell=0&ip=n&language=en&MAX_DEFER_FAIL_PERCENTAGE=unlimited'
                . '&MAX_EMAIL_PER_HOUR=0&max_emailacct_quota=unlimited&max_team_users=0&maxaddon=1&maxftp=2'
                . '&maxlst=3&maxpark=4&maxpop=5&maxsql=6&maxsub=7&quota=0',
            '{"pkg":"fixture"}', ['pkg' => 'fixture'],
        ];
        yield 'add extension package name first with space delimited names' => [
            'addpkgext', 'addPackageExtension', ['fixture', 'ExtOne ExtTwo'], 'POST',
            'name=fixture&_PACKAGE_EXTENSIONS=ExtOne%20ExtTwo', '{"pkg":"fixture"}', ['pkg' => 'fixture'],
        ];
        yield 'delete feature list' => [
            'delete_featurelist', 'deleteFeaturelist', ['fixture'], 'POST', 'featurelist=fixture',
            '{"deleted_featurelist":"fixture"}', ['deleted_featurelist' => 'fixture'],
        ];
        yield 'delete extension' => [
            'delpkgext', 'deletePackageExtension', ['fixture', 'ExtOne ExtTwo'], 'POST',
            'name=fixture&_DELETE_EXTENSIONS=ExtOne%20ExtTwo', '{"pkg":"fixture"}', ['pkg' => 'fixture'],
        ];
        yield 'edit package lower case mail fields and true flags' => [
            'editpkg', 'editPackage',
            ['name' => 'fixture', 'packageExtensions' => '', 'bwlimit' => 'unlimited', 'cgi' => true,
                'cpmod' => 'jupiter', 'digestauth' => true, 'featurelist' => 'fixture-list', 'frontpage' => false,
                'hasshell' => true, 'ip' => 'y', 'language' => 'en', 'maxDeferFailPercentage' => 0,
                'maxEmailPerHour' => 'unlimited', 'maxEmailacctQuota' => 0, 'maxTeamUsers' => 7,
                'maxaddon' => 'unlimited', 'maxftp' => 'unlimited', 'maxlst' => 'unlimited',
                'maxpark' => 'unlimited', 'maxpop' => 'unlimited', 'maxsql' => 'unlimited',
                'maxsub' => 'unlimited', 'quota' => 'unlimited'], 'POST',
            'name=fixture&_PACKAGE_EXTENSIONS=&bwlimit=unlimited&cgi=1&cpmod=jupiter&digestauth=1'
                . '&featurelist=fixture-list&frontpage=0&hasshell=1&ip=y&language=en&max_defer_fail_percentage=0'
                . '&max_email_per_hour=unlimited&max_emailacct_quota=0&max_team_users=7&maxaddon=unlimited'
                . '&maxftp=unlimited&maxlst=unlimited&maxpark=unlimited&maxpop=unlimited&maxsql=unlimited'
                . '&maxsub=unlimited&quota=unlimited', '{"pkg":"fixture"}', ['pkg' => 'fixture'],
        ];
        yield 'application selectors encode once' => [
            'get_available_applications', 'getAvailableApplications',
            ['applicationsList' => 'one,two', 'file' => 'synthetic &.conf'], 'GET',
            'applications_list=one%2Ctwo&file=synthetic%20%26.conf',
            '{"groups":[],"raw":[]}', ['groups' => [], 'raw' => []],
        ];
        yield 'package info keeps every known field and opaque extension variables' => [
            'getpkginfo', 'getPackageInfo', ['fixture'], 'GET', 'pkg=fixture',
            '{"pkg":{"BWLIMIT":null,"CGI":0,"CPMOD":"jupiter","DIGESTAUTH":"n",'
                . '"FEATURELIST":"default","FRONTPAGE":0,"HASSHELL":1,"IP":0,"LANG":"en",'
                . '"MAXADDON":1,"MAXFTP":"unlimited","MAXLST":null,"MAXPARK":0,"MAXPOP":2,'
                . '"MAXSQL":3,"MAXSUB":4,"MAX_DEFER_FAIL_PERCENTAGE":0,"MAX_EMAILACCT_QUOTA":"unlimited",'
                . '"MAX_EMAIL_PER_HOUR":100,"MAX_TEAM_USERS":null,"QUOTA":0,"_PACKAGE_EXTENSIONS":"ExtOne",'
                . '"ExtOne_setting":{"opaque":[null,0,"synthetic"]}}}',
            ['pkg' => ['BWLIMIT' => null, 'CGI' => 0, 'CPMOD' => 'jupiter', 'DIGESTAUTH' => 'n',
                'FEATURELIST' => 'default', 'FRONTPAGE' => 0, 'HASSHELL' => 1, 'IP' => 0, 'LANG' => 'en',
                'MAXADDON' => 1, 'MAXFTP' => 'unlimited', 'MAXLST' => null, 'MAXPARK' => 0, 'MAXPOP' => 2,
                'MAXSQL' => 3, 'MAXSUB' => 4, 'MAX_DEFER_FAIL_PERCENTAGE' => 0,
                'MAX_EMAILACCT_QUOTA' => 'unlimited', 'MAX_EMAIL_PER_HOUR' => 100, 'MAX_TEAM_USERS' => null,
                'QUOTA' => 0, '_PACKAGE_EXTENSIONS' => 'ExtOne',
                'ExtOne_setting' => ['opaque' => [null, 0, 'synthetic']]]],
        ];
        yield 'delete package documents no data' => [
            'killpkg', 'deletePackage', ['fixture'], 'POST', 'pkgname=fixture', null, null,
        ];
        yield 'match every selector with uppercase wire names' => [
            'matchpkgs', 'matchPackages',
            ['packageExtensions' => 'ExtOne ExtTwo', 'bwlimit' => 0, 'cgi' => false, 'cpmod' => 'jupiter',
                'digestauth' => 'n', 'featurelist' => 'default', 'hasshell' => true, 'ip' => 'n', 'lang' => 'en',
                'maxDeferFailPercentage' => 0, 'maxEmailPerHour' => 'unlimited', 'maxEmailacctQuota' => 1,
                'maxTeamUsers' => 0, 'maxaddon' => 0, 'maxftp' => 1, 'maxlst' => 2, 'maxpark' => 3,
                'maxpop' => 4, 'maxsql' => 5, 'maxsub' => 6, 'quota' => 'unlimited'], 'GET',
            '_PACKAGE_EXTENSIONS=ExtOne%20ExtTwo&BWLIMIT=0&CGI=0&CPMOD=jupiter&DIGESTAUTH=n&FEATURELIST=default'
                . '&HASSHELL=1&IP=n&LANG=en&MAX_DEFER_FAIL_PERCENTAGE=0&MAX_EMAIL_PER_HOUR=unlimited'
                . '&MAX_EMAILACCT_QUOTA=1&MAX_TEAM_USERS=0&MAXADDON=0&MAXFTP=1&MAXLST=2&MAXPARK=3'
                . '&MAXPOP=4&MAXSQL=5&MAXSUB=6&QUOTA=unlimited',
            '{"pkg":[{"BWLIMIT":"unlimited","CGI":1,"CPMOD":"jupiter","DIGESTAUTH":"y",'
                . '"FEATURELIST":"default","FRONTPAGE":0,"HASSHELL":"n","IP":"n","LANG":"en",'
                . '"MAXADDON":1,"MAXFTP":"unlimited","MAXLST":2,"MAXPARK":3,"MAXPOP":4,'
                . '"MAXSQL":5,"MAXSUB":6,"MAX_DEFER_FAIL_PERCENTAGE":0,"MAX_EMAILACCT_QUOTA":0,'
                . '"MAX_EMAIL_PER_HOUR":100,"QUOTA":0,"_PACKAGE_EXTENSIONS":"ExtOne ExtTwo",'
                . '"name":"fixture"}]}',
            ['pkg' => [['BWLIMIT' => 'unlimited', 'CGI' => 1, 'CPMOD' => 'jupiter', 'DIGESTAUTH' => 'y',
                'FEATURELIST' => 'default', 'FRONTPAGE' => 0, 'HASSHELL' => 'n', 'IP' => 'n', 'LANG' => 'en',
                'MAXADDON' => 1, 'MAXFTP' => 'unlimited', 'MAXLST' => 2, 'MAXPARK' => 3, 'MAXPOP' => 4,
                'MAXSQL' => 5, 'MAXSUB' => 6, 'MAX_DEFER_FAIL_PERCENTAGE' => 0, 'MAX_EMAILACCT_QUOTA' => 0,
                'MAX_EMAIL_PER_HOUR' => 100, 'QUOTA' => 0, '_PACKAGE_EXTENSIONS' => 'ExtOne ExtTwo',
                'name' => 'fixture']]],
        ];
        yield 'remove overrides sends a JSON list once' => [
            'remove_override_features_for_user', 'removeOverrideFeaturesForUser',
            ['fixture', '["backup","sslinstall"]'], 'POST',
            'user=fixture&features=%5B%22backup%22%2C%22sslinstall%22%5D', null, null,
        ];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('errorOperations')]
    public function testApiFailureIsReportedWithoutRetry(
        string $operation,
        string $method,
        array $arguments,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"command":"' . $operation
            . '","reason":"synthetic rejection","result":0,"version":1}}'));
        try {
            $client->whm()->hostingPlans()->{$method}(...$arguments);
            self::fail('A failed WHM outcome must not become a successful result.');
        } catch (ApiErrorException $exception) {
            self::assertNotNull($exception->metadata);
            self::assertSame(0, $exception->metadata->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function errorOperations(): iterable
    {
        foreach (self::operations() as $name => [$operation, $method, $arguments]) {
            yield $name => [$operation, $method, $arguments];
        }
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string}> */
    public static function malformedPayloads(): iterable
    {
        $operations = [
            ['addpkg', 'addPackage', ['fixture']],
            ['addpkgext', 'addPackageExtension', ['fixture', 'ExtOne']],
            ['delete_featurelist', 'deleteFeaturelist', ['fixture']],
            ['delpkgext', 'deletePackageExtension', ['fixture', 'ExtOne']],
            ['editpkg', 'editPackage', ['fixture']],
            ['get_available_applications', 'getAvailableApplications', []],
            ['getpkginfo', 'getPackageInfo', ['fixture']],
            ['matchpkgs', 'matchPackages', []],
        ];
        foreach ($operations as [$operation, $method, $arguments]) {
            foreach (['null', 'true', '0', '"text"', '[{}]'] as $payload) {
                yield "$method root $payload" => [$operation, $method, $arguments, $payload];
            }
            yield "$method absent data" => [$operation, $method, $arguments, 'ABSENT'];
        }
        foreach (['true', '0', '"text"', '[{}]'] as $payload) {
            yield 'extension form root ' . $payload => [
                '_getpkgextensionform', 'getPackageExtensionForm', ['fixture'], $payload,
            ];
        }
        yield 'HTML stays string' => ['_getpkgextensionform', 'getPackageExtensionForm', ['fixture'], '{"html":0}'];
        foreach (
            ['addpkg' => 'addPackage', 'addpkgext' => 'addPackageExtension',
            'editpkg' => 'editPackage', 'delpkgext' => 'deletePackageExtension'] as $operation => $method
        ) {
            yield "$method package name leaf" => [$operation, $method,
                str_ends_with($operation, 'ext') ? ['fixture', 'ExtOne'] : ['fixture'], '{"pkg":null}'];
        }
        yield 'deleted feature list stays string' => [
            'delete_featurelist', 'deleteFeaturelist', ['fixture'], '{"deleted_featurelist":1}',
        ];
        foreach (['getpkginfo' => 'getPackageInfo', 'matchpkgs' => 'matchPackages'] as $operation => $method) {
            $arguments = $operation === 'getpkginfo' ? ['fixture'] : [];
            $invalidRoots = $operation === 'getpkginfo' ? ['null', '[{}]', 'true'] : ['null', '{}', 'true', '[1]'];
            foreach ($invalidRoots as $payload) {
                yield "$method package root $payload" => [$operation, $method, $arguments, '{"pkg":' . $payload . '}'];
            }
            foreach (self::packageLimitFields() as $field) {
                foreach (
                    ['false', 'true', '1.0', '1.5', '[]', '{}', '"not-unlimited"', '"Unlimited"', '"unlimited "',
                    '""', '"00"', '"-0"', '"+1"', '"-01"', '" 1"', '"1 "', '"1.0"', '"1e3"',
                    '"' . PHP_INT_MAX . '0"', '"' . PHP_INT_MIN . '0"', (string) PHP_INT_MAX . '0'] as $payload
                ) {
                    $row = '{"' . $field . '":' . $payload . '}';
                    yield "$method $field $payload" => [$operation, $method, $arguments,
                        '{"pkg":' . ($operation === 'matchpkgs' ? '[' . $row . ']' : $row) . '}'];
                }
                if (
                    $operation === 'matchpkgs' || in_array($field, [
                        'MAX_DEFER_FAIL_PERCENTAGE', 'MAX_EMAILACCT_QUOTA', 'MAX_EMAIL_PER_HOUR',
                    ], true)
                ) {
                    $row = '{"' . $field . '":null}';
                    yield "$method $field remains nonnullable" => [$operation, $method, $arguments,
                        '{"pkg":' . ($operation === 'matchpkgs' ? '[' . $row . ']' : $row) . '}'];
                }
            }
            foreach ($operation === 'getpkginfo' ? ['CGI', 'FRONTPAGE'] : ['FRONTPAGE'] as $field) {
                $row = '{"' . $field . '":"0"}';
                yield "$method strict flag $field" => [$operation, $method, $arguments,
                    '{"pkg":' . ($operation === 'matchpkgs' ? '[' . $row . ']' : $row) . '}'];
            }
        }
        yield 'get package IP is not a string' => ['getpkginfo', 'getPackageInfo', ['fixture'], '{"pkg":{"IP":"n"}}'];
        yield 'get package CGI remains an integer flag' => [
            'getpkginfo', 'getPackageInfo', ['fixture'], '{"pkg":{"CGI":"n"}}',
        ];
        foreach (
            [
            'listpkgs' => 'listPackages',
            'getpkginfo' => 'getPackageInfo',
            'matchpkgs' => 'matchPackages',
            ] as $op => $method
        ) {
            foreach (
                [
                'true', 'false', '1.5', '1.0', '""', '"07"', '"+7"', '"-0"', '" 7"', '"7 "',
                '"7.0"', '"1e2"', '"unlimited"', '[]', '{}', '"' . PHP_INT_MAX . '0"',
                ] as $value
            ) {
                $row = '{"MAX_TEAM_USERS":' . $value . '}';
                yield $method . ' strict team limit ' . $value => [
                    $op, $method, $op === 'getpkginfo' ? ['fixture'] : [],
                    '{"pkg":' . ($op === 'getpkginfo' ? $row : '[' . $row . ']') . '}',
                ];
            }
        }
        foreach (['true', 'false', 'null', '1.0', '2', '[]', '{}'] as $value) {
            yield 'matching CGI rejects invalid nonstrings ' . $value => [
                'matchpkgs', 'matchPackages', [], '{"pkg":[{"CGI":' . $value . '}]}',
            ];
        }
        yield 'match package shell remains a string' => ['matchpkgs', 'matchPackages', [], '{"pkg":[{"HASSHELL":0}]}'];
        foreach (['null', '1', 'false', '[]', '{}'] as $value) {
            yield 'match package name remains a string ' . $value => [
                'matchpkgs', 'matchPackages', [], '{"pkg":[{"name":' . $value . '}]}',
            ];
        }
        foreach (['null', '{}', '[1]', '[{}]'] as $groups) {
            yield 'application groups ' . $groups => [
                'get_available_applications', 'getAvailableApplications', [], '{"groups":' . $groups . '}',
            ];
        }
        yield 'raw directive requires itemdesc' => [
            'get_available_applications', 'getAvailableApplications', [], '{"raw":[{"type":"raw"}]}',
        ];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedPayloads')]
    public function testSuccessfulOutcomeWithMalformedPayloadIsNotRetried(
        string $operation,
        string $method,
        array $arguments,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload === 'ABSENT' ? null : $payload)));
        try {
            $client->whm()->hostingPlans()->{$method}(...$arguments);
            self::fail('The malformed payload must be rejected after recording the successful outcome.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertNotNull($exception->metadata);
            self::assertSame(1, $exception->metadata->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testOmittedAndNullOptionsDoNotSendServerDefaults(): void
    {
        [$client, $http] = self::client();
        foreach (['addpkg', 'editpkg', 'matchpkgs', 'get_available_applications'] as $operation) {
            $http->addResponse(new Response(200, [], self::envelope($operation, '{}')));
        }
        $group = $client->whm()->hostingPlans();
        self::assertSame([], $group->addPackage('fixture', bwlimit: null, maxftp: null, quota: null));
        self::assertSame([], $group->editPackage('fixture', maxEmailacctQuota: null, maxTeamUsers: null));
        self::assertSame([], $group->matchPackages(maxTeamUsers: null));
        self::assertSame([], $group->getAvailableApplications());
        $requests = $http->getRequests();
        self::assertCount(4, $requests);
        self::assertSame('name=fixture', (string) $requests[0]->getBody());
        self::assertSame('name=fixture', (string) $requests[1]->getBody());
        self::assertSame('api.version=1', $requests[2]->getUri()->getQuery());
        self::assertSame('api.version=1', $requests[3]->getUri()->getQuery());
    }

    public function testMetadataOnlyExtensionFormPreservesDocumentedNoExtensionsCase(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('_getpkgextensionform', null)));
        self::assertNull($client->whm()->hostingPlans()->getPackageExtensionForm('fixture'));
        $http->addResponse(new Response(200, [], self::envelope('_getpkgextensionform', 'null')));
        self::assertNull($client->whm()->hostingPlans()->getPackageExtensionForm('fixture'));
    }

    public function testEmptyPackageObjectAndPackageListPreserveNullableLimits(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('getpkginfo', '{"pkg":{}}')));
        $http->addResponse(new Response(200, [], self::envelope('matchpkgs', '{"pkg":[]}')));
        $group = $client->whm()->hostingPlans();
        self::assertSame(['pkg' => []], $group->getPackageInfo('fixture'));
        self::assertSame(['pkg' => []], $group->matchPackages());
        $http->addResponse(new Response(200, [], self::envelope(
            'getpkginfo',
            '{"pkg":{"BWLIMIT":null,"MAXADDON":null,"MAXFTP":null,"MAXLST":null,"MAXPARK":null,"MAXPOP":null,'
            . '"MAXSQL":null,"MAXSUB":null,"QUOTA":null}}',
        )));
        self::assertSame(['pkg' => [
            'BWLIMIT' => null, 'MAXADDON' => null, 'MAXFTP' => null, 'MAXLST' => null, 'MAXPARK' => null,
            'MAXPOP' => null, 'MAXSQL' => null, 'MAXSUB' => null, 'QUOTA' => null]], $group->getPackageInfo('fixture'));
    }

    public function testApplicationGroupAndNestedRequiredFieldsRemainTyped(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'get_available_applications',
            '{"groups":[{"type":"group","acl":null,"dnsonly_ok":null,"file":"fixture.png",'
                . '"group":"fixture","groupdesc":"Fixture","grouporder":1,"items":['
                . '{"type":"image","acl":null,"description":"Fixture","dnsonly_ok":null,'
                . '"file":"item.svg","group":"fixture","itemorder":1,"url":"/synthetic",'
                . '"role":{"match":"all","roles":["MailLocal"]},"service":"mysql",'
                . '"subitems":[{"type":"subitem","acl":null,"breadcrumb":"/synthetic",'
                . '"dnsonly_ok":null,"file":"sub.svg","group":"fixture","parent":1,"url":"/child"}]}]}],'
            . '"raw":[{"type":"raw","itemdesc":"[% fixture %]"}]}',
        )));
        self::assertSame([
            'groups' => [['acl' => null, 'dnsonly_ok' => null, 'file' => 'fixture.png', 'group' => 'fixture',
                'groupdesc' => 'Fixture', 'grouporder' => 1,
                'items' => [['acl' => null, 'description' => 'Fixture', 'dnsonly_ok' => null, 'file' => 'item.svg',
                    'group' => 'fixture', 'itemorder' => 1,
                    'role' => ['match' => 'all', 'roles' => ['MailLocal']], 'service' => 'mysql',
                    'subitems' => [
                        ['acl' => null, 'breadcrumb' => '/synthetic', 'dnsonly_ok' => null,
                            'file' => 'sub.svg', 'group' => 'fixture', 'parent' => 1,
                            'type' => 'subitem', 'url' => '/child'],
                    ],
                    'type' => 'image', 'url' => '/synthetic']], 'type' => 'group']],
            'raw' => [['itemdesc' => '[% fixture %]', 'type' => 'raw']],
        ], $client->whm()->hostingPlans()->getAvailableApplications());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedApplications(): iterable
    {
        $group = ['type' => 'group', 'acl' => null, 'dnsonly_ok' => null, 'file' => 'group.svg',
            'group' => 'fixture', 'groupdesc' => 'Fixture', 'grouporder' => 1];
        $image = ['type' => 'image', 'acl' => null, 'description' => 'Fixture', 'dnsonly_ok' => null,
            'file' => 'item.svg', 'group' => 'fixture', 'itemorder' => 1, 'url' => '/synthetic'];
        $subitem = ['type' => 'subitem', 'acl' => null, 'breadcrumb' => '/synthetic', 'dnsonly_ok' => null,
            'file' => 'sub.svg', 'group' => 'fixture', 'parent' => 1, 'url' => '/child'];
        foreach (['type', 'dnsonly_ok', 'file', 'group', 'groupdesc', 'grouporder'] as $field) {
            $invalid = $group;
            unset($invalid[$field]);
            yield 'group requires ' . $field => [json_encode(['groups' => [$invalid]], JSON_THROW_ON_ERROR)];
        }
        foreach (['type', 'acl', 'file', 'group', 'itemorder', 'url'] as $field) {
            $invalid = $image;
            unset($invalid[$field]);
            yield 'image requires ' . $field => [json_encode(['groups' => [
                $group + ['items' => [$invalid]],
            ]], JSON_THROW_ON_ERROR)];
        }
        foreach (['type', 'acl', 'breadcrumb', 'dnsonly_ok', 'file', 'group', 'parent', 'url'] as $field) {
            $invalid = $subitem;
            unset($invalid[$field]);
            yield 'subitem requires ' . $field => [json_encode(['groups' => [
                $group + ['items' => [$image + ['subitems' => [$invalid]]]],
            ]], JSON_THROW_ON_ERROR)];
        }
        foreach (
            ['grouporder' => '1', 'groupdesc' => 1, 'acl' => false, 'target' => null,
            'description' => null, 'items' => [1]] as $field => $value
        ) {
            $invalid = $group;
            $invalid[$field] = $value;
            yield 'group field ' . $field => [json_encode(['groups' => [$invalid]], JSON_THROW_ON_ERROR)];
        }
        foreach ([false, 1, [], (object) []] as $value) {
            $invalid = $group + ['description' => $value];
            yield 'group description ' . json_encode($value, JSON_THROW_ON_ERROR) => [
                json_encode(['groups' => [$invalid]], JSON_THROW_ON_ERROR),
            ];
        }
        foreach (
            ['role' => ['roles' => [1]], 'service' => ['services' => [false]], 'itemorder' => '1',
            'url' => false, 'subitems' => [1], 'description' => null, 'dnsonly_ok' => false,
            'minimum_accounts_needed' => '1', 'multiuser_required' => '1', 'target' => null] as $field => $value
        ) {
            $invalid = $image;
            $invalid[$field] = $value;
            yield 'image field ' . $field => [json_encode(['groups' => [
                $group + ['items' => [$invalid]],
            ]], JSON_THROW_ON_ERROR)];
        }
        foreach (['parent' => '1', 'breadcrumb' => null, 'url' => 1] as $field => $value) {
            $invalid = $subitem;
            $invalid[$field] = $value;
            yield 'subitem field ' . $field => [json_encode(['groups' => [
                $group + ['items' => [$image + ['subitems' => [$invalid]]]],
            ]], JSON_THROW_ON_ERROR)];
        }
    }

    #[DataProvider('malformedApplications')]
    public function testNestedApplicationContractsRejectMalformedLeavesAndMissingRequiredFields(string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('get_available_applications', $payload)));
        $this->expectException(PayloadMismatchException::class);
        $client->whm()->hostingPlans()->getAvailableApplications();
    }

    /** @return list<string> */
    private static function packageLimitFields(): array
    {
        return ['BWLIMIT', 'MAXADDON', 'MAXFTP', 'MAXLST', 'MAXPARK', 'MAXPOP', 'MAXSQL', 'MAXSUB',
            'MAX_DEFER_FAIL_PERCENTAGE', 'MAX_EMAILACCT_QUOTA', 'MAX_EMAIL_PER_HOUR', 'QUOTA'];
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, array<array-key, mixed>}> */
    public static function reviewedPackageResponses(): iterable
    {
        foreach (['getpkginfo' => 'getPackageInfo', 'matchpkgs' => 'matchPackages'] as $operation => $method) {
            $arguments = $operation === 'getpkginfo' ? ['fixture'] : [];
            foreach (self::packageLimitFields() as $field) {
                foreach ([0, 1000, 4294967296, PHP_INT_MIN, PHP_INT_MAX] as $value) {
                    foreach ([false, true] as $quoted) {
                        $wire = $quoted ? (string) $value : $value;
                        $row = [$field => $wire];
                        $expected = [$field => $value];
                        yield $method . ' ' . $field . ' ' . json_encode($wire, JSON_THROW_ON_ERROR) => [
                            $operation, $method, $arguments,
                            json_encode(['pkg' => $operation === 'matchpkgs' ? [$row] : $row], JSON_THROW_ON_ERROR),
                            ['pkg' => $operation === 'matchpkgs' ? [$expected] : $expected],
                        ];
                    }
                }
                $row = [$field => 'unlimited'];
                $expected = ['pkg' => $operation === 'matchpkgs' ? [$row] : $row];
                yield $method . ' ' . $field . ' literal' => [
                    $operation, $method, $arguments, json_encode($expected, JSON_THROW_ON_ERROR), $expected,
                ];
            }
        }
        foreach (
            [
            'listpkgs' => 'listPackages',
            'getpkginfo' => 'getPackageInfo',
            'matchpkgs' => 'matchPackages',
            ] as $op => $method
        ) {
            foreach ([null, 0, 7, PHP_INT_MAX, PHP_INT_MIN] as $value) {
                foreach ([false, true] as $quoted) {
                    $wire = $quoted && $value !== null ? (string) $value : $value;
                    $row = ['MAX_TEAM_USERS' => $wire];
                    $expected = ['MAX_TEAM_USERS' => $value];
                    yield $method . ' team ' . json_encode($wire, JSON_THROW_ON_ERROR) . ($quoted ? ' quoted' : '') => [
                        $op, $method, $op === 'getpkginfo' ? ['fixture'] : [],
                        json_encode(['pkg' => $op === 'getpkginfo' ? $row : [$row]], JSON_THROW_ON_ERROR),
                        $op === 'listpkgs' ? [$expected] : ['pkg' => $op === 'getpkginfo' ? $expected : [$expected]],
                    ];
                }
            }
        }
        yield 'list packages mixes null numeric string and omitted team limits' => [
            'listpkgs', 'listPackages', [],
            '{"pkg":[{"name":"stock","MAX_TEAM_USERS":null},'
                . '{"name":"fixture","MAX_TEAM_USERS":"7","CGI":"n"},{"name":"omitted"}]}',
            [
                ['name' => 'stock', 'MAX_TEAM_USERS' => null],
                ['name' => 'fixture', 'MAX_TEAM_USERS' => 7, 'CGI' => 'n'],
                ['name' => 'omitted'],
            ],
        ];
        yield 'matching keeps populated string CGI and team count without changing other limits' => [
            'matchpkgs', 'matchPackages', [],
            '{"pkg":[{"name":"fixture","CGI":"n","HASSHELL":"n","IP":"n","MAX_TEAM_USERS":"7",'
                . '"MAXSQL":"5","QUOTA":"100","FRONTPAGE":0},{"name":"omitted"}]}',
            ['pkg' => [
                [
                    'name' => 'fixture', 'CGI' => 'n', 'HASSHELL' => 'n', 'IP' => 'n',
                    'MAX_TEAM_USERS' => 7, 'MAXSQL' => 5, 'QUOTA' => 100, 'FRONTPAGE' => 0,
                ],
                ['name' => 'omitted'],
            ]],
        ];
        foreach (['n', 'y', '0', '', 0, 1] as $cgi) {
            yield 'matching CGI preserves exact string or pinned flag ' . json_encode($cgi, JSON_THROW_ON_ERROR) => [
                'matchpkgs', 'matchPackages', [], json_encode(['pkg' => [['CGI' => $cgi]]], JSON_THROW_ON_ERROR),
                ['pkg' => [['CGI' => $cgi]]],
            ];
        }
        yield 'matched deprecated frontpage retains native integer contract' => ['matchpkgs', 'matchPackages', [],
            '{"pkg":[{"FRONTPAGE":2}]}', ['pkg' => [['FRONTPAGE' => 2]]]];
        yield 'stock package park null' => ['getpkginfo', 'getPackageInfo', ['fixture'],
            '{"pkg":{"MAXPARK":null,"MAXADDON":null,"CGI":0}}',
            ['pkg' => ['MAXPARK' => null, 'MAXADDON' => null, 'CGI' => 0]]];
        yield 'matched package empty row stays a list row' => ['matchpkgs', 'matchPackages', [],
            '{"pkg":[{}]}', ['pkg' => [[]]]];
        yield 'matched package two named rows preserve limits and omissions' => ['matchpkgs', 'matchPackages', [],
            '{"pkg":[{"name":"first","QUOTA":"4294967296","MAXPARK":"unlimited","CGI":1,"HASSHELL":"n"},'
                . '{"name":"second","QUOTA":0,"BWLIMIT":"1000","IP":"y"},{}]}',
            ['pkg' => [
                ['name' => 'first', 'QUOTA' => 4294967296, 'MAXPARK' => 'unlimited', 'CGI' => 1, 'HASSHELL' => 'n'],
                ['name' => 'second', 'QUOTA' => 0, 'BWLIMIT' => 1000, 'IP' => 'y'], [],
            ]]];
    }

    /** @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('reviewedPackageResponses')]
    public function testReviewedPackageRowsPreserveNormalizedLimitsAndNames(
        string $operation,
        string $method,
        array $arguments,
        string $payload,
        array $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($operation, $payload)));
        self::assertSame(
            self::canonical($expected),
            self::canonical($client->whm()->hostingPlans()->{$method}(...$arguments)),
        );
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function reviewedApplicationResponses(): iterable
    {
        $group = ['type' => 'group', 'acl' => null, 'dnsonly_ok' => null, 'file' => 'group.svg',
            'group' => 'fixture', 'groupdesc' => 'Fixture', 'grouporder' => 1, 'target' => '_blank'];
        $described = $group + ['description' => 'Synthetic group description'];
        yield 'group description retained beside omitted description' => [
            json_encode(['groups' => [$described, $group]], JSON_THROW_ON_ERROR), ['groups' => [$described, $group]],
        ];
        foreach (['acl', 'target'] as $field) {
            $row = $group;
            unset($row[$field]);
            yield 'optional group ' . $field => [json_encode(['groups' => [$row]], JSON_THROW_ON_ERROR),
                ['groups' => [$row]]];
        }
        $item = ['type' => 'image', 'acl' => null, 'description' => 'Fixture', 'dnsonly_ok' => null,
            'file' => 'item.svg', 'group' => 'fixture', 'itemorder' => 1, 'url' => '/synthetic',
            'minimum_accounts_needed' => 1, 'multiuser_required' => 1, 'target' => '_blank',
            'role' => ['match' => 'all', 'roles' => ['MailLocal']], 'service' => 'mysql', 'subitems' => []];
        foreach (
            ['minimum_accounts_needed', 'role', 'service', 'target', 'subitems', 'multiuser_required',
            'description', 'dnsonly_ok'] as $field
        ) {
            $row = $item;
            unset($row[$field]);
            $expected = ['groups' => [$group + ['items' => [$row]]]];
            yield 'optional item ' . $field => [json_encode($expected, JSON_THROW_ON_ERROR), $expected];
        }
        yield 'reviewed omissions across two groups and two items' => [
            '{"groups":[{"type":"group","dnsonly_ok":null,"file":"one.svg","group":"one",'
                . '"groupdesc":"One","grouporder":1,"items":[{"type":"image","acl":null,"file":"a.svg",'
                . '"group":"one","itemorder":1,"url":"/a"},{"type":"image","acl":"all","file":"b.svg",'
                . '"group":"one","itemorder":2,"url":"/b","description":"B","dnsonly_ok":null}]},'
                . '{"type":"group","dnsonly_ok":"dns","file":"two.svg","group":"two",'
                . '"groupdesc":"Two","grouporder":2}],"raw":[{"type":"raw","itemdesc":"[% synthetic %]"}]}',
            ['groups' => [
                ['type' => 'group', 'dnsonly_ok' => null, 'file' => 'one.svg', 'group' => 'one',
                    'groupdesc' => 'One', 'grouporder' => 1, 'items' => [
                        ['type' => 'image', 'acl' => null, 'file' => 'a.svg', 'group' => 'one',
                            'itemorder' => 1, 'url' => '/a'],
                        ['type' => 'image', 'acl' => 'all', 'file' => 'b.svg', 'group' => 'one',
                            'itemorder' => 2, 'url' => '/b', 'description' => 'B', 'dnsonly_ok' => null],
                    ]],
                ['type' => 'group', 'dnsonly_ok' => 'dns', 'file' => 'two.svg', 'group' => 'two',
                    'groupdesc' => 'Two', 'grouporder' => 2],
            ], 'raw' => [['type' => 'raw', 'itemdesc' => '[% synthetic %]']]],
        ];
    }

    /** @param array<array-key, mixed> $expected */
    #[DataProvider('reviewedApplicationResponses')]
    public function testReviewedApplicationOmissionsPreserveRemainingFields(string $payload, array $expected): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('get_available_applications', $payload)));
        self::assertSame(
            self::canonical($expected),
            self::canonical($client->whm()->hostingPlans()->getAvailableApplications()),
        );
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonical($entry);
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public function testNamesRemainVisibleAndNewFeatureMapsAreTraceRedacted(): void
    {
        [$client] = self::client();
        $group = $client->whm()->hostingPlans();
        foreach (['createFeaturelist', 'updateFeaturelist'] as $method) {
            $parameters = (new ReflectionMethod($group, $method))->getParameters();
            self::assertSame([], $parameters[0]->getAttributes(SensitiveParameter::class));
            self::assertCount(1, $parameters[1]->getAttributes(SensitiveParameter::class));
            self::assertFalse($parameters[1]->isOptional());
        }
        foreach (self::operations() as [, $method]) {
            foreach ((new ReflectionMethod($group, $method))->getParameters() as $parameter) {
                self::assertCount(
                    $method === 'getAvailableApplications' && $parameter->getName() === 'applicationsList' ? 1 : 0,
                    $parameter->getAttributes(SensitiveParameter::class),
                );
            }
        }
    }

    private static function envelope(string $operation, ?string $payload): string
    {
        return '{"metadata":{"command":"' . $operation . '","reason":"OK","result":1,"version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
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
