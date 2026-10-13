package parity

import (
	"fmt"
	"sort"
	"strings"
)

type ProtectedMapping struct {
	OperationID   string          `json:"operation_id"`
	VariantID     string          `json:"variant_id"`
	Symbol        string          `json:"symbol"`
	Status        string          `json:"status"`
	Tests         []string        `json:"tests"`
	ContractUnits []ContractClaim `json:"contract_units"`
}
type MappingFact struct {
	ProtectedMapping
	Valid bool `json:"valid"`
}
type Baseline struct {
	SchemaVersion string             `json:"schema_version"`
	Operations    []string           `json:"operations"`
	Mappings      []ProtectedMapping `json:"mappings"`
}
type Violation struct {
	Code    string `json:"code"`
	ID      string `json:"id"`
	Message string `json:"message"`
}

// CheckBaseline protects identities and exact declaration links, never percentages
// or live outcomes. Only validated current mappings can satisfy a protected claim.
func CheckBaseline(current Report, baseline Baseline) []Violation {
	violations := []Violation{}
	add := func(code, id, message string) { violations = append(violations, Violation{code, id, message}) }
	operations := map[string]OperationResult{}
	for _, op := range current.Operations {
		operations[op.ID] = op
	}
	for _, id := range baseline.Operations {
		if operations[id].State != "implemented" {
			add("operation_regression", id, "Protected implemented operation is missing, partial, or excluded.")
		}
	}
	for _, want := range baseline.Mappings {
		var found *MappingFact
		for i := range current.Mappings {
			got := &current.Mappings[i]
			if got.Valid && got.OperationID == want.OperationID && got.VariantID == want.VariantID && got.Symbol == want.Symbol {
				found = got
				break
			}
		}
		if found == nil {
			add("mapping_regression", want.VariantID, "Protected PHP mapping is missing or invalid: "+want.Symbol)
			continue
		}
		if want.Status == "implemented" && found.Status != "implemented" {
			add("status_regression", want.VariantID, "Protected mapping implementation status weakened.")
		}
		contains := func(haystack []string, needle string) bool {
			for _, x := range haystack {
				if x == needle {
					return true
				}
			}
			return false
		}
		for _, test := range want.Tests {
			if !contains(found.Tests, test) {
				add("test_mapping_regression", want.VariantID, "Protected mapping lost test: "+test)
			}
		}
		for _, claim := range want.ContractUnits {
			var actual *ContractClaim
			for i := range found.ContractUnits {
				if found.ContractUnits[i].ID == claim.ID {
					actual = &found.ContractUnits[i]
					break
				}
			}
			if actual == nil || actual.Hash != claim.Hash {
				add("contract_regression", want.VariantID, "Protected contract claim is missing or changed; review required: "+claim.ID)
				continue
			}
			for _, test := range claim.Tests {
				if !contains(actual.Tests, test) {
					add("contract_test_regression", want.VariantID, "Protected contract lost test mapping: "+claim.ID+" / "+test)
				}
			}
		}
	}
	sort.Slice(violations, func(i, j int) bool {
		a, b := violations[i], violations[j]
		return a.ID+a.Code+a.Message < b.ID+b.Code+b.Message
	})
	return violations
}

func LoadBaseline(data []byte) (Baseline, error) {
	var b Baseline
	if err := load(data, "coverage-baseline-v1.schema.json", &b); err != nil {
		return b, err
	}
	seen := map[string]bool{}
	for _, m := range b.Mappings {
		key := m.OperationID + "\x00" + m.VariantID + "\x00" + m.Symbol
		if seen[key] || !strings.HasPrefix(m.VariantID, m.OperationID+"#") {
			return b, fmt.Errorf("duplicate or inconsistent protected mapping")
		}
		seen[key] = true
		claims := map[string]bool{}
		for _, claim := range m.ContractUnits {
			if claims[claim.ID] {
				return b, fmt.Errorf("duplicate protected contract")
			}
			claims[claim.ID] = true
		}
	}
	return b, nil
}
