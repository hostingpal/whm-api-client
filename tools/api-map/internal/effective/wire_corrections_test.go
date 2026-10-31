package effective

import (
	"fmt"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestBuildWireCorrectionPreservesSourceOrderAndPublicArguments(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{
		"username": parameterCorrection(t, `{"wire":"account_name"}`),
		"password": parameterCorrection(t, `{"wire":"account_password"}`),
	}
	op.Order = []string{"username", "password", "plan"}
	op.Evidence = evidence()
	set.Files[1].Operations["whm1:createacct"] = op
	result, problems := buildProblems(t, fixtureMap(t), set)
	if problems != "" {
		t.Fatal(problems)
	}
	params := groupByClass(t, result, "Accounts").Operations[0].Params
	if len(params) != 3 {
		t.Fatalf("parameters lost: %+v", params)
	}
	for i, want := range []struct {
		wire, php           string
		required, sensitive bool
	}{
		{"account_name", "username", true, false},
		{"account_password", "password", false, true},
		{"plan", "plan", false, false},
	} {
		got := params[i]
		if got.Wire != want.wire || got.PHP != want.php || got.Required != want.required || got.Sensitive != want.sensitive || got.PHPType != "string" || got.Encoding != "scalar" {
			t.Fatalf("parameter %d: got %+v, want %+v", i, got, want)
		}
	}
}

func TestBuildRejectsUnsafeOrCollidingWireCorrections(t *testing.T) {
	for _, target := range []string{"plan", "bad&name", "api.version", "cpanel.user", "cpanel.module", "cpanel.function", "name[]", "123", "field*"} {
		t.Run(target, func(t *testing.T) {
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			op.Params = map[string]overlay.Param{"username": parameterCorrection(t, fmt.Sprintf(`{"wire":%q}`, target))}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			_, problems := buildProblems(t, fixtureMap(t), set)
			if !strings.Contains(problems, "wire") {
				t.Fatalf("invalid wire name accepted: %s", problems)
			}
		})
	}
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{
		"username": parameterCorrection(t, `{"wire":"same"}`),
		"password": parameterCorrection(t, `{"wire":"same"}`),
	}
	op.Evidence = evidence()
	set.Files[1].Operations["whm1:createacct"] = op
	if _, problems := buildProblems(t, fixtureMap(t), set); !strings.Contains(problems, "wire") {
		t.Fatalf("duplicate corrected wire name accepted: %s", problems)
	}
}

func TestBuildRejectsWireCorrectionsForOmittedOrMultivalueInputs(t *testing.T) {
	for _, tc := range []struct {
		name    string
		schema  map[string]any
		setting string
	}{
		{"omitted", map[string]any{"type": "string"}, `{"wire":"target","omit":true}`},
		{"repeated", map[string]any{"type": "string"}, `{"wire":"target","encoding":"repeat"}`},
		{"numbered", map[string]any{"type": "string"}, `{"wire":"target","encoding":"numbered"}`},
		{"list scalar", map[string]any{"type": "array", "items": map[string]any{"type": "string"}}, `{"wire":"target","encoding":"scalar"}`},
		{"object scalar", map[string]any{"type": "object"}, `{"wire":"target","encoding":"scalar"}`},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", "candidate", tc.schema)
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:createacct"]
			op.Params = map[string]overlay.Param{"candidate": parameterCorrection(t, tc.setting)}
			op.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = op
			if _, problems := buildProblems(t, m, set); !strings.Contains(problems, "wire") {
				t.Fatalf("unsupported wire correction accepted: %s", problems)
			}
		})
	}
}
