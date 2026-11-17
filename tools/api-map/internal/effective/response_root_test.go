package effective

import (
	"fmt"
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

func TestMissingPayloadRootCorrectionRejectsMalformedEnvelopeAlternatives(t *testing.T) {
	for _, composition := range []string{"anyOf", "oneOf", "allOf"} {
		for _, name := range []string{"nonstring", "empty", "whitespace", "unresolved", "referenced", "nested", "cycle", "deep"} {
			t.Run(composition+"/"+name, func(t *testing.T) {
				m, set, root := correctedRootFixture(t)
				schemas := map[string]any{}
				m.Documents["whm-openapi"]["components"] = map[string]any{"schemas": schemas}
				var branch any = root
				switch name {
				case "nonstring":
					root["$ref"] = false
				case "empty":
					root["$ref"] = ""
				case "whitespace":
					root["$ref"] = " \n "
				case "unresolved":
					root["$ref"] = "#/missing"
				case "referenced":
					root["$ref"] = 17
					schemas["Invalid"] = root
					branch = map[string]any{"$ref": "#/components/schemas/Invalid"}
				case "nested":
					root["$ref"] = false
					schemas["Invalid"] = map[string]any{"oneOf": []any{root}}
					branch = map[string]any{"anyOf": []any{map[string]any{"$ref": "#/components/schemas/Invalid"}}}
				case "cycle":
					// Properties let ordinary payload resolution finish; the new
					// admission guard must still reject a cyclic envelope reference.
					root[composition] = []any{map[string]any{"$ref": "#/components/schemas/Loop"}}
					schemas["Loop"] = root
				case "deep":
					for range 40 {
						branch = map[string]any{"anyOf": []any{branch}}
					}
				}
				envelope := map[string]any{composition: []any{branch}}
				if composition == "allOf" {
					// The ordinary WHM envelope reader uses properties rather
					// than allOf alone; admission also validates composed nodes.
					envelope["properties"] = map[string]any{"metadata": map[string]any{"type": "object"}}
				}
				setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), envelope)
				_, problems := buildProblems(t, m, set)
				if !strings.Contains(problems, "whm1:createacct:") {
					t.Fatalf("missing-payload root replacement accepted %s envelope alternative", name)
				}
			})
		}
	}
}

func TestMissingPayloadRootCorrectionPreservesResolvableEnvelopeAlternatives(t *testing.T) {
	for _, composition := range []string{"anyOf", "oneOf", "allOf"} {
		t.Run(composition, func(t *testing.T) {
			m, set, root := correctedRootFixture(t)
			// Result fields are outside the local envelope-reference guard.
			root["properties"].(map[string]any)["metadata"] = map[string]any{"$ref": false}
			m.Documents["whm-openapi"]["components"] = map[string]any{"schemas": map[string]any{"Envelope": root}}
			ref := map[string]any{"$ref": "#/components/schemas/Envelope"}
			var branch any = ref
			for range 12 {
				branch = map[string]any{"oneOf": []any{branch}}
			}
			envelope := map[string]any{composition: []any{branch, ref}}
			if composition == "allOf" {
				envelope["properties"] = map[string]any{"metadata": map[string]any{"type": "object"}}
			}
			setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), envelope)
			result, problems := buildProblems(t, m, set)
			if problems != "" {
				t.Fatal(problems)
			}
			if got := groupByClass(t, result, "Accounts").Operations[0].Returns; got == nil || fieldNames(*got) != "needs_reboot" {
				t.Fatalf("resolvable no-data alternatives lost corrected payload: %+v", got)
			}
		})
	}
}

func TestMissingPayloadRootCorrectionBoundsEnvelopeReferenceChains(t *testing.T) {
	for _, count := range []int{12, 40} {
		m, set, root := correctedRootFixture(t)
		schemas := map[string]any{"Leaf": root}
		m.Documents["whm-openapi"]["components"] = map[string]any{"schemas": schemas}
		ref := "#/components/schemas/Leaf"
		for i := range count {
			name := fmt.Sprintf("Ref%d", i)
			schemas[name] = map[string]any{"$ref": ref}
			ref = "#/components/schemas/" + name
		}
		setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), map[string]any{"anyOf": []any{map[string]any{"$ref": ref}}})
		_, problems := buildProblems(t, m, set)
		if count == 12 && problems != "" {
			t.Fatalf("bounded reference chain was rejected: %s", problems)
		}
		if count == 40 && !strings.Contains(problems, "whm1:createacct:") {
			t.Fatal("over-depth reference chain was accepted")
		}
	}
}

func TestMissingPayloadRootCorrectionLocallyRejectsCompositionCycles(t *testing.T) {
	for _, composition := range []string{"anyOf", "oneOf", "allOf"} {
		t.Run(composition, func(t *testing.T) {
			m, set, _ := correctedRootFixture(t)
			envelope := map[string]any{composition: []any{map[string]any{"$ref": "#/components/schemas/Loop"}}}
			m.Documents["whm-openapi"]["components"] = map[string]any{"schemas": map[string]any{"Loop": envelope}}
			setEnvelope(t, fixtureNode(t, m, "whm1:createacct"), envelope)
			s, err := newSchemas(m)
			if err != nil {
				t.Fatal(err)
			}
			b := builder{schemas: s}
			op := operationByID(t, m, "whm1:createacct")
			// Exercise admission directly: ordinary payload resolution has
			// separate, pre-existing recursion behavior outside this guard.
			if b.missingPayloadRoot(op, op.Variants[0], set.Files[1].Operations[op.ID], payloadInfo{}) != nil {
				t.Fatal("local missing-payload admission accepted a composition cycle")
			}
		})
	}
}
