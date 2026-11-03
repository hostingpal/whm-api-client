<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\LangPhpSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SslSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\AccountsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\SystemPackagesSpec;
use HostingPal\WhmApiClient\Client as ApiClient;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\CaptureClient;
use HostingPal\WhmApiClient\Tools\LiveVerify\DiscrepancyPolicy;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @phpstan-import-type Context from EvidenceRunner */
final class EvidenceRunnerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @return iterable<string, array{string|null, bool, string, list<array{path: string, kind: string}>}> */
    public static function optionalPayloadResponses(): iterable
    {
        yield 'optional absent' => [null, true, 'passed', []];
        yield 'optional explicit null' => ['null', true, 'passed', []];
        yield 'optional present invalid' => ['{"rpms":[1]}', true, 'failed',
            [['path' => '$.rpms[]', 'kind' => 'type_mismatch']]];
        yield 'required absent' => [null, false, 'failed', [['path' => '$', 'kind' => 'missing_required']]];
    }

    /** @param list<array{path: string, kind: string}> $discrepancies */
    #[DataProvider('optionalPayloadResponses')]
    public function testEffectiveOptionalPayloadContractFlowsIntoStrictEvidence(
        ?string $payload,
        bool $optional,
        string $outcome,
        array $discrepancies,
    ): void {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}'));
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}'));
        $spec = $optional ? SystemPackagesSpec::LIST_RPMS : SystemPackagesSpec::PACKAGE_MANAGER_LIST_PACKAGES;
        $operation = $optional ? 'list_rpms' : 'package_manager_list_packages';
        $probe = new Probe(
            'whm1:' . $operation,
            'whm',
            $spec,
            'https://test.oblak.host:2087/json-api/' . $operation . '?api.version=1',
            static fn (ApiClient $client): mixed => $optional
                ? $client->whm()->systemPackages()->listRpms()
            : $client->whm()->systemPackages()->listPackages(),
        );
        $version = new Probe(
            'whm1:version',
            'whm',
            ServerSpec::VERSION,
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            static fn (ApiClient $client): mixed => $client->whm()->server()->version(),
        );
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            [$version, $probe],
            'optional-payload-test',
            '1',
            ['whm' => 'private-whm', 'cpanel' => 'private-cpanel'],
        );
        $record = $report['probes'][1];
        self::assertCount(2, $http->getRequests());
        self::assertSame($outcome, $record['probe_outcome']);
        self::assertSame($outcome, $record['payload_validation']);
        self::assertSame($discrepancies, $record['discrepancies']);
        self::assertSame($outcome, $report['status']);
        self::assertSame(
            hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)),
            $record['effective_contract_sha256'],
        );
        self::assertSame(self::context()['revision'], $record['revision']);
        if ($payload === null) {
            self::assertSame(['missing'], $record['payload_paths']['$']);
        } elseif ($payload === 'null') {
            self::assertSame(['null'], $record['payload_paths']['$']);
        }
    }

    public function testFailedRootUnionAddsCheckedAlternativeDiagnosticsWithoutReplayOrUnmasking(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"status":1,"data":[{'
            . '"php_fpm":"private-value",'
            . '"private-key":{"phpversion_source":"private-value"}}]}'));
        $probes = array_values(array_filter(
            ProbeCatalog::load(self::ROOT),
            static fn (Probe $probe): bool => $probe->operation === 'uapi:LangPHP/php_get_vhost_versions'
                && $probe->route === 'direct-uapi',
        ));
        $records = (new EvidenceRunner($http, self::context()))->run(
            $probes,
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );
        self::assertCount(1, $http->getRequests());
        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame('failed', $record['probe_outcome']);
        self::assertSame('payload_mismatch', $record['failure_kind']);
        self::assertSame('failed', $record['payload_validation']);
        self::assertSame([['path' => '$', 'kind' => 'type_mismatch']], $record['discrepancies']);
        self::assertSame([
            '$' => ['list'], '$[]' => ['object'], '$[].*' => ['object', 'string'],
            '$[].*.*' => ['string'],
        ], $record['payload_paths']);
        self::assertArrayHasKey('payload_alternatives', $record);
        $alternatives = $record['payload_alternatives'] ?? [];
        self::assertCount(2, $alternatives);
        [$list, $object] = $alternatives;
        self::assertSame(0, $list['index']);
        self::assertSame(1, $object['index']);
        foreach ($alternatives as $index => $alternative) {
            self::assertSame(hash('sha256', json_encode(
                LangPhpSpec::PHP_GET_VHOST_VERSIONS['returns']['anyOf'][$index],
                JSON_THROW_ON_ERROR,
            )), $alternative['sha256']);
        }
        self::assertSame([
            ['path' => '$[].*', 'kind' => 'unknown_field'],
            ['path' => '$[].php_fpm', 'kind' => 'type_mismatch'],
        ], $list['discrepancies']);
        self::assertSame(['string'], $list['paths']['$[].php_fpm']);
        self::assertSame(['string'], $list['paths']['$[].*.*']);
        self::assertArrayNotHasKey('$[].*.phpversion_source', $list['paths']);
        self::assertSame($record['payload_paths'], $object['paths']);
        self::assertSame([['path' => '$', 'kind' => 'type_mismatch']], $object['discrepancies']);
        self::assertStringNotContainsString('private-', json_encode($record, JSON_THROW_ON_ERROR));
        $policy = DiscrepancyPolicy::load(self::ROOT);
        $review = $policy->assess([$record]);
        self::assertSame('failed', $review['status']);
        unset($record['payload_alternatives']);
        self::assertSame($review, $policy->assess([$record]));
    }

    public function testSuccessfulRootUnionDoesNotAddCandidateDiagnostics(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"status":1,"data":[]}'));
        $probes = array_values(array_filter(
            ProbeCatalog::load(self::ROOT),
            static fn (Probe $probe): bool => $probe->operation === 'uapi:LangPHP/php_get_vhost_versions'
                && $probe->route === 'direct-uapi',
        ));
        $record = (new EvidenceRunner($http, self::context()))->run(
            $probes,
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        )[0];
        self::assertCount(1, $http->getRequests());
        self::assertSame('passed', $record['probe_outcome']);
        self::assertArrayNotHasKey('payload_alternatives', $record);
    }

    #[DataProvider('mutationResponses')]
    public function testLifecycleCaptureKeepsOriginalPostOutcomeWithoutReplay(
        string $body,
        string $outcome,
        ?string $failure,
    ): void {
        $http = new Client();
        $http->addResponse(new Response(200, [], $body));
        $capture = new CaptureClient($http);
        $factory = new Psr17Factory();
        $client = new ApiClient([
            'host' => 'test.oblak.host', 'authType' => 'whm', 'username' => 'root', 'token' => 'private-token',
        ], new HttpTransport($capture, $factory, $factory));
        $probe = new Probe(
            'whm1:passwd',
            'whm',
            AccountsSpec::PASSWD,
            'https://test.oblak.host:2087/json-api/passwd?api.version=1',
            static fn (ApiClient $api): mixed =>
                $api->whm()->accounts()->changePassword(user: 'private-user', password: 'private-password'),
        );
        $record = (new EvidenceRunner($http, self::context()))->capture($probe, $capture, $client);

        self::assertCount(1, $http->getRequests());
        self::assertSame('POST', $record['method']);
        self::assertSame('whm1:passwd#GET', $record['variant']);
        self::assertSame(200, $record['http_status']);
        self::assertSame($outcome, $record['api_outcome']);
        self::assertSame($failure, $record['failure_kind']);
        self::assertSame($failure === null ? 'passed' : 'failed', $record['probe_outcome']);
        self::assertSame($body, $capture->rawBody());
        if ($failure === 'payload_mismatch') {
            self::assertSame('failed', $record['payload_validation']);
            self::assertSame([['path' => '$', 'kind' => 'type_mismatch']], $record['discrepancies']);
        }
        $privateValues = ['private-token', 'private-user', 'private-password', 'private-error', 'private-payload'];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, json_encode($record, JSON_THROW_ON_ERROR));
        }

        $localFailure = new Probe(
            $probe->operation,
            $probe->route,
            $probe->spec,
            $probe->uri,
            static fn (ApiClient $api): mixed => throw new RuntimeException('private-error'),
        );
        $failed = (new EvidenceRunner($http, self::context()))->capture($localFailure, $capture, $client);
        self::assertCount(1, $http->getRequests());
        self::assertNull($failed['method']);
        self::assertNull($failed['http_status']);
        self::assertSame([], $failed['response_paths']);
        self::assertSame([], $failed['payload_paths']);
        self::assertSame('probe_failure', $failed['failure_kind']);
    }

    public function testExplicitPostSourceVariantSurvivesFailureBeforeAnyRequest(): void
    {
        $http = new Client();
        $capture = new CaptureClient($http);
        $factory = new Psr17Factory();
        $client = new ApiClient([
            'host' => 'test.oblak.host', 'authType' => 'cpanel', 'username' => 'npr', 'token' => 'private-token',
        ], new HttpTransport($capture, $factory, $factory));
        $probe = new Probe(
            'uapi:SSL/upload_key',
            'direct-uapi',
            SslSpec::UPLOAD_KEY,
            'https://test.oblak.host:2083/execute/SSL/upload_key',
            static fn (ApiClient $api): mixed => throw new RuntimeException('fixture failure'),
            sourceVariant: 'uapi:SSL/upload_key#POST',
        );
        $record = (new EvidenceRunner($http, self::context()))->capture($probe, $capture, $client);
        self::assertSame('uapi:SSL/upload_key#POST', $record['variant']);
        self::assertNull($record['method']);
        self::assertSame([], $http->getRequests());
    }

    public function testSourceVariantCannotNameAnotherOperation(): void
    {
        $this->expectException(LogicException::class);
        new Probe(
            'uapi:SSL/upload_key',
            'direct-uapi',
            SslSpec::UPLOAD_KEY,
            'https://test.oblak.host:2083/execute/SSL/upload_key',
            static fn (ApiClient $api): mixed => null,
            sourceVariant: 'uapi:SSL/upload_cert#POST',
        );
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function mutationResponses(): iterable
    {
        yield 'success' => ['{"metadata":{"result":1},"data":null}', 'success', null];
        yield 'accepted mutation with malformed payload' => [
            '{"metadata":{"result":1},"data":"private-payload"}', 'success', 'payload_mismatch',
        ];
        yield 'api failure' => [
            '{"metadata":{"result":0,"reason":"private-error"}}', 'failure', 'api_failure',
        ];
    }

    public function testExplicitDirectUsernameBindsOnlyDirectAuthentication(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"status":1,"data":{}}'));
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{}}}}'));

        $records = (new EvidenceRunner($http, self::context(), 'wqfinite'))->run(
            array_slice(ProbeCatalog::load(self::ROOT), 2, 2),
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );

        self::assertSame(['passed', 'passed'], array_column($records, 'probe_outcome'));
        self::assertSame('cpanel wqfinite:synthetic-cpanel', $http->getRequests()[0]->getHeaderLine('Authorization'));
        self::assertSame('whm root:synthetic-whm', $http->getRequests()[1]->getHeaderLine('Authorization'));
        self::assertStringContainsString('cpanel.user=npr&', (string) $http->getRequests()[1]->getUri());
    }

    public function testReviewedProbesUseGeneratedClientAndRawSameResponse(): void
    {
        $http = new Client();
        foreach (
            [
            '{"metadata":{"result":1},"data":{"version":"11.138.0.15"}}',
            '{"metadata":{"result":1},"data":{"one":"0.50","private@example.test":"secret"}}',
            '{"status":1,"data":{"main_domain":"example.test","sub_domains":[]}}',
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{"main_domain":"example.test"}}}}',
                '{"status":1,"data":[{"email":"mailbox@example.test","suspended_login":0}]}',
                '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":[]}}}',
            ] as $body
        ) {
            $http->addResponse(new Response(200, [], $body));
        }
        $records = (new EvidenceRunner($http, self::context()))->run(
            array_slice(ProbeCatalog::load(self::ROOT), 0, 6),
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );
        self::assertCount(6, $http->getRequests());
        self::assertSame(
            ['whm', 'whm', 'direct-uapi', 'bridged-uapi', 'direct-uapi', 'bridged-uapi'],
            array_column($records, 'route'),
        );
        self::assertSame(array_fill(0, 6, 'passed'), array_column($records, 'probe_outcome'));
        self::assertSame(['string'], $records[4]['payload_paths']['$[].email']);
        self::assertSame(['empty-list'], $records[5]['payload_paths']['$']);
        self::assertArrayNotHasKey('$[].email', $records[5]['payload_paths']);
        self::assertSame(['numeric-string'], $records[1]['payload_paths']['$.one']);
        self::assertSame([['path' => '$.*', 'kind' => 'unknown_field']], $records[1]['discrepancies']);
        self::assertSame(['empty-list'], $records[2]['payload_paths']['$.sub_domains']);
        self::assertArrayNotHasKey('$.sub_domains[]', $records[2]['payload_paths']);
        $versions = array_values(array_unique(array_column($records, 'server_version')));
        self::assertSame(['11.138.0.15'], $versions);
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        $privateValues = [
            'private@example.test', 'example.test', 'secret', 'synthetic-whm', 'synthetic-cpanel', '0.50',
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testInventoryProbesCaptureReviewedArgumentsAndRawPayloadShapes(): void
    {
        $http = new Client();
        $bodies = [
            '{"metadata":{"result":1},"data":{"acct":[{"user":"private-user-marker",'
                . '"domain":"private-domain.test","uid":"12345","suspendtime":0,"child_nodes":[]}]}}',
            '{"metadata":{"result":1},"data":{"acct":[{"user":"private-user-marker",'
                . '"domain":"private-domain.test","uid":"12345","suspendreason":null,"standalone_feature":""}]}}',
            '{"metadata":{"result":1},"data":{"domains":[{"domain":"private-domain.test",'
                . '"user":"private-user-marker","port":"80","port_ssl":"443","ipv6":null}]}}',
            '{"metadata":{"result":1},"data":{"versions":["ea-php82","ea-php83"]}}',
            '{"metadata":{"result":1},"data":{"version":"ea-php82"}}',
            '{"result":{"status":1,"data":{"primary_domain":"private-domain.test"}}}',
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{"primary_domain":null}}}}',
            '{"status":1,"data":{"versions":["ea-php82"]}}',
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{"versions":[]}}}}',
            '{"status":1,"data":{"php_handler":"suphp"}}',
            '{"metadata":{"result":1},"data":{"uapi":{"status":1,"data":{"php_handler":"suphp"}}}}',
        ];
        foreach ($bodies as $body) {
            $http->addResponse(new Response(200, [], $body));
        }
        $records = (new EvidenceRunner($http, self::context()))->run(
            array_slice(ProbeCatalog::load(self::ROOT), 6, 11),
            '1',
            ['whm' => 'synthetic-whm', 'cpanel' => 'synthetic-cpanel'],
        );

        $requests = $http->getRequests();
        self::assertCount(11, $requests);
        $expectedUris = [
            'https://test.oblak.host:2087/json-api/listaccts?api.version=1'
                . '&search=npr&searchtype=user&searchmethod=exact',
            'https://test.oblak.host:2087/json-api/accountsummary?api.version=1&user=npr',
            'https://test.oblak.host:2087/json-api/get_domain_info?api.version=1',
            'https://test.oblak.host:2087/json-api/php_get_installed_versions?api.version=1',
            'https://test.oblak.host:2087/json-api/php_get_system_default_version?api.version=1',
            'https://test.oblak.host:2083/execute/DomainInfo/primary_domain',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=DomainInfo&cpanel.function=primary_domain',
            'https://test.oblak.host:2083/execute/LangPHP/php_get_installed_versions',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_installed_versions',
            'https://test.oblak.host:2083/execute/LangPHP/php_get_domain_handler?type=home',
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_domain_handler&type=home',
        ];
        foreach ($requests as $index => $request) {
            self::assertSame($expectedUris[$index], (string) $request->getUri());
            self::assertSame('GET', $request->getMethod());
            self::assertSame('', (string) $request->getBody());
        }
        self::assertSame(array_fill(0, 11, 'passed'), array_column($records, 'probe_outcome'));
        self::assertSame(array_fill(0, 11, []), array_column($records, 'discrepancies'));
        self::assertSame([
            'whm1:listaccts', 'whm1:accountsummary', 'whm1:get_domain_info',
            'whm1:php_get_installed_versions', 'whm1:php_get_system_default_version',
            'uapi:DomainInfo/primary_domain', 'uapi:DomainInfo/primary_domain',
            'uapi:LangPHP/php_get_installed_versions', 'uapi:LangPHP/php_get_installed_versions',
            'uapi:LangPHP/php_get_domain_handler', 'uapi:LangPHP/php_get_domain_handler',
        ], array_column($records, 'operation'));
        self::assertSame(['numeric-string'], $records[0]['payload_paths']['$.acct[].uid']);
        self::assertSame(['int'], $records[0]['payload_paths']['$.acct[].suspendtime']);
        self::assertSame(['empty-list'], $records[0]['payload_paths']['$.acct[].child_nodes']);
        self::assertArrayNotHasKey('$.acct[].child_nodes[]', $records[0]['payload_paths']);
        self::assertSame(['null'], $records[1]['payload_paths']['$.acct[].suspendreason']);
        self::assertSame(['empty-string'], $records[1]['payload_paths']['$.acct[].standalone_feature']);
        self::assertSame(['numeric-string'], $records[2]['payload_paths']['$.domains[].port']);
        self::assertSame(['numeric-string'], $records[2]['payload_paths']['$.domains[].port_ssl']);
        self::assertSame(['null'], $records[2]['payload_paths']['$.domains[].ipv6']);
        self::assertSame(['string'], $records[3]['payload_paths']['$.versions[]']);
        self::assertSame(['string'], $records[4]['payload_paths']['$.version']);
        self::assertSame(['string'], $records[5]['payload_paths']['$.primary_domain']);
        self::assertSame(['null'], $records[6]['payload_paths']['$.primary_domain']);
        self::assertSame(['string'], $records[7]['payload_paths']['$.versions[]']);
        self::assertSame(['empty-list'], $records[8]['payload_paths']['$.versions']);
        self::assertArrayNotHasKey('$.versions[]', $records[8]['payload_paths']);
        self::assertSame(['string'], $records[9]['payload_paths']['$.php_handler']);
        self::assertSame(['string'], $records[10]['payload_paths']['$.php_handler']);
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        foreach (['private-user-marker', 'private-domain.test', '12345', 'ea-php82', 'suphp'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testPayloadFailureIsSeparateFromApiOutcome(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":7}}'));
        $records = (new EvidenceRunner($http, self::context()))->run(
            [ProbeCatalog::load(self::ROOT)[0]],
            '1',
            ['whm' => 'synthetic', 'cpanel' => 'synthetic'],
        );
        self::assertSame('success', $records[0]['api_outcome']);
        self::assertSame('failed', $records[0]['payload_validation']);
        self::assertSame('failed', $records[0]['probe_outcome']);
        self::assertSame([['path' => '$.version', 'kind' => 'type_mismatch']], $records[0]['discrepancies']);
        self::assertCount(1, $http->getRequests());
    }

    public function testApiFailureDoesNotLeakUpstreamErrors(): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"private error token"}}'));
        $records = (new EvidenceRunner($http, self::context()))->run(
            [ProbeCatalog::load(self::ROOT)[0]],
            '1',
            ['whm' => 'synthetic', 'cpanel' => 'synthetic'],
        );
        self::assertSame('failure', $records[0]['api_outcome']);
        self::assertSame('not_checked', $records[0]['payload_validation']);
        self::assertSame([], $records[0]['discrepancies']);
        self::assertStringNotContainsString('private error token', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function testOptInFailsBeforeAnyRequest(): void
    {
        $http = new Client();
        $caught = null;
        try {
            (new EvidenceRunner($http, self::context()))->run(
                ProbeCatalog::load(self::ROOT),
                false,
                ['whm' => 'synthetic', 'cpanel' => 'synthetic'],
            );
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $caught, 'Missing opt-in must fail.');
        self::assertCount(0, $http->getRequests());
    }

    /** @param array{api: string, payload: string, probe: string} $expected */
    #[DataProvider('uapiEnvelopes')]
    public function testEnvelopeOutcomesRemainRouteScoped(string $body, int $index, array $expected): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], $body));
        $records = (new EvidenceRunner($http, self::context()))->run(
            [ProbeCatalog::load(self::ROOT)[$index]],
            '1',
            ['whm' => 'synthetic', 'cpanel' => 'synthetic'],
        );
        self::assertSame($expected['api'], $records[0]['api_outcome']);
        self::assertSame($expected['payload'], $records[0]['payload_validation']);
        self::assertSame($expected['probe'], $records[0]['probe_outcome']);
        if ($expected['api'] !== 'success') {
            self::assertSame([], $records[0]['discrepancies']);
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, int, array{api: string, payload: string, probe: string}}> */
    public static function uapiEnvelopes(): iterable
    {
        yield 'wrapped direct' => [
            '{"result":{"status":1,"data":{"main_domain":"example.test"}}}', 2,
            ['api' => 'success', 'payload' => 'passed', 'probe' => 'passed'],
        ];
        yield 'present malformed wrapper wins' => [
            '{"result":null,"status":1,"data":{}}', 2,
            ['api' => 'unavailable', 'payload' => 'not_checked', 'probe' => 'failed'],
        ];
        yield 'bridge outer failure wins' => [
            '{"metadata":{"result":0},"data":{"uapi":{"status":1,"data":{}}}}', 3,
            ['api' => 'failure', 'payload' => 'not_checked', 'probe' => 'failed'],
        ];
        yield 'bridge inner failure' => [
            '{"metadata":{"result":1},"data":{"uapi":{"status":0,"data":{}}}}', 3,
            ['api' => 'failure', 'payload' => 'not_checked', 'probe' => 'failed'],
        ];
        yield 'missing payload' => [
            '{"status":1}', 2,
            ['api' => 'success', 'payload' => 'failed', 'probe' => 'failed'],
        ];
        yield 'invalid json' => [
            'private not JSON', 2,
            ['api' => 'unavailable', 'payload' => 'not_checked', 'probe' => 'failed'],
        ];
    }

    /** @return Context */
    private static function context(): array
    {
        return [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
    }
}
