package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func projectedFixture(t *testing.T) (model.Map, overlay.Set, map[string]any) {
	t.Helper()
	m := fixtureMap(t)
	root := map[string]any{"type": "object", "properties": map[string]any{
		"metadata":     map[string]any{"properties": map[string]any{"result": map[string]any{"type": "integer", "enum": []any{0, 1}}}},
		"details":      map[string]any{"type": "object", "properties": map[string]any{"updates": map[string]any{"type": "object"}, "quota": map[string]any{"type": "integer", "enum": []any{1}}}},
		"needs_reboot": map[string]any{"type": "integer", "enum": []any{0, 1}},
	}, "required": []any{"metadata", "needs_reboot"}}
	setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), root)
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	if err := json.Unmarshal([]byte(`{"response_fields":["details","needs_reboot"]}`), &op); err != nil {
		t.Fatal(err)
	}
	for _, o := range m.Operations {
		if o.ID == "whm1:createacct" {
			loc := o.Variants[0].OperationLocator
			op.Evidence = []overlay.Evidence{{Kind: "doc", Version: "pinned", Date: "2026-10-13", Note: "full siblings", SourceSHA256: loc.SourceSHA256, Pointer: *loc.Pointer + "/responses/200/content/application~1json/schema"}}
		}
	}
	set.Files[1].Operations["whm1:createacct"] = op
	return m, set, root
}

func TestResponseFieldsLowerCompleteSourceShape(t *testing.T) {
	m, set, _ := projectedFixture(t)
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	op := groupByClass(t, result, "Accounts").Operations[0]
	if op.Returns == nil || op.Returns.Type != "object" || fieldNames(*op.Returns) != "details,needs_reboot" {
		t.Fatalf("missing sibling object: %+v", op.Returns)
	}
	if strings.Join(op.Returns.Required, ",") != "needs_reboot" || op.Returns.Fields[1].Spec.Type != "flag" {
		t.Fatalf("lost requiredness/types: %+v", op.Returns)
	}
	details := op.Returns.Fields[0].Spec
	if details.Fields[0].Spec.Type != "int" || details.Fields[1].Spec.Type != "map" || details.Fields[1].Spec.Values.Type != "mixed" {
		t.Fatalf("invented quota/map semantics: %+v", details)
	}
}

func TestResponseFieldsRejectInvalidSourceBindingAndSelection(t *testing.T) {
	for _, name := range []string{"hash", "pointer", "unrelated", "note", "omitted", "unknown", "duplicate", "reserved", "nullable", "typeless", "composed", "additional", "malformed-required", "unknown-required", "data", "metadata", "unresolved", "nested-required", "nested-type", "nested-nullable", "nested-properties", "uapi", "void", "optional"} {
		t.Run(name, func(t *testing.T) {
			m, set, root := projectedFixture(t)
			op := set.Files[1].Operations["whm1:createacct"]
			fields := `["details","needs_reboot"]`
			switch name {
			case "hash":
				op.Evidence[0].SourceSHA256 = strings.Repeat("0", 64)
			case "pointer":
				op.Evidence[0].Pointer += "/properties/details"
			case "unrelated":
				op.Evidence = evidence()
			case "note":
				op.Evidence[0].Note = " "
			case "omitted":
				fields = `["details"]`
			case "unknown":
				fields = `["details","needs_reboot","future"]`
			case "duplicate":
				fields = `["details","details"]`
			case "reserved":
				fields = `["metadata","needs_reboot"]`
			case "nullable":
				root["nullable"] = true
			case "typeless":
				delete(root, "type")
			case "composed":
				root["anyOf"] = []any{map[string]any{"type": "object"}}
			case "additional":
				root["additionalProperties"] = true
			case "malformed-required":
				root["required"] = []any{1}
			case "unknown-required":
				root["required"] = []any{"missing"}
			case "data":
				root["properties"].(map[string]any)["data"] = map[string]any{"type": "object"}
			case "metadata":
				delete(root["properties"].(map[string]any), "metadata")
			case "unresolved":
				root["properties"].(map[string]any)["details"] = map[string]any{"$ref": "#/missing"}
			case "nested-required":
				root["properties"].(map[string]any)["details"].(map[string]any)["required"] = []any{false}
			case "nested-type":
				root["properties"].(map[string]any)["needs_reboot"].(map[string]any)["type"] = "banana"
			case "nested-nullable":
				root["properties"].(map[string]any)["details"].(map[string]any)["nullable"] = "true"
			case "nested-properties":
				root["properties"].(map[string]any)["details"].(map[string]any)["type"] = "string"
			case "uapi":
				for i := range m.Operations {
					if m.Operations[i].ID == "whm1:createacct" {
						m.Operations[i].Family = "uapi"
					}
				}
			case "void":
				op.Void = true
			case "optional":
				op.OptionalPayload = true
			}
			if err := json.Unmarshal([]byte(`{"response_fields":`+fields+`}`), &op); err != nil {
				t.Fatal(err)
			}
			set.Files[1].Operations["whm1:createacct"] = op
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "response_fields") {
				t.Fatalf("accepted %s: %s", name, problems)
			}
		})
	}
}
