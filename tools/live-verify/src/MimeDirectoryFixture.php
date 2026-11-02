<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\Upload;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Prove the eight Mime and four directory-access mutations on one fresh disposable account, once, then remove it.
 *
 * DisposableAccountLifecycle owns identity, ownership, the short-lived writable token, account cleanup and the run's
 * status, and states their rules. This fixture holds the two sections and their readbacks.
 *
 * The Mime section adds and removes a MIME type, a handler, a redirect and hotlink protection, each twice: round a
 * adds through direct UAPI and removes through the WHM bridge, round b the reverse. Every mutation is followed by a
 * read on the same route that must show exactly the expected row, or its absence, so each route reads back a populated
 * row and its removal. Hotlink protection is account-wide, which is acceptable on this run's own account.
 *
 * The directory section creates one fresh directory under public_html by uploading one synthetic file, the only
 * documented API that creates a directory, and proves it held nothing else. It then changes indexing three times,
 * enables and disables password protection on each route, and adds and removes one protected user on each route,
 * reading each change back. Leech protection has no UAPI mutation in the pinned surface, so it is read before and
 * after. The two user passwords exist only in memory.
 *
 * Within a section, capture stops at the first unexpected state, because later steps could not be interpreted. The
 * sections share no state, so a stopped Mime section does not withhold the directory section. Everything this run
 * changes lives inside the account, so account removal takes whatever a stopped section left. API readback is not
 * enforcement: no URL is fetched, so nothing here shows HTTP redirects, indexing, authentication or hotlink blocking.
 *
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Assessment from MimeDirectoryEvidence
 * @phpstan-import-type Transfer from FilemanUploadBodyStream
 * @phpstan-import-type Framing from StreamingTransport
 * @phpstan-import-type CleanupOutcome from DisposableAccountLifecycle
 * @phpstan-import-type ResourceOutcome from DisposableAccountLifecycle
 * @phpstan-import-type TokenOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemOutcome from DisposableAccountLifecycle
 * @phpstan-import-type FilesystemControl from DisposableAccountLifecycle
 * @phpstan-type Observation array{
 *     phase: string, section: string, expectation: 'passed'|'failed'|'not_checked', uapi_status: int|null,
 *     operation: string, variant: string, argument_variant?: string, route: string, method: string|null,
 *     server_version: string|null, observed_at: string, revision: string, source_sha256: string,
 *     map_sha256: string, generator_tree: string, overlay_sha256: string, effective_contract_sha256: string,
 *     http_status: int|null, api_outcome: string, payload_validation: string, probe_outcome: string,
 *     failure_kind: string|null, response_paths: array<string, list<string>>,
 *     payload_paths: array<string, list<string>>, discrepancies: list<array{path: string, kind: string}>,
 *     payload_alternatives?: list<Alternative>
 * }
 * @phpstan-type MutationSummary array{
 *     result: 'null'|'object'|'list'|'string'|'other', messages: int, warnings: int
 * }
 * @phpstan-type UploadSummary array{
 *     uapi_status: int|null, succeeded: int|null, failed: int|null, rows: int, stored_row_exact: bool,
 *     transfer: Transfer|null, framing: Framing|null
 * }
 * @phpstan-type SectionReport array{
 *     capture: 'passed'|'failed'|'not_run', expectations: 'passed'|'failed'|'not_run', stopped_at: string|null
 * }
 * @phpstan-type DirectoryOutcome array{
 *     creation: 'not_attempted'|'confirmed'|'unconfirmed', cleanup: 'not_required'|'absent_with_account'|'unconfirmed'
 * }
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed'|'not_started', capture: 'passed'|'failed'|'not_run',
 *     expectations: 'passed'|'failed'|'not_run', cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     sections: array{mime: SectionReport, directory: SectionReport},
 *     resources: array{account: ResourceOutcome, token: TokenOutcome, directory: DirectoryOutcome},
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome,
 *     filesystem_cleanup: FilesystemOutcome, filesystem_residue: array<string, int>,
 *     filesystem_control: FilesystemControl
 * }
 * @phpstan-type Scenario array{
 *     scenario: 'mime-directory-disposable-v1', target_role: 'disposable-test-account', probes: list<Observation>,
 *     mutations: array<string, MutationSummary>, list_rows: array<string, int>, upload: UploadSummary|null,
 *     discrepancy_review: Assessment, fixture: Report, limitations: list<string>
 * }
 */
final class MimeDirectoryFixture
{
    private const HOST = 'test.oblak.host';

    /** Long enough for both sections and short enough that a lost token dies quickly; cleanup still revokes it. */
    private const TOKEN_LIFETIME = 1200;

    private readonly MimeDirectoryRecipe $recipe;

    private readonly MimeDirectoryGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    private readonly Client $root;

    private readonly DisposableAccountLifecycle $lifecycle;

    /** @var Report */
    private array $report;

    private ?Client $direct = null;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<string, MutationSummary> */
    private array $mutations = [];

    /** @var array<string, int> */
    private array $listRows = [];

    /** @var UploadSummary|null */
    private ?array $upload = null;

    private bool $expectationFailed = false;

    /** Index of the current section's first record, so a stop names a phase of its own section. */
    private int $sectionStart = 0;

    /**
     * Bind the fixed disposable account and a token expiration, without I/O.
     *
     * The ordinary client must support form POST; the streaming transport receives only the one multipart upload.
     * Both must verify TLS and refuse redirects and retries. The guard re-checks both live opt-ins and the exact
     * account and domain on every request. Live callers must obtain committed context through RepositoryContext first.
     * Without a filesystem proof, nothing is claimed about the server's files.
     *
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] StreamingTransport $streaming,
        #[SensitiveParameter] string $whmToken,
        private readonly array $context,
        ?FilesystemAbsenceProof $filesystem = null,
    ) {
        $this->recipe = new MimeDirectoryRecipe(bin2hex(random_bytes(16)), DisposableAccount::fresh(
            MimeDirectoryRecipe::ACCOUNT,
            MimeDirectoryRecipe::DOMAIN,
            MimeDirectoryRecipe::TOKEN_NAME,
            self::TOKEN_LIFETIME,
            readOnlyToken: false,
        ));
        $this->guard = new MimeDirectoryGuard($http, $streaming, $whmToken, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $this->runner = new EvidenceRunner($this->guard, $context, MimeDirectoryRecipe::ACCOUNT);
        $factory = new Psr17Factory();
        $this->root = new Client([
            'host' => self::HOST, 'port' => 2087, 'authType' => 'whm', 'username' => 'root', 'token' => $whmToken,
        ], new HttpTransport($this->capture, $factory, $factory));
        $this->lifecycle = new DisposableAccountLifecycle(
            $this->recipe->account,
            $this->capture,
            $this->root,
            $filesystem,
        );
    }

    /**
     * Set up, capture both sections, clean up, and return sanitized evidence including every failure.
     *
     * This instance is single-use. Exception messages, raw payloads, returned credentials, passwords, names, paths
     * and URLs never enter the result; fixture success and the strict discrepancy assessment stay separate.
     *
     * @return Scenario
     */
    public function run(): array
    {
        $this->report = self::report();
        try {
            $outcome = $this->lifecycle->run(function (#[SensitiveParameter] string $token): void {
                $this->exercise($token);
            });
        } catch (LogicException) {
            $this->report['failures'][] = 'configuration_failure';

            return $this->scenario();
        }
        $lifecycle = DisposableAccountLifecycle::reportFields($outcome);
        $this->report = [
            ...$this->report,
            ...$lifecycle,
            'resources' => [...$this->report['resources'], ...$lifecycle['resources']],
        ];
        if ($this->report['resources']['directory']['creation'] !== 'not_attempted') {
            // Account removal takes the directory with everything this run changed inside it.
            $this->report['resources']['directory']['cleanup'] = $outcome['account']['cleanup'] === 'confirmed_absent'
                ? 'absent_with_account' : 'unconfirmed';
        }

        return $this->scenario();
    }

    /**
     * Bind the token, run both sections, and tell the lifecycle what failed.
     *
     * @throws ExerciseFailure After recording, for a failed capture or any failed expectation, even in a finished one.
     */
    private function exercise(#[SensitiveParameter] string $token): void
    {
        $this->report['capture'] = 'failed';
        try {
            $this->guard->bindToken($token);
            $factory = new Psr17Factory();
            $this->direct = new Client([
                'host' => self::HOST, 'port' => 2083, 'authType' => 'cpanel',
                'username' => MimeDirectoryRecipe::ACCOUNT, 'token' => $token,
            ], new HttpTransport($this->capture, $factory, $factory));
            $this->observe('version', static fn (Client $client): mixed => $client->whm()->server()->version());
        } catch (Throwable) {
            throw new ExerciseFailure(['capture_failure']);
        }
        $failures = [
            ...$this->section('mime', $this->mimeSection(...)),
            ...$this->section('directory', $this->directorySection(...)),
        ];
        $sections = $this->report['sections'];
        $captured = $sections['mime']['capture'] === 'passed' && $sections['directory']['capture'] === 'passed';
        $this->report['capture'] = $captured ? 'passed' : 'failed';
        $this->report['expectations'] = match (true) {
            in_array('failed', array_column($sections, 'expectations'), true) => 'failed',
            $captured => 'passed',
            default => 'not_run',
        };
        if ($failures !== []) {
            throw new ExerciseFailure($failures);
        }
    }

    /**
     * Run one section to its end or its first unexpected state, and return its failure labels.
     *
     * @param 'mime'|'directory' $section
     * @param Closure(): void $run
     * @return list<string>
     */
    private function section(string $section, Closure $run): array
    {
        $this->expectationFailed = false;
        $this->sectionStart = count($this->records);
        $report = ['capture' => 'failed', 'expectations' => 'not_run', 'stopped_at' => null];
        $failures = [];
        try {
            $run();
            $report['capture'] = 'passed';
        } catch (Throwable) {
            $own = array_slice($this->records, $this->sectionStart);
            $last = array_key_last($own);
            $report['stopped_at'] = $last === null ? null : $own[$last]['phase'];
            if (!$this->expectationFailed() || in_array('failed', array_column($own, 'probe_outcome'), true)) {
                $failures[] = $section . '_capture_failure';
            }
        }
        if ($this->expectationFailed()) {
            $report['expectations'] = 'failed';
            $failures[] = $section . '_expectation_failure';
        } elseif ($report['capture'] === 'passed') {
            $report['expectations'] = 'passed';
        }
        $this->report['sections'][$section] = $report;

        return $failures;
    }

    private function mimeSection(): void
    {
        $recipe = $this->recipe;
        $this->expect(self::ours($this->listMime('mime_types_baseline'), 'type', $recipe->mimeTypes) === 0);
        $this->expect(self::ours($this->listHandlers('handlers_baseline'), 'extension', $recipe->handlerExtensions)
            === 0);
        $this->expect(self::ours($this->listRedirects('redirects_baseline'), 'sourceurl', $recipe->redirectSources)
            === 0);
        $this->expectDomainRedirect('redirect_domain_baseline', null);
        $this->expectHotlinks('hotlinks_baseline', null);

        foreach (['a' => ['direct', 'bridged'], 'b' => ['bridged', 'direct']] as $round => [$add, $remove]) {
            $type = $recipe->mimeTypes[$round];
            $extension = $recipe->mimeExtensions[$round];
            $this->mutate('add_mime_' . $add, static fn (UapiClient $account): mixed =>
                $account->mime()->addMime(extension: $extension, type: $type));
            $this->expect(self::matching($this->listMime('mime_types_after_add_' . $add), [
                'type' => $type, 'extension' => $extension, 'origin' => 'user',
            ]) === 1);
            $this->mutate('delete_mime_' . $remove, static fn (UapiClient $account): mixed =>
                $account->mime()->deleteMime(type: $type));
            $this->expect(self::matching($this->listMime('mime_types_after_delete_' . $remove), ['type' => $type])
                === 0);
        }

        foreach (['a' => ['direct', 'bridged'], 'b' => ['bridged', 'direct']] as $round => [$add, $remove]) {
            $extension = $recipe->handlerExtensions[$round];
            $handler = $recipe->handlers[$round];
            $this->mutate('add_handler_' . $add, static fn (UapiClient $account): mixed =>
                $account->mime()->addHandler(extension: $extension, handler: $handler));
            $this->expect(self::matching($this->listHandlers('handlers_after_add_' . $add), [
                'extension' => $extension, 'handler' => $handler, 'origin' => 'user',
            ]) === 1);
            $this->mutate('delete_handler_' . $remove, static fn (UapiClient $account): mixed =>
                $account->mime()->deleteHandler(extension: $extension));
            $this->expect(self::matching(
                $this->listHandlers('handlers_after_delete_' . $remove),
                ['extension' => $extension],
            ) === 0);
        }

        foreach (['a' => ['direct', 'bridged'], 'b' => ['bridged', 'direct']] as $round => [$add, $remove]) {
            $source = $recipe->redirectSources[$round];
            $target = $recipe->redirectTargets[$round];
            $this->mutate('add_redirect_' . $add, $round === 'a'
                ? static fn (UapiClient $account): mixed => $account->mime()->addRedirect(
                    domain: MimeDirectoryRecipe::DOMAIN,
                    redirect: $target,
                    src: $source,
                )
                : static fn (UapiClient $account): mixed => $account->mime()->addRedirect(
                    domain: MimeDirectoryRecipe::DOMAIN,
                    redirect: $target,
                    redirectWildcard: false,
                    redirectWww: 0,
                    src: $source,
                    type: 'temp',
                ));
            $this->expect(self::matching($this->listRedirects('redirects_after_add_' . $add), [
                'sourceurl' => $source, 'source' => $source, 'destination' => $target, 'targeturl' => $target,
                'domain' => MimeDirectoryRecipe::DOMAIN, 'docroot' => MimeDirectoryRecipe::DOCROOT, 'kind' => 'rewrite',
                'type' => $round === 'a' ? 'permanent' : 'temporary', 'statuscode' => $round === 'a' ? '301' : '302',
                'wildcard' => 0,
            ]) === 1);
            $this->expectDomainRedirect('redirect_domain_after_add_' . $add, $target);
            $this->mutate('delete_redirect_' . $remove, $round === 'a'
                ? static fn (UapiClient $account): mixed => $account->mime()->deleteRedirect(
                    domain: MimeDirectoryRecipe::DOMAIN,
                    src: $source,
                )
                : static fn (UapiClient $account): mixed => $account->mime()->deleteRedirect(
                    domain: MimeDirectoryRecipe::DOMAIN,
                    docroot: MimeDirectoryRecipe::DOCROOT,
                    src: $source,
                ));
            $this->expect(self::matching(
                $this->listRedirects('redirects_after_delete_' . $remove),
                ['sourceurl' => $source],
            ) === 0);
            $this->expectDomainRedirect('redirect_domain_after_delete_' . $remove, null);
        }

        foreach (['a' => ['direct', 'bridged'], 'b' => ['bridged', 'direct']] as $round => [$add, $remove]) {
            $extensions = $recipe->hotlinkExtensions[$round];
            $target = $recipe->hotlinkTargets[$round];
            $urls = $recipe->hotlinkUrls($round);
            $this->mutate('add_hotlink_' . $add, static fn (UapiClient $account): mixed => $account->mime()->addHotlink(
                extensions: $extensions,
                redirectUrl: $target,
                urls: $urls,
                allowNull: $round === 'a',
            ));
            $this->expectHotlinks('hotlinks_after_add_' . $add, $round);
            $this->mutate('delete_hotlink_' . $remove, static fn (UapiClient $account): mixed =>
                $account->mime()->deleteHotlink());
            $this->expectHotlinks('hotlinks_after_delete_' . $remove, null);
        }
    }

    private function directorySection(): void
    {
        $recipe = $this->recipe;
        $absolute = $recipe->absoluteDirectory();
        $listing = $this->call(
            'docroot_listing',
            static fn (UapiClient $account): mixed => $account->call('Fileman', 'list_files', [
                'dir' => MimeDirectoryRecipe::DOCROOT, 'types' => 'dir|file',
            ], RequestMethod::Get)->data,
            false,
        );
        $this->expect($this->listingIsReal() && !isset($this->named($listing)[$recipe->directory]));

        $this->report['resources']['directory']['creation'] = 'unconfirmed';
        $file = Upload::fromString($recipe->uploadName, MimeDirectoryRecipe::UPLOAD_BYTES);
        $result = $this->mutate('create_directory', static fn (UapiClient $account): mixed =>
            $account->fileman()->uploadFiles(
                files: [$file],
                dir: $recipe->uploadDirectory(),
                overwrite: false,
                permissions: MimeDirectoryRecipe::UPLOAD_PERMISSIONS,
            ));
        $this->expect($this->uploadStored($result));

        $inventory = $this->named($this->call('directory_inventory', static fn (UapiClient $account): mixed =>
            $account->fileman()->listFiles(dir: $absolute, showHidden: true)));
        $row = $inventory[$recipe->uploadName] ?? null;
        $this->expect(count($inventory) === 1 && is_array($row) && ($row['type'] ?? null) === 'file'
            && ($row['fullpath'] ?? null) === $absolute . '/' . $recipe->uploadName
            && in_array($row['size'] ?? null, [strlen(MimeDirectoryRecipe::UPLOAD_BYTES),
                (string) strlen(MimeDirectoryRecipe::UPLOAD_BYTES)], true));
        $this->report['resources']['directory']['creation'] = 'confirmed';

        $this->expectLeech('leech_initial');
        $this->expectIndexing('indexing_initial', 'inherit');
        $this->expectProtection('privacy_initial', null);

        foreach (
            ['set_indexing_direct' => ['indexing_after_set_direct', 'disabled'],
                'set_indexing_bridged' => ['indexing_after_set_bridged', 'fancy'],
                'restore_indexing_direct' => ['indexing_after_restore_direct', 'inherit']] as $phase => [$read, $type]
        ) {
            $set = $this->mutate($phase, static fn (UapiClient $account): mixed =>
                $account->directoryIndexes()->setIndexing(dir: $absolute, type: $type));
            $this->expect($set === $type);
            $this->expectIndexing($read, $type);
            if ($phase === 'set_indexing_bridged') {
                $listed = $this->call('indexes_listing_bridged', static fn (UapiClient $account): mixed =>
                    $account->directoryIndexes()->listDirectories(dir: $absolute));
                $this->expect(self::current($listed, $absolute, 'index_type') === 'fancy');
            }
        }

        $this->protect('protect_direct', 'privacy_after_protect_direct', $recipe->authNames['a']);
        $listed = $this->call('privacy_listing_direct', static fn (UapiClient $account): mixed =>
            $account->directoryPrivacy()->listDirectories(dir: $absolute));
        $this->expect(self::current($listed, $absolute, 'protected') === 1
            && self::current($listed, $absolute, 'auth_name') === $recipe->authNames['a']);
        // Each phase: its readback, the user it adds or removes, whether it adds, and the users that must remain.
        $steps = [
            'add_user_direct' => ['users_after_add_direct', 'one', true, ['one']],
            'add_user_bridged' => ['users_after_add_bridged', 'two', true, ['one', 'two']],
            'delete_user_bridged' => ['users_after_delete_bridged', 'one', false, ['two']],
            'delete_user_direct' => ['users_after_delete_direct', 'two', false, []],
        ];
        foreach ($steps as $phase => [$read, $user, $add, $expected]) {
            $name = $recipe->users[$user];
            $this->mutate($phase, $add
                ? static fn (UapiClient $account): mixed => $account->directoryPrivacy()->addUser(
                    dir: $absolute,
                    user: $name,
                    password: $recipe->userPassword($user),
                )
                : static fn (UapiClient $account): mixed => $account->directoryPrivacy()->deleteUser(
                    dir: $absolute,
                    user: $name,
                ));
            $users = $this->call($read, static fn (UapiClient $account): mixed =>
                $account->directoryPrivacy()->listUsers(dir: $absolute));
            $this->expect($users === array_map(static fn (string $label): string => $recipe->users[$label], $expected));
        }
        $this->protect('unprotect_bridged', 'privacy_after_unprotect_bridged', null);
        $this->protect('protect_bridged', 'privacy_after_protect_bridged', $recipe->authNames['b']);
        $this->protect('unprotect_direct', 'privacy_after_unprotect_direct', null);
        $this->expectLeech('leech_final');
    }

    /** Enable protection with $realm, or disable it with null, then read it back on the same route. */
    private function protect(string $phase, string $read, ?string $realm): void
    {
        $absolute = $this->recipe->absoluteDirectory();
        $result = $this->mutate($phase, static fn (UapiClient $account): mixed =>
            $account->directoryPrivacy()->configureDirectoryProtection(
                dir: $absolute,
                enabled: $realm !== null,
                authname: $realm,
            ));
        $state = is_array($result) ? $result : [];
        $this->expect($realm === null
            ? ($state['protected'] ?? null) === 0
            : ($state['protected'] ?? null) === 1 && ($state['auth_name'] ?? null) === $realm
                && ($state['auth_type'] ?? null) === 'Basic' && is_string($state['passwd_file'] ?? null)
                && $state['passwd_file'] !== '');
        $this->expectProtection($read, $realm);
    }

    /** Require directory protection off, or on with exactly $realm and Basic authentication. */
    private function expectProtection(string $phase, ?string $realm): void
    {
        $absolute = $this->recipe->absoluteDirectory();
        $state = $this->call($phase, static fn (UapiClient $account): mixed =>
            $account->directoryPrivacy()->isDirectoryProtected(dir: $absolute));
        $state = is_array($state) ? $state : [];
        $this->expect($realm === null
            ? ($state['protected'] ?? null) === 0
            : ($state['protected'] ?? null) === 1 && ($state['auth_name'] ?? null) === $realm
                && ($state['auth_type'] ?? null) === 'Basic');
    }

    private function expectIndexing(string $phase, string $type): void
    {
        $absolute = $this->recipe->absoluteDirectory();
        $this->expect($this->call($phase, static fn (UapiClient $account): mixed =>
            $account->directoryIndexes()->getIndexing(dir: $absolute)) === $type);
    }

    private function expectLeech(string $phase): void
    {
        $absolute = $this->recipe->absoluteDirectory();
        $listed = $this->call($phase, static fn (UapiClient $account): mixed =>
            $account->directoryProtection()->listDirectories(dir: $absolute));
        $this->expect(self::current($listed, $absolute, 'has_leech_protection') === 0
            && is_array($listed) && ($listed['children'] ?? null) === []);
    }

    /** Require the domain's redirect to be exactly $target, or absent with a null URL. */
    private function expectDomainRedirect(string $phase, ?string $target): void
    {
        $state = $this->call($phase, static fn (UapiClient $account): mixed =>
            $account->mime()->getRedirect(domain: MimeDirectoryRecipe::DOMAIN));
        $state = is_array($state) ? $state : [];
        $this->expect(($state['redirection_enabled'] ?? null) === ($target === null ? 0 : 1)
            && array_key_exists('url', $state) && $state['url'] === $target);
    }

    /**
     * Require hotlink protection disabled, or enabled with exactly one round's settings.
     *
     * @param 'a'|'b'|null $round
     */
    private function expectHotlinks(string $phase, ?string $round): void
    {
        $state = $this->call($phase, static fn (UapiClient $account): mixed => $account->mime()->listHotlinks());
        $state = is_array($state) ? $state : [];
        if ($round === null) {
            $this->expect(($state['state'] ?? null) === 'disabled' && array_key_exists('redirect_url', $state)
                && $state['redirect_url'] === null);

            return;
        }
        $this->expect(($state['state'] ?? null) === 'enabled' && ($state['allow_null'] ?? null) === ($round === 'a'
            ? 1 : 0) && ($state['extensions'] ?? null) === $this->recipe->hotlinkExtensions[$round]
            && ($state['redirect_url'] ?? null) === $this->recipe->hotlinkTargets[$round]
            && ($state['urls'] ?? null) === MimeDirectoryRecipe::HOTLINK_URLS[$round]);
    }

    /** @return list<array<mixed>> */
    private function listMime(string $phase): array
    {
        return $this->rows($phase, static fn (UapiClient $account): mixed => $account->mime()->listMime(type: 'user'));
    }

    /** @return list<array<mixed>> */
    private function listHandlers(string $phase): array
    {
        return $this->rows($phase, static fn (UapiClient $account): mixed =>
            $account->mime()->listHandlers(type: 'user'));
    }

    /** @return list<array<mixed>> */
    private function listRedirects(string $phase): array
    {
        return $this->rows($phase, static fn (UapiClient $account): mixed => $account->mime()->listRedirects());
    }

    /**
     * Read one list, require every row to be an object, and record only its row count.
     *
     * @param Closure(UapiClient): mixed $invoke
     * @return list<array<mixed>>
     */
    private function rows(string $phase, Closure $invoke): array
    {
        $rows = $this->call($phase, $invoke);
        $this->expect(is_array($rows) && array_is_list($rows));
        /** @var list<mixed> $rows */
        $checked = array_values(array_filter($rows, 'is_array'));
        $this->expect(count($checked) === count($rows));
        $this->listRows[$phase] = count($checked);

        return $checked;
    }

    /**
     * Count rows whose $field equals any of this run's values; anything else on the account is not this run's.
     *
     * @param list<array<mixed>> $rows
     * @param array<string, string> $values
     */
    private static function ours(array $rows, string $field, array $values): int
    {
        return count(array_filter($rows, static fn (array $row): bool =>
            in_array($row[$field] ?? null, $values, true)));
    }

    /**
     * Count rows that hold every expected field exactly.
     *
     * @param list<array<mixed>> $rows
     * @param array<string, mixed> $fields
     */
    private static function matching(array $rows, array $fields): int
    {
        return count(array_filter($rows, static function (array $row) use ($fields): bool {
            foreach ($fields as $name => $value) {
                if (!array_key_exists($name, $row) || $row[$name] !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** One state field of a directory listing's current entry, only when that entry is the expected directory. */
    private static function current(mixed $listed, string $path, string $field): mixed
    {
        $current = is_array($listed) ? ($listed['current'] ?? null) : null;
        $state = is_array($current) && ($current['path'] ?? null) === $path ? ($current['state'] ?? null) : null;

        return is_array($state) ? ($state[$field] ?? null) : null;
    }

    /** Require the upload's one row to show exactly this run's file stored, at UAPI status 1. */
    private function uploadStored(mixed $result): bool
    {
        $payload = is_array($result) ? $result : [];
        $rows = is_array($payload['uploads'] ?? null) ? $payload['uploads'] : [];
        $row = count($rows) === 1 ? reset($rows) : null;
        $exact = is_array($row) && ($row['file'] ?? null) === $this->recipe->uploadName
            && ($row['status'] ?? null) === 1 && ($row['size'] ?? null) === strlen(MimeDirectoryRecipe::UPLOAD_BYTES)
            && ($row['warnings'] ?? []) === [];
        $delivery = $this->guard->transfer();
        $this->upload = [
            'uapi_status' => $this->last()['uapi_status'],
            'succeeded' => is_int($payload['succeeded'] ?? null) ? $payload['succeeded'] : null,
            'failed' => is_int($payload['failed'] ?? null) ? $payload['failed'] : null,
            'rows' => count($rows),
            'stored_row_exact' => $exact,
            'transfer' => $delivery['transfer'] ?? null,
            'framing' => $delivery['framing'] ?? null,
        ];
        $transfer = $this->upload['transfer'];
        $framing = $this->upload['framing'];

        return $exact && $this->upload['uapi_status'] === 1 && $this->upload['succeeded'] === 1
            && $this->upload['failed'] === 0 && $transfer !== null && $transfer['body_exact'] && !$transfer['buffered']
            && !$transfer['sought'] && $framing !== null && !in_array(false, $framing, true);
    }

    /**
     * Decide from the original response whether a listing is a real one.
     *
     * Decoded to arrays, `{}` is the same empty array as a JSON list, and an empty object cannot prove absence. Only a
     * JSON list or an object holding both documented lists can.
     */
    private function listingIsReal(): bool
    {
        $raw = $this->capture->rawBody();
        if ($raw === null) {
            return false;
        }
        $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $envelope = $document instanceof stdClass && property_exists($document, 'result')
            ? $document->result : $document;
        $data = $envelope instanceof stdClass ? ($envelope->data ?? null) : null;

        return is_array($data)
            || ($data instanceof stdClass && is_array($data->dirs ?? null) && is_array($data->files ?? null));
    }

    /**
     * Key listing rows by unique exact name, reading both documented listing forms.
     *
     * @return array<string, array<mixed>>
     */
    private function named(mixed $listing): array
    {
        $this->expect(is_array($listing));
        /** @var array<mixed> $listing */
        $dirs = $listing['dirs'] ?? [];
        $files = $listing['files'] ?? [];
        $rows = array_is_list($listing) ? $listing : [
            ...(is_array($dirs) ? array_values($dirs) : []), ...(is_array($files) ? array_values($files) : []),
        ];
        $named = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['file'] ?? null) : null;
            $this->expect(is_string($name) && $name !== '' && !isset($named[$name]));
            /** @var string $name */
            /** @var array<mixed> $row */
            if ($name !== '.' && $name !== '..' && ($row['isparent'] ?? 0) !== 1) {
                $named[$name] = $row;
            }
        }

        return $named;
    }

    /**
     * Send one mutation phase and record only the kind of its result and how many messages and warnings it carried.
     *
     * @param Closure(UapiClient): mixed $invoke
     */
    private function mutate(string $phase, Closure $invoke): mixed
    {
        $result = $this->call($phase, $invoke);
        $metadata = $this->handle(MimeDirectoryRecipe::PHASES[$phase][1])->responseMeta();
        $this->mutations[$phase] = [
            'result' => match (true) {
                $result === null => 'null',
                is_array($result) && $result !== [] && array_is_list($result) => 'list',
                is_array($result) => 'object',
                is_string($result) => 'string',
                default => 'other',
            },
            'messages' => $metadata === null ? 0 : count($metadata->uapiMessages()),
            'warnings' => $metadata === null ? 0 : count($metadata->uapiWarnings()),
        ];

        return $result;
    }

    /**
     * Capture one UAPI phase on its recipe route.
     *
     * @param Closure(UapiClient): mixed $invoke
     */
    private function call(string $phase, Closure $invoke, bool $validated = true): mixed
    {
        $bridged = MimeDirectoryRecipe::PHASES[$phase][1] === 'bridged-uapi';

        return $this->observe(
            $phase,
            static fn (Client $client): mixed => $invoke($client->uapi($bridged ? MimeDirectoryRecipe::ACCOUNT : null)),
            $validated,
        );
    }

    /**
     * Capture one phase through the shared runner, authorized for exactly its own request.
     *
     * The runner clears prior capture and never retries; the record carries only structural evidence. It reports
     * validation passed for any call that returned, but only a typed call has a validator, so a generic call's record
     * says not_checked. Structural discrepancies from the same response still reach the strict review.
     *
     * @param Closure(Client): mixed $invoke
     */
    private function observe(string $phase, Closure $invoke, bool $validated = true): mixed
    {
        [$section, $route] = MimeDirectoryRecipe::PHASES[$phase];
        $contract = MimeDirectoryEvidence::contract($phase);
        $client = $route === 'direct-uapi'
            ? ($this->direct ?? throw new LogicException('Mime directory client unavailable.')) : $this->root;
        $result = null;
        $probe = new Probe(
            $contract['operation'],
            $route,
            $contract['spec'],
            $this->recipe->wire($phase)['uri'],
            static function (#[SensitiveParameter] Client $client) use ($invoke, &$result): mixed {
                return $result = $invoke($client);
            },
            null,
            $contract['variant'],
        );
        $this->guard->authorize($phase);
        try {
            $record = $this->runner->capture($probe, $this->capture, $client);
        } finally {
            $this->guard->clearPending();
        }
        if (!$validated) {
            $record['payload_validation'] = 'not_checked';
        }
        $metadata = $route === 'whm' ? null : $this->handle($route)->responseMeta();
        $this->records[] = [...$record, 'phase' => $phase, 'section' => $section,
            'uapi_status' => $metadata?->uapiStatus(),
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked'];
        if ($record['probe_outcome'] !== 'passed') {
            throw new RuntimeException('Mime directory observation failed.');
        }

        return $result;
    }

    private function handle(string $route): UapiClient
    {
        return $route === 'direct-uapi'
            ? ($this->direct ?? throw new LogicException('Mime directory client unavailable.'))->uapi()
            : $this->root->uapi(MimeDirectoryRecipe::ACCOUNT);
    }

    /** Whether the current section has failed an expectation; expect() sets it inside the section's own closure. */
    private function expectationFailed(): bool
    {
        return $this->expectationFailed;
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $this->expectationFailed = true;
            $index = array_key_last($this->records);
            if ($index !== null && $index >= $this->sectionStart) {
                $this->records[$index]['expectation'] = 'failed';
            }
            throw new RuntimeException('Mime directory expectation failed.');
        }
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('Mime directory observation unavailable.');
        }

        return $this->records[$index];
    }

    /** @return Report */
    private static function report(): array
    {
        $resource = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];
        $section = ['capture' => 'not_run', 'expectations' => 'not_run', 'stopped_at' => null];

        return [
            'status' => 'failed', 'setup' => 'not_started', 'capture' => 'not_run',
            'expectations' => 'not_run', 'cleanup' => 'not_required', 'failures' => [],
            'sections' => ['mime' => $section, 'directory' => $section],
            'resources' => ['account' => $resource, 'token' => $resource, 'directory' => $resource],
            'domain_cleanup' => 'not_required', 'zone_cleanup' => 'not_required',
            'filesystem_cleanup' => 'not_claimed', 'filesystem_residue' => [],
            'filesystem_control' => ['status' => 'not_run', 'counts' => []],
        ];
    }

    /**
     * Assemble the sanitized result, attributing every observation to the run's one server version.
     *
     * @return Scenario
     */
    private function scenario(): array
    {
        $versions = array_column(array_filter($this->records, static fn (array $record): bool =>
            $record['phase'] === 'version'), 'server_version');
        $records = $this->records;
        foreach ($records as &$record) {
            $record['server_version'] ??= is_string($versions[0] ?? null) ? $versions[0] : null;
        }
        unset($record);

        return [
            'scenario' => 'mime-directory-disposable-v1',
            'target_role' => 'disposable-test-account',
            'probes' => $records,
            'mutations' => $this->mutations,
            'list_rows' => $this->listRows,
            'upload' => $this->upload,
            'discrepancy_review' => MimeDirectoryEvidence::assess($records, $this->context),
            'fixture' => $this->report,
            'limitations' => [
                'one_server_version_only',
                'api_readback_is_not_enforcement_no_url_fetched',
                'no_http_redirect_hotlink_indexing_or_authentication_behavior',
                'leech_protection_read_only_no_uapi_mutation_in_pinned_surface',
                'observed_argument_variants_only',
                $this->report['filesystem_cleanup'] === 'confirmed_absent'
                    ? 'filesystem_proof_covers_fixed_locations_at_one_moment'
                    : 'api_absence_only_no_filesystem_or_home_directory_cleanup_claim',
            ],
        ];
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** Fixture state contains credentials and cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Mime directory fixture state cannot be serialized.');
    }
}
