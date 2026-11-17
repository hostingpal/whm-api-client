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

func TestAdditionalJSONFieldSchema(t *testing.T) {
	method := `{"name":"configure","inputs":[{"php":"configuration","source":"search","json":{"alias":"ConfigurationInput"}}]}`
	content := func(m string) string {
		return strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "additional_methods":[`+m+`],`, 1)
	}
	if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content(method)})); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{
		strings.Replace(method, `"json":{"alias":"ConfigurationInput"}`, `"json":{"alias":"ConfigurationInput","routes":"direct"}`, 1),
		strings.Replace(method, `"json":{"alias":"ConfigurationInput"}`, `"json":{}`, 1),
		strings.Replace(method, `"json":{"alias":"ConfigurationInput"}`, `"json":null`, 1),
		strings.Replace(method, `"json":{"alias":"ConfigurationInput"}`, `"json":{"alias":"ConfigurationInput","schema":{}}`, 1),
		strings.Replace(method, `"source":"search",`, ``, 1),
		strings.Replace(method, `"json":`, `"Json":`, 1),
	} {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content(bad)})); err == nil {
			t.Errorf("bad JSON field accepted: %s", bad)
		}
	}
}

func TestScalarMapJSONFieldDescriptorSchema(t *testing.T) {
	method := `{"name":"configure","inputs":[{"php":"environment","source":"search","json":{"alias":"EnvironmentInput","scalar_map":true}}]}`
	load := func(m string) error {
		_, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "additional_methods":[`+m+`],`, 1)}))
		return err
	}
	if err := load(method); err != nil {
		t.Fatal(err)
	}
	for _, v := range []string{"false", "null", "1", `"true"`, `{}`} {
		if err := load(strings.Replace(method, `"scalar_map":true`, `"scalar_map":`+v, 1)); err == nil {
			t.Fatalf("invalid scalar_map %s accepted", v)
		}
	}
	if err := load(strings.Replace(method, `"scalar_map":true`, `"Scalar_map":true`, 1)); err == nil {
		t.Fatal("unknown key accepted")
	}
}

func TestFirstUnsuffixedRecordSchema(t *testing.T) {
	method := strings.Replace(methodJSON, `"start":1,"separator":""`, `"start":0,"separator":"-","first_unsuffixed":true`, 1)
	method = strings.Replace(method, `"source":"search"`, `"source":"search","required":true`, 1)
	load := func(m string) error {
		_, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "additional_methods":[`+m+`],`, 1)}))
		return err
	}
	if err := load(method); err != nil {
		t.Fatal(err)
	}
	for _, key := range []string{"first_unsuffixed", "required"} {
		for _, value := range []string{"false", "null", "1", `"true"`, `{}`} {
			if err := load(strings.Replace(method, `"`+key+`":true`, `"`+key+`":`+value, 1)); err == nil {
				t.Errorf("accepted %s=%s", key, value)
			}
		}
		if err := load(strings.Replace(method, `"`+key+`":true`, `"`+key+`":true,"`+key+`":true`, 1)); err == nil {
			t.Errorf("accepted duplicate %s", key)
		}
	}
	for _, bad := range []string{
		strings.Replace(method, `"start":0`, `"start":1`, 1),
		strings.Replace(method, `"separator":"-"`, `"separator":""`, 1),
		strings.Replace(method, `"first_unsuffixed"`, `"First_unsuffixed"`, 1),
		strings.Replace(method, `"required":true`, `"unknown":true`, 1),
	} {
		if err := load(bad); err == nil {
			t.Errorf("accepted unsafe descriptor: %s", bad)
		}
	}
}
