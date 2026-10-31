package phpgen

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"strings"
	"testing"
)

func TestObjectValuesRenderOpenShapeAndExactRuntimeChild(t *testing.T) {
	spec := effective.TypeSpec{Type: "object", Fields: []effective.Field{
		{Name: "known", Spec: effective.TypeSpec{Type: "number"}},
		{Name: "nullable", Spec: effective.TypeSpec{Type: "string", Nullable: true}},
	}, Required: []string{"known"}, Values: &effective.TypeSpec{Type: "list", Items: &effective.TypeSpec{Type: "string"}}}
	want := "array{\n    known: int|float,\n    nullable?: string|null,\n    ...<array-key, list<string>>\n}"
	if got := shape(spec, ""); got != want {
		t.Fatalf("open shape got %q want %q", got, want)
	}
	literal := specLiteral(spec, "")
	for _, part := range []string{"'values' => [", "'type' => 'list'", "'items' => ['type' => 'string']", "'required' => ['known']"} {
		if !strings.Contains(literal, part) {
			t.Fatalf("missing %s in %s", part, literal)
		}
	}
	spec.Fields = nil
	spec.Required = nil
	spec.Values = &effective.TypeSpec{Type: "mixed"}
	if got := shape(spec, ""); got != "array{\n    ...<array-key, mixed>\n}" {
		t.Fatalf("fieldless open shape %s", got)
	}
}

func TestObjectValuesRenderMixedExtrasAlongsideNamedFields(t *testing.T) {
	spec := effective.TypeSpec{Type: "object", Fields: []effective.Field{
		{Name: "page", Spec: effective.TypeSpec{Type: "int"}},
	}, Values: &effective.TypeSpec{Type: "mixed"}}
	want := "array{\n    page?: int,\n    ...<array-key, mixed>\n}"
	if got := shape(spec, ""); got != want {
		t.Fatalf("named field plus mixed extras: got %q want %q", got, want)
	}
	literal := specLiteral(spec, "")
	for _, part := range []string{"'page' => ['type' => 'int']", "'values' => ['type' => 'mixed']"} {
		if !strings.Contains(literal, part) {
			t.Fatalf("missing exact named/mixed child %s in %s", part, literal)
		}
	}
}
