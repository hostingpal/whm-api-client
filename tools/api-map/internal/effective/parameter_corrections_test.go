package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func parameterCorrection(t *testing.T, value string) overlay.Param {
	t.Helper()
	var param overlay.Param
	if err := json.Unmarshal([]byte(value), &param); err != nil {
		t.Fatal(err)
	}
	return param
}

func TestBuildCorrectsOnlySelectedScalarStringParameter(t *testing.T) {
	for _, tc := range []struct {
		wire, setting string
		required      bool
	}{
		{"username", `{"type":"string|int","description":"The deploy_id returned by creation. Preserve integer or string identifiers."}`, true},
		{"password", `{"type":"string|int","description":"Replacement text. Keep this second sentence."}`, false},
	} {
		t.Run(tc.wire, func(t *testing.T) {
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			op.Params = map[string]overlay.Param{tc.wire: parameterCorrection(t, tc.setting)}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			result, problems := buildProblems(t, fixtureMap(t), set)
			if problems != "" {
				t.Fatal(problems)
			}
			for _, p := range groupByClass(t, result, "Accounts").Operations[0].Params {
				if p.Wire == tc.wire {
					if p.PHPType != "string|int" || p.DocType != "string|int" || p.Encoding != "scalar" || p.Required != tc.required {
						t.Fatalf("incorrect corrected contract: %+v", p)
					}
					var raw map[string]string
					if err := json.Unmarshal([]byte(tc.setting), &raw); err != nil {
						t.Fatal(err)
					}
					if p.Description != raw["description"] {
						t.Fatalf("replacement lost text: %q", p.Description)
					}
					if p.Sensitive != (tc.wire == "password") {
						t.Fatalf("sensitivity changed: %+v", p)
					}
				} else if p.PHPType != "string" {
					t.Fatalf("unselected parameter widened: %+v", p)
				}
			}
		})
	}
}

func TestBuildRejectsIncompatibleParameterTypeCorrections(t *testing.T) {
	for _, tc := range []struct {
		name    string
		schema  map[string]any
		setting string
	}{
		{"integer", map[string]any{"type": "integer"}, `{"type":"string|int"}`},
		{"number", map[string]any{"type": "number"}, `{"type":"string|int"}`},
		{"boolean", map[string]any{"type": "boolean"}, `{"type":"string|int"}`},
		{"flag", map[string]any{"type": "integer", "enum": []any{0, 1}}, `{"type":"string|int"}`},
		{"mixed", map[string]any{}, `{"type":"string|int"}`},
		{"list", map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, `{"type":"string|int"}`},
		{"object scalar", map[string]any{"type": "object"}, `{"type":"string|int","encoding":"scalar"}`},
		{"repeat", map[string]any{"type": "string"}, `{"type":"string|int","encoding":"repeat"}`},
		{"numbered", map[string]any{"type": "string"}, `{"type":"string|int","encoding":"numbered"}`},
		{"bool encoding", map[string]any{"type": "string"}, `{"type":"string|int","encoding":"bool"}`},
		{"unsupported", map[string]any{"type": "string"}, `{"type":"string|float"}`},
		{"omitted", map[string]any{"type": "string"}, `{"type":"string|int","omit":true}`},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", "candidate", tc.schema)
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			op.Params = map[string]overlay.Param{"candidate": parameterCorrection(t, tc.setting)}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "parameter candidate") || !strings.Contains(problems, "type") {
				t.Fatalf("incompatible type correction accepted: %s", problems)
			}
		})
	}
}

func TestBuildDescriptionCorrectionPreservesBooleanOmission(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[0].Operations["uapi:DomainInfo/list_domains"]
	op.Params = map[string]overlay.Param{"hide_temporary_domains": parameterCorrection(t, `{"description":"Whether enabled. Omitting this parameter applies the server default; false disables it."}`)}
	op.Evidence = evidence()
	set.Files[0].Operations["uapi:DomainInfo/list_domains"] = op
	result, problems := buildProblems(t, fixtureMap(t), set)
	if problems != "" {
		t.Fatal(problems)
	}
	p := groupByClass(t, result, "DomainInfo").Operations[0].Params[0]
	if p.PHPType != "bool" || p.DocType != "bool" || p.Encoding != "bool" || p.Required || p.Description != "Whether enabled. Omitting this parameter applies the server default; false disables it." {
		t.Fatalf("boolean contract or replacement lost: %+v", p)
	}
}
