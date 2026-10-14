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
	current, e := os.ReadFile(filepath.Join(root, "docs/api/coverage.md"))
	if e != nil {
		t.Fatal(e)
	}
	checkWrite(t, root, "docs/api/coverage.md", append(current, []byte("\nedited by hand\n")...))
	if code, _, stderr := runCheck(t, context.Background(), root); code != 1 || !strings.Contains(stderr, "out of date: docs/api/coverage.md") {
		t.Fatalf("edited summary accepted: %d %s", code, stderr)
	}

	root = checkFixture(t)
	checkWrite(t, root, "src/Api/Uapi/Old.php", []byte("<?php\n/** "+phpgen.Marker+" */\n"))
	if code, _, stderr := runCheck(t, context.Background(), root); code != 1 || !strings.Contains(stderr, "src/Api/Uapi/Old.php") {
		t.Fatalf("stale generated file accepted: %d %s", code, stderr)
	}
}

func TestCheckRefusesHandWrittenFileAtRenderedPath(t *testing.T) {
	root := checkFixture(t)
	checkWrite(t, root, "docs/api/coverage.md", []byte("# written by hand\n"))
	if code, _, stderr := runCheck(t, context.Background(), root); code != 1 || !strings.Contains(stderr, "refusing to overwrite hand-written file: docs/api/coverage.md") {
		t.Fatalf("hand-written file accepted: %d %s", code, stderr)
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
