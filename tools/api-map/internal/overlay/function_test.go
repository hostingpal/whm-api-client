package overlay

import (
	"fmt"
	"strings"
	"testing"
)

func TestFunctionSettingSchemaAndEvidenceEligibility(t *testing.T) {
	for _, value := range []string{`null`, `""`, `"foo"`, `"../listaccts"`, `"A/listaccts?q=x"`, `3`, `false`} {
		content := strings.Replace(validWHM, `"name": "listAccounts",`, fmt.Sprintf(`"name":"listAccounts","function":%s,`, value), 1)
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
			t.Fatalf("accepted %s", value)
		}
	}
	content := strings.Replace(validWHM, `"name": "listAccounts",`, `"name":"listAccounts","function":"Example/listaccts",`, 1)
	set, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content}))
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(Validate(set), "\n"); !strings.Contains(got, "function needs evidence") {
		t.Fatalf("missing evidence accepted: %s", got)
	}
	op := set.Files[0].Operations["whm1:listaccts"]
	op.Evidence = []Evidence{{Kind: "doc", Version: "pinned", Date: "2026-10-31", Note: "CLI"}}
	set.Files[0].Operations["whm1:listaccts"] = op
	if got := Validate(set); len(got) != 0 {
		t.Fatal(got)
	}
	op.Exclude = &Exclusion{Reason: "excluded", Evidence: op.Evidence}
	set.Files[0].Operations["whm1:listaccts"] = op
	if got := strings.Join(Validate(set), "\n"); !strings.Contains(got, "excluded operations") {
		t.Fatalf("excluded correction accepted: %s", got)
	}
}
