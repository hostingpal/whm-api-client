package effective

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"testing"
)

func TestStringLiteralIsExplicitAndStringOnly(t *testing.T) {
	for _, value := range []string{"", "unlimited"} {
		spec, err := fromOverlay("", overlay.Spec{Type: "string", Literal: &value})
		if err != nil || spec.Literal == nil || *spec.Literal != value {
			t.Fatalf("lost explicit literal: %+v %v", spec, err)
		}
		root := TypeSpec{Type: "string"}
		if err := applyCorrection(&root, "", overlay.Correction{Replace: &overlay.Spec{Type: "string", Literal: &value}}); err != nil || root.Literal == nil || *root.Literal != value {
			t.Fatalf("lost correction: %+v %v", root, err)
		}
	}
	value := "unlimited"
	for _, kind := range []string{"int", "number", "bool", "flag", "mixed", "list", "map", "object", "union"} {
		if err := checkAccept("", TypeSpec{Type: kind, Literal: &value}); err == nil {
			t.Fatalf("accepted literal on %s", kind)
		}
	}
}
