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

func TestCanonicalRecordAlias(t *testing.T) {
	raw, err := os.ReadFile("../effective/testdata/fixture-map.json")
	if err != nil {
		t.Fatal(err)
	}
	var m model.Map
	d := json.NewDecoder(bytes.NewReader(raw))
	d.UseNumber()
	if err := d.Decode(&m); err != nil {
		t.Fatal(err)
	}
	var file overlay.File
	raw = []byte(`{"family":"whm1","group":"Accounts","accessor":"accounts","operations":{"whm1:createacct":{"name":"createAccount","additional_methods":[{"name":"createAccounts","inputs":[{"php":"accounts","records":{"alias":"AccountInput","start":1,"separator":"","sensitive":true,"fields":[{"source":"username","php":"name","prefix":"user"},{"source":"password","php":"password","prefix":"pass"},{"source":"plan","php":"plan","prefix":"plan"}]}}]}],"evidence":[{"kind":"doc","version":"fixture","date":"2026-10-31","note":"shared index","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0"},{"kind":"doc","version":"fixture","date":"2026-10-31","note":"shared index","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1"},{"kind":"doc","version":"fixture","date":"2026-10-31","note":"shared index","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/2"}]}}}`)
	if err := json.Unmarshal(raw, &file); err != nil {
		t.Fatal(err)
	}
	result, err := effective.Build(m, overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{file}})
	if err != nil || len(result.Problems) > 0 {
		t.Fatalf("%v %v", err, result.Problems)
	}
	files := Render(result)
	class := string(files["src/Api/Whm/Accounts.php"])
	spec := string(files["src/Api/Whm/Spec/AccountsSpec.php"])
	for _, s := range []string{"function createAccounts(", "non-empty-list<AccountInput>", "@phpstan-type AccountInput array{", "password?: string"} {
		if !strings.Contains(class, s) {
			t.Errorf("missing %s", s)
		}
	}
	if strings.Count(class, "@phpstan-type CreateAccountResult") != 1 {
		t.Error("duplicated result alias")
	}
	if !strings.Contains(spec, "...self::CREATEACCT,") || !strings.Contains(spec, "'encoding' => 'records'") {
		t.Error("alias must inherit canonical spec")
	}
	if strings.Contains(spec, "first_unsuffixed") || !strings.Contains(spec, "'start' => 1,\n                    'separator' => '',\n                    'fields' => [") {
		t.Error("old generated descriptor changed")
	}
	before := spec
	result.Groups[0].Operations[0].AdditionalMethods[0].Params[0].Records.FirstUnsuffixed = true
	result.Groups[0].Operations[0].AdditionalMethods[0].Params[0].Records.Start = 0
	result.Groups[0].Operations[0].AdditionalMethods[0].Params[0].Records.Separator = "-"
	changed := string(Render(result)["src/Api/Whm/Spec/AccountsSpec.php"])
	if !strings.Contains(changed, "'first_unsuffixed' => true,") || !strings.Contains(changed, "'start' => 0,") {
		t.Error("missing opt-in descriptor")
	}
	result.Groups[0].Operations[0].AdditionalMethods[0].Params[0].Records.FirstUnsuffixed = false
	result.Groups[0].Operations[0].AdditionalMethods[0].Params[0].Records.Start = 1
	result.Groups[0].Operations[0].AdditionalMethods[0].Params[0].Records.Separator = ""
	if got := string(Render(result)["src/Api/Whm/Spec/AccountsSpec.php"]); got != before {
		t.Error("default output changed")
	}
	if len(result.Groups[0].Operations) != 1 {
		t.Error("alias inflated canonical operation count")
	}
	if !strings.Contains(string(files["docs/api/coverage.md"]), "createAccounts") {
		t.Error("coverage missing alias")
	}
}
