package drift

import (
	"bytes"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

func TestDriftSharedPathMetadata(t *testing.T) {
	for _, scenario := range []string{"shared", "added consumer", "removed consumer"} {
		t.Run(scenario, func(t *testing.T) {
			b, a := document(t), document(t)
			if scenario != "added consumer" {
				obj(b["paths"])["/Demo/alias"] = model.Object{"$ref": "#/paths/~1Demo~1inspect"}
			}
			if scenario != "removed consumer" {
				obj(a["paths"])["/Demo/alias"] = model.Object{"$ref": "#/paths/~1Demo~1inspect"}
			}
			op(a)["description"] = "Edited shared documentation."
			before, after := build(t, b, nil), build(t, a, nil)
			var first []byte
			for i := 0; i < 40; i++ {
				r, e := Compare(before, after)
				if e != nil {
					t.Fatal(e)
				}
				found := 0
				for _, c := range r.Changes {
					if c.Category == "documentation" {
						found++
						if c.VariantID != "" {
							t.Fatalf("shared metadata must be source-global: %+v", c)
						}
					}
				}
				if found != 1 {
					t.Fatalf("surviving consumer metadata dropped or repeated: %+v", r.Changes)
				}
				raw, e := model.Encode(r)
				if e != nil {
					t.Fatal(e)
				}
				if i == 0 {
					first = raw
				} else if !bytes.Equal(first, raw) {
					t.Fatal("shared metadata comparison is nondeterministic")
				}
			}
		})
	}
}

func TestDriftRejectsIncompleteSourceBundles(t *testing.T) {
	empty := func(m *model.Map) {
		m.Documents = map[string]model.Object{}
		m.Operations = []model.Operation{}
		m.Diagnostics = []model.Diagnostic{}
	}
	cases := map[string]func(*model.Map){
		"missing OpenAPI document": empty,
		"prose has document": func(m *model.Map) {
			m.Sources[0]["kind"] = "prose"
			m.Operations = []model.Operation{}
			m.Diagnostics = []model.Diagnostic{}
		},
		"unknown kind": func(m *model.Map) { empty(m); m.Sources[0]["kind"] = "unknown" },
		"missing reference document": func(m *model.Map) {
			m.Sources = append(m.Sources, model.Object{"id": "extra", "kind": "reference", "file": "extra.json", "url": "https://example.test/extra.json", "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"})
		},
	}
	for _, field := range []string{"id", "file", "url", "sha256", "kind"} {
		for _, invalid := range []string{"missing", "empty"} {
			cases[invalid+" unreferenced "+field] = func(m *model.Map) {
				r := model.Object{"id": "extra", "kind": "prose", "file": "extra.html", "url": "https://example.test/extra.html", "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}
				if invalid == "missing" {
					delete(r, field)
				} else {
					r[field] = ""
				}
				m.Sources = append(m.Sources, r)
			}
		}
	}
	cases["invalid unreferenced hash"] = func(m *model.Map) {
		m.Sources = append(m.Sources, model.Object{"id": "extra", "kind": "prose", "file": "extra.html", "url": "https://example.test/extra.html", "sha256": "not-a-source-hash"})
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			valid := build(t, document(t), nil)
			invalid := build(t, document(t), nil)
			mutate(&invalid)
			if _, e := Compare(invalid, valid); e == nil {
				t.Fatal("invalid before source bundle accepted")
			}
			if _, e := Compare(valid, invalid); e == nil {
				t.Fatal("invalid after source bundle accepted")
			}
		})
	}
}
func TestDriftAllowsPinnedReferenceAndProseRecords(t *testing.T) {
	m := build(t, document(t), nil)
	m.Sources = append(m.Sources, model.Object{"id": "fragment", "kind": "reference", "file": "fragment.json", "url": "https://example.test/fragment.json", "sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}, model.Object{"id": "guide", "kind": "prose", "file": "guide.html", "url": "https://example.test/guide.html", "sha256": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"})
	m.Documents["fragment"] = model.Object{"type": "string"}
	if _, e := Compare(m, m); e != nil {
		t.Fatalf("valid source kinds rejected: %v", e)
	}
}
