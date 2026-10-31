package effective

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"reflect"
	"strings"
	"testing"
)

func TestJSONScalarMapSourceProseOptIn(t *testing.T) {
	descriptor := strings.Replace(fieldAlias, `"alias":"ConfigurationInput"`, `"alias":"ConfigurationInput","scalar_map":true`, 1)
	descriptor = strings.Replace(descriptor, `"evidence":[`, `"evidence":[{"kind":"doc","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0/schema","note":"Virtual scalar-map schema"},`, 1)
	m, set := fieldFixture(t, `{}`, descriptor)
	p := operationByID(t, m, "whm1:createacct").Variants[0].Parameters[0]
	delete(p.Definition, "content")
	p.Definition["schema"] = map[string]any{"type": "string", "format": "json"}
	r, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	op := groupByClass(t, r, "Accounts").Operations[0]
	got := op.Methods()[1].Params[0]
	if got.PHPType != "array" || got.Encoding != "json" || got.Input == nil || got.Input.Type != "object" || !got.Sensitive {
		t.Fatalf("missing scalar map lowering: %+v", got)
	}
}

func scalarMapFixture(t *testing.T) (model.Map, overlay.Set) {
	t.Helper()
	descriptor := strings.Replace(fieldAlias, `"alias":"ConfigurationInput"`, `"alias":"ConfigurationInput","scalar_map":true`, 1)
	descriptor = strings.Replace(descriptor, `"evidence":[`, `"evidence":[{"kind":"doc","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0/schema","note":"Virtual scalar-map schema"},`, 1)
	m, set := fieldFixture(t, `{}`, descriptor)
	p := operationByID(t, m, "whm1:createacct").Variants[0].Parameters[0]
	delete(p.Definition, "content")
	p.Definition["schema"] = map[string]any{"type": "string", "format": "json", "example": "{}", "description": "annotation", "title": "annotation"}
	return m, set
}

func TestScalarMapSourceAndDescriptorFailClosed(t *testing.T) {
	cases := []string{"false", "ordinary string", "wrong type", "content sibling", "reference", "nullable", "enum", "allOf", "oneOf", "anyOf", "maxLength", "additionalProperties", "items", "unknown", "wrong schema hash", "missing schema hash", "wrong schema pointer", "missing schema pointer", "wrong schema kind", "empty schema note", "missing prose", "wrong prose kind", "blank prose note", "invalid alias", "input type correction", "omit", "multipart"}
	for _, mode := range cases {
		t.Run(mode, func(t *testing.T) {
			m, set := scalarMapFixture(t)
			p := operationByID(t, m, "whm1:createacct").Variants[0].Parameters[0]
			schema := p.Definition["schema"].(map[string]any)
			o := set.Files[1].Operations["whm1:createacct"]
			switch mode {
			case "false":
				value := false
				o.AdditionalMethods[0].Inputs[0].JSON.ScalarMap = &value
			case "ordinary string":
				delete(schema, "format")
			case "wrong type":
				schema["type"] = "object"
			case "content sibling":
				p.Definition["content"] = map[string]any{}
			case "reference":
				schema["$ref"] = "#/components/schemas/anything"
			case "nullable":
				schema["nullable"] = false
			case "enum":
				schema["enum"] = []any{"{}"}
			case "allOf", "oneOf", "anyOf":
				schema[mode] = []any{map[string]any{"type": "string"}}
			case "maxLength":
				schema[mode] = 100
			case "additionalProperties", "items":
				schema[mode] = map[string]any{"type": "string"}
			case "unknown":
				schema["x-guess"] = true
			case "wrong schema hash":
				o.Evidence[0].SourceSHA256 = "wrong"
			case "missing schema hash":
				o.Evidence[0].SourceSHA256 = ""
			case "wrong schema pointer":
				o.Evidence[0].Pointer += "/example"
			case "missing schema pointer":
				o.Evidence[0].Pointer = ""
			case "wrong schema kind":
				o.Evidence[0].Kind = "live"
			case "empty schema note":
				o.Evidence[0].Note = " "
			case "missing prose":
				o.Evidence = append(o.Evidence[:1], o.Evidence[2:]...)
			case "wrong prose kind":
				o.Evidence[1].Kind = "live"
			case "blank prose note":
				o.Evidence[1].Note = " "
			case "invalid alias":
				o.AdditionalMethods[0].Inputs[0].JSON.Alias = "Configuration"
			case "input type correction":
				o.Params = map[string]overlay.Param{"username": {Type: "string|int"}}
			case "omit":
				o.Params = map[string]overlay.Param{"username": {Omit: true}}
			case "wrong location":
				p.In = "header"
			case "multipart":
				o.Body = "multipart"
			}
			set.Files[1].Operations["whm1:createacct"] = o
			_, problems := buildProblems(t, m, set)
			if problems == "" {
				t.Fatal("unsafe scalar_map accepted")
			}
		})
	}
}

func TestScalarMapCannotInheritCanonicalGuards(t *testing.T) {
	m, set := scalarMapFixture(t)
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	canonical := groupByClass(t, result, "Accounts").Operations[0]
	source := operationByID(t, m, "whm1:createacct").Variants[0].Parameters[0]
	o := set.Files[1].Operations[canonical.ID]
	for _, mode := range []string{"omitEmpty", "rejectEmpty", "collection", "encoding", "type", "input", "json body", "wrong location"} {
		t.Run(mode, func(t *testing.T) {
			source := source
			c := canonical
			c.Params = append([]Param(nil), canonical.Params...)
			switch mode {
			case "omitEmpty":
				c.Params[0].OmitEmpty = true
			case "rejectEmpty":
				c.Params[0].RejectEmpty = true
			case "collection":
				c.Params[0].Collection = &overlay.Collection{}
			case "encoding":
				c.Params[0].Encoding = "repeat"
			case "type":
				c.Params[0].PHPType = "string|int"
			case "input":
				c.Params[0].Input = &InputSpec{Type: "string"}
			case "json body":
				c.Body = "json"
			case "wrong location":
				source.In = "header"
			}
			b := builder{problems: map[string]bool{}}
			_, _, err := b.jsonField(source, o.AdditionalMethods[0].Inputs[0].JSON, overlay.Param{}, c, c.Params[0], o.Evidence)
			if err == nil {
				t.Fatal("guarded canonical source accepted")
			}
		})
	}
}

func TestScalarMapFixedGrammarAndOptionalOmission(t *testing.T) {
	m, set := scalarMapFixture(t)
	p := operationByID(t, m, "whm1:createacct").Variants[0].Parameters[0]
	p.Definition["required"] = false
	// Keep all sources mapped but required fields before optional.
	o := set.Files[1].Operations["whm1:createacct"]
	in := o.AdditionalMethods[0].Inputs
	o.AdditionalMethods[0].Inputs = []overlay.MethodInput{in[1], in[2], in[0]}
	set.Files[1].Operations["whm1:createacct"] = o
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	canonical := groupByClass(t, result, "Accounts").Operations[0]
	alias := canonical.Methods()[1]
	got := alias.Params[2]
	want := InputSpec{Type: "object", Values: &InputSpec{Type: "union", AnyOf: []InputSpec{{Type: "string"}, {Type: "number"}, {Type: "bool"}, {Type: "null"}}}}
	// Compare the strict lowered grammar, including number rather than overlapping int/number branches.
	if !reflect.DeepEqual(*got.Input, want) {
		a, _ := json.Marshal(got.Input)
		t.Fatalf("unexpected grammar: %s", a)
	}
	if got.Required || got.PHPType != "array" || got.Encoding != "json" || !got.Sensitive || !strings.Contains(got.Description, "source prose") {
		t.Fatalf("optional scalar map changed: %+v", got)
	}
	if canonical.Params[2].Encoding != "scalar" || canonical.Params[2].PHPType != "string" {
		t.Fatal("raw source changed")
	}
	alias.PHPName, alias.Constant, alias.Params, alias.AdditionalMethods = canonical.PHPName, canonical.Constant, canonical.Params, canonical.AdditionalMethods
	if !reflect.DeepEqual(alias, canonical) {
		t.Fatal("companion forked canonical identity, result, outcome or lifecycle")
	}
}
