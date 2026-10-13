package contract

import (
	"bytes"
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"os"
	"testing"
)

func ptr(s string) *string { return &s }
func fixture() (model.Map, model.Variant) {
	schema := model.Object{"type": "object", "description": "prose", "properties": model.Object{"description": model.Object{"type": "string"}, "next": model.Object{"$ref": "#/components/schemas/Node"}}, "default": model.Object{"description": "data", "$ref": "literal"}}
	op := model.Object{"parameters": []any{model.Object{"name": "x", "in": "query", "schema": model.Object{"type": "string"}}, model.Object{"name": "x", "in": "header", "schema": model.Object{"type": "string"}}}, "requestBody": model.Object{"content": model.Object{"application/json": model.Object{"schema": model.Object{"$ref": "#/components/schemas/Node"}}, "text/plain": model.Object{"schema": model.Object{"type": "string"}}}}, "responses": model.Object{"200": model.Object{"description": "ok", "content": model.Object{"application/json": model.Object{"schema": model.Object{"$ref": "#/components/schemas/Node"}}}}, "400": model.Object{"description": "bad", "content": model.Object{"application/json": model.Object{"schema": model.Object{"type": "string"}}}}, "204": model.Object{"description": "empty"}}}
	m := model.Map{Sources: []model.Object{{"id": "s", "file": "s.json", "url": "https://example.test/s.json", "sha256": "hash"}}, Documents: map[string]model.Object{"s": {"paths": model.Object{"/M/f": model.Object{"get": op}}, "components": model.Object{"schemas": model.Object{"Node": schema}}}}}
	v := model.Variant{ID: "uapi:M/f#GET", OperationLocator: model.Locator{SourceID: "s", SourceSHA256: "hash", Pointer: ptr("/paths/~1M~1f/get")}}
	for i, p := range op["parameters"].([]any) {
		o := p.(model.Object)
		suffix := "0"
		if i == 1 {
			suffix = "1"
		}
		v.Parameters = append(v.Parameters, model.Parameter{Name: "x", In: o["in"].(string), Definition: o, Locator: model.Locator{SourceID: "s", SourceSHA256: "hash", Pointer: ptr("/paths/~1M~1f/get/parameters/" + suffix)}})
	}
	return m, v
}
func TestUnitsIndependentDenominators(t *testing.T) {
	m, v := fixture()
	u, d, e := AnalyzeUnits(m, v)
	if e != nil {
		t.Fatal(e)
	}
	counts := map[string]int{}
	for _, x := range u {
		counts[x.Kind]++
	}
	if counts["parameter"] != 2 || counts["body"] != 2 || counts["response"] != 2 || len(d) != 1 {
		t.Fatalf("units=%v diagnostics=%v", counts, d)
	}
	if u[0].ID == u[1].ID {
		t.Fatal("collision")
	}
}
func TestHashReferencesCyclesAndMetadata(t *testing.T) {
	m, _ := fixture()
	l := model.Locator{SourceID: "s", Pointer: ptr("/components/schemas/Node")}
	h, e := Hash(m, l)
	if e != nil {
		t.Fatal(e)
	}
	s := m.Documents["s"]["components"].(model.Object)["schemas"].(model.Object)["Node"].(model.Object)
	s["description"] = "new prose"
	s["example"] = model.Object{"$ref": "not a reference"}
	h2, e := Hash(m, l)
	if e != nil || h != h2 {
		t.Fatalf("metadata changed hash %v", e)
	}
	s["properties"].(model.Object)["description"].(model.Object)["type"] = "number"
	h3, _ := Hash(m, l)
	if h3 == h {
		t.Fatal("schema property edit ignored")
	}
	s["default"].(model.Object)["description"] = "new data"
	h4, _ := Hash(m, l)
	if h4 == h3 {
		t.Fatal("default data ignored")
	}
	_, v := fixture()
	before, _ := Hash(m, model.Locator{SourceID: "s", Pointer: ptr(*v.OperationLocator.Pointer + "/responses/200/content/application~1json")})
	s["required"] = []any{"description"}
	after, _ := Hash(m, model.Locator{SourceID: "s", Pointer: ptr(*v.OperationLocator.Pointer + "/responses/200/content/application~1json")})
	if before == after {
		t.Fatal("transitive contract edit ignored")
	}
}

func TestBodyRequiredChangesContract(t *testing.T) {
	m, v := fixture()
	a, err := Units(m, v)
	if err != nil {
		t.Fatal(err)
	}
	op := m.Documents["s"]["paths"].(model.Object)["/M/f"].(model.Object)["get"].(model.Object)
	op["requestBody"].(model.Object)["required"] = true
	b, err := Units(m, v)
	if err != nil {
		t.Fatal(err)
	}
	for i, u := range a {
		if u.Kind == "body" && u.Hash == b[i].Hash {
			t.Fatal("body requiredness omitted from hash")
		}
	}
}
func TestReferenceContextAndDiscriminator(t *testing.T) {
	m, v := fixture()
	op := m.Documents["s"]["paths"].(model.Object)["/M/f"].(model.Object)["get"].(model.Object)
	op["$ref"] = "unsupported raw field"
	if _, err := Hash(m, v.OperationLocator); err != nil {
		t.Fatalf("operation raw ref resolved: %v", err)
	}
	schemas := m.Documents["s"]["components"].(model.Object)["schemas"].(model.Object)
	schemas["Other"] = model.Object{"type": "string"}
	node := schemas["Node"].(model.Object)
	node["discriminator"] = model.Object{"propertyName": "kind", "mapping": model.Object{"other": "Other"}}
	l := model.Locator{SourceID: "s", Pointer: ptr("/components/schemas/Node")}
	a, err := Hash(m, l)
	if err != nil {
		t.Fatal(err)
	}
	schemas["Other"].(model.Object)["type"] = "number"
	b, _ := Hash(m, l)
	if a == b {
		t.Fatal("discriminator target ignored")
	}
	schemas["Alias"] = model.Object{"$ref": "#/components/schemas/Node", "properties": model.Object{"bad": model.Object{"$ref": "#/missing"}}}
	if _, err = Hash(m, model.Locator{SourceID: "s", Pointer: ptr("/components/schemas/Alias")}); err != nil {
		t.Fatalf("ignored ref siblings traversed: %v", err)
	}
}

func TestSourceWildcardIsOneDeclaration(t *testing.T) {
	raw, err := os.ReadFile("testdata/wildcard-parameter.json")
	if err != nil {
		t.Fatal(err)
	}
	var fixture struct {
		Definition model.Object  `json:"definition"`
		Provenance model.Locator `json:"provenance"`
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err = decoder.Decode(&fixture); err != nil {
		t.Fatal(err)
	}
	if fixture.Provenance.SourceSHA256 != "172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc" {
		t.Fatal("fixture provenance changed")
	}
	m, v := fixtureMapParameter(fixture.Definition)
	u, err := Units(m, v)
	if err != nil {
		t.Fatal(err)
	}
	if len(u) != 1 || u[0].ID != `["uapi:M/f#GET","parameter","filter*","query"]` {
		t.Fatalf("wildcard expanded: %+v", u)
	}
}
func fixtureMapParameter(p model.Object) (model.Map, model.Variant) {
	m, v := fixture()
	op := m.Documents["s"]["paths"].(model.Object)["/M/f"].(model.Object)["get"].(model.Object)
	delete(op, "requestBody")
	delete(op, "responses")
	op["parameters"] = []any{p}
	v.Parameters = v.Parameters[:1]
	v.Parameters[0].Name = p["name"].(string)
	v.Parameters[0].In = p["in"].(string)
	v.Parameters[0].Definition = p
	return m, v
}
func TestTupleIDsAvoidDelimiterCollisions(t *testing.T) {
	if ID("v", "parameter", "a,b", "c") == ID("v", "parameter", "a", "b,c") {
		t.Fatal("tuple collision")
	}
}

func TestResponsesExtensionsDoNotResolveRawReferences(t *testing.T) {
	m, v := fixture()
	op := m.Documents["s"]["paths"].(model.Object)["/M/f"].(model.Object)["get"].(model.Object)
	op["responses"].(model.Object)["x-data"] = model.Object{"$ref": "#/missing", "description": "literal"}
	if _, err := Hash(m, v.OperationLocator); err != nil {
		t.Fatalf("response extension treated as reference: %v", err)
	}
}
func TestPathReferenceSiblingsAreRejected(t *testing.T) {
	m, v := fixture()
	p := m.Documents["s"]["paths"].(model.Object)["/M/f"].(model.Object)
	p["$ref"] = "#/paths/~1M~1f"
	l := v.OperationLocator
	l.Pointer = ptr("/paths/~1M~1f")
	if _, err := Hash(m, l); err == nil {
		t.Fatal("ambiguous path item siblings accepted")
	}
}
