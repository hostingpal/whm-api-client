package openapi

import (
	"encoding/json"
	"os"
	"reflect"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func document(t *testing.T, text string) model.Object {
	t.Helper()
	var d model.Object
	dec := json.NewDecoder(strings.NewReader(text))
	dec.UseNumber()
	if err := dec.Decode(&d); err != nil {
		t.Fatal(err)
	}
	return d
}
func bundle(d model.Object) source.Bundle {
	return source.Bundle{Documents: map[string]model.Object{"test": d}, Records: []model.Object{{"id": "test", "kind": "openapi", "file": "test.json", "url": "https://example.test/test.json", "sha256": strings.Repeat("a", 64)}}}
}
func base(t *testing.T, paths string) model.Object {
	return document(t, `{"openapi":"3.0.3","info":{"title":"Test","version":"1"},"paths":`+paths+`}`)
}
func hasCode(a Analysis, code string) int {
	n := 0
	for _, d := range a.Diagnostics {
		if d.Code == code {
			n++
		}
	}
	return n
}
func TestResolve(t *testing.T) {
	d := document(t, `{"a/b":{"~key":[null,{"n":9007199254740993}]}}`)
	idx, err := NewBundleIndex(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	r, err := idx.Resolve("test", "#/a~1b/~0key/1/n")
	if err != nil {
		t.Fatal(err)
	}
	if r.Value != json.Number("9007199254740993") || r.Origin.Pointer == nil || *r.Origin.Pointer != "/a~1b/~0key/1/n" || r.Origin.SourceSHA256 != strings.Repeat("a", 64) {
		t.Fatalf("resolved: %#v", r)
	}
	for _, ref := range []string{"#/missing", "#/a~2b", "https://remote.test/spec.json#/schema"} {
		if _, err := idx.Resolve("test", ref); err == nil {
			t.Fatalf("accepted %q", ref)
		}
	}
}
func TestInheritance(t *testing.T) {
	d := base(t, `{"/Mixed/Case":{"parameters":[{"name":"same","in":"query","schema":{"type":"string"}},{"name":"same","in":"header","schema":{"type":"string"}}],"servers":[{"url":"https://path.test"}],"get":{"parameters":[{"name":"same","in":"query","schema":{"type":"integer"}}],"security":[],"responses":{"200":{"description":"ok"}}},"post":{"servers":[],"responses":{"200":{"description":"ok"}}}},"/root":{"get":{"responses":{"200":{"description":"ok"}}}}}`)
	d["security"] = []any{map[string]any{"token": []any{}}}
	d["servers"] = []any{map[string]any{"url": "https://root.test"}}
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if len(a.Candidates) != 3 {
		t.Fatalf("candidates: %d", len(a.Candidates))
	}
	g := a.Candidates[0]
	if g.Path != "/Mixed/Case" || g.Method != "get" || len(g.Parameters) != 2 || g.Security.Level != "operation" || !reflect.DeepEqual(g.Security.Value, []any{}) || g.Servers.Level != "path" {
		t.Fatalf("get: %#v", g)
	}
	if g.Parameters[1].Definition["schema"].(map[string]any)["type"] != "integer" {
		t.Fatalf("override lost: %#v", g.Parameters)
	}
	if a.Candidates[1].Servers.Level != "operation" || a.Candidates[2].Servers.Level != "root" {
		t.Fatal("server precedence lost")
	}
}
func TestMalformedContracts(t *testing.T) {
	tests := []string{
		`{"/x":{"get":{"parameters":[{"name":"n","in":"query"},{"name":"n","in":"query"}],"responses":{"200":{"description":"ok"}}}}}`,
		`{"/x":{"parameters":[{"name":"n","in":"query"},{"name":"n","in":"query"}],"get":{"responses":{"200":{"description":"ok"}}}}}`,
		`{"/a":{"$ref":"#/paths/~1b"},"/b":{"$ref":"#/paths/~1a"}}`,
		`{"/x":{"get":false}}`, `{"/x":{"get":{}}}`, `{"/x":{"get":{"responses":[]}}}`,
		`{"/x":{"get":{"parameters":{},"responses":{"200":{"description":"ok"}}}}}`,
	}
	for _, p := range tests {
		t.Run(p, func(t *testing.T) {
			if _, err := Analyze(bundle(base(t, p))); err == nil {
				t.Fatal("accepted malformed contract")
			}
		})
	}
	d := base(t, `{}`)
	d["openapi"] = "3.1.0"
	if _, err := Analyze(bundle(d)); err == nil {
		t.Fatal("accepted unsupported dialect")
	}
	d = base(t, `{}`)
	delete(d, "info")
	if _, err := Analyze(bundle(d)); err == nil {
		t.Fatal("accepted missing required info")
	}
}
func TestAnalyzeDocumentContracts(t *testing.T) {
	for _, family := range []string{"uapi", "whm1"} {
		t.Run(family, func(t *testing.T) {
			raw, err := os.ReadFile("testdata/" + family + ".json")
			if err != nil {
				t.Fatal(err)
			}
			d := document(t, string(raw))
			a, err := Analyze(bundle(d))
			if err != nil {
				t.Fatal(err)
			}
			if len(a.Candidates) != 1 || hasCode(a, "UNRESOLVED_REF") != 0 {
				t.Fatalf("analysis: %#v", a)
			}
			c := a.Candidates[0]
			if family == "uapi" {
				if hasCode(a, "SCHEMA_CYCLE") != 1 || len(a.DiscriminatorTargets) != 3 {
					t.Fatalf("cycle=%d targets=%d", hasCode(a, "SCHEMA_CYCLE"), len(a.DiscriminatorTargets))
				}
				if len(c.Parameters) != 1 || c.Parameters[0].In != "query" || c.Parameters[0].Definition["content"] == nil || c.Operation["requestBody"] != nil {
					t.Fatal("Batch query content/body changed")
				}
				schemas := d["components"].(map[string]any)["schemas"].(map[string]any)
				schemas["Payload"].(map[string]any)["items"].(map[string]any)["discriminator"].(map[string]any)["mapping"].(map[string]any)["missing"] = "#/components/schemas/absent"
				bad, err := Analyze(bundle(d))
				if err == nil || hasCode(bad, "UNRESOLVED_DISCRIMINATOR") != 1 {
					t.Fatal("missing discriminator not an error")
				}
			} else {
				if hasCode(a, "GET_BODY") != 1 || c.Operation["requestBody"] == nil {
					t.Fatal("GET body lost")
				}
			}
		})
	}
}
func TestDataPositionsAndRetainedPaths(t *testing.T) {
	d := base(t, `{"x-map":{"$ref":"#/not-an-api-ref"},"/empty":{},"/x":{"get":{"responses":{"200":{"description":"ok","content":{"application/json":{"schema":{"type":"object","properties":{"x-frame-options":{"type":"string"},"$ref":{"type":"string"}},"example":{"$ref":"#/raw-data","discriminator":{"mapping":{"a":"#/raw-data"}}},"default":{"$ref":"#/raw-default"}}}}}}}}}`)
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if len(a.References) != 0 || len(a.DiscriminatorTargets) != 0 || len(a.Candidates) != 1 {
		t.Fatal("data mistaken for control declarations")
	}
	if len(d["paths"].(map[string]any)) != 3 {
		t.Fatal("original paths mutated")
	}
}
func TestPinnedInputs(t *testing.T) {
	b, err := source.Load("../../../../docs/api/sources/2026-10-13/manifest.json", source.Limits{})
	if err != nil {
		t.Fatal(err)
	}
	a, err := Analyze(b)
	if err != nil {
		t.Fatal(err)
	}
	for _, d := range a.Diagnostics {
		if d.Code == "SCHEMA_CYCLE" {
			t.Log(d.Locator.SourceID, *d.Locator.Pointer)
		}
	}
	counts := map[string]int{}
	for _, r := range a.References {
		counts[r.Origin.SourceID]++
	}
	if counts["whm-openapi"] != 278 || counts["cpanel-openapi"] != 81 {
		t.Fatalf("refs: %v", counts)
	}
	if len(a.Candidates) != 1325 || hasCode(a, "SCHEMA_CYCLE") != 2 || hasCode(a, "UNRESOLVED_REF") != 0 {
		t.Fatalf("ops=%d cycle=%d diagnostics=%#v", len(a.Candidates), hasCode(a, "SCHEMA_CYCLE"), a.Diagnostics)
	}
}

// Catch incorrect source-relative URL resolution, and invented parsed hashes.
func TestBundleReferenceIdentity(t *testing.T) {
	b := bundle(base(t, `{}`))
	b.Records[0]["url"] = "https://example.test/specs/root.json"
	b.Records = append(b.Records, model.Object{"id": "other", "file": "other.json", "kind": "reference", "url": "https://example.test/specs/child.json", "sha256": strings.Repeat("b", 64)})
	b.Documents["other"] = document(t, `{"schemas":{"payload":{"type":"string"}}}`)
	i, err := NewBundleIndex(b)
	if err != nil {
		t.Fatal(err)
	}
	r, err := i.Resolve("test", "child.json#/schemas/payload")
	if err != nil {
		t.Fatal(err)
	}
	if r.Origin.SourceID != "other" || r.Origin.SourceSHA256 != strings.Repeat("b", 64) {
		t.Fatal("wrong source provenance")
	}
	local, err := NewIndex(map[string]model.Object{"test": b.Documents["test"]})
	if err != nil {
		t.Fatal(err)
	}
	r, err = local.Resolve("test", "#/paths")
	if err != nil || r.Origin.SourceSHA256 != "" {
		t.Fatal("invented source-byte hash")
	}
}
func TestInvalidReferenceContracts(t *testing.T) {
	for _, components := range []string{
		`{"schemas":{"S":{"$ref":"#/components/schemas/missing"}}}`,
		`{"schemas":{"S":{"$ref":false}}}`,
		`{"schemas":{"S":{"discriminator":{"propertyName":"kind","mapping":{"a":"#/info/title"}}}}}`,
		`{"parameters":{"p":{"$ref":"#/components/parameters/p"}}}`,
	} {
		t.Run(components, func(t *testing.T) {
			d := base(t, `{}`)
			d["components"] = document(t, components)
			if _, err := Analyze(bundle(d)); err == nil {
				t.Fatal("accepted invalid reference")
			}
		})
	}
}
func TestResponseMapExtensionsAreData(t *testing.T) {
	d := base(t, `{"/x":{"get":{"responses":{"x-extra":{"$ref":"#/raw-data"},"200":{"description":"ok"}}}}}`)
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if len(a.References) != 0 {
		t.Fatal("response extension became a reference")
	}
}
func TestMalformedEffectiveDeclarations(t *testing.T) {
	for _, field := range []string{"security", "servers"} {
		d := base(t, `{"/x":{"get":{"responses":{"200":{"description":"ok"}}}}}`)
		d[field] = false
		if _, err := Analyze(bundle(d)); err == nil {
			t.Fatalf("accepted non-array %s", field)
		}
	}
}

func TestRequiredDeclarationStructures(t *testing.T) {
	cases := []string{
		`{"/x":{"get":{"parameters":[{"name":"p","in":"query"}],"responses":{"200":{"description":"ok"}}}}}`,
		`{"/x":{"get":{"parameters":[{"name":"p","in":"query","schema":{},"content":{"text/plain":{"schema":{}}}}],"responses":{"200":{"description":"ok"}}}}}`,
		`{"/x":{"get":{"parameters":[{"name":"p","in":"path","schema":{}}],"responses":{"200":{"description":"ok"}}}}}`,
		`{"/x":{"get":{"requestBody":{},"responses":{"200":{"description":"ok"}}}}}`,
		`{"/x":{"get":{"servers":[{}],"responses":{"200":{"description":"ok"}}}}}`,
		`{"/x":{"get":{"security":[{"token":false}],"responses":{"200":{"description":"ok"}}}}}`,
		`{"/x":{"servers":false,"get":{"servers":[],"responses":{"200":{"description":"ok"}}}}}`,
	}
	for _, p := range cases {
		t.Run(p, func(t *testing.T) {
			if _, err := Analyze(bundle(base(t, p))); err == nil {
				t.Fatal("accepted malformed required structure")
			}
		})
	}
}
func TestReferencedPathItemRetainsDeclarationProvenance(t *testing.T) {
	d := base(t, `{"/a":{"$ref":"#/paths/~1b"},"/b":{"parameters":[{"$ref":"#/components/parameters/p"}],"get":{"responses":{"200":{"description":"ok"}}}}}`)
	d["components"] = document(t, `{"parameters":{"p":{"name":"q","in":"query","schema":{"type":"string"}}}}`)
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	c := a.Candidates[0]
	if c.Path != "/a" || *c.PathItemLocator.Pointer != "/paths/~1a" || *c.OperationLocator.Pointer != "/paths/~1b/get" || *c.Parameters[0].Locator.Pointer != "/components/parameters/p" {
		t.Fatalf("wrong declaration provenance: %#v", c)
	}
}
func TestTraversalBound(t *testing.T) {
	d := base(t, `{}`)
	schema := map[string]any{"type": "string"}
	for n := 0; n < 270; n++ {
		schema = map[string]any{"items": schema}
	}
	d["components"] = map[string]any{"schemas": map[string]any{"deep": schema}}
	a, err := Analyze(bundle(d))
	if err == nil || hasCode(a, "DEPTH_LIMIT") == 0 {
		t.Fatal("unbounded schema traversal")
	}
}

func TestSourceSchemaPropertyIsData(t *testing.T) {
	raw, err := os.ReadFile("testdata/x-frame-schema.json")
	if err != nil {
		t.Fatal(err)
	}
	schema := document(t, string(raw))
	d := base(t, `{}`)
	d["components"] = map[string]any{"schemas": map[string]any{"extracted": schema}}
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if hasCode(a, "UNINTERPRETED_FIELD") != 0 || schema["properties"].(map[string]any)["x-frame-options"] == nil {
		t.Fatal("data property classified as extension")
	}
}
func TestLiteralValuesPreservePresence(t *testing.T) {
	d := base(t, `{"/x":{"get":{"parameters":[{"name":"p","in":"query","schema":{"type":"string","default":null,"nullable":false}}],"responses":{"200":{"description":"ok"}}}}}`)
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	schema := a.Candidates[0].Parameters[0].Definition["schema"].(map[string]any)
	v, present := schema["default"]
	if !present || v != nil || schema["nullable"] != false {
		t.Fatal("literal values lost")
	}
	if _, present := schema["example"]; present {
		t.Fatal("invented absent value")
	}
	if a.Candidates[0].Security.Present || a.Candidates[0].Security.Level != "none" {
		t.Fatal("invented authentication")
	}
}

func TestDottedDiscriminatorSchemaName(t *testing.T) {
	d := base(t, `{}`)
	d["components"] = document(t, `{"schemas":{"Payload":{"discriminator":{"propertyName":"kind","mapping":{"a":"A.B"}}},"A.B":{"type":"string"}}}`)
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if len(a.DiscriminatorTargets) != 1 || *a.DiscriminatorTargets[0].Target.Pointer != "/components/schemas/A.B" {
		t.Fatal("component identity lost")
	}
}
func TestBundleIndexRejectsMissingURLIdentity(t *testing.T) {
	b := bundle(base(t, `{}`))
	delete(b.Records[0], "url")
	if _, err := NewBundleIndex(b); err == nil {
		t.Fatal("accepted incomplete resolver identity")
	}
}
func TestResolveRejectsNonDocumentOrigin(t *testing.T) {
	b := bundle(base(t, `{}`))
	b.Records = append(b.Records, model.Object{"id": "prose", "file": "text.html", "url": "https://example.test/text.html", "kind": "prose"})
	idx, err := NewBundleIndex(b)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := idx.Resolve("prose", "#"); err == nil {
		t.Fatal("resolved nonexistent parsed origin")
	}
}

func TestOperationResponseDeclarationsInEveryContext(t *testing.T) {
	for _, tc := range []struct{ name, paths, code, pointer string }{
		{"extensions only", `{"/x":{"get":{"responses":{"x-extra":{"$ref":"#/raw"}}}}}`, "MALFORMED_RESPONSES", "/paths/~1x/get/responses"},
		{"invalid status", `{"/x":{"get":{"responses":{"bogus":{"description":"ok"}}}}}`, "MALFORMED_RESPONSE_KEY", "/paths/~1x/get/responses/bogus"},
		{"callback missing responses", `{"/x":{"get":{"responses":{"200":{"description":"ok"}},"callbacks":{"event":{"{$request.query.callback}":{"post":{}}}}}}}`, "MALFORMED_RESPONSES", "/paths/~1x/get/callbacks/event/{$request.query.callback}/post/responses"},
		{"callback invalid status", `{"/x":{"get":{"responses":{"200":{"description":"ok"}},"callbacks":{"event":{"{$request.query.callback}":{"post":{"responses":{"2xx":{"description":"ok"}}}}}}}}}`, "MALFORMED_RESPONSE_KEY", "/paths/~1x/get/callbacks/event/{$request.query.callback}/post/responses/2xx"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			d := base(t, tc.paths)
			a, err := Analyze(bundle(d))
			if err == nil {
				t.Fatal("accepted invalid operation responses")
			}
			if len(a.Candidates) != 1 {
				t.Fatal("failed analysis dropped original candidate")
			}
			found := false
			for _, diag := range a.Diagnostics {
				if diag.Code == tc.code && *diag.Locator.Pointer == tc.pointer && diag.Severity == "error" {
					found = true
				}
			}
			if !found {
				t.Fatalf("missing contextual error: %#v", a.Diagnostics)
			}
		})
	}
	for _, key := range []string{"default", "100", "299", "599", "1XX", "2XX", "3XX", "4XX", "5XX"} {
		t.Run(key, func(t *testing.T) {
			d := base(t, `{"/x":{"get":{"responses":{"`+key+`":{"description":"ok"},"x-extra":{"$ref":"#/raw"}}}}}`)
			if _, err := Analyze(bundle(d)); err != nil {
				t.Fatal(err)
			}
		})
	}
	for _, key := range []string{"099", "600", "2000", "2xx", "Default", "XX"} {
		t.Run(key, func(t *testing.T) {
			d := base(t, `{"/x":{"get":{"responses":{"`+key+`":{"description":"ok"}}}}}`)
			if _, err := Analyze(bundle(d)); err == nil {
				t.Fatal("accepted invalid response key")
			}
		})
	}
}

func TestReferenceObjectSiblingsRemainIgnoredData(t *testing.T) {
	d := base(t, `{"/x":{"get":{"parameters":[{"$ref":"#/components/parameters/q","name":"ignored","in":"header","schema":{"$ref":"#/missing-parameter-schema"},"x-note":{"$ref":"#/raw"}}],"responses":{"200":{"description":"ok","content":{"application/json":{"schema":{"$ref":"#/components/schemas/A","items":{"$ref":"#/missing-items"},"discriminator":{"propertyName":"kind","mapping":{"a":"#/missing-mapping"}}}}}}}}}}`)
	d["components"] = document(t, `{"schemas":{"A":{"type":"string"}},"parameters":{"q":{"name":"q","in":"query","schema":{"type":"string"}}}}`)
	before, err := model.Encode(d)
	if err != nil {
		t.Fatal(err)
	}
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if len(a.References) != 2 || len(a.DiscriminatorTargets) != 0 {
		t.Fatalf("ignored siblings became references: %#v", a)
	}
	c := a.Candidates[0]
	if len(c.Parameters) != 1 || c.Parameters[0].Name != "q" || c.Parameters[0].In != "query" || *c.Parameters[0].Locator.Pointer != "/components/parameters/q" {
		t.Fatalf("incorrect effective parameter: %#v", c.Parameters)
	}
	after, err := model.Encode(d)
	if err != nil {
		t.Fatal(err)
	}
	if string(before) != string(after) {
		t.Fatal("reference sibling data mutated")
	}
}
func TestPathItemReferenceSiblingsRemainAmbiguous(t *testing.T) {
	d := base(t, `{"/a":{"$ref":"#/paths/~1b","summary":"ambiguous"},"/b":{"get":{"responses":{"200":{"description":"ok"}}}}}`)
	a, err := Analyze(bundle(d))
	if err == nil || hasCode(a, "INVALID_PATH_ITEM_REF") != 1 {
		t.Fatal("accepted ambiguous Path Item reference sibling")
	}
}

func TestSchemaReferenceSiblingsAreNotTargets(t *testing.T) {
	d := base(t, `{}`)
	d["components"] = document(t, `{"schemas":{"A":{"type":"string"},"B":{"$ref":"#/components/schemas/A","items":{"$ref":"#/missing"},"discriminator":{"propertyName":"kind","mapping":{"a":"#/missing"}}}}}`)
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if len(a.References) != 1 || len(a.DiscriminatorTargets) != 0 {
		t.Fatal("ignored schema sibling treated as control field")
	}
}

func TestComponentNamesBeginningWithXAreIdentities(t *testing.T) {
	d := base(t, `{"/x":{"get":{"responses":{"200":{"description":"ok"}},"callbacks":{"x-event":{"$ref":"#/components/callbacks/x-callback"}}}}}`)
	d["components"] = document(t, `{"schemas":{"S":{"type":"string"}},"responses":{"x-response":{"description":"ok","content":{"application/json":{"schema":{"$ref":"#/components/schemas/S"}}}}},"callbacks":{"x-callback":{"x-note":{"$ref":"#/raw-extension"},"{$request.query.callback}":{"post":{"responses":{"200":{"description":"ok","content":{"application/json":{"schema":{"$ref":"#/components/schemas/S"}}}}}}}}}}`)
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if len(a.References) != 3 {
		t.Fatalf("component declarations dropped: %#v", a.References)
	}
	components := d["components"].(model.Object)
	components["responses"].(map[string]any)["x-response"] = map[string]any{}
	bad, err := Analyze(bundle(d))
	if err == nil || hasCode(bad, "MALFORMED_RESPONSE") != 1 {
		t.Fatal("x-named response component skipped validation")
	}
	components["responses"].(map[string]any)["x-response"] = map[string]any{"description": "ok"}
	callback := components["callbacks"].(map[string]any)["x-callback"].(map[string]any)["{$request.query.callback}"].(map[string]any)
	callback["post"] = map[string]any{}
	bad, err = Analyze(bundle(d))
	if err == nil || hasCode(bad, "MALFORMED_RESPONSES") != 1 {
		t.Fatal("x-named callback component skipped validation")
	}
}

func TestOperationRefCannotBypassRequiredResponses(t *testing.T) {
	for _, tc := range []struct {
		name, paths, pointer string
		count                int
	}{
		{"top-level", `{"/bad":{"get":{"$ref":"#/paths/~1valid/get"}},"/valid":{"get":{"responses":{"200":{"description":"ok"}}}}}`, "/paths/~1bad/get/responses", 2},
		{"callback", `{"/valid":{"get":{"responses":{"200":{"description":"ok"}},"callbacks":{"event":{"{$request.query.callback}":{"post":{"$ref":"#/paths/~1valid/get"}}}}}}}`, "/paths/~1valid/get/callbacks/event/{$request.query.callback}/post/responses", 1},
	} {
		t.Run(tc.name, func(t *testing.T) {
			d := base(t, tc.paths)
			a, err := Analyze(bundle(d))
			if err == nil {
				t.Fatal("unsupported operation ref bypassed required responses")
			}
			if len(a.Candidates) != tc.count || len(a.References) != 0 {
				t.Fatal("unsupported operation ref became normative or candidates dropped")
			}
			found := false
			for _, diag := range a.Diagnostics {
				if diag.Code == "MALFORMED_RESPONSES" && *diag.Locator.Pointer == tc.pointer {
					found = true
				}
			}
			if !found {
				t.Fatal("original operation not validated")
			}
		})
	}
}
func TestUnsupportedRefsRemainRawInNonReferenceContexts(t *testing.T) {
	d := base(t, `{"/x":{"get":{"$ref":"#/unresolved-operation-data","responses":{"200":{"description":"ok","content":{"application/json":{"$ref":"#/unresolved-media-data","schema":{"$ref":"#/components/schemas/S"},"encoding":{"p":{"$ref":"#/unresolved-encoding-data"}}}}}}}}}`)
	d["$ref"] = "#/unresolved-root-data"
	d["components"] = document(t, `{"schemas":{"S":{"type":"string"}}}`)
	before, err := model.Encode(d)
	if err != nil {
		t.Fatal(err)
	}
	a, err := Analyze(bundle(d))
	if err != nil {
		t.Fatal(err)
	}
	if len(a.Candidates) != 1 || len(a.References) != 1 || a.References[0].Ref != "#/components/schemas/S" || hasCode(a, "UNINTERPRETED_FIELD") != 4 {
		t.Fatalf("unsupported refs acquired semantics: %#v", a)
	}
	after, err := model.Encode(d)
	if err != nil {
		t.Fatal(err)
	}
	if string(before) != string(after) {
		t.Fatal("unsupported fields mutated")
	}
}
