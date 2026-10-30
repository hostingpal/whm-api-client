package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestIntegralFixedPointStringAppliesOnlyToIntegerSpecs(t *testing.T) {
	for _, accepts := range [][]string{{"integral-fixed-point-string"}, {"numeric-string", "integral-fixed-point-string"}} {
		spec, err := fromOverlay("", overlay.Spec{Type: "int", Accept: accepts})
		if err != nil || spec.Type != "int" || strings.Join(spec.Accept, ",") != strings.Join(accepts, ",") {
			t.Fatalf("integer acceptance lost: %+v %v", spec, err)
		}
		root := TypeSpec{Type: "int"}
		if err := applyCorrection(&root, "", overlay.Correction{Replace: &overlay.Spec{Type: "int", Accept: accepts}}); err != nil {
			t.Fatal(err)
		}
	}
	for _, kind := range []string{"number", "string", "bool", "flag", "mixed", "list", "map", "object", "union"} {
		if err := checkAccept("", TypeSpec{Type: kind, Accept: []string{"integral-fixed-point-string"}}); err == nil {
			t.Fatalf("accepted integer-only variant on %s", kind)
		}
	}
}
