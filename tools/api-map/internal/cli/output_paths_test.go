package cli

import (
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Include a non-OpenAPI selected source: every manifest input is protected.
func outputFixture(t *testing.T) string {
	t.Helper()
	dir := t.TempDir()
	files := map[string][]byte{
		"source.json":      []byte(`{"openapi":"3.0.2","info":{"title":"synthetic","version":"1"},"paths":{"/valid":{"get":{"responses":{"200":{"description":"ok"}}}}}}`),
		"guide.html":       []byte("<p>Reviewed guide.</p>"),
		"annotations.json": []byte(`{"schema_version":"1.0","annotations":[],"aliases":[]}`),
		"registry.json":    []byte(`{"schema_version":"1.0","entries":[],"infrastructure":[],"exclusions":[]}`),
		"symbols.json":     []byte(`{"schema_version":"1.0","symbols":[],"diagnostics":[]}`),
		"tests.json":       []byte(`{"schema_version":"1.0","runs":[]}`),
		"live.json":        []byte(`{"schema_version":"1.0","records":[]}`),
	}
	records := []any{}
	for _, name := range []string{"source.json", "guide.html"} {
		raw := files[name]
		kind := "openapi"
		if name == "guide.html" {
			kind = "prose"
		}
		h := fmt.Sprintf("%x", sha256.Sum256(raw))
		records = append(records, map[string]any{"id": name, "kind": kind, "api_family": "whm-api-1", "file": name, "url": "https://api.docs.cpanel.net/" + name, "sha256": h, "stored_sha256": h, "bytes": len(raw), "stored_bytes": len(raw), "compression": "none", "retrieved_at": "2026-10-13T00:00:00Z"})
	}
	files["manifest.json"], _ = json.Marshal(map[string]any{"schema_version": 1, "hash_algorithm": "sha256", "snapshot_id": "synthetic", "sources": records})
	for name, raw := range files {
		if err := os.WriteFile(filepath.Join(dir, name), raw, 0600); err != nil {
			t.Fatal(err)
		}
	}
	return dir
}

func directoryAlias(t *testing.T, dir string) string {
	t.Helper()
	alias := filepath.Join(t.TempDir(), "alias")
	if err := os.Symlink(dir, alias); err != nil {
		t.Fatal(err)
	}
	return alias
}
func assertPreserved(t *testing.T, files map[string][]byte) {
	t.Helper()
	for path, before := range files {
		after, err := os.ReadFile(path)
		if err != nil || !bytes.Equal(before, after) {
			t.Errorf("input/output changed: %s (%v)", path, err)
		}
	}
}
func TestMapOutputProtectsAllInputs(t *testing.T) {
	for _, target := range []string{"manifest.json", "annotations.json", "source.json", "guide.html"} {
		for _, alias := range []bool{false, true} {
			t.Run(fmt.Sprintf("%s/alias=%t", target, alias), func(t *testing.T) {
				dir := outputFixture(t)
				outDir := dir
				if alias {
					outDir = directoryAlias(t, dir)
				}
				path := filepath.Join(dir, target)
				before, _ := os.ReadFile(path)
				code, stdout, stderr := run("map", "build", "--manifest", filepath.Join(dir, "manifest.json"), "--annotations", filepath.Join(dir, "annotations.json"), "--output", filepath.Join(outDir, target))
				assertPreserved(t, map[string][]byte{path: before})
				if code != 1 || stdout != "" || !strings.Contains(stderr, "distinct") {
					t.Fatalf("collision not rejected: %d %q %s", code, stdout, stderr)
				}
			})
		}
	}
}
func TestParityOutputsProtectInputsAndEachOther(t *testing.T) {
	for _, outputFlag := range []string{"--output", "--markdown"} {
		for _, target := range []string{"map.json", "registry.json", "symbols.json", "tests.json", "live.json", "companion"} {
			for _, alias := range []bool{false, true} {
				t.Run(fmt.Sprintf("%s/%s/alias=%t", outputFlag, target, alias), func(t *testing.T) {
					dir := outputFixture(t)
					mapPath := filepath.Join(dir, "map.json")
					if code, _, errout := run("map", "build", "--manifest", filepath.Join(dir, "manifest.json"), "--annotations", filepath.Join(dir, "annotations.json"), "--output", mapPath); code != 0 {
						t.Fatal(errout)
					}
					outDir := dir
					if alias {
						outDir = directoryAlias(t, dir)
					}
					out, md := filepath.Join(dir, "report.json"), filepath.Join(dir, "report.md")
					name := target
					if name == "companion" {
						name = "report.json"
						if outputFlag == "--output" {
							name = "report.md"
						}
						if err := os.WriteFile(filepath.Join(dir, name), []byte("prior output"), 0600); err != nil {
							t.Fatal(err)
						}
					}
					if outputFlag == "--output" {
						out = filepath.Join(outDir, name)
					} else {
						md = filepath.Join(outDir, name)
					}
					preserved := map[string][]byte{}
					for _, name := range []string{"map.json", "registry.json", "symbols.json", "tests.json", "live.json"} {
						p := filepath.Join(dir, name)
						preserved[p], _ = os.ReadFile(p)
					}
					if target == "companion" {
						p := filepath.Join(dir, name)
						preserved[p], _ = os.ReadFile(p)
					}
					code, stdout, stderr := run("parity", "report", "--map", mapPath, "--registry", filepath.Join(dir, "registry.json"), "--symbols", filepath.Join(dir, "symbols.json"), "--test-evidence", filepath.Join(dir, "tests.json"), "--live-evidence", filepath.Join(dir, "live.json"), "--output", out, "--markdown", md)
					assertPreserved(t, preserved)
					if code != 1 || stdout != "" || !strings.Contains(stderr, "distinct") {
						t.Fatalf("collision not rejected: %d %q %s", code, stdout, stderr)
					}
				})
			}
		}
	}
}
func TestGeneratedOutputsCanBeReplaced(t *testing.T) {
	dir := outputFixture(t)
	for i := 0; i < 2; i++ {
		if code, _, stderr := run("map", "build", "--manifest", filepath.Join(dir, "manifest.json"), "--annotations", filepath.Join(dir, "annotations.json"), "--output", filepath.Join(dir, "map.json")); code != 0 {
			t.Fatal(stderr)
		}
		if code, _, stderr := run("parity", "report", "--map", filepath.Join(dir, "map.json"), "--registry", filepath.Join(dir, "registry.json"), "--symbols", filepath.Join(dir, "symbols.json"), "--output", filepath.Join(dir, "report.json"), "--markdown", filepath.Join(dir, "report.md")); code != 0 {
			t.Fatal(stderr)
		}
	}
}
