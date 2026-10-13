package schema

import (
	"bytes"
	"encoding/json"
	"fmt"
	"github.com/santhosh-tekuri/jsonschema/v6"
	"io/fs"
	"net/http"
	"os"
	"path/filepath"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/testutil"
)

func fixture(t *testing.T) map[string]any {
	t.Helper()
	d := json.NewDecoder(bytes.NewReader(testutil.Read(t, "../model/testdata/map-valid.json")))
	d.UseNumber()
	var m map[string]any
	if err := d.Decode(&m); err != nil {
		t.Fatal(err)
	}
	return m
}
func firstVariant(m map[string]any) map[string]any {
	return m["operations"].([]any)[0].(map[string]any)["variants"].([]any)[0].(map[string]any)
}
func TestMapSchemaAcceptsRetainedOpenAPIDialect(t *testing.T) {
	if err := Validate("api-map-v1.schema.json", fixture(t)); err != nil {
		t.Fatal(err)
	}
}
func TestMapSchemaRejectsInvalidShape(t *testing.T) {
	tests := map[string]func(map[string]any){
		"unknown normalized key": func(m map[string]any) { m["extra"] = true },
		"unknown variant key":    func(m map[string]any) { firstVariant(m)["extra"] = true },
		"invalid family":         func(m map[string]any) { m["operations"].([]any)[0].(map[string]any)["family"] = "legacy" },
		"invalid method":         func(m map[string]any) { firstVariant(m)["method"] = "get" },
		"invalid risk":           func(m map[string]any) { firstVariant(m)["risk"].(map[string]any)["classification"] = "safe" },
		"empty variants":         func(m map[string]any) { m["operations"].([]any)[0].(map[string]any)["variants"] = []any{} },
		"locator neither":        func(m map[string]any) { delete(firstVariant(m)["operation_locator"].(map[string]any), "pointer") },
		"locator both": func(m map[string]any) {
			firstVariant(m)["operation_locator"].(map[string]any)["prose_locator"] = "paragraph 1"
		},
		"absent with locator": func(m map[string]any) {
			firstVariant(m)["servers"].(map[string]any)["locator"] = firstVariant(m)["operation_locator"]
		},
		"present without locator": func(m map[string]any) { firstVariant(m)["security"].(map[string]any)["locator"] = nil },
	}
	for name, mutate := range tests {
		t.Run(name, func(t *testing.T) {
			m := fixture(t)
			mutate(m)
			if err := Validate("api-map-v1.schema.json", m); err == nil {
				t.Fatal("accepted invalid normalized shape")
			}
		})
	}
}
func TestSchemaLoaderNeverFetchesRemote(t *testing.T) {
	requests := 0
	original := http.DefaultTransport
	http.DefaultTransport = roundTripFunc(func(*http.Request) (*http.Response, error) { requests++; return nil, fmt.Errorf("unexpected request") })
	t.Cleanup(func() { http.DefaultTransport = original })
	compiler := jsonschema.NewCompiler()
	compiler.UseLoader(embeddedLoader{})
	if err := compiler.AddResource("https://example.com/root.json", map[string]any{"$ref": "https://example.com/remote.json"}); err != nil {
		t.Fatal(err)
	}
	if _, err := compiler.Compile("https://example.com/root.json"); err == nil {
		t.Fatal("accepted external schema reference")
	}
	if requests != 0 {
		t.Fatalf("schema loader sent %d HTTP requests", requests)
	}

	for _, name := range []string{"https://example.com/schema.json", "missing.schema.json"} {
		if err := Validate(name, fixture(t)); err == nil {
			t.Fatalf("accepted unregistered schema %s", name)
		}
	}
	if _, err := (embeddedLoader{}).Load("https://example.com/unknown.schema.json"); err == nil {
		t.Fatal("loader allowed remote schema")
	}
}
func TestEmbeddedSchemasMatchPublished(t *testing.T) {
	published, err := filepath.Glob("../../../../docs/api/schema/*.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	if len(published) == 0 {
		t.Fatal("no published schemas")
	}
	embedded, err := fs.Glob(assets, "assets/*.schema.json")
	if err != nil {
		t.Fatal(err)
	}
	if len(embedded) != len(published) {
		t.Fatalf("embedded %d schemas, published %d", len(embedded), len(published))
	}
	for _, path := range published {
		want, err := os.ReadFile(path)
		if err != nil {
			t.Fatal(err)
		}
		got, err := assets.ReadFile("assets/" + filepath.Base(path))
		if err != nil {
			t.Fatal(err)
		}
		if !bytes.Equal(want, got) {
			t.Fatalf("embedded schema differs: %s", path)
		}
	}
}
func TestAnnotationsWrapper(t *testing.T) {
	loc := map[string]any{"source_id": "synthetic", "source_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "pointer": ""}
	valid := map[string]any{"schema_version": "1.0", "annotations": []any{map[string]any{"id": "synthetic-note", "scope": "family", "target_id": "uapi", "kind": "supplement", "conclusion": "synthetic", "rationale": "Synthetic example only.", "evidence": []any{loc}}}, "aliases": []any{map[string]any{"alias_id": "uapi:Demo/old", "target_id": "uapi:Demo/inspect", "reason": "Synthetic alias only.", "evidence": []any{loc}}}}
	if err := Validate("annotations-v1.schema.json", valid); err != nil {
		t.Fatal(err)
	}
	valid["annotations"].([]any)[0].(map[string]any)["evidence"] = []any{}
	if err := Validate("annotations-v1.schema.json", valid); err == nil {
		t.Fatal("accepted annotation without source evidence")
	}
}

func TestMapSchemaRejectsUnknownNestedNormalizedKeys(t *testing.T) {
	for _, name := range []string{"operation", "locator", "parameter", "effective", "risk"} {
		t.Run(name, func(t *testing.T) {
			m := fixture(t)
			v := firstVariant(m)
			var object map[string]any
			switch name {
			case "operation":
				object = m["operations"].([]any)[0].(map[string]any)
			case "locator":
				object = v["operation_locator"].(map[string]any)
			case "parameter":
				object = v["parameters"].([]any)[0].(map[string]any)
			case "effective":
				object = v["security"].(map[string]any)
			case "risk":
				object = v["risk"].(map[string]any)
			}
			object["unexpected"] = true
			if err := Validate("api-map-v1.schema.json", m); err == nil {
				t.Fatal("accepted extra normalized field")
			}
		})
	}
}

func TestMapSchemaPreservesExplicitNullAndRootPointer(t *testing.T) {
	m := fixture(t)
	security := firstVariant(m)["security"].(map[string]any)
	security["value"] = nil
	security["level"] = "root"
	security["locator"].(map[string]any)["pointer"] = ""
	if err := Validate("api-map-v1.schema.json", m); err != nil {
		t.Fatal(err)
	}
}

func TestMapSchemaRejectsUnattributedRisk(t *testing.T) {
	for _, classification := range []string{"read-only", "mutating", "destructive"} {
		t.Run(classification, func(t *testing.T) {
			m := fixture(t)
			risk := firstVariant(m)["risk"].(map[string]any)
			risk["classification"] = classification
			switch classification {
			case "read-only":
				risk["destructive_potential"] = "no"
			case "destructive":
				risk["destructive_potential"] = "yes"
			}
			if err := Validate("api-map-v1.schema.json", m); err == nil {
				t.Fatal("accepted classification without evidence")
			}
		})
	}
}

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(request *http.Request) (*http.Response, error) { return f(request) }
