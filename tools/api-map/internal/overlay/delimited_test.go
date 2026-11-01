package overlay

import (
	"encoding/json"
	"strings"
	"testing"
)

const delimitedJSON = `{"kind":"delimited","item_type":"string","allow_scalar":true,"empty":"reject","delimiter":","}`

func TestDelimitedSchemaStrictFacts(t *testing.T) {
	for _, delimiter := range []string{",", "|"} {
		raw := strings.Replace(delimitedJSON, `"delimiter":","`, `"delimiter":"`+delimiter+`"`, 1)
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": collectionContent(raw)})); err != nil {
			t.Fatal(err)
		}
	}
	var original map[string]any
	_ = json.Unmarshal([]byte(delimitedJSON), &original)
	var bad []string
	for key := range original {
		for _, null := range []bool{false, true} {
			copy := map[string]any{}
			for k, v := range original {
				copy[k] = v
			}
			if null {
				copy[key] = nil
			} else {
				delete(copy, key)
			}
			raw, _ := json.Marshal(copy)
			bad = append(bad, string(raw))
		}
	}
	for _, fact := range []string{`"delimiter":";"`, `"delimiter":false`, `"item_type":"int"`, `"allow_scalar":1`, `"empty":"allow"`, `"prefix":"x"`, `"separator":"-"`, `"start":0`, `"unknown":true`} {
		var copy map[string]any
		_ = json.Unmarshal([]byte(delimitedJSON), &copy)
		var change map[string]any
		_ = json.Unmarshal([]byte(`{`+fact+`}`), &change)
		for k, v := range change {
			copy[k] = v
		}
		raw, _ := json.Marshal(copy)
		bad = append(bad, string(raw))
	}
	for _, descriptor := range []string{collectionJSON, mapJSON, `{"kind":"repeat","item_type":"string","allow_scalar":false,"empty":"reject"}`} {
		for _, value := range []string{`","`, `null`} {
			bad = append(bad, strings.TrimSuffix(descriptor, "}")+`,"delimiter":`+value+`}`)
		}
	}
	for _, raw := range bad {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": collectionContent(raw)})); err == nil {
			t.Errorf("invalid descriptor accepted: %s", raw)
		}
	}
}

func TestDelimitedDirectDescriptorValidation(t *testing.T) {
	yes, comma, semicolon, prefix, dash, zero := true, ",", ";", "x", "-", 0
	valid := Collection{Kind: "delimited", ItemType: "string", AllowScalar: &yes, Empty: "reject", Delimiter: &comma}
	if err := ValidateCollection(&valid); err != nil {
		t.Fatal(err)
	}
	for _, change := range []func(*Collection){
		func(c *Collection) { c.ItemType = "int" }, func(c *Collection) { c.AllowScalar = nil },
		func(c *Collection) { c.Empty = "allow" }, func(c *Collection) { c.Delimiter = nil },
		func(c *Collection) { c.Delimiter = &semicolon }, func(c *Collection) { c.Prefix = &prefix },
		func(c *Collection) { c.Separator = &dash }, func(c *Collection) { c.Start = &zero },
		func(c *Collection) { c.Kind = "repeat" },
	} {
		bad := valid
		change(&bad)
		if ValidateCollection(&bad) == nil {
			t.Fatalf("bad direct descriptor accepted: %+v", bad)
		}
	}
}
