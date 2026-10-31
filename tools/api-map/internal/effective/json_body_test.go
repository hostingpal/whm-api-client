package effective

import (
	"encoding/json"
	"fmt"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"strings"
	"testing"
)

func TestJSONBodyFoundation(t *testing.T) {
	for _, tc := range []struct {
		name, schema string
		required     bool
		want         string
	}{
		{"required object", `{"type":"object","properties":{"enabled":{"type":"integer","enum":[0,1]},"nested":{"type":"object","properties":{"value":{"type":"string","nullable":true}}}},"required":["enabled"]}`, true, ""},
		{"optional object", `{"type":"object"}`, false, ""},
		{"optional list", `{"type":"array","items":{"type":"string"}}`, false, ""},
		{"nullable", `{"type":"string","nullable":true}`, true, ""},
		{"alternatives", `{"anyOf":[{"type":"string"},{"type":"integer"}]}`, true, ""},
		{"missing type", `{"properties":{}}`, true, "explicit"},
		{"mixed extras", `{"type":"object","additionalProperties":true}`, true, "additional"},
		{"composition", `{"allOf":[{"type":"string"}]}`, true, "composition"},
		{"ambiguous", `{"anyOf":[{"type":"object"},{"type":"array","items":{"type":"string"}}]}`, true, "scalar"},
		{"optional nullable", `{"type":"string","nullable":true}`, false, "optional nullable"},
		{"binary", `{"type":"string","format":"binary"}`, true, "binary"},
		{"malformed required", `{"type":"object","required":"field"}`, true, "required"},
		{"malformed properties", `{"type":"object","properties":[]}`, true, "properties"},
		{"malformed nullable", `{"type":"string","nullable":"yes"}`, true, "nullable"},
		{"overlapping alternatives", `{"anyOf":[{"type":"integer"},{"type":"number"}]}`, true, "scalar"},
		{"sibling alternatives", `{"type":"string","anyOf":[{"type":"string"},{"type":"integer"}]}`, true, "composition"},

		{"reserved required", `{"type":"object","properties":{"api.future":{"type":"string"}},"required":["api.future"]}`, true, "reserved"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			var s map[string]any
			json.Unmarshal([]byte(tc.schema), &s)
			fixtureNode(t, m, "whm1:listaccts")["requestBody"] = map[string]any{"required": tc.required, "content": map[string]any{"application/json": map[string]any{"schema": s}}}
			set := fixtureOverlay("listed")
			o := set.Files[1].Operations["whm1:listaccts"]
			json.Unmarshal([]byte(`{"body":"json","json_body":{"php":"configuration","alias":"ConfigurationInput","routes":"direct"}}`), &o)
			o.Evidence = append(o.Evidence, overlay.Evidence{Kind: "doc", SourceSHA256: "y", Pointer: "/paths/~1listaccts/get/requestBody", Note: "Virtual source only"})
			set.Files[1].Operations["whm1:listaccts"] = o
			result, problems := buildProblems(t, m, set)
			if tc.want != "" {
				if !strings.Contains(problems, tc.want) {
					t.Fatalf("expected %q: %s", tc.want, problems)
				}
				return
			}
			if problems != "" {
				t.Fatal(problems)
			}
			op := groupByClass(t, result, "Accounts").Operations[1]
			if op.Body != "json" || op.Method != "POST" {
				t.Fatalf("policy %+v", op)
			}
			found := false
			for _, p := range op.Params {
				if p.PHP == "configuration" {
					found = true
					if p.Required != tc.required || !p.Sensitive {
						t.Fatalf("body %+v", p)
					}
				}
			}
			if !found {
				t.Fatal("whole-body argument missing")
			}
		})
	}
}

func jsonFixture(t *testing.T, descriptor string) (model.Map, overlay.Set) {
	t.Helper()
	m := fixtureMap(t)
	set := fixtureOverlay("listed")
	var schema any
	if err := json.Unmarshal([]byte(`{"type":"object","properties":{"changes":{"type":"array","items":{"type":"object","properties":{"domain":{"type":"string"},"analyzers":{"type":"array","items":{"type":"object","properties":{"name":{"type":"string"},"enabled":{"type":"integer","enum":[0,1]}}}}}}}}}`), &schema); err != nil {
		t.Fatal(err)
	}
	fixtureNode(t, m, "whm1:listaccts")["requestBody"] = map[string]any{"content": map[string]any{"application/json": map[string]any{"schema": schema}}}
	op := set.Files[1].Operations["whm1:listaccts"]
	if err := json.Unmarshal([]byte(descriptor), &op); err != nil {
		t.Fatal(err)
	}
	set.Files[1].Operations["whm1:listaccts"] = op
	return m, set
}

const bodyDescriptor = `{"body":"json","json_body":{"php":"configuration","alias":"ConfigurationInput","routes":"direct","required":true,"corrections":{
 "":{"required":["changes"],"evidence":[{"kind":"doc","source_sha256":"y","pointer":"/paths/~1listaccts/get/requestBody/content/application~1json/schema","note":"Virtual correction; reviewer attribution only"}]},
 "/properties/changes":{"nonempty":true,"evidence":[{"kind":"doc","source_sha256":"y","pointer":"/paths/~1listaccts/get/requestBody/content/application~1json/schema/properties/changes","note":"Virtual correction"}]},
 "/properties/changes/items":{"required":["domain","analyzers"],"evidence":[{"kind":"doc","source_sha256":"y","pointer":"/paths/~1listaccts/get/requestBody/content/application~1json/schema/properties/changes/items","note":"Virtual correction"}]},
 "/properties/changes/items/properties/analyzers/items":{"required":["name","enabled"],"evidence":[{"kind":"doc","source_sha256":"y","pointer":"/paths/~1listaccts/get/requestBody/content/application~1json/schema/properties/changes/items/properties/analyzers/items","note":"Virtual correction"}]}
 }},"evidence":[{"kind":"doc","source_sha256":"y","pointer":"/paths/~1listaccts/get/requestBody","note":"Virtual source only"}]}`

func TestJSONBodyCorrectionsAreNarrowAndSourceBound(t *testing.T) {
	m, set := jsonFixture(t, bodyDescriptor)
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	op := groupByClass(t, result, "Accounts").Operations[1]
	s := op.JSONBody.Schema
	if !op.JSONBody.Required || len(s.Required) != 1 || !s.Fields[0].Spec.Nonempty || len(s.Fields[0].Spec.Items.Required) != 2 {
		t.Fatalf("incorrect correction %+v", s)
	}
	analyzers := s.Fields[0].Spec.Items.Fields[0].Spec
	if analyzers.Nonempty || len(analyzers.Items.Required) != 2 || analyzers.Items.Fields[0].Spec.Type != "flag" {
		t.Fatalf("analyzers %+v", analyzers)
	}
	// Existing response corrections remain separate; no input compatibility strings.
	raw, _ := json.Marshal(op.JSONBody)
	if strings.Contains(string(raw), "numeric-string") {
		t.Fatal(string(raw))
	}
	baseline, _ := buildProblems(t, fixtureMap(t), fixtureOverlay("listed"))
	before, _ := json.Marshal(groupByClass(t, baseline, "Accounts").Operations[1].Returns)
	after, _ := json.Marshal(op.Returns)
	if string(before) != string(after) {
		t.Fatal("response schema drift")
	}
	for _, tc := range []struct{ old, new, want string }{
		{`"source_sha256":"y"`, `"source_sha256":"wrong"`, "exact source evidence"},
		{`/schema/properties/changes"`, `/schema/properties/missing"`, "exact source evidence"},
		{`"nonempty":true`, `"nonempty":false`, "nonempty"},
		{`"required":["changes"]`, `"required":["missing"]`, "not declared"},
	} {
		m, set := jsonFixture(t, strings.Replace(bodyDescriptor, tc.old, tc.new, 1))
		_, problems := buildProblems(t, m, set)
		if !strings.Contains(problems, tc.want) {
			t.Fatalf("expected %s, got %s", tc.want, problems)
		}
	}
	m, set = jsonFixture(t, bodyDescriptor)
	for i := range m.Operations {
		if m.Operations[i].ID == "whm1:listaccts" {
			m.Operations[i].Variants[0].Parameters = append(m.Operations[i].Variants[0].Parameters, model.Parameter{Name: "configuration", In: "query", Definition: model.Object{"required": false, "schema": map[string]any{"type": "string"}}, Locator: model.Locator{SourceID: "whm-openapi"}})
		}
	}
	if _, p := buildProblems(t, m, set); !strings.Contains(p, "collides") {
		t.Fatal(p)
	}

	m, set = jsonFixture(t, bodyDescriptor)
	o := set.Files[1].Operations["whm1:listaccts"]
	o.Evidence = nil
	set.Files[1].Operations["whm1:listaccts"] = o
	if _, p := buildProblems(t, m, set); !strings.Contains(p, "needs doc evidence") {
		t.Fatal(p)
	}
}

func TestJSONBodySelectedPostVariantAndUapi(t *testing.T) {
	for _, id := range []string{"whm1:listaccts", "uapi:DomainInfo/list_domains"} {
		m := fixtureMap(t)
		set := fixtureOverlay("listed")
		node := fixtureNode(t, m, id)
		node["requestBody"] = map[string]any{"required": true, "content": map[string]any{"application/json": map[string]any{"schema": map[string]any{"type": "object", "properties": map[string]any{"enabled": map[string]any{"type": "boolean"}}}}}}
		for i := range m.Operations {
			if m.Operations[i].ID == id {
				v := m.Operations[i].Variants[0]
				v.Method = "POST"
				v.ID = id + "#POST"
				m.Operations[i].Variants = append(m.Operations[i].Variants, v)
			}
		}
		n := 1
		pointer := "/paths/~1listaccts/get/requestBody"
		if strings.HasPrefix(id, "uapi:") {
			n = 0
			pointer = "/paths/~1DomainInfo~1list_domains/get/requestBody"
		}
		o := set.Files[n].Operations[id]
		o.Body = "json"
		o.Variant = "POST"
		o.JSONBody = &overlay.JSONBody{PHP: "body", Alias: "BodyInput", Routes: "direct"}
		o.Evidence = []overlay.Evidence{{Kind: "doc", SourceSHA256: map[bool]string{true: "x", false: "y"}[n == 0], Pointer: pointer, Note: "Virtual source POST choice"}}
		set.Files[n].Operations[id] = o
		result, p := buildProblems(t, m, set)
		if p != "" {
			t.Fatal(p)
		}
		class := "Accounts"
		index := 1
		if n == 0 {
			class = "DomainInfo"
			index = 0
		}
		op := groupByClass(t, result, class).Operations[index]
		if op.VariantID != id+"#POST" || op.Method != "POST" {
			t.Fatalf("variant %+v", op)
		}
	}
}

func TestJSONBodySchemaDepthDoesNotUseResponseFallback(t *testing.T) {
	m := fixtureMap(t)
	s, err := newSchemas(m)
	if err != nil {
		t.Fatal(err)
	}
	for _, depth := range []int{9, 511, 512} {
		var node any = map[string]any{"type": "string"}
		for range depth {
			node = map[string]any{"type": "array", "items": node}
		}
		result, err := s.inputFor("whm-openapi", node, 0, 0, "", nil, map[string]bool{}, model.Locator{}, "")
		if depth == 512 {
			if err == nil {
				t.Fatal("over-budget input schema accepted")
			}
			continue
		}
		if err != nil {
			t.Fatal(err)
		}
		for range depth {
			if result.Type != "list" {
				t.Fatal("request schema weakened")
			}
			result = *result.Items
		}
		if result.Type != "string" {
			t.Fatal("request scalar weakened")
		}
	}
}

func TestJSONIntegerFlagDoesNotUseResponseCompatibility(t *testing.T) {
	s, err := newSchemas(fixtureMap(t))
	if err != nil {
		t.Fatal(err)
	}
	for _, enum := range []any{[]any{"0", "1"}, []any{0, 1, nil}} {
		result, err := s.inputFor("whm-openapi", map[string]any{"type": "integer", "enum": enum}, 0, 0, "", nil, map[string]bool{}, model.Locator{}, "")
		if err != nil {
			t.Fatal(err)
		}
		if result.Type != "int" || result.Nullable {
			t.Fatalf("source request enum widened %+v", result)
		}
	}
}

func TestJSONInputCorrectionEvidenceFollowsResolvedSourcePointer(t *testing.T) {
	m := fixtureMap(t)
	components, _ := asObject(m.Documents["whm-openapi"]["components"])
	if components == nil {
		components = map[string]any{}
		m.Documents["whm-openapi"]["components"] = components
	}
	components["schemas"] = map[string]any{"Input": map[string]any{"type": "object", "properties": map[string]any{"field": map[string]any{"type": "string"}}}}
	s, err := newSchemas(m)
	if err != nil {
		t.Fatal(err)
	}
	pointer := "/paths/~1listaccts/get/requestBody/content/application~1json/schema"
	locator := model.Locator{SourceID: "whm-openapi", SourceSHA256: "y"}
	for _, evidencePointer := range []string{pointer, "/components/schemas/Input"} {
		corrections := map[string]overlay.InputCorrection{"": {Required: []string{"field"}, Evidence: []overlay.Evidence{{Kind: "doc", SourceSHA256: "y", Pointer: evidencePointer, Note: "Virtual ref source"}}}}
		_, err := s.inputFor("whm-openapi", map[string]any{"$ref": "#/components/schemas/Input"}, 0, 0, "", corrections, map[string]bool{}, locator, pointer)
		if evidencePointer == pointer && err == nil {
			t.Fatal("correction bound to nonexistent inline schema accepted")
		}
		if evidencePointer != pointer && err != nil {
			t.Fatal(err)
		}
	}
}

func TestJSONRequestBodyRefCorrectionProvenance(t *testing.T) {
	inline := "/paths/~1listaccts/get/requestBody/content/application~1json/schema"
	target := "/components/requestBodies/InputBody/content/application~1json/schema"
	for _, correct := range []bool{false, true} {
		name := "nonexistent inline evidence"
		if correct {
			name = "resolved component evidence"
		}
		t.Run(name, func(t *testing.T) {
			descriptor := bodyDescriptor
			if correct {
				descriptor = strings.ReplaceAll(descriptor, inline, target)
			}
			m, set := jsonFixture(t, descriptor)
			node := fixtureNode(t, m, "whm1:listaccts")
			body := node["requestBody"]
			components, _ := asObject(m.Documents["whm-openapi"]["components"])
			if components == nil {
				components = map[string]any{}
				m.Documents["whm-openapi"]["components"] = components
			}
			components["requestBodies"] = map[string]any{"InputBody": body}
			node["requestBody"] = map[string]any{"$ref": "#/components/requestBodies/InputBody"}
			_, problems := buildProblems(t, m, set)
			if correct && problems != "" {
				t.Errorf("correct resolved requestBody provenance rejected: %s", problems)
			}
			if !correct && problems == "" {
				t.Error("nonexistent inline schema provenance accepted through requestBody ref")
			}
		})
	}
}

func TestJSONRequestBodyRefCrossDocumentCorrectionsRemainUnsupported(t *testing.T) {
	inline := "/paths/~1listaccts/get/requestBody/content/application~1json/schema"
	target := "/components/requestBodies/InputBody/content/application~1json/schema"
	m, set := jsonFixture(t, strings.ReplaceAll(bodyDescriptor, inline, target))
	node := fixtureNode(t, m, "whm1:listaccts")
	components, ok := asObject(m.Documents["cpanel-openapi"]["components"])
	if !ok {
		t.Fatal("fixture components missing")
	}
	components["requestBodies"] = map[string]any{"InputBody": node["requestBody"]}
	node["requestBody"] = map[string]any{"$ref": "cpanel-openapi#/components/requestBodies/InputBody"}
	_, problems := buildProblems(t, m, set)
	if !strings.Contains(problems, "cross-document input corrections require separate source provenance") {
		t.Fatalf("cross-document requestBody correction did not fail closed: %s", problems)
	}
}

func TestJSONInputObjectNamesRetainStringKeys(t *testing.T) {
	s, err := newSchemas(fixtureMap(t))
	if err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct {
		name   string
		reject bool
	}{
		{"0", true}, {"1", true}, {"-1", true},
		{"2147483647", true}, {"2147483648", true},
		{"-2147483648", true}, {"-2147483649", true},
		{"9223372036854775807", true}, {"-9223372036854775808", true},
		{"", false}, {"00", false}, {"01", false}, {"+1", false},
		{"-0", false}, {"-01", false}, {"1.0", false}, {"1e1", false},
		{" 1", false}, {"1 ", false},
		{"9223372036854775808", false}, {"-9223372036854775809", false},
	} {
		for _, required := range []bool{false, true} {
			for _, nested := range []bool{false, true} {
				t.Run(fmt.Sprintf("%q/required=%t/nested=%t", tc.name, required, nested), func(t *testing.T) {
					var raw any = map[string]any{"type": "object", "properties": map[string]any{tc.name: map[string]any{"type": "string"}}}
					if required {
						raw.(map[string]any)["required"] = []any{tc.name}
					}
					if nested {
						raw = map[string]any{"type": "object", "properties": map[string]any{"child": raw}}
					}
					got, err := s.inputFor("whm-openapi", raw, 0, 0, "", nil, map[string]bool{}, model.Locator{}, "")
					if tc.reject {
						if err == nil || !strings.Contains(err.Error(), "string array keys") {
							t.Fatalf("unrepresentable declared name must fail lowering: got %+v, err %v", got, err)
						}
						if strings.Contains(err.Error(), tc.name) && tc.name != "" {
							t.Fatalf("diagnostic must not echo the source name: %v", err)
						}
						return
					}
					if err != nil {
						t.Fatal(err)
					}
					if nested {
						got = got.Fields[0].Spec
					}
					if len(got.Fields) != 1 || got.Fields[0].Name != tc.name || (len(got.Required) == 1) != required {
						t.Fatalf("source spelling/requiredness lost: %+v", got)
					}
				})
			}
		}
	}
}
