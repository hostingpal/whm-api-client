package parity

import (
	"fmt"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/contract"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
	"sort"
	"strings"
)

type Count struct {
	Numerator   int `json:"numerator"`
	Denominator int `json:"denominator"`
	Gross       int `json:"gross"`
	Excluded    int `json:"excluded"`
}
type Summary struct {
	Functions              Count `json:"functions"`
	Variants               Count `json:"variants"`
	Parameters             Count `json:"parameters"`
	Bodies                 Count `json:"bodies"`
	Responses              Count `json:"responses"`
	DeclaredTests          Count `json:"declared_tests"`
	PassingTests           Count `json:"passing_tests"`
	LiveVerified           Count `json:"live_verified"`
	MissingResponseSchemas int   `json:"missing_response_schemas"`
}
type VariantResult struct {
	MissingResponseSchemas int             `json:"missing_response_schemas"`
	ID                     string          `json:"id"`
	State                  string          `json:"state"`
	Units                  []contract.Unit `json:"units"`
	ClaimedUnits           []string        `json:"claimed_units"`
	Tests                  []string        `json:"tests"`
	TestStatus             string          `json:"test_status"`
	LiveEvidenceIDs        []string        `json:"live_evidence_ids"`
}
type OperationResult struct {
	ID       string          `json:"id"`
	Family   string          `json:"family"`
	Module   *string         `json:"module"`
	Tags     []string        `json:"tags"`
	State    string          `json:"state"`
	Variants []VariantResult `json:"variants"`
}
type Group struct {
	Family      string  `json:"family"`
	Name        string  `json:"name"`
	NonAdditive bool    `json:"non_additive"`
	Summary     Summary `json:"summary"`
}
type RouteCount struct {
	Route          string `json:"route"`
	Authentication string `json:"authentication"`
	Coverage       Count  `json:"coverage"`
}
type Report struct {
	Mappings      []MappingFact      `json:"mappings"`
	Sources       []model.Object     `json:"sources"`
	Evidence      Evidence           `json:"evidence"`
	SchemaVersion string             `json:"schema_version"`
	Valid         bool               `json:"valid"`
	Revision      string             `json:"revision"`
	Summary       Summary            `json:"summary"`
	Operations    []OperationResult  `json:"operations"`
	Groups        []Group            `json:"groups"`
	LiveRoutes    []RouteCount       `json:"live_routes"`
	Diagnostics   []model.Diagnostic `json:"diagnostics"`
}

func canonicalRegistry(m model.Map, r Registry) Registry {
	aliases := map[string]string{}
	for _, a := range m.Aliases {
		aliases[a.AliasID] = a.TargetID
	}
	resolve := func(id string) string {
		seen := map[string]bool{}
		for aliases[id] != "" && !seen[id] {
			seen[id] = true
			id = aliases[id]
		}
		return id
	}
	r.Entries = append([]Entry(nil), r.Entries...)
	for i := range r.Entries {
		e := &r.Entries[i]
		old := e.OperationID
		e.OperationID = resolve(old)
		if strings.HasPrefix(e.VariantID, old+"#") {
			e.VariantID = e.OperationID + strings.TrimPrefix(e.VariantID, old)
		}
		e.VariantID = resolve(e.VariantID)
	}
	r.Exclusions = append([]Exclusion(nil), r.Exclusions...)
	for i := range r.Exclusions {
		e := &r.Exclusions[i]
		old := e.OperationID
		e.OperationID = resolve(old)
		if strings.HasPrefix(e.VariantID, old+"#") {
			e.VariantID = e.OperationID + strings.TrimPrefix(e.VariantID, old)
		}
		e.VariantID = resolve(e.VariantID)
	}
	return r
}
func Compare(m model.Map, r Registry, s Symbols, e Evidence) (Report, error) {
	seenOperations, seenVariants := map[string]bool{}, map[string]bool{}
	for _, op := range m.Operations {
		if seenOperations[op.ID] {
			return Report{}, fmt.Errorf("duplicate canonical operation ID")
		}
		seenOperations[op.ID] = true
		for _, v := range op.Variants {
			if seenVariants[v.ID] {
				return Report{}, fmt.Errorf("duplicate canonical variant ID")
			}
			seenVariants[v.ID] = true
		}
	}

	r.originSHA256 = artifactHash(r, r.originSHA256)
	r = canonicalRegistry(m, r)
	report := Report{Sources: append([]model.Object{}, m.Sources...), Evidence: sortedEvidence(e), SchemaVersion: "1.0", Valid: true, Revision: e.Revision, Operations: []OperationResult{}, Groups: []Group{}, LiveRoutes: []RouteCount{}, Diagnostics: ValidateRegistry(m, r, s)}
	diag := func(code, message, id string) {
		report.Diagnostics = append(report.Diagnostics, model.Diagnostic{Severity: "error", Code: code, Message: message, CanonicalID: id, Locator: model.Locator{SourceID: "coverage-registry", SourceSHA256: r.originSHA256, Pointer: stringPointer("")}})
	}
	symbols := map[string]Symbol{}
	for _, x := range s.Symbols {
		symbols[x.ID] = x
	}
	validTest := func(id string) bool {
		x := symbols[id]
		return x.Kind == "test" && x.Public && !x.Abstract && !x.Static && projectFile(x.File, "tests/")
	}
	excluded := map[string]bool{}
	for _, x := range r.Exclusions {
		if x.VariantID != "" {
			excluded[x.VariantID] = true
		} else {
			excluded[x.OperationID] = true
		}
	}
	entries := map[string][]Entry{}
	for _, x := range r.Entries {
		entries[x.VariantID] = append(entries[x.VariantID], x)
	}
	outcomes := testOutcomes(e, validTest, diag)
	live := map[string]LiveRecord{}
	duplicates := map[string]bool{}
	for _, l := range e.Live {
		if _, ok := live[l.ID]; ok {
			duplicates[l.ID] = true
			diag("duplicate_live_evidence", "Repeated live evidence ID.", l.VariantID)
		}
		live[l.ID] = l
	}
	routes := map[string]map[string]bool{}
	for _, op := range m.Operations {
		or := OperationResult{ID: op.ID, Family: op.Family, Module: op.Module, Tags: []string{}, Variants: []VariantResult{}}
		tagset := map[string]bool{}
		for _, v := range op.Variants {
			units, gaps, err := contract.AnalyzeUnits(m, v)
			if err != nil {
				return report, fmt.Errorf("contract units for %s: %w", v.ID, err)
			}
			report.Diagnostics = append(report.Diagnostics, gaps...)
			vr := VariantResult{MissingResponseSchemas: len(gaps), ID: v.ID, State: "missing", Units: units, ClaimedUnits: []string{}, Tests: []string{}, TestStatus: "not-run", LiveEvidenceIDs: []string{}}
			unitByID := map[string]contract.Unit{}
			for _, u := range units {
				unitByID[u.ID] = u
			}
			claims := map[string]bool{}
			tests := map[string]bool{}
			verified := map[string]bool{}
			mapped := false
			complete := true
			for _, entry := range entries[v.ID] {
				symbol := symbols[entry.Symbol]
				if entry.OperationID != op.ID || !symbol.Public || symbol.Abstract || symbol.Kind != "method" || !projectFile(symbol.File, "src/") {
					continue
				}
				isInfra := false
				for _, inf := range r.Infrastructure {
					if inf.Symbol == entry.Symbol {
						isInfra = true
					}
				}
				if isInfra {
					continue
				}
				mapped = true
				if entry.Status != "implemented" {
					complete = false
				}
				for _, t := range entry.Tests {
					if validTest(t) {
						tests[t] = true
					}
				}
				for _, claim := range entry.ContractUnits {
					u, ok := unitByID[claim.ID]
					if !ok {
						diag("unknown_contract_unit", "Claim does not identify a contract unit in this variant.", v.ID)
						complete = false
						continue
					}
					if claim.Hash != u.Hash {
						diag("stale_contract_claim", "Contract hash has changed; declaration requires review.", v.ID)
						complete = false
						continue
					}
					valid := len(claim.Tests) > 0
					for _, t := range claim.Tests {
						if !validTest(t) {
							valid = false
						} else {
							tests[t] = true
						}
					}
					if valid {
						claims[u.ID] = true
					}
				}
				for _, id := range entry.LiveEvidenceIDs {
					l, ok := live[id]
					if !ok {
						if e.LiveProvided || len(e.Live) > 0 {
							diag("unknown_live_evidence", "Declared live evidence is unavailable in the supplied artifact.", v.ID)
						}
						continue
					}
					routeOK := (op.Family == "uapi" && (l.Route == "direct-uapi" && l.Authentication == "cpanel-token" || l.Route == "whm-bridge" && l.Authentication == "whm-token")) || (op.Family == "whm1" && l.Route == "direct-whm" && l.Authentication == "whm-token")
					if duplicates[id] || l.OperationID != op.ID || l.VariantID != v.ID || l.Host == "" || l.Version == "" || !timestamp(l.ObservedAt) || l.AccountContext == "" || !routeOK || (l.Status != "verified" && l.Status != "failed" && l.Status != "unverified") {
						diag("invalid_live_evidence", "Live evidence identity, context, route, or authentication is invalid.", v.ID)
						continue
					}
					if l.Status == "verified" && e.Revision != "" && l.Revision == e.Revision {
						verified[id] = true
						key := l.Route + "\x00" + l.Authentication
						if routes[key] == nil {
							routes[key] = map[string]bool{}
						}
						routes[key][v.ID] = true
					}
				}
			}
			for id := range claims {
				vr.ClaimedUnits = append(vr.ClaimedUnits, id)
			}
			sort.Strings(vr.ClaimedUnits)
			for id := range tests {
				vr.Tests = append(vr.Tests, id)
			}
			sort.Strings(vr.Tests)
			for id := range verified {
				vr.LiveEvidenceIDs = append(vr.LiveEvidenceIDs, id)
			}
			sort.Strings(vr.LiveEvidenceIDs)
			if len(tests) > 0 {
				vr.TestStatus = "passed"
				rank := map[string]int{"passed": 0, "skipped": 1, "not-run": 2, "stale": 3, "unknown": 4, "failed": 5}
				for _, test := range vr.Tests {
					status := outcomes[test]
					if status == "" {
						status = "not-run"
					}
					if rank[status] > rank[vr.TestStatus] {
						vr.TestStatus = status
					}
				}
			}
			if excluded[op.ID] || excluded[v.ID] {
				vr.State = "excluded"
				vr.ClaimedUnits = []string{}
				vr.Tests = []string{}
				vr.LiveEvidenceIDs = []string{}
				vr.TestStatus = "not-run"
			} else if mapped {
				vr.State = "partial"
				if complete && len(claims) == len(units) && len(gaps) == 0 {
					vr.State = "implemented"
				}
			}
			if v.OperationLocator.Pointer != nil {
				raw, _ := source.Pointer(m.Documents[v.OperationLocator.SourceID], *v.OperationLocator.Pointer)
				obj := asObject(raw)
				if tags, ok := obj["tags"].([]any); ok {
					for _, tag := range tags {
						if value, ok := tag.(string); ok {
							tagset[value] = true
						}
					}
				}
			}
			or.Variants = append(or.Variants, vr)
		}
		for tag := range tagset {
			or.Tags = append(or.Tags, tag)
		}
		sort.Strings(or.Tags)
		sort.Slice(or.Variants, func(i, j int) bool { return or.Variants[i].ID < or.Variants[j].ID })
		included, done, present := 0, 0, 0
		for _, v := range or.Variants {
			if v.State == "excluded" {
				continue
			}
			included++
			if v.State == "implemented" {
				done++
			}
			if v.State != "missing" {
				present++
			}
		}
		or.State = "missing"
		if included == 0 {
			or.State = "excluded"
		} else if done == included {
			or.State = "implemented"
		} else if present > 0 {
			or.State = "partial"
		}
		report.Operations = append(report.Operations, or)
	}
	sort.Slice(report.Operations, func(i, j int) bool { return report.Operations[i].ID < report.Operations[j].ID })
	report.Mappings = mappingFacts(r, ValidateRegistry(m, r, s), report.Diagnostics)
	report.Summary = summarize(report.Operations)
	groups := map[string][]OperationResult{}
	for _, op := range report.Operations {
		groups[op.Family+"\x00"] = append(groups[op.Family+"\x00"], op)
		if op.Module != nil {
			key := op.Family + "\x00" + *op.Module
			groups[key] = append(groups[key], op)
		} else {
			for _, tag := range op.Tags {
				key := op.Family + "\x00" + tag
				groups[key] = append(groups[key], op)
			}
		}
	}
	for key, ops := range groups {
		parts := strings.Split(key, "\x00")
		report.Groups = append(report.Groups, Group{Family: parts[0], Name: parts[1], NonAdditive: parts[0] == "whm1" && parts[1] != "", Summary: summarize(ops)})
	}
	sort.Slice(report.Groups, func(i, j int) bool {
		return report.Groups[i].Family+"\x00"+report.Groups[i].Name < report.Groups[j].Family+"\x00"+report.Groups[j].Name
	})
	for key, ids := range routes {
		parts := strings.Split(key, "\x00")
		coverage := Count{}
		for _, op := range report.Operations {
			eligible := op.Family == "uapi" && parts[0] != "direct-whm" || op.Family == "whm1" && parts[0] == "direct-whm"
			if eligible {
				for _, v := range op.Variants {
					tally(&coverage, v.State == "excluded", ids[v.ID])
				}
			}
		}
		report.LiveRoutes = append(report.LiveRoutes, RouteCount{parts[0], parts[1], coverage})
	}
	sort.Slice(report.LiveRoutes, func(i, j int) bool {
		return report.LiveRoutes[i].Route+report.LiveRoutes[i].Authentication < report.LiveRoutes[j].Route+report.LiveRoutes[j].Authentication
	})
	for _, d := range report.Diagnostics {
		if d.Severity == "error" {
			report.Valid = false
		}
	}
	sort.SliceStable(report.Diagnostics, func(i, j int) bool {
		a, b := report.Diagnostics[i], report.Diagnostics[j]
		return a.CanonicalID+a.Code+a.Message < b.CanonicalID+b.Code+b.Message
	})
	return report, nil
}
func asObject(v any) model.Object {
	switch x := v.(type) {
	case model.Object:
		return x
	case map[string]any:
		return model.Object(x)
	}
	return nil
}
func tally(c *Count, excluded, covered bool) {
	c.Gross++
	if excluded {
		c.Excluded++
	} else {
		c.Denominator++
		if covered {
			c.Numerator++
		}
	}
}
func summarize(ops []OperationResult) Summary {
	s := Summary{}
	for _, op := range ops {
		tally(&s.Functions, op.State == "excluded", op.State == "implemented")
		for _, v := range op.Variants {
			s.MissingResponseSchemas += v.MissingResponseSchemas
			x := v.State == "excluded"
			tally(&s.Variants, x, v.State == "implemented")
			tally(&s.DeclaredTests, x, len(v.Tests) > 0)
			tally(&s.PassingTests, x, v.TestStatus == "passed")
			tally(&s.LiveVerified, x, len(v.LiveEvidenceIDs) > 0)
			claims := map[string]bool{}
			for _, id := range v.ClaimedUnits {
				claims[id] = true
			}
			for _, u := range v.Units {
				switch u.Kind {
				case "parameter":
					tally(&s.Parameters, x, claims[u.ID])
				case "body":
					tally(&s.Bodies, x, claims[u.ID])
				case "response":
					tally(&s.Responses, x, claims[u.ID])
				}
			}
		}
	}
	return s
}
