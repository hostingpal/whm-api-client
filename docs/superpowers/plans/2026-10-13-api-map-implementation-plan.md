# API Map Toolchain Implementation Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or
> `superpowers:executing-plans` after the user reviews this plan and selects an
> execution method. Read this overview, the relevant component plan, and the
> approved spec before implementation. Numbered steps are instructions; Beads
> owns task status. Do not add Markdown progress checkboxes.

**Goal:** Deliver a reproducible Go CLI that inventories official WHM/UAPI
documentation and reports PHP coverage, server capabilities, and upstream drift
as separate dimensions.

**Architecture:** Preserve complete parsed source trees and build a normalized,
source-backed operation index. Pure comparison packages consume that index;
only explicit source refresh and opt-in capability capture perform networking.

**Tech stack:** Go 1.24+, `encoding/json` with `json.Number`,
`go.yaml.in/yaml/v3`, `github.com/santhosh-tekuri/jsonschema/v6`, PHP 8.3+
reflection/token APIs, and the repository's existing PHPUnit/Composer tools.

**Spec:** [Approved design](../specs/2026-10-13-api-map-design.md).
Evidence: [source audit](../../api/source-audit.md),
[inventory](../../api/source-inventory.json), and
[snapshot manifest](../../api/sources/2026-10-13/manifest.json).

## Deliverables and order

| Plan | Beads | Independently reviewable result |
| --- | --- | --- |
| [Foundation](2026-10-13-api-map-foundation.md) | `gwi.2`, `gwi.3` and children | Published map schema, verified sources, reference index, deterministic map and CLI |
| [PHP parity](2026-10-13-api-map-parity.md) | `gwi.4` and children | Validated PHP/test mappings and honest coverage reports |
| [Capabilities](2026-10-13-api-map-capabilities.md) | `gwi.5` and children | Opt-in fixed-call collector and offline profile comparison |
| [Drift and CI](2026-10-13-api-map-regression.md) | `gwi.6` and children | Contract drift reports and a coverage baseline enforced offline |

Finish the foundation first. Parity and capability work can then proceed in
parallel in their own packages. Integrate drift and CI after both reports have
stable contracts. The source audit (`gwi.1`) is already complete. Design approval
does not close `gwi.2`: its published schema and examples remain to be built.

## Global constraints

- The standalone Go module is `github.com/hostingpal/whm-api-client/tools/api-map`.
  Use `go 1.24.0`; pin compatible dependency versions in its `go.mod`/`go.sum`.
  Go is not a Composer runtime dependency.
- Preserve exact case-sensitive family/path identities: `whm1:<full-path>` and
  `uapi:<Module/function>`, without the leading slash. Variants append `#GET`,
  `#POST`, or the other documented uppercase method. Source pointers retain
  lowercase method keys. Do not infer aliases or extra transports.
- Map interchange version is `1.0`, with JSON Schema 2020-12 for the wrapper.
  Retained schemas remain OpenAPI 3.0 objects. Missing, false, null, and empty
  values remain distinct; JSON numbers must not round through float64.
- `x-readonly` absence stays `unknown`. GET is not a safety classifier. No map
  or report executes endpoints. Capability capture permits only WHM `version`
  and `applist`; tests use local fixtures and TLS test servers.
- Current input expectations are 630 WHM operations, 693 UAPI logical paths,
  and 695 UAPI variants. They are snapshot assertions, never parser constants.
- Real credentials and profiles remain outside committed fixtures. Never read
  or source a shell startup file. No live capture is part of implementation
  validation; an explicit later invocation is required.
- Preserve existing uncommitted scaffold work. The checked-in HEAD does not
  contain all current project files; a new worktree must not lose those inputs.
  Inspect workspace isolation at execution time before choosing where to edit.
- No commits, pushes, or Dolt remote sync are authorized. Update Beads as work
  advances; use reviewable diffs as the handoff between tasks.
- Update both `AGENTS.md` and `CLAUDE.md` when development commands change.
  Existing `composer check` remains offline and continues to check the PHP core.

## Shared files and contracts

Put Go implementation and tests together by responsibility under
`tools/api-map/internal/{model,schema,source,openapi,mapbuild,contract,parity,capability,drift,cli}`.
The binary entry point is `tools/api-map/cmd/api-map/main.go`. Tests and synthetic
inputs belong to the owning package's `testdata/`; source-derived fixtures
also record original source hash, JSON pointer, and any projection performed.

Publish machine contracts under `docs/api/schema/`. Embed generated copies in
`tools/api-map/internal/schema/assets/` so the executable needs no schema
downloads. `go generate ./internal/schema` synchronizes those copies from the
published files; a test compares their bytes. The schema loader registers only
embedded resources and rejects missing/remote schemas.

The foundation defines these common types, with explicit snake_case JSON tags:

| Type | Fields and meaning |
| --- | --- |
| `model.Object` | `map[string]any`; numbers are `json.Number` |
| `model.Locator` | `source_id`, `source_sha256`, and exactly one of `pointer` or `prose_locator` |
| `model.Diagnostic` | `severity` (`info`, `warning`, `error`), `code`, safe `message`, source locator, optional canonical ID |
| `model.Map` | `schema_version`, `sources`, `documents` keyed by source ID, `operations`, `aliases`, `annotations`, `diagnostics` |
| `model.Operation` | `id`, `family` (`whm1`/`uapi`), nullable `module`, `function`, `path`, provenance, nonempty `variants` |
| `model.Variant` | `id`, uppercase `method`, nullable `upstream_operation_id`, operation/path-item locators, `parameters`, `security`, `servers`, `risk` |
| `model.Parameter` | exact `name`/`in`, resolved `definition`, declaration locator |
| `model.Risk` | `classification`, `destructive_potential` (`yes`, `no`, `unknown`), evidence locators |

`sources` preserves all manifest records; only parsed OpenAPI documents enter
`documents`. Security and servers are the effective OpenAPI values, with source
locators and inheritance level. Risk true maps to read-only/no; false maps to
mutating/unknown; missing or conflicting evidence maps to unknown/unknown.
An evidenced destructive annotation maps to destructive/yes.

Aliases have `alias_id`, `target_id`, `reason`, and nonempty evidence. Annotations
have an ID, scope (family/operation/variant), kind, conclusion, rationale, and
nonempty evidence. At least one evidence locator binds every annotation to the
exact source hash. No arbitrary executable expressions are permitted.

`model.Encode(value any) ([]byte, error)` writes deterministic UTF-8 JSON with
two-space indentation and one trailing newline. Sort normalized record arrays;
preserve source-array ordering. `model.WriteAtomic(path string, data []byte) error`
writes via a sibling temporary file and renames only after successful validation.
It creates missing output directories and never destroys the previous output
on a failed build. Generated map JSON
lives at `.cache/api-map/map.json`; commit its checksum and reports, not a second
large copy of all source trees.

`cli.Run(ctx context.Context, args []string, stdout, stderr io.Writer) int` is
the testable command entry point; `main` only supplies streams/context and exits.
Use exit 0 for success, 1 for invalid input/contract failures, 2 for usage, and
3 for a baseline regression. Diagnostics never corrupt JSON stdout. All commands
accept explicit paths; none secretly refreshes sources or records live data.

## Review focus

Five failure classes receive explicit tests in the owning plans:

1. A changed referenced schema must invalidate a contract claim even when its
   parameter's `$ref` string is unchanged — parity and drift tests.
2. A valid empty path item or Paths Object extension must survive lossless
   ingestion — foundation reference/index tests.
3. A public provider or inherited PHPUnit assertion must not become a contract
   test — PHP inventory tests.
4. Failed/unauthorized inventory must not become an empty successful capability
   list, and diagnostics must not echo credentials — capability collector tests.
5. Adding unrelated coverage must not conceal removal of a protected mapping;
   stale source annotations must not survive refresh silently — baseline and
   foundation build tests respectively.

## Commands and final evidence

Run commands from the repository root. Keep build caches in the ignored
workspace directory when the execution environment restricts home writes:

```bash
export GOCACHE="$PWD/.cache/go-build"
export GOMODCACHE="$PWD/.cache/go-mod"
go -C tools/api-map test ./...
go -C tools/api-map vet ./...
go -C tools/api-map run ./cmd/api-map check --root ../..
composer validate
composer check
git diff --check
git status --short
```

These commands are the planned acceptance commands; they cannot run until the
tool exists. Dependency installation may need network access, while the actual
checks use only local inputs. `check` resolves all paths relative to its explicit
root, builds the map twice, validates schemas/registries/evidence, produces
reports in a temporary directory, compares checked artifact hashes, and enforces
the baseline. It must not modify checked artifacts or update the baseline.

Final evidence includes passing Go/PHP checks, identical map bytes from two
builds, the zero-coverage report, synthetic capability comparison, an unchanged
baseline passing, and removed/mis-mapped implementation fixtures failing. Keep
real-server verification separate. Close only Beads whose acceptance criteria
are fully met.

## Execution handoff

The recommended method is subagent-driven execution: the ten implementation
tasks have distinct reviewable boundaries, and mistakes in identity, references,
or coverage accounting can make plausible-looking reports wrong. A reviewer at
each boundary provides useful independent checks. Native execution is also
available if the user prefers one implementer and a final independent review.

The user has approved the design and selected Go. Review of these implementation
plans and selection of the execution method are the remaining workflow steps
before code changes.
