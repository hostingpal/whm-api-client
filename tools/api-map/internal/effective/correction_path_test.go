package effective

import (
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestCorrectionEscapedDotTargetsLiteralFieldOnly(t *testing.T) {
	root := TypeSpec{Type: "list", Items: &TypeSpec{Type: "object", Fields: []Field{
		{Name: "issuer.organizationName", Spec: TypeSpec{Type: "string"}},
		{Name: "issuer", Spec: TypeSpec{Type: "object", Fields: []Field{
			{Name: "organizationName", Spec: TypeSpec{Type: "string"}},
		}}},
	}}}
	yes := true
	if err := applyCorrection(&root, `[].issuer\.organizationName`, overlay.Correction{Nullable: &yes, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	if !root.Items.Fields[0].Spec.Nullable || root.Items.Fields[1].Spec.Fields[0].Spec.Nullable {
		t.Fatalf("correction did not isolate literal dotted field: %+v", root.Items.Fields)
	}
	if err := applyCorrection(&root, `[].issuer.organizationName`, overlay.Correction{Nullable: &yes, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	if !root.Items.Fields[1].Spec.Fields[0].Spec.Nullable {
		t.Fatal("ordinary nested path no longer works")
	}
}

func TestCorrectionPathRejectsMalformedEscapes(t *testing.T) {
	for _, path := range []string{`[].issuer\`, `[].issuer\q`, `[].issuer..name`} {
		if _, err := parsePath(path); err == nil {
			t.Errorf("accepted malformed path %q", path)
		}
	}
}

func TestCorrectionEscapedPathDoesNotTraverseUnionBranches(t *testing.T) {
	root := TypeSpec{Type: "union", AnyOf: []TypeSpec{{Type: "object", Fields: []Field{
		{Name: "issuer.organizationName", Spec: TypeSpec{Type: "string"}},
	}}}}
	yes := true
	if err := applyCorrection(&root, `issuer\.organizationName`, overlay.Correction{Nullable: &yes, Evidence: evidence()}); err == nil {
		t.Fatal("implicitly selected a union branch")
	}
	if root.AnyOf[0].Fields[0].Spec.Nullable {
		t.Fatal("changed union branch")
	}
}

func TestCorrectionPlainListAndMapPathsPreserved(t *testing.T) {
	root := TypeSpec{Type: "object", Fields: []Field{
		{Name: "records", Spec: TypeSpec{Type: "list", Items: &TypeSpec{Type: "map", Values: &TypeSpec{
			Type: "object", Fields: []Field{{Name: "count", Spec: TypeSpec{Type: "int"}}},
		}}}},
	}}
	if err := applyCorrection(&root, `records[]{}.count`, overlay.Correction{Accept: []string{"numeric-string"}, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	got := root.Fields[0].Spec.Items.Values.Fields[0].Spec.Accept
	if len(got) != 1 || got[0] != "numeric-string" {
		t.Fatalf("plain path correction failed: %v", got)
	}
}
