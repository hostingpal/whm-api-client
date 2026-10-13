# API Drift and CI Regression Implementation Plan

> **For agentic workers:** Use the execution skill selected at handoff. Read the
> [overview](2026-10-13-api-map-implementation-plan.md), dependent component
> plans, and approved spec first. Track status in Beads only.

**Goal:** Make upstream documentation changes visible and prevent established
implementation/test coverage from silently regressing.

**Architecture:** A pure drift comparator reuses reference-aware contract units.
A separate baseline checker protects exact mappings and claims. CI runs these
against pinned artifacts, independently of optional server profiles.

**Tech stack:** Go test/vet, the existing Composer checks, and GitHub Actions.
**Spec:** [approved design](../specs/2026-10-13-api-map-design.md).

## Global constraints

All overview constraints apply. Upstream, implementation, and runtime differences
remain separate reports. Baselines are explicit reviewed artifacts, never
automatically ratcheted by CI. Initial zero coverage passes. No credentials,
live servers, commits, pushes, or Dolt remote synchronization are required.
This plan depends on `gwi.4` and `gwi.5`.

## Review focus

Test referenced-schema edits with stable pointers; documentation-only edits;
unconfirmed renames; denominator changes that conceal lost implementations;
and failure paths that overwrite or silently update checked baselines.

## Task 9 — Report source-contract drift

**Beads:** `whm-api-client-gwi.6.1`.

**Files:** Create `internal/drift/{diff.go,render.go,diff_test.go,testdata/}`,
`internal/cli/drift.go`, published/embedded `drift-report-v1.schema.json`, and
`docs/api/examples/drift/{unchanged.json,changed.json,changed.md}`.

**Interfaces:** `drift.Compare(before, after model.Map) (Report, error)` and
`drift.Markdown(report Report) []byte`. `Report` contains source versions/hashes,
added/removed IDs and variants, confirmed alias relationships, and categorized
changes (`parameter`, `body`, `response`, `auth`, `availability`, `deprecation`,
`risk`, `documentation`, `uninterpreted`). Each change identifies before/after
locators and normalized hashes or explicit absent values.

1. Write `TestDriftCategories` with one edit per fixture:

   ```text
   identical map -> no changes
   description/example only -> documentation change, no contract change
   source timestamp only -> provenance change, no invented operation change
   referenced schema enum changes behind same $ref -> contract change
   parameter default null becomes absent -> parameter change
   same-name schema property called description changes enum -> contract change
   inherited security becomes explicit [] -> auth change
   GET gains POST variant -> variant addition, no extra logical operation
   risk/availability/rollback/deprecation changes -> corresponding metadata change
   old path removed + similar new path -> removed/added, not inferred rename
   explicit validated alias -> confirmed rename relationship, original delta retained
   unknown retained OpenAPI field changes -> uninterpreted change, not silently ignored
   ```

   A synthetic cyclic schema edit must terminate and produce one stable change
   set. Invalid maps or alias cycles must fail before comparison.
2. Run `go -C tools/api-map test ./internal/drift -run TestDrift`; verify the
   expected failing tests before implementing the comparator.
3. Reuse `contract.Units`/`Hash` from the parity plan; do not invent a second
   incompatible contract-hashing algorithm. Compare noncontract metadata and
   presentation separately, traversing schema/data positions correctly. Source
   hashes identify changed inputs but do not by themselves prove every contract
   changed. Missing versus explicit null remains visible.
4. Add `drift report --before PATH --after PATH --output PATH --markdown PATH`.
   A detected upstream change exits 0 with a nonempty report; malformed inputs
   exit 1. Upstream additions are findings, not automatically PHP regressions.
   Generate and validate unchanged/changed synthetic reports; run all Go tests.

## Task 10 — Enforce baselines and run offline CI

**Beads:** `whm-api-client-gwi.6.2`; depends on Task 9.

**Files:** Create `internal/parity/{baseline.go,baseline_test.go}`,
`internal/cli/{check.go,check_test.go}`, `docs/api/coverage/baseline.json`,
published/embedded `coverage-baseline-v1.schema.json`,
`docs/api/toolchain.json`, `.github/workflows/api-map.yml`, and
`tools/api-map/README.md`. Update root `README.md`, `docs/api/README.md`,
`AGENTS.md`, and `CLAUDE.md`. Add a separate Composer `api-map:check` script;
the existing PHP-only `check` script remains intact.

**Interfaces:** `parity.CheckBaseline(current Report, baseline Baseline) []Violation`;
`cli.Check(ctx context.Context, root string, stdout, stderr io.Writer) int`.
`Baseline` contains schema version and protected operation/variant IDs, PHP
symbols, contract IDs/hashes, and test-symbol mappings. Live evidence is optional
report data and cannot make an offline baseline pass or fail.

1. Write `TestBaselineProtectsMappings`, including these assertions:

   ```text
   current zero report + empty baseline -> zero violations
   protected A removed while unrelated B added -> regression
   protected A mapped to nonexistent ID -> invalid mapping + regression
   A loses one variant, contract claim, or test mapping -> regression
   denominator grows because upstream adds C -> no implementation regression
   unsupported/removed documented A still in registry -> orphan requiring review
   changed source contract hash -> stale claim requiring review, not silent pass
   absent live server evidence -> no offline regression
   ```

   `TestCheckIsReadOnlyAndOffline` uses a temporary miniature repository with
   stubbed trusted PHP exporter/test artifacts. Block all HTTP requests; assert
   no network attempt, unchanged baseline bytes, and unchanged checked reports
   after both passing and failing checks. Exit 3 identifies baseline regressions;
   invalid source/schema/registry input exits 1.
2. Run `go -C tools/api-map test ./internal/parity ./internal/cli`; confirm
   these new assertions fail before implementing baseline/check behavior.
3. Implement exact-set protection. A higher percentage or unchanged count must
   never conceal lost IDs/claims. `check --root PATH` reads `docs/api/toolchain.json`
   for manifest, annotations, registry, symbols, baseline, schema, and expected
   report/checksum paths, resolving them relative to that explicit repository root.
   The configuration contains no tokens or live hosts and permits no executable
   shell snippets. Use fixed argv for the trusted PHP exporter.
4. In a temporary output directory, verify sources and embedded schema equality,
   build the map twice, compare bytes and the committed checksum, export fresh
   symbols, validate registries/evidence, regenerate parity reports, and compare
   them to checked artifacts. Then enforce the baseline. Validate the saved
   synthetic capability/drift examples, but do not capture or compare a real
   server automatically. Propagate cancellation and clean temporary outputs.
5. Create the empty baseline and initial configuration. Document explicit
   artifact regeneration commands and manual baseline-review requirements.
   There is no automatic baseline-update switch in `check`. Set Composer
   `api-map:check` to `go -C tools/api-map run ./cmd/api-map check --root ../..`.
6. Add GitHub Actions jobs with PHP 8.3 and Go 1.24. Install pinned Composer/Go
   dependencies, verify formatting (`gofmt -l` must return no files), run Go
   test/vet, `composer validate`, `composer check`, and `composer api-map:check`.
   Pin third-party actions to reviewed commit SHAs at implementation time.
   Disable persistence of checkout credentials; provide no WHM/cPanel secrets.
   Test commands use pinned local sources; package installation may use normal
   package-network access. CI does not publish artifacts, commit, or push.
7. Run the overview's final commands. Demonstrate a passing unchanged baseline
   and failing removed/mis-mapped protected fixtures without modifying the real
   baseline. Confirm documentation commands, schemas, deterministic outputs,
   PHP formatting/static analysis/tests, and dependency boundaries. Close the
   remaining Beads and epic only after every acceptance criterion is met;
   report live verification as not run and retain uncommitted work for review.
