package overlay

import (
	"strings"
	"testing"
)

func TestPresenceParameterCorrectionRequiresEvidence(t *testing.T) {
	content := strings.Replace(validWHM, `"name": "listAccounts",`,
		`"name": "listAccounts", "params":{"search":{"type":"true","encoding":"bool"}},`, 1)
	if got := strings.Join(Validate(loadWHM(t, content)), "\n"); !strings.Contains(got, "type on parameter search needs evidence") {
		t.Fatalf("unevidenced presence correction accepted: %s", got)
	}
	content = strings.Replace(content, `"name": "listAccounts",`,
		`"name": "listAccounts", "evidence":`+evidenceJSON+`,`, 1)
	if problems := Validate(loadWHM(t, content)); len(problems) != 0 {
		t.Fatalf("reviewed presence correction rejected: %v", problems)
	}
}

func TestPresenceParameterCorrectionCannotTargetOmittedInput(t *testing.T) {
	content := strings.Replace(validWHM, `"name": "listAccounts",`,
		`"name": "listAccounts", "evidence":`+evidenceJSON+`, "params":{"search":{"type":"true","omit":true}},`, 1)
	if got := strings.Join(Validate(loadWHM(t, content)), "\n"); !strings.Contains(got, "omitted parameter search cannot have type") {
		t.Fatalf("omitted presence correction accepted: %s", got)
	}
}

func TestPresenceInputTypeDoesNotExtendResponseGrammar(t *testing.T) {
	content := strings.Replace(validWHM, `"name": "listAccounts",`,
		`"name": "listAccounts", "response":{".":{"replace":{"type":"true"},"evidence":`+evidenceJSON+`}},`, 1)
	if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
		t.Fatal("input presence correction extended response types")
	}
}
