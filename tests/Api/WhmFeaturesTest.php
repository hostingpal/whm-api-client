<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Whm\Features;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\HttpException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WhmFeaturesTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, array<string, mixed>|bool}> */
    public static function operations(): iterable
    {
        yield 'metadata' => ['metadata', 'get_feature_metadata',
            '{"features":[{"id":"ssl","name":"SSL","is_plugin":0},{}]}',
            ['features' => [['id' => 'ssl', 'name' => 'SSL', 'is_plugin' => 0], []]]];
        yield 'names' => ['names', 'get_feature_names', '{"feature":[{"id":"ssl","name":"SSL"},{}]}',
            ['feature' => [['id' => 'ssl', 'name' => 'SSL'], []]]];
        yield 'list data' => ['listData', 'get_featurelist_data',
            '{"featurelist":"disabled","features":[{"id":"ssl","is_disabled":0,"value":1,'
                . '"dependencies":["sslinstall"],"name":"SSL","is_standalone_experience":1,'
                . '"badge_label":"","badge_class":"featured","required_package_extension":"",'
                . '"is_meridian_only":null,"redirect_url":"/ssl","suppress_plugin_label":0,'
                . '"only_one_rules":["^standalone-.*"]}]}',
            ['featurelist' => 'disabled', 'features' => [[
                'id' => 'ssl', 'is_disabled' => 0, 'value' => 1,
                'dependencies' => ['sslinstall'], 'name' => 'SSL', 'is_standalone_experience' => 1,
                'badge_label' => '', 'badge_class' => 'featured', 'required_package_extension' => '',
                'is_meridian_only' => null, 'redirect_url' => '/ssl', 'suppress_plugin_label' => 0,
                'only_one_rules' => ['^standalone-.*'],
            ]]]];
        yield 'lists' => ['lists', 'get_featurelists', '{"featurelists":["default","disabled"]}',
            ['featurelists' => ['default', 'disabled']]];
        yield 'package types' => ['listsByPackageType', 'get_featurelists_by_package_types',
            '{"package_types_feature_lists":{"standard":[],"nova":["my-nova-list"]}}',
            ['package_types_feature_lists' => ['standard' => [], 'nova' => ['my-nova-list']]]];
        yield 'settings' => ['userSettings', 'get_users_features_settings',
            '{"users_features_settings":[{"cpuser_setting":null,"feature":"ssl",'
                . '"feature_list":"default","feature_list_setting":0,"user":"alice"},{}]}',
            ['users_features_settings' => [[
                'cpuser_setting' => null, 'feature' => 'ssl', 'feature_list' => 'default',
                'feature_list_setting' => 0, 'user' => 'alice',
            ], []]]];
        yield 'access' => ['userHasFeature', 'verify_user_has_feature',
            '{"has_feature":1,"query_feature":"unknown_feature"}', true];
    }

    /** @param array<string, mixed>|bool $expected */
    #[DataProvider('operations')]
    public function testOperationsPreserveReviewedFieldsAndUseTheirDocumentedRequests(
        string $method,
        string $operation,
        string $data,
        array|bool $expected,
    ): void {
        [$client, $http] = self::client();
        $group = $client->whm()->features();
        self::assertSame($group, $client->whm()->features());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::response($operation, $data)));

        self::assertSame($expected, self::execute($group, $method));
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $operation . '?api.version=1' . match ($method) {
            'listData' => '&featurelist=disabled',
            'userSettings' => '&user=alice',
            'userHasFeature' => '&user=alice&feature=unknown_feature',
            default => '',
        }, (string) $request->getUri());
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());
        self::assertSame($operation, $client->whm()->responseMeta()?->whm['command'] ?? null);
        self::assertSame(1, $client->whm()->responseMeta()?->whm['result'] ?? null);
        self::assertNull($client->uapi('alice')->responseMeta());
    }

    public function testOptionalKeysAndEmptyListsRemainDistinct(): void
    {
        $cases = [
            ['metadata', 'features'],
            ['names', 'feature'],
            ['listData', 'features'],
            ['lists', 'featurelists'],
            ['userSettings', 'users_features_settings'],
        ];
        foreach ($cases as [$method, $key]) {
            self::assertSame([], self::execute(self::group('{}'), $method));
            self::assertSame([$key => []], self::execute(self::group('{"' . $key . '":[]}'), $method));
        }
        self::assertSame([], self::group('{}')->listsByPackageType());
        self::assertSame(['package_types_feature_lists' => ['standard' => []]], self::group(
            '{"package_types_feature_lists":{"standard":[]}}',
        )->listsByPackageType());
    }

    public function testUserSettingsSupportsDocumentedNumberedParameters(): void
    {
        [$client, $http] = self::client();
        foreach (range(1, 3) as $ignored) {
            $http->addResponse(new Response(200, [], self::response('get_users_features_settings', '{}')));
        }

        $features = $client->whm()->features();
        $features->userSettings(['alice', 'bob', 'carol'], ['ssl', 'ftp', 'cron']);
        $features->userSettings('alice', '');
        $features->userSettings(['alice'], null);

        self::assertSame([
            'api.version=1&user=alice&user-1=bob&user-2=carol&feature=ssl&feature-1=ftp&feature-2=cron',
            'api.version=1&user=alice&feature=',
            'api.version=1&user=alice',
        ], array_map(
            static fn (RequestInterface $request): string => $request->getUri()->getQuery(),
            $http->getRequests(),
        ));
    }

    public function testFeatureQueriesEncodeUserSuppliedValues(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::response('get_users_features_settings', '{}')));

        $client->whm()->features()->userSettings(['alice', 'bob'], ['ssl&other=1', 'file manager']);

        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2087/json-api/get_users_features_settings?api.version=1'
                . '&user=alice&user-1=bob&feature=ssl%26other%3D1&feature-1=file%20manager',
            (string) $request->getUri(),
        );
    }

    public function testUserSettingsRejectsEmptyParameterListsBeforeRequesting(): void
    {
        [$client, $http] = self::client();

        try {
            $client->whm()->features()->userSettings([]);
            self::fail('Expected an invalid parameter list.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $http->getRequests());
        }
    }

    public function testEmptyFeatureParameterListIsNotSilentlyOmitted(): void
    {
        [$client, $http] = self::client();

        try {
            $client->whm()->features()->userSettings('alice', []);
            self::fail('Expected an invalid parameter list.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $http->getRequests());
        }
    }

    public function testInvalidParameterListsClearOnlyCurrentMetadataBeforeIo(): void
    {
        foreach ([false, true] as $emptyFeature) {
            [$client, $http] = self::client();
            $whm = $client->whm();
            $other = $client->uapi('other');
            $http->addResponse(new Response(200, [], '{"metadata":{"result":1},'
                . '"data":{"uapi":{"status":1,"data":{"versions":["ea-php84"]}}}}'));
            $other->php()->listVersions();
            $otherMetadata = $other->responseMeta();
            self::assertNotNull($otherMetadata);
            $http->addResponse(new Response(200, [], self::response('get_featurelists', '{"featurelists":[]}')));
            $whm->features()->lists();
            $retained = $whm->responseMeta();

            try {
                if ($emptyFeature) {
                    $whm->features()->userSettings('alice', []);
                } else {
                    $whm->features()->userSettings([]);
                }
                self::fail('Expected an invalid parameter list.');
            } catch (InvalidArgumentException) {
                self::assertNull($whm->responseMeta());
                self::assertSame('get_featurelists', $retained?->whm['command'] ?? null);
                self::assertSame(1, $retained?->whm['result'] ?? null);
                self::assertSame($otherMetadata, $other->responseMeta());
                self::assertCount(2, $http->getRequests());
            }
        }
    }

    public function testAccessFlagsRemainStrictAndDoNotCheckFeatureExistence(): void
    {
        self::assertFalse(self::group('{"has_feature":0}')->userHasFeature('alice', 'ssl'));
        self::assertTrue(self::group('{"has_feature":1}')->userHasFeature('alice', 'unknown_feature'));
    }

    public function testMetadataPreservesObservedStringPluginFlags(): void
    {
        self::assertSame(['features' => [
            ['id' => 'ssl', 'is_plugin' => '0'],
            ['id' => 'custom', 'is_plugin' => '1'],
        ]], self::group(
            '{"features":[{"id":"ssl","is_plugin":"0"},{"id":"custom","is_plugin":"1"}]}',
        )->metadata()->toArray());
    }

    public function testListDataPreservesObservedStringAndBooleanFlags(): void
    {
        self::assertSame(['features' => [
            ['id' => 'ssl', 'is_disabled' => '0', 'value' => '1', 'is_standalone_experience' => false,
                'is_meridian_only' => false, 'suppress_plugin_label' => true],
            ['id' => 'custom', 'is_disabled' => '1', 'value' => '0', 'is_standalone_experience' => true,
                'is_meridian_only' => true, 'suppress_plugin_label' => false],
        ]], self::group(
            '{"features":[{"id":"ssl","is_disabled":"0","value":"1",'
                . '"is_standalone_experience":false,"is_meridian_only":false,"suppress_plugin_label":true},'
                . '{"id":"custom","is_disabled":"1","value":"0",'
                . '"is_standalone_experience":true,"is_meridian_only":true,"suppress_plugin_label":false}]}',
        )->listData('default')->toArray());
    }

    public function testUserSettingsPreservesObservedStringDefaultFlags(): void
    {
        self::assertSame(['users_features_settings' => [
            ['cpuser_setting' => null, 'feature_list_setting' => '1'],
            ['cpuser_setting' => 0, 'feature_list_setting' => '0'],
        ]], self::group(
            '{"users_features_settings":[{"cpuser_setting":null,"feature_list_setting":"1"},'
                . '{"cpuser_setting":0,"feature_list_setting":"0"}]}',
        )->userSettings('alice')->toArray());
    }

    public function testReturnedArraysAreDetachedFromSourceObjects(): void
    {
        $record = (object) ['id' => 'ssl', 'name' => 'SSL', 'is_plugin' => 0];
        $payload = (object) ['features' => [$record], 'unknown_extension' => (object) ['ignored' => true]];
        $group = new Features(static fn (): ApiResponse => new ApiResponse(
            $payload,
            new ResponseMetadata(200, ['result' => 1]),
        ));

        $result = $group->metadata()->toArray();
        $record->name = 'changed';

        self::assertSame(['features' => [['id' => 'ssl', 'name' => 'SSL', 'is_plugin' => 0]]], $result);
    }

    /** @param class-string<ApiException> $exceptionClass */
    #[DataProvider('failedResponses')]
    public function testOperationFailuresReplaceMetadataWithoutRetry(
        string $method,
        int $status,
        string $body,
        string $exceptionClass,
        int|string|null $result,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $http->addResponse(new Response(200, [], self::response('get_featurelists', '{"featurelists":[]}')));
        $whm->features()->lists();
        $retained = $whm->responseMeta();
        $http->addResponse(new Response($status, [], $body));

        try {
            self::execute($whm->features(), $method);
            self::fail('Expected a feature operation failure.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($whm->responseMeta(), $exception->metadata);
            self::assertNotSame($retained, $whm->responseMeta());
            self::assertSame($result, $exception->metadata?->whm['result'] ?? null);
            self::assertSame('get_featurelists', $retained?->whm['command'] ?? null);
            self::assertNull($client->uapi('alice')->responseMeta());
        }
        self::assertCount(2, $http->getRequests());
    }

    /** @return iterable<string, array{string, int, string, class-string<ApiException>, int|string|null}> */
    public static function failedResponses(): iterable
    {
        foreach (self::operations() as [$method]) {
            yield $method . ' API error' => [$method, 200, '{"metadata":{"result":0,"reason":"failed"}}',
                ApiErrorException::class, 0];
            yield $method . ' invalid status' => [$method, 200, '{"metadata":{"result":"1"},"data":{}}',
                InvalidResponseException::class, '1'];
            yield $method . ' HTTP failure' => [$method, 403, '{"metadata":{"result":0}}', HttpException::class, 0];
            yield $method . ' missing data' => [$method, 200, '{"metadata":{"result":1}}',
                InvalidResponseException::class, 1];
            yield $method . ' redirect' => [$method, 302, '', HttpException::class, null];
            yield $method . ' malformed JSON' => [$method, 200, '{invalid', InvalidResponseException::class, null];
        }
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadsRetainTheEnvelopeSnapshot(string $method, string $data): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1, 'command' => 'feature-operation']);
        try {
            self::execute(self::group($data, $metadata), $method);
            self::fail('Expected an invalid feature payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPayloads(): iterable
    {
        foreach (self::operations() as [$method]) {
            foreach (['null', '[]', '0', '"object required"'] as $data) {
                yield $method . ' envelope ' . $data => [$method, $data];
            }
        }
        yield 'metadata noncanonical flag' => ['metadata', '{"features":[{"is_plugin":"01"}]}'];
        yield 'metadata boolean flag' => ['metadata', '{"features":[{"is_plugin":false}]}'];
        yield 'metadata null name' => ['metadata', '{"features":[{"name":null}]}'];
        yield 'metadata object list' => ['metadata', '{"features":{}}'];
        yield 'names scalar record' => ['names', '{"feature":[1]}'];
        yield 'names invalid id' => ['names', '{"feature":[{"id":1}]}'];
        yield 'list data missing id' => ['listData', '{"features":[{"is_disabled":0,"value":1}]}'];
        yield 'list data missing disabled flag' => ['listData', '{"features":[{"id":"ssl","value":1}]}'];
        yield 'list data missing value' => ['listData', '{"features":[{"id":"ssl","is_disabled":0}]}'];
        yield 'list data boolean flag' => ['listData', '{"features":[{"id":"ssl","is_disabled":true,"value":1}]}'];
        yield 'list data boolean value' => ['listData', '{"features":[{"id":"ssl","is_disabled":0,"value":true}]}'];
        yield 'list data noncanonical disabled flag' => ['listData',
            '{"features":[{"id":"ssl","is_disabled":"01","value":1}]}'];
        yield 'list data noncanonical value' => ['listData',
            '{"features":[{"id":"ssl","is_disabled":0,"value":"0.0"}]}'];
        yield 'list data bad list name' => ['listData', '{"featurelist":0}'];
        foreach (['is_standalone_experience', 'suppress_plugin_label', 'is_meridian_only'] as $field) {
            yield 'list data ' . $field => ['listData',
                '{"features":[{"id":"ssl","is_disabled":0,"value":1,"' . $field . '":2}]}'];
            yield 'list data string ' . $field => ['listData',
                '{"features":[{"id":"ssl","is_disabled":0,"value":1,"' . $field . '":"1"}]}'];
        }
        foreach (['dependencies', 'only_one_rules'] as $field) {
            yield 'list data ' . $field => ['listData',
                '{"features":[{"id":"ssl","is_disabled":0,"value":1,"' . $field . '":[null]}]}'];
        }
        foreach (['name', 'badge_label', 'badge_class', 'required_package_extension', 'redirect_url'] as $field) {
            yield 'list data ' . $field => ['listData',
                '{"features":[{"id":"ssl","is_disabled":0,"value":1,"' . $field . '":null}]}'];
        }
        yield 'lists null' => ['lists', '{"featurelists":null}'];
        yield 'lists numeric item' => ['lists', '{"featurelists":[1]}'];
        yield 'package types no standard' => ['listsByPackageType', '{"package_types_feature_lists":{"nova":[]}}'];
        yield 'package types null standard' => ['listsByPackageType',
            '{"package_types_feature_lists":{"standard":null}}'];
        yield 'package types scalar nova' => ['listsByPackageType',
            '{"package_types_feature_lists":{"standard":[],"nova":1}}'];
        yield 'settings bool override' => ['userSettings', '{"users_features_settings":[{"cpuser_setting":true}]}'];
        yield 'settings string override' => ['userSettings', '{"users_features_settings":[{"cpuser_setting":"1"}]}'];
        yield 'settings boolean default' => ['userSettings',
            '{"users_features_settings":[{"feature_list_setting":true}]}'];
        yield 'settings noncanonical default' => ['userSettings',
            '{"users_features_settings":[{"feature_list_setting":"01"}]}'];
        yield 'settings null default' => ['userSettings',
            '{"users_features_settings":[{"feature_list_setting":null}]}'];
        foreach (['feature', 'feature_list', 'user'] as $field) {
            yield 'settings ' . $field => ['userSettings', '{"users_features_settings":[{"' . $field . '":1}]}'];
        }
        foreach (
            ['{}', '{"has_feature":null}', '{"has_feature":true}', '{"has_feature":"1"}',
            '{"has_feature":2}', '{"has_feature":1,"query_feature":null}'] as $data
        ) {
            yield 'access ' . $data => ['userHasFeature', $data];
        }
    }

    private static function group(string $data, ?ResponseMetadata $metadata = null): Features
    {
        return new Features(static fn (): ApiResponse => new ApiResponse(
            json_decode($data, false, 512, JSON_THROW_ON_ERROR),
            $metadata ?? new ResponseMetadata(200, ['result' => 1]),
        ));
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $http = new MockClient();
        $factory = new Psr17Factory();

        return [new Client([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'username' => 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }

    private static function response(string $operation, string $data): string
    {
        return '{"metadata":{"result":1,"command":"' . $operation . '","reason":"OK","version":1},'
            . '"data":' . $data . '}';
    }

    /** @return array<string, mixed>|bool */
    private static function execute(Features $group, string $method): array|bool
    {
        return match ($method) {
            'metadata' => $group->metadata()->toArray(),
            'names' => $group->names(),
            'listData' => $group->listData('disabled')->toArray(),
            'lists' => $group->lists(),
            'listsByPackageType' => $group->listsByPackageType(),
            'userSettings' => $group->userSettings('alice')->toArray(),
            default => $group->userHasFeature('alice', 'unknown_feature'),
        };
    }
}
