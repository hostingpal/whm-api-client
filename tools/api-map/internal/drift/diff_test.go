package drift

import (
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"os"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/mapbuild"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func obj(v any) model.Object {
	switch o := v.(type) {
	case model.Object:
		return o
	case map[string]any:
		return model.Object(o)
	}
	return nil
}
func document(t *testing.T) model.Object {
	t.Helper()
	raw, e := os.ReadFile("testdata/document.json")
	if e != nil {
		t.Fatal(e)
	}
	var d model.Object
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	if e = dec.Decode(&d); e != nil {
		t.Fatal(e)
	}
	return d
}
func op(d model.Object) model.Object   { return obj(obj(obj(d["paths"])["/Demo/inspect"])["get"]) }
func node(d model.Object) model.Object { return obj(obj(obj(d["components"])["schemas"])["Node"]) }
func build(t *testing.T, d model.Object, aliases []model.Alias) model.Map {
	t.Helper()
	raw, e := model.Encode(d)
	if e != nil {
		t.Fatal(e)
	}
	hash := fmt.Sprintf("%x", sha256.Sum256(raw))
	m, e := mapbuild.Build(source.Bundle{Records: []model.Object{{"id": "synthetic", "synthetic": true, "kind": "openapi", "api_family": "cpanel-uapi", "url": "https://example.test/api.json", "file": "document.json", "sha256": hash, "version": "1", "retrieved_at": "2026-10-13T00:00:00Z"}}, Documents: map[string]model.Object{"synthetic": d}}, nil, aliases)
	if e != nil {
		t.Fatal(e)
	}
	return m
}
func TestDriftCategories(t *testing.T) {
	cases := []struct {
		name, category string
		edit           func(model.Object)
	}{
		{"identical", "", func(d model.Object) {}},
		{"description", "documentation", func(d model.Object) { op(d)["description"] = "new prose" }},
		{"example", "documentation", func(d model.Object) {
			obj(obj(op(d)["responses"])["200"])["description"] = "new response prose"
			obj(obj(obj(obj(op(d)["responses"])["200"])["content"])["application/json"])["example"] = model.Object{"description": "sample"}
		}},
		{"referenced cyclic schema enum", "response", func(d model.Object) { obj(obj(node(d)["properties"])["description"])["enum"] = []any{"new"} }},
		{"request body", "body", func(d model.Object) {
			op(d)["requestBody"] = model.Object{"content": model.Object{"application/json": model.Object{"schema": model.Object{"type": "string"}}}}
		}},
		{"absent default", "parameter", func(d model.Object) { delete(obj(obj(op(d)["parameters"].([]any)[0])["schema"]), "default") }},
		{"auth override", "auth", func(d model.Object) { op(d)["security"] = []any{} }},
		{"risk", "risk", func(d model.Object) { op(d)["x-readonly"] = false }},
		{"availability", "availability", func(d model.Object) { op(d)["x-cpanel-available-version"] = "11.140" }},
		{"rollback", "risk", func(d model.Object) { op(d)["x-rollback"] = "unsupported" }},
		{"deprecated", "deprecation", func(d model.Object) { op(d)["deprecated"] = true }},
		{"unknown extension", "uninterpreted", func(d model.Object) {
			op(d)["x-new-field"] = model.Object{"description": "literal", "$ref": "#/not-a-reference"}
		}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			before := build(t, document(t), nil)
			d := document(t)
			tc.edit(d)
			after := build(t, d, nil)
			r, e := Compare(before, after)
			if e != nil {
				t.Fatal(e)
			}
			if tc.category == "" {
				if len(r.Changes) != 0 || r.ProvenanceChanged {
					t.Fatalf("unchanged: %+v", r)
				}
				return
			}
			found := false
			for _, c := range r.Changes {
				if c.Category == tc.category {
					found = true
				}
				if (tc.category == "documentation" || tc.category == "response" || tc.category == "parameter") && c.Category != tc.category {
					t.Fatalf("prose changed contract: %+v", c)
				}
				if !c.Before.Absent && c.Before.Hash == "" || !c.After.Absent && c.After.Hash == "" {
					t.Fatalf("missing hashes: %+v", c)
				}
			}
			if !found {
				t.Fatalf("missing %s: %+v", tc.category, r)
			}
			r2, e := Compare(before, after)
			a, _ := model.Encode(r)
			b, _ := model.Encode(r2)
			if e != nil || !bytes.Equal(a, b) {
				t.Fatal("unstable cyclic comparison")
			}
		})
	}
}
func TestDriftProvenanceAndVariants(t *testing.T) {
	before := build(t, document(t), nil)
	after := build(t, document(t), nil)
	after.Sources[0]["retrieved_at"] = "2026-10-14T00:00:00Z"
	r, e := Compare(before, after)
	if e != nil || !r.ProvenanceChanged || len(r.Changes) != 0 {
		t.Fatalf("provenance: %+v %v", r, e)
	}
	d := document(t)
	obj(obj(d["paths"])["/Demo/inspect"])["post"] = model.Object{"responses": model.Object{"200": model.Object{"description": "ok"}}}
	after = build(t, d, nil)
	r, e = Compare(before, after)
	if e != nil || len(r.AddedOperations) != 0 || len(r.AddedVariants) != 1 || r.AddedVariants[0] != "uapi:Demo/inspect#POST" {
		t.Fatalf("variant addition: %+v %v", r, e)
	}
}
func TestDriftAliasesAndInvalidMaps(t *testing.T) {
	before := build(t, document(t), nil)
	d := document(t)
	paths := obj(d["paths"])
	paths["/Demo/inspect_new"] = paths["/Demo/inspect"]
	delete(paths, "/Demo/inspect")
	after := build(t, d, nil)
	r, e := Compare(before, after)
	if e != nil || len(r.AddedOperations) != 1 || len(r.RemovedOperations) != 1 || len(r.ConfirmedAliases) != 0 {
		t.Fatalf("inferred rename: %+v %v", r, e)
	}
	after.Aliases = []model.Alias{{AliasID: "uapi:Demo/inspect", TargetID: "uapi:Demo/inspect_new", Reason: "Explicit synthetic rename", Evidence: after.Operations[0].Provenance}}
	r, e = Compare(before, after)
	if e != nil || len(r.ConfirmedAliases) != 1 || len(r.RemovedOperations) != 1 || len(r.AddedOperations) != 1 {
		t.Fatalf("alias delta: %+v %v", r, e)
	}
	after.Aliases = append(after.Aliases, model.Alias{AliasID: "uapi:Demo/other", TargetID: "uapi:Demo/inspect", Reason: "cycle", Evidence: after.Operations[0].Provenance})
	after.Aliases[0].TargetID = "uapi:Demo/other"
	if _, e = Compare(before, after); e == nil {
		t.Fatal("alias cycle accepted")
	}
	after = build(t, document(t), nil)
	after.Operations[0].Variants[0].Security.Value = []any{}
	if _, e = Compare(before, after); e == nil {
		t.Fatal("inconsistent effective security accepted")
	}
	if _, e = Compare(model.Map{}, before); e == nil {
		t.Fatal("invalid map accepted")
	}
}
func TestDriftReportSchemaAndMarkdown(t *testing.T) {
	before := build(t, document(t), nil)
	d := document(t)
	op(d)["deprecated"] = true
	r, e := Compare(before, build(t, d, nil))
	if e != nil {
		t.Fatal(e)
	}
	if e = schema.Validate("drift-report-v1.schema.json", r); e != nil {
		t.Fatal(e)
	}
	md := Markdown(r)
	if !bytes.Contains(md, []byte("deprecation")) || !bytes.Contains(md, []byte("uapi:Demo/inspect#GET")) {
		t.Fatalf("missing readable change: %s", md)
	}
}

func TestDriftRawDataAndAbsence(t *testing.T) {
	for _, tc := range []struct {
		name          string
		before, after any
		afterPresent  bool
	}{
		{"explicit null removed", nil, nil, false},
		{"empty object removed", model.Object{}, nil, false},
		{"literal description", model.Object{"description": "old"}, model.Object{"description": "new"}, true},
		{"literal ref", model.Object{"$ref": "#/old"}, model.Object{"$ref": "#/new"}, true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			b := document(t)
			op(b)["x-raw"] = tc.before
			a := document(t)
			if tc.afterPresent {
				op(a)["x-raw"] = tc.after
			}
			r, e := Compare(build(t, b, nil), build(t, a, nil))
			if e != nil {
				t.Fatal(e)
			}
			if len(r.Changes) != 1 || r.Changes[0].Category != "uninterpreted" || r.Changes[0].Before.Absent || r.Changes[0].After.Absent == tc.afterPresent {
				t.Fatalf("raw/absence collapsed: %+v", r.Changes)
			}
		})
	}
}
func TestDriftSourceHashDoesNotChangeContracts(t *testing.T) {
	before := build(t, document(t), nil)
	records := []model.Object{}
	for _, s := range before.Sources {
		copy := model.Object{}
		for k, v := range s {
			copy[k] = v
		}
		copy["sha256"] = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
		records = append(records, copy)
	}
	after, e := mapbuild.Build(source.Bundle{Records: records, Documents: before.Documents}, nil, nil)
	if e != nil {
		t.Fatal(e)
	}
	r, e := Compare(before, after)
	if e != nil || len(r.Changes) != 0 || !r.ProvenanceChanged {
		t.Fatalf("source hash invented operation changes: %+v %v", r, e)
	}
}
func TestDriftEmptyRetainedSchemaAddition(t *testing.T) {
	before := build(t, document(t), nil)
	d := document(t)
	obj(obj(d["components"])["schemas"])["Unused"] = model.Object{}
	r, e := Compare(before, build(t, d, nil))
	if e != nil {
		t.Fatal(e)
	}
	if len(r.Changes) != 1 || r.Changes[0].Category != "uninterpreted" || !r.Changes[0].Before.Absent {
		t.Fatalf("empty retained object addition dropped: %+v", r.Changes)
	}
}
func TestDriftRejectsInvalidSourceRecords(t *testing.T) {
	m := build(t, document(t), nil)
	delete(m.Sources[0], "id")
	if _, e := Compare(m, m); e == nil {
		t.Fatal("invalid source accepted")
	}
}
func TestDriftSchemaRejectsAmbiguousAbsentState(t *testing.T) {
	m := build(t, document(t), nil)
	d := document(t)
	op(d)["deprecated"] = true
	r, e := Compare(m, build(t, d, nil))
	if e != nil {
		t.Fatal(e)
	}
	r.Changes[0].After.Absent = true
	if e = schema.Validate("drift-report-v1.schema.json", r); e == nil {
		t.Fatal("absent with locator/hash accepted")
	}
}

func TestDriftInfoAndServerProse(t *testing.T) {
	for _, where := range []string{"info", "server"} {
		t.Run(where, func(t *testing.T) {
			b, a := document(t), document(t)
			if where == "info" {
				obj(b["info"])["description"] = "old"
				obj(a["info"])["description"] = "new"
			} else {
				b["servers"] = []any{model.Object{"url": "https://example.test", "description": "old"}}
				a["servers"] = []any{model.Object{"url": "https://example.test", "description": "new"}}
			}
			r, e := Compare(build(t, b, nil), build(t, a, nil))
			if e != nil {
				t.Fatal(e)
			}
			if len(r.Changes) == 0 {
				t.Fatal("prose edit dropped")
			}
			for _, c := range r.Changes {
				if c.Category != "documentation" {
					t.Fatalf("prose classified as %s: %+v", c.Category, r.Changes)
				}
			}
		})
	}
}

func TestDriftRawPayloadArrayOrder(t *testing.T) {
	b, a := document(t), document(t)
	op(b)["x-raw"] = model.Object{"schema_version": "1.0", "operations": []any{model.Object{"id": "b"}, model.Object{"id": "a"}}}
	op(a)["x-raw"] = model.Object{"schema_version": "1.0", "operations": []any{model.Object{"id": "a"}, model.Object{"id": "b"}}}
	r, e := Compare(build(t, b, nil), build(t, a, nil))
	if e != nil {
		t.Fatal(e)
	}
	if len(r.Changes) != 1 || r.Changes[0].Before.Hash == r.Changes[0].After.Hash {
		t.Fatalf("raw array order was normalized away: %+v", r.Changes)
	}
}

func TestDriftMarkdownSourceProvenance(t *testing.T) {
	m := build(t, document(t), nil)
	r, e := Compare(m, m)
	if e != nil {
		t.Fatal(e)
	}
	md := Markdown(r)
	if !bytes.Contains(md, []byte(m.Sources[0]["sha256"].(string))) || !bytes.Contains(md, []byte("version 1")) {
		t.Fatalf("source provenance missing: %s", md)
	}
}

func TestDriftExamplesMatch(t *testing.T) {
	before := build(t, document(t), nil)
	changed := document(t)
	obj(obj(node(changed)["properties"])["description"])["enum"] = []any{"new"}
	op(changed)["description"] = "Updated synthetic documentation."
	for _, tc := range []struct {
		name  string
		after model.Map
	}{{"unchanged", before}, {"changed", build(t, changed, nil)}} {
		r, e := Compare(before, tc.after)
		if e != nil {
			t.Fatal(e)
		}
		if e = schema.Validate("drift-report-v1.schema.json", r); e != nil {
			t.Fatal(e)
		}
		got, e := model.Encode(r)
		if e != nil {
			t.Fatal(e)
		}
		want, e := os.ReadFile("../../../../docs/api/examples/drift/" + tc.name + ".json")
		if e != nil {
			t.Fatal(e)
		}
		if !bytes.Equal(got, want) {
			t.Fatalf("%s example is stale", tc.name)
		}
		if tc.name == "changed" {
			md, e := os.ReadFile("../../../../docs/api/examples/drift/changed.md")
			if e != nil {
				t.Fatal(e)
			}
			if !bytes.Equal(Markdown(r), md) {
				t.Fatal("Markdown example is stale")
			}
		}
	}
}
