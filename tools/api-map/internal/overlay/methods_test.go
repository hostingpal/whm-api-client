package overlay

import (
	"strings"
	"testing"
)

const methodJSON = `{"name":"many","inputs":[{"php":"rows","records":{"alias":"RowInput","start":1,"separator":"","sensitive":true,"fields":[{"source":"search","php":"search","prefix":"search"}]}}]}`

func TestAdditionalMethodsSchemaRejectsDriftingContracts(t *testing.T) {
	content := func(method string) string {
		return strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "additional_methods":[`+method+`],`, 1)
	}
	if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content(methodJSON)})); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{
		strings.Replace(methodJSON, `"name":"many"`, `"name":"many","returns":{}`, 1),
		strings.Replace(methodJSON, `"name":"many"`, `"name":"many","response":{}`, 1),
		strings.Replace(methodJSON, `"name":"many"`, `"name":"many","method":"GET"`, 1),
		strings.Replace(methodJSON, `"name":"many"`, `"name":"many","variant":"POST"`, 1),
		strings.Replace(methodJSON, `"name":"many"`, `"name":"many","outcome":"unchecked"`, 1),
		strings.Replace(methodJSON, `"php":"rows"`, `"php":"rows","source":"search"`, 1),
		strings.Replace(methodJSON, `"start":1`, `"start":null`, 1),
		strings.Replace(methodJSON, `"start":1`, `"start":1.5`, 1),
		strings.Replace(methodJSON, `"start":1`, `"Start":1`, 1),
		strings.Replace(methodJSON, `"start":1,`, ``, 1),
		strings.Replace(methodJSON, `"sensitive":true,`, ``, 1),
		strings.Replace(methodJSON, `"separator":"",`, ``, 1),
		strings.Replace(methodJSON, `"source":"search"`, `"source":"search","required":false`, 1),
		strings.Replace(methodJSON, `"source":"search"`, `"source":"search","type":"string"`, 1),
		strings.Replace(methodJSON, `"prefix":"search"`, `"prefix":"search","prefix":"other"`, 1),
	} {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content(bad)})); err == nil {
			t.Errorf("malformed method accepted: %s", bad)
		}
	}
}
