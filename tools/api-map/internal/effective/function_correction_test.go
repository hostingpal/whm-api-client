package effective

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"reflect"
	"strings"
	"testing"
)

func functionCorrection(t *testing.T, function string) overlay.Operation {
	t.Helper()
	var op overlay.Operation
	raw, _ := json.Marshal(map[string]any{"name": "createAccount", "function": function, "evidence": evidence()})
	if err := json.Unmarshal(raw, &op); err != nil {
		t.Fatal(err)
	}
	return op
}

func TestFunctionCorrectionPreservesCanonicalIdentityAndSource(t *testing.T) {
	m := fixtureMap(t)
	before, _ := json.Marshal(m)
	set := fixtureOverlay("listed")
	baseline, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	set.Files[1].Operations["whm1:createacct"] = functionCorrection(t, "Example/createacct")
	corrected, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	want := groupByClass(t, baseline, "Accounts").Operations[0]
	got := groupByClass(t, corrected, "Accounts").Operations[0]
	if got.Function != "Example/createacct" {
		t.Fatalf("wrong wire function: %s", got.Function)
	}
	got.Function = want.Function
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("canonical contract changed: %+v vs %+v", got, want)
	}
	after, _ := json.Marshal(m)
	if string(before) != string(after) {
		t.Fatal("canonical map/source hashes mutated")
	}
}

func TestFunctionCorrectionRejectsDifferentLeafUnsafeRoutesAndUAPI(t *testing.T) {
	for _, target := range []string{"createacct", "Example/other", "/createacct", "Example/createacct/extra", "../createacct", "Example/../createacct", "https://host/createacct", "Example/createacct?q=x", "Example/createacct#x", "Example%2f/createacct", "9Example/createacct"} {
		t.Run(target, func(t *testing.T) {
			set := fixtureOverlay("listed")
			set.Files[1].Operations["whm1:createacct"] = functionCorrection(t, target)
			_, problems := buildProblems(t, fixtureMap(t), set)
			if !strings.Contains(problems, "function") {
				t.Fatalf("unsafe correction accepted: %s", problems)
			}
		})
	}
	set := fixtureOverlay("listed")
	set.Files[0].Operations["uapi:DomainInfo/list_domains"] = functionCorrection(t, "Example/list_domains")
	_, problems := buildProblems(t, fixtureMap(t), set)
	if !strings.Contains(problems, "function") {
		t.Fatalf("UAPI correction accepted: %s", problems)
	}
}
