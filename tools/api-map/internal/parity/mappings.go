package parity

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"sort"
	"strings"
)

// Retain invalid/stale declarations for review, but never label them validated.
// Evidence outcomes and live artifact presence do not affect these offline facts.
func mappingFacts(r Registry, structural, diagnostics []model.Diagnostic) []MappingFact {
	result := []MappingFact{}
	for _, e := range r.Entries {
		fact := MappingFact{ProtectedMapping: ProtectedMapping{OperationID: e.OperationID, VariantID: e.VariantID, Symbol: e.Symbol, Status: e.Status, Tests: append([]string{}, e.Tests...), ContractUnits: append([]ContractClaim{}, e.ContractUnits...)}, Valid: true}
		for _, d := range structural {
			if d.Severity == "error" && !strings.Contains(d.Code, "live_evidence") && (d.CanonicalID == "" || d.CanonicalID == e.OperationID || d.CanonicalID == e.VariantID) {
				fact.Valid = false
			}
		}
		for _, d := range diagnostics {
			if (d.Code == "unknown_contract_unit" || d.Code == "stale_contract_claim") && d.CanonicalID == e.VariantID {
				fact.Valid = false
			}
		}
		sort.Strings(fact.Tests)
		for i := range fact.ContractUnits {
			fact.ContractUnits[i].Tests = append([]string{}, fact.ContractUnits[i].Tests...)
			sort.Strings(fact.ContractUnits[i].Tests)
		}
		sort.Slice(fact.ContractUnits, func(i, j int) bool { return fact.ContractUnits[i].ID < fact.ContractUnits[j].ID })
		result = append(result, fact)
	}
	sort.SliceStable(result, func(i, j int) bool {
		a, b := result[i], result[j]
		ar, _ := model.Encode(a)
		br, _ := model.Encode(b)
		return string(ar) < string(br)
	})
	return result
}
