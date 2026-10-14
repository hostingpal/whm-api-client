# Generated Client, Plan 3: Retire Parity Machinery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the symbol inventory, coverage registry, parity report and baseline with "coverage by construction": `composer api-map:check` verifies the map and that committed generated code matches the overlay.

**Architecture:** `api-map check` keeps its read-only, offline guarantees (schema copies, reproducible map, checksum, synthetic examples) and swaps the parity section for the generator: it loads `docs/api/overlay`, builds the effective contract from the freshly built map, renders it and fails when any generated file differs or a stale generated file remains. The `internal/parity` package, the `parity report` command, the PHP symbol exporter and every coverage artifact are deleted. This must land before the cutover (plan 4), which would otherwise need registry entries for every renamed method.

**Tech Stack:** Go 1.24 (`tools/api-map`), PHP 8.3 tooling configuration (PHPCS, PHPStan), Composer scripts.

**Spec:** `docs/superpowers/specs/2026-10-14-generated-client-design.md` (sections "Effective contract and generator" — coverage becomes a fact of construction — and "Migration and phasing", phase 1 retirements).

**Prerequisite:** Plans 1 and 2 are merged into `master`. Work on a branch `chore/retire-parity-machinery` from up-to-date `master`.

## Global Constraints

- `api-map check` stays read-only and offline: it never writes into the repository, never fetches sources, and cleans its temporary directory on success, failure and cancellation.
- Exit codes: 0 success, 1 invalid input/configuration, overlay problems or stale generated output, 2 usage. Exit code 3 (baseline regression) disappears with the baseline.
- Configuration paths stay confined to the repository (`confinedCheckPath`); unknown configuration keys stay rejected by the schema.
- Go code is `gofmt`-clean and passes `go vet`; CI runs Go 1.24 with `GOPROXY=off GOSUMDB=off`.
- `composer check` stays PHP-only and offline; CI keeps receiving no WHM/cPanel secrets.
- Agent guidance lives in `AGENTS.md` only (`CLAUDE.md` imports it).

## Review Focus

- **Stale generated file left behind.** A previously generated file that the overlay no longer produces must fail `check`. Pinned in Task 2 (`TestCheckRejectsStaleGeneratedCode`, second root).
- **Overlay problems during check.** An overlay naming an operation that is not in the map must fail `check` with the overlay message, not pass silently. Pinned in Task 2 (`TestCheckRejectsOverlayProblems`).
- **Removed configuration keys.** A `toolchain.json` that still names `registry` (or any removed key) must be rejected, so a stale config cannot silently skip checks. Pinned in Task 2 (`TestCheckRejectsConfigurationEscapesAndRemovedKeys`, row `removed registry key`).
- **Check must not write.** After a successful check, the repository tree is byte-identical. Pinned in Task 2 (`TestCheckIsReadOnlyAndOffline`).
- **Generator still reachable from tests after `parity_test.go` is deleted.** The shared `pinnedParityMap` helper must survive for `generate_test.go`. Pinned in Task 1 (helper moved before deletion; `go test ./...` compiles).

---

## File Structure

| File | Change |
| --- | --- |
| `tools/api-map/internal/cli/generate.go` | Extract `contractFromMap` and `outdatedFiles`, shared with `check`. |
| `tools/api-map/internal/cli/check.go` | Replace symbols/registry/parity/baseline with the generated-code check. |
| `tools/api-map/internal/cli/toolchain.go` | Config fields: drop registry/symbols/baseline/report/test-evidence/revision; add `overlay`. |
| `tools/api-map/internal/cli/check_test.go` | Rewritten for the new check. |
| `tools/api-map/internal/cli/helpers_test.go` (create) | `pinnedParityMap` moved here. |
| `tools/api-map/internal/cli/parity.go`, `parity_test.go`, `run.go` | Delete the `parity report` command and its tests; drop it from dispatch/usage. |
| `tools/api-map/internal/parity/` | Delete the package. |
| `docs/api/schema/toolchain-v1.schema.json` + embedded copy | New key set. |
| `docs/api/schema/{coverage-baseline,coverage-registry,live-evidence,parity-report,php-symbols,test-evidence}-v1.schema.json` + embedded copies | Delete. |
| `docs/api/toolchain.json` | New key set. |
| `docs/api/coverage/` | Delete (`docs/api/coverage.md` from plan 2 replaces it). |
| `tools/php-symbols/`, `tests/Tooling/SymbolInventoryTest.php`, `tests/Fixtures/SymbolInventory/` | Delete. |
| `phpcs.xml.dist`, `phpstan.neon.dist` | Drop `tools/php-symbols`. |
| `AGENTS.md`, `docs/api/README.md`, `tools/api-map/README.md` | Replace parity guidance with generation guidance. |

---

### Task 1: Share the generation comparison and move the map helper

**Files:**
- Modify: `tools/api-map/internal/cli/generate.go`
- Create: `tools/api-map/internal/cli/helpers_test.go`
- Modify: `tools/api-map/internal/cli/parity_test.go` (remove the moved helper)

**Interfaces:**
- Produces: `contractFromMap(m model.Map, set overlay.Set, stderr io.Writer) (effective.Result, bool)` (prints problems, returns false when any); `outdatedFiles(root string, files map[string][]byte) ([]string, error)` (sorted paths that are missing, differ, or are stale generated files). `effectiveContract` and `generatePHP --check` use them.

- [ ] **Step 1: Move the helper**

Find the helper and its imports:
```bash
grep -n 'func pinnedParityMap' -A20 tools/api-map/internal/cli/parity_test.go
grep -rn 'pinnedParityMap' tools/api-map/internal/cli
```
Create `tools/api-map/internal/cli/helpers_test.go` containing `package cli`, the imports the helper needs (`bytes`, `context`, `path/filepath`, `testing`), and the `pinnedParityMap` function moved verbatim. Delete it from `parity_test.go` (and any import that becomes unused there).

Run: `go -C tools/api-map test ./internal/cli/`
Expected: `ok` (pure move).

- [ ] **Step 2: Refactor generate.go**

Replace `effectiveContract` and the `--check` branch of `generatePHP` in `tools/api-map/internal/cli/generate.go` with:

```go
func effectiveContract(mapPath, overlayDir string, stderr io.Writer) (effective.Result, bool) {
	raw, err := os.ReadFile(mapPath)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return effective.Result{}, false
	}
	m, err := model.DecodeMap(raw)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return effective.Result{}, false
	}
	set, err := overlay.Load(overlayDir)
	if err != nil {
		fmt.Fprintln(stderr, "api-map: overlay:", err)
		return effective.Result{}, false
	}
	return contractFromMap(m, set, stderr)
}

// contractFromMap validates the overlay against the map and reports every problem.
func contractFromMap(m model.Map, set overlay.Set, stderr io.Writer) (effective.Result, bool) {
	problems := overlay.Validate(set)
	result, err := effective.Build(m, set)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return effective.Result{}, false
	}
	problems = append(problems, result.Problems...)
	for _, problem := range problems {
		fmt.Fprintln(stderr, "api-map: overlay:", problem)
	}
	return result, len(problems) == 0
}

// outdatedFiles lists rendered files that are missing or differ under root, plus
// stale generated files the render no longer produces, sorted.
func outdatedFiles(root string, files map[string][]byte) ([]string, error) {
	var outdated []string
	for path, data := range files {
		current, err := os.ReadFile(filepath.Join(root, filepath.FromSlash(path)))
		if err != nil || !bytes.Equal(current, data) {
			outdated = append(outdated, path)
		}
	}
	stale, err := staleGenerated(root, files)
	if err != nil {
		return nil, err
	}
	outdated = append(outdated, stale...)
	sort.Strings(outdated)
	return outdated, nil
}
```

and make the `--check` branch of `generatePHP`:

```go
	if *check {
		outdated, err := outdatedFiles(*root, files)
		if err != nil {
			fmt.Fprintln(stderr, "api-map:", err)
			return 1
		}
		for _, path := range outdated {
			fmt.Fprintln(stderr, "api-map: generated file out of date:", path)
		}
		if len(outdated) > 0 {
			return 1
		}
		fmt.Fprintf(stdout, "generated PHP is current: %d files\n", len(files))
		return 0
	}
```

(Compute `stale` with `staleGenerated` only in the write branch now.)

- [ ] **Step 3: Run tests**

Run: `go -C tools/api-map test ./internal/cli/ && go -C tools/api-map vet ./... && test -z "$(gofmt -l tools/api-map)"`
Expected: `ok`, no output from vet/gofmt.

- [ ] **Step 4: Commit**

```bash
git add tools/api-map/internal/cli
git commit -m "refactor(api-map): share generated-code comparison and test map helper"
```

### Task 2: Check generated code instead of parity artifacts

**Files:**
- Modify: `tools/api-map/internal/cli/check.go`, `tools/api-map/internal/cli/toolchain.go`, `tools/api-map/internal/cli/run.go`, `docs/api/schema/toolchain-v1.schema.json`, `docs/api/toolchain.json`
- Delete: `tools/api-map/internal/cli/parity.go`, `tools/api-map/internal/cli/parity_test.go`, `tools/api-map/internal/parity/`, the six parity schemas (published and embedded)
- Test: `tools/api-map/internal/cli/check_test.go` (rewrite)

**Interfaces:**
- Consumes: Task 1 helpers, `overlay.Load`, `phpgen.Render`, `phpgen.Marker`, `effective.Build`.
- Produces: `toolchainConfig{SchemaVersion, Manifest, Annotations, Overlay, SchemaDir, Map, MapChecksum, Examples}`; `Check(ctx, root, stdout, stderr) int` printing `Offline API map checks passed; generated code is current.` on success.

- [ ] **Step 1: Write the failing tests**

Replace `tools/api-map/internal/cli/check_test.go` entirely with:

```go
package cli

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/mapbuild"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/phpgen"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func checkWrite(t *testing.T, root, name string, data []byte) {
	t.Helper()
	p := filepath.Join(root, name)
	if e := os.MkdirAll(filepath.Dir(p), 0o755); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(p, data, 0o600); e != nil {
		t.Fatal(e)
	}
}

func checkJSON(t *testing.T, root, name string, v any) {
	t.Helper()
	b, e := model.Encode(v)
	if e != nil {
		t.Fatal(e)
	}
	checkWrite(t, root, name, b)
}

func checkToolchain() model.Object {
	return model.Object{
		"schema_version": "1.0",
		"manifest":       "sources/manifest.json",
		"annotations":    "annotations.json",
		"overlay":        "overlay",
		"schema_dir":     "schema",
		"map":            ".cache/map.json",
		"map_checksum":   "map.sha256",
		"examples":       []any{},
	}
}

func checkFixture(t *testing.T) string {
	t.Helper()
	root := t.TempDir()
	raw := []byte(`{"openapi":"3.0.2","info":{"title":"Synthetic offline source","version":"1"},"paths":{"/demo":{"get":{"responses":{"200":{"description":"ok"}}}}}}`)
	hash := fmt.Sprintf("%x", sha256.Sum256(raw))
	record := model.Object{"id": "synthetic", "kind": "openapi", "api_family": "whm-api-1", "file": "source.json", "url": "https://example.test/source.json", "sha256": hash, "stored_sha256": hash, "bytes": len(raw), "stored_bytes": len(raw), "compression": "none", "content_type": "application/json", "retrieved_at": "2026-10-13T00:00:00Z", "openapi": "3.0.2", "version": "1", "paths": 1, "operations": 1}
	checkJSON(t, root, "sources/manifest.json", model.Object{"schema_version": 1, "hash_algorithm": "sha256", "snapshot_id": "synthetic", "sources": []model.Object{record}})
	checkWrite(t, root, "sources/source.json", raw)
	checkJSON(t, root, "annotations.json", model.Object{"schema_version": "1.0", "annotations": []any{}, "aliases": []any{}})
	bundle, e := source.Load(filepath.Join(root, "sources/manifest.json"), source.Limits{})
	if e != nil {
		t.Fatal(e)
	}
	m, e := mapbuild.Build(bundle, nil, nil)
	if e != nil {
		t.Fatal(e)
	}
	data, e := model.Encode(m)
	if e != nil {
		t.Fatal(e)
	}
	checkWrite(t, root, "map.sha256", []byte(fmt.Sprintf("%x  .cache/map.json\n", sha256.Sum256(data))))
	files, e := filepath.Glob("../../../../docs/api/schema/*.schema.json")
	if e != nil {
		t.Fatal(e)
	}
	for _, p := range files {
		b, e := os.ReadFile(p)
		if e != nil {
			t.Fatal(e)
		}
		checkWrite(t, root, "schema/"+filepath.Base(p), b)
	}
	checkWrite(t, root, "overlay/config.json", []byte(`{"schema_version":"1.0","scope":"listed"}`))
	for _, dir := range []string{"overlay/uapi", "overlay/whm"} {
		if e := os.MkdirAll(filepath.Join(root, dir), 0o755); e != nil {
			t.Fatal(e)
		}
	}
	result, e := effective.Build(m, overlay.Set{Config: overlay.Config{SchemaVersion: "1.0", Scope: "listed"}})
	if e != nil {
		t.Fatal(e)
	}
	for path, content := range phpgen.Render(result) {
		checkWrite(t, root, path, content)
	}
	checkJSON(t, root, "docs/api/toolchain.json", checkToolchain())
	return root
}

func checkSnapshot(t *testing.T, root string) map[string]string {
	t.Helper()
	result := map[string]string{}
	e := filepath.WalkDir(root, func(p string, d fs.DirEntry, e error) error {
		if e != nil {
			return e
		}
		if !d.IsDir() {
			b, e := os.ReadFile(p)
			if e != nil {
				return e
			}
			result[p] = string(b)
		}
		return nil
	})
	if e != nil {
		t.Fatal(e)
	}
	return result
}

func runCheck(t *testing.T, ctx context.Context, root string) (int, string, string) {
	t.Helper()
	var stdout, stderr bytes.Buffer
	code := Check(ctx, root, &stdout, &stderr)
	return code, stdout.String(), stderr.String()
}

func TestCheckIsReadOnlyAndOffline(t *testing.T) {
	root := checkFixture(t)
	before := checkSnapshot(t, root)
	code, stdout, stderr := runCheck(t, context.Background(), root)
	if code != 0 || !strings.Contains(stdout, "generated code is current") {
		t.Fatalf("check %d: %s %s", code, stdout, stderr)
	}
	after := checkSnapshot(t, root)
	if len(before) != len(after) {
		t.Fatal("check created or removed files")
	}
	for path, content := range before {
		if after[path] != content {
			t.Fatalf("check modified %s", path)
		}
	}
}

func TestCheckRejectsStaleGeneratedCode(t *testing.T) {
	root := checkFixture(t)
	checkWrite(t, root, "docs/api/coverage.md", []byte("# stale\n"))
	if code, _, stderr := runCheck(t, context.Background(), root); code != 1 || !strings.Contains(stderr, "docs/api/coverage.md") {
		t.Fatalf("edited summary accepted: %d %s", code, stderr)
	}

	root = checkFixture(t)
	checkWrite(t, root, "src/Api/Uapi/Old.php", []byte("<?php\n/** "+phpgen.Marker+" */\n"))
	if code, _, stderr := runCheck(t, context.Background(), root); code != 1 || !strings.Contains(stderr, "src/Api/Uapi/Old.php") {
		t.Fatalf("stale generated file accepted: %d %s", code, stderr)
	}
}

func TestCheckRejectsOverlayProblems(t *testing.T) {
	root := checkFixture(t)
	checkWrite(t, root, "overlay/uapi/Missing.json", []byte(`{"schema_version":"1.0","family":"uapi","group":"Missing","operations":{"uapi:Missing/nothing":{}}}`))
	if code, _, stderr := runCheck(t, context.Background(), root); code != 1 || !strings.Contains(stderr, "not in the API map") {
		t.Fatalf("overlay problem accepted: %d %s", code, stderr)
	}
}

func TestCheckRejectsConfigurationEscapesAndRemovedKeys(t *testing.T) {
	for name, mutate := range map[string]func(model.Object){
		"escaping overlay":     func(c model.Object) { c["overlay"] = "../overlay" },
		"removed registry key": func(c model.Object) { c["registry"] = "registry.json" },
		"missing overlay":      func(c model.Object) { delete(c, "overlay") },
	} {
		t.Run(name, func(t *testing.T) {
			root := checkFixture(t)
			config := checkToolchain()
			mutate(config)
			checkJSON(t, root, "docs/api/toolchain.json", config)
			if code, _, stderr := runCheck(t, context.Background(), root); code != 1 || !strings.Contains(stderr, "toolchain configuration validation failed") {
				t.Fatalf("accepted %s: %d %s", name, code, stderr)
			}
		})
	}
}

func TestCheckStopsWhenCancelled(t *testing.T) {
	root := checkFixture(t)
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if code, _, stderr := runCheck(t, ctx, root); code == 0 || !strings.Contains(stderr, "operation cancelled") {
		t.Fatalf("cancelled check returned %d: %s", code, stderr)
	}
}

func TestCheckedToolchainMatchesTheRepository(t *testing.T) {
	raw, err := os.ReadFile("../../../../docs/api/toolchain.json")
	if err != nil {
		t.Fatal(err)
	}
	var config map[string]any
	if err := json.Unmarshal(raw, &config); err != nil {
		t.Fatal(err)
	}
	if config["overlay"] != "docs/api/overlay" {
		t.Fatalf("repository toolchain overlay %v", config["overlay"])
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `go -C tools/api-map test ./internal/cli/ -run Check`
Expected: FAIL — the current `Check` rejects the new configuration ("toolchain configuration validation failed") and the repository toolchain has no `overlay` key.

- [ ] **Step 3: Update configuration types, schema and repository config**

In `tools/api-map/internal/cli/toolchain.go` replace the struct and the confinement loop:

```go
type toolchainConfig struct {
	SchemaVersion string         `json:"schema_version"`
	Manifest      string         `json:"manifest"`
	Annotations   string         `json:"annotations"`
	Overlay       string         `json:"overlay"`
	SchemaDir     string         `json:"schema_dir"`
	Map           string         `json:"map"`
	MapChecksum   string         `json:"map_checksum"`
	Examples      []checkExample `json:"examples"`
}
```

```go
	for _, p := range []*string{&cfg.Manifest, &cfg.Annotations, &cfg.Overlay, &cfg.SchemaDir, &cfg.MapChecksum} {
```

Edit `docs/api/schema/toolchain-v1.schema.json`: delete the `registry`, `symbols`, `baseline`, `report_json`, `report_markdown`, `test_evidence` and `revision` entries from `properties` and from `required`; add an `overlay` property with exactly the same definition as `annotations`, and add `"overlay"` to `required` after `"annotations"`. Keep `additionalProperties: false`.

Replace the corresponding keys of `docs/api/toolchain.json` so its top level is:

```json
{
  "schema_version": "1.0",
  "manifest": "docs/api/sources/2026-10-13/manifest.json",
  "annotations": "docs/api/annotations.json",
  "overlay": "docs/api/overlay",
  "schema_dir": "docs/api/schema",
  "map": ".cache/api-map/map.json",
  "map_checksum": "docs/api/map.sha256",
  "examples": [ ...unchanged... ]
}
```

Delete the parity schemas from both locations:

```bash
for name in coverage-baseline coverage-registry live-evidence parity-report php-symbols test-evidence; do
  git rm -q "docs/api/schema/$name-v1.schema.json" "tools/api-map/internal/schema/assets/$name-v1.schema.json"
done
go -C tools/api-map generate ./internal/schema
```

Expected: the embedded assets equal the published schemas (the sync copies `toolchain-v1.schema.json`).

- [ ] **Step 4: Rewrite Check**

In `tools/api-map/internal/cli/check.go`, keep everything up to and including the `ctx.Err()` check after `model.DecodeMap`, delete everything from the symbols export (`symbolsPath := ...`) through the baseline/`report.Valid` handling and the stale-artifact loop, and replace the tail of `Check` with:

```go
	set, err := overlay.Load(cfg.Overlay)
	if err != nil {
		fmt.Fprintln(stderr, "api-map: overlay:", err)
		return 1
	}
	result, ok := contractFromMap(m, set, stderr)
	if !ok {
		return 1
	}
	outdated, err := outdatedFiles(root, phpgen.Render(result))
	if err != nil {
		return failure(stderr, "generated code comparison failed")
	}
	for _, example := range cfg.Examples {
		if ctx.Err() != nil {
			return failure(stderr, "operation cancelled")
		}
		data, e := os.ReadFile(example.Path)
		if e != nil {
			return failure(stderr, "synthetic example read failed")
		}
		value, e := decodeCheckJSON(data)
		if e != nil || schema.Validate(example.Schema, value) != nil {
			return failure(stderr, "synthetic example schema validation failed")
		}
		if example.Schema == "capability-profile-v1.schema.json" {
			if _, e = capability.DecodeProfile(data); e != nil {
				return failure(stderr, "synthetic capability profile validation failed")
			}
		}
		if example.Schema == "api-map-v1.schema.json" {
			if _, e = model.DecodeMap(data); e != nil {
				return failure(stderr, "synthetic map validation failed")
			}
		}
		if example.Schema == "capability-explanations-v1.schema.json" {
			if _, e = capability.LoadExplanations(data); e != nil {
				return failure(stderr, "synthetic explanations validation failed")
			}
		}
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	for _, path := range outdated {
		fmt.Fprintln(stderr, "api-map: generated file out of date:", path)
	}
	if len(outdated) > 0 {
		return 1
	}
	if _, err = fmt.Fprintln(stdout, "Offline API map checks passed; generated code is current."); err != nil {
		return failure(stderr, "check result write failed")
	}
	return 0
}
```

Update the comment above `Check` to: `// Check only reads repository inputs; generated maps live in a disposable directory and generated code is compared, never written.` Fix the imports: add `overlay` and `phpgen`; remove `parity`, `os/exec` and anything else that becomes unused (`goimports`-style; `go vet` will report leftovers).

- [ ] **Step 5: Delete the parity command and package**

```bash
git rm -q tools/api-map/internal/cli/parity.go tools/api-map/internal/cli/parity_test.go
git rm -rq tools/api-map/internal/parity
```

In `tools/api-map/internal/cli/run.go` delete the `case "parity report":` arm and the `| parity report --map PATH ... [--test-evidence PATH --live-evidence PATH --revision REVISION]` segment of the usage string. Run `grep -rn 'parity' tools/api-map/internal tools/api-map/cmd` and remove any remaining reference (for example usage assertions in `run_test.go`).

- [ ] **Step 6: Run tests to verify they pass**

Run: `go -C tools/api-map test ./... && go -C tools/api-map vet ./... && test -z "$(gofmt -l tools/api-map)"`
Expected: all packages `ok`; no vet/gofmt output.

- [ ] **Step 7: Commit**

```bash
git add -A tools/api-map docs/api/schema docs/api/toolchain.json
git commit -m "feat(api-map): check generated code instead of parity artifacts"
```

### Task 3: Remove PHP symbol tooling, coverage artifacts and parity guidance

**Files:**
- Delete: `tools/php-symbols/`, `tests/Tooling/SymbolInventoryTest.php`, `tests/Fixtures/SymbolInventory/`, `docs/api/coverage/`
- Modify: `phpcs.xml.dist`, `phpstan.neon.dist`, `AGENTS.md`, `docs/api/README.md`, `tools/api-map/README.md`

**Interfaces:**
- Consumes: Task 2 (`composer api-map:check` no longer reads these files).
- Produces: a repository whose only coverage artifact is the generated `docs/api/coverage.md`.

- [ ] **Step 1: Delete the tooling and artifacts**

```bash
git rm -rq tools/php-symbols tests/Tooling/SymbolInventoryTest.php tests/Fixtures/SymbolInventory docs/api/coverage
```

Remove `<file>tools/php-symbols</file>` from `phpcs.xml.dist` and the `- tools/php-symbols` line from the `paths` list in `phpstan.neon.dist`. If `tests/Tooling/` is now empty, it is gone with the deletion.

- [ ] **Step 2: Verify the PHP gate**

Run: `composer validate && composer check`
Expected: valid; PHPCS, PHPStan and PHPUnit pass (the test count drops by the removed symbol inventory tests).

- [ ] **Step 3: Update guidance**

In `AGENTS.md`:

1. Delete the "Offline implementation parity uses explicit PHP symbol and registry declarations:" block, its code fence, and the paragraph after it ("Optional `--test-evidence PATH`… response schemas retain the complete upstream envelope.").
2. Insert in its place:

````markdown
Generated client code is rendered from the canonical map plus `docs/api/overlay/`
(see the [generated-client design](docs/superpowers/specs/2026-10-14-generated-client-design.md)):

```bash
composer api-map:generate        # build the map, write generated PHP and docs/api/coverage.md
composer api-map:generate:check  # fail when committed generated files are stale
```

Never edit generated files (they carry "Generated by tools/api-map …; do not edit.");
change the overlay or the generator and regenerate. Every overlay correction and
exclusion needs evidence (`doc` or `live`, version, date, note). Coverage is a fact
of construction: `docs/api/coverage.md` lists generated groups and exclusions.
````

3. In the `api-map:check` paragraph, replace "fresh PHP symbols, checked parity artifacts, synthetic capability/drift examples, and exact operation/variant/PHP/contract/test baseline mappings. It is read-only and offline; it has no baseline-update flag. Review baseline edits manually. Invalid input/mappings exit 1; valid-input baseline regressions exit 3." with "the overlay against the map, that committed generated code is current (including stale generated files), and synthetic capability/drift examples. It is read-only and offline. Invalid input, overlay problems or stale generated code exit 1."
4. In the Git Workflow gate bullet, replace "when public PHP symbols, the coverage registry, or parity artifacts change" with "when `tools/api-map`, `docs/api/overlay` or generated code change".
5. In the architecture bullets, replace "They are registry infrastructure, never endpoint coverage; add a typed group when a consumer relies on an operation." with "They are an escape hatch, not coverage; typed coverage comes from generation." and delete the sentence ending "keep those variants `partial` in the registry."

In `tools/api-map/README.md` and `docs/api/README.md`, delete the sections describing `parity report`, `symbols.json`, `registry.json`, `report.json`/`report.md` and `baseline.json`, and point to the "Overlay and PHP generation" section instead. Then run `grep -rn 'registry.json\|symbols.json\|parity report\|baseline.json\|php-symbols' AGENTS.md README.md docs tools/api-map/README.md` and fix every remaining hit (historical design/plan documents under `docs/superpowers/` stay unchanged).

- [ ] **Step 4: Final gates**

Run (with `GOCACHE`, `GOMODCACHE`, `GOPROXY=off`, `GOSUMDB=off`):
```bash
go -C tools/api-map test ./... && go -C tools/api-map vet ./... && test -z "$(gofmt -l tools/api-map)"
composer validate && composer check && composer api-map:check && composer api-map:generate:check
```
Expected: all green; `api-map:check` prints `Offline API map checks passed; generated code is current.`

- [ ] **Step 5: Commit, push and open the pull request**

```bash
git add -A
git commit -m "chore: retire parity registry, symbols and PHP symbol exporter"
git push -u origin HEAD
gh pr create --base master --title "chore: retire parity machinery; check generated code" --body "Implements plan 3 of docs/superpowers/specs/2026-10-14-generated-client-design.md: api-map check now verifies the overlay and that committed generated code is current; the parity package and command, symbol exporter, registry/symbols/report/baseline artifacts and their schemas are removed."
bd dolt push
```
