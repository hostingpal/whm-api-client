# PHP and Test Parity Implementation Plan

> **For agentic workers:** Use the execution skill selected at handoff. Read the
> [overview](2026-10-13-api-map-implementation-plan.md), foundation interfaces,
> and approved spec first. Track work only in Beads.

**Goal:** Report PHP endpoint and contract-test coverage without crediting
generic transport methods, missing symbols, or unverified claims.

**Architecture:** A PHP development tool inventories trusted project symbols.
A Go registry validator and comparator bind those symbols to the canonical map
and produce independent function, variant, contract, and evidence counts.

**Tech stack:** PHP 8.3+ tokens/reflection, PHPUnit, and the foundation Go module.
**Spec:** [approved design](../specs/2026-10-13-api-map-design.md).

## Global constraints

All overview constraints apply. PHP runtime `src/` remains framework-independent
and unchanged by this tool. Sources/test classes may be autoloaded for reflection
but constructors, providers, test methods, attributes, and endpoint methods are
never executed during inventory. Coverage is explicit; current endpoint coverage
is zero. This plan depends on `gwi.3`.

## Review focus

Test inherited assertions/providers versus real tests; anonymous or unautoloadable
classes; existing method names mapped to nonexistent operations; referenced-schema
changes behind stable `$ref` strings; and declaration/passing-test/live-evidence
distinctions. Treat PHP symbol existence as a structural check, not proof that a
test asserts the correct contract.

## Task 5 — Inventory PHP symbols and validate registries

**Beads:** `whm-api-client-gwi.4.1`.

**Files:** Create `tools/php-symbols/{export.php,SymbolInventory.php}`,
`tests/Tooling/SymbolInventoryTest.php`, `tests/Fixtures/SymbolInventory/`,
`internal/parity/{registry.go,symbols.go,registry_test.go,testdata/}` under the Go
module, `docs/api/coverage/{registry.json,symbols.json}`, and published/embedded
`coverage-registry-v1.schema.json` and `php-symbols-v1.schema.json`.
Extend `phpcs.xml.dist` and `phpstan.neon.dist` to check `tools/php-symbols/`.

**Interfaces:** PHP `SymbolInventory::scan(string $root): array` emits
`schema_version`, `symbols`, and `diagnostics`. The CLI is
`php tools/php-symbols/export.php --root PATH --output PATH`.
Go provides `parity.LoadRegistry(data []byte) (Registry, error)`,
`parity.LoadSymbols(data []byte) (Symbols, error)`, and
`parity.ValidateRegistry(m model.Map, r Registry, s Symbols) []model.Diagnostic`.

1. Write PHP tests before the exporter. Exact current-repository expectations:

   ```text
   HttpTransport -> public __construct and send, both infrastructure
   HttpTransportTest -> 8 declared tests, 2 public static data providers
   inherited PHPUnit methods -> excluded from project-declared symbols
   anonymous exception class -> excluded from named package declarations
   constructors/providers/attribute constructors -> invocation count stays zero
   named declaration with failed autoload -> diagnostic, never silently omitted
   ```

   Test PSR-4 paths, bracketed namespaces, multiple named declarations, and
   `Foo::class` tokens that must not be mistaken for class declarations.
   A synthetic registry test binds a real public symbol to an unknown canonical
   operation: validation must fail. Another binds a provider to a test slot:
   it must fail despite the public method existing.
2. Run `vendor/bin/phpunit tests/Tooling/SymbolInventoryTest.php` and
   `go -C tools/api-map test ./internal/parity -run 'TestRegistry|TestSymbols'`;
   confirm failure before implementation.
3. Discover named project declarations with `token_get_all(..., TOKEN_PARSE)`
   under the configured `src/` and `tests/` roots. Load trusted classes through
   Composer to verify methods with reflection; never rely solely on Composer's
   classmap or `get_declared_classes()`. Check declaring file/root and retain
   autoload errors as diagnostics. Do not call `ReflectionAttribute::newInstance`
   or evaluate arbitrary default expressions. Test discovery uses declared
   public `test*` methods or `#[Test]`; provider references come from declaration
   metadata without invocation.
4. Symbol records contain fully qualified `Class::method` IDs (no leading
   backslash), kind (`method`, `test`, `provider`, `constructor`), declaring class,
   relative file, declaration line, public/static/abstract flags, and provider
   references. Sort IDs, remove timestamps/absolute paths, and use strict types
   and maximum PHPStan compatibility for exporter code.
5. Registry entries contain canonical operation/variant IDs, public PHP symbols,
   `implemented`/`partial` status, contract-unit claims (`id`, `hash`, test IDs),
   and separately linked live evidence IDs. Infrastructure symbols and excluded
   operations have explicit reasons. Intentional multiple-method mappings need
   a shared mapping-group ID and rationale; otherwise duplicates are errors.
   Start with no endpoint entries and explicit transport/provider infrastructure.
6. Run PHP/Go tests, PHPCS/PHPStan, and export `docs/api/coverage/symbols.json`.
   Confirm repeated exports are identical and the initial registry validates
   without reporting the transport as an implemented operation.

## Task 6 — Count contracts and emit coverage reports

**Beads:** `whm-api-client-gwi.4.2`; depends on Task 5.

**Files:** Create `internal/contract/{units.go,hash.go,units_test.go,testdata/}`,
`internal/parity/{report.go,render.go,evidence.go,report_test.go}`, and
`internal/cli/parity.go`. Publish/embed `parity-report-v1.schema.json` and
`test-evidence-v1.schema.json`. Generate `docs/api/coverage/{report.json,report.md}`.

**Interfaces:** `contract.Units(m model.Map, v model.Variant) ([]Unit, error)`;
`contract.Hash(m model.Map, origin model.Locator) (string, error)`;
`parity.Compare(m model.Map, r Registry, s Symbols, e Evidence) (Report, error)`;
`parity.Markdown(report Report) []byte`.
`Unit` contains ID, kind, source locator, and SHA-256 contract hash.
`Evidence` contains optional test-run outcomes and optional live records;
absence means not-run/unverified, never passed.

1. Add table tests with exact expected facts:

   ```text
   empty registry against pinned map -> implemented 0/1323, variants 0/1325
   WebApp/stage GET-only mapping -> partial function; 1/2 variants declared
   one same-name query/header pair -> two parameter units
   one body with two media types -> two body units
   response 200 JSON + response 400 JSON -> two response units
   source-derived wildcard/indexed family -> one declaration, no invented expansion
   missing PHP symbol / unknown ID / accidental duplicate -> invalid report diagnostic
   registered existing test with no run record -> declared, not passed
   direct UAPI record + bridge record -> separate route/auth evidence counters
   changed referenced schema behind unchanged $ref -> changed hash, stale claim
   description/example-only edit -> unchanged contract hash
   ```

   Add `TestAliasResolutionPreservesDenominator` and
   `TestRegistryClaimsCannotInflateCoverage`: aliases must resolve explicitly,
   duplicate claims count once, excluded IDs show gross/excluded/net counts,
   and an incomplete variant's function cannot become complete.
2. Run `go -C tools/api-map test ./internal/contract ./internal/parity`; verify
   the new behaviors fail first.
3. Contract IDs are JSON-encoded tuples to avoid delimiter collisions:
   `[variant,"parameter",name,in]`, `[variant,"body",mediaType]`, and
   `[variant,"response",status,mediaType]`. A response with no documented schema
   produces a missing-schema diagnostic, not a claimable response-contract
   unit; expose that gap separately from the response-schema denominator.
   Hash normalized contract content plus its reachable reference/discriminator
   graph, with cycle-safe locators. Omit presentation-only keys `description`,
   `summary`, `example`, `examples`, `externalDocs`, and `x-codeSamples` only
   at OpenAPI metadata positions; same-named schema properties remain data.
   Do not include whole-source hashes in contract hashes, so unrelated prose
   edits do not invalidate every unit. Evidence still records provenance hashes.
4. Report implemented/missing/partial/excluded/orphaned/duplicate/unknown states
   by family and module/tag. WHM tags may overlap: label tag subtotals as
   non-additive; global counts always deduplicate IDs. Report function/variant,
   parameter/body/response contracts, declared tests, passing test outcomes, and
   live evidence independently with explicit numerators and denominators.
   Test-run records carry run ID, revision, completion time, command, and
   per-symbol passed/failed/skipped outcomes; mismatched revisions are stale.
   Do not infer test outcomes from symbol discovery or method names.
5. Add `parity report --map PATH --registry PATH --symbols PATH --output PATH
   --markdown PATH [--test-evidence PATH] [--live-evidence PATH]`. Invalid mappings
   produce diagnostics and exit 1 rather than a misleading successful report.
   Regenerate the initial JSON/Markdown reports from the pinned map, with no
   live/test evidence supplied. Repeat generation and compare bytes.
6. Run all Go tests, `composer check`, and schema validation. Confirm zero
   endpoint coverage despite passing HTTP transport tests. Close `gwi.4` only
   after both children pass; symbol existence alone does not close contract work.
