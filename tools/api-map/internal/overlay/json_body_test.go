package overlay

import (
	"strings"
	"testing"
)

func TestJSONBodyDescriptorGrammar(t *testing.T) {
	descriptor := `{"php":"configuration","alias":"ConfigurationInput","routes":"direct","required":true,"corrections":{"/properties/changes":{"nonempty":true,"evidence":` + evidenceJSON + `}}}`
	content := func(d string) string {
		return strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "body":"json","json_body":`+d+`,"evidence":`+evidenceJSON+`,`, 1)
	}
	set, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content(descriptor)}))
	if err != nil {
		t.Fatal(err)
	}
	if p := Validate(set); len(p) > 0 {
		t.Fatal(p)
	}
	if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content(strings.Replace(descriptor, `"nonempty":true`, `"nonempty":false`, 1))})); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{
		strings.Replace(descriptor, `"php":"configuration",`, "", 1),
		strings.Replace(descriptor, `"alias":"ConfigurationInput"`, `"alias":"Configuration"`, 1),
		strings.Replace(descriptor, `"routes":"direct"`, `"routes":"bridge"`, 1),
		strings.Replace(descriptor, `"nonempty":true`, `"nonempty":1`, 1),
		strings.Replace(descriptor, `"nonempty":true`, `"accept":["numeric-string"]`, 1),
		strings.Replace(descriptor, `"required":true`, `"Required":true`, 1),
	} {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content(bad)})); err == nil {
			t.Fatal("invalid JSON body descriptor accepted")
		}
	}
}
