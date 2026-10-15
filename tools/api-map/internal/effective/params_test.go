package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestBuildRejectsOmittedRequiredParameters(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{"username": {Omit: true}}
	set.Files[1].Operations["whm1:createacct"] = op
	_, joined := buildProblems(t, fixtureMap(t), set)
	if want := "whm1:createacct: required parameter username cannot be omitted; exclude the operation and use call()"; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}
}

func TestBuildRejectsOrdersItCannotHonour(t *testing.T) {
	set := fixtureOverlay("listed")
	create := set.Files[1].Operations["whm1:createacct"]
	create.Order = []string{"plan", "username"}
	set.Files[1].Operations["whm1:createacct"] = create
	list := set.Files[1].Operations["whm1:listaccts"]
	list.Order = []string{"MXCHECK-*"}
	set.Files[1].Operations["whm1:listaccts"] = list
	_, joined := buildProblems(t, fixtureMap(t), set)
	for _, want := range []string{
		"whm1:createacct: order lists optional parameter plan before required parameter username",
		"whm1:listaccts: order lists omitted parameter MXCHECK-*",
	} {
		if !strings.Contains(joined, want) {
			t.Errorf("missing %q in %s", want, joined)
		}
	}
}

func TestParamTypesDeriveScalarUnions(t *testing.T) {
	union := func(options ...TypeSpec) TypeSpec { return TypeSpec{Type: "union", AnyOf: options} }
	str, integer, number, mixed := TypeSpec{Type: "string"}, TypeSpec{Type: "int"}, TypeSpec{Type: "number"}, TypeSpec{Type: "mixed"}
	list := TypeSpec{Type: "list", Items: &str}
	for _, c := range []struct {
		spec      TypeSpec
		php       string
		composite bool
	}{
		{union(str, str), "string", false},
		{union(str, integer), "string|int", false},
		{union(integer, TypeSpec{Type: "flag"}), "int", false},
		{union(integer, number), "int|float", false},
		{union(str, number), "string|int|float", false},
		{union(str, mixed), "string|int", false},
		{union(str, list), "string|int", true},
		{TypeSpec{Type: "object"}, "string|int", true},
		{TypeSpec{Type: "map", Values: &str}, "string|int", true},
		{mixed, "string|int", false},
	} {
		php, doc, encoding, composite := paramTypes(c.spec, "")
		if php != c.php || doc != c.php || encoding != "scalar" || composite != c.composite {
			t.Errorf("%+v: got %s %s %s %v", c.spec, php, doc, encoding, composite)
		}
	}
}

func TestBuildRequiresDecisionsForCompositeParameters(t *testing.T) {
	m := fixtureMap(t)
	addQueryParameter(t, m, "whm1:createacct", "options", map[string]any{"type": "object", "properties": map[string]any{"a": map[string]any{"type": "string"}}})
	addQueryParameter(t, m, "whm1:createacct", "names", map[string]any{"oneOf": []any{
		map[string]any{"type": "string"}, map[string]any{"type": "array", "items": map[string]any{"type": "string"}}}})
	addQueryParameter(t, m, "whm1:createacct", "host", map[string]any{"type": "string", "oneOf": []any{
		map[string]any{"format": "hostname", "type": "string"}, map[string]any{"format": "ipv4", "type": "string"}}})
	_, joined := buildProblems(t, m, fixtureOverlay("listed"))
	for _, want := range []string{
		"whm1:createacct: parameter options has an object or list schema; declare its encoding or omit it",
		"whm1:createacct: parameter names has an object or list schema; declare its encoding or omit it",
	} {
		if !strings.Contains(joined, want) {
			t.Errorf("missing %q in %s", want, joined)
		}
	}
	if strings.Contains(joined, "parameter host") {
		t.Errorf("a union of strings needs no decision: %s", joined)
	}

	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{"options": {Omit: true}, "names": {Encoding: "repeat"}}
	op.Evidence = evidence()
	set.Files[1].Operations["whm1:createacct"] = op
	result, joined := buildProblems(t, m, set)
	if joined != "" {
		t.Fatal(joined)
	}
	for _, p := range groupByClass(t, result, "Accounts").Operations[0].Params {
		if p.Wire == "host" && p.PHPType != "string" {
			t.Errorf("host typed %s", p.PHPType)
		}
	}
}
