package effective

import (
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestSingleResultStatusChecksSourceAndShape(t *testing.T) {
	const base = "/paths/~1ZoneEdit~1resetzone/get"
	for _, test := range []string{"valid", "wrong operation", "optional payload", "other flag encoding", "missing status", "wrong evidence"} {
		t.Run(test, func(t *testing.T) {
			ptr := base
			variant := model.Variant{OperationLocator: model.Locator{SourceSHA256: "source", Pointer: &ptr}}
			op := Operation{ID: "uapi:ZoneEdit/resetzone", Method: "POST", Outcome: "flag"}
			ov := overlay.Operation{SingleResultStatus: true, Evidence: []overlay.Evidence{{
				Kind: "doc", SourceSHA256: "source", Note: "strict nested flag", Pointer: base + "/responses/200/content/application~1json/schema/properties/data/items/properties/result/properties/status",
			}}}
			flag := TypeSpec{Type: "flag"}
			result := TypeSpec{Type: "object", Required: []string{"status"}}
			switch test {
			case "wrong operation":
				op.ID = "uapi:DNS/resetzone"
			case "optional payload":
				ov.OptionalPayload = true
			case "other flag encoding":
				flag.Accept = []string{"string-flag"}
			case "missing status":
				result.Required = nil
			case "wrong evidence":
				ov.Evidence[0].SourceSHA256 = "wrong"
			}
			result.Fields = []Field{{Name: "status", Spec: flag}}
			row := TypeSpec{Type: "object", Required: []string{"result"}, Fields: []Field{{Name: "result", Spec: result}}}
			payload := TypeSpec{Type: "list", Items: &row}
			b := builder{problems: map[string]bool{}}
			enabled := b.singleResultStatus(op, variant, ov, &payload)
			if test == "valid" && (!enabled || len(b.problems) != 0) {
				t.Fatalf("valid policy rejected: %v", b.problems)
			}
			if test != "valid" && len(b.problems) == 0 {
				t.Fatal("invalid policy accepted")
			}
		})
	}
}
