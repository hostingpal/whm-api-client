# UAPI account backup and restoration

> Agent contract notes for `backup()`, `restore()`, `wordPressBackup()`, `wordPressRestore()`, `backupInfo()`, `extractInfo()`, `websiteBackup()`, binary restore uploads. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The account-backup section exposes 36 compatible methods across `backup()`,
`restore()`, `wordPressBackup()`, `wordPressRestore()`, `backupInfo()`,
`extractInfo()` and `websiteBackup()`. Eight explicit reads use GET; 24 operations
use form POST and four Backup restore operations use streaming binary multipart POST.
Their alternative server-path query modes remain omitted under `8x0.30.9`;
generic form access does not establish compatibility with those modes.

Full backups preserve sensitive remote passwords and SSH key passphrases; `keyName`
is an existing key identifier, not private-key content. The pinned prerequisite
text names `SSL::generate_key` and API2 `SSH::importkey`; this UAPI slice does not
implement API2 key import. Omitting the completion-email
argument sends no notification. A PID, backup ID or restore ID does not establish
completion. Restore paths retain required slash conventions and are passed unchanged;
`overwrite` is required and can replace original content. Source role/version and
WordPress Manager plugin requirements remain in each generated method.

WordPress results preserve optional fields, null and opaque-object alternatives.
`WordPressRestore/any_running` preserves both the flat pinned examples and the
formal optional `items` wrapper, including `any_running`, `sites` and `last_outcome`.
The `sites` list keeps its known row shape; its formal object alternative is
unstructured and only documented as empty. No populated keyed-record shape is
inferred. Both `any_running` placements preserve the documented integer 0/1 values.
Cancellation and cleanup have their documented result meanings and do not imply
successful restoration or archive deletion. Returned SSE URLs are never fetched.

BackupInfo, ExtractInfo and WebsiteBackup omit `x-readonly`. Ten methods use the
conservative form POST fallback; WebsiteBackup creation, deletion, extraction and
restoration have explicit pinned POST/form contracts and known mutation effects.
Their POST responses are outside the committed read-only capture; separate reviewer
lifecycle observations are recorded in the source audit.
BackupInfo reset clears completion tracking without canceling a run or deleting
archives. ExtractInfo start records a baseline/marker and extracts no files;
finish clears that marker after either success or failure. Paths refer to server-side
account files. The documented estimate limitations remain visible.

WebsiteBackup uses repeated `database` and `ids` fields, preserving order and
values. Creation with omitted databases backs up files only; omitted email sends
no notification. Restore defaults differ: databases restore unless
`restoreDatabases: false` is supplied. Restore merges and overwrites matching files
by default; `clean: true` can remove later additions. These optional boolean
switches preserve omission and encode explicit false/true as 0/1. Archive deletion is destructive,
per-ID failures stay separate from API status, and source ID/domain association
limits remain explicit. Background operation IDs mean submitted work; inspect
operation status and errors/warnings for completion. Server database snapshots do
not imply an atomic site rollback. No client retry or rollback is introduced.

All 32 methods have offline contracts. The fixed nine-GET catalog selects WHM
version plus account backup inventory, restore-user inventory and both WordPress
`anyRunning()` reads through both routes. It guesses no site or path and excludes
all unknown-risk calls, writes, extraction, restoration, deletion and notifications.
Remaining source/live behavior stays under `8x0.2.32`.

`BackupInfo::reset()` and `ExtractInfo::finish()` accept boolean and integer 0/1
cleared flags. Quota checks normalize canonical, bounded integer strings only at
`estimatedBytes` and `availableBytes`. On reviewed cPanel `11.138.0.19`, WordPress
backup/restore modules were unavailable on both routes. Disabled server backups
prevented `queryFileInfo()` and `restoreFile()` while `getUsers()` still returned
an empty list. Missing-archive `ExtractInfo::start()` raises `ApiErrorException`.
These installation-specific observations preserve normal error handling.

WordPressBackup `cancel()` and `start()` return an optional string `site`,
preserving the pinned outer type across format-only alternatives. Their generated
input is `string`, following the same source type without URL/path format
validation. The shared lowering derives those response strings directly; the
redundant response replacements are removed, with provenance retained in the source audit.

## Account backup and restoration capture

The immutable [account-backup capture](../../api/evidence/2026-10-31-account-backup-restoration.json)
at `e7b4a42` on `11.138.0.19` records nine HTTP 200 observations. WHM version,
Backup/list_backups and Restore/get_users on both routes pass API, payload and
probe checks. Both inventories are empty; populated rows remain unobserved.
WordPressBackup/any_running and WordPressRestore/any_running fail at the API layer
on both routes. The sanitized evidence does not establish the cause, and no
response contract is widened from those failures. There are zero payload
discrepancies, while strict assessment remains failed for the four API errors.

No backup or restore job, extraction, cleanup, cancellation, message or filesystem
change ran in this capture. No POST method is represented by the artifact;
reviewer lifecycle reports remain separate evidence. Follow-up `8x0.2.32` retains the unobserved operations,
plugin availability and lifecycle behavior. No repeat capture was run merely to
seek a passing status. SHA-256:
`c06b329f50f2ebd16ae43d52a7be4afb8b37d0267956d2d557287113209956f3`.

### Backup binary restore uploads (2026-10-31)

`uapi()->backup()` generates `restoreDatabases(Upload|array $files, ?int $timeout = null,
?bool $verbose = null)`, `restoreEmailFilters(Upload|array $backups, ?int $timeout = null,
?bool $verbose = null)`, `restoreEmailForwarders(Upload|array $backups, ?int $timeout = null,
?bool $verbose = null)` and `restoreFiles(Upload|array $backups, ?string $directory = null,
?int $timeout = null, ?bool $verbose = null)`. Arrays are precisely non-empty-list<Upload>.
Every public value is sensitive. Scalars emit bare `file` for databases or `backup`
for the other methods; lists emit `file-1`/`backup-1`, then `-2`, even for a singleton.
Wire order and duplicate filenames survive one streaming POST; no path-string inference,
sparse renumbering or Fileman-specific filename filter applies.

These new binary methods initially require direct cPanel UAPI by client policy
pending route evidence, not an upstream bridge prohibition. Server-path query
`backup` alternatives are separately bound, explicitly omitted and deferred under
`8x0.30.9`; no mixed mode is accepted. Optional query values are encoded once;
null omits, false/zero survive. Database restore requires cPanel 84 and the MySQL
Client role; filters/forwarders require cPanel 86 and Receive Mail; file restore
requires cPanel 86 and File Storage. Omission retains timeout 7200 seconds for the
first three, 172800 for files; 0 disables timeout. The documented minimum 0 adds
no new runtime minimum validation. Files' omitted directory defaults to user home;
supplied bytes are forwarded without an inferred relative-path base. Verbose
selects extra information from each source's module-specific restore logs.

Each returns `array{log_id?:string,log_path?:string}` under
ordinary Flag outcome handling. An actual empty object is valid; missing, null,
list or malformed data raises PayloadMismatchException, retaining metadata and
outcomeConfirmed=true after status success. No message unwrapping, log fetching,
completion, atomicity or per-file-success inference is added. Restoration changes
server state with no rollback and no retry after API, payload or transport failure.
No live restore ran during this implementation or integration; offline checks establish request/envelope/lifecycle behavior.

[PR89 review5724752679](https://github.com/hostingpal/whm-api-client/pull/89#pullrequestreview-5724752679)
reports 19 successful direct-UAPI restores on cPanel 11.138.0.19 with log_id/log_path
in data and messages in the envelope. Read verbose/restore text through
`responseMeta()->uapi['messages']`. Flag raises API errors before validating data,
so unvalidated log_id/log_path are unavailable on failure; `errors()` retains text.
The reviewer also found overlapping-archive results disagreed with list order in
three of five permutations. Upstream application order is unreliable and overlapping
archives have unpredictable results; restore one archive per call when a specific
result is needed. This adds no completion, atomicity or rollback guarantee.

Exactly four canonical UAPI operations are added: 1,149 total (554 UAPI, 595 WHM),
99 groups and 201 generated outputs (200 PHP files and coverage). The new narrow
`multipart_upload_family` descriptor `{php,source,routes:direct}` is mutually
exclusive with Fileman's `multipart_uploads`, WHM's `multipart_upload` and JSON.
Source-bound lowering preserves absent body/schema requiredness and uses prose's
required upload alternative. Streaming, source preparation, transport, old generated
operations and SSL bridge behavior remain unchanged. Approved parent source/preparation and Fileman/WHM corrections are integrated.
Server-path modes (.30.9) and native Windows verification (.30.10) remain separate
open work. Owned Fileman evidence (.30.5) is closed; its passing capture is
`docs/api/evidence/2026-11-01-fileman-upload-v2.json`.
