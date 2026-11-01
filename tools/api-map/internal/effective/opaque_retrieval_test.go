package effective

import (
	"crypto/sha256"
	"encoding/json"
	"reflect"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// This independent fixture uses a different identity from the production retrieval.
func opaqueRetrievalFixture(t *testing.T) (model.Map, overlay.Set) {
	t.Helper()
	m := fixtureMap(t)
	fixtureNode(t, m, "whm1:suspendacct")["x-readonly"] = true
	set := fixtureOverlay("listed")
	set.Files[1].Operations["whm1:suspendacct"] = overlay.Operation{
		Name: "retrieveOpaque", Method: "GET", Evidence: evidence(),
		Response: map[string]overlay.Correction{"": {
			Replace: &overlay.Spec{Type: "mixed"}, Evidence: evidence(),
		}},
	}
	return m, set
}

func opaqueEnvelope(t *testing.T, m model.Map) map[string]any {
	t.Helper()
	node := fixtureNode(t, m, "whm1:suspendacct")
	responses, _ := asObject(node["responses"])
	response, _ := asObject(responses["200"])
	content, _ := asObject(response["content"])
	media, _ := asObject(content["application/json"])
	schema, _ := asObject(media["schema"])
	return schema
}

func TestOpaqueRetrievalRestoresOnlyEvidencedMetadataOnlyResult(t *testing.T) {
	m, set := opaqueRetrievalFixture(t)
	sourceBefore, _ := json.Marshal(m)
	baselineSet := fixtureOverlay("listed")
	baselineSet.Files[1].Operations["whm1:suspendacct"] = overlay.Operation{Name: "retrieveOpaque", Method: "GET", Evidence: evidence()}
	baseline, problems := buildProblems(t, m, baselineSet)
	if problems != "" {
		t.Fatal(problems)
	}
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	before := groupByClass(t, baseline, "Accounts").Operations[2]
	got := groupByClass(t, result, "Accounts").Operations[2]
	if before.Returns != nil || !reflect.DeepEqual(got.Returns, &TypeSpec{Type: "mixed"}) {
		t.Fatalf("wrong restoration: before=%+v after=%+v", before.Returns, got.Returns)
	}
	rawBefore, _ := json.Marshal(before)
	rawAfter, _ := json.Marshal(got)
	if sha256.Sum256(rawBefore) == sha256.Sum256(rawAfter) {
		t.Fatal("effective hash omitted correction")
	}
	got.Returns = nil
	if !reflect.DeepEqual(got, before) {
		t.Fatal("request/source identity changed")
	}
	sourceAfter, _ := json.Marshal(m)
	if string(sourceBefore) != string(sourceAfter) {
		t.Fatal("canonical map/source hashes mutated")
	}
}

func TestOpaqueRetrievalRejectsUnreviewedSourceAndPolicyCases(t *testing.T) {
	cases := map[string]func(model.Map, *overlay.Operation){
		"not readonly":     func(m model.Map, _ *overlay.Operation) { fixtureNode(t, m, "whm1:suspendacct")["x-readonly"] = false },
		"missing readonly": func(m model.Map, _ *overlay.Operation) { delete(fixtureNode(t, m, "whm1:suspendacct"), "x-readonly") },
		"policy POST":      func(_ model.Map, op *overlay.Operation) { op.Method = "POST" },
		"void":             func(_ model.Map, op *overlay.Operation) { op.Void = true },
		"optional":         func(_ model.Map, op *overlay.Operation) { op.OptionalPayload = true },
		"unwrap":           func(_ model.Map, op *overlay.Operation) { op.Unwrap = "metadata" },
		"top-level result sibling": func(m model.Map, _ *overlay.Operation) {
			props, _ := asObject(opaqueEnvelope(t, m)["properties"])
			props["needs_reboot"] = map[string]any{"type": "integer"}
		},
		"wrong top-level key": func(m model.Map, _ *overlay.Operation) {
			opaqueEnvelope(t, m)["properties"] = map[string]any{"result": map[string]any{"type": "object"}}
		},
		"missing envelope type": func(m model.Map, _ *overlay.Operation) { delete(opaqueEnvelope(t, m), "type") },
		"nonobject envelope":    func(m model.Map, _ *overlay.Operation) { opaqueEnvelope(t, m)["type"] = "array" },
		"nullable envelope":     func(m model.Map, _ *overlay.Operation) { opaqueEnvelope(t, m)["nullable"] = true },
		"unresolved envelope":   func(m model.Map, _ *overlay.Operation) { opaqueEnvelope(t, m)["$ref"] = "#/components/schemas/Missing" },
		"no JSON response": func(m model.Map, _ *overlay.Operation) {
			fixtureNode(t, m, "whm1:suspendacct")["responses"] = map[string]any{"200": map[string]any{"description": "no JSON"}}
		},
		"no success response": func(m model.Map, _ *overlay.Operation) {
			fixtureNode(t, m, "whm1:suspendacct")["responses"] = map[string]any{}
		},
		"no schema": func(m model.Map, _ *overlay.Operation) {
			for k := range opaqueEnvelope(t, m) {
				delete(opaqueEnvelope(t, m), k)
			}
		},
		"allOf alongside properties": func(m model.Map, _ *overlay.Operation) {
			opaqueEnvelope(t, m)["allOf"] = []any{map[string]any{"type": "object"}}
		},
		"oneOf alongside properties": func(m model.Map, _ *overlay.Operation) {
			opaqueEnvelope(t, m)["oneOf"] = []any{map[string]any{"type": "object"}}
		},
		"anyOf alongside properties": func(m model.Map, _ *overlay.Operation) {
			opaqueEnvelope(t, m)["anyOf"] = []any{map[string]any{"type": "object"}}
		},
		"metadata-only alternatives": func(m model.Map, _ *overlay.Operation) {
			env := opaqueEnvelope(t, m)
			delete(env, "properties")
			env["oneOf"] = []any{map[string]any{"type": "object", "properties": map[string]any{"metadata": map[string]any{"type": "object"}}}}
		},
		"extra correction": func(_ model.Map, op *overlay.Operation) {
			op.Response["extra"] = overlay.Correction{Type: "string", Evidence: evidence()}
		},
		"nonroot correction": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			delete(op.Response, "")
			op.Response["data"] = c
		},
		"no replacement": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			c.Replace = nil
			c.Type = "mixed"
			op.Response[""] = c
		},
		"object replacement":   func(_ model.Map, op *overlay.Operation) { op.Response[""].Replace.Type = "object" },
		"nullable replacement": func(_ model.Map, op *overlay.Operation) { op.Response[""].Replace.Nullable = true },
		"adorned mixed replacement": func(_ model.Map, op *overlay.Operation) {
			op.Response[""].Replace.Fields = map[string]overlay.Spec{"invented": {Type: "string"}}
		},
		"correction accept": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			c.Accept = []string{"numeric-string"}
			op.Response[""] = c
		},
		"correction nullable": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			b := true
			c.Nullable = &b
			op.Response[""] = c
		},
		"correction preserve": func(_ model.Map, op *overlay.Operation) { c := op.Response[""]; c.Preserve = true; op.Response[""] = c },
		"correction type":     func(_ model.Map, op *overlay.Operation) { c := op.Response[""]; c.Type = "mixed"; op.Response[""] = c },
		"correction add": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			c.Add = &overlay.Spec{Type: "mixed"}
			op.Response[""] = c
		},
		"correction required": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			b := true
			c.Required = &b
			op.Response[""] = c
		},
		"no correction evidence": func(_ model.Map, op *overlay.Operation) { c := op.Response[""]; c.Evidence = nil; op.Response[""] = c },
		"doc-only evidence": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			c.Evidence[0].Kind = "doc"
			op.Response[""] = c
		},
		"blank live version": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			c.Evidence[0].Version = " "
			op.Response[""] = c
		},
		"blank live note": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			c.Evidence[0].Note = " "
			op.Response[""] = c
		},
		"invalid live date": func(_ model.Map, op *overlay.Operation) {
			c := op.Response[""]
			c.Evidence[0].Date = "2026-99-99"
			op.Response[""] = c
		},
	}
	for name, change := range cases {
		t.Run(name, func(t *testing.T) {
			m, set := opaqueRetrievalFixture(t)
			op := set.Files[1].Operations["whm1:suspendacct"]
			change(m, &op)
			set.Files[1].Operations["whm1:suspendacct"] = op
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "response corrections need a documented payload") {
				t.Fatalf("unsafe restoration accepted or wrong rejection: %s", problems)
			}
		})
	}
}

func TestOpaqueRetrievalDoesNotRestoreUAPIOrPostSource(t *testing.T) {
	for _, family := range []string{"uapi", "POST"} {
		t.Run(family, func(t *testing.T) {
			m, set := opaqueRetrievalFixture(t)
			for index := range m.Operations {
				if m.Operations[index].ID == "whm1:suspendacct" {
					if family == "uapi" {
						m.Operations[index].Family = "uapi"
					} else {
						m.Operations[index].Variants[0].Method = "POST"
					}
				}
			}
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "response corrections need a documented payload") {
				t.Fatal(problems)
			}
		})
	}
}

func TestOpaqueRetrievalExclusionAndDocumentedCorrectionsStaySeparate(t *testing.T) {
	m, set := opaqueRetrievalFixture(t)
	op := set.Files[1].Operations["whm1:suspendacct"]
	op.Exclude = &overlay.Exclusion{Reason: "excluded", Evidence: evidence()}
	set.Files[1].Operations["whm1:suspendacct"] = op
	result, _ := buildProblems(t, m, set)
	if generatedIDs(result)["whm1:suspendacct"] {
		t.Fatal("excluded retrieval generated")
	}
	if len(overlay.Validate(set)) == 0 {
		t.Fatal("exclusion with corrections accepted")
	}
	// A documented payload still uses the ordinary correction path, with doc evidence.
	m = fixtureMap(t)
	set = fixtureOverlay("listed")
	op = set.Files[1].Operations["whm1:createacct"]
	op.Response = map[string]overlay.Correction{"": {Replace: &overlay.Spec{Type: "object", Fields: map[string]overlay.Spec{"value": {Type: "string"}}}, Evidence: []overlay.Evidence{{Kind: "doc", Version: "pinned", Date: "2026-10-31", Note: "documented correction"}}}}
	set.Files[1].Operations["whm1:createacct"] = op
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	if groupByClass(t, result, "Accounts").Operations[0].Returns.Type != "object" {
		t.Fatal("ordinary correction changed")
	}
}

func TestOpaqueRetrievalFollowsResolvedEnvelopeWithoutChangingOtherPayloads(t *testing.T) {
	m, set := opaqueRetrievalFixture(t)
	env := opaqueEnvelope(t, m)
	doc, _ := asObject(m.Documents["whm-openapi"])
	doc["reviewedEnvelope"] = env
	node := fixtureNode(t, m, "whm1:suspendacct")
	responses, _ := asObject(node["responses"])
	response, _ := asObject(responses["200"])
	content, _ := asObject(response["content"])
	media, _ := asObject(content["application/json"])
	media["schema"] = map[string]any{"$ref": "#/reviewedEnvelope"}
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	if groupByClass(t, result, "Accounts").Operations[2].Returns.Type != "mixed" {
		t.Fatal("resolved plain envelope not restored")
	}
	// Omitting the explicit correction continues to produce the pinned void result.
	op := set.Files[1].Operations["whm1:suspendacct"]
	op.Response = nil
	set.Files[1].Operations["whm1:suspendacct"] = op
	result, problems = buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	if groupByClass(t, result, "Accounts").Operations[2].Returns != nil {
		t.Fatal("payload inferred without correction")
	}
}

func TestOpaqueRetrievalNeverBypassesPayloadIssuePartialOrExistingSpec(t *testing.T) {
	for name, info := range map[string]payloadInfo{
		"issue":             {metadataOnly: true, issue: "unresolvable"},
		"partial":           {metadataOnly: true, partial: true},
		"documented":        {metadataOnly: true, spec: &TypeSpec{Type: "string"}},
		"not metadata only": {},
	} {
		t.Run(name, func(t *testing.T) {
			m, set := opaqueRetrievalFixture(t)
			op := operationByID(t, m, "whm1:suspendacct")
			if restoreOpaqueRetrieval(op, op.Variants[0], set.Files[1].Operations[op.ID], info, fixtureNode(t, m, op.ID), "GET") {
				t.Fatal("payload restriction bypassed")
			}
		})
	}
}
