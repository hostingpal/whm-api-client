package effective

import (
	"encoding/json"
	"strings"
	"testing"

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
