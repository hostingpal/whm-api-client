package parity

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"testing"
)

func TestOptionalLiveEvidenceOmissionIsUnverified(t *testing.T) {
	m, r, s := reportFixture()
	r.Entries[0].LiveEvidenceIDs = []string{"saved-live-run"}
	report, err := Compare(m, r, s, Evidence{})
	if err != nil {
		t.Fatal(err)
	}
	if !report.Valid {
		t.Fatalf("omitted optional live evidence invalidated registry: %+v", report.Diagnostics)
	}
	if report.Summary.LiveVerified.Numerator != 0 || len(report.Operations[0].Variants[0].LiveEvidenceIDs) != 0 {
		t.Fatal("omitted evidence received verification credit")
	}
}
func TestSuppliedLiveEvidenceRequiresDeclaredID(t *testing.T) {
	m, r, s := reportFixture()
	r.Entries[0].LiveEvidenceIDs = []string{"saved-live-run"}
	report, err := Compare(m, r, s, Evidence{LiveProvided: true, Live: []LiveRecord{}})
	if err != nil {
		t.Fatal(err)
	}
	if report.Valid {
		t.Fatal("supplied empty evidence did not report dangling ID")
	}
}
func TestGroupMissingSchemaTotals(t *testing.T) {
	for _, family := range []string{"uapi", "whm1"} {
		t.Run(family, func(t *testing.T) {
			m, r, s := reportFixture()
			m.Operations[0].Family = family
			if family == "whm1" {
				m.Operations[0].Module = nil
			}
			op := m.Documents["s"]["paths"].(model.Object)["/WebApp/stage"].(model.Object)["get"].(model.Object)
			op["tags"] = []any{"TagA", "TagB"}
			op["responses"] = model.Object{"204": model.Object{"description": "empty"}}
			report, err := Compare(m, r, s, Evidence{})
			if err != nil {
				t.Fatal(err)
			}
			if report.Summary.MissingResponseSchemas != 1 {
				t.Fatal("global missing-schema total wrong")
			}
			for _, g := range report.Groups {
				if g.Summary.MissingResponseSchemas != 1 {
					t.Fatalf("%s/%s missing-schema total=%d want 1", g.Family, g.Name, g.Summary.MissingResponseSchemas)
				}
			}
		})
	}
}
func TestLiveRouteGrossExcludedNetTotals(t *testing.T) {
	m, r, s := reportFixture()
	r.Entries[0].LiveEvidenceIDs = []string{"direct"}
	r.Exclusions = []Exclusion{{OperationID: m.Operations[0].ID, VariantID: m.Operations[0].Variants[1].ID, Reason: "documented", Evidence: []model.Locator{m.Operations[0].Variants[1].OperationLocator}}}
	ev := Evidence{Revision: "r", Live: []LiveRecord{{ID: "direct", OperationID: m.Operations[0].ID, VariantID: m.Operations[0].Variants[0].ID, Revision: "r", Host: "example.test", Version: "11.138", ObservedAt: "2026-10-13T00:00:00Z", Route: "direct-uapi", Authentication: "cpanel-token", AccountContext: "account", Status: "verified"}}}
	report, err := Compare(m, r, s, ev)
	if err != nil || !report.Valid {
		t.Fatalf("invalid report: %v %+v", err, report.Diagnostics)
	}
	if len(report.LiveRoutes) != 1 {
		t.Fatalf("routes=%v", report.LiveRoutes)
	}
	got := report.LiveRoutes[0].Coverage
	if got != (Count{Numerator: 1, Denominator: 1, Gross: 2, Excluded: 1}) {
		t.Fatalf("route count=%+v want gross 2 excluded 1 net 1 verified 1", got)
	}
	ev.Live[0].Route = "invalid"
	report, err = Compare(m, r, s, ev)
	if err != nil || report.Valid {
		t.Fatalf("invalid supplied route accepted: %v", err)
	}
}
