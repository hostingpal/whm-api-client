# Runtime architecture detail

> Detailed runtime invariants behind the summary in [AGENTS.md](../../AGENTS.md#architecture-and-conventions).
> Moved verbatim from `AGENTS.md` on 2026-11-17. Read the relevant section before changing `src/`, the
> generator or request encoding. The live-test authorizations stay in `AGENTS.md`.

## Generated surface and the operation runner

- Generated surface details: results are arrays read with wire keys
  (`$client->uapi('npr')->domainInfo()->listDomains()['main_domain']`); omitted fields
  are absent keys and explicit nulls stay `null`. `whm()->accounts()->listAccounts()` and
  `accountSummary()` return the `acct` record list (`accountSummary()` does not require
  exactly one record); `hostingPlans()->listPackages()` returns the `pkg` list.
  `listAccounts()` alone selects `account_search_empty`: success with omitted/null
  data and result 0 with exact `No accounts found.` plus omitted data return `[]`.
  Retain original metadata; other failures and present failure data still throw.
  The 11.138.0.15 successful-null replay is historical structural evidence, separate
  from the verbatim 11.138.0.19 omitted-data bodies. Summary, mutations, packages and
  generic Flag calls keep existing failure/payload rules.
  `createAccount()`, `changePassword()` and `modifyAccount()` return `null` when the
  server sends no data (overlay `optional_payload`, engine `optional` spec key) and the
  documented array otherwise. Overlay `order` puts the key arguments first in
  `listAccounts(search, searchtype, searchmethod, want)`, `suspendAccount(user, reason, ...)`,
  `changePackage(user, package)`, `changePassword(user, password, ...)` and
  `verifyUserHasFeature(user, feature)`; prefer named arguments. Response validation
  enforces the effective PHP shape, required-field presence, null/omission rules
  and evidenced wire conversions. General minimum/range/enum constraints are not
  currently enforced; additions need explicit compatibility decisions per field
  or reviewed family (bead `whm-api-client-8x0.18`). Preserve existing integer
  bounds and flag rules. Combined alternatives do not establish exact JSON Schema
  validation; one server's observed values do not define a universal closed enum.
  Load averages keep `int|float|numeric-string` to preserve precision. Feature access
  returns true for unknown feature IDs by upstream design, and disabled-list values have
  inverted meaning. Pinned contracts, observed compatibility and runtime evidence stay
  separate; the read-only live suite passes through the generated groups on
  `test.oblak.host`.
- Generated groups receive one synchronous `run(spec, arguments)` callback from their
  handle, which clears the metadata first. The internal `OperationRunner` checks the
  whole spec before dispatch (`SpecChecker`: operation keys, every parameter spec and
  the full type grammar, cached per spec; `GeneratedSpecsTest` lints every generated
  spec offline), encodes the named arguments from the spec, dispatches through the
  handle (WHM and UAPI handles own route construction, with one account-bound UAPI
  helper for direct and bridged execution), validates the payload
  with `ShapeValidator` and the internal `PayloadReader`, which attaches the response
  snapshot to every failure, and unwraps the result. `ArgumentEncoder` returns an
  internal `OrderedFields` accumulator of expanded literal names/values, PHP argument
  origins and opaque repeat-family identities. Different origins cannot emit the
  same wire name; only intentional repeats from one family may share it. Handles
  reject generated collisions with routing reservations before any filtering:
  WHM, direct UAPI and bridged UAPI reserve the entire case-sensitive `api.`
  prefix; bridged UAPI also reserves exactly `cpanel.user`, `cpanel.module` and
  `cpanel.function`. Static spec lint rejects these declarations (including
  bridge controls in shared UAPI specs) and overlapping numbered families before
  argument encoding, with exact `LogicException`; runtime field guards defend
  future expansions with `InvalidArgumentException`.
  `withRouting()` prepends routing fields while retaining original provenance.
  `ParameterExpander` and `CollectionEncoder` append fields rather than merging
  associative maps.
  `ParameterExpander` shares existing marker rules: `RepeatedList` repeats keys,
  `NumberedList` emits `name`, `name-1`, ... and `SuffixNumberedList` replaces its
  terminal wildcard with 1, 2, ... . Generic `call()` retains its associative input,
  plain repeated string lists, reserved-field filtering and suffix-only collision
  policy. All collection validation follows the metadata reset, before I/O; form
  names and values are encoded once. SSL multipart retains safe scalar string
  fields and literal value bytes. Selectors only bind callbacks and cache groups;
  they perform no I/O or metadata reset.

## Generic entrypoints, JSON values, response metadata and transport

- `whm()->callMultipart(operation, parts, query, outcome)` and direct-only
  `uapi()->callMultipart(module, operation, parts, query, outcome)` use fixed POST
  and the shared streaming transport/envelope lifecycle. Parts are an exact
  sequential list of name/value records; safe token names stay literal, strings
  stay text, Upload values are files, and repeated names/interleaving are preserved.
  Empty parts send a closing delimiter. MultipartInput validates the complete
  query/parts declaration, reservations and emitted-query/part collisions before
  source I/O. Query retains scalar/list markers plus multipart-only null omission;
  raw reserved keys still reject by presence. Both routes reserve all case-sensitive
  api. names, including bare api.; direct UAPI also reserves exact
  cpanel.user/module/function. Token-valid lookalikes remain data. Keep query secrets
  in parts because URLs may be logged. Local diagnostics and value-bearing library
  trace frames must omit dynamic names and values.
  Upload paths initially use POSIX local regular files with caller-supplied
  stable, reliable size metadata. Virtual/generated files with misleading sizes
  remain unsupported; metadata truthfulness is a caller prerequisite. Relative
  paths anchor at factory creation. Borrowed streams start at their call-time
  cursor, require a known exact remaining length and are never rewound/closed/
  detached. Reject duplicate borrowed identity; reuse paths/strings independently.
  Sources must stay stable and callers must avoid resource aliases. No extra-byte
  probes verify an unknown source's size assertion. Full preparation precedes reads;
  exact total Content-Length is mandatory. Owned files release on all exits,
  including partial consumption and pre-transport failures after body creation.
  Multipart source preparation RuntimeException maps to static unchained
  InvalidArgumentException before HTTP, with null metadata, zero reads/dispatch,
  unchanged borrowed cursors and all earlier owned files closed. Keep this catch
  confined to preparation; internal source/stream failures stay RuntimeException.
  Recorded streaming-body failure becomes a static unchained ApiException before
  original PSR client classification, including swallowed/wrapped failures. Healthy
  body/client exceptions retain existing classification or propagation; do not
  relabel synthetic errors as original PSR interfaces. Source/stream primitives
  retain their RuntimeException contract.
  One invocation is one attempt; never retry, roll back or infer remote ownership.
  Generic Flag can discard data/per-file reasons on status 0; explicit Unchecked
  retains raw CallResult data without endpoint schema guarantees. This adds no
  typed or live coverage. Fileman now has separate generated typed adoption with
  Unchecked and its exact pinned result; owned client capture stays 8x0.30.5. Existing
  operation-specific SSL multipart string bridge calls keep their reviewed path.
- `whm()->call()` and `uapi()->call()` are generic escape hatches: POST by default
  (GET only when passed explicitly), metadata cleared before name and parameter
  validation, reserved fields removed from caller parameters, `CallResult` with the payload
  converted to arrays and no schema. They support GET queries and form-encoded
  POST bodies only, with operation-specific parameter, route and envelope limits;
  JSON and multipart bodies remain unsupported by call(). Separate callJson()
  entrypoints accept validated raw JSON strings, always POST application/json,
  through WHM or direct UAPI only. Bridged UAPI rejects locally after metadata
  reset, before I/O. Raw execution requires object roots; direct UAPI preserves
  every caller byte. WHM inserts library-owned "api.version":1 as the first root
  member with a comma only for populated objects and otherwise preserves caller
  bytes, including whitespace, duplicate ordinary keys, huge numbers and escapes.
  No JSON query argument or URL version is sent: cPanel 11.138.0.19 reads JSON
  arguments only from the body. It removes root file- members. Native validation
  at depth 512 accepts at most 511 containers. Root reservations reject decoded
  member presence, including escaped names and nulls: both routes reserve the entire
  case-sensitive api. prefix, including bare api. and future controls; direct UAPI
  also reserves exact cpanel.user/module/function. Nested members remain data. Legacy call() filtering
  stays unchanged. Both return CallResult with standard envelopes/outcome policies;
  neither raw transport nor wrapper names establish typed or live coverage.
  Internal JsonBody distinguishes absent (no bytes/Content-Type) from present
  string 'null'; future typed dispatch resolves omission before serialization.
  Typed coverage comes from generation.
- Known structured JSON schemas retain arrays. Only literal additionalProperties:true
  or exactly {} on a typed, source-bound object lowers additional values to json.
  That node accepts strict scalars/null and explicit JsonObject/JsonList containers;
  bare arrays reject. An object with unrestricted additional values also accepts
  JsonObject while preserving known-field checks. Factories tag only; validate all
  contents after metadata reset, with one 511-container budget and bounded cycles.
  Descriptors retain PHP references, conceal secret-bearing frames, and add no typed
  endpoint/live coverage. Preserve whole-body object roots and routing restrictions.
- Each attempt clears old response metadata. A usable envelope records an
  immutable snapshot before API or payload errors. `ResponseMetadata::$whm`
  retains raw WHM metadata; `$uapi` retains the UAPI result envelope without `data`.
  `ApiException::$metadata` exposes the same snapshot, with distinct
  `ApiErrorException`, `HttpException`, `InvalidResponseException`, and
  `TransportException` failures. A payload that fails its generated spec after the
  envelope and outcome were accepted raises the `PayloadMismatchException` subclass
  (`$outcomeConfirmed`, unvalidated `$payload`): writes are validated as strictly as
  reads, and callers must not retry a mutation that took effect (8x0.23). Both bridge
  outcomes are checked. Do not apply
  these operations' status semantics universally or claim generic bridge coverage.
  Typed metadata accessors and `errors()` read the documented envelope fields;
  `ApiException::errors()` exposes upstream error text outside `getMessage()`.
  `TransportException` records the PSR failure kind, cause class and token-redacted
  cause message without chaining the cause.
- Authenticated operations perform no retries. The one narrow exception is
  development-only: `tools/live-verify/src/DisposableAccountLifecycle.php` re-reads its
  read-only `listaccts` cleanup-ownership check, at most three reads per decision and
  only after a `TransportException`, so one lost read cannot abandon a disposable
  account and its token. `Client` requires an explicit
  `HttpTransport`; its PSR-18 client must verify TLS, disable redirects and set
  timeouts, and `HttpTransport::discover()` is the explicit opt-in for unverified
  discovered defaults. The API layer rejects HTTP 3xx responses. Handles never
  expose `ClientConfig` or credentials, and credentials refuse serialization.
  Every library frame between a public method and the PSR-18 client that receives
  request parameters marks them `#[SensitiveParameter]`, so the library's trace
  frames carry no secrets even with `zend.exception_ignore_args=0`;
  `SensitiveTraceTest` searches them recursively for every failure type, including a
  client that throws inside `sendRequest()`. That client's own frame holds the
  request (token, GET query) and is outside the library's control.
  Direct UAPI supports the pinned `result` wrapper and the observed bare result
  object. A present `result` takes precedence even if malformed; the bare form
  requires top-level `status`. Authorized direct library verification on
  2026-10-13 for `DomainInfo/list_domains` as `npr` on `test.oblak.host:2083`
  passed with a cPanel token, the expected main domain, and HTTP 200/status 1.
  Keep this runtime evidence separate from pinned source contracts. Infrastructure
  tests and WHM bridge evidence do not establish other endpoint or route coverage.

The `single_result_status` outcome check applies to the generated ZoneEdit reset.
After accepted dispatch it requires exactly one rebuild row. A strict integer 0
raises a fixed-message API failure and 1 confirms success before ancillary
payload validation. Ambiguous rebuild outcomes raise a payload mismatch with
`outcomeConfirmed` false. The ordinary envelope and metadata paths run first.
