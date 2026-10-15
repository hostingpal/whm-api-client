package effective

import (
	"encoding/json"
	"reflect"
	"strings"
	"testing"
)

// specOf converts an inline schema written as JSON against the fixture's
// cpanel-openapi document.
func specOf(t *testing.T, schema string) TypeSpec {
	t.Helper()
	var node any
	if err := json.Unmarshal([]byte(schema), &node); err != nil {
		t.Fatal(err)
	}
	s, err := newSchemas(fixtureMap(t))
	if err != nil {
		t.Fatal(err)
	}
	return s.specFor("cpanel-openapi", node, 0, nil)
}

func fieldNames(spec TypeSpec) string {
	names := make([]string, len(spec.Fields))
	for i, field := range spec.Fields {
		names[i] = field.Name
	}
	return strings.Join(names, ",")
}

func fieldSpec(t *testing.T, spec TypeSpec, name string) TypeSpec {
	t.Helper()
	if i := fieldIndex(&spec, name); i >= 0 {
		return spec.Fields[i].Spec
	}
	t.Fatalf("no field %s in %+v", name, spec)
	return TypeSpec{}
}

func TestSpecForTreatsEnumNullAsNullable(t *testing.T) {
	if spec := specOf(t, `{"type": "integer", "enum": [0, 1, null]}`); spec.Type != "flag" || !spec.Nullable {
		t.Fatalf("flag enum with null: %+v", spec)
	}
	if spec := specOf(t, `{"type": "string", "enum": ["a", "b", null]}`); spec.Type != "string" || !spec.Nullable {
		t.Fatalf("string enum with null: %+v", spec)
	}
	// A branch that only admits null makes the union nullable instead of mixed.
	spec := specOf(t, `{"oneOf": [{"enum": [null]}, {"type": "string", "enum": ["unlimited"]}, {"type": "integer"}]}`)
	if spec.Type != "union" || !spec.Nullable || len(spec.AnyOf) != 2 || spec.AnyOf[0].Type != "string" || spec.AnyOf[1].Type != "int" {
		t.Fatalf("null-only branch: %+v", spec)
	}
	if spec := specOf(t, `{"anyOf": [{"type": "null"}, {"type": "string"}]}`); spec.Type != "string" || !spec.Nullable {
		t.Fatalf("null type branch: %+v", spec)
	}
}

func TestSpecForAcceptsStringFlags(t *testing.T) {
	for _, schema := range []string{`{"type": "string", "enum": ["1", "0"]}`, `{"enum": ["0", "1"]}`} {
		if spec := specOf(t, schema); spec.Type != "flag" || !reflect.DeepEqual(spec.Accept, []string{"string-flag"}) {
			t.Errorf("%s: %+v", schema, spec)
		}
	}
	if spec := specOf(t, `{"type": "integer", "enum": [0, 1]}`); spec.Type != "flag" || spec.Accept != nil {
		t.Errorf("integer flag: %+v", spec)
	}
}

func TestSpecForMergesAllOfSiblingsAndSkipsAnnotations(t *testing.T) {
	spec := specOf(t, `{"allOf": [{"type": "object", "properties": {"a": {"type": "string"}}}],
		"properties": {"b": {"type": "integer"}}, "required": ["a", "b"]}`)
	if spec.Type != "object" || fieldNames(spec) != "a,b" || strings.Join(spec.Required, ",") != "a,b" {
		t.Fatalf("allOf siblings: %+v", spec)
	}
	if spec := specOf(t, `{"allOf": [{"description": "Only present sometimes."}, {"type": "integer"}]}`); spec.Type != "int" {
		t.Fatalf("annotation-only part: %+v", spec)
	}
	spec = specOf(t, `{"allOf": [{"description": "The details."}, {"$ref": "#/components/schemas/Node"}]}`)
	if spec.Type != "object" || fieldNames(spec) != "child,name" {
		t.Fatalf("annotation-only part before a reference: %+v", spec)
	}
	spec = specOf(t, `{"allOf": [{"type": "object", "properties": {"a": {"type": "string"}}}, {"required": ["a"]}], "type": "object"}`)
	if spec.Type != "object" || strings.Join(spec.Required, ",") != "a" {
		t.Fatalf("required-only part: %+v", spec)
	}
}

func TestSpecForMergesObjectBranches(t *testing.T) {
	spec := specOf(t, `{"oneOf": [
		{"type": "object", "properties": {"type": {"type": "string"}, "data_b64": {"type": "array", "items": {"type": "string"}}}, "required": ["type", "data_b64"]},
		{"type": "object", "properties": {"type": {"type": "string"}, "text_b64": {"type": "string"}}, "required": ["type"]}
	]}`)
	if spec.Type != "object" || fieldNames(spec) != "data_b64,text_b64,type" || strings.Join(spec.Required, ",") != "type" {
		t.Fatalf("merged branches: %+v", spec)
	}
	spec = specOf(t, `{"anyOf": [
		{"type": "string"},
		{"type": "object", "properties": {"v": {"type": "string"}}},
		{"type": "object", "properties": {"v": {"type": "integer"}, "w": {"type": "integer"}}}
	]}`)
	if spec.Type != "union" || len(spec.AnyOf) != 2 || spec.AnyOf[0].Type != "string" || spec.AnyOf[1].Type != "object" {
		t.Fatalf("scalar branch kept beside the merged object: %+v", spec)
	}
	if v := fieldSpec(t, spec.AnyOf[1], "v"); v.Type != "union" || len(v.AnyOf) != 2 {
		t.Fatalf("conflicting field types: %+v", v)
	}
	if spec := specOf(t, `{"anyOf": [{"type": "string"}, {"type": "string", "nullable": true}]}`); spec.Type != "string" || !spec.Nullable {
		t.Fatalf("duplicate branches: %+v", spec)
	}
}

func TestSpecForExpandsDiscriminatorMappings(t *testing.T) {
	spec := specOf(t, `{"$ref": "#/components/schemas/Record"}`)
	if spec.Type != "object" || fieldNames(spec) != "address,line,text_b64,type" || strings.Join(spec.Required, ",") != "type" {
		t.Fatalf("discriminator mapping: %+v", spec)
	}
	if line := fieldSpec(t, spec, "line"); line.Type != "int" || !line.Nullable {
		t.Fatalf("line: %+v", line)
	}
}

func TestSpecForMarksDynamicKeysBesideProperties(t *testing.T) {
	spec := specOf(t, `{"type": "object", "properties": {"a": {"type": "string"}}, "additionalProperties": {"type": "integer"}}`)
	if spec.Type != "object" || spec.Issue == "" {
		t.Fatalf("dynamic keys beside properties: %+v", spec)
	}
	if spec := specOf(t, `{"type": "object", "additionalProperties": {"type": "integer"}}`); spec.Type != "map" || spec.Issue != "" {
		t.Fatalf("plain map: %+v", spec)
	}
}
