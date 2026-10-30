package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestMergeObjectsCountsEachRequiredNameOncePerObject(t *testing.T) {
	a := TypeSpec{Type: "object", Fields: []Field{{Name: "a", Spec: TypeSpec{Type: "string"}}}, Required: []string{"a", "a"}}
	b := TypeSpec{Type: "object", Fields: []Field{{Name: "a", Spec: TypeSpec{Type: "string"}}}}
	if merged := mergeObjects([]TypeSpec{a, b}); len(merged.Required) != 0 {
		t.Fatalf("a field one object requires twice became required: %+v", merged)
	}
}

func TestApplyCorrectionMergesAcceptWithSchemaDerivedTokens(t *testing.T) {
	root := &TypeSpec{Type: "object", Fields: []Field{{Name: "f", Spec: TypeSpec{Type: "flag", Accept: []string{"string-flag"}}}}}
	if err := applyCorrection(root, "f", overlay.Correction{Accept: []string{"bool", "string-flag"}, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(root.Fields[0].Spec.Accept, ","); got != "string-flag,bool" {
		t.Fatalf("accept %s", got)
	}
}

func TestSpecForMergesListAndMapBranches(t *testing.T) {
	spec := specOf(t, `{"oneOf": [
		{"type": "array", "items": {"type": "object", "properties": {"a": {"type": "string"}}}},
		{"type": "array", "items": {"type": "object", "properties": {"b": {"type": "integer"}}}}
	]}`)
	if spec.Type != "list" || spec.Items.Type != "object" || fieldNames(*spec.Items) != "a,b" {
		t.Fatalf("list branches: %+v", spec)
	}
	spec = specOf(t, `{"anyOf": [
		{"type": "object", "additionalProperties": {"type": "object", "properties": {"a": {"type": "string"}}}},
		{"type": "object", "additionalProperties": {"type": "object", "properties": {"b": {"type": "string"}}}}
	]}`)
	if spec.Type != "map" || spec.Values.Type != "object" || fieldNames(*spec.Values) != "a,b" {
		t.Fatalf("map branches: %+v", spec)
	}
	spec = specOf(t, `{"oneOf": [
		{"type": "string"},
		{"type": "array", "items": {"type": "string"}},
		{"type": "array", "items": {"type": "integer"}}
	]}`)
	if spec.Type != "union" || len(spec.AnyOf) != 2 || spec.AnyOf[1].Type != "list" || spec.AnyOf[1].Items.Type != "union" {
		t.Fatalf("scalar branch beside merged lists: %+v", spec)
	}
	var kinds []string
	for _, option := range spec.AnyOf[1].Items.AnyOf {
		kinds = append(kinds, option.Type)
	}
	if strings.Join(kinds, ",") != "string,int" {
		t.Fatalf("merged list items: %v", kinds)
	}
}

func TestFlagEmptyStringIsAnExplicitPreservedVariant(t *testing.T) {
	root := &TypeSpec{Type: "object", Fields: []Field{{Name: "f", Spec: TypeSpec{Type: "flag"}}}}
	if err := applyCorrection(root, "f", overlay.Correction{Accept: []string{"empty-string"}, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	if len(root.Fields[0].Spec.Accept) != 1 || root.Fields[0].Spec.Accept[0] != "empty-string" || root.Fields[0].Spec.Type != "flag" {
		t.Fatalf("unexpected flag %+v", root)
	}
	root = &TypeSpec{Type: "object", Fields: []Field{{Name: "f", Spec: TypeSpec{Type: "string"}}}}
	if err := applyCorrection(root, "f", overlay.Correction{Accept: []string{"empty-string"}, Evidence: evidence()}); err == nil {
		t.Fatal("empty-string accepted on nonflag")
	}
}
