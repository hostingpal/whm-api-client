package effective

import (
	"strings"
	"testing"
)

func TestMergeObjectsCountsEachRequiredNameOncePerObject(t *testing.T) {
	a := TypeSpec{Type: "object", Fields: []Field{{Name: "a", Spec: TypeSpec{Type: "string"}}}, Required: []string{"a", "a"}}
	b := TypeSpec{Type: "object", Fields: []Field{{Name: "a", Spec: TypeSpec{Type: "string"}}}}
	if merged := mergeObjects([]TypeSpec{a, b}); len(merged.Required) != 0 {
		t.Fatalf("a field one object requires twice became required: %+v", merged)
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
