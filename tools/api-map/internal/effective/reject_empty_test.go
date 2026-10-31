package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestRejectEmptyRequiresRequiredStringOrListSource(t *testing.T) {
	for _, tc := range []struct {
		name, setting string
		source        map[string]any
		required      bool
		valid         bool
	}{
		{"valid", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, false, true},
		{"required source overridden optional", `{"encoding":"repeat","required":false,"reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, true, false},
		{"optional", `{"encoding":"repeat","required":false,"reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, false, false},
		{"scalar", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "string"}, false, true},
		{"type override", `{"encoding":"repeat","required":true,"reject_empty":true,"type":"string|int"}`, map[string]any{"type": "string"}, false, false},
		{"enum", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "string", "enum": []any{"domain"}}, false, false},
		{"nullable scalar", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "string", "nullable": true}, false, false},
		{"integer item", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "integer"}}, false, false},
		{"nullable item", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string", "nullable": true}}, false, false},
		{"nullable list", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array", "nullable": true, "items": map[string]any{"type": "string"}}, false, false},
		{"enum-null list", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array", "enum": []any{[]any{"tag"}, nil}, "items": map[string]any{"type": "string"}}, false, false},
		{"enum-null item", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string", "enum": []any{"tag", nil}}}, false, false},
		{"missing item", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array"}, false, false},
		{"composition", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}, "anyOf": []any{map[string]any{"type": "array", "items": map[string]any{"type": "string"}}}}, false, false},
		{"item composition", `{"encoding":"repeat","required":true,"reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string", "oneOf": []any{map[string]any{"type": "string"}}}}, false, false},
		{"scalar encoding", `{"encoding":"scalar","reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, false, false},
		{"programmatic false", `{"encoding":"repeat","reject_empty":false}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, false, false},
		{"numbered", `{"encoding":"numbered","reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, false, false},
		{"bool", `{"encoding":"bool","reject_empty":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, false, false},
		{"omitted", `{"encoding":"repeat","reject_empty":true,"omit":true}`, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, false, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", "tags", tc.source)
			for _, op := range m.Operations {
				if op.ID == "whm1:createacct" {
					for _, p := range op.Variants[0].Parameters {
						if p.Name == "tags" {
							p.Definition["required"] = tc.required
						}
					}
				}
			}
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			var param overlay.Param
			if err := json.Unmarshal([]byte(tc.setting), &param); err != nil {
				t.Fatal(err)
			}
			op.Params = map[string]overlay.Param{"tags": param}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			result, joined := buildProblems(t, m, set)
			if tc.valid {
				if joined != "" {
					t.Fatal(joined)
				}
				raw, _ := json.Marshal(groupByClass(t, result, "Accounts").Operations[0].Params)
				if !strings.Contains(string(raw), `"RejectEmpty":true`) {
					t.Fatalf("setting lost from effective contract: %s", raw)
				}
			} else if !strings.Contains(joined, "reject_empty") {
				t.Fatalf("invalid source accepted: %s", joined)
			}
		})
	}
}
