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
): Upload;
```

All source-bearing and filename arguments/frames are sensitive. Factory objects
are descriptors; factories perform no HTTP request, file open, content read or
stream seek. Paths are local filesystem paths only, never PHP wrappers or URLs.
Relative paths are anchored to the working directory when the descriptor is
created. A path's basename supplies the default filename; overrides are explicit.
Factories may reject invalid descriptor metadata without altering handle metadata
because no API attempt has begun.

Strings contain exact bytes, including NUL, empty content and non-UTF-8 binary
data. They are never treated as paths or base64-encoded. A zero-byte file is a
real part. Content type defaults uniformly; no MIME sniffing or extension-based
guessing occurs. An override must be a valid ASCII `type/subtype` token pair;
arbitrary parameters and part headers are outside the initial contract.

Filenames are nonempty valid UTF-8 basenames. Reject controls, DEL, path separators
(`/` and `\\`) and the complete names `.` or `..`. Preserve valid spelling;
never silently basename an explicit filename or rewrite it. The header encoder
quotes/escapes a literal double quote safely. The typed Fileman adopter also
rejects its documented `<`, `>` and `;` filename characters. Generic upload values
do not invent that endpoint-specific restriction for other operations.

Descriptor diagnostics and debug/serialization behavior must not expose paths,
contents or stream internals: serialization is refused and debug information is
limited to source kind. Normal returned payloads retain the existing sensitive
data responsibility; this is not protection against callers explicitly reading
their own streams or logging PSR requests.

## Ownership and position

For `fromPath()`, open a readable regular local file in binary mode during request
preparation, after structural and route validation. Fail before HTTP if a path
cannot be opened or is not a regular file. Symlinks to regular local files are
allowed. Close every handle the library opened on success, preparation failure,
transport failure and response failure. Do not delete or modify the source file.

For `fromStream()`, borrow the stream and consume it from the position at which
the API call begins. Never rewind it to zero or close/detach it, including when
the HTTP client closes the composite body. A successful send advances it; a
failure can consume some bytes. The caller must deliberately reposition a
seekable stream or supply another stream before another call. Non-seekable
readable streams are supported; empty/EOF streams produce empty files. Reject
unreadable or detached sources before HTTP without consuming bytes.

Reject the same borrowed `StreamInterface` object in two parts of one request:
its shared mutable cursor cannot represent independent files. Different wrappers
around one underlying resource cannot be identified reliably; callers must avoid
that aliasing and must not mutate any source during a call. Reusing path or string
descriptors opens independent files or uses independent byte cursors respectively.
Reusing a borrowed descriptor in a later call uses its then-current position.

Open all owned files and validate source readability before dispatch. If a later
open fails, close earlier owned files, leave borrowed stream positions unchanged,
and send zero requests. Subsequent read failures cannot be made atomic with the
server and never trigger retry, rollback or inferred cleanup.

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
Get size without reading content: return a correctly bounded total remaining
size only when all part lengths/starting positions are known; otherwise return
null. Never drain a stream just to compute Content-Length. Unknown-length support
ultimately depends on the injected HTTP client/server; fail clearly without a
hidden buffering or alternate-route fallback when sending fails.

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

WHM reserves `api.version`; new UAPI multipart calls also reserve `cpanel.user`,
`cpanel.module` and `cpanel.function`, as well as `api.version`, on both routes.
Reject reserved names in all part records and query fields after expansion,
including a reserved field whose query value is null. Reject cross-placement
query/part name collisions. Repetition within the explicit part list remains
intentional. Legacy generic `call()` behavior is unchanged.

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

UAPI success does not imply that every file succeeded. Return documented counts
and per-file statuses; do not raise a new synthetic exception for a partial batch
whose envelope succeeded. Existing error policy still throws when the API envelope
fails, including all-files-failed errors. Do not promise recovery of a full data
payload from ApiErrorException; any enhancement there is separate. A payload
mismatch after an accepted outcome can mean files were stored; never retry it.

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
shapes, fail for unsupported binary constructs, generate explicit route policy,
and reproduce committed PHP without handwritten edits. Run Composer strict
validation/check plus Go/source/map/generation checks when their surfaces change.

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
Review and integration remain in the existing chats; an implementation PR's
publication is distinct from its external approval, merge and live coverage.
