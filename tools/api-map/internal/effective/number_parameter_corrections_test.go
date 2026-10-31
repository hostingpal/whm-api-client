package effective

import (
	"fmt"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestBuildCorrectsOnlySelectedScalarNumberParameter(t *testing.T) {
	for _, required := range []bool{false, true} {
		for _, encoding := range []string{"", "scalar"} {
			t.Run(fmt.Sprintf("required=%t/encoding=%s", required, encoding), func(t *testing.T) {
				m := fixtureMap(t)
				addQueryParameter(t, m, "whm1:createacct", "version", map[string]any{"type": "number", "example": 5.7})
				addQueryParameter(t, m, "whm1:createacct", "unmodified", map[string]any{"type": "number"})
				for _, op := range m.Operations {
					if op.ID == "whm1:createacct" {
						for _, p := range op.Variants[0].Parameters {
							if p.Name == "version" {
								p.Definition["required"] = required
							}
						}
					}
				}
				set := fixtureOverlay("listed")
				op := set.Files[1].Operations["whm1:createacct"]
				op.Params = map[string]overlay.Param{"version": {
					Type: "string|int|float", Encoding: encoding,
					Description: "The exact version returned by discovery. Preserve trailing zeroes such as 10.10.",
				}}
				op.Evidence = []overlay.Evidence{{Kind: "doc", Version: "pinned fixture", Date: "2026-10-31", Note: "Discovery uses version strings but the input schema says number."}}
				set.Files[1].Operations["whm1:createacct"] = op
				result, problems := buildProblems(t, m, set)
				if problems != "" {
					t.Fatal(problems)
				}
				seen := map[string]bool{}
				for _, p := range groupByClass(t, result, "Accounts").Operations[0].Params {
					switch p.Wire {
					case "version":
						seen[p.Wire] = true
						if p.PHPType != "string|int|float" || p.DocType != "string|int|float" || p.Encoding != "scalar" || p.Required != required || p.Sensitive {
							t.Fatalf("incorrect corrected number contract: %+v", p)
						}
						if p.Description != "The exact version returned by discovery. Preserve trailing zeroes such as 10.10." {
							t.Fatalf("description lost text: %q", p.Description)
						}
					case "unmodified":
						seen[p.Wire] = true
						if p.PHPType != "int|float" || p.DocType != "int|float" || p.Encoding != "scalar" || p.Required {
							t.Fatalf("unselected number contract changed: %+v", p)
						}
					}
				}
				if !seen["version"] || !seen["unmodified"] {
					t.Fatalf("parameter lost: %v", seen)
				}
			})
		}
	}
}

func TestBuildRejectsIncompatibleNumberParameterCorrections(t *testing.T) {
	for _, tc := range []struct {
		name    string
		schema  map[string]any
		setting overlay.Param
	}{
		{"string", map[string]any{"type": "string"}, overlay.Param{Type: "string|int|float"}},
		{"integer", map[string]any{"type": "integer"}, overlay.Param{Type: "string|int|float"}},
		{"boolean", map[string]any{"type": "boolean"}, overlay.Param{Type: "string|int|float"}},
		{"flag", map[string]any{"type": "integer", "enum": []any{0, 1}}, overlay.Param{Type: "string|int|float"}},
		{"mixed", map[string]any{}, overlay.Param{Type: "string|int|float"}},
		{"list", map[string]any{"type": "array", "items": map[string]any{"type": "number"}}, overlay.Param{Type: "string|int|float", Encoding: "scalar"}},
		{"object", map[string]any{"type": "object"}, overlay.Param{Type: "string|int|float", Encoding: "scalar"}},
		{"union", map[string]any{"oneOf": []any{map[string]any{"type": "number"}, map[string]any{"type": "string"}}}, overlay.Param{Type: "string|int|float"}},
		{"repeat", map[string]any{"type": "number"}, overlay.Param{Type: "string|int|float", Encoding: "repeat"}},
		{"numbered", map[string]any{"type": "number"}, overlay.Param{Type: "string|int|float", Encoding: "numbered"}},
		{"suffix-numbered", map[string]any{"type": "number"}, overlay.Param{Type: "string|int|float", Encoding: "suffix-numbered"}},
		{"suffix-numbered-scalar", map[string]any{"type": "number"}, overlay.Param{Type: "string|int|float", Encoding: "suffix-numbered-scalar"}},
		{"bool encoding", map[string]any{"type": "number"}, overlay.Param{Type: "string|int|float", Encoding: "bool"}},
		{"omitted", map[string]any{"type": "number"}, overlay.Param{Type: "string|int|float", Omit: true}},
		{"drops floats", map[string]any{"type": "number"}, overlay.Param{Type: "string|int"}},
		{"drops integers", map[string]any{"type": "number"}, overlay.Param{Type: "string|float"}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", "candidate", tc.schema)
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			op.Params = map[string]overlay.Param{"candidate": tc.setting}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "parameter candidate") || !strings.Contains(problems, "type") {
				t.Fatalf("incompatible number correction accepted: %s", problems)
			}
		})
	}
}
