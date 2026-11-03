package effective

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"reflect"
	"strings"
	"testing"
)

const fieldAlias = `{"name":"createAccount","additional_methods":[{"name":"configure","inputs":[{"php":"configuration","source":"username","json":{"alias":"ConfigurationInput"}},{"php":"password","source":"password"},{"php":"plan","source":"plan"}]}],"evidence":[{"kind":"doc","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0","note":"Virtual content selection"},{"kind":"doc","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1","note":"Virtual scalar binding"},{"kind":"doc","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/2","note":"Virtual scalar binding"}]}`

func fieldFixture(t *testing.T, schema, descriptor string) (model.Map, overlay.Set) {
	t.Helper()
	m := fixtureMap(t)
	p := operationByID(t, m, "whm1:createacct").Variants[0].Parameters[0]
	delete(p.Definition, "schema")
	var s any
	if err := json.Unmarshal([]byte(schema), &s); err != nil {
		t.Fatal(err)
	}
	p.Definition["content"] = map[string]any{"application/json": map[string]any{"schema": s}}
	set := fixtureOverlay("listed")
	var o overlay.Operation
	if err := json.Unmarshal([]byte(descriptor), &o); err != nil {
		t.Fatal(err)
	}
	set.Files[1].Operations["whm1:createacct"] = o
	return m, set
}
func TestJSONFieldCompanionKeepsScalarCanonicalAndSharedIdentity(t *testing.T) {
	m, set := fieldFixture(t, `{"type":"object","properties":{"api.version":{"type":"string"},"label":{"type":"string"}},"required":["api.version"]}`, fieldAlias)
	r, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	op := groupByClass(t, r, "Accounts").Operations[0]
	if op.Params[0].PHPType != "string" || op.Params[0].Encoding != "scalar" || op.Params[0].Input != nil {
		t.Fatal("canonical serialized-text contract changed")
	}
	alias := op.Methods()[1]
	p := alias.Params[0]
	if p.Encoding != "json" || p.Input == nil || p.Input.Type != "object" || p.PHPType != "array" || p.DocType != "ConfigurationInput" || !p.Sensitive || !p.Required || p.Wire != "username" {
		t.Fatalf("missing field JSON lowering: %+v", p)
	}
	alias.PHPName, alias.Constant, alias.Params, alias.AdditionalMethods = op.PHPName, op.Constant, op.Params, op.AdditionalMethods
	if !reflect.DeepEqual(alias, op) {
		t.Fatal("alias forked canonical identity/response/route/body")
	}
}
func TestJSONFieldSourceAndSchemaFailClosed(t *testing.T) {
	for _, tc := range []struct{ schema, old, new, want string }{
		{`{"type":"object"}`, `"source_sha256":"y"`, `"source_sha256":"wrong"`, "matching source_sha256"},
		{`{"type":"object"}`, `"source_sha256":"y",`, ``, "matching source_sha256"},
		{`{"type":"object"}`, `"pointer":"/paths/~1createacct/get/parameters/0",`, ``, "matching source_sha256"},
		{`{"type":"object"}`, `"note":"Virtual content selection"`, `"note":""`, "matching source_sha256"},
		{`{"type":"object"}`, `/parameters/0"`, `/parameters/0/schema"`, "matching source_sha256"},
		{`{"type":"object"}`, `"alias":"ConfigurationInput"`, `"alias":"CreateAccountResult"`, "Input alias"},
		{`{"type":"object"}`, `"name":"configure"`, `"name":"createaccount"`, "duplicate method"},
		{`{"type":"object"}`, `"php":"password"`, `"php":"configuration"`, "duplicated input"},
		{`{"type":"object"}`, `"source":"plan"`, `"source":"username"`, "duplicated source"},
		{`{"properties":{}}`, "", "", "explicit"},
		{`{"allOf":[{"type":"string"}]}`, "", "", "composition"},
		{`{"type":"object","additionalProperties":{"description":"untyped"}}`, "", "", "explicit"},
		{`{"type":"string","format":"binary"}`, "", "", "binary"},
		{`{"anyOf":[{"type":"object"},{"type":"array","items":{"type":"string"}}]}`, "", "", "scalar"},
		{`{"type":"object","properties":{"1":{"type":"string"}}}`, "", "", "string array keys"},
	} {
		t.Run(tc.want+tc.schema, func(t *testing.T) {
			descriptor := fieldAlias
			if tc.old != "" {
				descriptor = strings.Replace(descriptor, tc.old, tc.new, 1)
			}
			m, set := fieldFixture(t, tc.schema, descriptor)
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, tc.want) {
				t.Fatalf("expected %s, got %s", tc.want, problems)
			}
		})
	}
}
func TestJSONFieldRejectsOptionalNullableAndNonJSONSource(t *testing.T) {
	for _, mode := range []string{"optional nullable", "wrong media", "schema only", "multipart"} {
		t.Run(mode, func(t *testing.T) {
			m, set := fieldFixture(t, `{"type":"object","nullable":true}`, fieldAlias)
			p := operationByID(t, m, "whm1:createacct").Variants[0].Parameters[0]
			switch mode {
			case "optional nullable":
				p.Definition["required"] = false
			case "wrong media":
				p.Definition["content"] = map[string]any{"text/plain": map[string]any{"schema": map[string]any{"type": "string"}}}
			case "schema only":
				delete(p.Definition, "content")
				p.Definition["schema"] = map[string]any{"type": "string"}
			case "multipart":
				o := set.Files[1].Operations["whm1:createacct"]
				o.Body = "multipart"
				set.Files[1].Operations["whm1:createacct"] = o
			}
			_, problems := buildProblems(t, m, set)
			if problems == "" {
				t.Fatal("unsupported field source/presence accepted")
			}
		})
	}
}

func TestJSONFieldTypeAliasCollisions(t *testing.T) {
	m, set := fieldFixture(t, `{"type":"object"}`, fieldAlias)
	o := set.Files[1].Operations["whm1:createacct"]
	second := o.AdditionalMethods[0]
	second.Name = "configureAgain"
	o.AdditionalMethods = append(o.AdditionalMethods, second)
	set.Files[1].Operations["whm1:createacct"] = o
	_, problems := buildProblems(t, m, set)
	if !strings.Contains(problems, "duplicate type alias") {
		t.Fatal(problems)
	}
}

func TestJSONFieldSupportedShapesAndPresence(t *testing.T) {
	for _, tc := range []struct {
		schema, native string
		required       bool
	}{
		{`{"type":"object","properties":{"label":{"type":"string"}}}`, "array", true},
		{`{"type":"array","items":{"type":"string"}}`, "array", true},
		{`{"type":"string"}`, "string", true},
		{`{"type":"string","nullable":true}`, "?string", true},
		{`{"type":"object"}`, "array", false},
	} {
		t.Run(tc.schema, func(t *testing.T) {
			m, set := fieldFixture(t, tc.schema, fieldAlias)
			operationByID(t, m, "whm1:createacct").Variants[0].Parameters[0].Definition["required"] = tc.required
			r, problems := buildProblems(t, m, set)
			if problems != "" {
				t.Fatal(problems)
			}
			p := groupByClass(t, r, "Accounts").Operations[0].Methods()[1].Params[0]
			if p.PHPType != tc.native || p.Required != tc.required || p.Encoding != "json" {
				t.Fatalf("incorrect lowering: %+v", p)
			}
		})
	}
}
