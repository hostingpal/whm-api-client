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

func TestRenderReviewedParameterTypeAndDescriptionCorrections(t *testing.T) {
	for _, sourceType := range []string{"string", "integer"} {
		t.Run(sourceType, func(t *testing.T) {
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
						if p.Name == "username" || p.Name == "password" {
							p.Definition["schema"] = map[string]any{"type": sourceType}
						}
					}
				}
			}
			var file overlay.File
			if err := json.Unmarshal([]byte(`{
		"family":"whm1","group":"Accounts","accessor":"accounts",
		"operations":{"whm1:createacct":{
			"name":"createAccount",
			"params":{
				"username":{"type":"string|int","description":"The deploy_id returned by creation. Keep integer identifiers."},
				"password":{"type":"string|int","description":"Optional identifier. Preserve omission."}
			},
			"evidence":[{"kind":"live","version":"11.138.0.19","date":"2026-10-31","note":"Reviewed identifier shape."}]
		}}
	}`), &file); err != nil {
				t.Fatal(err)
			}
			result, err := effective.Build(m, overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{file}})
			if err != nil || len(result.Problems) != 0 {
				t.Fatalf("build: %v %v", err, result.Problems)
			}
			files := Render(result)
			class := string(files["src/Api/Whm/Accounts.php"])
			for _, want := range []string{
				"@param string|int $username The deploy_id returned by creation. Keep integer identifiers.",
				"@param string|int|null $password Optional identifier. Preserve omission. Null omits it.",
				"string|int $username,",
				"string|int|null $password = null,",
				"?string $plan = null",
				"@param 'default'|'custom'|null $plan",
			} {
				if !strings.Contains(class, want) {
					t.Errorf("missing %q in generated output:\n%s", want, class)
				}
			}
			if strings.Contains(class, "The account's username.") || strings.Contains(class, "The password.") {
				t.Error("original parameter description survived its reviewed replacement")
			}
			// Integer password parameters retain their existing strength-setting
			// interpretation; only the string source infers a sensitive password.
			if got := strings.Contains(class, "#[SensitiveParameter]\n        string|int|null $password = null,"); got != (sourceType == "string") {
				t.Errorf("parameter correction changed password sensitivity for %s source", sourceType)
			}
			spec := string(files["src/Api/Whm/Spec/AccountsSpec.php"])
			for _, want := range []string{
				"'username' => ['name' => 'username', 'encoding' => 'scalar'],",
				"'password' => ['name' => 'password', 'encoding' => 'scalar'],",
			} {
				if !strings.Contains(spec, want) {
					t.Errorf("missing %q in generated spec:\n%s", want, spec)
				}
			}
		})
	}
}
