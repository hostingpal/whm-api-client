package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

const delimitedDescriptor = `{"kind":"delimited","item_type":"string","allow_scalar":true,"empty":"reject","delimiter":"|"}`

func TestDelimitedConcreteSourceAndEvidenceGate(t *testing.T) {
	evidence := `"source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0/description"`
	cases := []struct {
		schema, evidence string
		content, valid   bool
	}{
		{`{"type":"string"}`, evidence, false, true},
		{`{"type":"string","enum":["a"],"pattern":"^a$"}`, evidence, false, true},
		{`{"type":"string","nullable":false}`, evidence, false, true},
		{`{"type":"integer"}`, evidence, false, false},
		{`{"type":"boolean"}`, evidence, false, false},
		{`{"enum":["a"]}`, evidence, false, false},
		{`{"type":["string"]}`, evidence, false, false},
		{`{"type":"array","items":{"type":"string"}}`, evidence, false, false},
		{`{"type":"object"}`, evidence, false, false},
		{`{"type":"string","nullable":true}`, evidence, false, false},
		{`{"type":["string","null"]}`, evidence, false, false},
		{`{"type":"string","enum":["a",null]}`, evidence, false, false},
		{`{"type":"string","format":"json"}`, evidence, false, false},
		{`{"type":"string","allOf":[{"type":"string"}]}`, evidence, false, false},
		{`{"type":"string","anyOf":[{"type":"string"}]}`, evidence, false, false},
		{`{"type":"string","oneOf":[{"type":"string"}]}`, evidence, false, false},
		{`{"type":"string","$ref":"#/components/schemas/Anything"}`, evidence, false, false},
		{`{"type":"string","items":{"type":"string"}}`, evidence, false, false},
		{`{"type":"string","properties":{}}`, evidence, false, false},
		{`{"type":"string","additionalProperties":false}`, evidence, false, false},
		{`{"type":"string"}`, evidence, true, false},
		{`{"type":"string"}`, `"source_sha256":"stale","pointer":"/paths/~1createacct/get/parameters/0"`, false, false},
		{`{"type":"string"}`, `"source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1"`, false, false},
		{`{"type":"string"}`, `"source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0/schema"`, false, false},
		{`{"type":"string"}`, `"note":"missing"`, false, false},
	}
	for _, tc := range cases {
		t.Run(tc.schema+tc.evidence, func(t *testing.T) {
			m := fixtureMap(t)
			var source map[string]any
			_ = json.Unmarshal([]byte(tc.schema), &source)
			for _, op := range m.Operations {
				if op.ID == "whm1:createacct" {
					op.Variants[0].Parameters[0].Definition["schema"] = source
					if tc.content {
						op.Variants[0].Parameters[0].Definition["content"] = map[string]any{}
					}
				}
			}
			set := fixtureOverlay("listed")
			var op overlay.Operation
			_ = json.Unmarshal([]byte(`{"name":"createAccount","params":{"username":{"collection":`+delimitedDescriptor+`}},"evidence":[{"kind":"doc","version":"synthetic","date":"2026-10-31",`+tc.evidence+`}]}`), &op)
			set.Files[1].Operations["whm1:createacct"] = op
			result, problems := buildProblems(t, m, set)
			if tc.valid {
				if problems != "" {
					t.Fatal(problems)
				}
				p := groupByClass(t, result, "Accounts").Operations[0].Params[0]
				if p.PHPType != "string|array" || p.DocType != "string|non-empty-list<string>" || p.Sensitive || groupByClass(t, result, "Accounts").Operations[0].Method != "POST" {
					t.Fatalf("unexpected contract: %+v", p)
				}
			} else if problems == "" {
				t.Fatal("invalid source accepted")
			}
		})
	}
}

func TestDelimitedArrayOnlyReservesFirstUnsuffixedBase(t *testing.T) {
	no, yes, zero, dash := false, true, 0, "-"
	var collection overlay.Collection
	_ = json.Unmarshal([]byte(strings.Replace(delimitedDescriptor, `true`, `false`, 1)), &collection)
	for _, reverse := range []bool{false, true} {
		pointer := "/parameters/0"
		otherPointer := "/parameters/1"
		canonical := Operation{ID: "fixture", Method: "GET", Params: []Param{{PHP: "field", Wire: "field", PHPType: "string", Encoding: "scalar", Required: true}, {PHP: "other", Wire: "group", PHPType: "array", Encoding: "collection", Collection: &collection}}}
		variant := model.Variant{Parameters: []model.Parameter{{Name: "field", Locator: model.Locator{SourceSHA256: "hash", Pointer: &pointer}}, {Name: "group", Locator: model.Locator{SourceSHA256: "hash", Pointer: &otherPointer}}}}
		inputs := []overlay.MethodInput{{PHP: "rows", Records: &overlay.Records{Alias: "RowInput", Start: &zero, Separator: &dash, Sensitive: &no, FirstUnsuffixed: &yes, Fields: []overlay.RecordField{{PHP: "field", Source: "field", Prefix: "group"}}}}, {PHP: "other", Source: "group"}}
		if reverse {
			inputs[0], inputs[1] = inputs[1], inputs[0]
		}
		ov := overlay.Operation{AdditionalMethods: []overlay.AdditionalMethod{{Name: "paired", Inputs: inputs}}, Evidence: []overlay.Evidence{{Kind: "doc", SourceSHA256: "hash", Pointer: pointer, Note: "fixture"}, {Kind: "doc", SourceSHA256: "hash", Pointer: otherPointer, Note: "fixture"}}}
		b := builder{problems: map[string]bool{}}
		b.additionalMethods(model.Operation{ID: "fixture"}, variant, ov, canonical)
		if !strings.Contains(strings.Join(sortedKeys(b.problems), "\n"), "overlapping wire") {
			t.Fatal("delimited literal base collision accepted")
		}
	}
}
