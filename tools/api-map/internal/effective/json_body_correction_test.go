package effective

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"strings"
	"testing"
)

func TestWholeJSONExecutionContract(t *testing.T) {
	for _, schema := range []string{
		`{"type":"string"}`, `{"type":"array","items":{"type":"string"}}`,
		`{"type":"null"}`, `{"type":"object","nullable":true}`,
		`{"type":"object","properties":{"api.optional":{"type":"string"}}}`,
	} {
		m, set := jsonFixture(t, bodyDescriptor)
		for i := range m.Operations {
			if m.Operations[i].ID == "whm1:listaccts" {
				m.Operations[i].Variants[0].Parameters = nil
			}
		}
		op := set.Files[1].Operations["whm1:listaccts"]
		op.Params = nil
		op.JSONBody.Corrections = nil
		set.Files[1].Operations["whm1:listaccts"] = op
		var raw any
		if err := json.Unmarshal([]byte(schema), &raw); err != nil {
			t.Fatal(err)
		}
		node := fixtureNode(t, m, "whm1:listaccts")
		node["requestBody"].(map[string]any)["content"].(map[string]any)["application/json"].(map[string]any)["schema"] = raw
		if _, problems := buildProblems(t, m, set); !strings.Contains(problems, "root") {
			t.Errorf("bad root accepted: %s: %s", schema, problems)
		}
	}
	m, set := jsonFixture(t, bodyDescriptor)
	for i := range m.Operations {
		if m.Operations[i].ID == "whm1:listaccts" {
			m.Operations[i].Variants[0].Parameters = append(m.Operations[i].Variants[0].Parameters, model.Parameter{Name: "ordinary", In: "query", Definition: model.Object{"schema": map[string]any{"type": "string"}}})
		}
	}
	if _, p := buildProblems(t, m, set); !strings.Contains(p, "query") {
		t.Errorf("JSON query accepted: %s", p)
	}
}

func TestStringNonemptyCorrectionAllowsExplicitFalse(t *testing.T) {
	for _, nonempty := range []bool{false, true} {
		m, set := jsonFixture(t, bodyDescriptor)
		for i := range m.Operations {
			if m.Operations[i].ID == "whm1:listaccts" {
				m.Operations[i].Variants[0].Parameters = nil
			}
		}
		op := set.Files[1].Operations["whm1:listaccts"]
		op.Params = nil
		op.JSONBody.Corrections["/properties/changes/items/properties/domain"] = overlay.InputCorrection{
			Nonempty: &nonempty, Evidence: []overlay.Evidence{{Kind: "doc", SourceSHA256: "y", Pointer: "/paths/~1listaccts/get/requestBody/content/application~1json/schema/properties/changes/items/properties/domain", Note: "Virtual reviewed policy"}},
		}
		set.Files[1].Operations["whm1:listaccts"] = op
		got, p := buildProblems(t, m, set)
		if p != "" {
			t.Fatal(p)
		}
		domain := groupByClass(t, got, "Accounts").Operations[1].JSONBody.Schema.Fields[0].Spec.Items.Fields[1].Spec
		if domain.Type != "string" || domain.Nonempty != nonempty {
			t.Fatalf("domain policy %+v", domain)
		}
	}
}

func TestOptionalWHMJSONBodyCannotOmitAPIVersionRouting(t *testing.T) {
	m, set := jsonFixture(t, bodyDescriptor)
	op := set.Files[1].Operations["whm1:listaccts"]
	optional := false
	op.JSONBody.Required = &optional
	set.Files[1].Operations["whm1:listaccts"] = op
	if _, p := buildProblems(t, m, set); !strings.Contains(p, "WHM JSON bodies must be required") {
		t.Fatalf("optional WHM body spec accepted: %s", p)
	}
}
