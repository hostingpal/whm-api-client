package overlay

import (
	"strings"
	"testing"
)

func TestResponseFieldsSchemaAndEvidence(t *testing.T) {
	for _, fields := range []string{`null`, `[]`, `[1]`, `["details","details"]`, `[""]`, `["a-b"]`, `["metadata"]`, `["data"]`} {
		content := strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "response_fields":`+fields+`,`, 1)
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
			t.Errorf("accepted %s", fields)
		}
	}
	content := strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "response_fields":["details","needs_reboot"],`, 1)
	set, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content}))
	if err != nil {
		t.Fatalf("valid field selection rejected: %v", err)
	}
	if got := strings.Join(Validate(set), "\n"); !strings.Contains(got, "response_fields needs evidence") {
		t.Fatal(got)
	}
	content = strings.Replace(content, `"name": "listAccounts",`, `"name": "listAccounts", "evidence":`+evidenceJSON+`,`, 1)
	if got := Validate(loadWHM(t, content)); len(got) != 0 {
		t.Fatal(got)
	}
	for _, setting := range []string{`"void":true,`, `"optional_payload":true,`} {
		bad := strings.Replace(content, `"name": "listAccounts",`, `"name": "listAccounts",`+setting, 1)
		if got := Validate(loadWHM(t, bad)); len(got) == 0 {
			t.Fatalf("accepted incompatible %s", setting)
		}
	}
}

func TestResponseFieldsProgrammaticValidationRejectsInvalidCombinations(t *testing.T) {
	for _, tc := range []struct {
		name, family string
		op           Operation
	}{
		{"UAPI", "uapi", Operation{ResponseFields: []string{"details"}, Evidence: []Evidence{{Kind: "doc"}}}},
		{"excluded", "whm1", Operation{ResponseFields: []string{"details"}, Exclude: &Exclusion{Reason: "fixture"}}},
		{"empty", "whm1", Operation{ResponseFields: []string{}}},
		{"duplicate", "whm1", Operation{ResponseFields: []string{"details", "details"}}},
		{"reserved", "whm1", Operation{ResponseFields: []string{"data"}}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if err := ValidateResponseFields(tc.family, tc.op); err == nil {
				t.Fatal("invalid explicit projection accepted")
			}
		})
	}
}
