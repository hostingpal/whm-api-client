<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Exception\TransportException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\Upload;
use HostingPal\WhmApiClient\WhmClient;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Prove typed Fileman uploads on one freshly created disposable account, once, then remove the account.
 *
 * Follows the finite-Quota lifecycle: raw identity lists must establish initial account, manual-domain and zone
 * absence, and a random contact-email marker, held only in memory, proves account ownership. An existing account is
 * never adopted, and a failed creation never authorizes removing anything the marker does not match. No mutation is
 * retried. Setup creates a short-lived writable token; it lives in memory only.
 *
 * Capture stops at the first unexpected state, because later uploads could not be interpreted. The batches with rows
 * run first, each gated by its rows and an inventory, so a problem in the readback endpoint, which has no earlier
 * observation, costs no row evidence; one read then proves both byte fidelity and that overwrite=0 kept the originals.
 * The empty file goes last, alone, as its row representation is the least certain and nothing depends on it.
 * Ownership of a file needs fresh directory absence, the original validated upload row and a matching byte readback
 * for this run, never a flag or a later inventory alone. Only proven-owned files are deleted individually; the
 * upstream-recursive directory delete runs only after an unfiltered inventory, hidden entries included, proves it
 * empty. Anything unproven stays for account removal and is reported. File cleanup failure does not prevent token and
 * account cleanup, and token, account, domain and zone absence are each checked through the API. That is the whole
 * claim: no filesystem or home-directory cleanup is claimed, and none is checked.
 *
 * Fileman/get_file_content transcodes, so readback is a documented reversible check: the file is read as ISO-8859-1
 * into UTF-8 and compared with the independent mapping in FilemanUploadRecipe, never with a size alone.
 *
 * @phpstan-import-type Alternative from EvidenceRunner
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Label from FilemanUploadRecipe
 * @phpstan-import-type UploadPhase from FilemanUploadRecipe
 * @phpstan-import-type Transfer from FilemanUploadBodyStream
 * @phpstan-import-type Framing from StreamingTransport
 * @phpstan-import-type Assessment from FilemanUploadEvidence
 * @phpstan-type Observation array{
 *     phase: string, expectation: 'passed'|'failed'|'not_checked', uapi_status: int|null,
 *     operation: string, variant: string, argument_variant?: string, route: string, method: string|null,
 *     server_version: string|null, observed_at: string, revision: string, source_sha256: string,
 *     map_sha256: string, generator_tree: string, overlay_sha256: string, effective_contract_sha256: string,
 *     http_status: int|null, api_outcome: string, payload_validation: string, probe_outcome: string,
 *     failure_kind: string|null, response_paths: array<string, list<string>>,
 *     payload_paths: array<string, list<string>>, discrepancies: list<array{path: string, kind: string}>,
 *     payload_alternatives?: list<Alternative>
 * }
 * @phpstan-type CleanupOutcome 'not_required'|'confirmed_absent'|'present'|'unconfirmed'
 * @phpstan-type ResourceOutcome array{
 *     creation: 'not_attempted'|'confirmed'|'unconfirmed', cleanup: CleanupOutcome
 * }
 * @phpstan-type UploadSummary array{
 *     surface: 'typed'|'generic', uapi_status: int|null, envelope_errors: int, succeeded: int|null, failed: int|null,
 *     warned: int|null, rows: int, stored_rows_exact: bool, refused_rows_exact: bool,
 *     diskinfo: 'present'|'absent', transfer: Transfer|null, framing: Framing|null
 * }
 * @phpstan-type OracleResult array{
 *     expected_bytes: int, expected_utf8_bytes: int, returned_utf8_bytes: int|null, content_exact: bool,
 *     first_difference: int|null, from_charset_echo: 'exact'|'lowercase'|'other',
 *     to_charset_echo: 'exact'|'lowercase'|'other'
 * }
 * @phpstan-type Report array{
 *     status: 'passed'|'failed', setup: 'passed'|'failed'|'not_started', capture: 'passed'|'failed'|'not_run',
 *     expectations: 'passed'|'failed'|'not_run', cleanup: 'passed'|'failed'|'not_required', failures: list<string>,
 *     stopped_at: string|null,
 *     resources: array{
 *         account: ResourceOutcome, token: ResourceOutcome, directory: ResourceOutcome,
 *         files: array{proven_owned: int, deleted: int}
 *     },
 *     domain_cleanup: CleanupOutcome, zone_cleanup: CleanupOutcome, filesystem_cleanup: 'not_claimed'
 * }
 * @phpstan-type Scenario array{
 *     scenario: 'fileman-upload-disposable-v2', target_role: 'disposable-test-account', probes: list<Observation>,
 *     uploads: array<string, UploadSummary>, byte_oracle: array<string, OracleResult>,
 *     borrowed_streams: array<string, bool>, discrepancy_review: Assessment, fixture: Report, limitations: list<string>
 * }
 */
final class FilemanUploadFixture
{
    private const HOST = 'test.oblak.host';

    /** Long enough for this run and short enough that a lost token dies quickly; cleanup still revokes it. */
    private const TOKEN_LIFETIME = 900;

    private const IDENTITY_READS = 3;

    private const REREAD_PAUSE_MICROSECONDS = 250000;

    private readonly FilemanUploadRecipe $recipe;

    private readonly FilemanUploadGuard $guard;

    private readonly CaptureClient $capture;

    private readonly EvidenceRunner $runner;

    private readonly Client $root;

    private readonly WhmClient $whm;

    private readonly UapiClient $account;

    private ?Client $direct = null;

    /** @var array<Label, true> */
    private array $owned = [];

    private bool $directoryOwned = false;

    /** @var list<Observation> */
    private array $records = [];

    /** @var array<string, UploadSummary> */
    private array $uploads = [];

    /** @var array<string, OracleResult> */
    private array $oracle = [];

    /** @var array<string, bool> */
    private array $borrowed = [];

    private bool $expectationFailed = false;

    private bool $creationOutcomeUnknown = false;

    private bool $used = false;

    /**
     * Bind the fixed disposable account and a token expiration, without I/O.
     *
     * The ordinary client must support form POST; the streaming transport receives only multipart bodies. Both must
     * verify TLS and refuse redirects and retries. The guard re-checks both live opt-ins and the exact account and
     * domain on every request. Live callers must obtain committed context through RepositoryContext first.
     *
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] StreamingTransport $streaming,
        #[SensitiveParameter] string $whmToken,
        private readonly array $context,
    ) {
        $this->recipe = new FilemanUploadRecipe(bin2hex(random_bytes(16)), time() + self::TOKEN_LIFETIME);
        $this->guard = new FilemanUploadGuard($http, $streaming, $whmToken, $this->recipe);
        $this->capture = new CaptureClient($this->guard);
        $this->runner = new EvidenceRunner($this->guard, $context, FilemanUploadRecipe::ACCOUNT);
        $factory = new Psr17Factory();
        $this->root = new Client([
            'host' => self::HOST, 'port' => 2087, 'authType' => 'whm', 'username' => 'root', 'token' => $whmToken,
        ], new HttpTransport($this->capture, $factory, $factory));
        $this->whm = $this->root->whm();
        $this->account = $this->root->uapi(FilemanUploadRecipe::ACCOUNT);
    }

    /**
     * Set up, capture, clean up, and return sanitized evidence including every failure.
     *
     * This instance is single-use. Exception messages, raw payloads, returned credentials, file bytes, filenames
     * and paths never enter the result; fixture success and the strict discrepancy assessment stay separate.
     *
     * @return Scenario
     */
    public function run(): array
    {
        $report = self::report();
        try {
            if ($this->used) {
                throw new RuntimeException('Fileman upload fixture is single-use.');
            }
            FilemanUploadGuard::requireEnvironment();
        } catch (Throwable) {
            $report['failures'][] = 'configuration_failure';

            return $this->scenario($report);
        }
        $this->used = true;
        try {
            $report['setup'] = 'failed';
            $this->setup($report);
            $report['setup'] = 'passed';
            $report['capture'] = 'failed';
            $report['expectations'] = 'failed';
            $this->exercise($report);
            $report['capture'] = 'passed';
            $report['expectations'] = 'passed';
        } catch (Throwable) {
            $report['failures'][] = match (true) {
                $report['setup'] !== 'passed' => 'setup_failure',
                $this->expectationFailed => 'expectation_failure',
                default => 'capture_failure',
            };
            $last = array_key_last($this->records);
            $report['stopped_at'] = $last === null ? null : $this->records[$last]['phase'];
        } finally {
            $this->cleanup($report);
        }
        $report['failures'] = array_values(array_unique($report['failures']));
        if ($report['failures'] === [] && $report['expectations'] === 'passed' && $report['cleanup'] === 'passed') {
            $report['status'] = 'passed';
        }

        return $this->scenario($report);
    }

    /** @param Report $report */
    private function setup(array &$report): void
    {
        if ($this->accountExists() || $this->domainExists() || $this->zoneExists()) {
            throw new RuntimeException('Fileman upload fixture identity is already present.');
        }
        $report['resources']['account']['creation'] = 'unconfirmed';
        try {
            $this->whm->call('createacct', [
                'username' => FilemanUploadRecipe::ACCOUNT, 'domain' => FilemanUploadRecipe::DOMAIN,
                'contactemail' => $this->recipe->contactEmail,
                'quota' => 64, 'forcedns' => 0, 'hasshell' => 0, 'ip' => 'n',
            ], RequestMethod::Post);
        } catch (TransportException $failure) {
            // The server may still be working: absence read now cannot prove the account will stay absent.
            $this->creationOutcomeUnknown = true;
            throw $failure;
        }
        $report['resources']['account']['creation'] = 'confirmed';
        if (!$this->accountExists() || $this->tokenExists()) {
            throw new RuntimeException('Fileman upload fixture setup identities are unusable.');
        }
        $report['resources']['token']['creation'] = 'unconfirmed';
        $result = $this->account->call('Tokens', 'create_full_access', [
            'name' => FilemanUploadRecipe::TOKEN_NAME, 'expires_at' => $this->recipe->expiresAt,
        ], RequestMethod::Post);
        $report['resources']['token']['creation'] = 'confirmed';
        $token = is_array($result->data) ? ($result->data['token'] ?? null) : null;
        if (!is_string($token)) {
            throw new RuntimeException('Fileman upload fixture token was not returned.');
        }
        $this->confirmTokenCapabilities();
        $this->guard->bindToken($token);
        $factory = new Psr17Factory();
        $this->direct = new Client([
            'host' => self::HOST, 'port' => 2083, 'authType' => 'cpanel',
            'username' => FilemanUploadRecipe::ACCOUNT, 'token' => $token,
        ], new HttpTransport($this->capture, $factory, $factory));
    }

    /** @param Report $report */
    private function exercise(array &$report): void
    {
        $recipe = $this->recipe;
        $direct = $this->direct ?? throw new LogicException('Fileman upload client unavailable.');
        $this->succeed('version', new Probe(
            'whm1:version',
            'whm',
            ServerSpec::VERSION,
            'https://test.oblak.host:2087/json-api/version?api.version=1',
            static fn (#[SensitiveParameter] Client $client): mixed => $client->whm()->server()->version(),
        ), $this->root);

        $home = $this->homeRows('home_initial', $direct);
        $this->expect(!isset($home[$recipe->directory]));

        $report['resources']['directory']['creation'] = 'unconfirmed';
        $cursor = self::borrowedStream('cursor');
        $source = self::temporarySource();
        try {
            $uploads = [
                Upload::fromString($recipe->names['bytes'], FilemanUploadRecipe::contents('bytes')),
                Upload::fromStream($recipe->names['cursor'], $cursor),
                Upload::fromPath($source, $recipe->names['path']),
            ];
            $this->upload(
                'upload_success',
                'typed',
                static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->fileman()->uploadFiles(
                    files: $uploads,
                    dir: $recipe->directory,
                    getDiskInfo: true,
                    overwrite: false,
                    permissions: FilemanUploadRecipe::PERMISSIONS,
                ),
                ['bytes', 'cursor', 'path'],
                0,
                1,
                $direct,
            );
        } finally {
            @unlink($source);
        }
        $this->expectBorrowedAdvanced('cursor', $cursor);
        $this->inventory('inventory_success', ['bytes', 'cursor', 'path'], $direct);
        $report['resources']['directory']['creation'] = 'confirmed';
        $this->directoryOwned = true;

        $partial = self::borrowedStream('partial');
        $uploads = [
            Upload::fromString($recipe->names['bytes'], FilemanUploadRecipe::CONFLICT_BYTES),
            Upload::fromStream(
                $recipe->names['partial'],
                $partial,
                size: strlen(FilemanUploadRecipe::contents('partial')),
            ),
        ];
        $this->upload(
            'upload_partial',
            'typed',
            static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->fileman()->uploadFiles(
                files: $uploads,
                dir: $recipe->directory,
                overwrite: false,
                permissions: FilemanUploadRecipe::PERMISSIONS,
            ),
            ['partial'],
            1,
            1,
            $direct,
        );
        $this->expectBorrowedAdvanced('partial', $partial);

        $conflict = [Upload::fromString($recipe->names['cursor'], FilemanUploadRecipe::CONFLICT_BYTES)];
        $this->upload(
            'upload_all_failed',
            'typed',
            static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->fileman()->uploadFiles(
                files: $conflict,
                dir: $recipe->directory,
                overwrite: false,
                permissions: FilemanUploadRecipe::PERMISSIONS,
            ),
            [],
            1,
            0,
            $direct,
        );

        $raw = Upload::fromString($recipe->names['raw'], FilemanUploadRecipe::contents('raw'));
        $this->upload(
            'upload_raw',
            'generic',
            static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->callMultipart(
                'Fileman',
                'upload_files',
                [['name' => 'file-0', 'value' => $raw]],
                ['dir' => $recipe->directory, 'overwrite' => 0, 'permissions' => FilemanUploadRecipe::PERMISSIONS],
            )->data,
            ['raw'],
            0,
            1,
            $direct,
        );

        $this->inventory('inventory_final', ['bytes', 'cursor', 'path', 'partial', 'raw'], $direct);
        $this->readBack([
            'read_bytes' => 'bytes', 'read_cursor' => 'cursor', 'read_path' => 'path',
            'read_partial' => 'partial', 'read_raw' => 'raw',
        ], $direct);

        $empty = [Upload::fromString($recipe->names['empty'], FilemanUploadRecipe::contents('empty'))];
        $this->upload(
            'upload_empty',
            'typed',
            static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->fileman()->uploadFiles(
                files: $empty,
                dir: $recipe->directory,
                overwrite: false,
                permissions: FilemanUploadRecipe::PERMISSIONS,
            ),
            ['empty'],
            0,
            1,
            $direct,
        );
        $this->inventory('inventory_complete', FilemanUploadRecipe::LABELS, $direct);
        $this->readBack(['read_empty' => 'empty'], $direct);
        if ($this->expectationFailed) {
            throw new RuntimeException('Fileman upload readback expectation failed.');
        }
    }

    /**
     * Send one upload attempt and judge the original response against this phase's exact plan.
     *
     * @param UploadPhase $phase
     * @param 'typed'|'generic' $surface
     * @param Closure(Client): mixed $send
     * @param list<Label> $stored
     */
    private function upload(
        string $phase,
        string $surface,
        #[SensitiveParameter] Closure $send,
        array $stored,
        int $refused,
        int $status,
        Client $direct,
    ): void {
        $this->guard->authorizeUpload($phase);
        try {
            $result = $this->observe($phase, new Probe(
                'uapi:Fileman/upload_files',
                'direct-uapi',
                FilemanSpec::UPLOAD_FILES,
                $this->recipe->uploadUri($phase),
                $send,
                null,
                'uapi:Fileman/upload_files#POST',
                str_replace('_', '-', $phase),
            ), $direct);
        } finally {
            $this->guard->clearPending();
        }
        $payload = is_array($result) ? $result : [];
        $rows = $this->rowFindings($payload['uploads'] ?? null, $stored, $refused);
        $delivery = $this->guard->transfer($phase);
        $observed = $this->last()['uapi_status'];
        $metadata = $direct->uapi()->responseMeta();
        $disk = $payload['diskinfo'] ?? null;
        $this->uploads[$phase] = [
            'surface' => $surface,
            'uapi_status' => $observed,
            'envelope_errors' => $metadata === null ? 0 : count($metadata->uapiErrors()),
            'succeeded' => self::integer($payload['succeeded'] ?? null),
            'failed' => self::integer($payload['failed'] ?? null),
            'warned' => self::integer($payload['warned'] ?? null),
            'rows' => is_array($payload['uploads'] ?? null) ? count($payload['uploads']) : 0,
            'stored_rows_exact' => $rows['stored'],
            'refused_rows_exact' => $rows['refused'],
            'diskinfo' => is_array($disk) && $disk !== [] ? 'present' : 'absent',
            'transfer' => $delivery['transfer'] ?? null,
            'framing' => $delivery['framing'] ?? null,
        ];
        $this->passed();
        $summary = $this->uploads[$phase];
        $this->expect(
            $observed === $status && $rows['stored'] && $rows['refused']
            && $summary['succeeded'] === count($stored) && $summary['failed'] === $refused
            && in_array($summary['warned'], [0, null], true)
            && ($phase !== 'upload_success' || $summary['diskinfo'] === 'present')
            && self::deliveredExactly($delivery),
        );
    }

    /**
     * Match stored rows by exact name, then require every remaining row to be a refusal with a reason.
     *
     * The source promises one entry per file but no order. A refusal proves nothing about ownership, so its
     * optional name need only agree when present.
     *
     * @param list<Label> $stored
     * @return array{stored: bool, refused: bool}
     */
    private function rowFindings(#[SensitiveParameter] mixed $rows, array $stored, int $refused): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== count($stored) + $refused) {
            return ['stored' => false, 'refused' => false];
        }
        $storedExact = true;
        foreach ($stored as $label) {
            $name = $this->recipe->names[$label];
            $matching = array_filter($rows, static fn (mixed $row): bool => is_array($row)
                && ($row['file'] ?? null) === $name && ($row['status'] ?? null) === 1);
            $row = count($matching) === 1 ? reset($matching) : null;
            $storedExact = $storedExact && is_array($row)
                && ($row['size'] ?? null) === strlen(FilemanUploadRecipe::contents($label))
                && ($row['warnings'] ?? []) === [];
            $rows = array_diff_key($rows, $matching);
        }
        $refusedExact = count($rows) === $refused;
        foreach ($rows as $row) {
            $reason = is_array($row) ? ($row['reason'] ?? null) : null;
            $refusedExact = $refusedExact && is_array($row) && ($row['status'] ?? null) === 0
                && ($row['size'] ?? null) === null && is_string($reason) && $reason !== ''
                && (!array_key_exists('file', $row) || in_array($row['file'], $this->recipe->names, true));
        }

        return ['stored' => $storedExact, 'refused' => $refusedExact];
    }

    /** @param array{transfer: Transfer, framing: Framing|null}|null $delivery */
    private static function deliveredExactly(?array $delivery): bool
    {
        if ($delivery === null || $delivery['framing'] === null) {
            return false;
        }
        $transfer = $delivery['transfer'];

        return $transfer['body_exact'] && !$transfer['buffered'] && !$transfer['sought']
            && $transfer['largest_read'] <= 8192 && !in_array(false, $delivery['framing'], true);
    }

    /**
     * Require the exact set of named files with their sizes and requested permissions.
     *
     * @param list<Label> $labels
     */
    private function inventory(string $phase, array $labels, Client $direct): void
    {
        $rows = $this->directoryRows($phase, $direct);
        $this->expect(count($rows) === count($labels));
        foreach ($labels as $label) {
            $row = $rows[$this->recipe->names[$label]] ?? null;
            $length = strlen(FilemanUploadRecipe::contents($label));
            $this->expect(
                is_array($row) && ($row['type'] ?? null) === 'file'
                && ($row['fullpath'] ?? null) === $this->recipe->absolutePath($label)
                && ($length === 0 ? in_array($row['size'] ?? null, [0, ''], true) : ($row['size'] ?? null) === $length)
                && self::ownerOnly($row['nicemode'] ?? null),
            );
        }
    }

    /** Accept the observed int|string spellings of one numeric permission value; never another permission. */
    private static function ownerOnly(mixed $mode): bool
    {
        return (is_int($mode) || is_string($mode)) && ltrim((string) $mode, '0') === '600';
    }

    /**
     * Read every listed file, record each oracle outcome, then stop if any content differed.
     *
     * All reads complete first: they mutate nothing, and one mismatch should not hide the others. An unaccepted
     * charset echo over exact content fails the run at its end instead: it cannot affect how later uploads are read.
     *
     * @param array<string, Label> $phases
     */
    private function readBack(array $phases, Client $direct): void
    {
        $matched = true;
        foreach ($phases as $phase => $label) {
            $matched = $this->readOne($phase, $label, $direct) && $matched;
        }
        $this->expect($matched);
    }

    /** @param Label $label */
    private function readOne(string $phase, string $label, Client $direct): bool
    {
        $recipe = $this->recipe;
        $result = $this->observe($phase, new Probe(
            'uapi:Fileman/get_file_content',
            'direct-uapi',
            FilemanSpec::GET_FILE_CONTENT,
            $recipe->readUri($label),
            static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->fileman()->getFileContent(
                dir: $recipe->absoluteDirectory(),
                file: $recipe->names[$label],
                fromCharset: FilemanUploadRecipe::FROM_CHARSET,
                toCharset: FilemanUploadRecipe::TO_CHARSET,
                updateHtmlDocumentEncoding: false,
            ),
            null,
            null,
            'latin1-byte-oracle',
        ), $direct);
        $bytes = FilemanUploadRecipe::contents($label);
        $expected = FilemanUploadRecipe::latin1ToUtf8($bytes);
        $payload = is_array($result) ? $result : [];
        $content = $payload['content'] ?? null;
        $exact = $content === $expected;
        $from = self::charsetEcho($payload, 'from_char', 'from_charset', FilemanUploadRecipe::FROM_CHARSET);
        $to = self::charsetEcho($payload, 'to_char', 'to_charset', FilemanUploadRecipe::TO_CHARSET);
        $this->oracle[$phase] = [
            'expected_bytes' => strlen($bytes),
            'expected_utf8_bytes' => strlen($expected),
            'returned_utf8_bytes' => is_string($content) ? strlen($content) : null,
            'content_exact' => $exact,
            'first_difference' => is_string($content) && !$exact ? self::firstDifference($content, $expected) : null,
            'from_charset_echo' => $from,
            'to_charset_echo' => $to,
        ];
        $matched = $this->last()['probe_outcome'] === 'passed' && $exact;
        if ($matched) {
            // Anything left unproven is not cleaned up individually; account removal takes it.
            $this->owned[$label] = true;
        }
        if (!$matched || $from === 'other' || $to === 'other') {
            $this->markFailed();
        }

        return $matched;
    }

    /**
     * Classify the documented charset field, falling back to its schema-less twin only when absent.
     *
     * The source documents from_char/to_char as echoing the request; from_charset/to_charset have empty schemas.
     * cPanel 11.138.0.19 omits the documented pair and returns the twins lowercased. Charset labels are
     * case-insensitive, so exactly the requested label or its lowercase form shows the requested conversion.
     * Anything else is 'other' and fails the run, because exact content alone does not show which conversion ran.
     *
     * @param array<mixed> $payload
     * @return 'exact'|'lowercase'|'other'
     */
    private static function charsetEcho(array $payload, string $field, string $alternative, string $expected): string
    {
        return match (array_key_exists($field, $payload) ? $payload[$field] : ($payload[$alternative] ?? null)) {
            $expected => 'exact',
            strtolower($expected) => 'lowercase',
            default => 'other',
        };
    }

    /** Locate the first differing offset of two unequal strings; only this number leaves memory. */
    private static function firstDifference(
        #[SensitiveParameter] string $left,
        #[SensitiveParameter] string $right,
    ): int {
        $limit = min(strlen($left), strlen($right));
        for ($offset = 0; $offset < $limit; ++$offset) {
            if ($left[$offset] !== $right[$offset]) {
                return $offset;
            }
        }

        return $limit;
    }

    /** @param 'cursor'|'partial' $label */
    private function expectBorrowedAdvanced(string $label, StreamInterface $stream): void
    {
        $end = strlen(FilemanUploadRecipe::STREAM_PREFIX . FilemanUploadRecipe::contents($label));
        $this->borrowed[$label] = $stream->isReadable() && $stream->tell() === $end;
        $this->expect($this->borrowed[$label]);
    }

    /**
     * Place a borrowed stream after a prefix: rewinding it would upload the prefix and fail the exact body check.
     *
     * @param 'cursor'|'partial' $label
     */
    private static function borrowedStream(string $label): StreamInterface
    {
        $stream = Stream::create(FilemanUploadRecipe::STREAM_PREFIX . FilemanUploadRecipe::contents($label));
        $stream->seek(strlen(FilemanUploadRecipe::STREAM_PREFIX));

        return $stream;
    }

    /** Write a private local source for fromPath(); it holds synthetic bytes and is removed after its one attempt. */
    private static function temporarySource(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wlupload-source-');
        if ($path === false) {
            throw new RuntimeException('Fileman upload source file is unavailable.');
        }
        $bytes = FilemanUploadRecipe::contents('path');
        if (!chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes)) {
            @unlink($path);
            throw new RuntimeException('Fileman upload source file is unavailable.');
        }

        return $path;
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * List the home directory; its known entry proves the listing is usable as evidence of absence.
     *
     * This identity read is generic, as in the finite-Quota inventories: an optional field on an unrelated home
     * entry must not stop an upload run. Any such surprise still reaches the strict review from the same response.
     *
     * @return array<string, array<mixed>>
     */
    private function homeRows(string $phase, Client $direct): array
    {
        $rows = $this->named($this->succeed($phase, new Probe(
            'uapi:Fileman/list_files',
            'direct-uapi',
            FilemanSpec::LIST_FILES,
            $this->recipe->homeListUri(),
            static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->call('Fileman', 'list_files', [
                'dir' => FilemanUploadRecipe::HOME, 'types' => 'dir|file',
            ], RequestMethod::Get)->data,
            null,
            null,
            'home-presence',
        ), $direct));
        $this->expect(isset($rows['public_html']));
        $webroot = FilemanUploadRecipe::HOME . '/public_html';
        $this->expect(($rows['public_html']['fullpath'] ?? $webroot) === $webroot);

        return $rows;
    }

    /** @return array<string, array<mixed>> */
    private function directoryRows(string $phase, Client $direct): array
    {
        $recipe = $this->recipe;

        return $this->named($this->succeed($phase, new Probe(
            'uapi:Fileman/list_files',
            'direct-uapi',
            FilemanSpec::LIST_FILES,
            $recipe->directoryListUri(),
            static fn (#[SensitiveParameter] Client $client): mixed => $client->uapi()->fileman()->listFiles(
                dir: $recipe->directory,
                includePermissions: true,
                showHidden: true,
            ),
            null,
            null,
            'fixture-directory',
        ), $direct));
    }

    /**
     * Decide from the original response whether an empty listing is a real one.
     *
     * Decoded to arrays, `{}` is the same empty array as a JSON list, and an empty object cannot prove an empty
     * directory. Only a JSON list or an object holding both documented lists can.
     */
    private function listingProvesContents(): bool
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
     * Dot and parent entries are not contents, whichever way the server reports them.
     *
     * @return array<string, array<mixed>>
     */
    private function named(#[SensitiveParameter] mixed $listing): array
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
     * Capture one generated call through the shared runner, keeping its result in memory for expectations.
     *
     * The runner clears prior capture and never retries; the record carries only structural evidence.
     */
    private function observe(string $phase, Probe $probe, Client $client): mixed
    {
        $result = null;
        $invoke = $probe->invoke;
        $wrapped = new Probe(
            $probe->operation,
            $probe->route,
            $probe->spec,
            $probe->uri,
            static function (#[SensitiveParameter] Client $client) use ($invoke, &$result): mixed {
                return $result = $invoke($client);
            },
            null,
            $probe->sourceVariant,
            $probe->argumentVariant,
        );
        $record = $this->runner->capture($wrapped, $this->capture, $client);
        $metadata = $probe->route === 'direct-uapi' ? $client->uapi()->responseMeta() : null;
        $this->records[] = [...$record, 'phase' => $phase, 'uapi_status' => $metadata?->uapiStatus(),
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked'];

        return $result;
    }

    private function succeed(string $phase, Probe $probe, Client $client): mixed
    {
        $result = $this->observe($phase, $probe, $client);
        $this->passed();

        return $result;
    }

    private function passed(): void
    {
        if ($this->last()['probe_outcome'] !== 'passed') {
            throw new RuntimeException('Fileman upload observation failed.');
        }
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $this->markFailed();
            throw new RuntimeException('Fileman upload expectation failed.');
        }
    }

    private function markFailed(): void
    {
        $this->expectationFailed = true;
        $index = array_key_last($this->records);
        if ($index !== null) {
            $this->records[$index]['expectation'] = 'failed';
        }
    }

    /** @return Observation */
    private function last(): array
    {
        $index = array_key_last($this->records);
        if ($index === null) {
            throw new RuntimeException('Fileman upload observation unavailable.');
        }

        return $this->records[$index];
    }

    /**
     * Read exact account ownership; another primary domain or marker cannot authorize account removal.
     *
     * @phpstan-impure
     */
    private function accountExists(): bool
    {
        $rows = array_values(array_filter($this->accounts(), static fn (stdClass $row): bool =>
            $row->user === FilemanUploadRecipe::ACCOUNT));
        if ($rows === []) {
            return false;
        }
        if (
            count($rows) !== 1 || $rows[0]->domain !== FilemanUploadRecipe::DOMAIN
            || ($rows[0]->email ?? null) !== $this->recipe->contactEmail
        ) {
            throw new RuntimeException('Fileman upload account ownership is unconfirmed.');
        }

        return true;
    }

    /**
     * Check main and manually configured addon/alias/subdomain identities, without persisting inventory.
     *
     * @phpstan-impure
     */
    private function domainExists(): bool
    {
        $accounts = $this->accounts();
        $this->whm->call('get_domain_info', [], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->domains ?? null) : null, 'domain', 'user');

        return in_array(FilemanUploadRecipe::DOMAIN, array_column($accounts, 'domain'), true)
            || in_array(FilemanUploadRecipe::DOMAIN, array_column($rows, 'domain'), true);
    }

    /**
     * Validate every row before exact local filtering; failed or null filtered lookups cannot prove absence.
     *
     * The server always hosts other accounts, so an empty list is no proof of absence either.
     *
     * @return list<stdClass>
     */
    private function accounts(): array
    {
        $this->whm->call('listaccts', ['want' => 'user,domain,email'], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->acct ?? null) : null, 'user', 'domain');
        if ($rows === []) {
            throw new RuntimeException('Fileman upload account inventory cannot prove absence.');
        }

        return $rows;
    }

    /**
     * Read account ownership for a cleanup decision, surviving a transient failed request.
     *
     * Only this read repeats, and only when the request itself failed: a returned inventory is final, so a foreign
     * marker or unusable rows are never read twice. One lost read must not abandon an account and a writable token.
     *
     * @phpstan-impure
     */
    private function accountOwned(): bool
    {
        for ($attempt = 1; $attempt < self::IDENTITY_READS; ++$attempt) {
            try {
                return $this->accountExists();
            } catch (ApiException) {
                usleep(self::REREAD_PAUSE_MICROSECONDS);
            }
        }

        return $this->accountExists();
    }

    /**
     * Read exact zone presence; the server always hosts other zones, so an empty list cannot prove absence.
     *
     * @phpstan-impure
     */
    private function zoneExists(): bool
    {
        $this->whm->call('listzones', [], RequestMethod::Get);
        $payload = $this->rawPayload(false);
        $rows = self::identityRows($payload instanceof stdClass ? ($payload->zone ?? null) : null, 'domain');
        if ($rows === []) {
            throw new RuntimeException('Fileman upload zone inventory cannot prove absence.');
        }

        return in_array(FilemanUploadRecipe::DOMAIN, array_column($rows, 'domain'), true);
    }

    /** @phpstan-impure */
    private function tokenExists(): bool
    {
        $this->account->call('Tokens', 'list', [], RequestMethod::Get);
        $rows = self::identityRows($this->rawPayload(true), 'name');

        return in_array(FilemanUploadRecipe::TOKEN_NAME, array_column($rows, 'name'), true);
    }

    /**
     * Confirm writability and the exact expiry once during setup; cleanup needs usable identities only.
     *
     * The source says readonly is absent or 0 on a writable token. An earlier reviewed setup returned expires_at
     * as the exact requested decimal string, which is accepted without numeric coercion.
     */
    private function confirmTokenCapabilities(): void
    {
        $this->account->call('Tokens', 'list', [], RequestMethod::Get);
        foreach (self::identityRows($this->rawPayload(true), 'name') as $row) {
            if ($row->name === FilemanUploadRecipe::TOKEN_NAME) {
                $expiresAt = $row->expires_at ?? null;
                if (
                    ($row->readonly ?? 0) === 0 && ($expiresAt === $this->recipe->expiresAt
                        || $expiresAt === (string) $this->recipe->expiresAt)
                ) {
                    return;
                }
                break;
            }
        }
        throw new RuntimeException('Fileman upload token capabilities are unconfirmed.');
    }

    /**
     * Inspect the same successful generic response while retaining JSON object/list distinctions.
     *
     * The generic call has already validated the envelope and outcome. An empty JSON object cannot prove an empty list.
     */
    private function rawPayload(bool $bridge): mixed
    {
        $raw = $this->capture->rawBody();
        if ($raw === null) {
            throw new RuntimeException('Fileman upload identity inventory was not captured.');
        }
        $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $payload = $document instanceof stdClass ? ($document->data ?? null) : null;
        if ($bridge) {
            $uapi = $payload instanceof stdClass ? ($payload->uapi ?? null) : null;

            return $uapi instanceof stdClass ? ($uapi->data ?? null) : null;
        }

        return $payload;
    }

    /** @return list<stdClass> */
    private static function identityRows(
        #[SensitiveParameter] mixed $rows,
        string $identity,
        ?string $related = null,
    ): array {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Fileman upload identity inventory must be a list.');
        }
        $identities = [];
        $checked = [];
        foreach ($rows as $row) {
            $name = $row instanceof stdClass ? ($row->{$identity} ?? null) : null;
            $owner = $row instanceof stdClass && $related !== null ? ($row->{$related} ?? null) : '';
            if (
                !$row instanceof stdClass || !is_string($name) || $name === '' || in_array($name, $identities, true)
                || !is_string($owner) || ($related !== null && $owner === '')
            ) {
                throw new RuntimeException('Fileman upload inventory identities are unusable.');
            }
            $identities[] = $name;
            $checked[] = $row;
        }

        return $checked;
    }

    /**
     * Reconcile every attempted resource, removing only what this run proved it owns.
     *
     * @param Report $report
     */
    private function cleanup(array &$report): void
    {
        if ($report['resources']['account']['creation'] === 'not_attempted') {
            return;
        }
        $report['cleanup'] = 'passed';
        $report['resources']['account']['cleanup'] = 'unconfirmed';
        $report['resources']['files']['proven_owned'] = count($this->owned);
        if ($report['resources']['token']['creation'] !== 'not_attempted') {
            $report['resources']['token']['cleanup'] = 'unconfirmed';
        }
        if ($report['resources']['directory']['creation'] !== 'not_attempted') {
            $report['resources']['directory']['cleanup'] = 'unconfirmed';
        }
        try {
            if ($this->accountOwned()) {
                $report['resources']['account']['creation'] = 'confirmed';
                $this->cleanupFiles($report);
                $this->cleanupToken($report);
                if (!$this->accountOwned()) {
                    throw new RuntimeException('Fileman upload account ownership changed during cleanup.');
                }
                try {
                    $this->whm->call('removeacct', [
                        'username' => FilemanUploadRecipe::ACCOUNT, 'keepdns' => 0,
                    ], RequestMethod::Post);
                } catch (Throwable) {
                    self::cleanupFailure($report, 'account_remove_failure');
                }
                $report['resources']['account']['cleanup'] = $this->accountOwned() ? 'present' : 'confirmed_absent';
            } elseif ($this->creationOutcomeUnknown) {
                self::cleanupFailure($report, 'account_creation_outcome_unknown');
            } else {
                $report['resources']['account']['cleanup'] = 'confirmed_absent';
                if ($report['resources']['token']['creation'] !== 'not_attempted') {
                    $report['resources']['token']['cleanup'] = 'unconfirmed';
                    self::cleanupFailure($report, 'token_absence_unconfirmed');
                }
            }
            if ($report['resources']['account']['cleanup'] === 'present') {
                self::cleanupFailure($report, 'account_still_present');
            }
        } catch (Throwable) {
            self::cleanupFailure($report, 'account_identity_unconfirmed');
        }
        try {
            $report['domain_cleanup'] = $this->domainExists() ? 'present' : 'confirmed_absent';
            if ($report['domain_cleanup'] === 'present') {
                self::cleanupFailure($report, 'domain_still_present');
            }
        } catch (Throwable) {
            $report['domain_cleanup'] = 'unconfirmed';
            self::cleanupFailure($report, 'domain_absence_unconfirmed');
        }
        try {
            $report['zone_cleanup'] = $this->zoneExists() ? 'present' : 'confirmed_absent';
            if ($report['zone_cleanup'] === 'present') {
                self::cleanupFailure($report, 'zone_still_present');
            }
        } catch (Throwable) {
            $report['zone_cleanup'] = 'unconfirmed';
            self::cleanupFailure($report, 'zone_absence_unconfirmed');
        }
    }

    /**
     * Delete proven-owned files, then the directory only if owned and empty, then read its absence.
     *
     * An unproven or unexpected entry is left alone; account removal takes it, and the outcome is reported.
     *
     * @param Report $report
     */
    private function cleanupFiles(array &$report): void
    {
        $direct = $this->direct;
        if ($report['resources']['directory']['creation'] === 'not_attempted' || $direct === null) {
            return;
        }
        foreach (FilemanUploadRecipe::LABELS as $label) {
            if (
                isset($this->owned[$label])
                && $this->deleteExact($report, 'delete_' . $label, $this->recipe->relativePath($label), $direct)
            ) {
                ++$report['resources']['files']['deleted'];
            }
        }
        try {
            if ($this->directoryOwned) {
                if ($this->directoryRows('inventory_empty', $direct) !== []) {
                    self::cleanupFailure($report, 'fixture_directory_not_empty');
                } elseif ($this->listingProvesContents()) {
                    $this->deleteExact($report, 'delete_directory', $this->recipe->directory, $direct);
                } else {
                    self::cleanupFailure($report, 'directory_emptiness_unproven');
                }
            }
            $present = isset($this->homeRows('home_final', $direct)[$this->recipe->directory]);
            $report['resources']['directory']['cleanup'] = $present ? 'present' : 'confirmed_absent';
            if ($present) {
                self::cleanupFailure($report, 'directory_still_present');
            }
        } catch (Throwable) {
            self::cleanupFailure($report, 'directory_absence_unconfirmed');
        }
    }

    /**
     * Delete one exact guard-authorized path once; a following inventory, not this reply, proves the outcome.
     *
     * @param Report $report
     */
    private function deleteExact(array &$report, string $phase, string $path, Client $direct): bool
    {
        try {
            $this->guard->authorizeDelete($path);
            try {
                $this->succeed($phase, new Probe(
                    'uapi:Fileman/delete_file',
                    'direct-uapi',
                    FilemanSpec::DELETE_FILE,
                    $this->recipe->deleteUri(),
                    static fn (#[SensitiveParameter] Client $client): mixed =>
                        $client->uapi()->fileman()->deleteFile(path: $path),
                    null,
                    null,
                    $phase === 'delete_directory' ? 'empty-owned-directory' : 'owned-file',
                ), $direct);
            } finally {
                $this->guard->clearPending();
            }

            return true;
        } catch (Throwable) {
            self::cleanupFailure($report, 'owned_delete_failure');

            return false;
        }
    }

    /** @param Report $report */
    private function cleanupToken(array &$report): void
    {
        if ($report['resources']['token']['creation'] === 'not_attempted') {
            return;
        }
        $report['resources']['token']['cleanup'] = 'unconfirmed';
        try {
            if ($this->tokenExists()) {
                $report['resources']['token']['creation'] = 'confirmed';
                if (!$this->accountExists()) {
                    throw new RuntimeException('Fileman upload token ownership changed during cleanup.');
                }
                try {
                    $this->account->call('Tokens', 'revoke', [
                        'name' => FilemanUploadRecipe::TOKEN_NAME,
                    ], RequestMethod::Post);
                } catch (Throwable) {
                    self::cleanupFailure($report, 'token_revoke_failure');
                }
                $report['resources']['token']['cleanup'] = $this->tokenExists() ? 'present' : 'confirmed_absent';
            } else {
                $report['resources']['token']['cleanup'] = 'confirmed_absent';
            }
            if ($report['resources']['token']['cleanup'] === 'present') {
                self::cleanupFailure($report, 'token_still_present');
            }
        } catch (Throwable) {
            self::cleanupFailure($report, 'token_absence_unconfirmed');
        }
    }

    /** @param Report $report */
    private static function cleanupFailure(array &$report, string $kind): void
    {
        $report['cleanup'] = 'failed';
        $report['failures'][] = $kind;
    }

    /** @return Report */
    private static function report(): array
    {
        $resource = ['creation' => 'not_attempted', 'cleanup' => 'not_required'];

        return [
            'status' => 'failed', 'setup' => 'not_started', 'capture' => 'not_run',
            'expectations' => 'not_run', 'cleanup' => 'not_required', 'failures' => [], 'stopped_at' => null,
            'resources' => ['account' => $resource, 'token' => $resource, 'directory' => $resource,
                'files' => ['proven_owned' => 0, 'deleted' => 0]],
            'domain_cleanup' => 'not_required', 'zone_cleanup' => 'not_required',
            'filesystem_cleanup' => 'not_claimed',
        ];
    }

    /**
     * Assemble the sanitized result, attributing every observation to the run's one server version.
     *
     * @param Report $report
     * @return Scenario
     */
    private function scenario(array $report): array
    {
        $versions = array_column(array_filter($this->records, static fn (array $record): bool =>
            $record['phase'] === 'version'), 'server_version');
        $records = $this->records;
        foreach ($records as &$record) {
            $record['server_version'] ??= is_string($versions[0] ?? null) ? $versions[0] : null;
        }
        unset($record);

        return [
            'scenario' => 'fileman-upload-disposable-v2',
            'target_role' => 'disposable-test-account',
            'probes' => $records,
            'uploads' => $this->uploads,
            'byte_oracle' => $this->oracle,
            'borrowed_streams' => $this->borrowed,
            'discrepancy_review' => FilemanUploadEvidence::assess($records, $this->context),
            'fixture' => $report,
            'limitations' => [
                'direct_route_on_one_server_version_only',
                'byte_oracle_is_reversible_transcoding_not_a_raw_download',
                'api_absence_only_no_filesystem_or_home_directory_cleanup_claim',
                'observed_filename_and_size_variants_only',
                'no_status_0_null_or_empty_rows_observation',
                'no_antivirus_or_archive_behavior',
                'no_memory_benchmark',
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
        throw new LogicException('Fileman upload fixture state cannot be serialized.');
    }
}
