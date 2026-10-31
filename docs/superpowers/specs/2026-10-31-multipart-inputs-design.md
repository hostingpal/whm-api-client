# Streaming uploads and multipart input design

Date: 2026-10-31. Status: user-approved public contract; implementation follows in
separate logical PRs. Tracking: `whm-api-client-8x0.30`, with the selected endpoint
tracked by the existing `whm-api-client-8x0.1.19`.

## Intent and scope

Callers upload local files, existing bytes or PSR streams without constructing
multipart boundaries or numbered file fields. Typed methods describe the actual
operation; an explicit `callMultipart()` offers generic access. The library
constructs requests with bounded additional memory and preserves its existing
authentication, metadata, outcome and no-retry behavior.

The user approved the three source factories, ordinary file lists, filenames and
content-type defaults, current-position/ownership rules, one-request semantics,
generic escape hatch and direct-only Fileman route. The detailed validation and
internal stream choices below are implementation decisions made within that
contract, rather than additional upstream claims.

The first typed adopter is UAPI `Fileman/upload_files#POST`. Four UAPI Backup
restore operations and WHM `restore_config_from_upload` are later adopters and
require their own source audits. Existing SSL multipart string methods remain
supported with their reviewed contracts. This design does not add downloads,
remote URL fetching, automatic chunked/resumable uploads, batch request loops,
framework adapters, server configuration changes or automatic restoration.

## Approaches

The selected approach is a small explicit `Upload` value with path, byte-string
and PSR-stream factories, plus an internal streaming multipart body. It makes
source interpretation and lifetime clear while fitting the project's PSR HTTP
interfaces and ordinary-array method inputs.

Ambiguous strings that might mean paths or bytes were rejected. Raw resources
alone would require caller-specific lifetime and filename conventions; callers
can instead wrap resources using their PSR implementation. Building a complete
multipart string would scale memory with total file size. A mandatory concrete
HTTP runtime would conflict with the project's dependency boundary. These are
the alternatives considered; only the selected contract was proposed and
approved in the conversation.

## Source inventory and present runtime

The immutable snapshot in `docs/api/sources/2026-10-13/` contains nine multipart
operations: one WHM and eight UAPI. Three SSL operations already send reviewed
string fields through `Api/Engine/MultipartEncoder`. That encoder chooses a
delimiter absent from all supplied strings and returns a complete string.

`Http/HttpTransport::send()` currently accepts an optional string and uses a
PSR-17 factory to make the body stream. Extending it to accept an already-built
`StreamInterface` must preserve every existing string/null request. The HTTP
client remains injected and owns TLS verification, disabled redirects and
timeouts. No Guzzle or other concrete HTTP dependency is added.

Pinned Fileman descriptions explicitly require multipart file parts, name stored
files using each part's filename, and forbid `uapi_cpanel` bridging. Its documented
convention is `file-0`, `file-1`, etc. File Storage role availability is required.
These facts come from the local pinned operation and upload tutorial; transport
support alone is neither live verification nor coverage of all multipart APIs.

## Source, observed evidence and client policy

**Source:** The locally inspected [pinned manifest](../../api/sources/2026-10-13/manifest.json)
binds snapshot `2026-10-13`; both OpenAPI inputs declare `11.138.0.10`. The
uncompressed WHM source SHA-256 is
`6edb13c51d53f3c2be1fa83cc93e31d3a81f4f8506b3b45284fa00b986041594`;
the UAPI source is
`172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc`.
The [Fileman operation](https://api.docs.cpanel.net/openapi/cpanel/operation/upload_files/)
request/response and [upload tutorial](https://api.docs.cpanel.net/guides/quickstart-development-guide/tutorial-use-uapis-fileman-upload-files-function-in-custom-code/)
are pinned locally; the tutorial's uncompressed SHA-256 is
`1d5f4516ad562bf2046f8e0a9808c5870adddbd1d9545fc4d9b2e0658a586c33`.
These source contracts establish the inventory, file-N convention, query
parameters, role/route caveats and response shape. They were not refreshed here.
Tutorial examples do not override this client's verified-TLS requirement.

**Observed (reviewer report):** [Review 5722763413](https://github.com/hostingpal/whm-api-client/pull/74#pullrequestreview-5722763413),
submitted `2026-10-31T12:47:52Z` against `5f541618d768de9613ec27018721a0a139555fd1`,
reports raw multipart POSTs through direct UAPI on cPanel `11.138.0.19`. The
reviewer created disposable `wrvup`/`wrvup2` accounts, reported both accounts and
home directories removed and temporary tokens revoked. These calls bypassed the
client; they establish server observations, not generated-client execution,
committed sanitized capture or bridged/WHM upload coverage. No upload or capture
is repeated by this correction.

The reviewer reports known-length status `1`/succeeded `1` twice, while chunked
framing produced HTTP 200/status `0` with a missing-file error twice and with a
200 KB body. The `Cpanel/Form.pm:69` CONTENT_LENGTH gate is the reviewer's
installed-source explanation, not locally pinned source inspection. Likewise,
the reviewer's `extract_api_args`/`Cpanel/Args.pm:86` trace says every `api.*`
name is extracted; those Perl sources are not part of this local snapshot.
An escaped quote in `qu\"ote.txt` was reported stored as `qu` with status `1`;
percent-encoding `%22` was not tested.

The same report observes byte-identical CRLF/NUL/0xFF content and real zero-byte
files; UTF-8/spaces preserved; `<`, `>` and `;` rejected per file; duplicate
`dup.txt` parts stored as `dup.txt` and `dup-2.txt`; existing-name failure without
overwrite and replacement with `overwrite=1`. It reports query placement of all
four ordinary parameters, creation of a missing directory, `0644`/`600`
permissions and omitted-dir home behavior. One-good/one-bad returned status `1`;
a single existing filename returned status `0` with a row reason but generic
envelope errors. Reported integer counts/status/size, failed-row null size and
mixed diskinfo types agree with the pinned shape. Acceptance of `file`, lone
`file-1` and arbitrary filename-bearing part names is a server detail: retain the
documented file-N convention. These reports are version/route-specific, not
universal filename, overwrite or antivirus guarantees.

**Policy:** Known total length for every new multipart request, global quote
rejection, complete `api.` reservation and typed Fileman Unchecked are the
reviewed client decisions below. They do not infer WHM/bridge upload support,
virus-scanner verification or further endpoint coverage from direct raw calls.
Independent offline acceptance, reported live observations and future client
fixture evidence remain separate.

## Public upload value

Add `HostingPal\WhmApiClient\Upload` with these public static factories:

```php
Upload::fromPath(
    string $path,
    ?string $filename = null,
    string $contentType = 'application/octet-stream',
): Upload;

Upload::fromString(
    string $filename,
    string $contents,
    string $contentType = 'application/octet-stream',
): Upload;

Upload::fromStream(
    string $filename,
    StreamInterface $stream,
    string $contentType = 'application/octet-stream',
    ?int $size = null,
): Upload;
```

All source-bearing and filename arguments/frames are sensitive. Factory objects
are descriptors; factories perform no HTTP request, file open, content read or
stream seek. Paths are local filesystem paths only, never PHP wrappers or URLs.
Relative paths are anchored to the working directory when the descriptor is
created. Prefix relative POSIX filenames containing a colon with `./`, for example
`./report:2026.csv`, to avoid URI-scheme ambiguity. A path's basename supplies the default filename; overrides are explicit.
Factories may reject invalid descriptor metadata, including a negative explicit
size, without altering handle metadata because no API attempt has begun. The first
three `fromStream()` arguments and their names remain unchanged; optional fourth
`$size` is an assertion of the exact nonnegative number of bytes remaining at the
API call's starting cursor. It is neither the original stream's total size nor a
limit selecting a prefix to upload. Null requests derivation at call time, not an
unknown-length HTTP body.

Strings contain exact bytes, including NUL, empty content and non-UTF-8 binary
data. They are never treated as paths or base64-encoded. A zero-byte file is a
real part. Content type defaults uniformly; no MIME sniffing or extension-based
guessing occurs. An override must be a valid ASCII `type/subtype` token pair;
arbitrary parameters and part headers are outside the initial contract.

Filenames are nonempty valid UTF-8 basenames. Reject controls, DEL, path separators
(`/` and `\\`), literal double quotes (`"`) and the complete names `.` or `..`.
Preserve valid UTF-8 spelling and spaces; never silently basename an explicit
filename or rewrite it. Reject a double quote at descriptor validation for every
source factory: escaping it in a header does not preserve the observed stored
name. Do not percent-encode or otherwise rewrite rejected names as a workaround.
The typed Fileman adopter also rejects its documented `<`, `>` and `;` filename
characters. Generic upload values do not inherit those endpoint-specific
restrictions for other operations.

Descriptor diagnostics and debug/serialization behavior must not expose paths,
contents or stream internals: serialization is refused and debug information is
limited to source kind. Normal returned payloads retain the existing sensitive
data responsibility; this is not protection against callers explicitly reading
their own streams or logging PSR requests.

## Ownership and position

For `fromPath()`, open a readable regular local file in binary mode during request
preparation, after structural and route validation. Fail before HTTP if a path
cannot be opened or is not a regular file. Symlinks to regular local files are
allowed, including genuine empty regular files. Callers must supply path sources
with stable, reliable byte-size metadata. Virtual/generated pseudo-files with
misleading sizes are unsupported even when reported as regular files. This is a
caller prerequisite, not a promise to detect every unsupported source: no path
blacklist or extra-byte probe establishes metadata reliability. Unsupported sources
can yield incorrect lengths or truncated content; callers can materialize their
bytes themselves and use `fromString()` instead. Close every handle the library
opened on success, preparation failure,
transport failure and response failure. Do not delete or modify the source file.

For `fromStream()`, borrow the stream and consume it from the position at which
the API call begins. Never rewind it to zero or close/detach it, including when
the HTTP client closes the composite body. A successful send advances it; a
failure can consume some bytes. The caller must deliberately reposition a
seekable stream or supply another stream before another call. Non-seekable
readable streams remain supported when their remaining length is known or an
explicit `$size` supplies it. A known zero remainder produces an empty file;
unknown size is not inferred to be zero from EOF. Reject unreadable or detached
sources before HTTP without consuming bytes.

At call time derive remaining length as `getSize() - tell()` only when both values
are reliable nonnegative integers and the cursor is within the total size. Never
rewind or read to discover a length. A null `getSize()` does not make a readable
stream unusable: an explicit remaining-size assertion can supply the length. If
size or position is unknown and no exact remainder can be derived, require that
assertion. If explicit and derived remaining lengths are both known, reject a
disagreement before HTTP and before reading any source. Reject invalid lengths
or out-of-bounds positions locally. The caller must supply an accurate assertion
and must not mutate or advance the stream during a call. Borrowed metadata must
be truthful; supplying an explicit size cannot override disagreement with the
derived remainder. The library checks consistency, but cannot establish metadata
truthfulness without reading the source.

Reject the same borrowed `StreamInterface` object in two parts of one request:
its shared mutable cursor cannot represent independent files. Different wrappers
around one underlying resource cannot be identified reliably; callers must avoid
that aliasing and must not mutate any source during a call. Reusing path or string
descriptors opens independent files or uses independent byte cursors respectively.
Reusing a borrowed descriptor in a later call uses its then-current position.

Open all owned files and validate every source's readability and remaining length
before dispatch and before reading any source byte. Use exact byte-string lengths
and owned regular-file metadata without reading content. If a later open, length
check or request-total calculation fails, close every already-opened owned file,
leave all borrowed cursors unchanged and send zero requests. Unknown remaining
length is such a local rejection, with zero bytes read from every source. No
chunked fallback, buffering, spooling, drain or alternate route is permitted.
Subsequent read failures cannot be made atomic with the server and never trigger
retry, rollback or inferred cleanup.

## Streaming body and framing

The encoder produces a read-only PSR body composed of small framing segments and
file sources. It does not concatenate the entire body, pre-read borrowed streams
or spool uploads to disk. It must work with `read()`/`getContents()`; explicit
consumer calls to `getContents()` or string conversion naturally allocate their
requested output. The bounded-memory promise applies to library construction and
incremental reads, not buffering performed by an injected PSR client.

The initial composite stream may be non-seekable. Closing or detaching it releases
owned resources only; it never transfers or destroys a borrowed source. Seek,
write and detach behavior must satisfy PSR contracts and be covered independently.
The composite body's `getSize()` reports its stable total body length, including
after reads, as required by PSR; it does not report the changing unread remainder.
Each source contributes its remaining length at the starting cursor. Before HTTP
dispatch and before reading any source byte, compute the exact multipart total,
including every header, text part, CRLF, boundary and closing delimiter. Use
checked integer arithmetic; invalid or overflowing lengths reject locally with
the preparation cleanup above. Never drain a stream to size it.

Set exact `Content-Length` and multipart `Content-Type` with the selected boundary
on the final request; the library emits no `Transfer-Encoding`. The injected PSR
client must honor this framing. Requiring a known total for new WHM and direct
UAPI multipart calls is conservative client policy informed by direct Fileman
observations, not live proof of WHM behavior.

Bind each source's reads to its declared remaining length. Unexpected early EOF
or a read failure aborts safely with no retry; preceding bytes may already have
arrived at the server. An explicit size asserts the entire remainder and gives
no permission to truncate. The library cannot prove an unknown/non-seekable
source's actual length in advance or detect all excess bytes without reading
beyond that assertion. Do not probe or drain beyond the declared length merely
to verify it. Unverifiable excess is a caller-assertion limitation, not a claim
that a prefix upload is correct. Owned resources are always released and borrowed
sources are never rewound, closed or detached.

Use CRLF multipart framing, a library-generated boundary with at least 192 random
bits, `Content-Disposition: form-data` and the exact field names. File parts carry
filename and Content-Type; ordinary string fields do not masquerade as files.
Part contents are raw bytes, never form-encoded. Field/query values pass through
their appropriate encoder exactly once.

Preserve the existing collision-free preflight for in-memory string-only SSL
encoding. Streaming sources cannot be pre-scanned without violating the selected
contract. For the new encoder, choose a delimiter absent from already-known text
fields and use bounded look-behind to detect that delimiter across file read
chunks before emitting the matching sequence. A collision aborts with a generic
stream failure and no retry; earlier bytes may already have reached the server.
Do not claim atomic upload or collision-free preflight for streamed content.
An internal deterministic boundary seam permits offline collision tests.

The transport accepts `string|StreamInterface|null`, attaching a supplied PSR
stream without casting it to a string. Existing string and absent-body behavior
stays byte-compatible. Keep release of owned streams in a finally path enclosing
the complete synchronous attempt, including clients that consume only part of a
request or throw before/during reading it.

## Generic multipart escape hatch

```php
// WhmClient
callMultipart(
    string $operation,
    array $parts,
    array $query = [],
    OutcomePolicy $outcome = OutcomePolicy::Flag,
): CallResult;

// UapiClient
callMultipart(
    string $module,
    string $operation,
    array $parts,
    array $query = [],
    OutcomePolicy $outcome = OutcomePolicy::Flag,
): CallResult;
```

`$parts` has the precise shape
`list<array{name: string, value: string|Upload}>`. Ordered records permit intentional
repeated field names and retain file/text interleaving; a map would lose this.
Each record has exactly these two fields. Lists must be sequential; empty generic
lists are allowed and send a closing delimiter (endpoint validity is the caller's
responsibility). No null/number/bool coercion is inferred for text parts; callers
provide the intended textual representation. A string value always means a text
field, even if it looks like a path. `Upload` always means a file part.

Part names use the existing safe token alphabet `[A-Za-z0-9_.-]+`, are nonempty
strings, and are never normalized. Repeated explicit part names are permitted;
they are not silently collapsed. The typed generator separately rejects accidental
collisions between fixed fields and expanded families. `$query` uses the existing
`Parameters` representation, including its reviewed list markers.

Both calls fix HTTP POST and multipart Content-Type. They accept no URL, header,
authentication, method, account or boundary override. WHM and direct UAPI are the
initial generic routes. Bridged generic multipart fails locally after metadata
reset and before opening/reading sources; there is no credential switch or form
fallback. Existing reviewed bridged SSL string methods continue working through
their current operation-specific path. This restriction is not a claim that all
multipart bridge operations are impossible.

Reserve the entire case-sensitive `api.` prefix for both new WHM and direct
UAPI multipart calls: `api.` itself and every `api.*` name are forbidden in all
raw part names, raw query keys and expanded query fields. Check raw keys by
presence, including a reserved query key whose value is null. New UAPI multipart
calls additionally reserve the exact `cpanel.user`, `cpanel.module` and
`cpanel.function` names even on direct access; this is conservative client policy.
The unsupported bridge still rejects locally before any source open or read.
Lookalikes such as `API.version`, `apiVersion` and bare `api` remain permitted by
the existing token rule; do not normalize case or spelling.

Reject a collision between any part name and an emitted query-field name after
query expansion. Nonreserved null query values emit nothing, so their omission
creates no cross-placement wire collision and claims no declaration ownership.
Reserved raw keys still reject by presence even when null. Preserve intentional
repetition within the explicit part list. Legacy generic `call()` filtering is
unchanged.

Validate names, complete parts/query input, reservations, source metadata and
supported route after clearing response metadata and before any file read/open or
HTTP attempt. Normal outcome policy, CallResult and exception semantics apply.
An accepted envelope records metadata even on an API or later payload failure.
Raw generic results have no endpoint schema guarantee and add no typed coverage.
The same bytes/parts under an unsupported operation are not promised to work.

## First typed adopter: Fileman uploadFiles

Generate this method on the existing `fileman()` group:

```php
uploadFiles(
    array $files,
    ?string $dir = null,
    ?bool $getDiskInfo = null,
    ?bool $overwrite = null,
    ?string $permissions = null,
): array;
```

`$files` is `non-empty-list<Upload>`, sensitive throughout every frame. Number it
from zero as `file-0`, `file-1`, etc.; reject sparse keys and non-Upload values
without renumbering. Caller filenames determine stored names. Duplicate filenames
remain in order: the source describes server renaming of the second duplicate;
do not deduplicate or promise a particular final name. One invocation is one
request, not a per-file loop.

The four ordinary arguments are the pinned query parameters for this multipart
operation. Omitted nulls preserve server defaults; explicit false sends `0`.
Permissions remain a string, preserving `0644` and `644` without integer conversion.
Relative target directories are account-home relative; omission defaults to the
home directory, and the source says a missing destination is created. Overwrite
defaults to false upstream; never silently enable it. The API scans uploaded files
for viruses and can reject them. Local validation does not establish server-side
acceptance or antivirus protection.

Direct cPanel authentication only: a WHM-configured UAPI handle must reject this
typed call before opening or consuming files. The generated spec must express a
route restriction, not hide it in a handwritten group method. Other typed SSL
multipart string routes retain their existing support.

The pinned return is an object with optional `failed`, `succeeded`, `warned`
integer counts, optional `uploads` list and optional `diskinfo`. Upload rows have
optional `file`/`reason` strings, optional nullable integer `size`, optional integer
`0|1` status and optional string-list warnings. File reason/warnings are payload
data, not exception-message templates. `diskinfo` preserves documented decimal
byte strings, integer|string inode counts, infinity strings and additional string
display fields. Generate its exact pinned shape; do not infer required fields,
coerce decimals to integers or interpret absent diskinfo as zero usage.

Generate `OutcomePolicy::Unchecked` explicitly in this typed operation's spec.
Preserve and validate the same exact pinned return shape for integer envelope
status `1` and `0`: all-success, one-good/one-bad and all-files-failed responses
with valid data return counts, per-file rows, reasons and warnings. In particular,
a single existing filename can return status `0` with a useful row reason.
Status `1` does not establish that all files were stored, and status `0` alone does
not throw under this typed policy. Callers inspect response metadata, counts,
row status/reason and warnings. Do not synthesize partial- or total-failure
exceptions or change the global `Flag` policy.

Unchecked does not waive envelope or payload validation. The existing typed UAPI
engine requires integer status `0|1`; missing, malformed or noninteger status is
an `InvalidResponseException`. HTTP and unusable-envelope failures still fail.
The data object remains required even though its pinned members are optional:
missing, null or malformed data, or an invalid member shape, raises
`PayloadMismatchException` with the response snapshot, unvalidated payload and
`outcomeConfirmed=false` under Unchecked. Valid data is not weakened to opaque or
optional output. Any failure can follow stored files; never retry automatically,
roll back or infer cleanup from a flag or exception.

Generic `callMultipart()` still defaults to `OutcomePolicy::Flag`; callers may
explicitly choose Unchecked to retain raw `CallResult` data. Under the unchanged
Flag default an all-failed status `0` raises `ApiErrorException` without the data
payload, and `ResponseMetadata::$uapi` excludes data, so its per-file reasons are
unavailable there. Do not extend that exception or the metadata snapshot. The
typed choice avoids this loss when the response contains a valid pinned payload.

## Security and verification

Paths, filenames, file bytes, query/part values and generated bodies must remain
sensitive throughout public and internal frames. Redact dynamic values from local
errors and exception causes. New source/stream exceptions must not chain arbitrary
path/content-bearing lower-level exceptions. Keep token redaction and known PSR
exception classification; a read failure may occur after remote state changes.
The caller's PSR client can inspect requests and is outside this trace guarantee.

Independent offline fixtures cover exact CRLF framing, file numbering, text versus
file parts, binary/NUL/empty bytes, filenames, repeated fields, source position,
non-seekable/unknown-length streams, duplicate-stream rejection, ownership on
every failure path, late invalid input with zero I/O, metadata reset, reservations,
header injection, collisions spanning read chunks and bounded read sizes. Assert
that request creation never reads entire sources, and that bodies are attached as
streams. Existing form/GET/SSL bytes and all supported envelopes remain covered.

Generator tests must start from pinned Fileman source, preserve known return
shapes, fail for unsupported binary constructs, generate explicit route and
Unchecked outcome policies, and reproduce committed PHP without handwritten
edits. Run Composer strict validation/check plus Go/source/map/generation checks
when their surfaces change. This PR corrects the design only; the following are
implementation acceptance fixtures, not newly executed tests or a RED claim.

| Area | Required independent offline acceptance |
| --- | --- |
| Known length | Current-position derivation, explicit remaining size on a non-seekable or null-size stream, known zero, matching explicit/derived sizes, exact final Content-Length and stable composite getSize() after reads. |
| Preflight failures | Unknown remainder without assertion, negative/invalid lengths, out-of-bounds cursor, explicit/derived disagreement and total overflow reject with zero HTTP, zero source reads, unchanged borrowed cursors and all opened owned files closed. |
| Read boundaries | Exact declared reads, early EOF and read failure abort without retry; no extra-byte probe or drain; inaccurate unverifiable excess remains the documented caller limitation. |
| Names and bytes | Reject literal double quotes in every Upload factory without escaping/rewrite fixtures; preserve UTF-8/spaces, raw binary/NUL/non-UTF-8 content and real zero-byte parts; typed-only `<`, `>`, `;` rejection. |
| Reserved prefix | Reject `api.`, `api.version` and arbitrary `api.other` in raw parts and raw or expanded query fields on WHM/direct UAPI, including reserved raw null keys; reject exact cpanel routing names on new direct UAPI. |
| Case and omission | Permit token-valid case/lookalikes and bare `api`; allow a nonreserved null query value alongside the same part name because no query field is emitted. |
| Expansion and repetition | Reject emitted query/part collisions after list-marker expansion; preserve repeated explicit part names and file/text order; reject accidental generated fixed/family collisions. |
| Fileman returns | All-success, partial status 1, and single-existing-file/all-failed status 0 preserve valid counts, rows, reasons and warnings under generated Unchecked. |
| Fileman failures | Missing/null/malformed required data and malformed members preserve payload-mismatch metadata with outcomeConfirmed=false; missing/invalid/noninteger status, HTTP and envelope failures retain their distinct exceptions and never retry. |
| Compatibility | Generic default Flag and explicit Unchecked remain distinct; legacy form/GET/SSL bytes, metadata lifecycle, ownership and supported envelopes remain intact. |

A separate opted-in live fixture may use only fresh owned files/directories on
designated test accounts, with overwrite disabled, unique names, one small binary
and one empty/text file. Confirm exact bytes and clean up only confirmed owned
resources. Uncertain outcomes are not ownership proof. Capture structural evidence
and fixture expectations without retaining file contents, filenames, paths or
tokens. Do not repeat completed historical captures. This fixture does not run
Backup restores, root configuration uploads or lockout operations.

## Delivery boundaries and builder choices

Keep the spec in its own PR. File durable tasks for Upload/stream ownership,
streaming encoder/transport, generic calls, generated Fileman adoption and final
compatibility/evidence audit. The existing `8x0.1.19` owns endpoint delivery rather
than a duplicate endpoint issue. Other five binary endpoints stay open pending
individual audit; old nine-operation inventory is not a completion claim.

Beads is the durable implementation plan:

| Bead | Deliverable |
| --- | --- |
| `8x0.30.1` | Publish this specification |
| `8x0.30.2` | Explicit Upload descriptors and ownership-safe source primitives |
| `8x0.30.3` | Streaming multipart framing and PSR transport integration |
| `8x0.30.4` | Generic callMultipart and route/reservation lifecycle |
| `8x0.1.19` | Generated Fileman uploadFiles with binary-list/route-policy support |
| `8x0.30.5` | Integrated compatibility and owned-fixture evidence audit |
| `8x0.30.6` | Source audit of the other five binary multipart endpoints |

The runtime tasks depend in order on the preceding task; generic calls also
depend on the shared ordered-parameter foundation `8x0.28.2`. Fileman depends on
generic multipart delivery, and the final audit depends on Fileman. The remaining
endpoint audit needs this design but need not wait for runtime implementation.

Internal class names, bounded read-buffer size, exact generator schema spelling
and focused test organization belong to the builder. Preserve the public names,
signatures, source/route semantics and lifetime contract above. Reuse shared
ordered-field/reservation work from the indexed/map design where useful; multipart
file numbering is not permission to guess other operation index bases. JSON body
and multipart capabilities are independent, even if both extend request dispatch.

The user's latest approval resumes implementation of the three agreed designs.
Implementations must incorporate the reviewed public-contract corrections from
PRs 72, 73 and 74 before delivery; that requirement does not pause the owner's
resumed implementation. Review and integration remain in the existing chats; an
implementation PR's publication is distinct from its external approval, merge
and live coverage.
