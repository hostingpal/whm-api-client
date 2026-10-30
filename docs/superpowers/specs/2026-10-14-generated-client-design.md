# Contract-driven generated client for full API parity

Status: implemented through phase 1 (plans 1-4); design agreed with the user on
2026-10-14.
Delivery and validation policy refined on 2026-10-15 under
`whm-api-client-8x0.26`. The first phase-2 evidence runner and fingerprints are
implemented under `8x0.10.1`, with Email inventory added under `8x0.10.2`.
At the PR20 checkpoint (2026-10-16), [generated coverage](../../api/coverage.md)
contained 74 operations in 13 groups. The
[saved evidence](../../api/evidence/2026-10-16-discrepancy-policy.json) covers
43 route-specific probes across 25 operations, including account/domain/PHP,
Email, SSL/AutoSSL, Quota and MySQL inventory. Its discrepancy review passes with
2 acknowledged Quota observations, 0 unexpected and 0 stale; their field contracts
remain unresolved under `8x0.4.3.1`. That checkpoint's empty MySQL lists did not
establish row-field evidence. The subsequent
[2026-10-17 fixture run](../../api/evidence/2026-10-17-mysql-populated.json)
observes all documented MySQL row fields and populated/empty nested lists on both
routes, with confirmed cleanup and no contract correction. The separate
[finite-Quota scenario](../../api/evidence/2026-10-17-finite-quota.json) adds five
observations with successful finite-limit checks and cleanup; its command exits
1 for two unacknowledged unknown-field observations, without reusing baseline
acknowledgments or changing the Quota contract. The consumer exercise
is deferred by the user as of 2026-10-17; further coverage remains unfinished. The
correction cycle below completed through PR25, followed by the separately approved
PR26 provisioning reads (82 operations). The owner authorized completing the Mysql,
FTP and PostgreSQL sections on 2026-10-30; current [generated coverage](../../api/coverage.md)
is 131 operations across 15 groups, including all 25 pinned Mysql, 20 Ftp and
14 Postgresql functions. Full API parity remains unfinished.
Decision record: `whm-api-client-8x0.10`. This design supersedes the
"typed core plus generic calls / lean pattern" scaling decision
(`whm-api-client-8x0.7`, recorded in the
[PHP client design](2026-10-13-php-client-design.md#scaling-decision-typed-core-plus-generic-calls))
and the DTO return-convention rule adopted for the WHM feature records
(`whm-api-client-8x0.6`). The agreed interface entry points, credentials, routing,
metadata and exception contracts of the PHP client design remain in force.

## Goal and constraints

- **Full parity.** Every documented WHM API 1 and cPanel UAPI function on the
  server gets a typed, documented method: 1,323 functions / 1,325 variants in the
  pinned map, minus 13 deprecated functions (1,310).
- **AI-maintained.** Agents maintain the repository across many independent
  sessions. Consistency must hold by construction, not by convention, and a
  convention change must not mean rewriting a thousand methods by hand.
- **Readable at scale.** Methods keep PHPDoc because it is how humans and agents
  navigate a very large codebase. Architecture, style and runtime performance
  matter more than line count.
- **The documentation is not authoritative.** cPanel often behaves differently
  from its documentation. The first 39 operations showed about one discrepancy per
  4–5 operations (bare direct-UAPI envelope, string ports and `uid`, string and
  boolean flags, null inodes, undocumented enum values and fields, omitted
  fields), so 250–350 discrepancies are expected across the surface. Finding and
  recording them systematically is the core work; trimming documentation prose is
  deferred.
- **Live verification of everything.** `test.oblak.host` and its live domain may
  be used for all operations, including destructive ones, because a server
  snapshot exists. The user explicitly reaffirmed on 2026-10-15 that live tests
  may perform any operation, including deletion, on designated test accounts,
  whether existing or created by a run. This is standing authorization with no
  repeated operation/target/rerun approval or account-test snapshot gate. Keep
  server-wide and lockout phases separate. Snapshot restores are performed by the
  user, never automatically.
- **Unchanged constraints.** Framework-independent PHP 8.3 library, PSR-18/17
  transport, PHPStan maximum level, PSR-12 plus the selected Slevomat rules, a
  public repository (no credentials or live payload values committed), offline CI.
- **Breaking changes are acceptable.** The package has no tags, releases or
  external consumers yet; current method names and DTOs may change.

## Surface profile (pinned map, 2026-10-13 sources)

| Measure | Value |
| --- | --- |
| Functions / variants | 1,323 / 1,325 (UAPI 693 in 105 modules; WHM 630) |
| WHM grouping | every WHM operation maps to exactly one of 29 documented `x-tagGroups` |
| Documented HTTP methods | 1,272 GET, 53 POST |
| Risk | 513 read-only, 717 mutating, 95 unknown |
| Parameters per variant | mean 2.0, median 1, max 62; 401 take none |
| Parameter types | 1,532 string, 693 enum (467 are 0/1 flags), 197 integer, 33 array, 147 untyped |
| Response payload fields | mean 5.2, median 1, p90 11, max 776; 381 have no fields |
| Irregular payloads | 159 untyped objects, 75 unions, 79 maps |
| Operation extensions | `x-readonly` 1,230, `x-rollback` 350, `x-cpanel-plugin` 34, `x-cpanel-internal-only` 28, `x-cpanel-available-version` 1,321, `x-codeSamples` 1,323 |
| Deprecated | 13 flagged (`deprecated: true`) |
| Names without a separator | 96 WHM, 78 UAPI (legacy concatenations and single words) |

Hand-writing the remainder at the measured cost (about 70–160 source and 140
test lines per operation) extrapolates to 280,000–400,000 lines across 50–130
agent sessions. Generation from contract data is expected to produce roughly
35 lines per operation (about 45,000–50,000 uniform lines) plus data-driven tests.

## Decisions

| Topic | Decision |
| --- | --- |
| Approach | Generate the typed surface from the canonical map plus an evidence-bound overlay corrected by live verification |
| Deprecated functions | Exclude the 13 flagged functions from generation, with documented reasons; generic calls remain subject to supported request encodings, routes and envelopes |
| Groups | UAPI: one group per module (105). WHM: one group per documented `x-tagGroups` entry (29) |
| Method names | Mechanical camelCase of the documented name; the overlay names every separator-less function and resolves collisions |
| Returns | Plain arrays with generated PHPStan shapes; no DTO classes |
| Generator | Go, inside `tools/api-map` |
| Response validation | One hand-written engine interprets compact generated specs (class constants) |
| Evidence | Sanitized type fingerprints (paths and types, no values) are committed |
| Escape hatch | `whm()->call()` and `uapi()->call()` provide GET/form POST access for compatible excluded or not-yet-generated functions; JSON/multipart bodies remain unsupported |

## Architecture

```
pinned sources ──► canonical map (tools/api-map, exists)
                         │
overlay (docs/api/overlay/**) ──► effective contract (.cache, never committed)
        ▲                                │
        │                                ▼
live runner fingerprints ──► generator (Go) ──► src/Api/** + specs + fixture rows (committed)
(docs/api/evidence/**)                            │
                                                  ▼
                              hand-written core: Client, handles, transport, engine
```

The **unit of maintenance is contract data**. Agents edit the overlay (naming,
request facts, observed response corrections, probe recipes, exclusions) and,
rarely, the generator or the hand-written core. Generated PHP is a deterministic
rendering and is never edited by hand.

## Public API surface

**Entry points are unchanged.** `$client->whm()` and `$client->uapi(?string $user)`
keep the agreed credential, routing, account-isolation, `responseMeta()`,
exception and `errors()` contracts. `call()` remains.

**Groups.**

- UAPI: one group per module, accessor and class names derived mechanically:
  words split at case boundaries, a run of capitals is one word (`LangPHP` →
  `langPhp()` / `LangPhp`, `DNSSEC` → `dnssec()`, `DomainInfo` → `domainInfo()`).
- WHM: one group per `x-tagGroups` entry. Its 29 accessor names are curated in the
  overlay (for example `Web Server Security (ModSecurity)` → `modSecurity()`,
  `Login Security (cPHulk)` → `cphulk()`, `SSL Certificates` → `ssl()`). The
  operation's documented subcategory tag appears in its docblock.
- Group accessors never collide with handle methods (`call`, `responseMeta`,
  `username`, `isBridged`); the overlay renames on collision.

**Method names.**

- Default: camelCase of the documented function name (`add_zone_key` →
  `addZoneKey`, `list_pops` → `listPops`).
- Every function whose name has no separator (174 today), a leading underscore or
  a `/` must have an explicit overlay name, even when it is unchanged
  (`lookup`), so curation is complete and auditable: `listaccts` → `listAccounts`,
  `addzonerecord` → `addZoneRecord`, `addaddondomain` → `addAddonDomain`.
- The overlay resolves collisions within a group.
- Each docblock states the exact wire name and the API-map identity, so the
  official documentation stays searchable.

**Parameters.**

- Every documented parameter becomes a PHP named argument: required parameters
  first, then optional ones, each in documented order. Optional parameters are
  nullable with default `null`, which omits them from the request.
- PHP names are the camelCase of the wire name; names that are not valid
  identifiers (`MXCHECK-domain`, dotted names) are mapped by rule or overlay.
- Types: `string`, `int`, `int|float` for `number`; 0/1 flags become `?bool`
  encoded as `0`/`1`; other enums stay `string`/`int` with a PHPDoc literal union
  and are not enforced at runtime; array parameters become `list<string>` with a
  declared encoding (repeated key or WHM-numbered `name`, `name-1`, …); object
  parameters follow their overlay entry.
- Secret-bearing parameters (overlay flag, suggested by name heuristics such as
  password, token, secret, key) receive `#[SensitiveParameter]` and force POST.
- HTTP method within the supported encodings: read-only operations use GET; mutating, unknown-risk and
  secret-bearing operations use form-encoded POST (routing fields stay in the
  query string). The overlay can override with evidence. This policy does not
  implement documented JSON or multipart request bodies.

**Returns.**

- The method returns the documented payload: an object becomes an array shape, a
  list becomes `list<…>`, a scalar stays scalar, and an operation without a payload
  returns `void`. The overlay may declare `unwrap` to return a single field
  directly (for example WHM `version` → `string`).
- Values whose observed wire type differs from the pinned type are normalized to
  the pinned type when the conversion is lossless (`"80"` → `80`, `"1"` → `1`).
  When no lossless normalization exists, the overlay widens the declared type
  instead (load averages keep `int|float|numeric-string` to preserve precision).
- Scalar alternatives remain unions. Object, list and map alternatives are
  combined to preserve their documented fields; required fields are intersected
  across alternatives. This widens branch combinations and does not enforce
  discriminator exclusivity. Maps become `array<array-key, T>`; untyped
  objects become `array<string, mixed>` with a docblock note until live evidence
  refines them. Recursive schemas are expanded to a depth of 8, below which the
  node is typed `array<array-key, mixed>`.
- Unknown response fields are dropped from the typed result. The implemented live
  runner observes the same response before this filtering and normalization, so
  its fingerprints can reveal extensions for review. A successful empty list
  supplies no evidence about the types of its unobserved record fields.
- There are no DTO classes. The existing DTOs (`AccountDomains`, `DomainInfo`,
  `ServerDomains`, `DiskPartition`, `ServerDiskUsage`, `ServerLoadAverage` and the
  WHM feature records) are retired along with `toArray()`.

**Exclusions.** The 13 deprecated functions are excluded, each with its
documented reason:

| Kind | Functions |
| --- | --- |
| Successor with the same purpose | WHM `get_available_featurelists` (→ `get_featurelists`), `read_featurelist` (→ `get_featurelist_data`), `dumpzone` (→ `parse_dns_zone`); UAPI `DCV/ensure_domains_can_pass_dcv` (→ `DCV/check_domains_via_http`) |
| Retired mechanism | WHM `accesshash`, `get_remote_access_hash` (replaced by API tokens, not drop-in) |
| No successor | WHM `disable_mail_sni` (always fails), `get_ecommerce`, `set_ecommerce`; UAPI `Themes/get_theme_base` (no useful output), `Sitejet/get_templates`, `Sitejet/set_template`, `SSL/check_shared_cert` |

## Validation and compatibility policy

The runtime guarantees the declared PHP result shape: types, required-field
presence, omission versus explicit null, and the lossless conversions explicitly
admitted by the effective contract. Reads and writes use the same rules. A
successful API outcome and a valid typed payload remain separate facts.

General OpenAPI minima, maxima and enums are not currently enforced. Extending
validation is a field-level or reviewed-family decision (`whm-api-client-8x0.18`),
not an automatic consequence of finding a keyword in the source. Each decision
records the pinned constraint, observed representations, the consumer invariant
it protects, and either enforcement or a documented compatibility exception.
Existing type rules, including integer bounds and 0/1 flag validation, remain in
force. An open string domain must not become a closed enum merely because one
server has returned only known values.

The effective contract is a typed compatibility model. Combined alternatives,
depth limits and opaque metadata have explicit limits; do not advertise exact
JSON Schema validation. Observations are scoped to their server version, route
and tested cases. Keep documentation facts and runtime corrections separately
attributed, and retain independent regression fixtures for every new constraint
or relaxation. A generated fixture agreeing with its own generated validator is
not sufficient evidence of upstream correctness.

## Overlay

The overlay is the only place where judgment is recorded. It lives in
`docs/api/overlay/uapi/<Module>.json` and `docs/api/overlay/whm/<group>.json`, is
validated against a published JSON schema, and is keyed by API-map operation ID.
One file per group keeps parallel agent sessions free of merge conflicts.

Per operation it can declare:

- **Naming and grouping:** method name, group reassignment.
- **Request facts:** HTTP method, parameter renames and encodings, sensitive
  parameters, outcome policy (`flag` or `unchecked`).
- **Response corrections:** per JSON path, the observed types, the declared type
  after correction, nullability, undocumented fields, and normalization; optional
  `unwrap`.
- **Documentation:** short observed-behaviour notes appended to the generated
  docblock.
- **Exclusion:** a reason and its evidence.
- **Probe recipe:** see [Live verification](#live-verification).

Every correction carries evidence: its kind (`doc` or `live`), the cPanel version,
date and rationale. Phase-1 live entries cite scoped prose observations. Phase-2
corrections additionally cite the fingerprint they were derived from; older
observations acquire that stronger evidence only when recaptured. An entry
without the currently required evidence fails validation. Per-group overlay files also hold the group's
curated accessor name (WHM) and any group-level docblock text.

## Effective contract and generator

The Go tool gains commands to validate the overlay, build the effective contract
(pinned schema with the overlay applied) into `.cache/`, and generate PHP. Output
is deterministic: the same map, overlay and generator produce byte-identical
files. Generated files carry a "generated by tools/api-map; do not edit" header.
The generated PHP is committed so consumers never need Go; CI regenerates it and
fails on any difference.

Generation is checked against the selected overlay scope. The current `listed`
scope contains 74 operations; only a complete `all` scope establishes that every
non-excluded operation in the map is generated. The generated coverage summary
currently reports generated groups and exclusions. Independent offline tests and
saved live fingerprints cover scoped cases separately; neither is implied by
generation. Consolidated coverage reporting remains planned work.

Report the fixed documented target alongside generated operations, tested cases,
and live observations. A live record identifies the route, version, code revision,
contract hashes and outcome; skipped, unsupported and failed probes carry explicit
reasons and never count as verified success. One route, version or empty payload
does not prove another. The summary replaces duplicate implementation registries,
while the evidence retains these separate claims.

## Generated PHP and the runtime engine

**Layout.**

- `src/Api/Uapi/<Module>.php` and `src/Api/Whm/<Group>.php`: `final readonly`
  group classes holding only methods and their docblocks. The shared lifecycle
  (immediate execution, metadata reset, single attempt, envelope retention) is
  documented once on the group class and the handles.
- `src/Api/Uapi/Spec/<Module>Spec.php` and `src/Api/Whm/Spec/<Group>Spec.php`:
  compact per-operation specs as class constants (wire function name, HTTP
  method, outcome policy, parameter wire names and encodings, response shape
  rules).
- Generated traits `UapiGroups` and `WhmGroups`, used by the hand-written handles,
  provide the lazy, cached group accessors.

**Generated method form.** Each method passes its arguments and spec to an
account-bound run callback supplied by its handle and returns the validated
result:

```php
/**
 * Return the account's email addresses.
 *
 * <first paragraph of the documented description>
 * Observed: <overlay notes, when present>.
 *
 * @param ?string $regex Filter by Perl regular expression; null omits it.
 * @param ?bool $noValidate Skip mailbox validation; null omits it.
 * @return list<ListPopsRecord>
 * @throws ApiException If transport, HTTP, the outcome, or the payload fails.
 * @see https://api.docs.cpanel.net/openapi/cpanel/operation/list_pops
 * API: uapi:Email/list_pops#GET (wire name list_pops)
 */
public function listPops(?string $regex = null, ?bool $noValidate = null): array
{
    /** @var list<ListPopsRecord> $result Validated against EmailSpec::LIST_POPS. */
    $result = ($this->run)(EmailSpec::LIST_POPS, ['regex' => $regex, 'noValidate' => $noValidate]);

    return $result;
}
```

The `@var` assertion is generated from the same contract as the spec the engine
enforces; engine tests and generated fixture rows verify that the two agree.

**Engine.** One hand-written path in the core:

1. drops `null` arguments (omission);
2. encodes booleans as `0`/`1` and lists as repeated or numbered parameters, as the
   spec declares;
3. selects GET or POST and the outcome policy from the spec;
4. executes through the existing request lifecycle (metadata reset, single
   attempt, envelope reading, typed exceptions);
5. validates and normalizes the payload against the spec in a single pass,
   raising `PayloadMismatchException` (an `InvalidResponseException`) with the
   response snapshot on mismatch.

**Write responses** (decision of 2026-10-15, `whm-api-client-8x0.23`). Mutations are
validated as strictly as reads; the typed return contract never weakens. Because a
mismatch is raised only after the envelope and outcome were accepted, it must not be
mistaken for a failed operation: `PayloadMismatchException` exposes
`$outcomeConfirmed` (true under the flag policy, so the operation took effect; false
when the outcome was not checked) and `$payload`, the unvalidated decoded data member
as plain arrays (null when omitted or null), so callers can recover data instead of
retrying a non-idempotent call. It covers shape mismatches, an omitted declared data
member and a missing unwrap field; envelope and JSON failures keep the plain
`InvalidResponseException`, and spec defects stay `LogicException`. Every generated
method with a payload documents it in `@throws`.

**Performance.** Specs are immutable class constants that opcache stores once;
groups load lazily through PSR-4 and are cached per handle; validation is one
linear walk of the payload. HTTP latency dominates by orders of magnitude.

## Live verification

**Runner.** A development-only PHP runner in `tools/live-verify/` drives
the generated client itself. It runs only with explicit opt-in and never in CI.
Start with the bounded slice below, then extend its recipes alongside useful
generated modules.

Observe the raw response from that same HTTP exchange before shape validation,
normalization, field removal or unwrapping. A development transport decorator can
provide this boundary without adding a public observation API. Capture must
preserve the status, headers and body supplied to the client, including stream
semantics, and must not change outcomes, exceptions or retry behavior. Never
repeat a request through `call()` merely to obtain its raw payload. Raw bytes and
decoded values remain in memory; only sanitized evidence leaves the process.

**Probe recipes** live in the overlay, per operation: arguments (which may
reference fixtures such as `{account}`, `{domain}`, `{mailbox}`, `{database}`),
required fixtures, phase, expected outcome (success or a known error such as a
missing plugin), and rollback (from `x-rollback` where documented, otherwise
explicit). Recipes are seeded from `x-codeSamples` and refined in batches.

**Fixtures.** Initial read-only recipes use the already approved test account
and resources, without creating anything. Later mutation recipes may use existing
explicitly designated test accounts or create the disposable fixtures their batch
requires. Record test-account designation, fixture dependencies and cleanup
results; tear down run-created resources in reverse dependency order. A broad
fixture world is not a prerequisite for the first evidence slice.

**Phases**, classified from `x-readonly`, `x-rollback`, the risk annotations and
an overlay override:

1. **Read-only:** safe at any time.
2. **Account-scoped mutations:** any operation on explicitly designated test
   accounts, including existing accounts and deletion, under standing user
   authorization. Recipes declare expected final state and verify cleanup.
3. **Server-wide settings** (tweak settings, service configuration, packages,
   feature lists): read the current value through the matching getter, change it,
   restore it.
4. **Lockout-class** (root password, token revocation, service restarts or reboot,
   hostname, IP or resolver changes, firewall and cPHulk rules, restores and
   transfers): run last and one at a time behind an explicit `--phase=lockout`
   flag. The runner then stops for the user to restore the snapshot.

Bind account mutations to explicitly designated test accounts; account ownership
by the current run is not an authorization requirement. Keep control credentials
separate from credentials under test; never revoke the control token or block the
controller's IP. Keep server-wide/lockout execution in its dedicated phase.
Live opt-in and exact target checks enforce the selected
recipe; they are not requests for renewed user permission.

**Fingerprints.** Each probe records structural paths and observed
types before conversion, preserving numeric-string, empty-string, null, boolean,
omission and empty-container distinctions. Empty containers never imply an item
schema. Bind evidence to the canonical operation/variant, actual request method,
route (WHM, direct UAPI or bridge), cPanel version, timestamp, code revision and
source/effective-contract hashes. API outcome, payload-validation result and probe
outcome are separate fields. Changed revisions or contracts make evidence
historical until its applicability is explicitly reassessed or the probe reruns.

Values, credentials, request URLs/bodies and arbitrary upstream error messages
are never persisted. Keys can themselves contain private data: dynamic and
unreviewed keys collapse to `*`; only schema-defined or explicitly reviewed static
field names survive. Key-name heuristics alone are insufficient. Sanitization
applies to reports and failure diagnostics as well as saved fingerprints.
Fingerprints are committed under `docs/api/evidence/` and cited by overlay entries.
No live response is copied into a fixture; synthetic fixtures use reserved data.

**Discrepancy loop.** Compare raw observations against the effective
contract's accepted wire representations, including documented normalizations,
so an admitted numeric-string does not produce a false discrepancy against an
integer return type. Report unknown fields, missing required fields and type
differences without silently widening the contract. Reports are deterministic;
an agent reviews corrections and their evidence before committing an overlay
change. Never apply corrections automatically. Re-running reviewed read-only
recipes after a cPanel upgrade supplies version-scoped drift evidence.

The development runner may acknowledge explicitly reviewed structural observations
without resolving their field contracts (issue #18, bead `8x0.2.5`). Such a policy
binds an immutable evidence artifact hash, operation/variant/route/method/version,
source and effective contract hashes, complete masked payload fingerprint and
discrepancy set. Keep observations intact and report acknowledgments separately.
Unexpected or stale observations and unsuccessful probes fail the gate. Masked
paths cannot identify unknown key names or multiplicity, so this policy does not
promise detection of new keys with identical masked types. See the
[live runner policy](../../../tools/live-verify/README.md#reviewed-structural-acknowledgments).

**Unverifiable operations** (missing plugins per `x-cpanel-plugin`, external
services such as Market, Sitejet and 360 Monitoring, operations that need a second
server such as transfers and clusters) are marked with a reason, generated from
documentation alone, and flagged in the coverage summary.

### First evidence slice and next batch

`whm-api-client-8x0.10.1` implements the loop for WHM `version` and `systemloadavg`,
plus UAPI `DomainInfo/list_domains` through direct and WHM-bridged routes. These
four route-specific probes exercise existing code and require no mutations.
Independent offline cases prove raw numeric-string and unknown-field discovery,
response preservation, secret values and dynamic keys remaining private, and
missing/null/empty distinctions. Retain the existing regression oracles.

`whm-api-client-8x0.10.2` then adds UAPI `Email/list_pops` through that loop. The
pinned source declares it read-only, with optional `regex`, `no_validate` and
`skip_main` parameters. The batch includes its overlay, generated method,
independent nonempty/empty/failure fixtures, and probes on both routes. An empty
live result is recorded as such; unobserved record fields remain unverified.
This is a slice of Email, not a claim of complete module coverage.

Finish each batch with reviewed discrepancies or explicit unresolved cases,
scoped coverage and a short effort record in Beads: operations added, corrections,
reusable tooling changes and operation-specific work. Compare this effort across
batches to test whether generation is reducing maintenance work. Broader runners,
fixture orchestration and request-body support are added when a reviewed batch
needs them; JSON/multipart support is still required for eventual full parity.

### Approved post-PR20 correction cycle

The historical delivery sequence is tracked under `whm-api-client-8x0.10`.
Steps 1, 3, 4 and 6 completed in PR21–PR25 on 2026-10-17. Step 2 remains
deferred, and step 5 remains conditional future work; neither blocks the completed
pilot or the owner-authorized full Mysql section. The sequence was:

1. Correct support claims and stale progress (`8x0.1.17`). Current execution
   supports GET and form-encoded POST. The pinned inventory includes 13 JSON and
   9 multipart operations whose body formats remain unsupported.
2. The real HostingPal account/domain inventory workflow (`8x0.10.3`) is deferred
   indefinitely by the user on 2026-10-17, beyond this bounded pilot. On resumption,
   record the actual consumer integration and client revision; a synthetic demo
   does not satisfy this task. No consumer validation is claimed by the deferral.
3. Observe nonempty MySQL records through both routes (`8x0.2.4`), then finite
   Quota limits and cleanup (`8x0.3.1`). These evidence tasks may proceed if
   consumer access is blocked, under the standing test-account authorization.
4. Pilot a bounded reduction in repetitive test/probe plumbing (`8x0.10.4`),
   preserving independently authored contract expectations and measuring manual
   touchpoints and review effort. Add only machinery justified by these tasks.
5. Implement selected JSON (`8x0.1.18`) or multipart (`8x0.1.19`) support when an
   identified useful operation requires it, with explicit route/body contracts.
6. Deliver one coherent expansion pilot (`8x0.4.5`), gated on the MySQL,
   finite-Quota and plumbing tasks. The user's 2026-10-17 deferral and instruction
   to continue other work removes only the consumer prerequisite for this pilot;
   real consumer feedback before full rollout remains outstanding. Select scope
   from concrete library/test-account needs while consumer findings are absent.
   JSON/multipart dependencies are
   conditional on its chosen operations. Record blockers without bypassing them.

Keep one PR at a time. Address Opus's review comments and obtain substantive
external approval of the current head plus passing required checks before
authorized merge. This cycle does not resume unrestricted endpoint expansion.

## Testing and quality gates

1. **Core engine** (hand-written, exhaustive): request lifecycle, parameter
   encoding (omission, booleans, repeated and numbered lists, POST body versus
   query), outcome policies, metadata and exceptions, and every spec rule (scalars,
   nullable, unions, maps, nested lists, normalization, dropped unknown fields).
2. **Generator** (Go): golden-file tests from a fixture map and overlay, overlay
   schema and evidence validation, determinism.
3. **Generated surface (phase 2):** data-driven PHPUnit classes exercise request
   encoding, payload validation/normalization and API failures per operation.
   Generated examples provide breadth; independently authored expectations from
   reviewed documentation and observations remain the correctness oracle. Keep
   existing regression tests until equivalent independent assertions are retained
   in the data-driven suite. Do not derive every expected result from the same
   effective contract used to generate the method and validator.
4. **Evidence boundary (phase 2):** synthetic responses prove capture before
   normalization/filtering, unchanged client-visible responses, safe diagnostics
   and key sanitization, route/revision attribution and deterministic reports.
   These checks stay offline even when credentials are present.
5. **Static:** PHPStan at maximum level and PHPCS over generated code; templates
   must emit compliant code.

CI gates: `gofmt`, `go test`, `go vet`, overlay and evidence validation,
regenerate-and-diff (generated code and coverage summary current), `composer
validate`, `composer check`. No live calls.

## Migration and phasing

0. **Foundation.** Land pull request #1 (form-encoded POST, per-request
   `OutcomePolicy`, `call()`), on which the engine builds. Write the implementation
   plan from this spec.
1. **Engine and generator on the existing 39 operations.** Generate them, migrate
   their tests into fixture rows, and use the existing hand-written tests as the
   equivalence oracle (allowing for the agreed renames and the removal of DTOs).
   Then retire the hand-written groups, the DTOs, the symbol inventory
   (`tools/php-symbols`), the registry, report and baseline artifacts under
   `docs/api/coverage/`, and the parity code that only served them.
   Phase 1 note: the hand-written endpoint tests were adapted to the generated
   groups and serve as the equivalence oracle. The phase-2 runner and fingerprints
   are implemented for the recorded probes; broader fixture-row consolidation
   remains subject to the bounded plumbing pilot (`8x0.10.4`).
2. **First evidence loop and consumer feedback.** Deliver the bounded existing-
   operation slice, then the Email inventory slice described above. In parallel,
   exercise a real HostingPal read-only account/domain inventory workflow with the
   current generated client (`whm-api-client-8x0.10.3`). Identify the consumer
   integration point and record argument, result, error-handling and testing
   friction as focused follow-ups. This does not require a tag, full parity or
   the separate Laravel package.
3. **Expand toward the full surface (1,310).** After the approved correction-cycle
   prerequisites and expansion pilot above, grow read-only recipes, evidence
   and generated coverage together in reviewed batches per UAPI module or WHM
   group. Curate names and resolve contract/request-body gaps as each batch
   requires. Per-group files permit parallel work; the first runner is not a
   prerequisite for unrelated consumer feedback or offline contract decisions.
   Retire per-endpoint live classes only after their independent assertions and
   route coverage are preserved.
4. **Mutation phases** 2 and 3 with declared account final states, fixture cleanup
   and server-wide rollbacks; then the lockout phase with the user, followed by a
   snapshot restore.
5. **Documentation.** Generate a per-group API reference to replace the README's
   per-endpoint tables, and reduce `AGENTS.md` to rules and conventions.

Beads tracks delivery and dependencies; this document records design and
acceptance boundaries. Plans 1-4 cover the implemented foundation. Plan only the
next bounded runner or module slice when starting it, using the previous batch's
evidence and effort. Keep generated, independently tested and live-observed
progress separate throughout expansion.

## Open questions (with proposed defaults)

- **Internal-only metadata:** the pinned sources contain 28
  `x-cpanel-internal-only` occurrences (27 UAPI, 1 WHM), all explicitly `false`.
  Presence alone establishes no internal-only restriction or warning. Reassess
  if a future reviewed snapshot contains true values.
- **Functions present on the server but undocumented** (WHM `applist` reported 675
  functions against 630 documented): out of scope for generation from
  documentation; generic calls are limited to compatible GET/form contracts;
  revisit after phase 3 with
  overlay-authored contracts backed by live evidence.
- **Consumer feedback and releases:** exercise the current client in HostingPal
  before full coverage. Full parity remains the target, but is not a prerequisite
  for a deliberately scoped 0.x prerelease. Publishing/tagging is a separate
  explicit decision, backed by an honest coverage statement and supported
  route/version scope; this design change does not authorize publication.

## Risks

- **PHPStan cost** over roughly 50,000 generated lines: keep one class per group,
  use parallel analysis, and measure in phase 1.
- **Schema quality:** reference cycles, deep recursion and very large payloads
  (776 fields) must be handled by the generator's depth limits.
- **POST acceptance:** form-encoded POST has scoped live observations on all three
  routes with read-only functions. Acceptance by additional operations remains to
  be verified; the latest saved 43-probe run uses GET only.
- **Curation volume:** about 1,310 probe recipes, 174 names and 250–350 corrections
  are agent work in batches; the overlay schema and evidence requirement keep it
  auditable.
- **Live run side effects:** phases, hard guards, fixture ownership and the manual
  snapshot restore contain them.

## Out of scope

The Laravel integration package, hand-written ergonomic facades over the generated
groups, asynchronous or batched execution, and cPanel API 1 and API 2.
