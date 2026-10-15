# Contract-driven generated client for full API parity

Status: design agreed with the user on 2026-10-14; awaiting written-spec review.
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
  snapshot exists. Snapshot restores are performed by the user, never
  automatically.
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
| Deprecated functions | Exclude the 13 flagged functions from generation, with documented reasons; they stay reachable through `call()` |
| Groups | UAPI: one group per module (105). WHM: one group per documented `x-tagGroups` entry (29) |
| Method names | Mechanical camelCase of the documented name; the overlay names every separator-less function and resolves collisions |
| Returns | Plain arrays with generated PHPStan shapes; no DTO classes |
| Generator | Go, inside `tools/api-map` |
| Response validation | One hand-written engine interprets compact generated specs (class constants) |
| Evidence | Sanitized type fingerprints (paths and types, no values) are committed |
| Escape hatch | `whm()->call()` and `uapi()->call()` remain for excluded or not-yet-generated functions |

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
- HTTP method: read-only operations use GET; mutating, unknown-risk and
  secret-bearing operations use form-encoded POST (routing fields stay in the
  query string). The overlay can override with evidence.

**Returns.**

- The method returns the documented payload: an object becomes an array shape, a
  list becomes `list<…>`, a scalar stays scalar, and an operation without a payload
  returns `void`. The overlay may declare `unwrap` to return a single field
  directly (for example WHM `version` → `string`).
- Values whose observed wire type differs from the pinned type are normalized to
  the pinned type when the conversion is lossless (`"80"` → `80`, `"1"` → `1`).
  When no lossless normalization exists, the overlay widens the declared type
  instead (load averages keep `int|float|numeric-string` to preserve precision).
- Unions become PHPStan unions of shapes; maps become `array<string, T>`; untyped
  objects become `array<string, mixed>` with a docblock note until live evidence
  refines them. Recursive schemas are expanded to a depth of 8, below which the
  node is typed `array<array-key, mixed>`.
- Unknown response fields are dropped from the result. Live fingerprints detect
  them, and the overlay then declares them.
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
the date and, for live evidence, the fingerprint it was derived from. An entry
without evidence fails validation. Per-group overlay files also hold the group's
curated accessor name (WHM) and any group-level docblock text.

## Effective contract and generator

The Go tool gains commands to validate the overlay, build the effective contract
(pinned schema with the overlay applied) into `.cache/`, and generate PHP. Output
is deterministic: the same map, overlay and generator produce byte-identical
files. Generated files carry a "generated by tools/api-map; do not edit" header.
The generated PHP is committed so consumers never need Go; CI regenerates it and
fails on any difference.

Coverage becomes a fact of construction: every non-excluded operation in the map
is generated. A generated coverage summary (generated, excluded with reason,
live-verified with version and date, unverifiable with reason) replaces the
symbol inventory, coverage registry, parity report and baseline.

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
   raising `InvalidResponseException` with the response snapshot on mismatch.

**Performance.** Specs are immutable class constants that opcache stores once;
groups load lazily through PSR-4 and are cached per handle; validation is one
linear walk of the payload. HTTP latency dominates by orders of magnitude.

## Live verification

**Runner.** A development-only PHP runner in `tools/live-verify/` drives the
generated client itself, so every probe also exercises the real code path. It
runs only with explicit opt-in and never in CI.

**Probe recipes** live in the overlay, per operation: arguments (which may
reference fixtures such as `{account}`, `{domain}`, `{mailbox}`, `{database}`),
required fixtures, phase, expected outcome (success or a known error such as a
missing plugin), and rollback (from `x-rollback` where documented, otherwise
explicit). Recipes are seeded from `x-codeSamples` and refined in batches.

**Fixtures.** Each run creates a disposable world through the client's own
mutating operations: a reseller and `apitest*` cPanel accounts, subdomains of the
live test domain, mailboxes, forwarders, databases, FTP users, DNS records, cron
jobs and API tokens. It tears them down in reverse dependency order.

**Phases**, classified from `x-readonly`, `x-rollback`, the risk annotations and
an overlay override:

1. **Read-only:** safe at any time.
2. **Account-scoped mutations:** only against the run's fixtures, rolled back.
3. **Server-wide settings** (tweak settings, service configuration, packages,
   feature lists): read the current value through the matching getter, change it,
   restore it.
4. **Lockout-class** (root password, token revocation, service restarts or reboot,
   hostname, IP or resolver changes, firewall and cPHulk rules, restores and
   transfers): run last and one at a time behind an explicit `--phase=lockout`
   flag. The runner then stops for the user to restore the snapshot.

Hard guards in every phase: never touch accounts the run did not create, never
revoke the runner's own token, never block the runner's own IP address.

**Fingerprints.** Each probe records a type fingerprint: JSON paths and observed
types (including numeric-string, empty-string, null and boolean distinctions),
outcome, cPanel version and date. Values are never stored. Map keys collapse to
`*` where the schema declares a map or the keys look like data (domains, email
addresses, IP addresses, usernames). Fingerprints are committed under
`docs/api/evidence/` and cited by overlay entries.

**Discrepancy loop.** A tool command diffs fingerprints against the effective
contract and writes a discrepancy report with suggested overlay entries; an agent
reviews them and commits the overlay changes with their evidence. Re-running the
read-only sweep after a cPanel upgrade produces a drift report.

**Unverifiable operations** (missing plugins per `x-cpanel-plugin`, external
services such as Market, Sitejet and 360 Monitoring, operations that need a second
server such as transfers and clusters) are marked with a reason, generated from
documentation alone, and flagged in the coverage summary.

## Testing and quality gates

1. **Core engine** (hand-written, exhaustive): request lifecycle, parameter
   encoding (omission, booleans, repeated and numbered lists, POST body versus
   query), outcome policies, metadata and exceptions, and every spec rule (scalars,
   nullable, unions, maps, nested lists, normalization, dropped unknown fields).
2. **Generator** (Go): golden-file tests from a fixture map and overlay, overlay
   schema and evidence validation, determinism.
3. **Generated surface:** a few data-driven PHPUnit classes iterate one generated
   fixture row per operation: request encoding from the probe recipe's sample
   arguments; response validation and normalization of the documented example and
   a synthetic payload built from the committed fingerprint; and an API failure
   raising `ApiErrorException`.
4. **Static:** PHPStan at maximum level and PHPCS over generated code; templates
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
2. **Runner, fingerprints and discrepancy loop.** Sweep all read-only operations
   and correct the overlay. Retire the per-endpoint live test classes once the
   runner covers their operations.
3. **Generate the full surface (1,310).** Curate the separator-less names and the
   29 WHM group names; write probe recipes in batches of one bead per UAPI module
   or WHM group. Per-group overlay and generated files let many agent sessions
   work in parallel worktrees without conflicts.
4. **Mutation phases** 2 and 3 with fixtures and rollbacks; then the lockout phase
   with the user, followed by a snapshot restore.
5. **Documentation.** Generate a per-group API reference to replace the README's
   per-endpoint tables, and reduce `AGENTS.md` to rules and conventions.

Each phase has its own beads; phase 3 batches are created per group when the
phase starts. The first implementation plan covers phases 0 and 1 only; later
phases are planned when they start, using what phase 1 measures.

## Open questions (with proposed defaults)

- **Internal-only functions** (`x-cpanel-internal-only`, 28): generate them with a
  docblock warning that cPanel marks them internal-only.
- **Functions present on the server but undocumented** (WHM `applist` reported 675
  functions against 630 documented): out of scope for generation from
  documentation; reachable through `call()`; revisit after phase 3 with
  overlay-authored contracts backed by live evidence.
- **Releases:** no tagged release until phase 3 completes; then 0.x releases.

## Risks

- **PHPStan cost** over roughly 50,000 generated lines: keep one class per group,
  use parallel analysis, and measure in phase 1.
- **Schema quality:** reference cycles, deep recursion and very large payloads
  (776 fields) must be handled by the generator's depth limits.
- **POST acceptance:** form-encoded POST is verified on all three routes with
  read-only functions; the read-only sweep confirms it per operation family.
- **Curation volume:** about 1,310 probe recipes, 174 names and 250–350 corrections
  are agent work in batches; the overlay schema and evidence requirement keep it
  auditable.
- **Live run side effects:** phases, hard guards, fixture ownership and the manual
  snapshot restore contain them.

## Out of scope

The Laravel integration package, hand-written ergonomic facades over the generated
groups, asynchronous or batched execution, and cPanel API 1 and API 2.
