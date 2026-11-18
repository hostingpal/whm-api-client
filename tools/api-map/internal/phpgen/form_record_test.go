package phpgen

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func TestRenderFormRecord(t *testing.T) {
	var op effective.Operation
	err := json.Unmarshal([]byte(`{"PHPName":"editZoneRecord","Constant":"EDIT_ZONE_RECORD","Method":"POST","Outcome":"flag","Params":[{"PHP":"domain","Wire":"domain","PHPType":"string","DocType":"string","Required":true,"Encoding":"scalar"},{"PHP":"record","PHPType":"array","DocType":"EditZoneRecordInput","Sensitive":true,"Encoding":"form_record"}],"FormRecord":{"PHP":"options","Discriminator":"provider","ChangeParams":["domain"],"Alias":"EditZoneRecordInput","Untagged":true,"Variants":[{"Tag":"MX","Fields":[{"Name":"preference","Type":"int"},{"Name":"exchange","Type":"string"}]}]}}`), &op)
	if err != nil {
		t.Fatal(err)
	}
	g := effective.Group{Family: "whm1", Name: "DNS", Class: "Dns", Operations: []effective.Operation{op}}
	group, spec := groupClass(g), specClass(g)
	for _, s := range []string{"@phpstan-type EditZoneRecordInput", "provider?: 'MX'", "exchange?: string", "preference?: int", "array $record = null", "#[SensitiveParameter]"} {
		if !strings.Contains(group, s) {
			t.Errorf("missing group %q", s)
		}
	}
	for _, s := range []string{"'form_record' => [", "'untagged' => true", "'php' => 'options'", "'discriminator' => 'provider'", "'change_params' => ['domain']", "'MX' => [", "'exchange' => ['type' => 'string', 'required' => false]", "'domain' => ['name' => 'domain', 'encoding' => 'scalar']"} {
		if !strings.Contains(spec, s) {
			t.Errorf("missing spec %q", s)
		}
	}
	if strings.Contains(spec, "'record' => ['name'") {
		t.Fatal("record leaked into scalar parameters")
	}
}

func TestLongFormRecordAliasAndCombinedScalarSettings(t *testing.T) {
	w := &writer{}
	alias := strings.Repeat("Long", 32) + "Input"
	w.paramDoc("    ", effective.Param{PHP: "options", PHPType: "array", Encoding: "form_record", DocType: alias}, "Choices.")
	if !strings.Contains(w.b.String(), "@param "+alias+"|null $options") {
		t.Fatal("lost discriminated shape alias")
	}
	op := effective.Operation{PHPName: "f", Constant: "F", Params: []effective.Param{{PHP: "n", Wire: "n", Encoding: "scalar", PositiveInt: true, OmitEmpty: true}}}
	spec := specClass(effective.Group{Family: "whm1", Class: "Group", Operations: []effective.Operation{op}})
	if !strings.Contains(spec, "'positive_int' => true, 'omit_empty' => true") {
		t.Fatal("inline settings dropped")
	}
}
