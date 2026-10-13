package parity

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"testing"
)

func reportFixture() (model.Map, Registry, Symbols) {
	p := "/paths/~1WebApp~1stage/get"
	q := "/paths/~1WebApp~1stage/post"
	m := model.Map{Documents: map[string]model.Object{"s": {"paths": model.Object{"/WebApp/stage": model.Object{"get": model.Object{}, "post": model.Object{}}}}}, Operations: []model.Operation{{ID: "uapi:WebApp/stage", Family: "uapi", Module: stringPointer("WebApp"), Variants: []model.Variant{{ID: "uapi:WebApp/stage#GET", OperationLocator: model.Locator{SourceID: "s", SourceSHA256: "h", Pointer: &p}}, {ID: "uapi:WebApp/stage#POST", OperationLocator: model.Locator{SourceID: "s", SourceSHA256: "h", Pointer: &q}}}}}}
	r := Registry{SchemaVersion: "1.0", Entries: []Entry{{OperationID: m.Operations[0].ID, VariantID: m.Operations[0].Variants[0].ID, Symbol: "Client::stage", Status: "implemented", Tests: []string{"Test::stage"}}}}
	s := Symbols{SchemaVersion: "1.0", Symbols: []Symbol{{ID: "Client::stage", Kind: "method", DeclaringClass: "Client", Public: true, File: "src/Client.php", Line: 1}, {ID: "Test::stage", Kind: "test", DeclaringClass: "Test", Public: true, File: "tests/Test.php", Line: 1}}}
	return m, r, s
}
func TestReportKeepsDimensionsIndependent(t *testing.T) {
	m, r, s := reportFixture()
	report, e := Compare(m, r, s, Evidence{})
	if e != nil {
		t.Fatal(e)
	}
	if report.Summary.Functions.Numerator != 0 || report.Summary.Variants.Numerator != 1 || report.Summary.Variants.Denominator != 2 || report.Summary.DeclaredTests.Numerator != 1 || report.Summary.PassingTests.Numerator != 0 || report.Operations[0].State != "partial" {
		t.Fatalf("%+v %+v", report.Summary, report.Operations)
	}
	r.Entries = nil
	report, e = Compare(m, r, s, Evidence{})
	if e != nil || report.Summary.Variants.Numerator != 0 {
		t.Fatal(report, e)
	}
}
func TestAliasResolutionPreservesDenominator(t *testing.T) {
	m, r, s := reportFixture()
	m.Aliases = []model.Alias{{AliasID: "uapi:Old/stage", TargetID: m.Operations[0].ID, Reason: "rename", Evidence: []model.Locator{m.Operations[0].Variants[0].OperationLocator}}}
	r.Entries[0].OperationID = "uapi:Old/stage"
	r.Entries[0].VariantID = "uapi:Old/stage#GET"
	report, e := Compare(m, r, s, Evidence{})
	if e != nil || !report.Valid || report.Summary.Functions.Gross != 1 || report.Summary.Variants.Numerator != 1 {
		t.Fatalf("%+v %v", report, e)
	}
}
func TestRegistryClaimsCannotInflateCoverage(t *testing.T) {
	m, r, s := reportFixture()
	r.Entries = append(r.Entries, r.Entries[0])
	report, e := Compare(m, r, s, Evidence{})
	if e != nil || report.Valid || report.Summary.Variants.Numerator > 1 || report.Summary.Functions.Numerator != 0 {
		t.Fatalf("%+v %v", report, e)
	}
	r.Entries = nil
	r.Exclusions = []Exclusion{{OperationID: m.Operations[0].ID, VariantID: m.Operations[0].Variants[1].ID, Reason: "documented", Evidence: []model.Locator{m.Operations[0].Variants[1].OperationLocator}}}
	report, e = Compare(m, r, s, Evidence{})
	if e != nil || report.Summary.Variants.Gross != 2 || report.Summary.Variants.Excluded != 1 || report.Summary.Variants.Denominator != 1 {
		t.Fatalf("%+v %v", report, e)
	}
}
func TestTestEvidenceRevisionAndLiveRoutes(t *testing.T) {
	m, r, s := reportFixture()
	r.Entries[0].LiveEvidenceIDs = []string{"direct", "bridge"}
	ev := Evidence{Revision: "abc", TestRuns: []TestRun{{RunID: "r", Revision: "abc", CompletedAt: "2026-10-13T00:00:00Z", Command: "composer test", Outcomes: []Outcome{{Symbol: "Test::stage", Status: "passed"}}}}, Live: []LiveRecord{{ID: "direct", OperationID: m.Operations[0].ID, VariantID: m.Operations[0].Variants[0].ID, Revision: "abc", Host: "example.test", Version: "11.138", ObservedAt: "2026-10-13T00:00:00Z", Route: "direct-uapi", Authentication: "cpanel-token", AccountContext: "account", Status: "verified"}, {ID: "bridge", OperationID: m.Operations[0].ID, VariantID: m.Operations[0].Variants[0].ID, Revision: "abc", Host: "example.test", Version: "11.138", ObservedAt: "2026-10-13T00:00:00Z", Route: "whm-bridge", Authentication: "whm-token", AccountContext: "account", Status: "verified"}}}
	report, e := Compare(m, r, s, ev)
	if e != nil || report.Summary.PassingTests.Numerator != 1 || len(report.LiveRoutes) != 2 {
		t.Fatalf("%+v %v", report, e)
	}
	ev.Revision = "def"
	report, e = Compare(m, r, s, ev)
	if e != nil || report.Summary.PassingTests.Numerator != 0 || report.Summary.LiveVerified.Numerator != 0 {
		t.Fatalf("stale %+v %v", report, e)
	}
}

func TestUnknownAndStaleClaimsAreInvalid(t *testing.T) {
	for _, which := range []string{"symbol", "operation", "claim", "stale"} {
		t.Run(which, func(t *testing.T) {
			m, r, s := reportFixture()
			switch which {
			case "symbol":
				r.Entries[0].Symbol = "Unknown::method"
			case "operation":
				r.Entries[0].OperationID = "uapi:Unknown/f"
			case "claim":
				r.Entries[0].ContractUnits = []ContractClaim{{ID: "unknown", Hash: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", Tests: []string{"Test::stage"}}}
			case "stale":
				p := model.Object{"name": "x", "in": "query", "schema": model.Object{"type": "string"}}
				op := m.Documents["s"]["paths"].(model.Object)["/WebApp/stage"].(model.Object)["get"].(model.Object)
				op["parameters"] = []any{p}
				v := &m.Operations[0].Variants[0]
				v.Parameters = []model.Parameter{{Name: "x", In: "query", Definition: p, Locator: model.Locator{SourceID: "s", Pointer: stringPointer(*v.OperationLocator.Pointer + "/parameters/0")}}}
				r.Entries[0].ContractUnits = []ContractClaim{{ID: `["uapi:WebApp/stage#GET","parameter","x","query"]`, Hash: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", Tests: []string{"Test::stage"}}}
			}
			report, e := Compare(m, r, s, Evidence{})
			if e != nil || report.Valid {
				t.Fatalf("valid invalid mapping %+v %v", report, e)
			}
		})
	}
}
func TestLatestOutcomesAreDeterministic(t *testing.T) {
	m, r, s := reportFixture()
	r.Entries[0].Tests = append(r.Entries[0].Tests, "Test::other")
	s.Symbols = append(s.Symbols, Symbol{ID: "Test::other", Kind: "test", DeclaringClass: "Test", Public: true, File: "tests/Test.php", Line: 2})
	e := Evidence{Revision: "r", TestRuns: []TestRun{{RunID: "old", Revision: "r", Command: "test", CompletedAt: "2026-10-13T01:00:00Z", Outcomes: []Outcome{{Symbol: "Test::stage", Status: "passed"}, {Symbol: "Test::other", Status: "failed"}}}, {RunID: "new", Revision: "r", Command: "test", CompletedAt: "2026-10-13T02:00:00Z", Outcomes: []Outcome{{Symbol: "Test::stage", Status: "skipped"}}}}}
	for i := 0; i < 30; i++ {
		report, err := Compare(m, r, s, e)
		if err != nil {
			t.Fatal(err)
		}
		if got := report.Operations[0].Variants[0].TestStatus; got != "failed" {
			t.Fatalf("failed outcome must dominate skipped, got %s", got)
		}
	}
}

func TestStaleOutcomesRemainVisible(t *testing.T) {
	m, r, s := reportFixture()
	e := Evidence{Revision: "new", TestRuns: []TestRun{{RunID: "old-run", Revision: "old", CompletedAt: "2026-10-13T00:00:00Z", Command: "test", Outcomes: []Outcome{{Symbol: "Test::stage", Status: "passed"}}}}}
	report, err := Compare(m, r, s, e)
	if err != nil {
		t.Fatal(err)
	}
	if report.Operations[0].Variants[0].TestStatus != "stale" {
		t.Fatal("stale run not distinguished from absence")
	}
}

func TestReportRetainsEvidenceMetadata(t *testing.T) {
	m, r, s := reportFixture()
	m.Sources = []model.Object{{"id": "s", "file": "s.json", "url": "https://example.test/s.json", "sha256": "h"}}
	e := Evidence{Revision: "r", TestRuns: []TestRun{{RunID: "run", Revision: "r", CompletedAt: "2026-10-13T00:00:00Z", Command: "test", Outcomes: []Outcome{{Symbol: "Test::stage", Status: "passed"}}}}}
	report, err := Compare(m, r, s, e)
	if err != nil {
		t.Fatal(err)
	}
	if len(report.Sources) != 1 || len(report.Evidence.TestRuns) != 1 || report.Evidence.TestRuns[0].RunID != "run" {
		t.Fatal("evidence provenance omitted")
	}
}

func TestDuplicateCanonicalIDsCannotInflateDenominator(t *testing.T) {
	m, r, s := reportFixture()
	m.Operations = append(m.Operations, m.Operations[0])
	if _, err := Compare(m, r, s, Evidence{}); err == nil {
		t.Fatal("duplicate canonical IDs accepted")
	}
}
