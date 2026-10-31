package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func enumCorrectionFixture(t *testing.T, schema map[string]any, setting overlay.Param) (model.Map, overlay.Set) {
	t.Helper()
	m := fixtureMap(t)
	for i := range m.Operations {
		if m.Operations[i].ID == "whm1:createacct" {
			m.Operations[i].Variants[0].Parameters[0].Definition["schema"] = schema
		}
	}
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{"username": setting}
	op.Method = "POST"
	op.Evidence = []overlay.Evidence{{Kind: "doc", Version: "fixture", Date: "2026-10-31", Note: "Text enum and prose.", SourceSHA256: "y", Pointer: "/paths/~1createacct/get/parameters/0/schema"}}
	set.Files[1].Operations["whm1:createacct"] = op
	return m, set
}

func TestBuildEnumOnlyStringCorrectionPreservesLiteralDocsAndScalarContract(t *testing.T) {
	for _, encoding := range []string{"", "scalar"} {
		m, set := enumCorrectionFixture(t, map[string]any{"enum": []any{"home", "mail", "public_html", "public_ftp"}, "example": "home"}, overlay.Param{Type: "string", Encoding: encoding})
		result, problems := buildProblems(t, m, set)
		if problems != "" {
			t.Fatal(problems)
		}
		op := groupByClass(t, result, "Accounts").Operations[0]
		p := op.Params[0]
		if p.PHPType != "string" || p.DocType != "'home'|'mail'|'public_html'|'public_ftp'" || p.Encoding != "scalar" || !p.Required || p.Sensitive || p.Wire != "username" || op.Method != "POST" {
			t.Fatalf("incorrect enum correction: %+v / %s", p, op.Method)
		}
		if op.Params[1].PHPType != "string" || !op.Params[1].Sensitive || op.Params[1].Required {
			t.Fatalf("sibling changed: %+v", op.Params[1])
		}
	}
}

func TestBuildRejectsIncompatibleEnumOnlyStringCorrections(t *testing.T) {
	base := func() map[string]any { return map[string]any{"enum": []any{"home", "mail"}} }
	cases := []struct {
		name    string
		schema  map[string]any
		setting overlay.Param
	}{
		{"empty enum", map[string]any{"enum": []any{}}, overlay.Param{Type: "string"}},
		{"missing enum", map[string]any{}, overlay.Param{Type: "string"}},
		{"numeric enum", map[string]any{"enum": []any{1, 2}}, overlay.Param{Type: "string"}},
		{"mixed enum", map[string]any{"enum": []any{"home", 1}}, overlay.Param{Type: "string"}},
		{"null enum", map[string]any{"enum": []any{"home", nil}}, overlay.Param{Type: "string"}},
		{"bool enum", map[string]any{"enum": []any{true}}, overlay.Param{Type: "string"}},
	}
	for _, key := range []string{"type", "format", "nullable", "allOf", "anyOf", "oneOf", "not", "properties", "additionalProperties", "items", "prefixItems", "$ref", "discriminator"} {
		schema := base()
		schema[key] = map[string]any{}
		if key == "type" {
			schema[key] = "string"
		}
		if key == "format" {
			schema[key] = "string"
		}
		if key == "nullable" {
			schema[key] = true
		}
		cases = append(cases, struct {
			name    string
			schema  map[string]any
			setting overlay.Param
		}{key, schema, overlay.Param{Type: "string"}})
	}
	for _, setting := range []overlay.Param{{Type: "string", Encoding: "repeat"}, {Type: "string", Encoding: "numbered"}, {Type: "string", Encoding: "bool"}, {Type: "string", Wire: "other"}, {Type: "string", Omit: true}, {Type: "string", Collection: &overlay.Collection{Kind: "repeat"}}} {
		cases = append(cases, struct {
			name    string
			schema  map[string]any
			setting overlay.Param
		}{"incompatible input setting", base(), setting})
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			m, set := enumCorrectionFixture(t, tc.schema, tc.setting)
			_, problems := buildProblems(t, m, set)
			want := "parameter username: string type correction requires"
			if tc.setting.Omit {
				want = "omitted parameter username cannot have type"
			}
			if !strings.Contains(problems, want) {
				t.Fatalf("missing specific rejection %q: %s", want, problems)
			}
		})
	}
}

func TestBuildEnumOnlyStringCorrectionRequiresExactSchemaEvidence(t *testing.T) {
	for _, name := range []string{"missing", "hash", "pointer", "parameter pointer", "prose pointer", "variant pointer", "live", "source hash", "source pointer", "operation hash", "operation pointer", "content"} {
		t.Run(name, func(t *testing.T) {
			m, set := enumCorrectionFixture(t, map[string]any{"enum": []any{"home", "mail"}}, overlay.Param{Type: "string"})
			op := set.Files[1].Operations["whm1:createacct"]
			switch name {
			case "missing":
				op.Evidence = nil
			case "hash":
				op.Evidence[0].SourceSHA256 = "wrong"
			case "pointer":
				op.Evidence[0].Pointer = ""
			case "parameter pointer":
				op.Evidence[0].Pointer = "/paths/~1createacct/get/parameters/0"
			case "prose pointer":
				op.Evidence[0].Pointer = "/paths/~1createacct/get/parameters/0/description"
			case "variant pointer":
				op.Evidence[0].Pointer = "/paths/~1createacct/post/parameters/0/schema"
			case "live":
				op.Evidence[0].Kind = "live"
			}
			set.Files[1].Operations["whm1:createacct"] = op
			for i := range m.Operations {
				if m.Operations[i].ID == "whm1:createacct" {
					v := &m.Operations[i].Variants[0]
					switch name {
					case "source hash":
						v.Parameters[0].Locator.SourceSHA256 = ""
					case "source pointer":
						v.Parameters[0].Locator.Pointer = nil
					case "operation hash":
						v.OperationLocator.SourceSHA256 = "other"
					case "operation pointer":
						pointer := "/paths/~1listaccts/get"
						v.OperationLocator.Pointer = &pointer
					case "content":
						delete(v.Parameters[0].Definition, "schema")
						v.Parameters[0].Definition["content"] = map[string]any{"application/json": map[string]any{"schema": map[string]any{"enum": []any{"home"}}}}
					}
				}
			}
			_, problems := buildProblems(t, m, set)
			want := "parameter username: string type correction needs doc evidence"
			if name == "content" {
				want = "parameter username: content requires"
			}
			if !strings.Contains(problems, want) {
				t.Fatalf("missing specific rejection %q: %s", want, problems)
			}
		})
	}
}

func TestBuildUnannotatedTypelessInputsKeepFallback(t *testing.T) {
	for _, schema := range []map[string]any{{}, {"enum": []any{"home", "mail"}}, {"format": "string"}, {"enum": []any{"home", 1}}} {
		m, set := enumCorrectionFixture(t, schema, overlay.Param{})
		result, problems := buildProblems(t, m, set)
		if problems != "" {
			t.Fatal(problems)
		}
		p := groupByClass(t, result, "Accounts").Operations[0].Params[0]
		if p.PHPType != "string|int" || p.DocType != "string|int" || p.Encoding != "scalar" {
			t.Fatalf("global inference changed: %+v", p)
		}
	}
}
