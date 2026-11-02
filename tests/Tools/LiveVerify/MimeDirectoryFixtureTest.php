<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilesystemAbsenceProof;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeDirectoryFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\MimeDirectoryRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionParameter;
use SensitiveParameter;

/**
 * @phpstan-import-type Scenario from MimeDirectoryFixture
 * @phpstan-import-type Observation from MimeDirectoryFixture
 * @phpstan-import-type Context from EvidenceRunner
 */
final class MimeDirectoryFixtureTest extends TestCase
{
    /** Every mutation the run sends, in order, by route; the server double prefixes direct ones. */
    private const MUTATIONS = [
        'createacct', 'Tokens/create_full_access',
        'direct:Mime/add_mime', 'Mime/delete_mime', 'Mime/add_mime', 'direct:Mime/delete_mime',
        'direct:Mime/add_handler', 'Mime/delete_handler', 'Mime/add_handler', 'direct:Mime/delete_handler',
        'direct:Mime/add_redirect', 'Mime/delete_redirect', 'Mime/add_redirect', 'direct:Mime/delete_redirect',
        'direct:Mime/add_hotlink', 'Mime/delete_hotlink', 'Mime/add_hotlink', 'direct:Mime/delete_hotlink',
        'direct:Fileman/upload_files',
        'direct:DirectoryIndexes/set_indexing', 'DirectoryIndexes/set_indexing', 'direct:DirectoryIndexes/set_indexing',
        'direct:DirectoryPrivacy/configure_directory_protection', 'direct:DirectoryPrivacy/add_user',
        'DirectoryPrivacy/add_user', 'DirectoryPrivacy/delete_user', 'direct:DirectoryPrivacy/delete_user',
        'DirectoryPrivacy/configure_directory_protection', 'DirectoryPrivacy/configure_directory_protection',
        'direct:DirectoryPrivacy/configure_directory_protection',
        'Tokens/revoke', 'removeacct',
    ];

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1', 'WHM_API_LIVE_ACCOUNT' => 'wlmime',
                'WHM_API_LIVE_DOMAIN' => 'wlmime.example.com'] as $key => $value
        ) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testCompleteRunProvesEveryMutationAndItsReadbackOnBothRoutes(): void
    {
        $http = new MimeDirectoryServer();
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('passed', $fixture['status'], (string) json_encode($fixture['failures']));
        self::assertSame('mime-directory-disposable-v2', $scenario['scenario']);
        self::assertSame('mime-directory-no-acknowledgments-v1', $scenario['discrepancy_review']['policy']);
        self::assertSame([], $scenario['discrepancy_review']['findings']);
        self::assertSame('passed', $scenario['discrepancy_review']['status']);
        self::assertSame([], $fixture['failures']);
        self::assertSame('passed', $fixture['capture']);
        self::assertSame('passed', $fixture['expectations']);
        foreach (['mime', 'directory'] as $section) {
            self::assertSame(
                ['capture' => 'passed', 'expectations' => 'passed', 'stopped_at' => null],
                $fixture['sections'][$section],
            );
        }
        $confirmed = ['creation' => 'confirmed', 'cleanup' => 'confirmed_absent'];
        self::assertSame($confirmed, $fixture['resources']['account']);
        self::assertSame($confirmed, $fixture['resources']['token']);
        self::assertSame(
            ['creation' => 'confirmed', 'cleanup' => 'absent_with_account'],
            $fixture['resources']['directory'],
        );
        self::assertSame(array_keys(MimeDirectoryRecipe::PHASES), array_column($scenario['probes'], 'phase'));
        self::assertSame(self::MUTATIONS, $http->mutations);
        self::assertSame(1, $http->streamed);
        self::assertFalse($http->account);
        self::assertSame([], $http->tokens);
        self::assertSame([], $http->mimeTypes);
        self::assertSame([], $http->redirects);
        self::assertNull($http->hotlink);
        self::assertSame(['.php .php8 .phtml'], array_keys($http->handlers));
        self::assertContains(
            'api_absence_only_no_filesystem_or_home_directory_cleanup_claim',
            $scenario['limitations'],
        );
        self::assertContains('api_readback_is_not_enforcement_no_url_fetched', $scenario['limitations']);
        self::assertPrivate($scenario);
    }

    /** Each route reads back a populated row of its own addition, and its removal, for every Mime kind. */
    public function testEachRouteReadsBackItsPopulatedRowsAndTheirRemoval(): void
    {
        $rows = self::scenario(new MimeDirectoryServer())['list_rows'];
        foreach (['mime_types', 'redirects'] as $kind) {
            self::assertSame(0, $rows[$kind . '_baseline']);
            self::assertSame(1, $rows[$kind . '_after_add_direct']);
            self::assertSame(1, $rows[$kind . '_after_add_bridged']);
            self::assertSame(0, $rows[$kind . '_after_delete_direct']);
            self::assertSame(0, $rows[$kind . '_after_delete_bridged']);
        }
        self::assertSame(2, $rows['handlers_after_add_direct']);
        self::assertSame(1, $rows['handlers_after_delete_bridged']);
    }

    /** Mutations report only the kind of their result and how many messages they carried. */
    public function testMutationSummariesHoldOnlyKindsAndCounts(): void
    {
        $scenario = self::scenario(new MimeDirectoryServer());
        $mutations = $scenario['mutations'];
        self::assertCount(28, $mutations);
        self::assertSame(['result' => 'null', 'messages' => 1, 'warnings' => 0], $mutations['add_redirect_direct']);
        self::assertSame(['result' => 'null', 'messages' => 0, 'warnings' => 0], $mutations['add_mime_bridged']);
        self::assertSame(['result' => 'string', 'messages' => 0, 'warnings' => 0], $mutations['set_indexing_bridged']);
        self::assertSame('object', $mutations['unprotect_direct']['result']);
        $upload = $scenario['upload'];
        self::assertNotNull($upload);
        self::assertSame(1, $upload['uapi_status']);
        self::assertTrue($upload['stored_row_exact']);
        self::assertNotNull($upload['transfer']);
        self::assertTrue($upload['transfer']['body_exact']);
        self::assertFalse($upload['transfer']['buffered']);
    }

    /** A disable returns null realm fields, which the corrected contract accepts; evidence keeps their types. */
    public function testDisableKeepsNullAuthFieldsInStructuralEvidence(): void
    {
        $scenario = self::scenario(new MimeDirectoryServer());
        foreach (['unprotect_bridged', 'unprotect_direct'] as $phase) {
            $record = self::observation($scenario, $phase);
            self::assertSame(['null'], $record['payload_paths']['$.auth_name']);
            self::assertSame(['null'], $record['payload_paths']['$.auth_type']);
            self::assertSame('passed', $record['payload_validation']);
        }
        self::assertSame(['string'], self::observation($scenario, 'protect_bridged')['payload_paths']['$.auth_name']);
    }

    public function testReportCarriesEveryLifecycleField(): void
    {
        $fixture = self::scenario(new MimeDirectoryServer())['fixture'];
        foreach (CleanLifecycleRun::reportFields() as $field => $value) {
            if ($field === 'resources') {
                foreach ($value as $resource => $outcome) {
                    self::assertSame($outcome, $fixture['resources'][$resource], $resource);
                }
                continue;
            }
            self::assertSame($value, $fixture[$field], $field);
        }
    }

    /** The fixture, its guard, recipe, lifecycle and scenario never keep the marker; only the server double saw it. */
    public function testNoPublicObjectHoldsTheMarker(): void
    {
        $http = new MimeDirectoryServer();
        $streaming = $http->streaming();
        $fixture = new MimeDirectoryFixture($http, $streaming, 'WHMTOKEN', self::context());
        $scenario = $fixture->run();
        $marker = self::sentField($http->requests, '/createacct?', 'contactemail');
        foreach ([$marker, rawurlencode($marker)] as $form) {
            MarkerExposure::assertNeverExposed($form, $scenario);
            MarkerExposure::assertNeverExposed($form, $fixture, $http, $streaming);
        }
    }

    /** Both passwords reached the server, each in its own add_user body, and never the scenario. */
    public function testPasswordsReachOnlyTheirAddUserBodies(): void
    {
        $http = new MimeDirectoryServer();
        $scenario = self::scenario($http);
        $passwords = [];
        foreach ($http->requests as $request) {
            if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/')) {
                // The streamed upload is forward-only and carries only the synthetic file.
                continue;
            }
            parse_str((string) $request->getBody(), $fields);
            if (isset($fields['password']) && is_string($fields['password'])) {
                $passwords[] = $fields['password'];
                $uri = (string) $request->getUri();
                self::assertTrue(
                    str_ends_with($uri, '/execute/DirectoryPrivacy/add_user')
                        || str_ends_with($uri, '&cpanel.module=DirectoryPrivacy&cpanel.function=add_user'),
                );
                self::assertStringNotContainsString(rawurlencode($fields['password']), $uri);
            }
        }
        self::assertCount(2, array_unique($passwords));
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        foreach ($passwords as $password) {
            self::assertStringNotContainsString($password, $json);
            self::assertStringNotContainsString(substr($password, 2, 12), $json);
        }
    }

    public function testAnIgnoredRemovalStopsOnlyTheMimeSectionAtItsReadback(): void
    {
        $http = new MimeDirectoryServer();
        $http->applyDeleteMime = false;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame('failed', $fixture['status']);
        self::assertSame(
            ['capture' => 'failed', 'expectations' => 'failed', 'stopped_at' => 'mime_types_after_delete_bridged'],
            $fixture['sections']['mime'],
        );
        self::assertSame(['mime_expectation_failure'], $fixture['failures']);
        self::assertSame('failed', self::observation($scenario, 'mime_types_after_delete_bridged')['expectation']);
        self::assertSame(
            ['capture' => 'passed', 'expectations' => 'passed', 'stopped_at' => null],
            $fixture['sections']['directory'],
        );
        self::assertSame(1, $http->counts['direct:Mime/add_mime']);
        self::assertSame(1, $http->counts['Mime/delete_mime']);
        self::assertArrayNotHasKey('Mime/add_mime', $http->counts);
        self::assertArrayNotHasKey('direct:Mime/add_handler', $http->counts);
        self::assertSame('passed', $fixture['cleanup']);
        self::assertFalse($http->account);
        self::assertContains('missing_phase:add_mime_bridged', $scenario['discrepancy_review']['findings']);
    }

    public function testARefusedMutationStopsItsSectionAsACaptureFailure(): void
    {
        $http = new MimeDirectoryServer();
        $http->failures['direct:Mime/add_handler:1'] = 'api_after';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(['mime_capture_failure'], $fixture['failures']);
        self::assertSame(
            ['capture' => 'failed', 'expectations' => 'not_run', 'stopped_at' => 'add_handler_direct'],
            $fixture['sections']['mime'],
        );
        self::assertSame('api_failure', self::observation($scenario, 'add_handler_direct')['failure_kind']);
        self::assertArrayNotHasKey('Mime/delete_handler', $http->counts);
        self::assertSame('passed', $fixture['sections']['directory']['capture']);
        self::assertFalse($http->account);
    }

    public function testALostUploadWithholdsEveryDirectoryMutation(): void
    {
        $http = new MimeDirectoryServer();
        $http->failures['direct:Fileman/upload_files:1'] = 'throw_after';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(['directory_capture_failure'], $fixture['failures']);
        self::assertSame('create_directory', $fixture['sections']['directory']['stopped_at']);
        self::assertSame('passed', $fixture['sections']['mime']['capture']);
        self::assertSame(
            ['creation' => 'unconfirmed', 'cleanup' => 'absent_with_account'],
            $fixture['resources']['directory'],
        );
        foreach (array_keys($http->counts) as $function) {
            self::assertStringNotContainsString('Directory', $function);
        }
        self::assertFalse($http->account);
        self::assertSame([], $http->directories);
    }

    public function testADirectoryThatHeldSomethingElseIsNeverChanged(): void
    {
        $http = new MimeDirectoryServer();
        $http->overrides['direct:Fileman/list_files:2'] = (string) json_encode([[
            'absdir' => '/home/wlmime/private', 'exists' => 1, 'file' => 'private-other',
            'fullpath' => '/home/wlmime/private/private-other', 'gid' => 1000, 'humansize' => '1 KB', 'mode' => 33188,
            'mtime' => 1790000000, 'nicemode' => '644', 'path' => '/home/wlmime/private', 'read' => 1, 'size' => '1',
            'type' => 'file', 'uid' => 1000, 'write' => 1,
        ]]);
        $fixture = self::scenario($http)['fixture'];

        self::assertSame('directory_inventory', $fixture['sections']['directory']['stopped_at']);
        self::assertSame(['directory_expectation_failure'], $fixture['failures']);
        self::assertSame('unconfirmed', $fixture['resources']['directory']['creation']);
        self::assertArrayNotHasKey('direct:DirectoryIndexes/set_indexing', $http->counts);
    }

    public function testAServerVersionFailureRunsNoSectionButStillCleansUp(): void
    {
        $http = new MimeDirectoryServer();
        $http->failures['version:1'] = 'http_before';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(['capture_failure'], $fixture['failures']);
        self::assertSame('not_run', $fixture['sections']['mime']['capture']);
        self::assertSame('not_run', $fixture['sections']['directory']['capture']);
        self::assertSame(['createacct', 'Tokens/create_full_access', 'Tokens/revoke', 'removeacct'], $http->mutations);
        self::assertSame('passed', $fixture['cleanup']);
        self::assertContains('unusable_server_version', $scenario['discrepancy_review']['findings']);
    }

    public function testASetupFailureSendsNoFixtureRequest(): void
    {
        $http = new MimeDirectoryServer();
        $http->failures['createacct:1'] = 'api_before';
        $scenario = self::scenario($http);

        self::assertSame('failed', $scenario['fixture']['setup']);
        self::assertSame(['setup_failure'], $scenario['fixture']['failures']);
        self::assertSame([], $scenario['probes']);
        self::assertSame([], $http->mutations);
        self::assertSame('not_attempted', $scenario['fixture']['resources']['directory']['creation']);
    }

    public function testAnUnknownResponseFieldReachesTheStrictReviewWithoutStoppingCapture(): void
    {
        $http = new MimeDirectoryServer();
        $http->overrides['direct:Mime/list_hotlinks:1'] = (string) json_encode([
            'allow_null' => 0, 'extensions' => 'jpg', 'redirect_url' => null, 'state' => 'disabled', 'urls' => [],
            'private_extra' => 'private',
        ]);
        $scenario = self::scenario($http);

        self::assertSame('passed', $scenario['fixture']['status']);
        self::assertSame('failed', $scenario['discrepancy_review']['status']);
        self::assertSame(
            ['unacknowledged_discrepancy:hotlinks_baseline'],
            $scenario['discrepancy_review']['findings'],
        );
        self::assertPrivate($scenario);
    }

    /**
     * Decoded to arrays, `{}` equals an empty list, but the typed list contracts refuse it before any expectation.
     *
     * Absence is therefore never judged from `{}`: the read fails as a payload mismatch and stops its section.
     *
     * @param 'mime'|'directory' $section
     */
    #[DataProvider('emptyObjects')]
    public function testAnEmptyObjectStopsAsAPayloadMismatchBeforeAbsenceIsJudged(
        string $step,
        string $section,
        string $phase,
    ): void {
        $http = new MimeDirectoryServer();
        $http->overrides[$step] = '{}';
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];
        $record = self::observation($scenario, $phase);

        self::assertSame(
            ['capture' => 'failed', 'expectations' => 'not_run', 'stopped_at' => $phase],
            $fixture['sections'][$section],
        );
        self::assertSame([$section . '_capture_failure'], $fixture['failures']);
        self::assertSame('payload_mismatch', $record['failure_kind']);
        self::assertSame([['path' => '$', 'kind' => 'type_mismatch']], $record['discrepancies']);
    }

    /** @return iterable<string, array{string, 'mime'|'directory', string}> */
    public static function emptyObjects(): iterable
    {
        yield 'baseline' => ['direct:Mime/list_mime:1', 'mime', 'mime_types_baseline'];
        yield 'bridged removal' => ['Mime/list_mime:1', 'mime', 'mime_types_after_delete_bridged'];
        yield 'direct removal' => ['direct:Mime/list_redirects:3', 'mime', 'redirects_after_delete_direct'];
        yield 'handler removal' => ['Mime/list_handlers:1', 'mime', 'handlers_after_delete_bridged'];
        yield 'last user removal' => ['direct:DirectoryPrivacy/list_users:2', 'directory', 'users_after_delete_direct'];
    }

    public function testAnEmptyObjectNeverProvesADirectoryHasNoSubdirectories(): void
    {
        $http = new MimeDirectoryServer();
        $http->childrenAsObject = true;
        $scenario = self::scenario($http);
        $record = self::observation($scenario, 'leech_initial');

        self::assertSame('leech_initial', $scenario['fixture']['sections']['directory']['stopped_at']);
        self::assertSame(['directory_capture_failure'], $scenario['fixture']['failures']);
        self::assertSame('payload_mismatch', $record['failure_kind']);
        self::assertSame([['path' => '$.children', 'kind' => 'type_mismatch']], $record['discrepancies']);
    }

    /**
     * The recipe expects null data from every Mime and protected-user mutation, as the server source returns.
     *
     * @param 'mime'|'directory' $section
     */
    #[DataProvider('unexpectedResults')]
    public function testAMutationResultOtherThanNullFailsItsExpectation(
        string $step,
        string $payload,
        string $section,
        string $phase,
    ): void {
        $http = new MimeDirectoryServer();
        $http->overrides[$step] = $payload;
        $scenario = self::scenario($http);
        $fixture = $scenario['fixture'];

        self::assertSame(
            ['capture' => 'failed', 'expectations' => 'failed', 'stopped_at' => $phase],
            $fixture['sections'][$section],
        );
        self::assertSame([$section . '_expectation_failure'], $fixture['failures']);
        self::assertSame('failed', self::observation($scenario, $phase)['expectation']);
    }

    /** @return iterable<string, array{string, string, 'mime'|'directory', string}> */
    public static function unexpectedResults(): iterable
    {
        yield 'object from a MIME addition' => ['direct:Mime/add_mime:1', '{"private":"x"}', 'mime', 'add_mime_direct'];
        yield 'empty object from a handler removal' => ['Mime/delete_handler:1', '{}', 'mime',
            'delete_handler_bridged'];
        yield 'scalar from a hotlink removal' => ['Mime/delete_hotlink:1', '1', 'mime', 'delete_hotlink_bridged'];
        yield 'object from a user addition' => ['direct:DirectoryPrivacy/add_user:1', '{"private":"x"}',
            'directory', 'add_user_direct'];
        yield 'object from a user removal' => ['DirectoryPrivacy/delete_user:1', '{"private":"x"}', 'directory',
            'delete_user_bridged'];
    }

    /** Listing rows carry account file names, so library and PHP traces must never show them. */
    public function testTheListingHelperKeepsItsArgumentOutOfTraces(): void
    {
        $parameter = new ReflectionParameter([MimeDirectoryFixture::class, 'named'], 'listing');
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
    }

    public function testAMissingOptInSendsNothing(): void
    {
        putenv('WHM_API_LIVE_MUTATIONS');
        $http = new MimeDirectoryServer();
        $scenario = self::scenario($http);

        self::assertSame([], $http->requests);
        self::assertSame(['configuration_failure'], $scenario['fixture']['failures']);
    }

    public function testAConfirmedFilesystemProofReplacesTheApiOnlyLimitation(): void
    {
        $proof = new class implements FilesystemAbsenceProof {
            private int $calls = 0;

            public function check(string $user, string $domain): array
            {
                return ++$this->calls === 1
                    ? ['outcome' => 'present', 'residue' => array_fill_keys(self::ACCOUNT_LOCATIONS, 1)]
                    : ['outcome' => 'confirmed_absent', 'residue' => []];
            }
        };
        $scenario = self::scenario(new MimeDirectoryServer(), $proof);

        self::assertSame('confirmed_absent', $scenario['fixture']['filesystem_cleanup']);
        self::assertSame('passed', $scenario['fixture']['filesystem_control']['status']);
        self::assertContains('filesystem_proof_covers_fixed_locations_at_one_moment', $scenario['limitations']);
        self::assertNotContains(
            'api_absence_only_no_filesystem_or_home_directory_cleanup_claim',
            $scenario['limitations'],
        );
    }

    /**
     * @param Scenario $scenario
     * @return Observation
     */
    private static function observation(array $scenario, string $phase): array
    {
        foreach ($scenario['probes'] as $record) {
            if ($record['phase'] === $phase) {
                return $record;
            }
        }
        self::fail('Missing observation.');
    }

    /** @param list<RequestInterface> $requests */
    private static function sentField(array $requests, string $uri, string $field): string
    {
        foreach ($requests as $request) {
            if (str_contains((string) $request->getUri(), $uri)) {
                parse_str((string) $request->getBody(), $fields);
                $value = $fields[$field] ?? null;
                self::assertIsString($value);

                return $value;
            }
        }
        self::fail('The fixture sent no such request.');
    }

    /** @return Scenario */
    private static function scenario(MimeDirectoryServer $http, ?FilesystemAbsenceProof $filesystem = null): array
    {
        return (new MimeDirectoryFixture($http, $http->streaming(), 'WHMTOKEN', self::context(), $filesystem))->run();
    }

    /** @return Context */
    public static function context(): array
    {
        return [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ];
    }

    /** @param array<mixed> $scenario */
    private static function assertPrivate(array $scenario): void
    {
        $json = json_encode($scenario, JSON_THROW_ON_ERROR);
        foreach (
            ['private', 'WHMTOKEN', 'PRIVATEFIXTURETOKEN', 'wlmime', 'example.', '/home', 'public_html', 'http:',
                'https:', 'realm', 'Basic', 'Synthetic', '1790000000'] as $value
        ) {
            self::assertStringNotContainsString($value, $json);
        }
    }
}
