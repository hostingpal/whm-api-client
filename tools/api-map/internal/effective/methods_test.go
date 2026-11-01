package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

const aliasFixture = `{"name":"createAccount","additional_methods":[{"name":"createAccounts","inputs":[{"php":"accounts","records":{"alias":"AccountInput","start":1,"separator":"","sensitive":true,"fields":[{"source":"username","php":"name","prefix":"user"},{"source":"password","php":"password","prefix":"pass"},{"source":"plan","php":"plan","prefix":"plan"}]}}]}],"evidence":[{"kind":"doc","version":"fixture","date":"2026-10-31","note":"shared index","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0"},{"kind":"doc","version":"fixture","date":"2026-10-31","note":"shared index","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1"},{"kind":"doc","version":"fixture","date":"2026-10-31","note":"shared index","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/2"}]}`

func TestAdditionalMethodRejectsInvalidMappings(t *testing.T) {
	cases := []struct{ old, new, problem string }{
		{`"name":"createAccounts"`, `"name":"createAccount"`, "duplicate method"},
		{`"name":"createAccounts"`, `"name":"createaccount"`, "duplicate method"},
		{`"name":"createAccounts"`, `"name":"createacct"`, "duplicate spec constant"},
		{`"name":"createAccounts"`, `"name":"class"`, "invalid name"},
		{`"php":"accounts"`, `"php":"this"`, "invalid"},
		{`"alias":"AccountInput"`, `"alias":"CreateAccountResult"`, "duplicate type alias"},
		{`"source":"plan"`, `"source":"password"`, "duplicated source"},
		{`"source":"plan"`, `"source":"unknown"`, "unknown"},
		{`"php":"plan"`, `"php":"password"`, "duplicated record field"},
		{`"prefix":"plan"`, `"prefix":"pass"`, "overlapping wire"},
		{`"prefix":"plan"`, `"prefix":"pass1"`, "overlapping wire"},
		{`"prefix":"plan"`, `"prefix":"bad.key"`, "invalid"},
		{`"start":1`, `"start":-1`, "invalid record descriptor"},
		{`"separator":""`, `"separator":"."`, "invalid record descriptor"},
		{`"sensitive":true`, `"sensitive":false`, "secrecy"},
		{`"source_sha256":"y"`, `"source_sha256":"wrong"`, "matching source_sha256"},
		{`/parameters/1"`, `/parameters/1/schema"`, "matching source_sha256"},
	}
	for _, tc := range cases {
		t.Run(tc.new, func(t *testing.T) {
			set := fixtureOverlay("listed")
			var ov overlay.Operation
			if err := json.Unmarshal([]byte(strings.Replace(aliasFixture, tc.old, tc.new, 1)), &ov); err != nil {
				t.Fatal(err)
			}
			set.Files[1].Operations["whm1:createacct"] = ov
			_, problems := buildProblems(t, fixtureMap(t), set)
			if !strings.Contains(problems, tc.problem) {
				t.Fatalf("want %s, got %s", tc.problem, problems)
			}
		})
	}
}

func TestAdditionalMethodSharesCanonicalContract(t *testing.T) {
	set := fixtureOverlay("listed")
	var ov overlay.Operation
	if err := json.Unmarshal([]byte(aliasFixture), &ov); err != nil {
		t.Fatal(err)
	}
	set.Files[1].Operations["whm1:createacct"] = ov
	result, problems := buildProblems(t, fixtureMap(t), set)
	if problems != "" {
		t.Fatal(problems)
	}
	canonical := groupByClass(t, result, "Accounts").Operations[0]
	methods := canonical.Methods()
	if len(methods) != 2 {
		t.Fatal("missing alias")
	}
	alias := methods[1]
	if canonical.Returns != alias.Returns || canonical.Alias != alias.Alias || canonical.Method != alias.Method || canonical.ID != alias.ID || canonical.VariantID != alias.VariantID || canonical.Function != alias.Function || canonical.Outcome != alias.Outcome {
		t.Fatal("alias forked canonical contract")
	}
	if alias.Params[0].Records.Fields[1].Required || !alias.Params[0].Records.Fields[0].Required {
		t.Fatal("changed source requiredness")
	}
	ov.AdditionalMethods = append(ov.AdditionalMethods, ov.AdditionalMethods[0])
	set.Files[1].Operations["whm1:createacct"] = ov
	_, problems = buildProblems(t, fixtureMap(t), set)
	if !strings.Contains(problems, "duplicate method") {
		t.Fatal("duplicate alias accepted")
	}
}

func TestAdditionalMethodCombinesSourceInputsAndRecords(t *testing.T) {
	set := fixtureOverlay("listed")
	var ov overlay.Operation
	if err := json.Unmarshal([]byte(aliasFixture), &ov); err != nil {
		t.Fatal(err)
	}
	mapping := &ov.AdditionalMethods[0]
	mapping.Inputs[0].Records.Fields = mapping.Inputs[0].Records.Fields[:2]
	mapping.Inputs = append(mapping.Inputs, overlay.MethodInput{Source: "plan", PHP: "hostingPlan"})
	set.Files[1].Operations["whm1:createacct"] = ov
	result, problems := buildProblems(t, fixtureMap(t), set)
	if problems != "" {
		t.Fatal(problems)
	}
	method := groupByClass(t, result, "Accounts").Operations[0].AdditionalMethods[0]
	if len(method.Params) != 2 || method.Params[1].Wire != "plan" || method.Params[1].PHP != "hostingPlan" || method.Params[1].Required {
		t.Fatal("source input was not preserved")
	}
	mapping.Inputs[0].PHP = "plan" // distinct PHP arguments must not duplicate descriptor wire names
	set.Files[1].Operations["whm1:createacct"] = ov
	_, problems = buildProblems(t, fixtureMap(t), set)
	if !strings.Contains(problems, "duplicate wire") {
		t.Fatalf("invalid duplicate wire spec accepted: %s", problems)
	}
}

func TestRecordAliasCannotInheritMultipartBody(t *testing.T) {
	set := fixtureOverlay("listed")
	var ov overlay.Operation
	if err := json.Unmarshal([]byte(aliasFixture), &ov); err != nil {
		t.Fatal(err)
	}
	set.Files[1].Operations["whm1:createacct"] = ov
	m := fixtureMap(t)
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	canonical := groupByClass(t, result, "Accounts").Operations[0]
	canonical.Body = "multipart"
	b := builder{problems: map[string]bool{}}
	for _, op := range m.Operations {
		if op.ID == canonical.ID {
			b.additionalMethods(op, op.Variants[0], ov, canonical)
		}
	}
	if !strings.Contains(strings.Join(sortedKeys(b.problems), "\n"), "form body") {
		t.Fatal("records accepted incompatible inherited body")
	}
}

func TestAdditionalMethodMapsSourceNamesAfterWireCorrection(t *testing.T) {
	set := fixtureOverlay("listed")
	var ov overlay.Operation
	if err := json.Unmarshal([]byte(aliasFixture), &ov); err != nil {
		t.Fatal(err)
	}
	ov.Params = map[string]overlay.Param{"username": {Wire: "account_name"}}
	ov.AdditionalMethods[0].Inputs[0].Records.Fields = ov.AdditionalMethods[0].Inputs[0].Records.Fields[1:]
	ov.AdditionalMethods[0].Inputs = append([]overlay.MethodInput{{Source: "username", PHP: "account"}}, ov.AdditionalMethods[0].Inputs...)
	set.Files[1].Operations["whm1:createacct"] = ov
	result, problems := buildProblems(t, fixtureMap(t), set)
	if problems != "" {
		t.Fatal(problems)
	}
	method := groupByClass(t, result, "Accounts").Operations[0].AdditionalMethods[0]
	if method.Params[0].Wire != "account_name" || method.Params[0].PHP != "account" {
		t.Fatal("source and wire names confused")
	}
}

func TestFirstUnsuffixedRecordRequirednessAndSerialization(t *testing.T) {
	build := func(raw string) (Operation, string) {
		set := fixtureOverlay("listed")
		var ov overlay.Operation
		if err := json.Unmarshal([]byte(raw), &ov); err != nil {
			t.Fatal(err)
		}
		set.Files[1].Operations["whm1:createacct"] = ov
		result, problems := buildProblems(t, fixtureMap(t), set)
		if problems != "" {
			return Operation{}, problems
		}
		return groupByClass(t, result, "Accounts").Operations[0], ""
	}
	old, problems := build(aliasFixture)
	if problems != "" {
		t.Fatal(problems)
	}
	encoded, _ := json.Marshal(old.AdditionalMethods[0].Params[0].Records)
	want := `{"Alias":"AccountInput","Start":1,"Separator":"","Fields":[{"Description":"The account's username.","PHP":"name","Prefix":"user","Type":"string","Required":true},{"Description":"The password.","PHP":"password","Prefix":"pass","Type":"string","Required":false},{"Description":"","PHP":"plan","Prefix":"plan","Type":"string","Required":false}]}`
	if string(encoded) != want {
		t.Fatalf("old effective serialization changed: %s", encoded)
	}
	raw := strings.Replace(aliasFixture, `"start":1,"separator":""`, `"start":0,"separator":"-","first_unsuffixed":true`, 1)
	raw = strings.Replace(raw, `"source":"password"`, `"source":"password","required":true`, 1)
	op, problems := build(raw)
	if problems != "" {
		t.Fatal(problems)
	}
	records := op.AdditionalMethods[0].Params[0].Records
	encoded, _ = json.Marshal(records)
	if !strings.Contains(string(encoded), `"FirstUnsuffixed":true`) || !records.Fields[1].Required || op.Params[1].Required || old.AdditionalMethods[0].Params[0].Records.Fields[1].Required {
		t.Fatalf("strengthening escaped alias or metadata lost: %s", encoded)
	}
	for _, bad := range []string{
		strings.Replace(raw, `"first_unsuffixed":true`, `"first_unsuffixed":false`, 1),
		strings.Replace(raw, `"start":0`, `"start":1`, 1),
		strings.Replace(raw, `"separator":"-"`, `"separator":""`, 1),
		strings.Replace(raw, `"required":true`, `"required":false`, 1),
		strings.ReplaceAll(raw, `"source_sha256":"y"`, `"source_sha256":"wrong"`),
		strings.ReplaceAll(raw, `"kind":"doc"`, `"kind":"live"`),
		strings.Replace(raw, `/parameters/1"`, `/parameters/1/schema"`, 1),
	} {
		if _, problems := build(bad); problems == "" {
			t.Errorf("schema bypass accepted %s", bad)
		}
	}
}

// Construct overlays directly to prove the effective layer does not rely on schema validation.
func TestFirstUnsuffixedDirectConstructionCollisionsInBothInputOrders(t *testing.T) {
	yes, no, zero, one, dash, empty := true, false, 0, 1, "-", ""
	repeat := &overlay.Collection{Kind: "repeat", ItemType: "string", AllowScalar: &no, Empty: "reject"}
	prefix := "group"
	indexed := &overlay.Collection{Kind: "indexed", ItemType: "string", AllowScalar: &no, Empty: "reject", Prefix: &prefix, Separator: &dash, Start: &one}
	indexedZero := &overlay.Collection{Kind: "indexed", ItemType: "string", AllowScalar: &no, Empty: "reject", Prefix: &prefix, Separator: &dash, Start: &zero}
	namedMap := &overlay.Collection{Kind: "map", ItemType: "string", AllowScalar: &no, Empty: "allow", Prefix: &empty}
	cases := []struct {
		name, recordPrefix, wire, encoding string
		collection                         *overlay.Collection
		records, collision                 bool
	}{
		{"literal base", "group", "group", "scalar", nil, false, true},
		{"literal tail", "group", "group-1", "scalar", nil, false, true},
		{"native repeat", "group", "group", "repeat", nil, false, true},
		{"explicit repeat", "group", "group", "collection", repeat, false, true},
		{"indexed tail", "group", "list", "collection", indexed, false, true},
		{"indexed zero tail", "group", "list", "collection", indexedZero, false, true},
		{"legacy numbered", "group", "group", "numbered", nil, false, true},
		{"legacy suffix base", "group1", "group*", "suffix-numbered", nil, false, true},
		{"legacy scalar suffix base", "group1", "group*", "suffix-numbered-scalar", nil, false, true},
		{"record base", "group", "other", "scalar", nil, true, true},
		{"disjoint suffix", "group", "group*", "suffix-numbered", nil, false, false},
		{"leading zero", "group0", "group*", "suffix-numbered", nil, false, false},
		{"omitted zero tail", "group", "group-0", "scalar", nil, false, false},
		{"unknown map keys", "group", "other", "collection", namedMap, false, false},
		{"disjoint literal", "group", "other", "scalar", nil, false, false},
	}
	for _, tc := range cases {
		for _, reverse := range []bool{false, true} {
			t.Run(tc.name+map[bool]string{false: " forward", true: " reverse"}[reverse], func(t *testing.T) {
				rowPointer, otherPointer := "/parameters/0", "/parameters/1"
				canonical := Operation{ID: "fixture", Method: "POST", Params: []Param{
					{Source: "field", PHP: "field", Wire: "field", PHPType: "string", Encoding: "scalar", Required: true},
					{Source: tc.wire, PHP: "other", Wire: tc.wire, PHPType: "string", Encoding: tc.encoding, Required: true, Collection: tc.collection},
				}}
				variant := model.Variant{Parameters: []model.Parameter{
					{Name: "field", Locator: model.Locator{SourceSHA256: "hash", Pointer: &rowPointer}},
					{Name: tc.wire, Locator: model.Locator{SourceSHA256: "hash", Pointer: &otherPointer}},
				}}
				rows := overlay.MethodInput{PHP: "rows", Records: &overlay.Records{Alias: "RowInput", Start: &zero, Separator: &dash, Sensitive: &no, FirstUnsuffixed: &yes, Fields: []overlay.RecordField{{PHP: "field", Source: "field", Prefix: tc.recordPrefix}}}}
				other := overlay.MethodInput{PHP: "other", Source: tc.wire}
				if tc.records {
					other = overlay.MethodInput{PHP: "others", Records: &overlay.Records{Alias: "OtherInput", Start: &zero, Separator: &dash, Sensitive: &no, FirstUnsuffixed: &yes, Fields: []overlay.RecordField{{PHP: "other", Source: tc.wire, Prefix: tc.recordPrefix}}}}
				}
				inputs := []overlay.MethodInput{rows, other}
				if reverse {
					inputs[0], inputs[1] = inputs[1], inputs[0]
				}
				ov := overlay.Operation{AdditionalMethods: []overlay.AdditionalMethod{{Name: "paired", Inputs: inputs}}, Evidence: []overlay.Evidence{
					{Kind: "doc", SourceSHA256: "hash", Pointer: rowPointer, Note: "fixture"},
					{Kind: "doc", SourceSHA256: "hash", Pointer: otherPointer, Note: "fixture"},
				}}
				b := builder{problems: map[string]bool{}}
				b.additionalMethods(model.Operation{ID: "fixture"}, variant, ov, canonical)
				problems := strings.Join(sortedKeys(b.problems), "\n")
				if tc.collision != strings.Contains(problems, "overlapping wire") || (!tc.collision && problems != "") {
					t.Fatalf("collision=%t: %s", tc.collision, problems)
				}
			})
		}
	}
}

func TestRecordStrengtheningRequiresEvidenceAndDoesNotChangeSibling(t *testing.T) {
	set := fixtureOverlay("listed")
	var ov overlay.Operation
	if err := json.Unmarshal([]byte(aliasFixture), &ov); err != nil {
		t.Fatal(err)
	}
	sibling := ov.AdditionalMethods[0]
	sibling.Name = "otherAccounts"
	sibling.Inputs[0].Records.Alias = "OtherAccountInput"
	// Decode into a new value: unmarshalling into the old one reuses descriptor pointers.
	var selected overlay.Operation
	if err := json.Unmarshal([]byte(aliasFixture), &selected); err != nil {
		t.Fatal(err)
	}
	ov = selected
	yes, no := true, false
	ov.AdditionalMethods[0].Inputs[0].Records.Fields[1].Required = &yes
	ov.AdditionalMethods = append(ov.AdditionalMethods, sibling)
	set.Files[1].Operations["whm1:createacct"] = ov
	result, problems := buildProblems(t, fixtureMap(t), set)
	if problems != "" {
		t.Fatal(problems)
	}
	op := groupByClass(t, result, "Accounts").Operations[0]
	if !op.AdditionalMethods[0].Params[0].Records.Fields[1].Required || op.AdditionalMethods[1].Params[0].Records.Fields[1].Required || op.Params[1].Required {
		t.Fatal("strengthening leaked beyond selected alias")
	}
	ov.Evidence = nil
	set.Files[1].Operations["whm1:createacct"] = ov
	_, problems = buildProblems(t, fixtureMap(t), set)
	if !strings.Contains(problems, "matching source_sha256") {
		t.Fatal("strengthening without evidence accepted")
	}
	ov.AdditionalMethods[0].Inputs[0].Records.Fields[1].Required = &no
	set.Files[1].Operations["whm1:createacct"] = ov
	_, problems = buildProblems(t, fixtureMap(t), set)
	if !strings.Contains(problems, "only strengthen") {
		t.Fatal("direct false override accepted")
	}
}
