package phpgen

import (
	"bytes"
	"encoding/json"
	"os"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestRenderScalarSourceFidelity(t *testing.T) {
	raw, err := os.ReadFile("../effective/testdata/fixture-map.json")
	if err != nil {
		t.Fatal(err)
	}
	var m model.Map
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&m); err != nil {
		t.Fatal(err)
	}
	for _, op := range m.Operations {
		if op.ID == "whm1:createacct" {
			for _, p := range op.Variants[0].Parameters {
				switch p.Name {
				case "username":
					// Pinned WordPressBackup/cancel and start use this outer-string anyOf.
					p.Definition["schema"] = map[string]any{"type": "string", "anyOf": []any{
						map[string]any{"format": "domain", "example": "example.com"},
						map[string]any{"format": "url-path", "example": "example.com/wordpress"},
					}}
				case "password":
					delete(p.Definition, "schema")
					// Provider-display configurations is a query parameter with JSON content,
					// not a JSON request body. Its nested properties do not become PHP inputs.
					p.Definition["content"] = map[string]any{"application/json": map[string]any{"schema": map[string]any{
						"type": "object", "properties": map[string]any{"color": map[string]any{"type": "string"}},
					}}}
				}
			}
		}
	}
	set := overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{{
		Family: "whm1", Group: "Accounts", Accessor: "accounts", Operations: map[string]overlay.Operation{
			"whm1:createacct": {Name: "createAccount"},
		},
	}}}
	result, err := effective.Build(m, set)
	if err != nil || len(result.Problems) > 0 {
		t.Fatalf("%v %v", err, result.Problems)
	}
	files := Render(result)
	class := string(files["src/Api/Whm/Accounts.php"])
	for _, want := range []string{"string $username,", "?string $password = null,", "@param string $username", "@param string|null $password", "#[SensitiveParameter]"} {
		if !strings.Contains(class, want) {
			t.Errorf("missing %q in generated class", want)
		}
	}
	spec := string(files["src/Api/Whm/Spec/AccountsSpec.php"])
	for _, want := range []string{"'method' => 'POST'", "'username' => ['name' => 'username', 'encoding' => 'scalar']", "'password' => ['name' => 'password', 'encoding' => 'scalar']", "'uid' => ['type' => 'int']"} {
		if !strings.Contains(spec, want) {
			t.Errorf("missing %q in generated spec", want)
		}
	}
	if strings.Contains(spec, "'body'") || strings.Contains(class, "string|int") {
		t.Fatal("source strings introduced a body encoder or integer native input")
	}
}
