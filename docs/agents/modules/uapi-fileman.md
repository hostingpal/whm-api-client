# UAPI Fileman

> Agent contract notes for `uapi()->fileman()`, including typed `uploadFiles()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The Fileman group implements all 14 pinned functions. Directory autocomplete,
file information, file content and directory listings use GET; content saving,
in-memory transcoding, copy, move, rename, delete, trash, restore and trash purging
use form POST. `uploadFiles()` uses streamed binary multipart with direct cPanel
credentials only. A WHM-bridged handle rejects it locally before source I/O.
The existing typed SSL string multipart methods retain support for both routes.

`uploadFiles()` requires a sensitive `non-empty-list<Upload>` and emits `file-0`,
`file-1`, and so on in one request. Empty/sparse lists, non-Upload items and
filenames containing `<`, `>` or `;` fail before any source opens or reads.
General Upload filename rules also reject double quotes and header injection.
Duplicate filenames stay in order; the source describes server renaming without
promising a particular stored name. Files start at the borrowed stream's current
cursor and require a known exact remaining length; all sources stay stable during
the call. The shared transport sends exact Content-Length without chunking.

The optional `dir`, `getDiskInfo`, `overwrite` and `permissions` arguments are
query fields, encoded once. Every argument is sensitive in library trace frames,
including the query flags. Null omits; false sends `0`; `0644` and `644` remain
strings. Omission preserves server defaults, including overwrite=false. Source
prose resolves relative directories against the account home, defaults omission
to that home, creates a missing directory, and describes antivirus scanning and
permission warnings. These are source statements, not a client live guarantee.

Typed upload explicitly uses `OutcomePolicy::Unchecked`: both integer UAPI flags
`0` and `1` preserve valid per-file rows, including counts, status/reason/warnings
and nullable stored size. Inspect `responseMeta()->uapiStatus()` and every row;
either flag can include failed files. Status `0` with explicit null data or a
validated object containing `uploads: []` raises `ApiErrorException`, preserving
the same response snapshot and `errors()`. The upstream "You must specify at least
one file to upload." message can mean insufficient disk space: the form parser
may discard all files before the upload handler sees them. A row-bearing partial
or all-failed batch still returns normally. Missing/invalid status remains an
envelope error. Missing data, status-0 missing rows, list roots and malformed known
fields raise `PayloadMismatchException`, retaining the snapshot and unvalidated
payload with `outcomeConfirmed=false`. Status `1` retains the documented optional
fields; null data still mismatches. Decimal byte/infinity strings, int|string
inode counts and additional string display values remain exact. The data root
explicitly rejects empty JSON arrays; existing nested empty-object compatibility
is unchanged. Absent diskinfo is not zero usage. Any failure may follow stored
files; there is no retry, rollback, atomicity or inferred ownership/cleanup.


`copyFile(source, destination)`, `moveFile(source, destination)` and
`renameFile(source, destination)` keep source first. Content arguments are
sensitive and form-encoded once. Paths and content are forwarded unchanged;
charset conversion is performed by the server. **Omitting `saveFileContent()`
content or passing `content: null` truncates an existing file to zero bytes.**
A null variable is not a no-op; supply intended content and verify the target.
`emptyTrash()` defaults to purging the account trash. Preserve each
method's version, File Storage role, charset and destructive-operation caveats.

`listFiles(types: 'file|dir')` accepts a pre-serialized pipe-delimited filter.
The existing scalar encoding exposes a native `string|int` boundary without
establishing integer filter semantics. No undeclared indexed `filepath-` family
is inferred from `limitToList`. Optional response keys remain absent, flags stay
integer `0|1`. File-information and flat-list sizes preserve the observed empty
string for zero-byte files, distinct from numeric zero and omission; canonical
integer strings still normalize losslessly. Their uid/gid fields also preserve
observed empty strings for root-owned entries. File-content charset fields
with unspecified schemas stay mixed. `emptyTrash()` preserves nullable opaque
objects and rejects a missing payload.

Observed file information preserves `mode` and `nicemode` as `int|string`,
including string spelling, and normalizes `size` only from a lossless canonical
integer string. String permission values do not establish a numeric format rule.
Every recorded listing is a flat list of rows: iterate it directly. The pinned
object alternative with `dirs`/`files` lists remains supported but unobserved.
Use `array_is_list($result)` to select flat iteration; otherwise read those named
lists when present. An empty array contains no rows under either interpretation.
Flat rows have the reviewed permission/size representation corrections; the
documented object alternative retains its original row types.

All 14 methods have offline contract tests. The bounded live catalog selects
three directory inventory reads through both routes, plus WHM version. It reads
no existing file content and sends no mutations. Content, filesystem lifecycle
and transcoding verification remain under `8x0.2.24`; exclusive fixture creation
must precede overwrite-capable saves.

The [final Fileman inventory capture](../../api/evidence/2026-10-30-fileman-inventory-final.json)
at `ec442bd` on `11.138.0.19` passes all seven GET observations
and strict assessment with zero discrepancies. It covers three inventory
operations through both routes; the other ten original methods remain offline-tested.
Typed binary upload has separate offline coverage.
No existing file content was read and no mutation ran. Follow-up `8x0.2.24`
owns their live verification; typed binary upload has offline verification only.
A separate owned client capture remains under `8x0.30.5`.

The [PR37 review capture](../../api/evidence/2026-10-30-fileman-review-final.json)
at `06001e0cab782c7bd46d7d0d1f659b0c3f7ebc7d` on `11.138.0.19` passes all seven
GET observations with zero discrepancies. Both routes return an empty-string
size for the selected existing zero-byte file; information and directory checks
confirm its identity and zero marker. No content was read and no resource was
created or changed. The root-owned uid/gid variants remain supported by Claude's
separate report. Earlier artifacts stay immutable.

The typed Fileman upload adoption adds one canonical UAPI operation: current
coverage is 1,144 operations (550 UAPI, 594 WHM), 99 groups and 201 generated
outputs. Only uploadFiles uses the new evidence-bound multipart_uploads required
list descriptor. Its fixed params stay query fields, its route is direct, and its
explicit Unchecked outcome preserves valid row-bearing all-failed payload reasons.
The source-bound rows:uploads result policy raises ApiErrorException for status 0
with explicit null or a fully validated object with an empty uploads list. Missing
data/rows and malformed known fields remain payload mismatches; status 1 retains
pinned optional fields. Global Unchecked, Features and massModifyAccount are
unchanged. The object-only accept_empty_array:false return opt-in applies solely to its required data root;
default source lowering, nested/default shapes and earlier generated constants
are unchanged. Diskinfo uses the existing object fields+values grammar.
No owned client upload capture ran in this implementation. Attributed PR74 raw
server observations remain separate, and 8x0.30.5 owns client live evidence. The
five remaining binary operations remain 8x0.30.7/.30.8/.30.9. Approved parent fixes
are consumed through the master merge. This correction changes no Upload or
source/stream contract.
