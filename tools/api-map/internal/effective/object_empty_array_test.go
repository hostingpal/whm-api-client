package effective

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"testing"
)

func TestExplicitObjectEmptyArrayCorrection(t *testing.T) {
	var correction overlay.Correction
	if err := json.Unmarshal([]byte(`{"accept_empty_array":false}`), &correction); err != nil {
		t.Fatal(err)
	}
	root := TypeSpec{Type: "object", Fields: []Field{{Name: "child", Spec: TypeSpec{Type: "object"}}}}
	if err := applyCorrection(&root, "", correction); err != nil {
		t.Fatal(err)
	}
	if root.AcceptEmptyArray == nil || *root.AcceptEmptyArray || root.Fields[0].Spec.AcceptEmptyArray != nil {
		t.Fatal("root-only opt-in lost or leaked")
	}
	if err := applyCorrection(&root, "child", correction); err != nil {
		t.Fatal(err)
	}
	if root.Fields[0].Spec.AcceptEmptyArray == nil || *root.Fields[0].Spec.AcceptEmptyArray {
		t.Fatal("nested opt-in lost")
	}
	var spec overlay.Spec
	if err := json.Unmarshal([]byte(`{"type":"string","accept_empty_array":false}`), &spec); err != nil {
		t.Fatal(err)
	}
	if _, err := fromOverlay("", spec); err == nil {
		t.Fatal("nonobject policy must fail")
	}
}
