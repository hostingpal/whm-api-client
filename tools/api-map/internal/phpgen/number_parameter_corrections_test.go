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

func TestRenderReviewedNumberParameterCorrection(t *testing.T) {
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
					p.Definition["schema"] = map[string]any{"type": "number", "example": 5.7}
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
				"username":{"wire":"version","type":"string|int|float","description":"Exact version from discovery, such as 10.10."},
				"password":{"type":"string|int|float","sensitive":true,"description":"Optional numeric or string value."}
			},
			"evidence":[{"kind":"doc","version":"pinned fixture","date":"2026-10-31","note":"Number input must preserve discovery strings."}]
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
		"@param string|int|float $username Exact version from discovery, such as 10.10.",
		"@param string|int|float|null $password Optional numeric or string value. Null omits it.",
		"string|int|float $username,",
		"#[SensitiveParameter]\n        string|int|float|null $password = null,",
		"?string $plan = null",
		"@param 'default'|'custom'|null $plan",
		"'username' => $username,",
		"'password' => $password,",
	} {
		if !strings.Contains(class, want) {
			t.Errorf("missing %q in generated output:\n%s", want, class)
		}
	}
	spec := string(files["src/Api/Whm/Spec/AccountsSpec.php"])
	for _, want := range []string{
		"'username' => ['name' => 'version', 'encoding' => 'scalar'],",
		"'password' => ['name' => 'password', 'encoding' => 'scalar'],",
	} {
		if !strings.Contains(spec, want) {
			t.Errorf("missing %q in generated spec:\n%s", want, spec)
		}
	}
}
