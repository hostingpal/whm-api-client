package overlay

import (
	"encoding/json"
	"strings"
	"testing"
)

const collectionJSON = `{"kind":"indexed","item_type":"string","allow_scalar":true,"empty":"reject","prefix":"domain","separator":"-","start":0}`

func collectionContent(descriptor string) string {
	return strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "params":{"search":{"collection":`+descriptor+`}},`, 1)
}

func TestCollectionSchemaRequiresEveryExplicitFact(t *testing.T) {
	var original map[string]any
	_ = json.Unmarshal([]byte(collectionJSON), &original)
	for key := range original {
		copy := map[string]any{}
		for k, v := range original {
			if k != key {
				copy[k] = v
			}
		}
		raw, _ := json.Marshal(copy)
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": collectionContent(string(raw))})); err == nil {
			t.Errorf("missing %s accepted", key)
		}
	}
	for _, bad := range []string{`null`, `[]`, `true`,
		strings.Replace(collectionJSON, `"start":0`, `"start":-1`, 1),
		strings.Replace(collectionJSON, `"start":0`, `"start":1.5`, 1),
		strings.Replace(collectionJSON, `"start":0`, `"start":"1"`, 1),
		strings.Replace(collectionJSON, `"start":0`, `"start":2147483648`, 1),
		strings.Replace(collectionJSON, `"separator":"-"`, `"separator":"."`, 1),
		strings.Replace(collectionJSON, `"prefix":"domain"`, `"prefix":"api.version"`, 1),
		strings.Replace(collectionJSON, `"prefix":"domain"`, `"prefix":""`, 1),
		strings.Replace(collectionJSON, `"item_type":"string"`, `"item_type":"float"`, 1),
		strings.Replace(collectionJSON, `"allow_scalar":true`, `"allow_scalar":null`, 1),
		strings.Replace(collectionJSON, `"empty":"reject"`, `"empty":"omit"`, 1),
		strings.Replace(collectionJSON, `"kind":"indexed"`, `"kind":"repeat"`, 1),
		strings.Replace(collectionJSON, `"start":0`, `"Start":0`, 1),
	} {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": collectionContent(bad)})); err == nil {
			t.Errorf("invalid descriptor accepted: %s", bad)
		}
	}
}

func TestCollectionEvidenceAndExclusiveSettings(t *testing.T) {
	content := collectionContent(collectionJSON)
	if got := strings.Join(Validate(loadWHM(t, content)), "\n"); !strings.Contains(got, "collection on parameter search needs evidence") {
		t.Fatalf("unreviewed collection accepted: %s", got)
	}
	content = strings.Replace(content, `"name": "listAccounts",`, `"name": "listAccounts", "evidence":`+evidenceJSON+`,`, 1)
	if got := Validate(loadWHM(t, content)); len(got) > 0 {
		t.Fatal(got)
	}
	for _, setting := range []string{`"encoding":"repeat"`, `"type":"string|int"`, `"wire":"renamed"`, `"omit":true`, `"omit_empty":true`, `"reject_empty":true`} {
		raw := strings.Replace(content, `"collection":`, setting+`,"collection":`, 1)
		if got := strings.Join(Validate(loadWHM(t, raw)), "\n"); !strings.Contains(got, "collection cannot combine") {
			t.Errorf("conflicting collection accepted: %s: %s", setting, got)
		}
	}
}
