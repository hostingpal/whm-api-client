package effective

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"strings"
	"testing"
)

func TestRowsPolicyBindsDeclaredNonnullableObjectRows(t *testing.T) {
	for _, change := range []string{"valid", "flag", "WHM", "optional", "unwrap", "void", "unknown", "non-list", "scalar row", "nullable root", "nullable rows", "nullable row", "no evidence", "wrong hash", "wrong pointer"} {
		t.Run(change, func(t *testing.T) {
			m := fixtureMap(t)
			id := "uapi:DomainInfo/list_domains"
			if change == "WHM" {
				id = "whm1:createacct"
			}
			row := map[string]any{"type": "object", "properties": map[string]any{"reason": map[string]any{"type": "string"}}}
			rows := map[string]any{"type": "array", "items": row}
			data := map[string]any{"type": "object", "properties": map[string]any{"uploads": rows, "failed": map[string]any{"type": "integer"}}}
			switch change {
			case "nullable root":
				data["nullable"] = true
			case "nullable rows":
				rows["nullable"] = true
			case "nullable row":
				row["nullable"] = true
			case "non-list":
				rows["type"] = "string"
				delete(rows, "items")
			case "scalar row":
				rows["items"] = map[string]any{"type": "string"}
			}
			envelope := map[string]any{"type": "object", "properties": map[string]any{"data": data}}
			if change != "WHM" {
				envelope = map[string]any{"type": "object", "properties": map[string]any{"result": envelope}}
			}
			setEnvelope(t, fixtureNode(t, m, id), envelope)
			settings := map[string]any{"rows": "uploads", "outcome": "unchecked", "evidence": []overlay.Evidence{{Kind: "doc", Version: "synthetic", Date: "2026-11-01", Note: "Source-bound row list", SourceSHA256: "x", Pointer: "/paths/~1DomainInfo~1list_domains/get/responses/200/content/application~1json/schema/properties/result/properties/data/properties/uploads"}}}
			switch change {
			case "flag":
				settings["outcome"] = "flag"
			case "optional":
				settings["optional_payload"] = true
			case "unwrap":
				settings["unwrap"] = "uploads"
			case "void":
				settings["void"] = true
			case "unknown":
				settings["rows"] = "missing"
			case "no evidence":
				delete(settings, "evidence")
			}
			if change == "wrong hash" {
				settings["evidence"].([]overlay.Evidence)[0].SourceSHA256 = "wrong"
			}
			if change == "wrong pointer" {
				settings["evidence"].([]overlay.Evidence)[0].Pointer = "/paths/~1other/get"
			}
			raw, _ := json.Marshal(settings)
			var op overlay.Operation
			if err := json.Unmarshal(raw, &op); err != nil {
				t.Fatal(err)
			}
			set := fixtureOverlay("listed")
			index := 0
			if change == "WHM" {
				index = 1
				op.Name = "createAccount"
			}
			set.Files[index].Operations[id] = op
			result, problems := buildProblems(t, m, set)
			if change != "valid" {
				// Some semantic checks belong to overlay validation, before Build.
				problems += strings.Join(overlay.Validate(set), "\n")
				if !strings.Contains(problems, "rows") {
					t.Fatalf("missing rows rejection: %s", problems)
				}
				return
			}
			if problems != "" {
				t.Fatal(problems)
			}
			raw, _ = json.Marshal(groupByClass(t, result, "DomainInfo").Operations[0])
			var rendered map[string]any
			json.Unmarshal(raw, &rendered)
			if rendered["Rows"] != "uploads" {
				t.Fatalf("rows policy lost: %s", raw)
			}
		})
	}
}
