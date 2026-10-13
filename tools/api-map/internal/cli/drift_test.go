package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/drift"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/mapbuild"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func driftMap(t *testing.T, path string, changed bool) {
	t.Helper()
	raw, e := os.ReadFile("../drift/testdata/document.json")
	if e != nil {
		t.Fatal(e)
	}
	var d model.Object
	if e = json.Unmarshal(raw, &d); e != nil {
		t.Fatal(e)
	}
	if changed {
		d["paths"].(map[string]any)["/Demo/inspect"].(map[string]any)["get"].(map[string]any)["deprecated"] = true
	}
	m, e := mapbuild.Build(source.Bundle{Records: []model.Object{{"id": "synthetic", "file": "document.json", "kind": "openapi", "api_family": "cpanel-uapi", "url": "https://example.test/api.json", "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}, Documents: map[string]model.Object{"synthetic": d}}, nil, nil)
	if e != nil {
		t.Fatal(e)
	}
	raw, e = model.Encode(m)
	if e != nil {
		t.Fatal(e)
	}
	if e = os.WriteFile(path, raw, 0600); e != nil {
		t.Fatal(e)
	}
}
func TestDriftCLI(t *testing.T) {
	dir := t.TempDir()
	before, after, out, md := filepath.Join(dir, "before.json"), filepath.Join(dir, "after.json"), filepath.Join(dir, "report.json"), filepath.Join(dir, "report.md")
	driftMap(t, before, false)
	driftMap(t, after, true)
	args := []string{"drift", "report", "--before", before, "--after", after, "--output", out, "--markdown", md}
	var stdout, stderr bytes.Buffer
	if code := Run(context.Background(), args, &stdout, &stderr); code != 0 {
		t.Fatalf("upstream changes exit %d: %s", code, stderr.String())
	}
	raw, e := os.ReadFile(out)
	if e != nil {
		t.Fatal(e)
	}
	var r drift.Report
	if e = json.Unmarshal(raw, &r); e != nil || len(r.Changes) == 0 {
		t.Fatalf("empty report: %s %v", raw, e)
	}
	markdown, e := os.ReadFile(md)
	if e != nil || !bytes.Contains(markdown, []byte("deprecation")) {
		t.Fatalf("Markdown: %s %v", markdown, e)
	}
	args[7] = "-"
	stdout.Reset()
	stderr.Reset()
	if code := Run(context.Background(), args, &stdout, &stderr); code != 0 || !json.Valid(stdout.Bytes()) {
		t.Fatalf("JSON stdout: exit %d %s %s", code, stdout.String(), stderr.String())
	}
	if e = os.WriteFile(after, []byte("{}"), 0600); e != nil {
		t.Fatal(e)
	}
	args[7] = out
	stderr.Reset()
	if code := Run(context.Background(), args, &stdout, &stderr); code != 1 {
		t.Fatalf("invalid input exit %d", code)
	}
	again, _ := os.ReadFile(out)
	if !bytes.Equal(raw, again) {
		t.Fatal("invalid input overwrote report")
	}
	if code := Run(context.Background(), []string{"drift", "report"}, &stdout, &stderr); code != 2 {
		t.Fatalf("usage exit %d", code)
	}
}
func TestDriftOutputCollision(t *testing.T) {
	dir := t.TempDir()
	in := filepath.Join(dir, "map.json")
	driftMap(t, in, false)
	raw, _ := os.ReadFile(in)
	var stdout, stderr bytes.Buffer
	if code := Run(context.Background(), []string{"drift", "report", "--before", in, "--after", in, "--output", in, "--markdown", filepath.Join(dir, "report.md")}, &stdout, &stderr); code != 1 {
		t.Fatalf("collision exit %d", code)
	}
	after, _ := os.ReadFile(in)
	if !bytes.Equal(raw, after) {
		t.Fatal("input overwritten")
	}
}

func TestDriftTruncatedBundlePreservesOutputs(t *testing.T) {
	for _, side := range []string{"before", "after"} {
		t.Run(side, func(t *testing.T) {
			dir := t.TempDir()
			before, after := filepath.Join(dir, "before.json"), filepath.Join(dir, "after.json")
			driftMap(t, before, false)
			driftMap(t, after, true)
			invalid := before
			if side == "after" {
				invalid = after
			}
			raw, e := os.ReadFile(invalid)
			if e != nil {
				t.Fatal(e)
			}
			m, e := model.DecodeMap(raw)
			if e != nil {
				t.Fatal(e)
			}
			m.Documents = map[string]model.Object{}
			m.Operations = []model.Operation{}
			m.Diagnostics = []model.Diagnostic{}
			raw, e = model.Encode(m)
			if e != nil {
				t.Fatal(e)
			}
			if e = os.WriteFile(invalid, raw, 0600); e != nil {
				t.Fatal(e)
			}
			out, md := filepath.Join(dir, "report.json"), filepath.Join(dir, "report.md")
			for _, p := range []string{out, md} {
				if e = os.WriteFile(p, []byte("keep prior report"), 0600); e != nil {
					t.Fatal(e)
				}
			}
			var stdout, stderr bytes.Buffer
			code := Run(context.Background(), []string{"drift", "report", "--before", before, "--after", after, "--output", out, "--markdown", md}, &stdout, &stderr)
			if code != 1 {
				t.Fatalf("truncated %s accepted: exit %d %s", side, code, stderr.String())
			}
			for _, p := range []string{out, md} {
				got, e := os.ReadFile(p)
				if e != nil || string(got) != "keep prior report" {
					t.Fatalf("output replaced: %s %v", got, e)
				}
			}
		})
	}
}
