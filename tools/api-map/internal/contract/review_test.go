package contract

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"testing"
)

func TestServerMetadataContexts(t *testing.T) {
	for _, scope := range []string{"document", "path", "operation"} {
		t.Run(scope, func(t *testing.T) {
			m, v := fixture()
			root := m.Documents["s"]
			path := root["paths"].(model.Object)["/M/f"].(model.Object)
			op := path["get"].(model.Object)
			loc := v.OperationLocator
			var owner model.Object
			switch scope {
			case "document":
				owner = root
				loc.Pointer = ptr("")
			case "path":
				owner = path
				loc.Pointer = ptr("/paths/~1M~1f")
			case "operation":
				owner = op
			}
			variable := model.Object{"default": "api", "enum": []any{"api", "test"}, "description": "old variable prose", "x-data": model.Object{"description": "literal variable data"}}
			server := model.Object{"url": "https://{x-host}.example.test", "description": "old server prose", "variables": model.Object{"x-host": variable}, "x-data": model.Object{"description": "literal server data", "$ref": "#/missing"}}
			owner["servers"] = []any{server}
			hash := func() string {
				t.Helper()
				h, err := Hash(m, loc)
				if err != nil {
					t.Fatal(err)
				}
				return h
			}
			before := hash()
			server["description"] = "new server prose"
			variable["description"] = "new variable prose"
			if hash() != before {
				t.Fatal("presentation-only server/variable description changed contract hash")
			}
			for _, edit := range []func(){func() { server["url"] = "https://{x-host}.other.test" }, func() { variable["default"] = "test" }, func() { variable["enum"] = []any{"api"} }, func() { server["x-data"].(model.Object)["description"] = "changed data" }, func() { variable["x-data"].(model.Object)["description"] = "changed variable data" }} {
				before = hash()
				edit()
				if hash() == before {
					t.Fatal("server contract data edit ignored")
				}
			}
		})
	}
}
func TestInfoAndTagMetadataContexts(t *testing.T) {
	m, _ := fixture()
	root := m.Documents["s"]
	root["info"] = model.Object{"title": "API", "version": "1", "description": "old", "x-data": model.Object{"description": "literal"}}
	root["tags"] = []any{model.Object{"name": "test", "description": "old"}}
	loc := model.Locator{SourceID: "s", Pointer: ptr("")}
	before, err := Hash(m, loc)
	if err != nil {
		t.Fatal(err)
	}
	root["info"].(model.Object)["description"] = "new"
	root["tags"].([]any)[0].(model.Object)["description"] = "new"
	after, err := Hash(m, loc)
	if err != nil || before != after {
		t.Fatalf("info/tag prose changed hash: %v", err)
	}
	root["info"].(model.Object)["x-data"].(model.Object)["description"] = "changed data"
	after, err = Hash(m, loc)
	if err != nil || before == after {
		t.Fatalf("info extension data omitted: %v", err)
	}
	for _, tc := range []struct{ pointer, want string }{{"/info", "info"}, {"/tags/0", "tag"}, {"/servers/0", "server"}, {"/servers/0/variables/x-host", "serverVariable"}} {
		if got := KindAt(model.Locator{Pointer: ptr(tc.pointer)}); got != tc.want {
			t.Fatalf("%s kind=%s want=%s", tc.pointer, got, tc.want)
		}
	}
}
func TestPathsExtensionsStayData(t *testing.T) {
	m, _ := fixture()
	paths := m.Documents["s"]["paths"].(model.Object)
	paths["x-data"] = model.Object{"$ref": "#/missing", "description": "literal"}
	loc := model.Locator{SourceID: "s", Pointer: ptr("")}
	before, err := Hash(m, loc)
	if err != nil {
		t.Fatalf("Paths extension interpreted as control reference: %v", err)
	}
	paths["x-data"].(model.Object)["description"] = "new literal"
	after, err := Hash(m, loc)
	if err != nil || before == after {
		t.Fatalf("Paths extension data lost: %v", err)
	}
}
func TestExtensionPrefixedIdentityMapsRemainControlObjects(t *testing.T) {
	m, _ := fixture()
	schemas := m.Documents["s"]["components"].(model.Object)["schemas"].(model.Object)
	schemas["x-schema"] = model.Object{"type": "object", "properties": model.Object{"x-property": model.Object{"$ref": "#/components/schemas/Node"}}}
	loc := model.Locator{SourceID: "s", Pointer: ptr("/components/schemas/x-schema")}
	before, err := Hash(m, loc)
	if err != nil {
		t.Fatal(err)
	}
	schemas["Node"].(model.Object)["required"] = []any{"description"}
	after, err := Hash(m, loc)
	if err != nil || before == after {
		t.Fatalf("x-prefixed schema/property lost reference context: %v", err)
	}
}
