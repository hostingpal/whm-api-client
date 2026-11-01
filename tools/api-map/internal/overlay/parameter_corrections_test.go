package overlay

import (
	"strings"
	"testing"
)

func TestParameterCorrectionsRequireOperationEvidence(t *testing.T) {
	for _, tc := range []struct{ setting, name string }{
		{`"type":"string|int"`, "type"},
		{`"type":"string"`, "type"},
		{`"wire":"search_text"`, "wire"},
		{`"description":"Use the deployment identifier. Omission preserves the default."`, "description"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			content := strings.Replace(validWHM, `"name": "listAccounts",`,
				`"name": "listAccounts", "params":{"search":{`+tc.setting+`}},`, 1)
			set := loadWHM(t, content)
			want := "whm1:listaccts: " + tc.name + " on parameter search needs evidence"
			if got := strings.Join(Validate(set), "\n"); !strings.Contains(got, want) {
				t.Fatalf("missing %q in %s", want, got)
			}
			content = strings.Replace(content, `"name": "listAccounts",`,
				`"name": "listAccounts", "evidence":`+evidenceJSON+`,`, 1)
			if got := Validate(loadWHM(t, content)); len(got) != 0 {
				t.Fatalf("reviewed correction rejected: %v", got)
			}
		})
	}
}

func TestLoadRejectsUnsupportedParameterCorrections(t *testing.T) {
	for _, setting := range []string{
		`"type":"int"`, `"type":"int|string"`, `"type":"string|int|null"`,
		`"type":"mixed"`, `"type":""`, `"type":null`, `"type":true`,
		`"wire":""`, `"wire":null`, `"wire":true`, `"wire":"api.version"`, `"wire":"cpanel.user"`,
		`"wire":"a&b"`, `"wire":"field[]"`, `"wire":"name*"`, `"wire":"123"`,
		`"description":""`, `"description":" \n\t "`, `"description":null`, `"description":false`,
	} {
		t.Run(setting, func(t *testing.T) {
			content := strings.Replace(validWHM, `"name": "listAccounts",`,
				`"name": "listAccounts", "params":{"search":{`+setting+`}},`, 1)
			if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
				t.Fatal("accepted unsupported correction")
			}
		})
	}
}

func TestValidateRejectsCorrectionsOnOmittedParameters(t *testing.T) {
	for _, setting := range []string{`"type":"string|int"`, `"description":"Replacement."`, `"wire":"corrected"`} {
		content := strings.Replace(validWHM, `"name": "listAccounts",`,
			`"name": "listAccounts", "evidence":`+evidenceJSON+`, "params":{"search":{"omit":true,`+setting+`}},`, 1)
		if got := strings.Join(Validate(loadWHM(t, content)), "\n"); !strings.Contains(got, "omitted parameter search cannot have") {
			t.Fatalf("correction silently ignored: %s", got)
		}
	}
}
