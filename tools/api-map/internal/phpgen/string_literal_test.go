package phpgen

import (
	"encoding/json"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func TestStringLiteralRendersPreciseShapeAndRuntimeConstraint(t *testing.T) {
	var spec effective.TypeSpec
	if err := json.Unmarshal([]byte(`{"Type":"string","Literal":"unlimited"}`), &spec); err != nil {
		t.Fatal(err)
	}
	if got := shape(spec, ""); got != "'unlimited'" {
		t.Fatal(got)
	}
	if got := nativeType(spec); got != "string" {
		t.Fatal(got)
	}
	if got := specLiteral(spec, ""); got != "['type' => 'string', 'literal' => 'unlimited']" {
		t.Fatal(got)
	}
}

func TestStringLiteralEscapingEmptyAndUnion(t *testing.T) {
	for _, tc := range []struct{ value, wantShape, wantSpec string }{
		{"", "''", "['type' => 'string', 'literal' => '']"},
		{`a'b\c*/`, `'a\'b\\c*\/'`, `['type' => 'string', 'literal' => 'a\'b\\c*/']`},
	} {
		spec := effective.TypeSpec{Type: "string", Literal: &tc.value}
		if got := shape(spec, ""); got != tc.wantShape {
			t.Fatalf("shape %q, want %q", got, tc.wantShape)
		}
		if got := specLiteral(spec, ""); got != tc.wantSpec {
			t.Fatalf("spec %q, want %q", got, tc.wantSpec)
		}
	}
	value := "unlimited"
	spec := effective.TypeSpec{Type: "union", AnyOf: []effective.TypeSpec{{Type: "int"}, {Type: "string", Literal: &value}}}
	if got := shape(spec, ""); got != "int|'unlimited'" {
		t.Fatal(got)
	}
	if got := nativeType(spec); got != "int|string" {
		t.Fatal(got)
	}
}
