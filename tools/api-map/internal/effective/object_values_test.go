package effective

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"strings"
	"testing"
)

func TestOverlayObjectValuesKeepsKnownFieldsAndChecksExtraChildren(t *testing.T) {
	s := overlay.Spec{Type: "object", Fields: map[string]overlay.Spec{"known": {Type: "number"}}, Required: []string{"known"}, Values: &overlay.Spec{Type: "list", Items: &overlay.Spec{Type: "string"}}}
	got, err := fromOverlay("", s)
	if err != nil || got.Values == nil || got.Values.Type != "list" || got.Values.Items.Type != "string" || fieldSpec(t, got, "known").Type != "number" || len(got.Required) != 1 {
		t.Fatalf("spec %+v err %v", got, err)
	}
	if defects := specDefects(got, ""); len(defects) != 0 {
		t.Fatalf("defects %v", defects)
	}
	s.Values = &overlay.Spec{Type: "list"}
	if _, err := fromOverlay("", s); err == nil || !strings.Contains(err.Error(), "list needs items") {
		t.Fatalf("missing nested rejection: %v", err)
	}
}
