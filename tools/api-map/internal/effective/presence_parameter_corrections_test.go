package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestBuildPresenceParameterCorrectionPreservesUnselectedInputs(t *testing.T) {
	for _, setting := range []string{`{"type":"true"}`, `{"type":"true","encoding":"bool"}`, `{"type":"true","required":false,"enum_doc":false}`} {
		t.Run(setting, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", "acl-all", map[string]any{"type": "integer", "enum": []any{json.Number("1")}})
			addQueryParameter(t, m, "whm1:createacct", "unselected", map[string]any{"type": "integer", "enum": []any{json.Number("1")}})
			addQueryParameter(t, m, "whm1:createacct", "save-flag", map[string]any{"type": "integer", "enum": []any{0, 1}})
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			op.Params = map[string]overlay.Param{"acl-all": parameterCorrection(t, setting)}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			result, problems := buildProblems(t, m, set)
			if problems != "" {
				t.Fatal(problems)
			}
			seen := map[string]bool{}
			for _, p := range groupByClass(t, result, "Accounts").Operations[0].Params {
				switch p.Wire {
				case "acl-all":
					seen[p.Wire] = true
					if p.PHPType != "true" || p.DocType != "true" || p.Encoding != "bool" || p.Required || p.PHP != "aclAll" {
						t.Fatalf("presence input lost its native guard or exact bool encoding: %+v", p)
					}
				case "unselected":
					seen[p.Wire] = true
					if p.PHPType != "int" || p.DocType != "1" || p.Encoding != "scalar" || p.Required {
						t.Fatalf("singleton enum was globally inferred: %+v", p)
					}
				case "save-flag":
					seen[p.Wire] = true
					if p.PHPType != "bool" || p.DocType != "bool" || p.Encoding != "bool" || p.Required {
						t.Fatalf("ordinary removal/addition flag changed: %+v", p)
					}
				}
			}
			if len(seen) != 3 {
				t.Fatalf("parameter lost: %v", seen)
			}
		})
	}
}

func TestBuildRejectsIncompatiblePresenceParameterCorrections(t *testing.T) {
	one := map[string]any{"type": "integer", "enum": []any{json.Number("1")}}
	for _, tc := range []struct {
		name, setting string
		schema        map[string]any
		required      bool
	}{
		{"unconstrained integer", `{"type":"true"}`, map[string]any{"type": "integer"}, false},
		{"zero singleton", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{0}}, false},
		{"duplicate singleton", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{1, 1}}, false},
		{"zero one flag", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{0, 1}}, false},
		{"quoted enum", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{"1"}}, false},
		{"decimal enum", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{json.Number("1.0")}}, false},
		{"nullable enum", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{1, nil}}, false},
		{"nullable schema", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{1}, "nullable": true}, false},
		{"nullable type list", `{"type":"true"}`, map[string]any{"type": []any{"integer", "null"}, "enum": []any{1}}, false},
		{"number", `{"type":"true"}`, map[string]any{"type": "number", "enum": []any{1}}, false},
		{"string", `{"type":"true"}`, map[string]any{"type": "string", "enum": []any{"1"}}, false},
		{"boolean", `{"type":"true"}`, map[string]any{"type": "boolean", "enum": []any{true}}, false},
		{"mixed", `{"type":"true"}`, map[string]any{}, false},
		{"list", `{"type":"true","encoding":"bool"}`, map[string]any{"type": "array", "items": one}, false},
		{"object", `{"type":"true","encoding":"bool"}`, map[string]any{"type": "object"}, false},
		{"union", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{1}, "oneOf": []any{one, one}}, false},
		{"allOf", `{"type":"true"}`, map[string]any{"type": "integer", "enum": []any{1}, "allOf": []any{one}}, false},
		{"required source made optional", `{"type":"true","required":false}`, one, true},
		{"optional source made required", `{"type":"true","required":true}`, one, false},
		{"scalar encoding", `{"type":"true","encoding":"scalar"}`, one, false},
		{"repeat encoding", `{"type":"true","encoding":"repeat"}`, one, false},
		{"numbered encoding", `{"type":"true","encoding":"numbered"}`, one, false},
		{"suffix encoding", `{"type":"true","encoding":"suffix-numbered"}`, one, false},
		{"scalar suffix encoding", `{"type":"true","encoding":"suffix-numbered-scalar"}`, one, false},
		{"wire correction", `{"type":"true","wire":"corrected"}`, one, false},
		{"omitted", `{"type":"true","omit":true}`, one, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", "candidate", tc.schema)
			for _, op := range m.Operations {
				if op.ID == "whm1:createacct" {
					for _, p := range op.Variants[0].Parameters {
						if p.Name == "candidate" {
							p.Definition["required"] = tc.required
						}
					}
				}
			}
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			op.Params = map[string]overlay.Param{"candidate": parameterCorrection(t, tc.setting)}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "parameter candidate") || !strings.Contains(problems, "type") {
				t.Fatalf("incompatible presence correction accepted: %s", problems)
			}
		})
	}
}

func TestBuildRequiredTrueCorrectionPreservesRequiredness(t *testing.T) {
	for _, setting := range []string{`{"type":"true"}`, `{"type":"true","encoding":"bool","required":true}`} {
		t.Run(setting, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", "candidate", map[string]any{"type": "integer", "enum": []any{json.Number("1")}})
			for _, op := range m.Operations {
				if op.ID == "whm1:createacct" {
					for _, p := range op.Variants[0].Parameters {
						if p.Name == "candidate" {
							p.Definition["required"] = true
						}
					}
				}
			}
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			op.Params = map[string]overlay.Param{"candidate": parameterCorrection(t, setting)}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			result, problems := buildProblems(t, m, set)
			if problems != "" {
				t.Fatal(problems)
			}
			for _, p := range groupByClass(t, result, "Accounts").Operations[0].Params {
				if p.Wire == "candidate" {
					if p.PHPType != "true" || p.DocType != "true" || p.Encoding != "bool" || !p.Required {
						t.Fatalf("required true guard lost: %+v", p)
					}
					return
				}
			}
			t.Fatal("required parameter lost")
		})
	}
}
