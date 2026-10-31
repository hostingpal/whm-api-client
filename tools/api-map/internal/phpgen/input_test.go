package phpgen

import (
	"bytes"
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"os"
	"strings"
	"testing"
)

func TestJSONInputPublicGrammarAndExactSpec(t *testing.T) {
	s := effective.InputSpec{Type: "object", Fields: []effective.InputField{{Name: "changes", Spec: effective.InputSpec{Type: "list", Nonempty: true, Items: &effective.InputSpec{Type: "object", Fields: []effective.InputField{{Name: "enabled", Spec: effective.InputSpec{Type: "flag"}}, {Name: "nested", Spec: effective.InputSpec{Type: "list", Items: &effective.InputSpec{Type: "string", Nullable: true}}}}, Required: []string{"enabled"}}}}}, Required: []string{"changes"}}
	g := effective.Group{Family: "uapi", Name: "Examples", Class: "Examples", Accessor: "examples", Operations: []effective.Operation{{ID: "uapi:Examples/save", VariantID: "uapi:Examples/save#POST", Module: "Examples", Function: "save", PHPName: "save", Constant: "SAVE", Method: "POST", Body: "json", Outcome: "flag", JSONBody: &effective.JSONBody{PHP: "configuration", Alias: "ConfigurationInput", Routes: "direct", Required: true, Schema: s}, Params: []effective.Param{{PHP: "configuration", PHPType: "array", DocType: "ConfigurationInput", Required: true, Sensitive: true, Input: &s}, {PHP: "query", Wire: "q", Encoding: "scalar", PHPType: "string", DocType: "string"}}, Returns: &effective.TypeSpec{Type: "flag"}}}}
	files := Render(effective.Result{Groups: []effective.Group{g}})
	class := string(files["src/Api/Uapi/Examples.php"])
	spec := string(files["src/Api/Uapi/Spec/ExamplesSpec.php"])
	for _, want := range []string{"@phpstan-type ConfigurationInput array{", "changes: non-empty-list<array{", "enabled: bool,", "nested?: list<string|null>,", "@param ConfigurationInput $configuration", "#[SensitiveParameter]\n        array $configuration,", "?string $query = null,", "@return 0|1", "ConfigurationInput", "Direct routes only", "@throws InvalidArgumentException If the whole JSON body"} {
		if !strings.Contains(class, want) {
			t.Fatalf("missing %q in class:\n%s", want, class)
		}
	}
	for _, want := range []string{"'body' => 'json'", "'json_body' => [", "'php' => 'configuration'", "'required' => true", "'routes' => 'direct'", "'nonempty' => true", "'enabled' => ['type' => 'flag']", "'returns' => ['type' => 'flag']", "'query' => ['name' => 'q', 'encoding' => 'scalar']"} {
		if !strings.Contains(spec, want) {
			t.Fatalf("missing %q in spec:\n%s", want, spec)
		}
	}
	if strings.Contains(spec, "'configuration' => ['name'") {
		t.Fatal("body leaked into query params")
	}
	for _, tc := range []struct {
		s        effective.InputSpec
		required bool
		native   string
		wire     string
	}{
		{effective.InputSpec{Type: "object"}, false, "?array $configuration = null,", "'required' => false"},
		{effective.InputSpec{Type: "list", Items: &effective.InputSpec{Type: "string"}}, false, "?array $configuration = null,", "'type' => 'list'"},
		{effective.InputSpec{Type: "int", Nullable: true}, true, "?int $configuration,", "'nullable' => true"},
		{effective.InputSpec{Type: "null"}, true, "null $configuration,", "'type' => 'null'"},
		{effective.InputSpec{Type: "union", AnyOf: []effective.InputSpec{{Type: "string"}, {Type: "int"}}}, true, "string|int $configuration,", "'type' => 'union'"},
	} {
		op := &g.Operations[0]
		op.JSONBody.Schema = tc.s
		op.JSONBody.Required = tc.required
		op.Params[0].Input = &tc.s
		op.Params[0].PHPType = effective.InputNative(tc.s)
		op.Params[0].Required = tc.required
		f := Render(effective.Result{Groups: []effective.Group{g}})
		if !strings.Contains(string(f["src/Api/Uapi/Examples.php"]), tc.native) || !strings.Contains(string(f["src/Api/Uapi/Spec/ExamplesSpec.php"]), tc.wire) {
			t.Fatalf("incorrect native/spec for %+v", tc)
		}
	}
}

func TestJSONVirtualStatsConfigurationSourceToGeneratedInput(t *testing.T) {
	raw, err := os.ReadFile("../effective/testdata/fixture-map.json")
	if err != nil {
		t.Fatal(err)
	}
	var m model.Map
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&m); err != nil {
		t.Fatal(err)
	}
	paths := m.Documents["cpanel-openapi"]["paths"].(map[string]any)
	item := paths["/DomainInfo/list_domains"].(map[string]any)
	node := item["get"].(map[string]any)
	item["post"] = node
	var bodySchema any
	decoder = json.NewDecoder(strings.NewReader(`{"type":"object","properties":{"changes":{"type":"array","items":{"type":"object","properties":{"domain":{"type":"string"},"analyzers":{"type":"array","items":{"type":"object","properties":{"name":{"type":"string"},"enabled":{"type":"integer","enum":[0,1]}}}}}}}}}`))
	decoder.UseNumber()
	if err := decoder.Decode(&bodySchema); err != nil {
		t.Fatal(err)
	}
	node["requestBody"] = map[string]any{"content": map[string]any{"application/json": map[string]any{"schema": bodySchema}}}
	pointer := "/paths/~1DomainInfo~1list_domains/post"
	for i := range m.Operations {
		if m.Operations[i].ID == "uapi:DomainInfo/list_domains" {
			m.Operations[i].Variants[0].Method = "POST"
			m.Operations[i].Variants[0].ID = "uapi:DomainInfo/list_domains#POST"
			m.Operations[i].Variants[0].OperationLocator.Pointer = &pointer
		}
	}
	required, nonempty := true, true
	proof := func(p string) []overlay.Evidence {
		return []overlay.Evidence{{Kind: "doc", SourceSHA256: "x", Pointer: p, Note: "Synthetic source plus attributed handler correction only"}}
	}
	schemaPointer := pointer + "/requestBody/content/application~1json/schema"
	corrections := map[string]overlay.InputCorrection{}
	for path, fields := range map[string][]string{"": {"changes"}, "/properties/changes/items": {"domain", "analyzers"}, "/properties/changes/items/properties/analyzers/items": {"name", "enabled"}} {
		corrections[path] = overlay.InputCorrection{Required: fields, Evidence: proof(schemaPointer + path)}
	}
	corrections["/properties/changes"] = overlay.InputCorrection{Nonempty: &nonempty, Evidence: proof(schemaPointer + "/properties/changes")}
	set := overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{{Family: "uapi", Group: "DomainInfo", Operations: map[string]overlay.Operation{"uapi:DomainInfo/list_domains": {Name: "saveConfiguration", Variant: "POST", Body: "json", JSONBody: &overlay.JSONBody{PHP: "configuration", Alias: "StatsConfigurationInput", Routes: "direct", Required: &required, Corrections: corrections}, Evidence: proof(pointer + "/requestBody")}}}}}
	result, err := effective.Build(m, set)
	if err != nil || len(result.Problems) > 0 {
		t.Fatalf("%v %v", err, result.Problems)
	}
	files := Render(result)
	class := string(files["src/Api/Uapi/DomainInfo.php"])
	spec := string(files["src/Api/Uapi/Spec/DomainInfoSpec.php"])
	for _, want := range []string{"@phpstan-type StatsConfigurationInput array{", "changes: non-empty-list<array{", "domain: string,", "analyzers: list<array{", "enabled: bool,", "name: string,", "array $configuration,", "uapi:DomainInfo/list_domains#POST"} {
		if !strings.Contains(class, want) {
			t.Fatalf("missing %q:\n%s", want, class)
		}
	}
	for _, want := range []string{"'required' => ['changes']", "'required' => ['domain', 'analyzers']", "'required' => ['name', 'enabled']", "'enabled' => ['type' => 'flag']", "'nonempty' => true"} {
		if !strings.Contains(spec, want) {
			t.Fatalf("missing %q:\n%s", want, spec)
		}
	}
	if strings.Contains(class, "$configuration = null") {
		t.Fatal("reviewed body became optional")
	}
}
