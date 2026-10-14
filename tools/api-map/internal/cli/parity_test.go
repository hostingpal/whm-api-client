package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/parity"
	"os"
	"path/filepath"
	"testing"
)

func TestParityPinnedReport(t *testing.T) {
	root := "../../../.."
	mapPath := pinnedParityMap(t)
	dir := t.TempDir()
	out := filepath.Join(dir, "report.json")
	md := filepath.Join(dir, "report.md")
	args := []string{"parity", "report", "--map", mapPath, "--registry", filepath.Join(root, "docs/api/coverage/registry.json"), "--symbols", filepath.Join(root, "docs/api/coverage/symbols.json"), "--output", out, "--markdown", md}
	var stdout, stderr bytes.Buffer
	if code := Run(context.Background(), args, &stdout, &stderr); code != 0 {
		t.Fatalf("exit %d: %s", code, stderr.String())
	}
	first, _ := os.ReadFile(out)
	firstMD, _ := os.ReadFile(md)
	stdout.Reset()
	stderr.Reset()
	if code := Run(context.Background(), args, &stdout, &stderr); code != 0 {
		t.Fatalf("second exit %d: %s", code, stderr.String())
	}
	second, _ := os.ReadFile(out)
	secondMD, _ := os.ReadFile(md)
	if !bytes.Equal(first, second) || !bytes.Equal(firstMD, secondMD) {
		t.Fatal("report nondeterministic")
	}
	var report parity.Report
	if err := json.Unmarshal(first, &report); err != nil {
		t.Fatal(err)
	}
	// Denominators are facts of the pinned sources; no passing-test evidence is
	// supplied. Implemented coverage must match the checked report artifact.
	if !report.Valid || report.Summary.Functions.Denominator != 1323 || report.Summary.Variants.Denominator != 1325 || report.Summary.PassingTests.Numerator != 0 {
		t.Fatalf("unexpected totals %+v", report.Summary)
	}
	checkedRaw, err := os.ReadFile(filepath.Join(root, "docs/api/coverage/report.json"))
	if err != nil {
		t.Fatal(err)
	}
	var checked parity.Report
	if err := json.Unmarshal(checkedRaw, &checked); err != nil {
		t.Fatal(err)
	}
	if report.Summary.Functions != checked.Summary.Functions || report.Summary.Variants != checked.Summary.Variants {
		t.Fatalf("coverage %+v differs from checked report %+v", report.Summary, checked.Summary)
	}
}

func TestParityInvalidRegistryWritesDiagnosticReport(t *testing.T) {
	root := "../../../.."
	mapPath := pinnedParityMap(t)
	raw, err := os.ReadFile(filepath.Join(root, "docs/api/coverage/registry.json"))
	if err != nil {
		t.Fatal(err)
	}
	var registry map[string]any
	if err = json.Unmarshal(raw, &registry); err != nil {
		t.Fatal(err)
	}
	registry["entries"] = []any{map[string]any{"operation_id": "uapi:Unknown/f", "variant_id": "uapi:Unknown/f#GET", "symbol": "Missing::method", "status": "implemented", "tests": []any{}, "contract_units": []any{}, "live_evidence_ids": []any{}}}
	raw, _ = json.Marshal(registry)
	dir := t.TempDir()
	reg := filepath.Join(dir, "registry.json")
	if err = os.WriteFile(reg, raw, 0600); err != nil {
		t.Fatal(err)
	}
	out := filepath.Join(dir, "report.json")
	var stdout, stderr bytes.Buffer
	code := Run(context.Background(), []string{"parity", "report", "--map", mapPath, "--registry", reg, "--symbols", filepath.Join(root, "docs/api/coverage/symbols.json"), "--output", out, "--markdown", filepath.Join(dir, "report.md")}, &stdout, &stderr)
	if code != 1 {
		t.Fatalf("invalid registry exit %d: %s", code, stderr.String())
	}
	raw, err = os.ReadFile(out)
	if err != nil {
		t.Fatalf("diagnostic report missing: %v %s", err, stderr.String())
	}
	var report parity.Report
	if err = json.Unmarshal(raw, &report); err != nil {
		t.Fatal(err)
	}
	if report.Valid || len(report.Diagnostics) == 0 || report.Summary.Functions.Numerator != 0 {
		t.Fatalf("invalid report %+v", report)
	}
}

func TestParityOptionalLiveEvidenceArtifact(t *testing.T) {
	mapPath := pinnedParityMap(t)
	dir := t.TempDir()
	registry := parity.Registry{SchemaVersion: "1.0", Entries: []parity.Entry{{OperationID: "uapi:WebApp/stage", VariantID: "uapi:WebApp/stage#GET", Symbol: "Client::stage", Status: "partial", Tests: []string{}, ContractUnits: []parity.ContractClaim{}, LiveEvidenceIDs: []string{"saved-live-run"}}}, Infrastructure: []parity.Infrastructure{}, Exclusions: []parity.Exclusion{}}
	symbols := parity.Symbols{SchemaVersion: "1.0", Symbols: []parity.Symbol{{ID: "Client::stage", Kind: "method", DeclaringClass: "Client", File: "src/Client.php", Line: 1, Public: true, Providers: []string{}}}, Diagnostics: []parity.SymbolDiagnostic{}}
	write := func(name string, value any) string {
		t.Helper()
		raw, err := json.Marshal(value)
		if err != nil {
			t.Fatal(err)
		}
		p := filepath.Join(dir, name)
		if err = os.WriteFile(p, raw, 0600); err != nil {
			t.Fatal(err)
		}
		return p
	}
	reg := write("registry.json", registry)
	sym := write("symbols.json", symbols)
	live := write("live.json", parity.LiveEvidence{SchemaVersion: "1.0", Records: []parity.LiveRecord{}})
	for _, provided := range []bool{false, true} {
		t.Run(fmt.Sprint(provided), func(t *testing.T) {
			out := filepath.Join(t.TempDir(), "report.json")
			args := []string{"parity", "report", "--map", mapPath, "--registry", reg, "--symbols", sym, "--output", out, "--markdown", out + ".md"}
			want := 0
			if provided {
				args = append(args, "--live-evidence", live)
				want = 1
			}
			var stdout, stderr bytes.Buffer
			if code := Run(context.Background(), args, &stdout, &stderr); code != want {
				t.Fatalf("exit=%d want=%d: %s", code, want, stderr.String())
			}
			raw, err := os.ReadFile(out)
			if err != nil {
				t.Fatal(err)
			}
			var report parity.Report
			if err = json.Unmarshal(raw, &report); err != nil {
				t.Fatal(err)
			}
			if report.Evidence.LiveProvided != provided || report.Summary.LiveVerified.Numerator != 0 {
				t.Fatalf("artifact presence/credit wrong: %+v", report.Evidence)
			}
		})
	}
}
