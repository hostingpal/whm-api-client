package effective

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"strings"
	"testing"
)

func TestExplicitJSONAdditionalValuesLowerOnlyAtTypedObjectBoundary(t *testing.T) {
	s, err := newSchemas(fixtureMap(t))
	if err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct {
		raw   string
		valid bool
	}{
		{`{"type":"object","additionalProperties":true}`, true},
		{`{"type":"object","additionalProperties":{}}`, true},
		{`{}`, false}, {`{"additionalProperties":true}`, false},
		{`{"type":"object","properties":{"x":{}}}`, false},
		{`{"type":"array","items":{}}`, false},
		{`{"type":"object","additionalProperties":{"description":"untyped"}}`, false},
		{`{"type":"object","additionalProperties":{"$ref":"#/missing"}}`, false},
		{`{"type":"object","additionalProperties":{"allOf":[{}]}}`, false},
		{`{"type":"object","additionalProperties":[]}`, false},
		{`{"type":"object","additionalProperties":null}`, false},
	} {
		var raw any
		if err := json.Unmarshal([]byte(tc.raw), &raw); err != nil {
			t.Fatal(err)
		}
		got, err := s.inputFor("whm-openapi", raw, 0, 0, "", nil, map[string]bool{}, model.Locator{}, "")
		if !tc.valid {
			if err == nil {
				t.Fatalf("untyped source accepted: %s: %+v", tc.raw, got)
			}
			continue
		}
		if err != nil || got.Values == nil || got.Values.Type != "json" {
			t.Fatalf("explicit additional schema lost: %s: %+v, %v", tc.raw, got, err)
		}
		if InputNative(got) != `array|\HostingPal\WhmApiClient\JsonObject` {
			t.Fatal(InputNative(got))
		}
	}
}

func TestExplicitJSONStillRequiresExactWholeBodyEvidence(t *testing.T) {
	for _, extra := range []any{true, map[string]any{}} {
		for _, correct := range []bool{true, false} {
			m, set := jsonFixture(t, bodyDescriptor)
			node := fixtureNode(t, m, "whm1:listaccts")
			node["requestBody"] = map[string]any{"required": true, "content": map[string]any{"application/json": map[string]any{"schema": map[string]any{"type": "object", "additionalProperties": extra}}}}
			op := set.Files[1].Operations["whm1:listaccts"]
			op.JSONBody.Corrections = nil
			if !correct {
				op.Evidence = []overlay.Evidence{{Kind: "doc", SourceSHA256: "wrong", Pointer: "/paths/~1listaccts/get/requestBody", Note: "synthetic"}}
			}
			set.Files[1].Operations["whm1:listaccts"] = op
			result, problems := buildProblems(t, m, set)
			if !correct {
				if !strings.Contains(problems, "needs doc evidence") {
					t.Fatal(problems)
				}
				continue
			}
			if problems != "" {
				t.Fatal(problems)
			}
			got := groupByClass(t, result, "Accounts").Operations[1].JSONBody.Schema
			if got.Values == nil || got.Values.Type != "json" {
				t.Fatalf("%+v", got)
			}
		}
	}
}

func TestExplicitJSONFieldRetainsParameterSourceBindingAndRawCanonical(t *testing.T) {
	for _, additional := range []string{`true`, `{}`} {
		for _, correct := range []bool{true, false} {
			descriptor := fieldAlias
			if !correct {
				descriptor = strings.Replace(descriptor, `/parameters/0"`, `/parameters/0/schema"`, 1)
			}
			m, set := fieldFixture(t, `{"type":"object","properties":{"known":{"type":"integer"}},"required":["known"],"additionalProperties":`+additional+`}`, descriptor)
			result, problems := buildProblems(t, m, set)
			if !correct {
				if !strings.Contains(problems, "matching source_sha256") {
					t.Fatal(problems)
				}
				continue
			}
			if problems != "" {
				t.Fatal(problems)
			}
			op := groupByClass(t, result, "Accounts").Operations[0]
			if op.Params[0].PHPType != "string" || op.Params[0].Input != nil {
				t.Fatal("raw canonical altered")
			}
			input := op.Methods()[1].Params[0]
			if input.Input.Values.Type != "json" || len(input.Input.Fields) != 1 || input.Input.Fields[0].Spec.Type != "int" || len(input.Input.Required) != 1 {
				t.Fatalf("known schema widened: %+v", input)
			}
		}
	}
}

func TestExplicitJSONNativeHelperNeverCreatesCoercingScalarUnion(t *testing.T) {
	if got := InputNative(InputSpec{Type: "json"}); got != "mixed" {
		t.Fatal(got)
	}
	if got := InputNative(InputSpec{Type: "json", Nullable: true}); got != "mixed" {
		t.Fatal(got)
	}
}
