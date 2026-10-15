package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func formBody(required bool, schema map[string]any) map[string]any {
	return map[string]any{"required": required, "content": map[string]any{
		"application/x-www-form-urlencoded": map[string]any{"schema": schema},
	}}
}

func wireNames(params []Param) string {
	names := make([]string, len(params))
	for i, p := range params {
		names[i] = p.Wire
	}
	return strings.Join(names, ",")
}

func TestBuildMapsFormBodyFieldsToParameters(t *testing.T) {
	m := fixtureMap(t)
	fixtureNode(t, m, "whm1:listaccts")["requestBody"] = formBody(true, map[string]any{
		"type": "object",
		"properties": map[string]any{
			"secret_code": map[string]any{"type": "string", "description": "A one-off secret. Never logged."},
			"count":       map[string]any{"type": "integer"},
		},
		"required": []any{"secret_code"},
	})
	result, joined := buildProblems(t, m, fixtureOverlay("listed"))
	if joined != "" {
		t.Fatal(joined)
	}
	list := groupByClass(t, result, "Accounts").Operations[1]
	if list.ID != "whm1:listaccts" || wireNames(list.Params) != "secret_code,count" {
		t.Fatalf("body parameters %+v", list.Params)
	}
	secret, count := list.Params[0], list.Params[1]
	if !secret.Required || !secret.Sensitive || secret.Description != "A one-off secret." || count.Required || count.PHPType != "int" {
		t.Fatalf("body parameter details %+v", list.Params)
	}
	// Body fields travel in the form-encoded POST body, even for a read-only operation.
	if list.Method != "POST" {
		t.Fatalf("method %s", list.Method)
	}

	// A body that is not required leaves every field optional.
	fixtureNode(t, m, "whm1:listaccts")["requestBody"] = formBody(false, map[string]any{
		"type": "object", "properties": map[string]any{"count": map[string]any{"type": "integer"}}, "required": []any{"count"},
	})
	result, _ = buildProblems(t, m, fixtureOverlay("listed"))
	if params := groupByClass(t, result, "Accounts").Operations[1].Params; len(params) != 1 || params[0].Required {
		t.Fatalf("optional body %+v", params)
	}
}

func TestBuildRejectsUnsupportedRequestBodies(t *testing.T) {
	for name, c := range map[string]struct {
		body map[string]any
		want string
	}{
		"multipart": {map[string]any{"content": map[string]any{"multipart/form-data": map[string]any{"schema": map[string]any{"type": "object"}}}},
			"documents a request body that is not form-encoded; exclude it until such bodies are supported"},
		"alternatives": {formBody(true, map[string]any{"anyOf": []any{map[string]any{"type": "object", "properties": map[string]any{"a": map[string]any{"type": "string"}}}}}),
			"documents a form request body without plain properties; exclude it until such bodies are supported"},
		"collision": {formBody(true, map[string]any{"type": "object", "properties": map[string]any{"MXCHECK-*": map[string]any{"type": "string"}}}),
			"parameter MXCHECK-* is documented in both the query and the request body"},
	} {
		t.Run(name, func(t *testing.T) {
			m := fixtureMap(t)
			fixtureNode(t, m, "whm1:listaccts")["requestBody"] = c.body
			_, joined := buildProblems(t, m, fixtureOverlay("listed"))
			if want := "whm1:listaccts: " + c.want; !strings.Contains(joined, want) {
				t.Fatalf("missing %q in %s", want, joined)
			}
		})
	}
}

func TestBuildRefusesGETForRequestBodies(t *testing.T) {
	m := fixtureMap(t)
	fixtureNode(t, m, "whm1:listaccts")["requestBody"] = formBody(true, map[string]any{
		"type": "object", "properties": map[string]any{"count": map[string]any{"type": "integer"}},
	})
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:listaccts"]
	op.Method, op.Evidence = "GET", evidence()
	set.Files[1].Operations["whm1:listaccts"] = op
	if _, joined := buildProblems(t, m, set); !strings.Contains(joined, "whm1:listaccts: request body parameters require POST") {
		t.Fatalf("GET accepted for a request body: %s", joined)
	}
}

// addPostVariant gives whm1:listaccts a second, POST variant whose form body
// carries a passphrase.
func addPostVariant(t *testing.T, m model.Map) {
	t.Helper()
	paths := m.Documents["whm-openapi"]["paths"].(map[string]any)
	item := paths["/listaccts"].(map[string]any)
	get := item["get"].(map[string]any)
	post := map[string]any{}
	for k, v := range get {
		post[k] = v
	}
	post["requestBody"] = formBody(false, map[string]any{"type": "object", "properties": map[string]any{"passphrase": map[string]any{"type": "string"}}})
	item["post"] = post
	for i := range m.Operations {
		if m.Operations[i].ID != "whm1:listaccts" {
			continue
		}
		variant := m.Operations[i].Variants[0]
		variant.ID, variant.Method = "whm1:listaccts#POST", "POST"
		pointer, upstream := "/paths/~1listaccts/post", "Accounts-listaccts-post"
		variant.OperationLocator.Pointer, variant.UpstreamOperationID = &pointer, &upstream
		m.Operations[i].Variants = append(m.Operations[i].Variants, variant)
		return
	}
	t.Fatal("fixture operation missing")
}

func TestBuildNeedsAVariantDecisionForSeveralVariants(t *testing.T) {
	m := fixtureMap(t)
	addPostVariant(t, m)
	_, joined := buildProblems(t, m, fixtureOverlay("listed"))
	if want := "whm1:listaccts: operations with several variants need an overlay variant decision (GET, POST)"; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}

	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:listaccts"]
	op.Variant, op.Evidence = "POST", evidence()
	set.Files[1].Operations["whm1:listaccts"] = op
	result, joined := buildProblems(t, m, set)
	if joined != "" {
		t.Fatal(joined)
	}
	list := groupByClass(t, result, "Accounts").Operations[1]
	if list.VariantID != "whm1:listaccts#POST" || list.Method != "POST" || wireNames(list.Params) != "passphrase" || !list.Params[0].Sensitive {
		t.Fatalf("selected variant %+v", list)
	}
	if !strings.HasSuffix(list.SeeURL, "/accounts-listaccts-post") {
		t.Fatalf("variant link %s", list.SeeURL)
	}

	op.Variant = "PUT"
	set.Files[1].Operations["whm1:listaccts"] = op
	if _, joined := buildProblems(t, m, set); !strings.Contains(joined, "whm1:listaccts: variant PUT is not documented (GET, POST)") {
		t.Fatalf("unknown variant accepted: %s", joined)
	}
	op.Variant = "POST"
	set.Files[1].Operations["whm1:listaccts"] = op
	if _, joined := buildProblems(t, fixtureMap(t), set); !strings.Contains(joined, "whm1:listaccts: variant POST is not documented (GET)") {
		t.Fatalf("variant on a single-variant operation accepted: %s", joined)
	}
	if problems := overlay.Validate(set); len(problems) != 0 {
		t.Fatalf("variant with evidence: %v", problems)
	}
}
