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

func TestRenderReviewedPresenceParameterCorrection(t *testing.T) {
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
				if p.Name == "plan" {
					p.Definition["schema"] = map[string]any{"type": "integer", "enum": []any{json.Number("1")}}
				}
			}
		}
	}
	var file overlay.File
	if err := json.Unmarshal([]byte(`{
		"family":"whm1","group":"Accounts","accessor":"accounts",
		"operations":{"whm1:createacct":{
			"name":"createAccount",
			"params":{"plan":{"type":"true","encoding":"bool","description":"True includes the privilege. Null omits this key."}},
			"evidence":[{"kind":"doc","version":"pinned fixture","date":"2026-10-31","note":"Upstream grants a privilege whenever the optional key is present."}]
		}}
	}`), &file); err != nil {
		t.Fatal(err)
	}
	set := overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{file}}
	if problems := overlay.Validate(set); len(problems) != 0 {
		t.Fatalf("overlay: %v", problems)
	}
	result, err := effective.Build(m, set)
	if err != nil || len(result.Problems) != 0 {
		t.Fatalf("build: %v %v", err, result.Problems)
	}
	files := Render(result)
	class := string(files["src/Api/Whm/Accounts.php"])
	for _, want := range []string{
		"@param true|null $plan True includes the privilege. Null omits this key.",
		"?true $plan = null",
		"string $username,",
		"#[SensitiveParameter]\n        ?string $password = null,",
		"'plan' => $plan,",
	} {
		if !strings.Contains(class, want) {
			t.Errorf("missing %q in generated output:\n%s", want, class)
		}
	}
	spec := string(files["src/Api/Whm/Spec/AccountsSpec.php"])
	if !strings.Contains(spec, "'plan' => ['name' => 'plan', 'encoding' => 'bool'],") {
		t.Fatalf("presence input must use the existing bool encoder:\n%s", spec)
	}
}
