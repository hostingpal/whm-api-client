package cli

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"io/fs"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"syscall"
	"testing"
	"time"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/mapbuild"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/parity"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func checkWrite(t *testing.T, root, name string, data []byte) {
	t.Helper()
	p := filepath.Join(root, name)
	if e := os.MkdirAll(filepath.Dir(p), 0755); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(p, data, 0600); e != nil {
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
	registry := parity.Registry{SchemaVersion: "1.0", Entries: []parity.Entry{}, Infrastructure: []parity.Infrastructure{}, Exclusions: []parity.Exclusion{}}
	symbols := parity.Symbols{SchemaVersion: "1.0", Symbols: []parity.Symbol{}, Diagnostics: []parity.SymbolDiagnostic{}}
	checkJSON(t, root, "registry.json", registry)
	checkJSON(t, root, "symbols.json", symbols)
	checkWrite(t, root, "tools/php-symbols/export.php", []byte("<?php\nif ($argc !== 5 || $argv[1] !== '--root' || $argv[3] !== '--output') { exit(9); }\ncopy($argv[2] . '/symbols.json', $argv[4]);\n"))
	checkJSON(t, root, "tests.json", parity.TestEvidence{SchemaVersion: "1.0", Runs: []parity.TestRun{}})
	report, e := parity.Compare(m, registry, symbols, parity.Evidence{})
	if e != nil {
		t.Fatal(e)
	}
	checkJSON(t, root, "report.json", report)
	checkWrite(t, root, "report.md", parity.Markdown(report))
	checkJSON(t, root, "baseline.json", parity.Baseline{SchemaVersion: "1.0", Operations: []string{}, Mappings: []parity.ProtectedMapping{}})
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
	checkJSON(t, root, "docs/api/toolchain.json", model.Object{"schema_version": "1.0", "manifest": "sources/manifest.json", "annotations": "annotations.json", "registry": "registry.json", "symbols": "symbols.json", "baseline": "baseline.json", "schema_dir": "schema", "map": ".cache/map.json", "map_checksum": "map.sha256", "report_json": "report.json", "report_markdown": "report.md", "test_evidence": "tests.json", "examples": []any{}})
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
func TestCheckIsReadOnlyAndOffline(t *testing.T) {
	requests := 0
	original := http.DefaultTransport
	http.DefaultTransport = roundTripFunc(func(*http.Request) (*http.Response, error) { requests++; return nil, fmt.Errorf("network prohibited") })
	t.Cleanup(func() { http.DefaultTransport = original })
	root := checkFixture(t)
	for _, mode := range []string{"pass", "regression", "invalid registry", "invalid source"} {
		t.Run(mode, func(t *testing.T) {
			want := 0
			switch mode {
			case "regression":
				checkJSON(t, root, "baseline.json", parity.Baseline{SchemaVersion: "1.0", Operations: []string{"whm1:demo"}, Mappings: []parity.ProtectedMapping{}})
				want = 3
			case "invalid registry":
				checkWrite(t, root, "registry.json", []byte(`{}`))
				want = 1
			case "invalid source":
				checkWrite(t, root, "sources/source.json", []byte(`{}`))
				want = 1
			}
			before := checkSnapshot(t, root)
			var out, err bytes.Buffer
			code := Run(context.Background(), []string{"check", "--root", root}, &out, &err)
			if code != want {
				t.Fatalf("exit=%d want=%d stderr=%s", code, want, err.String())
			}
			after := checkSnapshot(t, root)
			b, _ := json.Marshal(before)
			a, _ := json.Marshal(after)
			if !bytes.Equal(b, a) {
				t.Fatal("check modified repository artifacts")
			}
		})
	}
	if requests != 0 {
		t.Fatalf("made %d HTTP requests", requests)
	}
}
func TestCheckRejectsStaleArtifactsAndCancellation(t *testing.T) {
	for _, name := range []string{"symbols", "report", "checksum", "schema", "config", "cancel"} {
		t.Run(name, func(t *testing.T) {
			root := checkFixture(t)
			ctx := context.Background()
			switch name {
			case "symbols":
				checkWrite(t, root, "tools/php-symbols/export.php", []byte("<?php file_put_contents($argv[4], '{\"schema_version\":\"1.0\",\"symbols\":[],\"diagnostics\":[]}');"))
			case "report":
				checkWrite(t, root, "report.md", []byte("stale"))
			case "checksum":
				checkWrite(t, root, "map.sha256", []byte("bad"))
			case "schema":
				checkWrite(t, root, "schema/api-map-v1.schema.json", []byte("{}"))
			case "config":
				checkWrite(t, root, "docs/api/toolchain.json", []byte(`{"schema_version":"1.0","command":"curl"}`))
			case "cancel":
				c, cancel := context.WithCancel(ctx)
				cancel()
				ctx = c
			}
			before := checkSnapshot(t, root)
			var out, err bytes.Buffer
			if code := Check(ctx, root, &out, &err); code != 1 {
				t.Fatalf("exit=%d %s", code, err.String())
			}
			after := checkSnapshot(t, root)
			b, _ := json.Marshal(before)
			a, _ := json.Marshal(after)
			if !bytes.Equal(a, b) {
				t.Fatal("failure modified repository")
			}
		})
	}
}

func TestCheckExactRemovedAndMismappedFixtures(t *testing.T) {
	for _, mode := range []string{"unchanged", "removed", "mismapped"} {
		t.Run(mode, func(t *testing.T) {
			root := checkFixture(t)
			symbols := parity.Symbols{SchemaVersion: "1.0", Symbols: []parity.Symbol{{ID: "Client::demo", Kind: "method", DeclaringClass: "Client", File: "src/Client.php", Line: 1, Public: true, Providers: []string{}}}, Diagnostics: []parity.SymbolDiagnostic{}}
			checkJSON(t, root, "symbols.json", symbols)
			entry := parity.Entry{OperationID: "whm1:demo", VariantID: "whm1:demo#GET", Symbol: "Client::demo", Status: "partial", Tests: []string{}, ContractUnits: []parity.ContractClaim{}, LiveEvidenceIDs: []string{}}
			registry := parity.Registry{SchemaVersion: "1.0", Entries: []parity.Entry{entry}, Infrastructure: []parity.Infrastructure{}, Exclusions: []parity.Exclusion{}}
			checkJSON(t, root, "baseline.json", parity.Baseline{SchemaVersion: "1.0", Operations: []string{}, Mappings: []parity.ProtectedMapping{{OperationID: entry.OperationID, VariantID: entry.VariantID, Symbol: entry.Symbol, Status: entry.Status, Tests: []string{}, ContractUnits: []parity.ContractClaim{}}}})
			bundle, e := source.Load(filepath.Join(root, "sources/manifest.json"), source.Limits{})
			if e != nil {
				t.Fatal(e)
			}
			m, e := mapbuild.Build(bundle, nil, nil)
			if e != nil {
				t.Fatal(e)
			}
			report, e := parity.Compare(m, registry, symbols, parity.Evidence{})
			if e != nil {
				t.Fatal(e)
			}
			checkJSON(t, root, "report.json", report)
			checkWrite(t, root, "report.md", parity.Markdown(report))
			want := 0
			switch mode {
			case "removed":
				registry.Entries = []parity.Entry{}
				want = 3
			case "mismapped":
				registry.Entries[0].OperationID = "whm1:missing"
				registry.Entries[0].VariantID = "whm1:missing#GET"
				want = 1
			}
			checkJSON(t, root, "registry.json", registry)
			temporary := t.TempDir()
			t.Setenv("TMPDIR", temporary)
			before := checkSnapshot(t, root)
			var out, err bytes.Buffer
			code := Check(context.Background(), root, &out, &err)
			if code != want {
				t.Fatalf("exit %d want %d: %s", code, want, err.String())
			}
			if mode != "unchanged" && !bytes.Contains(err.Bytes(), []byte("mapping_regression")) {
				t.Fatal("missing exact mapping regression diagnosis", err.String())
			}
			after := checkSnapshot(t, root)
			a, _ := json.Marshal(after)
			b, _ := json.Marshal(before)
			if !bytes.Equal(a, b) {
				t.Fatal("check changed artifacts")
			}
			leftovers, e := os.ReadDir(temporary)
			if e != nil || len(leftovers) != 0 {
				t.Fatal("temporary outputs not cleaned", e, leftovers)
			}
		})
	}
}

func TestCheckRejectsConfigurationEscapesAndAmbiguity(t *testing.T) {
	for _, mode := range []string{"absolute", "parent", "symlink", "duplicate", "trailing", "exporter failure"} {
		t.Run(mode, func(t *testing.T) {
			root := checkFixture(t)
			p := filepath.Join(root, "docs/api/toolchain.json")
			raw, e := os.ReadFile(p)
			if e != nil {
				t.Fatal(e)
			}
			var cfg map[string]any
			if e = json.Unmarshal(raw, &cfg); e != nil {
				t.Fatal(e)
			}
			switch mode {
			case "absolute":
				cfg["symbols"] = "/tmp/symbols.json"
			case "parent":
				cfg["symbols"] = "../symbols.json"
			case "symlink":
				outside := filepath.Join(t.TempDir(), "outside.json")
				if e = os.WriteFile(outside, []byte("{}"), 0600); e != nil {
					t.Fatal(e)
				}
				if e = os.Symlink(outside, filepath.Join(root, "outside.json")); e != nil {
					t.Fatal(e)
				}
				cfg["symbols"] = "outside.json"
			case "exporter failure":
				checkWrite(t, root, "tools/php-symbols/export.php", []byte("<?php exit(4);"))
			}
			raw, e = model.Encode(cfg)
			if e != nil {
				t.Fatal(e)
			}
			if mode == "duplicate" {
				raw = append([]byte(`{"schema_version":"1.0",`), raw[1:]...)
			}
			if mode == "trailing" {
				raw = append(raw, []byte(" {}")...)
			}
			checkWrite(t, root, "docs/api/toolchain.json", raw)
			var out, err bytes.Buffer
			if code := Check(context.Background(), root, &out, &err); code != 1 {
				t.Fatalf("exit %d: %s", code, err.String())
			}
		})
	}
}

func TestCheckCancelsRunningExporterAndCleansTemporaryOutputs(t *testing.T) {
	root := checkFixture(t)
	temporary := t.TempDir()
	t.Setenv("TMPDIR", temporary)
	checkWrite(t, root, "tools/php-symbols/export.php", []byte(`<?php
file_put_contents($argv[4] . '.started', (string) getmypid());
while (true) { usleep(10000); }
`))
	before := checkSnapshot(t, root)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var stdout, stderr bytes.Buffer
	done := make(chan int, 1)
	go func() { done <- Check(ctx, root, &stdout, &stderr) }()

	deadline := time.NewTimer(5 * time.Second)
	defer deadline.Stop()
	ticker := time.NewTicker(5 * time.Millisecond)
	defer ticker.Stop()
	var process *os.Process
	for process == nil {
		select {
		case code := <-done:
			t.Fatalf("check exited before exporter startup: %d: %s", code, stderr.String())
		case <-deadline.C:
			t.Fatal("exporter did not start")
		case <-ticker.C:
			markers, err := filepath.Glob(filepath.Join(temporary, "api-map-check-*", "symbols.json.started"))
			if err != nil {
				t.Fatal(err)
			}
			if len(markers) == 0 {
				continue
			}
			data, err := os.ReadFile(markers[0])
			if err != nil {
				t.Fatal(err)
			}
			if len(data) == 0 {
				continue
			} // The writer may have created, but not filled, the marker.
			pid, err := strconv.Atoi(string(data))
			if err != nil {
				t.Fatal(err)
			}
			process, err = os.FindProcess(pid)
			if err != nil {
				t.Fatal(err)
			}
		}
	}
	// Cleanup also bounds a failing implementation that neglects to stop PHP.
	defer process.Kill()
	cancel()
	select {
	case code := <-done:
		if code != 1 {
			t.Fatalf("cancelled exporter exit=%d: %s", code, stderr.String())
		}
	case <-time.After(5 * time.Second):
		_ = process.Kill()
		<-done
		t.Fatal("cancellation did not stop the running exporter")
	}
	if err := process.Signal(syscall.Signal(0)); err == nil {
		t.Fatal("exporter process is still alive after cancellation returned")
	}
	leftovers, err := os.ReadDir(temporary)
	if err != nil || len(leftovers) != 0 {
		t.Fatalf("temporary outputs remain: %v, %v", leftovers, err)
	}
	after := checkSnapshot(t, root)
	oldBytes, _ := json.Marshal(before)
	newBytes, _ := json.Marshal(after)
	if !bytes.Equal(oldBytes, newBytes) {
		t.Fatal("cancellation modified repository artifacts")
	}
}
