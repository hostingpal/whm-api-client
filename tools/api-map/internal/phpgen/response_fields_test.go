package phpgen

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"strings"
	"testing"
)

func TestRenderResponseFieldsOnlyWhenExplicitAndAliasesInherit(t *testing.T) {
	var op effective.Operation
	if err := json.Unmarshal([]byte(`{"ID":"whm1:fixture","Function":"fixture","PHPName":"fixture","Constant":"FIXTURE","Method":"POST","Outcome":"flag","ResponseFields":["details","needs_reboot"],"Returns":{"Type":"object","Fields":[{"Name":"needs_reboot","Spec":{"Type":"flag"}},{"Name":"details","Spec":{"Type":"object"}}]},"AdditionalMethods":[{"PHPName":"alternate","Constant":"ALTERNATE"}]}`), &op); err != nil {
		t.Fatal(err)
	}
	ordinary := op
	ordinary.ID = "whm1:ordinary"
	ordinary.Function = "ordinary"
	ordinary.PHPName = "ordinary"
	ordinary.Constant = "ORDINARY"
	ordinary.AdditionalMethods = nil
	// Decode absent settings onto a fresh operation so the ordinary output stays data-only.
	ordinary = effective.Operation{ID: ordinary.ID, Function: ordinary.Function, PHPName: ordinary.PHPName, Constant: ordinary.Constant, Method: "GET", Outcome: "flag", Returns: ordinary.Returns}
	files := Render(effective.Result{Groups: []effective.Group{{Family: "whm1", Class: "Examples", Name: "Examples", Accessor: "examples", Operations: []effective.Operation{op, ordinary}}}})
	spec := string(files["src/Api/Whm/Spec/ExamplesSpec.php"])
	if strings.Count(spec, `'response_fields' => ['details', 'needs_reboot'],`) != 1 || !strings.Contains(spec, "...self::FIXTURE,") {
		t.Fatalf("missing/inferred projection: %s", spec)
	}
}
