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
	class := string(Render(result)["src/Api/Whm/Accounts.php"])
	for _, want := range []string{
		"@param string|int $username The deploy_id returned by creation. Keep integer identifiers.",
		"@param string|int|null $password Optional identifier. Preserve omission. Null omits it.",
		"\n        string|int $username,",
		"#[SensitiveParameter]\n        string|int|null $password = null,",
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
}
