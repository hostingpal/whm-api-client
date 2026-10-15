package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// setEnvelope replaces the documented 200 JSON schema of a fixture operation.
func setEnvelope(t *testing.T, node map[string]any, schema any) {
	t.Helper()
	node["responses"] = map[string]any{"200": map[string]any{"content": map[string]any{
		"application/json": map[string]any{"schema": schema},
	}}}
}

func TestBuildUsesDataFromEnvelopeAlternatives(t *testing.T) {
	m := fixtureMap(t)
	data := map[string]any{"allOf": []any{
		map[string]any{"description": "Only present when the token is recognized."},
		map[string]any{"type": "object", "properties": map[string]any{"uid": map[string]any{"type": "integer"}}},
	}}
	setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), map[string]any{"anyOf": []any{
		map[string]any{"type": "object", "properties": map[string]any{"data": data, "metadata": map[string]any{"type": "object"}}},
		map[string]any{"type": "object", "properties": map[string]any{"metadata": map[string]any{"type": "object"}}},
	}})
	_, joined := buildProblems(t, m, fixtureOverlay("listed"))
	if want := "whm1:createacct: data is documented in only some 200 envelope alternatives; set optional_payload"; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}

	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.OptionalPayload, op.Evidence = true, evidence()
	set.Files[1].Operations["whm1:createacct"] = op
	result, joined := buildProblems(t, m, set)
	if joined != "" {
		t.Fatal(joined)
	}
	create := groupByClass(t, result, "Accounts").Operations[0]
	if create.Returns == nil || create.Returns.Type != "object" || fieldNames(*create.Returns) != "uid" {
		t.Fatalf("payload from the alternatives: %+v", create.Returns)
	}
}

func TestBuildReportsUnresolvableEnvelopes(t *testing.T) {
	for name, c := range map[string]struct {
		schema any
		want   string
	}{
		"no properties": {map[string]any{"type": "object"}, "the 200 envelope documents neither properties nor alternatives"},
		"broken ref":    {map[string]any{"$ref": "#/components/schemas/Missing"}, "the 200 response schema cannot be resolved"},
		"broken result": {map[string]any{"type": "object", "properties": map[string]any{"result": map[string]any{"$ref": "#/components/schemas/Missing"}}}, ""},
	} {
		t.Run(name, func(t *testing.T) {
			m := fixtureMap(t)
			setEnvelope(t, fixtureNode(t, m, "whm1:listaccts"), c.schema)
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:listaccts"]
			op.Response = nil
			set.Files[1].Operations["whm1:listaccts"] = op
			_, joined := buildProblems(t, m, set)
			if c.want == "" {
				// A WHM envelope ignores "result"; it simply documents no data.
				if strings.Contains(joined, "whm1:listaccts") {
					t.Fatalf("unexpected problem: %s", joined)
				}
				return
			}
			want := "whm1:listaccts: " + c.want + "; declare void with evidence or exclude the operation"
			if !strings.Contains(joined, want) {
				t.Fatalf("missing %q in %s", want, joined)
			}
			op.Void, op.Evidence = true, evidence()
			set.Files[1].Operations["whm1:listaccts"] = op
			if _, joined := buildProblems(t, m, set); joined != "" {
				t.Fatalf("void did not resolve the envelope: %s", joined)
			}
		})
	}
}

func TestBuildReportsUnresolvableUAPIResult(t *testing.T) {
	m := fixtureMap(t)
	setEnvelope(t, fixtureNode(t, m, "uapi:DomainInfo/list_domains"), map[string]any{
		"type": "object", "properties": map[string]any{"result": map[string]any{"$ref": "#/components/schemas/Missing"}},
	})
	_, joined := buildProblems(t, m, fixtureOverlay("listed"))
	if want := "uapi:DomainInfo/list_domains: the UAPI result envelope cannot be resolved; declare void with evidence or exclude the operation"; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}
}

func TestBuildReportsIssuesUntilReplaced(t *testing.T) {
	m := fixtureMap(t)
	setEnvelope(t, fixtureNode(t, m, "whm1:listaccts"), map[string]any{"type": "object", "properties": map[string]any{
		"data": map[string]any{"type": "object", "properties": map[string]any{
			"acct": map[string]any{"type": "array", "items": map[string]any{"type": "object",
				"properties":           map[string]any{"uid": map[string]any{"type": "integer"}},
				"additionalProperties": map[string]any{"type": "string"}}},
		}},
	}})
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:listaccts"]
	op.Response = nil
	set.Files[1].Operations["whm1:listaccts"] = op
	_, joined := buildProblems(t, m, set)
	want := `whm1:listaccts: payload path "acct[]" declares properties and additionalProperties, which the engine cannot represent together; replace it in the overlay`
	if !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}

	op.Response = map[string]overlay.Correction{"acct[]": {Replace: &overlay.Spec{Type: "map", Values: &overlay.Spec{Type: "mixed"}}, Evidence: evidence()}}
	set.Files[1].Operations["whm1:listaccts"] = op
	if _, joined := buildProblems(t, m, set); joined != "" {
		t.Fatalf("replace did not resolve the issue: %s", joined)
	}
}

func TestApplyCorrectionReplacesThePayloadRoot(t *testing.T) {
	root := &TypeSpec{Type: "object", Issue: "declares properties and additionalProperties"}
	if err := applyCorrection(root, "", overlay.Correction{Replace: &overlay.Spec{Type: "map", Values: &overlay.Spec{Type: "string"}}, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	if root.Type != "map" || root.Values.Type != "string" || root.Issue != "" {
		t.Fatalf("root replace: %+v", root)
	}
}
