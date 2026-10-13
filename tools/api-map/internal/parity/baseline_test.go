package parity

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/contract"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"testing"
)

func baselineFixture(t *testing.T) (model.Map, Registry, Symbols, Baseline) {
	t.Helper()
	m, r, s := reportFixture()
	v := &m.Operations[0].Variants[0]
	p := model.Object{"name": "x", "in": "query", "schema": model.Object{"type": "string"}}
	m.Documents["s"]["paths"].(model.Object)["/WebApp/stage"].(model.Object)["get"].(model.Object)["parameters"] = []any{p}
	v.Parameters = []model.Parameter{{Name: "x", In: "query", Definition: p, Locator: model.Locator{SourceID: "s", Pointer: stringPointer(*v.OperationLocator.Pointer + "/parameters/0")}}}
	units, _, err := contract.AnalyzeUnits(m, *v)
	if err != nil {
		t.Fatal(err)
	}
	r.Entries[0].ContractUnits = []ContractClaim{{ID: units[0].ID, Hash: units[0].Hash, Tests: []string{"Test::stage"}}}
	b := Baseline{SchemaVersion: "1.0", Operations: []string{}, Mappings: []ProtectedMapping{{OperationID: r.Entries[0].OperationID, VariantID: v.ID, Symbol: r.Entries[0].Symbol, Status: "implemented", Tests: []string{"Test::stage"}, ContractUnits: append([]ContractClaim{}, r.Entries[0].ContractUnits...)}}}
	return m, r, s, b
}

func TestBaselineProtectsMappings(t *testing.T) {
	if got := CheckBaseline(Report{}, Baseline{SchemaVersion: "1.0"}); len(got) != 0 {
		t.Fatal(got)
	}
	tests := []struct {
		name       string
		change     func(*model.Map, *Registry, *Symbols)
		regression bool
		invalid    bool
	}{
		{"unchanged", func(*model.Map, *Registry, *Symbols) {}, false, false},
		{"unrelated addition cannot hide removal", func(m *model.Map, r *Registry, s *Symbols) {
			r.Entries = nil
			op := m.Operations[0]
			op.ID = "uapi:Other/b"
			op.Variants = nil
			m.Operations = append(m.Operations, op)
		}, true, false},
		{"nonexistent mapping", func(m *model.Map, r *Registry, s *Symbols) {
			r.Entries[0].OperationID = "uapi:Other/missing"
			r.Entries[0].VariantID = "uapi:Other/missing#GET"
		}, true, true},
		{"lost variant", func(m *model.Map, r *Registry, s *Symbols) {
			r.Entries = nil
			m.Operations[0].Variants = m.Operations[0].Variants[1:]
		}, true, false},
		{"lost contract", func(m *model.Map, r *Registry, s *Symbols) { r.Entries[0].ContractUnits = nil }, true, false},
		{"lost test mapping", func(m *model.Map, r *Registry, s *Symbols) { r.Entries[0].Tests = nil }, true, false},
		{"different symbol", func(m *model.Map, r *Registry, s *Symbols) {
			x := s.Symbols[0]
			x.ID = "Client::other"
			s.Symbols = append(s.Symbols, x)
			r.Entries[0].Symbol = x.ID
		}, true, false},
		{"test moved between claims", func(m *model.Map, r *Registry, s *Symbols) {
			x := s.Symbols[1]
			x.ID = "Test::other"
			s.Symbols = append(s.Symbols, x)
			r.Entries[0].ContractUnits[0].Tests = []string{x.ID}
			r.Entries[0].Tests = append(r.Entries[0].Tests, x.ID)
		}, true, false},
		{"status weakened", func(m *model.Map, r *Registry, s *Symbols) { r.Entries[0].Status = "partial" }, true, false},
		{"denominator grows", func(m *model.Map, r *Registry, s *Symbols) {
			op := m.Operations[0]
			op.ID = "uapi:Other/c"
			op.Variants = nil
			m.Operations = append(m.Operations, op)
		}, false, false},
		{"orphan requires review", func(m *model.Map, r *Registry, s *Symbols) { m.Operations = nil }, true, true},
		{"stale hash requires review", func(m *model.Map, r *Registry, s *Symbols) {
			m.Operations[0].Variants[0].Parameters[0].Definition["required"] = true
		}, true, true},
		{"live evidence absent", func(m *model.Map, r *Registry, s *Symbols) { r.Entries[0].LiveEvidenceIDs = []string{"not-supplied"} }, false, false},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m, r, s, b := baselineFixture(t)
			tt.change(&m, &r, &s)
			report, err := Compare(m, r, s, Evidence{})
			if err != nil {
				t.Fatal(err)
			}
			if report.Valid == tt.invalid {
				t.Fatalf("valid=%t diagnostics=%+v", report.Valid, report.Diagnostics)
			}
			got := CheckBaseline(report, b)
			if (len(got) > 0) != tt.regression {
				t.Fatalf("violations=%+v", got)
			}
		})
	}
}
func TestBaselineRejectsInvalidShape(t *testing.T) {
	for _, raw := range []string{`{"schema_version":"1.0","operations":[],"mappings":[],"command":"sh"}`, `{"schema_version":"1.0","operations":[],"operations":[],"mappings":[]}`, `{"schema_version":"1.0","operations":[],"mappings":[]} {}`} {
		if _, err := LoadBaseline([]byte(raw)); err == nil {
			t.Fatal("invalid baseline accepted")
		}
	}
	_, err := LoadBaseline([]byte(`{"schema_version":"1.0","operations":[],"mappings":[{"operation_id":"whm1:a","variant_id":"whm1:b#GET","symbol":"C::a","status":"implemented","tests":[],"contract_units":[]}]}`))
	if err == nil {
		t.Fatal("inconsistent IDs accepted")
	}
}

func TestReportMappingFactsRetainInvalidAndExactLinks(t *testing.T) {
	m, r, s, b := baselineFixture(t)
	report, err := Compare(m, r, s, Evidence{})
	if err != nil {
		t.Fatal(err)
	}
	if len(report.Mappings) != 1 || !report.Mappings[0].Valid || report.Mappings[0].Symbol != b.Mappings[0].Symbol || len(report.Mappings[0].ContractUnits) != 1 {
		t.Fatalf("mapping facts lost: %+v", report.Mappings)
	}
	old, _ := model.Encode(report)
	r.Entries[0].ContractUnits[0].Tests = []string{"Test::missing"}
	report, err = Compare(m, r, s, Evidence{})
	if err != nil {
		t.Fatal(err)
	}
	now, _ := model.Encode(report)
	if report.Mappings[0].Valid || string(old) == string(now) || report.Mappings[0].ContractUnits[0].Tests[0] != "Test::missing" {
		t.Fatal("invalid test mapping was hidden or credited")
	}
}

func TestBaselineProtectsImplementedOperationAndIgnoresLiveFailures(t *testing.T) {
	m, r, s, _ := baselineFixture(t)
	m.Operations[0].Variants = m.Operations[0].Variants[:1]
	report, err := Compare(m, r, s, Evidence{})
	if err != nil {
		t.Fatal(err)
	}
	baseline := Baseline{SchemaVersion: "1.0", Operations: []string{m.Operations[0].ID}}
	if got := CheckBaseline(report, baseline); len(got) != 0 {
		t.Fatal(got)
	}
	m.Operations[0].Variants = append(m.Operations[0].Variants, model.Variant{ID: "uapi:WebApp/stage#POST", OperationLocator: model.Locator{SourceID: "s", Pointer: stringPointer("/paths/~1WebApp~1stage/post")}})
	report, err = Compare(m, r, s, Evidence{})
	if err != nil {
		t.Fatal(err)
	}
	if got := CheckBaseline(report, baseline); len(got) == 0 {
		t.Fatal("lost complete operation coverage")
	}
	m, r, s, baseline = baselineFixture(t)
	r.Entries[0].LiveEvidenceIDs = []string{"missing"}
	report, err = Compare(m, r, s, Evidence{LiveProvided: true})
	if err != nil {
		t.Fatal(err)
	}
	if report.Valid {
		t.Fatal("fixture must expose live error")
	}
	if got := CheckBaseline(report, baseline); len(got) != 0 {
		t.Fatal("live errors changed offline baseline", got)
	}
}
