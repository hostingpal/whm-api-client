package parity

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"strings"
	"testing"
)

func TestLoadedExclusionEvidenceResolvesRetainedDocument(t *testing.T) {
	for _, tc := range []struct {
		name, pointer         string
		missing, prose, valid bool
	}{
		{name: "existing null", pointer: "/a~1b/~0key/1", valid: true},
		{name: "root", pointer: "", valid: true},
		{name: "dangling", pointer: "/not-an-existing-source-location"},
		{name: "invalid escape", pointer: "/a~2b"},
		{name: "noncanonical index", pointer: "/a~1b/~0key/00"},
		{name: "missing document", pointer: "", missing: true},
		{name: "prose boundary", pointer: "#reviewed-section", prose: true, missing: true, valid: true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m, _, s := reportFixture()
			loc := model.Locator{SourceID: "evidence", SourceSHA256: strings.Repeat("a", 64), Pointer: &tc.pointer}
			if tc.prose {
				loc.Pointer = nil
				loc.ProseLocator = &tc.pointer
			}
			m.Operations[0].Provenance = []model.Locator{loc}
			if !tc.missing {
				m.Documents["evidence"] = model.Object{"a/b": model.Object{"~key": []any{"first", nil}}}
			}
			r := Registry{SchemaVersion: "1.0", Entries: []Entry{}, Infrastructure: []Infrastructure{{Symbol: "Client::stage", Reason: "Transport."}}, Exclusions: []Exclusion{{OperationID: m.Operations[0].ID, Reason: "Reviewed exclusion.", Evidence: []model.Locator{loc}}}}
			raw, err := model.Encode(r)
			if err != nil {
				t.Fatal(err)
			}
			loaded, err := LoadRegistry(raw)
			if err != nil {
				if tc.name == "invalid escape" {
					return
				}
				t.Fatal(err)
			}
			report, err := Compare(m, loaded, s, Evidence{})
			if err != nil {
				t.Fatal(err)
			}
			if report.Valid != tc.valid {
				t.Fatalf("valid=%t want=%t; coverage=%+v diagnostics=%+v", report.Valid, tc.valid, report.Summary.Functions, report.Diagnostics)
			}
			if !tc.valid && !hasCode(report.Diagnostics, "invalid_exclusion_evidence") {
				t.Fatal("invalid exclusion missing diagnostic")
			}
			if tc.valid && report.Summary.Functions.Excluded != 1 {
				t.Fatal("valid documented exclusion lost")
			}
		})
	}
}
