package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// The runtime SpecChecker rejects these specs before every call, so the
// generator must never emit them.

func TestBuildRejectsRequiredNamesThatAreNotFields(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:listaccts"]
	op.Response = map[string]overlay.Correction{
		"acct[]": {Replace: &overlay.Spec{Type: "object", Fields: map[string]overlay.Spec{"uid": {Type: "int"}}, Required: []string{"uid", "user"}}, Evidence: evidence()},
	}
	set.Files[1].Operations["whm1:listaccts"] = op
	_, joined := buildProblems(t, fixtureMap(t), set)
	if want := `whm1:listaccts: payload path "acct[]" requires undeclared field user`; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}
}

func TestBuildRejectsUnwrapOfANullablePayload(t *testing.T) {
	m := fixtureMap(t)
	node := fixtureNode(t, m, "uapi:DomainInfo/primary_domain")
	data := node["responses"].(map[string]any)["200"].(map[string]any)["content"].(map[string]any)["application/json"].(map[string]any)["schema"].(map[string]any)["properties"].(map[string]any)["result"].(map[string]any)["properties"].(map[string]any)["data"].(map[string]any)
	data["nullable"] = true
	_, joined := buildProblems(t, m, fixtureOverlay("listed"))
	if want := "uapi:DomainInfo/primary_domain: unwrap needs a non-nullable object payload"; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}
}

func TestBuildRejectsDuplicateWireNames(t *testing.T) {
	m := fixtureMap(t)
	addQueryParameter(t, m, "whm1:createacct", "plan", map[string]any{"type": "string"})
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{"plan": {PHP: "planName"}}
	set.Files[1].Operations["whm1:createacct"] = op
	_, joined := buildProblems(t, m, set)
	if want := "whm1:createacct: parameter plan is documented more than once"; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}
}
