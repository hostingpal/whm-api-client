package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/capability"
)

func compareArgs(t *testing.T) ([]string, string, string) {
	t.Helper()
	dir := t.TempDir()
	out := filepath.Join(dir, "comparison.json")
	md := filepath.Join(dir, "comparison.md")
	return []string{"capabilities", "compare", "--map", "../model/testdata/map-valid.json", "--profile", "../../../../docs/api/examples/capabilities/synthetic-success.json", "--output", out, "--markdown", md}, out, md
}
func TestCapabilitiesCompareOfflineDeterministic(t *testing.T) {
	requests := 0
	original := http.DefaultTransport
	http.DefaultTransport = capabilityRoundTrip(func(*http.Request) (*http.Response, error) {
		requests++
		return nil, fmt.Errorf("comparison must stay offline")
	})
	t.Cleanup(func() { http.DefaultTransport = original })
	t.Cleanup(func() {
		if requests != 0 {
			t.Errorf("comparison sent %d HTTP requests", requests)
		}
	})
	args, out, md := compareArgs(t)
	var stdout, stderr bytes.Buffer
	if code := Run(context.Background(), args, &stdout, &stderr); code != 0 {
		t.Fatalf("exit %d: %s", code, &stderr)
	}
	first, err := os.ReadFile(out)
	if err != nil {
		t.Fatal(err)
	}
	firstMD, err := os.ReadFile(md)
	if err != nil {
		t.Fatal(err)
	}
	if stdout.Len() != 0 || stderr.Len() != 0 {
		t.Fatal("unexpected command output")
	}
	if code := Run(context.Background(), args, &stdout, &stderr); code != 0 {
		t.Fatalf("repeat exit %d: %s", code, &stderr)
	}
	second, _ := os.ReadFile(out)
	secondMD, _ := os.ReadFile(md)
	if !bytes.Equal(first, second) || !bytes.Equal(firstMD, secondMD) {
		t.Fatal("comparison nondeterministic")
	}
	var report capability.Comparison
	if err := json.Unmarshal(first, &report); err != nil {
		t.Fatal(err)
	}
	if report.WHM.DocumentedCount != 1 || report.UAPI.DocumentedCount != 1 || report.UAPI.AdvertisedCount != nil {
		t.Fatalf("bad denominators: %+v", report)
	}
	args[7] = "-" // output flag's value
	stdout.Reset()
	if code := Run(context.Background(), args, &stdout, &stderr); code != 0 {
		t.Fatalf("stdout exit %d: %s", code, &stderr)
	}
	if !json.Valid(stdout.Bytes()) {
		t.Fatal("JSON stdout corrupted")
	}
}
func TestCapabilitiesCompareRejectsInputsWithoutReplacingOutput(t *testing.T) {
	for name, mutate := range map[string]func([]string, string) []string{
		"missing profile":        func(a []string, p string) []string { a[5] = p; return a },
		"invalid profile":        func(a []string, p string) []string { a[5] = p; return a },
		"output equals input":    func(a []string, p string) []string { a[7] = a[5]; return a },
		"output equals markdown": func(a []string, p string) []string { a[9] = a[7]; return a },
		"invalid explanation":    func(a []string, p string) []string { return append(a, "--explanations", p) },
	} {
		t.Run(name, func(t *testing.T) {
			args, out, _ := compareArgs(t)
			prior := []byte("previous report\n")
			if err := os.WriteFile(out, prior, 0600); err != nil {
				t.Fatal(err)
			}
			bad := filepath.Join(t.TempDir(), "bad.json")
			if name != "missing profile" {
				if err := os.WriteFile(bad, []byte(`{"origin":"http://invalid"}`), 0600); err != nil {
					t.Fatal(err)
				}
			}
			args = mutate(args, bad)
			var stdout, stderr bytes.Buffer
			if code := Run(context.Background(), args, &stdout, &stderr); code != 1 {
				t.Fatalf("exit %d: %s", code, &stderr)
			}
			after, err := os.ReadFile(out)
			if err != nil || !bytes.Equal(prior, after) {
				t.Fatal("invalid input replaced report")
			}
		})
	}
}
func TestCapabilitiesCompareSavedExplanations(t *testing.T) {
	args, out, _ := compareArgs(t)
	explanation := `{"schema_version":"1.0","explanations":[{"side":"runtime-only","name":"MixedCase","category":"plugin","certainty":"possible","rationale":"Synthetic plugin review only.","evidence":[{"source_id":"synthetic-review","source_sha256":"` + strings.Repeat("a", 64) + `","pointer":""}]}]}`
	path := filepath.Join(t.TempDir(), "explanations.json")
	if err := os.WriteFile(path, []byte(explanation), 0600); err != nil {
		t.Fatal(err)
	}
	args = append(args, "--explanations", path)
	var stdout, stderr bytes.Buffer
	if code := Run(context.Background(), args, &stdout, &stderr); code != 0 {
		t.Fatalf("exit %d: %s", code, &stderr)
	}
	raw, err := os.ReadFile(out)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Contains(raw, []byte("Synthetic plugin review only.")) {
		t.Fatal("missing reviewed explanation")
	}
}
func TestCapabilitiesCompareUsageAndCancellation(t *testing.T) {
	var stdout, stderr bytes.Buffer
	if code := Run(context.Background(), []string{"capabilities", "compare"}, &stdout, &stderr); code != 2 {
		t.Fatalf("usage exit %d", code)
	}
	args, out, _ := compareArgs(t)
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if code := Run(ctx, args, &stdout, &stderr); code != 1 {
		t.Fatalf("cancel exit %d", code)
	}
	if _, err := os.Stat(out); !os.IsNotExist(err) {
		t.Fatal("cancelled comparison wrote output")
	}
}
