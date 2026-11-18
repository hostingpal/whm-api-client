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

func TestRootRowsPolicyBindsExactSourceDataAndShape(t *testing.T) {
	for _, change := range []string{"valid", "flag", "WHM", "optional", "unwrap", "void", "unknown", "non-list", "scalar row", "nullable root", "nullable rows", "nullable row", "no evidence", "wrong hash", "wrong pointer", "field pointer", "parent pointer"} {
		t.Run(change, func(t *testing.T) {
			m := fixtureMap(t)
			id := "uapi:DomainInfo/list_domains"
			if change == "WHM" {
				id = "whm1:createacct"
			}
			row := map[string]any{"type": "object", "properties": map[string]any{"reason": map[string]any{"type": "string"}}}
			rows := map[string]any{"type": "array", "items": row}
			data := rows
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
			settings := map[string]any{"rows": "$", "outcome": "unchecked", "evidence": []overlay.Evidence{{Kind: "doc", Version: "synthetic", Date: "2026-11-01", Note: "Source-bound row list", SourceSHA256: "x", Pointer: "/paths/~1DomainInfo~1list_domains/get/responses/200/content/application~1json/schema/properties/result/properties/data"}}}
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
			if change == "field pointer" {
				settings["evidence"].([]overlay.Evidence)[0].Pointer += "/properties/uploads"
			}
			if change == "parent pointer" {
				settings["evidence"].([]overlay.Evidence)[0].Pointer = "/paths/~1DomainInfo~1list_domains/get"
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
			if rendered["Rows"] != "$" {
				t.Fatalf("rows policy lost: %s", raw)
			}
		})
	}
}

func TestNullableRowsNeedsLiveEvidenceForTheExactObjectField(t *testing.T) {
	const id = "uapi:DomainInfo/list_domains"
	const pointer = "/paths/~1DomainInfo~1list_domains/get/responses/200/content/application~1json/schema/properties/result/properties/data/properties/mail.rows"
	for _, change := range []string{"observed", "no correction", "doc only", "nested field only", "operation live only", "wrong source pointer", "wrong source hash", "invalid observation date", "no nullable decision"} {
		t.Run(change, func(t *testing.T) {
			m := fixtureMap(t)
			row := map[string]any{"type": "object", "properties": map[string]any{"reason": map[string]any{"type": "string"}}}
			list := map[string]any{"type": "array", "items": row}
			if change != "observed" {
				list["nullable"] = true
			}
			data := map[string]any{"type": "object", "properties": map[string]any{"mail.rows": list,
				"mail": map[string]any{"type": "object", "properties": map[string]any{"rows": list}}}}
			setEnvelope(t, fixtureNode(t, m, id), map[string]any{"type": "object", "properties": map[string]any{
				"result": map[string]any{"type": "object", "properties": map[string]any{"data": data}}}})

			doc := overlay.Evidence{Kind: "doc", Version: "pin", Date: "2026-11-01", Note: "Source list field", SourceSHA256: "x", Pointer: pointer}
			live := overlay.Evidence{Kind: "live", Version: "test server", Date: "2026-11-01", Note: "Observed null field"}
			yes := true
			correction := overlay.Correction{Nullable: &yes, Evidence: []overlay.Evidence{live}}
			path := `mail\.rows`
			op := overlay.Operation{Outcome: "unchecked", Rows: "mail.rows", Evidence: []overlay.Evidence{doc}}
			switch change {
			case "doc only":
				correction.Evidence = []overlay.Evidence{doc}
			case "nested field only":
				path = "mail.rows"
			case "operation live only":
				op.Evidence = append(op.Evidence, live)
				correction.Evidence = []overlay.Evidence{doc}
			case "wrong source pointer":
				op.Evidence[0].Pointer += "/items"
			case "wrong source hash":
				op.Evidence[0].SourceSHA256 = "different"
			case "invalid observation date":
				correction.Evidence[0].Date = "unknown"
			case "no nullable decision":
				correction.Nullable = nil
				correction.Required = &yes
			}
			if change != "no correction" {
				op.Response = map[string]overlay.Correction{path: correction}
			}
			set := fixtureOverlay("listed")
			set.Files[0].Operations[id] = op
			result, problems := buildProblems(t, m, set)
			if change != "observed" {
				want := "nullable rows field needs a nullable correction with live evidence for that exact field"
				if change == "wrong source pointer" || change == "wrong source hash" {
					want = "rows needs exact source-bound result field evidence"
				}
				if !strings.Contains(problems, want) {
					t.Fatalf("expected %q, got %q", want, problems)
				}
				return
			}
			if problems != "" {
				t.Fatal(problems)
			}
			batch := groupByClass(t, result, "DomainInfo").Operations[0]
			index := fieldIndex(batch.Returns, "mail.rows")
			if batch.Rows != "mail.rows" || index < 0 || !batch.Returns.Fields[index].Spec.Nullable {
				t.Fatalf("lost nullable literal field: %+v", batch)
			}
		})
	}
}
