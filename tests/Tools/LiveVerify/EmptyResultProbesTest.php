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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use stdClass;

/**
 * The literal table below is the reviewed allowlist and the reviewed outcome per probe; it never reads the
 * catalog's constants or declarations.
 *
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Report from ReadOnlySectionRunner
 */
final class EmptyResultProbesTest extends TestCase
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    private const BRIDGED = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr';

    /** The immutable capture; fixtures and declarations are held to it, and it is never rewritten. */
    private const ARTIFACT = 'docs/api/evidence/2026-11-01-empty-results.json';

    private const ARTIFACT_SHA256 = '2ec7d60176b4354c01d1016849e0e3d62ae85a7182354010ab8d89e77a39894f';

    private const ARTIFACT_REVISION = 'fc5264d97f0c9d90ebbf9148b714d69e4a288959';

    public function testCatalogIsTheReviewedBoundedAllowlist(): void
    {
        $probes = EmptyResultProbes::load();
        $expected = self::allowlist();
        self::assertSame('empty-results-v2', EmptyResultProbes::RECIPE);
        self::assertCount(27, $probes);
        self::assertCount(count($expected), $probes);
        foreach ($probes as $index => $probe) {
            [$operation, $route, $variant, $uri, $outcome] = $expected[$index];
            self::assertSame($operation, $probe->operation);
            self::assertSame($route, $probe->route);
            self::assertSame($variant, $probe->argumentVariant);
            self::assertSame($uri, $probe->uri);
            self::assertSame($outcome, EmptyResultProbes::declaredOutcome($probe));
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
        $expected = self::allowlist();
        foreach (EmptyResultProbes::load() as $index => $probe) {
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
            $failed = false;
            try {
                ($probe->invoke)($client);
            } catch (ApiErrorException) {
                $failed = true;
            }
            self::assertSame($expected[$index][4] === 'api_failure', $failed, self::key($probe));
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
        $swapped = ['cpanel.user=npr' => 0, 'wzzemptyaudit' => 0];
        foreach (EmptyResultProbes::load() as $probe) {
            $http = new MockClient(new Psr17Factory());
            $wrongRequests = [
                new Request('POST', $probe->uri),
                new Request('DELETE', $probe->uri),
                new Request('GET', $probe->uri, [], 'unexpected'),
                new Request('GET', $probe->uri . '&extra=1'),
                new Request('GET', str_replace('test.oblak.host', 'other.example', $probe->uri)),
            ];
            foreach (['cpanel.user=npr' => 'cpanel.user=other', 'wzzemptyaudit' => 'npr'] as $from => $to) {
                $changed = str_replace($from, $to, $probe->uri);
                if ($changed !== $probe->uri) {
                    $wrongRequests[] = new Request('GET', $changed);
                    $swapped[$from]++;
                }
            }
            foreach ($wrongRequests as $wrong) {
                try {
                    (new RequestGuard($http, $probe->uri))->sendRequest($wrong);
                    self::fail('A changed recipe must be rejected before HTTP.');
                } catch (LogicException) {
                    self::assertSame([], $http->getRequests());
                }
            }
        }
        // Each swap is its only change, so these prove the guard binds the account and the criterion.
        self::assertSame(['cpanel.user=npr' => 9, 'wzzemptyaudit' => 21], $swapped);
    }

    public function testRecordedFormsPassTheDeclaredOutcomesAndKeepOnlySanitizedStructure(): void
    {
        $probes = EmptyResultProbes::load();
        $expected = self::allowlist();
        $report = self::capture();
        self::assertCount(27, $report['probes']);
        self::assertSame('empty-results-v2', $report['recipe']);
        self::assertSame('passed', $report['status']);
        // The shared strict rule stays unchanged, and this catalog still cannot satisfy it.
        self::assertFalse(ReadOnlySectionRunner::passes($probes, $report['probes'], self::context()));
        foreach ($report['probes'] as $index => $record) {
            self::assertSame($probes[$index]->operation, $record['operation']);
            self::assertSame($probes[$index]->argumentVariant, $record['argument_variant'] ?? null);
            self::assertSame('11.138.0.19', $record['server_version']);
            if ($expected[$index][4] === 'api_failure') {
                self::assertSame('failed', $record['probe_outcome']);
                self::assertSame('api_failure', $record['failure_kind']);
                self::assertSame('failure', $record['api_outcome']);
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
        foreach (['No accounts found', 'does not exist', 'synthetic failure'] as $text) {
            self::assertStringNotContainsString($text, $json);
        }
    }

    /**
     * Compare each fixture's raw structure with the artifact's response paths, reading no spec.
     *
     * Running a fixture through the evidence tool would derive its paths from the current spec, so an ordinary
     * contract change would fail this. Recorded `missing` entries name absent fields, which a body cannot show.
     */
    public function testSyntheticBodiesReproduceTheCommittedStructure(): void
    {
        $probes = EmptyResultProbes::load();
        $bodies = self::noMatchBodies();
        $captured = self::artifact()['probes'];
        self::assertCount(count($probes), $captured);
        foreach ($probes as $index => $probe) {
            $recorded = $captured[$index]['response_paths'] ?? null;
            self::assertIsArray($recorded);
            $observed = self::structure(json_decode($bodies[self::key($probe)], false, 512, JSON_THROW_ON_ERROR));
            ksort($observed, SORT_STRING);
            self::assertSame(
                array_filter($recorded, static fn (mixed $types): bool => $types !== ['missing']),
                $observed,
                self::key($probe),
            );
        }
    }

    /**
     * Bind the immutable capture to this catalog by identity and declared outcome.
     *
     * The binding is the operation, source variant, argument label, route and method, each record's outcome, the
     * capture revision and the SHA-256 the docs publish. Contract hashes stay out of it, and passes() is not
     * called, because both read the current spec. The artifact stays a valid historical record when a spec later
     * changes, so an ordinary offline contract change must never fail this test or require a live capture.
     */
    public function testCommittedArtifactMatchesTheDeclaredOutcomes(): void
    {
        $probes = EmptyResultProbes::load();
        $expected = self::allowlist();
        $artifact = self::artifact();
        self::assertSame('empty-results-v1', $artifact['recipe']);
        self::assertSame('failed', $artifact['status']);
        self::assertCount(count($probes), $artifact['probes']);
        $root = dirname(__DIR__, 3);
        foreach (['docs/api/evidence/README.md', 'docs/api/source-audit.md', 'tools/live-verify/README.md'] as $path) {
            $document = file_get_contents($root . '/' . $path);
            self::assertIsString($document);
            self::assertStringContainsString(self::ARTIFACT_SHA256, $document, $path);
            self::assertStringContainsString(self::ARTIFACT_REVISION, $document, $path);
        }
        $counts = ['value' => 0, 'fixed_path_empty' => 0, 'empty' => 0, 'api_failure' => 0];
        foreach ($probes as $index => $probe) {
            $record = $artifact['probes'][$index];
            $label = self::key($probe);
            self::assertSame($probe->operation, $record['operation'] ?? null, $label);
            self::assertSame($probe->sourceVariant ?? $probe->operation . '#GET', $record['variant'] ?? null, $label);
            self::assertSame($probe->argumentVariant, $record['argument_variant'] ?? null, $label);
            self::assertSame($probe->route, $record['route'] ?? null, $label);
            self::assertSame('GET', $record['method'] ?? null, $label);
            self::assertSame(self::ARTIFACT_REVISION, $record['revision'] ?? null, $label);
            self::assertTrue(EmptyResultProbes::matchesDeclaredOutcome($probe, $record), $label);
            $outcome = self::classification($record);
            self::assertSame($expected[$index][4], $outcome, $label);
            $counts[$outcome]++;
        }
        self::assertSame(['value' => 1, 'fixed_path_empty' => 1, 'empty' => 22, 'api_failure' => 3], $counts);
    }

    /** @return iterable<string, array{string, int, string, string|null}> */
    public static function deviations(): iterable
    {
        $whm = static fn (string $data): string
            => '{"metadata":{"command":"synthetic","reason":"OK","result":1,"version":1},"data":' . $data . '}';
        $status = static fn (int $status, string $data): string => '{"status":' . $status . ',"data":' . $data
            . ',"errors":null,"messages":null,"metadata":{},"warnings":null}';
        $broken = '"broken"';
        $report = '"month":11,"reseller":"root","totalused":"0","year":2026';
        yield 'showbw raises a payload mismatch' => ['whm1:showbw|whm|absent-user-pcre', 200,
            $whm('{"acct":' . $broken . ',' . $report . '}'), 'payload_mismatch'];
        yield 'a direct list raises a payload mismatch' => ['uapi:SSL/list_ssl_items|direct-uapi|absent-domain', 200,
            $status(1, $broken), 'payload_mismatch'];
        yield 'a bridged list raises a payload mismatch' => ['uapi:Email/list_pops|bridged-uapi|absent-regex', 200,
            $whm('{"uapi":' . $status(1, $broken) . '}'), 'payload_mismatch'];
        yield 'the entity control unexpectedly succeeds' => ['whm1:accountsummary|whm|entity-lookup-control', 200,
            $whm('{"acct":[]}'), null];
        yield 'direct Bandwidth absent domain unexpectedly succeeds'
            => ['uapi:Bandwidth/query|direct-uapi|absent-domain', 200, $status(1, '{}'), null];
        yield 'bridged Bandwidth absent domain unexpectedly succeeds' => [
            'uapi:Bandwidth/query|bridged-uapi|absent-domain',
            200,
            $whm('{"uapi":' . $status(1, '{}') . '}'),
            null,
        ];
        yield 'a filter returns rows' => ['uapi:Email/list_pops|direct-uapi|absent-regex', 200,
            $status(1, '[{"email":"synthetic","login":"synthetic"}]'), null];
        yield 'an empty object becomes an empty list' => ['uapi:Bandwidth/query|direct-uapi|past-window', 200,
            $status(1, '[]'), null];
        yield 'an empty collection becomes an API error' => ['whm1:showbw|whm|absent-user-pcre', 200,
            '{"metadata":{"command":"showbw","reason":"synthetic","result":0,"version":1}}', 'api_failure'];
        yield 'an empty collection gains an unknown field' => ['whm1:matchpkgs|whm|absent-feature-list', 200,
            $whm('{"pkg":[],"unknown":1}'), null];
        yield 'the fixed path gains a data member' => ['whm1:listaccts|whm|fixed-path-control', 200,
            $whm('{"acct":[]}'), null];
        yield 'the fixed path refuses another diagnostic' => ['whm1:listaccts|whm|fixed-path-control', 200,
            '{"metadata":{"command":"listaccts","reason":"synthetic","result":0,"version":1}}', 'api_failure'];
        yield 'the entity control fails as a request instead' => ['whm1:accountsummary|whm|entity-lookup-control', 500,
            'synthetic', 'request_failure'];
    }

    #[DataProvider('deviations')]
    public function testDeviationFromADeclaredOutcomeFailsTheRun(
        string $key,
        int $status,
        string $body,
        ?string $failureKind,
    ): void {
        $probes = EmptyResultProbes::load();
        $report = self::capture([$key => new Response($status, [], $body)]);
        self::assertSame('failed', $report['status']);
        $deviated = [];
        foreach ($report['probes'] as $index => $record) {
            if (!EmptyResultProbes::matchesDeclaredOutcome($probes[$index], $record)) {
                $deviated[self::key($probes[$index])] = $record['failure_kind'];
            }
        }
        self::assertSame([$key => $failureKind], $deviated);
        $lines = ReadOnlySectionRunner::deviations(
            $probes,
            $report['probes'],
            EmptyResultProbes::matchesDeclaredOutcome(...),
        );
        self::assertCount(1, $lines);
        [$operation, $route, $variant] = explode('|', $key);
        self::assertStringStartsWith('Deviation: ' . $operation . ' ' . $route . ' ' . $variant . ': ', $lines[0]);
        self::assertStringContainsString('failure_kind=' . ($failureKind ?? 'none'), $lines[0]);
        foreach (['wzzemptyaudit', 'private-', 'synthetic', 'broken', 'https:', '$'] as $private) {
            self::assertStringNotContainsString($private, $lines[0]);
        }
    }

    public function testEveryDeclaredOutcomeRejectsEveryOtherOutcome(): void
    {
        $probes = EmptyResultProbes::load();
        $expected = self::allowlist();
        $context = self::context();
        $report = self::capture();
        $assess = EmptyResultProbes::matchesDeclaredOutcome(...);
        $others = [
            'client exception' => ['api_outcome' => 'success', 'payload_validation' => 'failed',
                'probe_outcome' => 'failed', 'failure_kind' => 'payload_mismatch'],
            'request failure' => ['api_outcome' => 'unavailable', 'payload_validation' => 'not_checked',
                'probe_outcome' => 'failed', 'failure_kind' => 'request_failure'],
            'success' => ['api_outcome' => 'success', 'payload_validation' => 'passed',
                'probe_outcome' => 'passed', 'failure_kind' => null],
            'api failure' => ['api_outcome' => 'failure', 'payload_validation' => 'not_checked',
                'probe_outcome' => 'failed', 'failure_kind' => 'api_failure'],
            'discrepancy' => ['discrepancies' => [['path' => '$.*', 'kind' => 'unknown_field']]],
            // Records are untrusted, so one field changing alone must also fail.
            'failure kind alone' => ['failure_kind' => 'probe_failure'],
        ];
        $rejected = 0;
        foreach ($report['probes'] as $index => $record) {
            foreach ($others as $label => $other) {
                $changed = array_replace($record, $other);
                if ($changed === $record) {
                    continue;
                }
                $records = $report['probes'];
                $records[$index] = $changed;
                self::assertFalse(
                    ReadOnlySectionRunner::passes($probes, $records, $context, $assess),
                    self::key($probes[$index]) . ' ' . $label,
                );
                $rejected++;
            }
            if ($expected[$index][4] === 'empty') {
                $populated = $record;
                $populated['payload_paths'] = array_map(
                    static fn (array $types): array
                        => array_map(static fn (string $type): string => str_replace('empty-', '', $type), $types),
                    $record['payload_paths'],
                );
                self::assertFalse($assess($probes[$index], $populated), self::key($probes[$index]));
                $rejected++;
            }
        }
        self::assertSame(27 * 5 + 22, $rejected);
    }

    public function testDeclaredAssessmentKeepsTheSharedProvenanceAndLabelChecks(): void
    {
        $probes = EmptyResultProbes::load();
        $context = self::context();
        $report = self::capture();
        $assess = EmptyResultProbes::matchesDeclaredOutcome(...);
        self::assertTrue(ReadOnlySectionRunner::passes($probes, $report['probes'], $context, $assess));
        foreach (['argument_variant', 'revision', 'effective_contract_sha256', 'route', 'method'] as $field) {
            $records = $report['probes'];
            $records[2][$field] = 'other-value';
            self::assertFalse(ReadOnlySectionRunner::passes($probes, $records, $context, $assess), $field);
        }
        array_pop($report['probes']);
        self::assertFalse(ReadOnlySectionRunner::passes($probes, $report['probes'], $context, $assess));
    }

    public function testFixedPathRecordCannotSeparateResultZeroFromResultOne(): void
    {
        $resultZero = self::capture()['probes'][1];
        $resultOne = self::capture(['whm1:listaccts|whm|fixed-path-control' => new Response(
            200,
            [],
            '{"metadata":{"command":"listaccts","reason":"OK","result":1,"version":1}}',
        )])['probes'][1];
        unset($resultZero['observed_at'], $resultOne['observed_at']);
        self::assertSame($resultZero, $resultOne);
        self::assertSame('success', $resultOne['api_outcome']);
    }

    public function testUndeclaredProbeCannotBeAssessed(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Every no-match probe must declare its outcome.');
        EmptyResultProbes::declaredOutcome(new Probe(
            'whm1:version',
            'whm',
            EmptyResultProbes::load()[0]->spec,
            self::WHM . 'version?api.version=1',
            static fn (Client $client): mixed => null,
            argumentVariant: 'undeclared',
        ));
    }

    public function testCatalogRequiresExplicitOptInBeforeAnyRequest(): void
    {
        $http = new MockClient();
        try {
            (new ReadOnlySectionRunner($http, self::context()))->run(
                EmptyResultProbes::load(),
                EmptyResultProbes::RECIPE,
                false,
                ['whm' => 'test-whm', 'cpanel' => 'test-cpanel'],
                EmptyResultProbes::matchesDeclaredOutcome(...),
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

    public function testEntrypointBindsTheRecipeAndDeclaredAssessment(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/tools/live-verify/empty-results.php');
        self::assertIsString($source);
        self::assertStringContainsString('EmptyResultProbes::RECIPE,', $source);
        self::assertStringContainsString('EmptyResultProbes::matchesDeclaredOutcome(...),', $source);
    }

    /** @return list<array{string, string, string|null, string, string}> */
    private static function allowlist(): array
    {
        $direct = self::DIRECT;
        $bridged = self::BRIDGED;

        return [
            ['whm1:version', 'whm', null, self::WHM . 'version?api.version=1', 'value'],
            ['whm1:listaccts', 'whm', 'fixed-path-control', self::WHM
                . 'listaccts?api.version=1&search=wzzemptyaudit&searchtype=user&searchmethod=exact',
                'fixed_path_empty'],
            ['whm1:showbw', 'whm', 'absent-user-pcre', self::WHM
                . 'showbw?api.version=1&search=wzzemptyaudit&searchtype=user', 'empty'],
            ['whm1:matchpkgs', 'whm', 'absent-feature-list', self::WHM
                . 'matchpkgs?api.version=1&FEATURELIST=wzzemptyaudit', 'empty'],
            ['whm1:emailtrack_search', 'whm', 'all-event-types-off', self::WHM
                . 'emailtrack_search?api.version=1&defer=0&failure=0&inprogress=0&max_results_by_type=1&success=0',
                'empty'],
            ['whm1:emailtrack_user_stats', 'whm', 'absent-sender-past-window', self::WHM
                . 'emailtrack_user_stats?api.version=1&endtime=1000000001'
                . '&sender=nobody%40wzzemptyaudit.invalid&starttime=1000000000', 'empty'],
            ['whm1:get_unique_sender_recipient_count_per_user', 'whm', 'past-window', self::WHM
                . 'get_unique_sender_recipient_count_per_user?api.version=1'
                . '&start_time=1000000000&end_time=1000000001', 'empty'],
            ['whm1:php_get_vhosts_by_version', 'whm', 'absent-php-package', self::WHM
                . 'php_get_vhosts_by_version?api.version=1&version=ea-php00', 'empty'],
            ['whm1:accountsummary', 'whm', 'entity-lookup-control', self::WHM
                . 'accountsummary?api.version=1&user=wzzemptyaudit', 'api_failure'],
            ['uapi:Email/list_pops', 'direct-uapi', 'absent-regex', $direct
                . 'Email/list_pops?regex=wzzemptyaudit', 'empty'],
            ['uapi:Email/list_pops', 'bridged-uapi', 'absent-regex', $bridged
                . '&cpanel.module=Email&cpanel.function=list_pops&regex=wzzemptyaudit', 'empty'],
            ['uapi:Email/list_pops_with_disk', 'direct-uapi', 'absent-regex', $direct
                . 'Email/list_pops_with_disk?regex=wzzemptyaudit', 'empty'],
            ['uapi:Email/list_pops_with_disk', 'bridged-uapi', 'absent-regex', $bridged
                . '&cpanel.module=Email&cpanel.function=list_pops_with_disk&regex=wzzemptyaudit', 'empty'],
            ['uapi:Email/list_pops_with_disk', 'direct-uapi', 'absent-domain', $direct
                . 'Email/list_pops_with_disk?domain=wzzemptyaudit.invalid', 'empty'],
            ['uapi:Email/list_pops_with_disk', 'bridged-uapi', 'absent-domain', $bridged
                . '&cpanel.module=Email&cpanel.function=list_pops_with_disk&domain=wzzemptyaudit.invalid', 'empty'],
            ['uapi:SubDomain/listsubdomains', 'direct-uapi', 'absent-regex', $direct
                . 'SubDomain/listsubdomains?regex=wzzemptyaudit', 'empty'],
            ['uapi:SubDomain/listsubdomains', 'bridged-uapi', 'absent-regex', $bridged
                . '&cpanel.module=SubDomain&cpanel.function=listsubdomains&regex=wzzemptyaudit', 'empty'],
            ['uapi:Features/list_features_like', 'direct-uapi', 'absent-substring', $direct
                . 'Features/list_features_like?pattern=wzzemptyaudit&is_regex=0', 'empty'],
            ['uapi:Features/list_features_like', 'bridged-uapi', 'absent-substring', $bridged
                . '&cpanel.module=Features&cpanel.function=list_features_like&pattern=wzzemptyaudit&is_regex=0',
                'empty'],
            ['uapi:Fileman/autocompletedir', 'direct-uapi', 'absent-prefix', $direct
                . 'Fileman/autocompletedir?path=wzzemptyaudit', 'empty'],
            ['uapi:Fileman/autocompletedir', 'bridged-uapi', 'absent-prefix', $bridged
                . '&cpanel.module=Fileman&cpanel.function=autocompletedir&path=wzzemptyaudit', 'empty'],
            ['uapi:Bandwidth/query', 'direct-uapi', 'absent-domain', $direct
                . 'Bandwidth/query?grouping=domain&domains=wzzemptyaudit.invalid', 'api_failure'],
            ['uapi:Bandwidth/query', 'bridged-uapi', 'absent-domain', $bridged
                . '&cpanel.module=Bandwidth&cpanel.function=query&grouping=domain&domains=wzzemptyaudit.invalid',
                'api_failure'],
            ['uapi:Bandwidth/query', 'direct-uapi', 'past-window', $direct
                . 'Bandwidth/query?grouping=domain&end=1000000001&start=1000000000', 'empty'],
            ['uapi:Bandwidth/query', 'bridged-uapi', 'past-window', $bridged
                . '&cpanel.module=Bandwidth&cpanel.function=query&grouping=domain&end=1000000001&start=1000000000',
                'empty'],
            ['uapi:SSL/list_ssl_items', 'direct-uapi', 'absent-domain', $direct
                . 'SSL/list_ssl_items?domains=wzzemptyaudit.invalid', 'empty'],
            ['uapi:SSL/list_ssl_items', 'bridged-uapi', 'absent-domain', $bridged
                . '&cpanel.module=SSL&cpanel.function=list_ssl_items&domains=wzzemptyaudit.invalid', 'empty'],
        ];
    }

    /**
     * Run the whole catalog through the real client and declared assessment, replacing selected responses.
     *
     * @param array<string, Response> $replacements Keyed as key() does.
     * @return Report
     */
    private static function capture(array $replacements = []): array
    {
        $probes = EmptyResultProbes::load();
        $bodies = self::noMatchBodies();
        $http = new MockClient();
        foreach ($probes as $probe) {
            $http->addResponse($replacements[self::key($probe)] ?? new Response(200, [], $bodies[self::key($probe)]));
        }
        $report = (new ReadOnlySectionRunner($http, self::context()))->run(
            $probes,
            EmptyResultProbes::RECIPE,
            '1',
            ['whm' => 'private-whm-token', 'cpanel' => 'private-cpanel-token'],
            EmptyResultProbes::matchesDeclaredOutcome(...),
        );
        self::assertCount(count($probes), $http->getRequests());

        return $report;
    }

    /**
     * List a decoded fixture's paths and types in the evidence tool's names, reading no spec.
     *
     * A key named synthetic stands for the wildcard that hides an unreviewed key in the artifact.
     *
     * @return array<string, list<string>>
     */
    private static function structure(mixed $value, string $path = '$'): array
    {
        $type = match (true) {
            $value === null => 'null',
            is_int($value) => 'int',
            is_string($value) && is_numeric($value) => 'numeric-string',
            is_string($value) => 'string',
            $value instanceof stdClass => get_object_vars($value) === [] ? 'empty-object' : 'object',
            is_array($value) => $value === [] ? 'empty-list' : 'list',
            default => throw new LogicException('Unexpected fixture value.'),
        };
        $paths = [$path => [$type]];
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $paths += self::structure($item, $path . '.' . ($key === 'synthetic' ? '*' : $key));
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $paths += self::structure($item, $path . '[]');
            }
        }

        return $paths;
    }

    /**
     * Read a captured outcome from the record's own fields, without the catalog's declarations.
     *
     * @param array<mixed> $record
     */
    private static function classification(array $record): string
    {
        if (($record['failure_kind'] ?? null) === 'api_failure') {
            return 'api_failure';
        }
        self::assertNull($record['failure_kind'] ?? null);
        $paths = $record['payload_paths'] ?? null;
        self::assertIsArray($paths);
        if (($paths['$'] ?? null) === ['missing']) {
            return 'fixed_path_empty';
        }
        $observed = json_encode($paths, JSON_THROW_ON_ERROR);

        return str_contains($observed, '"empty-list"') || str_contains($observed, '"empty-object"')
            ? 'empty'
            : 'value';
    }

    private static function key(Probe $probe): string
    {
        return $probe->operation . '|' . $probe->route . '|' . ($probe->argumentVariant ?? '');
    }

    /**
     * Synthetic values in the structure the 2026-11-01 capture recorded for each probe.
     *
     * No upstream value was retained. The two diagnostics are the ones the source audit publishes, and the
     * listaccts path reads its one. testSyntheticBodiesReproduceTheCommittedStructure holds these to the artifact.
     *
     * @return array<string, string>
     */
    private static function noMatchBodies(): array
    {
        $whm = static fn (string $data, string $extra = ''): string
            => '{"metadata":{"command":"synthetic","reason":"OK","result":1,"version":1' . $extra . '},"data":'
                . $data . '}';
        $bodies = [
            'whm1:version|whm|' => $whm('{"version":"11.138.0.19"}'),
            'whm1:listaccts|whm|fixed-path-control'
                => '{"metadata":{"command":"listaccts","reason":"No accounts found.","result":0,"version":1}}',
            'whm1:showbw|whm|absent-user-pcre'
                => $whm('{"acct":[],"month":11,"reseller":"root","totalused":"0","year":2026}'),
            'whm1:matchpkgs|whm|absent-feature-list' => $whm('{"pkg":[]}'),
            'whm1:emailtrack_search|whm|all-event-types-off' => $whm('{"records":[]}', ',"synthetic":0'),
            'whm1:emailtrack_user_stats|whm|absent-sender-past-window' => $whm('{"records":[]}', ',"synthetic":0'),
            'whm1:get_unique_sender_recipient_count_per_user|whm|past-window' => $whm('{"payload":[]}'),
            'whm1:php_get_vhosts_by_version|whm|absent-php-package' => $whm('{"vhosts":[]}'),
            'whm1:accountsummary|whm|entity-lookup-control' => '{"metadata":{"command":"accountsummary",'
                . '"reason":"Account does not exist.","result":0,"version":1},"data":{"acct":null}}',
        ];
        foreach (self::allowlist() as [$operation, $route, $variant]) {
            if ($route === 'whm') {
                continue;
            }
            $envelope = match ($operation . '|' . $variant) {
                'uapi:Bandwidth/query|absent-domain' => '{"status":0,"data":null,"errors":["synthetic failure"],'
                    . '"messages":null,"metadata":{},"warnings":null}',
                'uapi:Bandwidth/query|past-window'
                    => '{"status":1,"data":{},"errors":null,"messages":null,"metadata":{},"warnings":null}',
                default => '{"status":1,"data":[],"errors":null,"messages":null,"metadata":{"synthetic":0},'
                    . '"warnings":null}',
            };
            $bodies[$operation . '|' . $route . '|' . $variant] = $route === 'direct-uapi'
                ? $envelope
                : $whm('{"uapi":' . $envelope . '}');
        }

        return $bodies;
    }

    /**
     * Read the committed capture; only its structural record fields are used.
     *
     * @return array{recipe: mixed, status: mixed, probes: list<array<mixed>>}
     */
    private static function artifact(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 3) . '/' . self::ARTIFACT);
        self::assertIsString($raw);
        self::assertSame(self::ARTIFACT_SHA256, hash('sha256', $raw));
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $section = $document['read_only_section'] ?? null;
        self::assertIsArray($section);
        $probes = [];
        foreach (is_array($section['probes'] ?? null) ? $section['probes'] : [] as $record) {
            self::assertIsArray($record);
            $probes[] = $record;
        }

        return ['recipe' => $section['recipe'] ?? null, 'status' => $section['status'] ?? null, 'probes' => $probes];
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
