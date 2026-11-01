<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\EmptyResultProbes;
use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ReadOnlySectionRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\RequestGuard;
use Http\Mock\Client as MockClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The literal request table below is the reviewed allowlist; it never reads the catalog's constants.
 *
 * @phpstan-import-type Context from EvidenceRunner
 */
final class EmptyResultProbesTest extends TestCase
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    private const BRIDGED = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr';

    public function testCatalogIsTheReviewedBoundedAllowlist(): void
    {
        $probes = EmptyResultProbes::load();
        $expected = self::allowlist();
        self::assertCount(27, $probes);
        self::assertCount(count($expected), $probes);
        foreach ($probes as $index => $probe) {
            [$operation, $route, $variant, $uri] = $expected[$index];
            self::assertSame($operation, $probe->operation);
            self::assertSame($route, $probe->route);
            self::assertSame($variant, $probe->argumentVariant);
            self::assertSame($uri, $probe->uri);
            self::assertSame('GET', $probe->spec['method']);
            self::assertSame($operation . '#GET', $probe->sourceVariant ?? $operation . '#GET');
            self::assertNull($probe->unavailable);
        }
        $audited = array_unique(array_map(static fn (Probe $probe): string => $probe->operation, $probes));
        self::assertCount(16, $audited, 'WHM version plus at most 15 audited operations.');
        self::assertSame('whm1:version', $probes[0]->operation);
    }

    public function testEveryProbeSendsItsExactGetOnceThroughTheGeneratedMethod(): void
    {
        $bodies = self::noMatchBodies();
        foreach (EmptyResultProbes::load() as $probe) {
            $direct = $probe->route === 'direct-uapi';
            $factory = new Psr17Factory();
            $http = new MockClient($factory);
            $http->addResponse(new Response(200, [], $bodies[self::key($probe)]));
            $client = new Client(
                ['host' => 'test.oblak.host', 'port' => $direct ? 2083 : 2087,
                'authType' => $direct ? 'cpanel' : 'whm', 'username' => $direct ? 'npr' : 'root',
                'token' => 'synthetic-token'],
                new HttpTransport(new RequestGuard($http, $probe->uri), $factory, $factory),
            );
            try {
                ($probe->invoke)($client);
                self::assertNotSame('whm1:accountsummary', $probe->operation);
            } catch (ApiErrorException) {
                self::assertSame('whm1:accountsummary', $probe->operation);
            }
            self::assertCount(1, $http->getRequests());
            $request = $http->getLastRequest();
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame($probe->uri, (string) $request->getUri());
            self::assertSame('GET', $request->getMethod());
            self::assertSame('', (string) $request->getBody());
            self::assertSame(
                $direct ? 'cpanel npr:synthetic-token' : 'whm root:synthetic-token',
                $request->getHeaderLine('Authorization'),
            );
        }
    }

    public function testRequestGuardRejectsChangedTargetsBeforeHttp(): void
    {
        foreach (EmptyResultProbes::load() as $probe) {
            $http = new MockClient(new Psr17Factory());
            $wrongRequests = [
                new Request('POST', $probe->uri),
                new Request('DELETE', $probe->uri),
                new Request('GET', $probe->uri, [], 'unexpected'),
                new Request('GET', $probe->uri . '&extra=1'),
                new Request('GET', str_replace('test.oblak.host', 'other.example', $probe->uri)),
                new Request('GET', str_replace('cpanel.user=npr', 'cpanel.user=other', $probe->uri) . '&x=1'),
                new Request('GET', str_replace('wzzemptyaudit', 'npr', $probe->uri) . '&x=1'),
            ];
            foreach ($wrongRequests as $wrong) {
                try {
                    (new RequestGuard($http, $probe->uri))->sendRequest($wrong);
                    self::fail('A changed recipe must be rejected before HTTP.');
                } catch (LogicException) {
                    self::assertSame([], $http->getRequests());
                }
            }
        }
    }

    public function testEntityControlFailsTheStrictAssessmentAndKeepsOnlySanitizedStructure(): void
    {
        $probes = EmptyResultProbes::load();
        $bodies = self::noMatchBodies();
        $http = new MockClient();
        foreach ($probes as $probe) {
            $http->addResponse(new Response(200, [], $bodies[self::key($probe)]));
        }
        $context = self::context();
        $report = (new ReadOnlySectionRunner($http, $context))->run(
            $probes,
            'empty-results-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );
        self::assertCount(27, $http->getRequests());
        self::assertSame('failed', $report['status']);
        self::assertFalse(ReadOnlySectionRunner::passes($probes, $report['probes'], $context));
        foreach ($report['probes'] as $index => $record) {
            self::assertSame($probes[$index]->operation, $record['operation']);
            self::assertSame($probes[$index]->argumentVariant, $record['argument_variant'] ?? null);
            self::assertSame('11.138.0.19', $record['server_version']);
            if ($record['operation'] === 'whm1:accountsummary') {
                self::assertSame('failed', $record['probe_outcome']);
                self::assertSame('api_failure', $record['failure_kind']);
                self::assertSame('failure', $record['api_outcome']);
                self::assertSame(['null'], $record['payload_paths']['$.acct'] ?? null);
            } else {
                self::assertSame('passed', $record['probe_outcome'], $record['operation']);
            }
        }
        $listaccts = $report['probes'][1];
        self::assertSame('fixed-path-control', $listaccts['argument_variant'] ?? null);
        self::assertSame(['missing'], $listaccts['payload_paths']['$'] ?? null);
        // The client's accepted absence form stays a visible evidence discrepancy, never a silent pass.
        self::assertSame([['path' => '$', 'kind' => 'missing_required']], $listaccts['discrepancies']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['private-whm-token', 'private-cpanel-token', 'wzzemptyaudit'] as $text) {
            self::assertStringNotContainsString($text, $json);
        }
        foreach (['No accounts found', 'does not exist'] as $text) {
            self::assertStringNotContainsString($text, $json);
        }
    }

    public function testAssessmentPassesOnlyCleanNoMatchRecordsWithExactLabels(): void
    {
        $controls = ['whm1:accountsummary', 'whm1:listaccts'];
        $probes = array_values(array_filter(
            EmptyResultProbes::load(),
            static fn (Probe $probe): bool => !in_array($probe->operation, $controls, true),
        ));
        $bodies = self::noMatchBodies();
        $http = new MockClient();
        foreach ($probes as $probe) {
            $http->addResponse(new Response(200, [], $bodies[self::key($probe)]));
        }
        $context = self::context();
        $report = (new ReadOnlySectionRunner($http, $context))->run(
            $probes,
            'empty-results-v1',
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
        );
        self::assertSame('passed', $report['status']);
        $records = $report['probes'];
        self::assertTrue(ReadOnlySectionRunner::passes($probes, $records, $context));
        $records[2]['argument_variant'] = 'other-variant';
        self::assertFalse(ReadOnlySectionRunner::passes($probes, $records, $context));
    }

    public function testCatalogRequiresExplicitOptInBeforeAnyRequest(): void
    {
        $http = new MockClient();
        try {
            (new ReadOnlySectionRunner($http, self::context()))->run(
                EmptyResultProbes::load(),
                'empty-results-v1',
                false,
                ['whm' => 'test-whm', 'cpanel' => 'test-cpanel'],
            );
            self::fail('No live opt-in must mean no request.');
        } catch (RuntimeException) {
            self::assertSame([], $http->getRequests());
        }
    }

    public function testEntrypointHelpAndArgumentRejectionNeedNoLiveCredentials(): void
    {
        [$exit, $stdout, $stderr] = self::execute(['--help']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('--output=PATH', $stdout);
        self::assertStringNotContainsString('MUTATIONS', $stdout);
        self::assertSame('', $stderr);
        [$exit, $stdout, $stderr] = self::execute(['--search=npr']);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertSame("Read-only section verification failed (configuration_failure).\n", $stderr);
    }

    /** @return list<array{string, string, string|null, string}> */
    private static function allowlist(): array
    {
        $direct = self::DIRECT;
        $bridged = self::BRIDGED;

        return [
            ['whm1:version', 'whm', null, self::WHM . 'version?api.version=1'],
            ['whm1:listaccts', 'whm', 'fixed-path-control', self::WHM
                . 'listaccts?api.version=1&search=wzzemptyaudit&searchtype=user&searchmethod=exact'],
            ['whm1:showbw', 'whm', 'absent-user-pcre', self::WHM
                . 'showbw?api.version=1&search=wzzemptyaudit&searchtype=user'],
            ['whm1:matchpkgs', 'whm', 'absent-feature-list', self::WHM
                . 'matchpkgs?api.version=1&FEATURELIST=wzzemptyaudit'],
            ['whm1:emailtrack_search', 'whm', 'all-event-types-off', self::WHM
                . 'emailtrack_search?api.version=1&defer=0&failure=0&inprogress=0&max_results_by_type=1&success=0'],
            ['whm1:emailtrack_user_stats', 'whm', 'absent-sender-past-window', self::WHM
                . 'emailtrack_user_stats?api.version=1&endtime=1000000001'
                . '&sender=nobody%40wzzemptyaudit.invalid&starttime=1000000000'],
            ['whm1:get_unique_sender_recipient_count_per_user', 'whm', 'past-window', self::WHM
                . 'get_unique_sender_recipient_count_per_user?api.version=1'
                . '&start_time=1000000000&end_time=1000000001'],
            ['whm1:php_get_vhosts_by_version', 'whm', 'absent-php-package', self::WHM
                . 'php_get_vhosts_by_version?api.version=1&version=ea-php00'],
            ['whm1:accountsummary', 'whm', 'entity-lookup-control', self::WHM
                . 'accountsummary?api.version=1&user=wzzemptyaudit'],
            ['uapi:Email/list_pops', 'direct-uapi', 'absent-regex', $direct
                . 'Email/list_pops?regex=wzzemptyaudit'],
            ['uapi:Email/list_pops', 'bridged-uapi', 'absent-regex', $bridged
                . '&cpanel.module=Email&cpanel.function=list_pops&regex=wzzemptyaudit'],
            ['uapi:Email/list_pops_with_disk', 'direct-uapi', 'absent-regex', $direct
                . 'Email/list_pops_with_disk?regex=wzzemptyaudit'],
            ['uapi:Email/list_pops_with_disk', 'bridged-uapi', 'absent-regex', $bridged
                . '&cpanel.module=Email&cpanel.function=list_pops_with_disk&regex=wzzemptyaudit'],
            ['uapi:Email/list_pops_with_disk', 'direct-uapi', 'absent-domain', $direct
                . 'Email/list_pops_with_disk?domain=wzzemptyaudit.invalid'],
            ['uapi:Email/list_pops_with_disk', 'bridged-uapi', 'absent-domain', $bridged
                . '&cpanel.module=Email&cpanel.function=list_pops_with_disk&domain=wzzemptyaudit.invalid'],
            ['uapi:SubDomain/listsubdomains', 'direct-uapi', 'absent-regex', $direct
                . 'SubDomain/listsubdomains?regex=wzzemptyaudit'],
            ['uapi:SubDomain/listsubdomains', 'bridged-uapi', 'absent-regex', $bridged
                . '&cpanel.module=SubDomain&cpanel.function=listsubdomains&regex=wzzemptyaudit'],
            ['uapi:Features/list_features_like', 'direct-uapi', 'absent-substring', $direct
                . 'Features/list_features_like?pattern=wzzemptyaudit&is_regex=0'],
            ['uapi:Features/list_features_like', 'bridged-uapi', 'absent-substring', $bridged
                . '&cpanel.module=Features&cpanel.function=list_features_like&pattern=wzzemptyaudit&is_regex=0'],
            ['uapi:Fileman/autocompletedir', 'direct-uapi', 'absent-prefix', $direct
                . 'Fileman/autocompletedir?path=wzzemptyaudit'],
            ['uapi:Fileman/autocompletedir', 'bridged-uapi', 'absent-prefix', $bridged
                . '&cpanel.module=Fileman&cpanel.function=autocompletedir&path=wzzemptyaudit'],
            ['uapi:Bandwidth/query', 'direct-uapi', 'absent-domain', $direct
                . 'Bandwidth/query?grouping=domain&domains=wzzemptyaudit.invalid'],
            ['uapi:Bandwidth/query', 'bridged-uapi', 'absent-domain', $bridged
                . '&cpanel.module=Bandwidth&cpanel.function=query&grouping=domain&domains=wzzemptyaudit.invalid'],
            ['uapi:Bandwidth/query', 'direct-uapi', 'past-window', $direct
                . 'Bandwidth/query?grouping=domain&end=1000000001&start=1000000000'],
            ['uapi:Bandwidth/query', 'bridged-uapi', 'past-window', $bridged
                . '&cpanel.module=Bandwidth&cpanel.function=query&grouping=domain&end=1000000001&start=1000000000'],
            ['uapi:SSL/list_ssl_items', 'direct-uapi', 'absent-domain', $direct
                . 'SSL/list_ssl_items?domains=wzzemptyaudit.invalid'],
            ['uapi:SSL/list_ssl_items', 'bridged-uapi', 'absent-domain', $bridged
                . '&cpanel.module=SSL&cpanel.function=list_ssl_items&domains=wzzemptyaudit.invalid'],
        ];
    }

    private static function key(Probe $probe): string
    {
        return $probe->operation . '|' . $probe->route . '|' . ($probe->argumentVariant ?? '');
    }

    /**
     * Synthetic no-match bodies keyed by probe; independent of every recorded live form.
     *
     * @return array<string, string>
     */
    private static function noMatchBodies(): array
    {
        $whm = static fn (string $data): string => '{"metadata":{"result":1,"reason":"OK"},"data":' . $data . '}';
        $bodies = [
            'whm1:version|whm|' => $whm('{"version":"11.138.0.19"}'),
            'whm1:listaccts|whm|fixed-path-control' => '{"metadata":{"result":0,"reason":"No accounts found."}}',
            'whm1:showbw|whm|absent-user-pcre' => $whm('{"acct":[]}'),
            'whm1:matchpkgs|whm|absent-feature-list' => $whm('{"pkg":[]}'),
            'whm1:emailtrack_search|whm|all-event-types-off' => $whm('{"records":[]}'),
            'whm1:emailtrack_user_stats|whm|absent-sender-past-window' => $whm('{"records":[]}'),
            'whm1:get_unique_sender_recipient_count_per_user|whm|past-window' => $whm('{"payload":[]}'),
            'whm1:php_get_vhosts_by_version|whm|absent-php-package' => $whm('{"vhosts":[]}'),
            'whm1:accountsummary|whm|entity-lookup-control'
                => '{"metadata":{"result":0,"reason":"Account does not exist."},"data":{"acct":null}}',
        ];
        foreach (self::allowlist() as [$operation, $route, $variant]) {
            if ($route === 'whm') {
                continue;
            }
            $key = $operation . '|' . $route . '|' . $variant;
            $payload = $operation === 'uapi:Bandwidth/query' ? '{}' : '[]';
            $envelope = '{"status":1,"data":' . $payload . ',"errors":null,"messages":null}';
            $bodies[$key] = $route === 'direct-uapi'
                ? $envelope
                : '{"metadata":{"result":1},"data":{"uapi":' . $envelope . '}}';
        }

        return $bodies;
    }

    /** @return Context */
    private static function context(): array
    {
        return ['revision' => 'synthetic-revision',
            'source_hashes' => ['whm1' => 'whm-source', 'uapi' => 'uapi-source'],
            'map_sha256' => 'map', 'generator_tree' => 'generator', 'overlay_sha256' => 'overlay'];
    }

    /**
     * Run only the help or invalid-argument path with every live opt-in and credential cleared.
     *
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private static function execute(array $arguments): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            [PHP_BINARY, $root . '/tools/live-verify/empty-results.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['WHM_API_LIVE' => '', 'WHM_API_LIVE_ACCOUNT' => '', 'WHM_API_LIVE_DOMAIN' => '',
                'OBLAK_TEST_WHM_KEY' => '', 'OBLAK_TEST_NPR_KEY' => ''],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [proc_close($process), $stdout, $stderr];
    }
}
