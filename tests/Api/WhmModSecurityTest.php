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
use ReflectionParameter;
use SensitiveParameter;
use TypeError;

/** Literal source-reviewed fixtures, independent of generated specs and overlay data. */
final class WhmModSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        [$client] = self::client();
        self::assertContains('modSecurity', get_class_methods($client->whm()));
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testExactRequestsAndPayloads(
        string $function,
        string $method,
        array $arguments,
        string $verb,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        $expected = $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            self::canonical($expected),
            self::canonical($client->whm()->modSecurity()->{$method}(...$arguments)),
        );
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($verb, $request->getMethod());
        self::assertSame('https://api.example.test:2087/json-api/' . $function . '?api.version=1'
            . ($verb === 'GET' && $wire !== '' ? '&' . $wire : ''), (string) $request->getUri());
        self::assertSame($verb === 'POST' ? $wire : '', (string) $request->getBody());
        self::assertSame(
            $verb === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertSame($function, $client->whm()->responseMeta()?->whm['command'] ?? null);
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string, string, ?string}> */
    public static function operations(): iterable
    {
        $rule = '{"config":"user.conf","config_active":1,"disabled":0,"id":123,"meta_msg":"message",'
            . '"rule":"SecAction pass","staged":1,"vendor_active":0,"vendor_id":"vendor"}';
        $vendor = '"archive_url":"https://vendor.test/rules.zip","cpanel_provided":0,"description":"vendor",'
            . '"dist_md5":"digest","dist_sha512":"digest512","installed":1,'
            . '"installed_from":"https://vendor.test/meta.yaml","name":"Vendor","path":"/rules",'
            . '"report_url":"https://vendor.test/report","supported_versions":["2.9","3.0"],'
            . '"vendor_id":"vendor","vendor_url":"https://vendor.test"';
        $config = '{"active":1,"config":"vendor/rules.conf","vendor_id":"vendor"}';
        $options = '[{"name":"On label","option":"On"},{"name":"Off label","option":"Off"}]';
        $setting = '"default":"Off","description":"setting","directive":"SecRuleEngine","engine":1,'
            . '"name":"Engine","radio_options":' . $options . ',"setting_id":2,"state":"On",'
            . '"type":"radio","url":"https://reference.test/rule"';
        $hit = '"action_desc":"denied","handler":null,"host":"example.test","http_method":"GET",'
            . '"http_status":406,"http_version":"HTTP/1.1","id":123,"ip":"192.0.2.1",'
            . '"justification":"match","meta_file":"/rules.conf","meta_id":456,"meta_line":2,'
            . '"meta_logdata":null,"meta_msg":"message","meta_offset":0,"meta_rev":null,'
            . '"meta_severity":null,"meta_uri":null,"path":"/path","timestamp":"2026-10-30 12:00:00"';
        yield 'add rule unspecified fields' => ['modsec_add_rule', 'addRule', ['user.conf', 'a +&=%/雪'], 'POST',
            'config=user.conf&rule=a%20%2B%26%3D%25%2F%E9%9B%AA',
            '{"rule":{"config":null,"config_active":"opaque","disabled":0,"duplicate":1,"id":123,'
            . '"meta_msg":"message","rule":"rule","staged":null,"vendor_active":false,"vendor_id":[]}}'];
        yield 'add vendor' => ['modsec_add_vendor', 'addVendor', ['https://vendor.test/meta?a=1&b=2', false], 'POST',
            'url=https%3A%2F%2Fvendor.test%2Fmeta%3Fa%3D1%26b%3D2&enabled=0',
            '{' . $vendor . ',"enabled":0,"inst_dist":"vendor-2"}'];
        yield 'assemble' => ['modsec_assemble_config_text', 'assembleConfigText',
            ['user.conf', false, true, true, 'a +&=%/雪'], 'POST',
            'config=user.conf&deploy=0&final=1&init=1&text=a%20%2B%26%3D%25%2F%E9%9B%AA', null];
        yield 'batch one' => ['modsec_batch_settings', 'batchSettings', [0, 0, false], 'POST',
            'setting_id=0&state=0&remove=0', '{"updated_settings":[{' . $setting . '}]}'];
        yield 'check invalid' => ['modsec_check_rule', 'checkRule', ['SecAction pass'], 'POST',
            'rule=SecAction%20pass', '{"problem":"synthetic validation error","valid":0}'];
        yield 'clone' => ['modsec_clone_rule', 'cloneRule', ['user.conf', 123], 'POST',
            'config=user.conf&id=123', '{"rule":' . $rule . '}'];
        yield 'deploy all partial failure' => ['modsec_deploy_all_rule_changes', 'deployAllRuleChanges', [], 'POST', '',
            '{"failed":["bad.conf"],"outcomes":[{"config":"bad.conf","exception":"invalid","ok":0},'
                . '{"config":"good.conf","ok":1}]}'];
        yield 'deploy one' => ['modsec_deploy_rule_changes', 'deployRuleChanges', ['user.conf'], 'POST',
            'config=user.conf', '{"opaque":{"zero":0,"null":null}}'];
        yield 'deploy settings' => ['modsec_deploy_settings_changes', 'deploySettingsChanges', [], 'POST', '', 'null'];
        yield 'disable string id' => ['modsec_disable_rule', 'disableRule', ['user.conf', '00123'], 'POST',
            'config=user.conf&id=00123', null];
        yield 'disable vendor' => ['modsec_disable_vendor', 'disableVendor', ['vendor'], 'POST',
            'vendor_id=vendor', '{}'];
        yield 'disable configs' => ['modsec_disable_vendor_configs', 'disableVendorConfigs', ['vendor'], 'POST',
            'vendor_id=vendor', '{"outcomes":[{"active":0,"config":"rules.conf","exception":"error","ok":0}]}'];
        yield 'disable updates' => ['modsec_disable_vendor_updates', 'disableVendorUpdates', ['vendor'], 'POST',
            'vendor_id=vendor', '{}'];
        yield 'discard all' => ['modsec_discard_all_rule_changes', 'discardAllRuleChanges', [], 'POST', '',
            '{"failed":["bad.conf"],"outcomes":[{"config":"bad.conf","exception":"error","ok":0}]}'];
        yield 'discard one' => ['modsec_discard_rule_changes', 'discardRuleChanges', ['user.conf'], 'POST',
            'config=user.conf', '{}'];
        yield 'edit rule' => ['modsec_edit_rule', 'editRule', ['user.conf', 123, 'a +&=%/雪'], 'POST',
            'config=user.conf&id=123&rule=a%20%2B%26%3D%25%2F%E9%9B%AA',
            '{"rule":{"disabled":1,"id":123,"meta_msg":"message","rule":"changed"}}'];
        yield 'enable vendor' => ['modsec_enable_vendor', 'enableVendor', ['vendor'], 'POST',
            'vendor_id=vendor', '{}'];
        yield 'enable configs' => ['modsec_enable_vendor_configs', 'enableVendorConfigs', ['vendor'], 'POST',
            'vendor_id=vendor', '{"outcomes":[{"active":1,"config":"rules.conf","ok":1},'
                . '{"active":0,"config":"failed.conf","exception":"error","ok":0}]}'];
        yield 'enable updates' => ['modsec_enable_vendor_updates', 'enableVendorUpdates', ['vendor'], 'POST',
            'vendor_id=vendor', '{}'];
        yield 'get text' => ['modsec_get_config_text', 'getConfigText', ['a +&=%/雪'], 'GET',
            'config=a%20%2B%26%3D%25%2F%E9%9B%AA', '{"text":"SecAction pass\n"}'];
        yield 'configs' => ['modsec_get_configs', 'getConfigs', [], 'GET', '', '{"configs":[' . $config . ',{}]}'];
        yield 'pending configs' => ['modsec_get_configs_with_changes_pending', 'getConfigsWithChangesPending', [],
            'GET', '', '{"configs":["user.conf",""]}'];
        yield 'log' => ['modsec_get_log', 'getLog', [], 'GET', '',
            '{"data":[{' . $hit . ',"file_exists":1,"reportable":0,"timezone":-60}]}'];
        yield 'rules flags' => ['modsec_get_rules', 'getRules', ['user.conf', false, true, ''], 'GET',
            'config=user.conf&exclude_bare_comments=0&exclude_other_directives=1&vendor_id=',
            '{"chunks":[' . $rule . ',{}],"staged_changes":1}'];
        yield 'settings' => ['modsec_get_settings', 'getSettings', [], 'GET', '',
            '{"settings":[{' . $setting . ',"missing":1,"validation":[{"arg":"prefix","name":"startsWith"},'
                . '"path","honeypotAccessKey","positiveInteger"]},{"default":"","state":"","validation":[]}]}'];
        yield 'vendors' => ['modsec_get_vendors', 'getVendors', [], 'GET', '',
            '{"vendors":[{' . $vendor . ',"enabled":1,"in_use":0,"inst_dist":"vendor-2","update":0,'
                . '"configs":[' . $config . ']}]}'];
        yield 'installed' => ['modsec_is_installed', 'isInstalled', [], 'GET', '', '{"data":{"installed":0}}'];
        yield 'activate' => ['modsec_make_config_active', 'makeConfigActive', ['user.conf'], 'POST',
            'config=user.conf', '{}'];
        yield 'deactivate' => ['modsec_make_config_inactive', 'makeConfigInactive', ['user.conf'], 'POST',
            'config=user.conf', '{}'];
        yield 'preview POST' => ['modsec_preview_vendor', 'previewVendor', ['https://vendor.test/meta'], 'POST',
            'url=https%3A%2F%2Fvendor.test%2Fmeta', '{' . $vendor . '}'];
        yield 'remove rule' => ['modsec_remove_rule', 'removeRule', ['user.conf', 123], 'POST',
            'config=user.conf&id=123', '{}'];
        yield 'remove setting' => ['modsec_remove_setting', 'removeSetting', [0], 'POST', 'setting_id=0', '{}'];
        yield 'remove vendor' => ['modsec_remove_vendor', 'removeVendor', ['vendor'], 'POST', 'vendor_id=vendor', null];
        yield 'report without sending' => ['modsec_report_rule', 'reportRule',
            ['a+tag@example.test', 'a +&=%/雪', 123, false, 'free text'], 'POST',
            'email=a%2Btag%40example.test&message=a%20%2B%26%3D%25%2F%E9%9B%AA&row_ids=123&send=0&type=free%20text',
            '{"report":[{"email":"a@example.test","hits":[{' . $hit . ',"timezone":"-60"}],'
                . '"message":"message","rule_text":"SecAction pass","type":"free text"}]}'];
        yield 'set text' => ['modsec_set_config_text', 'setConfigText', ['user.conf', 'a +&=%/雪'], 'POST',
            'config=user.conf&text=a%20%2B%26%3D%25%2F%E9%9B%AA', '{"opaque":[1,null]}'];
        yield 'set setting' => ['modsec_set_setting', 'setSetting', [0, '0'], 'POST', 'setting_id=0&state=0',
            '{"default":"1500","description":"setting","directive":"SecPcreMatchLimit","name":"Limit",'
                . '"radio_options":' . $options . ',"setting_id":0,"state":0,"type":"number",'
                . '"url":"https://reference.test/limit","validation":[{"arg":"prefix","name":"startsWith"},'
                . '"positiveInteger"]}'];
        yield 'undisable string id' => ['modsec_undisable_rule', 'undisableRule', ['user.conf', '00123'], 'POST',
            'config=user.conf&id=00123', null];
        yield 'update' => ['modsec_update_vendor', 'updateVendor', ['https://vendor.test/meta'], 'POST',
            'url=https%3A%2F%2Fvendor.test%2Fmeta', '{"diagnostics":{"added_configs":["new.conf"],'
                . '"deleted_configs":["old.conf"],"new_configs":[' . $config
                . '],"prev_configs":[{"config":"old.conf"}]},'
                . '"vendor":{"archive_url":"https://vendor.test/rules.zip","cpanel_provided":0,"description":"vendor",'
                . '"dist_md5":"digest","enabled":1,"inst_dist":"vendor-2","installed":1,'
                . '"installed_from":"https://vendor.test/meta","name":"Vendor","path":"/rules",'
                . '"report_url":"https://vendor.test/report","vendor_id":"vendor",'
                . '"vendor_url":"https://vendor.test"}}'];
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testApiFailureIsNeverRetried(
        string $function,
        string $method,
        array $arguments,
        string $verb,
        string $wire,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, 'false', 0)));
        try {
            $client->whm()->modSecurity()->{$method}(...$arguments);
            self::fail('API failure must precede payload validation.');
        } catch (ApiErrorException $error) {
            self::assertSame(0, $error->metadata?->whmResult());
        }
        self::assertCount(1, $http->getRequests());
    }

    public function testSelectorSensitivityAndRequiredArguments(): void
    {
        [$client, $http] = self::client();
        $group = $client->whm()->modSecurity();
        self::assertSame($group, $client->whm()->modSecurity());
        self::assertSame([], $http->getRequests());
        self::assertNull($client->whm()->responseMeta());
        foreach (
            ['addRule' => 'rule', 'checkRule' => 'rule', 'editRule' => 'rule',
            'assembleConfigText' => 'text', 'setConfigText' => 'text', 'reportRule' => 'message',
            'batchSettings' => 'state', 'setSetting' => 'state'] as $method => $parameter
        ) {
            self::assertCount(1, (new ReflectionParameter([$group, $method], $parameter))
                ->getAttributes(SensitiveParameter::class));
        }
        foreach (
            ['addRule' => 2, 'addVendor' => 1, 'assembleConfigText' => 1, 'batchSettings' => 2,
            'checkRule' => 1, 'cloneRule' => 2, 'editRule' => 3, 'getRules' => 0, 'reportRule' => 5,
            'setSetting' => 2, 'disableRule' => 2, 'undisableRule' => 2] as $method => $count
        ) {
            self::assertSame($count, (new ReflectionMethod($group, $method))->getNumberOfRequiredParameters());
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('malformedPayloads')]
    public function testMalformedPayloadRetainsConfirmedOutcome(
        string $function,
        string $method,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        try {
            $client->whm()->modSecurity()->{$method}(...$arguments);
            self::fail('Malformed or missing payload must fail after confirmed API success.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame($client->whm()->responseMeta(), $error->metadata);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, ?string}> */
    public static function malformedPayloads(): iterable
    {
        $opaque = ['deployRuleChanges', 'deploySettingsChanges', 'disableVendor', 'disableVendorUpdates',
            'discardRuleChanges', 'enableVendor', 'enableVendorUpdates', 'makeConfigActive', 'makeConfigInactive',
            'removeRule', 'removeSetting', 'setConfigText'];
        foreach (self::operations() as [$function, $method, $arguments, , , $payload]) {
            if ($payload === null) {
                continue;
            }
            if (in_array($method, $opaque, true)) {
                continue;
            }
            yield "$function missing" => [$function, $method, $arguments, null];
            foreach (['null', 'false', '0', '1.5', '"text"'] as $bad) {
                yield "$function root $bad" => [$function, $method, $arguments, $bad];
            }
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            foreach (self::fieldPaths($decoded) as $path) {
                $dotted = implode('.', $path);
                if (
                    $method === 'addRule' && in_array($dotted, ['rule.config', 'rule.config_active', 'rule.staged',
                    'rule.vendor_active', 'rule.vendor_id'], true)
                ) {
                    continue;
                }
                $bad = self::replaceAt($decoded, $path, true);
                yield "$function leaf $dotted" => [$function, $method, $arguments,
                    json_encode($bad, JSON_THROW_ON_ERROR)];
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<array-key> $prefix
     * @return iterable<non-empty-list<array-key>>
     */
    private static function fieldPaths(array $value, array $prefix = []): iterable
    {
        foreach ($value as $key => $leaf) {
            $path = [...$prefix, $key];
            yield $path;
            if (is_array($leaf)) {
                yield from self::fieldPaths($leaf, $path);
            }
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('operations')]
    public function testOmissionEmptyObjectsAndUnconstrainedPayloads(
        string $function,
        string $method,
        array $arguments,
        string $verb,
        string $wire,
        ?string $fixture,
    ): void {
        [$client, $http] = self::client();
        $payload = $fixture === null ? null : '{}';
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        self::assertSame($fixture === null ? null : [], $client->whm()->modSecurity()->{$method}(...$arguments));
        self::assertCount(1, $http->getRequests());
    }

    public function testOptionalArgumentsAreOmittedAndExplicitZeroAndFalseSurvive(): void
    {
        [$client, $http] = self::client();
        $group = $client->whm()->modSecurity();
        $cases = [
            ['modsec_add_vendor', 'addVendor', ['https://vendor.test/meta'],
                'url=https%3A%2F%2Fvendor.test%2Fmeta', '{}'],
            ['modsec_assemble_config_text', 'assembleConfigText', ['user.conf'], 'config=user.conf', null],
            ['modsec_batch_settings', 'batchSettings', [0, ''], 'setting_id=0&state=', '{}'],
        ];
        foreach ($cases as [$function, $method, $arguments, $wire, $payload]) {
            $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
            $group->{$method}(...$arguments);
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame($wire, (string) $request->getBody());
        }
        $http->addResponse(new Response(200, [], self::envelope('modsec_get_rules', '{}')));
        $group->getRules(vendorId: 'vendor');
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(
            'https://api.example.test:2087/json-api/modsec_get_rules?api.version=1&vendor_id=vendor',
            (string) $request->getUri(),
        );
        self::assertCount(4, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function nullableFields(): iterable
    {
        yield 'log values' => ['getLog', '{"data":[{"handler":"handler","meta_logdata":"data","meta_rev":2,'
            . '"meta_severity":"future severity","meta_uri":"/uri"}]}'];
        yield 'report values' => ['reportRule', '{"report":[{"hits":[{"handler":"handler","meta_logdata":"data",'
            . '"meta_msg":null,"meta_rev":2,"meta_severity":"CRITICAL","meta_uri":"/uri"}]}]}'];
        yield 'settings validators optional fields' => ['getSettings', '{"settings":[{"default":0,"engine":1,'
            . '"missing":1,"radio_options":[],"validation":[{},"path",{"name":"endsWith","arg":"suffix"}]}]}'];
    }

    #[DataProvider('nullableFields')]
    public function testNullableAndUnionVariantsRemainDistinct(string $method, string $payload): void
    {
        [$client, $http] = self::client();
        $arguments = $method === 'reportRule' ? ['a@example.test', '', 1, false, 'freeform'] : [];
        $http->addResponse(new Response(200, [], self::envelope('synthetic', $payload)));
        self::assertSame(
            self::canonical(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)),
            self::canonical($client->whm()->modSecurity()->{$method}(...$arguments)),
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>, string|null}> */
    public static function unconstrainedPayloads(): iterable
    {
        $methods = ['deployRuleChanges', 'deploySettingsChanges', 'disableVendor', 'disableVendorUpdates',
            'discardRuleChanges', 'enableVendor', 'enableVendorUpdates', 'makeConfigActive', 'makeConfigInactive',
            'removeRule', 'removeSetting', 'setConfigText'];
        $payloads = [null, 'null', 'false', '0', '1.5', '"text"', '[]', '{}', '{"opaque":{"nested":null,"zero":0}}'];
        foreach (self::operations() as [$function, $method, $arguments]) {
            if (!in_array($method, $methods, true)) {
                continue;
            }
            foreach ($payloads as $i => $payload) {
                yield "$function $i" => [$function, $method, $arguments, $payload];
            }
        }
    }

    /** @param array<array-key, mixed> $arguments */
    #[DataProvider('unconstrainedPayloads')]
    public function testUnconstrainedSourceDataStaysMixed(
        string $function,
        string $method,
        array $arguments,
        ?string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        self::assertSame(
            $payload === null ? null : json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $client->whm()->modSecurity()->{$method}(...$arguments),
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedFlags(): iterable
    {
        foreach (['"0"', '"1"', 'true', 'false', '1.0', '0.0', '2', '-1', 'null', '[]', '{}'] as $bad) {
            yield $bad => [$bad];
        }
    }

    #[DataProvider('malformedFlags')]
    public function testResponseFlagsStayStrictIntegers(string $bad): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('modsec_check_rule', '{"valid":' . $bad . '}')));
        $this->expectException(PayloadMismatchException::class);
        try {
            $client->whm()->modSecurity()->checkRule('SecAction pass');
        } finally {
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        }
    }

    public function testEmptyLogWrapperIsPreservedAfterSuccessfulRead(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('modsec_get_log', '{"data":[]}')));
        self::assertSame(['data' => []], $client->whm()->modSecurity()->getLog());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertCount(1, $http->getRequests());
    }

    public function testWrappedLogPreservesAllTwentyThreeFieldsAndNullableHitLeaves(): void
    {
        [$client, $http] = self::client();
        $row = self::logRow();
        self::assertCount(23, $row);
        $ordinary = array_replace($row, ['meta_msg' => 'synthetic message', 'meta_offset' => 0]);
        $omitted = $row;
        unset($omitted['meta_msg'], $omitted['meta_offset']);
        $expected = ['data' => [$row, $ordinary, $omitted]];
        $http->addResponse(new Response(200, [], self::envelope(
            'modsec_get_log',
            json_encode($expected, JSON_THROW_ON_ERROR),
        )));
        self::assertSame(self::canonical($expected), self::canonical($client->whm()->modSecurity()->getLog()));
        self::assertCount(1, $http->getRequests());
    }

    public function testReportHitsPreserveNullableOffsetAlongsideNullableMessage(): void
    {
        [$client, $http] = self::client();
        $hit = self::logRow();
        unset($hit['file_exists'], $hit['reportable']);
        $hit['timezone'] = '-60';
        $expected = ['report' => [['hits' => [$hit], 'rule_text' => 'synthetic rule']]];
        $http->addResponse(new Response(200, [], self::envelope(
            'modsec_report_rule',
            json_encode($expected, JSON_THROW_ON_ERROR),
        )));
        self::assertSame(
            self::canonical($expected),
            self::canonical($client->whm()->modSecurity()->reportRule('a@example.test', '', 1, false, 'freeform')),
        );
        self::assertCount(1, $http->getRequests());
    }

    /** @return array<string, mixed> */
    private static function logRow(): array
    {
        return [
            'action_desc' => 'synthetic denial', 'file_exists' => 1, 'handler' => null, 'host' => 'example.test',
            'http_method' => 'GET', 'http_status' => 406, 'http_version' => 'HTTP/1.1', 'id' => 123,
            'ip' => '192.0.2.1', 'justification' => 'synthetic match', 'meta_file' => '/synthetic.conf',
            'meta_id' => 456, 'meta_line' => 2, 'meta_logdata' => null, 'meta_msg' => null, 'meta_offset' => null,
            'meta_rev' => null, 'meta_severity' => null, 'meta_uri' => null, 'path' => '/synthetic',
            'reportable' => 0, 'timestamp' => '2026-10-30 12:00:00', 'timezone' => -60,
        ];
    }

    #[DataProvider('reviewedPayloadBoundaries')]
    public function testReviewedShapesRejectInvalidRootsAndUnrelatedFieldTypes(
        string $function,
        string $method,
        string $payload,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($function, $payload)));
        $arguments = match ($method) {
            'setSetting', 'batchSettings' => [0, 'On'],
            'reportRule' => ['a@example.test', '', 1, false, 'freeform'],
            default => [],
        };
        try {
            $client->whm()->modSecurity()->{$method}(...$arguments);
            self::fail('Only reviewed container and leaf corrections may change validation.');
        } catch (PayloadMismatchException $error) {
            self::assertTrue($error->outcomeConfirmed);
            self::assertSame(1, $error->metadata?->whmResult());
            self::assertSame(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), $error->payload);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function reviewedPayloadBoundaries(): iterable
    {
        foreach (['[{}]', '{"data":null}', '{"data":{}}', '{"data":false}', '{"data":[1]}'] as $payload) {
            yield "log root $payload" => ['modsec_get_log', 'getLog', $payload];
        }
        $badRows = [
            '{"meta_msg":0}', '{"meta_offset":"0"}', '{"meta_offset":1.5}',
            '{"meta_offset":false}', '{"meta_offset":{}}', '{"http_status":"406"}',
            '{"meta_id":null}', '{"handler":true}', '{"host":null}', '{"id":"123"}',
        ];
        foreach ($badRows as $i => $row) {
            yield "log field $i" => ['modsec_get_log', 'getLog', '{"data":[' . $row . ']}'];
            yield "report field $i" => ['modsec_report_rule', 'reportRule', '{"report":[{"hits":[' . $row . ']}]}'];
        }
        yield 'log timezone stays integer' => ['modsec_get_log', 'getLog', '{"data":[{"timezone":"-60"}]}'];
        yield 'report timezone stays string' =>
            ['modsec_report_rule', 'reportRule', '{"report":[{"hits":[{"timezone":-60}]}]}'];
        foreach (['default', 'state'] as $field) {
            foreach (['null', 'true', 'false', '1.5', '[]', '{}'] as $bad) {
                $row = '{"' . $field . '":' . $bad . '}';
                yield "get $field $bad" => ['modsec_get_settings', 'getSettings', '{"settings":[' . $row . ']}'];
                yield "set $field $bad" => ['modsec_set_setting', 'setSetting', $row];
                if ($field === 'default') {
                    yield "batch $field $bad" =>
                        ['modsec_batch_settings', 'batchSettings', '{"updated_settings":[' . $row . ']}'];
                }
            }
        }
        foreach (['engine' => '"1"', 'missing' => 'true', 'setting_id' => '"2"', 'name' => 'null'] as $field => $bad) {
            $row = '{"' . $field . '":' . $bad . '}';
            yield "get strict $field" => ['modsec_get_settings', 'getSettings', '{"settings":[' . $row . ']}'];
            yield "set strict $field" => ['modsec_set_setting', 'setSetting', $row];
        }
        foreach (['arg', 'name'] as $field) {
            foreach (['null', '0', 'true', '[]', '{}'] as $bad) {
                $row = '{"validation":[{"' . $field . '":' . $bad . '}]}';
                yield "get validator $field $bad" =>
                    ['modsec_get_settings', 'getSettings', '{"settings":[' . $row . ']}'];
                yield "set validator $field $bad" => ['modsec_set_setting', 'setSetting', $row];
            }
        }
        yield 'batch state remains string' =>
            ['modsec_batch_settings', 'batchSettings', '{"updated_settings":[{"state":1}]}'];
        yield 'batch engine remains flag' =>
            ['modsec_batch_settings', 'batchSettings', '{"updated_settings":[{"engine":2}]}'];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('sharedSettingRows')]
    public function testGetAndSetSettingsPreserveTheSameCompleteRecord(array $row): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(
            'modsec_get_settings',
            json_encode(['settings' => [$row]], JSON_THROW_ON_ERROR),
        )));
        $http->addResponse(new Response(200, [], self::envelope(
            'modsec_set_setting',
            json_encode($row, JSON_THROW_ON_ERROR),
        )));
        $group = $client->whm()->modSecurity();
        self::assertSame(self::canonical(['settings' => [$row]]), self::canonical($group->getSettings()));
        self::assertSame(self::canonical($row), self::canonical($group->setSetting(2, 'On')));
        self::assertCount(2, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('setting_id=2&state=On', (string) $request->getBody());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function sharedSettingRows(): iterable
    {
        yield 'full engine record with typed validators' => [[
            'default' => 'Off', 'description' => 'Synthetic description', 'directive' => 'SecRuleEngine',
            'engine' => 1, 'missing' => 1, 'name' => 'Engine',
            'radio_options' => [['name' => 'Off label', 'option' => 'Off'], ['name' => 'On label', 'option' => 'On']],
            'setting_id' => 2, 'state' => 'On', 'type' => 'radio', 'url' => 'https://reference.test/engine',
            'validation' => [['arg' => 'prefix', 'name' => 'startsWith'], 'path', [], 'positiveInteger'],
        ]];
        yield 'only formerly discarded fields' => [['engine' => 1, 'missing' => 1]];
        yield 'empty record' => [[]];
        foreach (['default', 'state'] as $field) {
            foreach ([0, 1500, PHP_INT_MAX, '', 'Off', 'On', '001500', '123456789012345678901'] as $i => $value) {
                yield "$field $i" => [[$field => $value]];
            }
        }
    }

    #[DataProvider('batchDefaults')]
    public function testBatchDefaultPreservesStringsAndIntegers(string|int $default): void
    {
        [$client, $http] = self::client();
        $expected = ['updated_settings' => [['default' => $default]]];
        $http->addResponse(new Response(200, [], self::envelope(
            'modsec_batch_settings',
            json_encode($expected, JSON_THROW_ON_ERROR),
        )));
        self::assertSame($expected, $client->whm()->modSecurity()->batchSettings(2, 'On'));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string|int}> */
    public static function batchDefaults(): iterable
    {
        foreach (['Off', 'On', '', '001500', '123456789012345678901', 0, 1500, PHP_INT_MAX] as $i => $value) {
            yield (string) $i => [$value];
        }
    }

    public function testCloneRuleIntegerIdChainsIntoDisableAndUndisableWithoutCasting(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('modsec_clone_rule', '{"rule":{"id":123}}')));
        $http->addResponse(new Response(200, [], self::envelope('modsec_disable_rule', null)));
        $http->addResponse(new Response(200, [], self::envelope('modsec_undisable_rule', null)));
        $group = $client->whm()->modSecurity();
        $id = $group->cloneRule('user.conf', 7)['rule']['id'] ?? null;
        self::assertSame(123, $id);
        $group->disableRule('user.conf', $id);
        $group->undisableRule('user.conf', $id);
        self::assertCount(3, $http->getRequests());
        foreach (array_slice($http->getRequests(), 1) as $request) {
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('config=user.conf&id=123', (string) $request->getBody());
            self::assertSame('POST', $request->getMethod());
        }
    }

    #[DataProvider('ruleIds')]
    public function testRuleIdsPreserveNativeIntegersAndExactStrings(string $method, string|int $id, string $wire): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('synthetic', null)));
        $client->whm()->modSecurity()->{$method}('user.conf', $id);
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('config=user.conf&id=' . $wire, (string) $request->getBody());
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string|int, string}> */
    public static function ruleIds(): iterable
    {
        $ids = [
            [123, '123'], [PHP_INT_MAX, (string) PHP_INT_MAX], ['00123', '00123'],
            ['123456789012345678901', '123456789012345678901'],
        ];
        foreach (['disableRule', 'undisableRule'] as $method) {
            foreach ($ids as $i => [$id, $wire]) {
                yield "$method $i" => [$method, $id, $wire];
            }
        }
    }

    #[DataProvider('invalidRuleIds')]
    public function testRuleIdWideningRejectsOtherTypesBeforeIo(string $method, mixed $id): void
    {
        [$client, $http] = self::client();
        $this->expectException(TypeError::class);
        try {
            $client->whm()->modSecurity()->{$method}('user.conf', $id);
        } finally {
            self::assertSame([], $http->getRequests());
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidRuleIds(): iterable
    {
        foreach (['disableRule', 'undisableRule'] as $method) {
            foreach ([null, true, false, 1.5, []] as $i => $id) {
                yield "$method $i" => [$method, $id];
            }
        }
    }

    public function testPendingConfigurationIdleOutcomeRetainsFlagWithoutMessageMatching(): void
    {
        foreach (['No rule changes are pending.', 'Synthetic permission failure'] as $reason) {
            [$client, $http] = self::client();
            $envelope = '{"metadata":{"result":0,"reason":' . json_encode($reason, JSON_THROW_ON_ERROR) . '}}';
            $http->addResponse(new Response(200, [], $envelope));
            try {
                $client->whm()->modSecurity()->getConfigsWithChangesPending();
                self::fail('Idle and other failed outcomes must remain ApiErrorException.');
            } catch (ApiErrorException $error) {
                self::assertSame(0, $error->metadata?->whmResult());
                self::assertSame([$reason], $error->errors());
            }
            self::assertCount(1, $http->getRequests());
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param non-empty-list<array-key> $path
     * @return array<array-key, mixed>
     */
    private static function replaceAt(array $payload, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($path === []) {
            $payload[$key] = $value;
        } else {
            self::assertIsArray($payload[$key]);
            $payload[$key] = self::replaceAt($payload[$key], $path, $value);
        }
        return $payload;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        return $value;
    }

    private static function envelope(string $function, ?string $payload, int $result = 1): string
    {
        return '{"metadata":{"command":"' . $function . '","result":' . $result . ',"reason":"Synthetic"}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(['host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root',
            'token' => 'fixture-token'], new HttpTransport($http, $factory, $factory)), $http];
    }
}
