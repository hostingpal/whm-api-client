package capability

import (
	"bytes"
	"os"
	"reflect"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

func comparisonProfile(t *testing.T) Profile {
	t.Helper()
	raw, err := os.ReadFile("../../../../docs/api/examples/capabilities/synthetic-success.json")
	if err != nil {
		t.Fatal(err)
	}
	p, err := DecodeProfile(raw)
	if err != nil {
		t.Fatal(err)
	}
	p.AdvertisedNames = []string{"accountsummary", "listaccts", "is_ready", "custom_plugin_call"}
	return p
}
func comparisonMap() model.Map {
	m := model.Map{SchemaVersion: "1.0"}
	for _, name := range []string{"accountsummary", "listaccts", "EcosystemFeatures/is_ready"} {
		m.Operations = append(m.Operations, model.Operation{ID: "whm1:" + name, Family: "whm1", Path: "/" + name})
	}
	m.Operations = append(m.Operations, model.Operation{ID: "uapi:Demo/list", Family: "uapi", Path: "/Demo/list"})
	return m
}
func TestCompareKeepsEvidenceSeparate(t *testing.T) {
	m, p := comparisonMap(), comparisonProfile(t)
	report, err := Compare(m, p, nil)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(report.WHM.Shared, []string{"accountsummary", "listaccts"}) || !reflect.DeepEqual(report.WHM.DocsOnly, []string{"EcosystemFeatures/is_ready"}) || !reflect.DeepEqual(report.WHM.RuntimeOnly, []string{"custom_plugin_call", "is_ready"}) {
		t.Fatalf("wrong exact sets: %+v", report.WHM)
	}
	if report.WHM.DocumentedCount != 3 || *report.WHM.AdvertisedCount != 4 {
		t.Fatalf("wrong counts: %+v", report.WHM)
	}
	for _, e := range report.Explanations {
		if e.Category != "unknown" || e.Certainty != "unknown" || e.RelatedName != "" {
			t.Fatalf("inferred cause or alias: %+v", e)
		}
	}
	pointer := "/reviewed-alias"
	explained, err := Compare(m, p, []Explanation{{Side: "docs-only", Name: "EcosystemFeatures/is_ready", Category: "namespace/alias", Certainty: "confirmed", Rationale: "Synthetic reviewed relationship only.", RelatedName: "is_ready", Evidence: []model.Locator{{SourceID: "synthetic-review", SourceSHA256: strings.Repeat("a", 64), Pointer: &pointer}}}})
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(report.WHM, explained.WHM) {
		t.Fatal("alias erased original differences")
	}
	if explained.Explanations[0].Category != "namespace/alias" {
		t.Fatal("explicit explanation missing")
	}
	raw, err := model.Encode(explained)
	if err != nil {
		t.Fatal(err)
	}
	if err := schema.Validate("capability-report-v1.schema.json", explained); err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(raw, []byte("unsupported")) {
		t.Fatal("unsupported assertion")
	}
	markdown := string(Markdown(explained))
	for _, value := range []string{p.Origin, p.ServerVersion, p.Context.Username, p.CapturedAt, p.CaptureStatus, "whm1", "uapi", "unavailable", "unverified", "EcosystemFeatures/is_ready", "is_ready", strings.Repeat("a", 64)} {
		if !strings.Contains(markdown, text(value)) {
			t.Errorf("Markdown omits %s", value)
		}
	}
}
func TestComparePreservesCaseAndDeduplicates(t *testing.T) {
	p := comparisonProfile(t)
	p.AdvertisedNames = []string{"listaccts", "listaccts", "ListAccts"}
	report, err := Compare(comparisonMap(), p, nil)
	if err != nil {
		t.Fatal(err)
	}
	if *report.WHM.AdvertisedCount != 2 || !reflect.DeepEqual(report.WHM.Shared, []string{"listaccts"}) || !reflect.DeepEqual(report.WHM.RuntimeOnly, []string{"ListAccts"}) {
		t.Fatalf("case or duplicate handling: %+v", report.WHM)
	}
}
func TestCompareUnavailableInventory(t *testing.T) {
	for _, fixture := range []string{"synthetic-failed.json", "synthetic-partial.json"} {
		t.Run(fixture, func(t *testing.T) {
			raw, err := os.ReadFile("../../../../docs/api/examples/capabilities/" + fixture)
			if err != nil {
				t.Fatal(err)
			}
			p, err := DecodeProfile(raw)
			if err != nil {
				t.Fatal(err)
			}
			report, err := Compare(comparisonMap(), p, nil)
			if err != nil {
				t.Fatal(err)
			}
			if report.WHM.InventoryStatus != "unavailable" || report.WHM.AdvertisedCount != nil || report.WHM.Shared != nil || report.WHM.DocsOnly != nil || report.WHM.RuntimeOnly != nil || len(report.Explanations) != 0 {
				t.Fatalf("unavailable inventory became differences: %+v", report)
			}
			if err := schema.Validate("capability-report-v1.schema.json", report); err != nil {
				t.Fatal(err)
			}
		})
	}
}
func TestCompareUnknownVersionAndHostIndependentDenominators(t *testing.T) {
	p := comparisonProfile(t)
	p.ServerVersion = "vendor edition banana"
	a, err := Compare(comparisonMap(), p, nil)
	if err != nil {
		t.Fatal(err)
	}
	p.Origin = "https://other.example.invalid:2087"
	b, err := Compare(comparisonMap(), p, nil)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(a.WHM, b.WHM) || !reflect.DeepEqual(a.UAPI, b.UAPI) {
		t.Fatal("host changed documentation denominators")
	}
	if a.UAPI.InventoryStatus != "unavailable" || a.UAPI.AdvertisedCount != nil || a.UAPI.DocumentedCount != 1 {
		t.Fatalf("invented UAPI inventory: %+v", a.UAPI)
	}
	for _, e := range a.Explanations {
		if e.Category != "unknown" {
			t.Fatal("unrecognized version inferred a cause")
		}
	}
	raw, _ := model.Encode(a)
	if bytes.Contains(raw, []byte("implementation")) {
		t.Fatal("capability comparison must not infer PHP implementation")
	}
}
func TestCompareRejectsInvalidExplanations(t *testing.T) {
	pointer := ""
	base := Explanation{Side: "runtime-only", Name: "custom_plugin_call", Category: "plugin", Certainty: "possible", Rationale: "Reviewed synthetic evidence.", Evidence: []model.Locator{{SourceID: "review", SourceSHA256: strings.Repeat("a", 64), Pointer: &pointer}}}
	for name, mutate := range map[string]func(*Explanation){"not a difference": func(e *Explanation) { e.Name = "listaccts" }, "wrong side": func(e *Explanation) { e.Side = "docs-only" }, "no evidence": func(e *Explanation) { e.Evidence = nil }, "unknown category": func(e *Explanation) { e.Category = "invented" }, "missing rationale": func(e *Explanation) { e.Rationale = "" }, "bad certainty": func(e *Explanation) { e.Certainty = "definite" }, "alias target missing": func(e *Explanation) { e.Category = "namespace/alias"; e.RelatedName = "absent" }, "unrelated relationship": func(e *Explanation) { e.RelatedName = "is_ready" }} {
		t.Run(name, func(t *testing.T) {
			e := base
			mutate(&e)
			if _, err := Compare(comparisonMap(), comparisonProfile(t), []Explanation{e}); err == nil {
				t.Fatal("accepted invalid explanation")
			}
		})
	}
}
func TestComparisonDeterministicAndPure(t *testing.T) {
	m, p := comparisonMap(), comparisonProfile(t)
	before, _ := model.Encode(p)
	a, err := Compare(m, p, nil)
	if err != nil {
		t.Fatal(err)
	}
	p.AdvertisedNames = []string{"is_ready", "listaccts", "custom_plugin_call", "accountsummary"}
	b, err := Compare(m, p, nil)
	if err != nil {
		t.Fatal(err)
	}
	// Profile preserves saved source order; repeat identical inputs must be identical.
	again, err := Compare(m, p, nil)
	if err != nil {
		t.Fatal(err)
	}
	ar, _ := model.Encode(b)
	br, _ := model.Encode(again)
	if !bytes.Equal(ar, br) || !bytes.Equal(Markdown(b), Markdown(again)) {
		t.Fatal("non-deterministic output")
	}
	after, _ := model.Encode(a.Profile)
	if !bytes.Equal(before, after) {
		t.Fatal("mutated saved profile")
	}
}

func TestComparisonSyntheticExampleReproduces(t *testing.T) {
	root := "../../../../docs/api/examples/capabilities/"
	read := func(name string) []byte {
		t.Helper()
		raw, err := os.ReadFile(root + name)
		if err != nil {
			t.Fatal(err)
		}
		return raw
	}
	m, err := model.DecodeMap(read("comparison-map.json"))
	if err != nil {
		t.Fatal(err)
	}
	p, err := DecodeProfile(read("comparison-profile.json"))
	if err != nil {
		t.Fatal(err)
	}
	e, err := LoadExplanations(read("comparison-explanations.json"))
	if err != nil {
		t.Fatal(err)
	}
	report, err := Compare(m, p, e)
	if err != nil {
		t.Fatal(err)
	}
	raw, err := model.Encode(report)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(raw, read("comparison.json")) || !bytes.Equal(Markdown(report), read("comparison.md")) {
		t.Fatal("synthetic report does not reproduce")
	}
}
func TestLoadExplanationsRejectsRawShapeErrors(t *testing.T) {
	for _, raw := range []string{`[]`, `{"schema_version":"1.0","explanations":[],"extra":true}`, `{"schema_version":"1.0","explanations":[]} null`, `{"schema_version":"1.0","explanations":[{"side":"runtime-only","name":"x","category":"unknown","certainty":"unknown","rationale":"reviewed","evidence":[],"Name":"x"}]}`} {
		if _, err := LoadExplanations([]byte(raw)); err == nil {
			t.Fatalf("accepted invalid raw explanations: %s", raw)
		}
	}
}
func TestCompareReviewedExplanationCategories(t *testing.T) {
	pointer := ""
	for _, category := range []string{"version", "plugin", "role/permission", "namespace/alias", "unknown"} {
		t.Run(category, func(t *testing.T) {
			certainty := "possible"
			if category == "unknown" {
				certainty = "unknown"
			}
			e := Explanation{Side: "runtime-only", Name: "custom_plugin_call", Category: category, Certainty: certainty, Rationale: "Synthetic reviewed evidence; no automatic cause inference.", Evidence: []model.Locator{{SourceID: "review", SourceSHA256: strings.Repeat("a", 64), Pointer: &pointer}}}
			report, err := Compare(comparisonMap(), comparisonProfile(t), []Explanation{e})
			if err != nil {
				t.Fatal(err)
			}
			found := false
			for _, item := range report.Explanations {
				if item.Name == e.Name && item.Category == category {
					found = true
					if item.Certainty != certainty || len(item.Evidence) != 1 {
						t.Fatal("lost explanation certainty or evidence")
					}
				}
			}
			if !found {
				t.Fatal("lost reviewed explanation")
			}
		})
	}
}
