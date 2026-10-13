# API Map Foundation Implementation Plan

> **For agentic workers:** Use the execution skill selected at handoff. Read the
> [overview](2026-10-13-api-map-implementation-plan.md) and approved spec first.
> Numbered steps are instructions; all task state stays in Beads.

**Goal:** Publish the canonical map contract and produce it deterministically
from verified official sources.

**Architecture:** A lossless document loader feeds a cycle-aware OpenAPI index,
then a normalized map builder. The CLI wraps those packages without adding
implicit network operations.

**Tech stack:** Go 1.24+, the two dependencies selected in the overview, and
JSON Schema 2020-12. **Spec:** [approved design](../specs/2026-10-13-api-map-design.md).

## Global constraints

All overview constraints apply: map version `1.0`, original OpenAPI 3.0 trees,
exact case and numeric values, explicit source refresh only, no live API calls,
and no commits or remote synchronization. Preserve current workspace changes.

## Review focus

The tests below cover source tampering and decompression limits; empty paths
and unknown fields; recursive/discriminator references; inherited contracts
with explicit empty security; and stale annotations/alias collisions. Counts
are asserted only for the pinned snapshot, not arbitrary future inputs.

## Task 1 — Publish map schema and shared model

**Beads:** `whm-api-client-gwi.2`.

**Files:** Create `docs/api/schema/api-map-v1.schema.json`,
`docs/api/schema/annotations-v1.schema.json`,
`docs/api/examples/api-map-v1.json`, `tools/api-map/go.mod`, `go.sum`,
`internal/model/{model.go,json.go,model_test.go,testdata/map-valid.json}`, and
`internal/schema/{schema.go,sync.go,schema_test.go,assets/}` under the Go module.
Create `internal/testutil/fixtures.go` with `Read(t testing.TB, path string) []byte`.

**Interfaces:** Define the model types in the overview;
`model.DecodeMap(data []byte) (Map, error)`;
`model.Encode(value any) ([]byte, error)`;
`model.WriteAtomic(path string, data []byte) error`;
`schema.Validate(name string, value any) error`.
`model.Map.Sources` is `[]model.Object`; `Documents` is
`map[string]model.Object`. These types avoid altering optional manifest fields.

1. Write schema/model tests before implementation. A minimal synthetic example
   includes one namespaced WHM function and a UAPI function with GET/POST
   variants. Include required-null, absent-default, and explicit-false values.
   Record the example as synthetic, not observed API behavior.

   ```go
   func TestMapRoundTripPreservesSourceNumbers(t *testing.T) {
       input := testutil.Read(t, "testdata/map-valid.json")
       m, err := model.DecodeMap(input)
       if err != nil { t.Fatal(err) }
       value := m.Documents["synthetic-uapi"]["x-large-integer"]
       if value != json.Number("9007199254740993") { t.Fatalf("rounded: %v", value) }
       encoded, err := model.Encode(m)
       if err != nil { t.Fatal(err) }
       if !bytes.Contains(encoded, []byte("9007199254740993")) { t.Fatal("lost number") }
   }
   ```

   Add `TestMapSchemaRejectsInvalidShape` for unknown normalized keys, invalid
   families/methods/risk values, empty variants, and locators with neither or
   both pointer/prose locator. Raw document keys remain unrestricted. Add
   `TestSchemaLoaderNeverFetchesRemote` and `TestAtomicWritePreservesPrevious`.
2. Run `go -C tools/api-map test ./internal/model ./internal/schema`; initially
   it must fail for missing model/schema behavior. Module/dependency setup is
   part of this task, not a separate deliverable.
3. Implement the interfaces and strict wrapper schemas. Preserve complete raw
   documents without applying JSON Schema 2020-12 rules to their nested OpenAPI
   schemas. Use `json.Number`, deterministic JSON, and no ignored encode errors.
   The annotations-file wrapper has `schema_version:"1.0"`, `annotations`, and
   `aliases`; the CLI passes its two arrays to the builder independently.
   Embed schema copies; make `sync.go` a build-ignored generator with a
   `go:generate` directive in `schema.go`. Resolve schema references only from
   the embedded registry.
4. Run the same tests, `go -C tools/api-map generate ./internal/schema`, and
   `go -C tools/api-map test ./...`. `TestEmbeddedSchemasMatchPublished` must
   compare every published schema byte-for-byte. Record the schema, examples,
   Go rationale, and validation in Beads; close `gwi.2` only when they pass.

## Task 2 — Verify sources and refresh immutable snapshots

**Beads:** `whm-api-client-gwi.3.1`; depends on Task 1.

**Files:** Create `internal/source/{manifest.go,decode.go,load.go,refresh.go,
source_test.go,refresh_test.go,testdata/}` and
`docs/api/schema/source-manifest-v1.schema.json`. Embed the new schema.

**Interfaces:** `source.Bundle{Records []model.Object, Documents map[string]model.Object}`;
`source.Limits`; `source.DefaultLimits() Limits`;
`source.Load(manifestPath string, limits Limits) (Bundle, error)`;
`source.Refresh(ctx context.Context, request RefreshRequest, client *http.Client) (string, error)`.
`RefreshRequest` has the old manifest path, new snapshot directory, limits, and
an injected clock. Its result is the newly published manifest path.

1. Write `TestLoadPinnedSources` and table tests asserting these results:

   ```text
   changed compressed byte / wrong raw hash / wrong size -> error, no bundle
   duplicate JSON or YAML key -> error before map decoding
   9007199254740993 / null / false / empty string -> exact preserved values
   ../outside or a symlink escaping the snapshot -> error
   YAML non-string keys/custom tags/cyclic aliases -> error
   ordinary local reference loading -> zero HTTP requests
   ```

   `TestRefreshIsAtomicAndAllowlisted` must cover failed second downloads,
   existing destination directories, redirects off the allowlist, and corrupt
   OpenAPI input: no partial manifest becomes selected. A counting test transport
   supplies public-document fixtures for permitted URLs; it receives no auth
   headers. Test TLS rejection with an untrusted local TLS server separately.
2. Run `go -C tools/api-map test ./internal/source -run 'TestLoad|TestRefresh'`;
   verify failure before implementing these behaviors.
3. Implement loading and refresh with production HTTPS hosts restricted to
   `api.docs.cpanel.net` and `docs.cpanel.net`. Check each redirect before sending
   it; permit at most three redirects and a 30-second overall request deadline.
   Limits are 32 MiB compressed/input bytes per file, 64 MiB decompressed bytes,
   256 MiB per source set, depth 128, and 10,000 expanded YAML alias nodes.
   Check bounded reads before allocation. Tests can supply smaller limits.
   Preserve absent/null values; reject trailing JSON and multiple YAML documents.
4. Recompute retrieval time, raw/stored hashes, sizes, product/spec versions,
   and path/operation counts for refreshed inputs. Count only keys beginning
   with `/` as paths; retain Paths Object extensions without counting them.
   A methodless path survives in the source tree but creates no logical API
   operation. Preserve original sources;
   stage downloads separately and publish only after every input validates.
   Local/external reference targets are resolved using the manifest's file and
   URL identities, never by opportunistic downloads. Explicit refresh may pin
   allowlisted external references; ordinary loading must reject missing ones.
5. Run the source tests and `go -C tools/api-map test ./...`. The existing 23
   cached inputs must verify without network access. No real refresh is required
   to prove the implementation; the immutable 2026-10-13 cache remains unchanged.

## Task 3 — Index references and effective contracts

**Beads:** `whm-api-client-gwi.3.2`; depends on Task 2.

**Files:** Create `internal/openapi/{index.go,resolve.go,operations.go,
inheritance.go,diagnostics.go,index_test.go,testdata/}`.

**Interfaces:** `openapi.NewIndex(documents map[string]model.Object) (*Index, error)`;
`(*Index).Resolve(sourceID, ref string) (Resolved, error)` where `Resolved`
contains `Value any` and `Origin model.Locator`;
`openapi.Analyze(bundle source.Bundle) (Analysis, error)` where `Analysis`
contains exact path/method candidates, effective declarations, and diagnostics.
Candidate fields are source ID, full path, lowercase method, operation/path-item
locators, effective parameters/security/servers, and original operation object.

1. Create small source-derived fixtures with extraction provenance. Pin the
   following assertions in `TestAnalyzeDocumentContracts`:

   ```text
   UAPI ServiceAccount self-ref -> resolves; one cycle; zero unresolved refs
   UAPI Payload discriminator -> 3 mapping targets, including allOf/oneOf closure
   missing discriminator target -> error, not merely a warning
   UAPI Batch/strict command -> query content schema; no invented request body
   WHM enqueue_transfer_item GET -> form body retained + GET_BODY diagnostic
   empty path item + Paths Object extension -> remain in original document
   x-frame-options schema property -> data, not a vendor-extension declaration
   ```

   Add synthetic `TestInheritance` fixtures: operation `(name,in)` overrides
   the path declaration; same-name query/header parameters remain two entries;
   same-scope duplicates fail; `security:[]` overrides inherited authentication;
   operation/path/root server precedence is preserved. Path-item `$ref` cycles
   fail; legitimate schema cycles remain symbolic. Reject unsupported OpenAPI
   versions and malformed required structures without dropping operations.
2. Run `go -C tools/api-map test ./internal/openapi`; confirm failing cases.
3. Implement reference traversal with visited/active sets and bounded depth.
   Resolve JSON Pointer escapes and relative source URLs against pinned source
   identity. Validate discriminator targets independently of `$ref` traversal.
   Preserve unknown source fields; distinguish warnings (missing metadata,
   GET bodies) from errors (unresolved references, duplicate declarations).
   Missing upstream operation IDs yield diagnostics; canonical IDs are path-based.
4. Run the package and full Go tests. On the full pinned specs, verify 278 WHM
   and 81 UAPI `$ref` occurrences, zero unresolved/external targets, and the
   legitimate UAPI schema cycle. No eager recursive expansion is permitted.

## Task 4 — Build the canonical map and initial CLI

**Beads:** `whm-api-client-gwi.3.3`; depends on Task 3.

**Files:** Create `internal/mapbuild/{build.go,annotations.go,aliases.go,risk.go,
build_test.go,testdata/}`, `internal/cli/{run.go,sources.go,map.go,run_test.go}`,
`cmd/api-map/main.go`, `docs/api/annotations.json`, and `docs/api/map.sha256`.
Update `docs/api/README.md` and both agent guides with usable commands.

**Interfaces:** `mapbuild.Build(bundle source.Bundle, annotations []model.Annotation,
aliases []model.Alias) (model.Map, error)` and the overview's `cli.Run`.
Command forms are `sources verify --manifest PATH`,
`sources refresh --manifest PATH --output-dir NEW_DIR`, and
`map build --manifest PATH --annotations PATH --output PATH`.

1. Write `TestBuildCanonicalIdentity`, `TestRiskEvidence`,
   `TestAnnotationsAndAliases`, and `TestBuildIsDeterministic` with these exact
   fixture expectations:

   ```text
   WHM apply_dmarc + UAPI EmailAuth/apply_dmarc -> two distinct IDs
   WHM EcosystemFeatures/is_ready -> retains namespace despite short operationId
   UAPI WebApp/stage -> one operation, two variants, POST body and query retained
   SiteQuality/get_all_scores -> required + nullable + null default; risk unknown
   CCS/add_delegate -> integer/string RPM/version extensions retained
   x-readonly true / false / absent -> read-only / mutating / unknown
   conflicting risk evidence -> unknown + diagnostic
   stale annotation hash / alias cycle / ambiguous alias target -> build error
   reordered input object keys -> equal output; reordered semantic arrays preserved
   ```

   CLI tests assert exit codes 0/1/2, clean JSON stdout, safe stderr diagnostics,
   and previous-output preservation when a build fails.
2. Run `go -C tools/api-map test ./internal/mapbuild ./internal/cli`; verify
   failures before the builder/commands exist.
3. Build exact family/path IDs, sort normalized arrays, and retain complete
   parsed documents including methodless paths. Add only reviewed family token
   annotations backed by the pinned token-guide hashes and locators; retain
   BasicAuth source declarations. Preserve unresolved audit discrepancies as
   attributed diagnostics, not undocumented transport variants.
4. Run all Go tests, build twice into separate cache files using the command
   below, and compare bytes. Expect 1,323 logical IDs and 1,325 variants, with
   29 cross-family upstream-ID collisions reported without canonical collisions.
   Validate the map against its schema, save the checksum, and close `gwi.3`
   only after its three children meet acceptance.

   ```bash
   go -C tools/api-map run ./cmd/api-map map build --manifest ../../docs/api/sources/2026-10-13/manifest.json --annotations ../../docs/api/annotations.json --output ../../.cache/api-map/map.json
   ```
