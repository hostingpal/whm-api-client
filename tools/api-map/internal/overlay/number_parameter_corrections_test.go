package overlay

import (
	"strings"
	"testing"
)

func TestNumberParameterCorrectionRequiresEvidence(t *testing.T) {
	content := strings.Replace(validWHM, `"name": "listAccounts",`,
		`"name": "listAccounts", "params":{"search":{"type":"string|int|float"}},`, 1)
	set := loadWHM(t, content)
	if got := strings.Join(Validate(set), "\n"); !strings.Contains(got, "type on parameter search needs evidence") {
		t.Fatalf("unevidenced number correction accepted: %s", got)
	}
	content = strings.Replace(content, `"name": "listAccounts",`,
		`"name": "listAccounts", "evidence":`+evidenceJSON+`,`, 1)
	if problems := Validate(loadWHM(t, content)); len(problems) != 0 {
		t.Fatalf("reviewed number correction rejected: %v", problems)
	}
}

func TestNumberParameterCorrectionCannotTargetOmittedInput(t *testing.T) {
	content := strings.Replace(validWHM, `"name": "listAccounts",`,
		`"name": "listAccounts", "evidence":`+evidenceJSON+`, "params":{"search":{"type":"string|int|float","omit":true}},`, 1)
	if got := strings.Join(Validate(loadWHM(t, content)), "\n"); !strings.Contains(got, "omitted parameter search cannot have type") {
		t.Fatalf("omitted number correction accepted: %s", got)
	}
}

func TestLoadRejectsUnreviewedNumberParameterUnions(t *testing.T) {
	for _, value := range []string{"string|float", "int|float|string", "string|int|float|null", "string|int|float|bool", "float", "number"} {
		t.Run(value, func(t *testing.T) {
			content := strings.Replace(validWHM, `"name": "listAccounts",`,
				`"name": "listAccounts", "params":{"search":{"type":"`+value+`"}},`, 1)
			if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
				t.Fatal("accepted unsupported number correction")
			}
		})
	}
}
