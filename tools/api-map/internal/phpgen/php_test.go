package phpgen

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func object(fields ...effective.Field) effective.TypeSpec {
	return effective.TypeSpec{Type: "object", Fields: fields}
}

func TestPhpStringEscapes(t *testing.T) {
	if got := phpString(`it's a \ test`); got != `'it\'s a \\ test'` {
		t.Fatal(got)
	}
}

func TestDocEscapesCommentTerminators(t *testing.T) {
	if got := escapeDoc("Use /* and */ carefully"); strings.Contains(got, "*/") {
		t.Fatal(got)
	}
}

func TestWrapKeepsWordsWithinWidth(t *testing.T) {
	for _, line := range wrap(strings.Repeat("word ", 40), 30) {
		if len(line) > 30 {
			t.Fatalf("line too long: %q", line)
		}
	}
}

func TestShapeQuotesNonIdentifierKeys(t *testing.T) {
	spec := object(
		effective.Field{Name: "0", Spec: effective.TypeSpec{Type: "int"}},
		effective.Field{Name: "MXCHECK-domain", Spec: effective.TypeSpec{Type: "string"}},
		effective.Field{Name: "port", Spec: effective.TypeSpec{Type: "int", Nullable: true}},
	)
	spec.Required = []string{"port"}
	want := "array{\n    '0'?: int,\n    'MXCHECK-domain'?: string,\n    port: int|null,\n}"
	if got := shape(spec, ""); got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}
}

func TestShapeCoversEveryType(t *testing.T) {
	cases := map[string]effective.TypeSpec{
		"''|0|1":                   {Type: "flag", Accept: []string{"empty-string"}},
		"list<string>":             {Type: "list", Items: &effective.TypeSpec{Type: "string"}},
		"array<array-key, 0|1>":    {Type: "map", Values: &effective.TypeSpec{Type: "flag"}},
		"int|float|numeric-string": {Type: "number", Preserve: true},
		"string|int":               {Type: "union", AnyOf: []effective.TypeSpec{{Type: "string"}, {Type: "int"}}},
		"array{}":                  {Type: "object"},
		"mixed":                    {Type: "mixed", Nullable: true},
		"bool|null":                {Type: "bool", Nullable: true},
	}
	for want, spec := range cases {
		if got := shape(spec, ""); got != want {
			t.Errorf("shape(%+v) = %q, want %q", spec, got, want)
		}
	}
}

func TestNativeTypes(t *testing.T) {
	cases := map[string]effective.TypeSpec{
		"string":           {Type: "string"},
		"int|string":       {Type: "flag", Accept: []string{"empty-string"}},
		"?int":             {Type: "int", Nullable: true},
		"int":              {Type: "flag"},
		"int|float|string": {Type: "number", Preserve: true},
		"array":            {Type: "list", Items: &effective.TypeSpec{Type: "string"}},
		"string|int|null":  {Type: "union", Nullable: true, AnyOf: []effective.TypeSpec{{Type: "string"}, {Type: "int"}}},
		"mixed":            {Type: "union", AnyOf: []effective.TypeSpec{{Type: "string"}, {Type: "mixed"}}},
	}
	for want, spec := range cases {
		if got := nativeType(spec); got != want {
			t.Errorf("nativeType(%+v) = %q, want %q", spec, got, want)
		}
	}
}

func TestSpecLiteralInlinesScalarsAndBreaksContainers(t *testing.T) {
	if got := specLiteral(effective.TypeSpec{Type: "int", Accept: []string{"numeric-string"}}, ""); got != "['type' => 'int', 'accept' => ['numeric-string']]" {
		t.Fatal(got)
	}
	spec := object(effective.Field{Name: "a", Spec: effective.TypeSpec{Type: "string"}})
	spec.Required = []string{"a"}
	want := "[\n    'type' => 'object',\n    'fields' => [\n        'a' => ['type' => 'string'],\n    ],\n    'required' => ['a'],\n]"
	if got := specLiteral(spec, ""); got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}
}

func TestNativeTypeFlattensNestedUnions(t *testing.T) {
	cases := map[string]effective.TypeSpec{
		"string|int|null": {Type: "union", AnyOf: []effective.TypeSpec{
			{Type: "union", AnyOf: []effective.TypeSpec{{Type: "string", Nullable: true}}},
			{Type: "int"},
		}},
		"string|int|bool|null": {Type: "union", AnyOf: []effective.TypeSpec{
			{Type: "union", AnyOf: []effective.TypeSpec{{Type: "string"}, {Type: "int", Nullable: true}}},
			{Type: "bool"},
		}},
	}
	for want, spec := range cases {
		if got := nativeType(spec); got != want {
			t.Errorf("nativeType(%+v) = %q, want %q", spec, got, want)
		}
	}
}

func TestShapeEscapesDocTerminatorInKeys(t *testing.T) {
	spec := object(effective.Field{Name: "a*/b", Spec: effective.TypeSpec{Type: "string"}})
	if got := shape(spec, ""); strings.Contains(got, "*/") {
		t.Fatal(got)
	}
}
