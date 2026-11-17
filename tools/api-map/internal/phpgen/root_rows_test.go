package phpgen

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"strings"
	"testing"
)

func TestRenderRootRowsSelectorUnchanged(t *testing.T) {
	op := effective.Operation{PHPName: "createThumbnails", Alias: "CreateThumbnailsResult", Constant: "CREATE_THUMBNAILS", Function: "create_thumbnails", Method: "POST", Outcome: "unchecked", Rows: "$", Returns: &effective.TypeSpec{Type: "list", Items: &effective.TypeSpec{Type: "object", Fields: []effective.Field{{Name: "reason", Spec: effective.TypeSpec{Type: "string"}}}}}}
	g := effective.Group{Family: "uapi", Name: "ImageManager", Class: "ImageManager", Operations: []effective.Operation{op}}
	spec := specClass(g)
	for _, want := range []string{"'rows' => '$'", "'outcome' => 'unchecked'", "'type' => 'list'", "'type' => 'object'"} {
		if !strings.Contains(spec, want) {
			t.Errorf("missing literal %s in %s", want, spec)
		}
	}
	for _, want := range []string{"@phpstan-type CreateThumbnailsResult list<array{", "reason?: string,", "@return CreateThumbnailsResult"} {
		if !strings.Contains(groupClass(g), want) {
			t.Fatalf("root list PHPDoc lost %s in %s", want, groupClass(g))
		}
	}
}
