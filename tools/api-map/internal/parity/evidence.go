package parity

import (
	"sort"
	"strings"
	"time"
)

type Outcome struct {
	Symbol string `json:"symbol"`
	Status string `json:"status"`
}
type TestRun struct {
	RunID       string    `json:"run_id"`
	Revision    string    `json:"revision"`
	CompletedAt string    `json:"completed_at"`
	Command     string    `json:"command"`
	Outcomes    []Outcome `json:"outcomes"`
}
type TestEvidence struct {
	SchemaVersion string    `json:"schema_version"`
	Runs          []TestRun `json:"runs"`
}
type LiveRecord struct {
	ID             string `json:"id"`
	OperationID    string `json:"operation_id"`
	VariantID      string `json:"variant_id"`
	Revision       string `json:"revision"`
	Host           string `json:"host"`
	Version        string `json:"version"`
	ObservedAt     string `json:"observed_at"`
	Route          string `json:"route"`
	Authentication string `json:"authentication"`
	AccountContext string `json:"account_context"`
	Status         string `json:"status"`
}
type LiveEvidence struct {
	SchemaVersion string       `json:"schema_version"`
	Records       []LiveRecord `json:"records"`
}

// Revision is the independently supplied revision being assessed. Empty means
// unknown: no saved run or live record can prove the current revision passed.
type Evidence struct {
	// LiveProvided distinguishes an explicitly supplied empty artifact from omission.
	// Nonempty Live records also imply supplied evidence for direct Go callers.
	LiveProvided bool         `json:"live_provided"`
	Revision     string       `json:"revision"`
	TestRuns     []TestRun    `json:"test_runs"`
	Live         []LiveRecord `json:"live"`
}

func LoadTestEvidence(b []byte) (TestEvidence, error) {
	var e TestEvidence
	err := load(b, "test-evidence-v1.schema.json", &e)
	return e, err
}
func LoadLiveEvidence(b []byte) (LiveEvidence, error) {
	var e LiveEvidence
	err := load(b, "live-evidence-v1.schema.json", &e)
	return e, err
}
func timestamp(s string) bool { _, e := time.Parse(time.RFC3339, s); return e == nil }

// testOutcomes selects the latest matching completion for each executable test.
// Conflicting equal-time outcomes remain unknown and mismatches remain stale.
func testOutcomes(e Evidence, validTest func(string) bool, diag func(string, string, string)) map[string]string {
	outcomes := map[string]string{}
	latest := map[string]time.Time{}
	runIDs := map[string]bool{}
	for _, run := range e.TestRuns {
		if runIDs[run.RunID] || run.RunID == "" || run.Revision == "" || !timestamp(run.CompletedAt) || strings.TrimSpace(run.Command) == "" {
			diag("invalid_test_run", "Test run metadata is invalid or duplicated.", "")
			continue
		}
		runIDs[run.RunID] = true
		seen := map[string]bool{}
		for _, o := range run.Outcomes {
			if seen[o.Symbol] || !validTest(o.Symbol) || (o.Status != "passed" && o.Status != "failed" && o.Status != "skipped") {
				diag("invalid_test_outcome", "Test outcome must identify one declared executable test and a valid status.", "")
				continue
			}
			seen[o.Symbol] = true
			if e.Revision == "" || run.Revision != e.Revision {
				if outcomes[o.Symbol] == "" {
					outcomes[o.Symbol] = "stale"
				}
				continue
			}
			at, _ := time.Parse(time.RFC3339, run.CompletedAt)
			if at.After(latest[o.Symbol]) {
				latest[o.Symbol] = at
				outcomes[o.Symbol] = o.Status
			} else if at.Equal(latest[o.Symbol]) && outcomes[o.Symbol] != o.Status {
				outcomes[o.Symbol] = "unknown"
			}
		}
	}
	return outcomes
}

func sortedEvidence(e Evidence) Evidence {
	e.LiveProvided = e.LiveProvided || len(e.Live) > 0
	e.TestRuns = append([]TestRun{}, e.TestRuns...)
	e.Live = append([]LiveRecord{}, e.Live...)
	for i := range e.TestRuns {
		e.TestRuns[i].Outcomes = append([]Outcome{}, e.TestRuns[i].Outcomes...)
		sort.Slice(e.TestRuns[i].Outcomes, func(a, b int) bool { return e.TestRuns[i].Outcomes[a].Symbol < e.TestRuns[i].Outcomes[b].Symbol })
	}
	sort.Slice(e.TestRuns, func(i, j int) bool { return e.TestRuns[i].RunID < e.TestRuns[j].RunID })
	sort.Slice(e.Live, func(i, j int) bool { return e.Live[i].ID < e.Live[j].ID })
	return e
}
