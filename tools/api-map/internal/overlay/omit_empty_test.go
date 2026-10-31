package overlay

import (
	"strings"
	"testing"
)

func TestOmitEmptySchemaAndEvidence(t *testing.T) {
	for _, value := range []string{"false", "null", "1", `"true"`, "[]"} {
		content := strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "params":{"search":{"omit_empty":`+value+`}},`, 1)
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
			t.Errorf("accepted omit_empty %s", value)
		}
	}
	content := strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "params":{"search":{"omit_empty":true}},`, 1)
	set := loadWHM(t, content)
	if got := strings.Join(Validate(set), "\n"); !strings.Contains(got, "omit_empty on parameter search needs evidence") {
		t.Fatalf("missing evidence gate: %s", got)
	}
	content = strings.Replace(content, `"name": "listAccounts",`, `"name": "listAccounts", "evidence":`+evidenceJSON+`,`, 1)
	if got := Validate(loadWHM(t, content)); len(got) != 0 {
		t.Fatal(got)
	}
	content = strings.Replace(content, `"omit_empty":true`, `"omit_empty":true,"omit":true`, 1)
	if got := strings.Join(Validate(loadWHM(t, content)), "\n"); !strings.Contains(got, "cannot have omit_empty") {
		t.Fatalf("omitted correction accepted: %s", got)
	}
}
