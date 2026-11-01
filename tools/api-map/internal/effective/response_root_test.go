package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func correctedRootFixture(t *testing.T) (model.Map, overlay.Set, map[string]any) {
	t.Helper()
	m := fixtureMap(t)
	root := map[string]any{"type": "object", "properties": map[string]any{
		"metadata":     map[string]any{"type": "object"},
		"needs_reboot": map[string]any{"type": "integer", "enum": []any{0, 1}},
	}}
	setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), root)
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	loc := operationByID(t, m, "whm1:createacct").Variants[0].OperationLocator
	op.Response = map[string]overlay.Correction{"": {
		Replace: &overlay.Spec{Type: "object", Fields: map[string]overlay.Spec{"needs_reboot": {Type: "flag"}}},
		Evidence: []overlay.Evidence{
			{Kind: "doc", Version: "pinned", Date: "2026-11-01", Note: "incorrect source envelope", SourceSHA256: loc.SourceSHA256, Pointer: *loc.Pointer + "/responses/200/content/application~1json/schema"},
			{Kind: "live", Version: "11.138.0.19", Date: "2026-11-01", Note: "external review observed ordinary data envelope"},
		},
	}}
	set.Files[1].Operations["whm1:createacct"] = op
	return m, set, root
}

func TestMissingPayloadRootCorrectionUsesExactSourceAndLiveEvidence(t *testing.T) {
	m, set, _ := correctedRootFixture(t)
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	got := groupByClass(t, result, "Accounts").Operations[0].Returns
	if got == nil || got.Type != "object" || got.Nullable || len(got.Required) != 0 || fieldNames(*got) != "needs_reboot" || got.Fields[0].Spec.Type != "flag" {
		t.Fatalf("corrected root contract not retained: %+v", got)
	}
}

func TestMissingPayloadRootCorrectionAcceptsResolvedEnvelopeReference(t *testing.T) {
	m, set, root := correctedRootFixture(t)
	m.Documents["whm-openapi"]["components"] = map[string]any{"schemas": map[string]any{"Envelope": root}}
	setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), map[string]any{"$ref": "#/components/schemas/Envelope"})
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	if got := groupByClass(t, result, "Accounts").Operations[0].Returns; got == nil || fieldNames(*got) != "needs_reboot" {
		t.Fatalf("resolvable source was rejected: %+v", got)
	}
}

func TestMissingPayloadRootCorrectionRejectsUnboundEvidenceAndInvalidSource(t *testing.T) {
	for _, name := range []string{"hash", "pointer", "unrelated-doc", "doc-note", "no-doc", "no-live", "live-note", "live-version", "live-date", "void", "uapi", "unresolved", "malformed-ref", "malformed-target-ref", "no-json", "no-response", "partial", "non-replacement", "conflicting-root", "unknown-type", "missing-fields", "missing-items", "missing-values", "missing-branches", "undeclared-required", "invalid-accept"} {
		t.Run(name, func(t *testing.T) {
			m, set, root := correctedRootFixture(t)
			op := set.Files[1].Operations["whm1:createacct"]
			correction := op.Response[""]
			switch name {
			case "hash":
				correction.Evidence[0].SourceSHA256 = strings.Repeat("0", 64)
			case "pointer":
				correction.Evidence[0].Pointer += "/properties/needs_reboot"
			case "unrelated-doc":
				correction.Evidence[0].Kind = "live"
			case "doc-note":
				correction.Evidence[0].Note = " \n "
			case "no-doc":
				correction.Evidence = correction.Evidence[1:]
			case "no-live":
				correction.Evidence = correction.Evidence[:1]
			case "live-note":
				correction.Evidence[1].Note = " \n "
			case "live-version":
				correction.Evidence[1].Version = " "
			case "live-date":
				correction.Evidence[1].Date = " "
			case "void":
				op.Void = true
			case "uapi":
				for i := range m.Operations {
					if m.Operations[i].ID == "whm1:createacct" {
						m.Operations[i].Family = "uapi"
					}
				}
			case "unresolved":
				setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), map[string]any{"$ref": "#/missing"})
			case "malformed-ref":
				root["$ref"] = 17
			case "malformed-target-ref":
				m.Documents["whm-openapi"]["components"] = map[string]any{"schemas": map[string]any{"Invalid": root}}
				root["$ref"] = false
				setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), map[string]any{"$ref": "#/components/schemas/Invalid"})
			case "no-json":
				fixtureNode(t, m, "whm1:createacct")["responses"] = map[string]any{"200": map[string]any{}}
			case "no-response":
				delete(fixtureNode(t, m, "whm1:createacct"), "responses")
			case "partial":
				setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), map[string]any{"anyOf": []any{root, map[string]any{"type": "object", "properties": map[string]any{"data": map[string]any{"type": "string"}}}}})
			case "non-replacement":
				correction.Replace = nil
				correction.Type = "mixed"
			case "conflicting-root":
				value := true
				correction.Nullable = &value
			case "unknown-type":
				correction.Replace.Type = "unknown"
			case "missing-fields":
				correction.Replace.Fields = nil
			case "missing-items":
				correction.Replace = &overlay.Spec{Type: "list"}
			case "missing-values":
				correction.Replace = &overlay.Spec{Type: "map"}
			case "missing-branches":
				correction.Replace = &overlay.Spec{Type: "union"}
			case "undeclared-required":
				correction.Replace.Required = []string{"missing"}
			case "invalid-accept":
				correction.Replace.Fields["needs_reboot"] = overlay.Spec{Type: "flag", Accept: []string{"numeric-string"}}
			}
			op.Response[""] = correction
			set.Files[1].Operations["whm1:createacct"] = op
			_, problems := buildProblems(t, m, set)
			if name == "uapi" && !strings.Contains(problems, "response corrections need a documented payload") {
				t.Fatalf("UAPI was not rejected by payload correction gate: %s", problems)
			}
			if !strings.Contains(problems, "whm1:createacct:") {
				t.Fatalf("accepted %s: %s", name, problems)
			}
		})
	}
}

func TestRootCorrectionsPreserveOrdinaryDocumentedPayloadAndVoid(t *testing.T) {
	m, set, _ := correctedRootFixture(t)
	setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), map[string]any{"type": "object", "properties": map[string]any{"data": map[string]any{"type": "string"}}})
	op := set.Files[1].Operations["whm1:createacct"]
	correction := op.Response[""]
	correction.Evidence = evidence() // Ordinary documented roots need no new provenance gate.
	op.Response[""] = correction
	set.Files[1].Operations["whm1:createacct"] = op
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	if got := groupByClass(t, result, "Accounts").Operations[0].Returns; got == nil || got.Type != "object" {
		t.Fatalf("documented correction lost: %+v", got)
	}
	setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), map[string]any{"type": "object", "properties": map[string]any{"metadata": map[string]any{"type": "object"}}})
	op.Response = nil
	set.Files[1].Operations["whm1:createacct"] = op
	result, problems = buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	if got := groupByClass(t, result, "Accounts").Operations[0].Returns; got != nil {
		t.Fatalf("metadata-only operation gained payload: %+v", got)
	}
}
