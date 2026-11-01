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
	// Complete pinned ModSecurity prose copied into a small independent mapping fixture.
	// A dangerous terminator/link suffix additionally exercises the established converter.
	for i := range m.Operations {
		if m.Operations[i].ID != "whm1:createacct" {
			continue
		}
		params := m.Operations[i].Variants[0].Parameters
		params[0].Definition["description"] = "The configuration setting's ID.\n\n**Note:**\n\nTo configure multiple IDs, increment the parameter name. For example, `setting_id1`,\n`setting_id2`, and `setting_id3`."
		params[1].Definition["description"] = "The configuration setting's current state.\n\n* `On`\n* `Off`\n\nSome settings accept additional values for this parameter. See the references above for more inforamation.\n\n**Note:**\n\n* To configure multiple settings, increment the parameter name. For example, `state1`,\n`state2`, and `state3`.\n* `state` is ignored if `remove` is set to `1` for the setting. Literal */; see [details](https://example.test/id)."
		params[2].Definition["description"] = "Whether to add or remove the configuration setting in the\n`/usr/local/apache/conf/modsec2.cpanel.conf` file.\n\n* `1` — Remove the configuration setting.\n* `0` — Add or update the configuration setting.\n\n* To remove multiple settings, increment the parameter name. For example, `remove1`,\n`remove2`, and `remove3`."
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
	normalized := strings.Join(strings.Fields(strings.ReplaceAll(strings.ReplaceAll(class, "\n     *", " "), "\n *", " ")), " ")
	for _, want := range []string{
		"name: The configuration setting's ID. Note: To configure multiple IDs, increment the parameter name. For example, `setting_id1`, `setting_id2`, and `setting_id3`.",
		"password: The configuration setting's current state. * `On` * `Off` Some settings accept additional values for this parameter. See the references above for more inforamation. Note: * To configure multiple settings, increment the parameter name. For example, `state1`, `state2`, and `state3`. * `state` is ignored if `remove` is set to `1` for the setting. Literal *\\/; see details.",
		"plan: Whether to add or remove the configuration setting in the `/usr/local/apache/conf/modsec2.cpanel.conf` file. * `1` — Remove the configuration setting. * `0` — Add or update the configuration setting. * To remove multiple settings, increment the parameter name. For example, `remove1`, `remove2`, and `remove3`.",
		"@phpstan-type AccountInput array{ name: string, password?: string, plan?: string, }",
	} {
		if !strings.Contains(normalized, want) {
			t.Errorf("missing complete, safe record description/shape %q", want)
		}
	}
	if strings.Contains(class, "Literal */") || strings.Contains(class, "**Note:**") {
		t.Error("unsafe or unconverted record prose")
	}
	if strings.Count(class, "@phpstan-type CreateAccountResult") != 1 {
		t.Error("duplicated result alias")
	}
	if !strings.Contains(spec, "...self::CREATEACCT,") || !strings.Contains(spec, "'encoding' => 'records'") {
		t.Error("alias must inherit canonical spec")
	}
	if len(result.Groups[0].Operations) != 1 {
		t.Error("alias inflated canonical operation count")
	}
	if !strings.Contains(string(files["docs/api/coverage.md"]), "createAccounts") {
		t.Error("coverage missing alias")
	}
}
