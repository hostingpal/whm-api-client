package parity

import (
	"crypto/sha256"
	"fmt"
	"path"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

type Registry struct {
	originSHA256   string
	SchemaVersion  string           `json:"schema_version"`
	Entries        []Entry          `json:"entries"`
	Infrastructure []Infrastructure `json:"infrastructure"`
	Exclusions     []Exclusion      `json:"exclusions"`
}

type Entry struct {
	OperationID      string          `json:"operation_id"`
	VariantID        string          `json:"variant_id"`
	Symbol           string          `json:"symbol"`
	Status           string          `json:"status"`
	Tests            []string        `json:"tests"`
	ContractUnits    []ContractClaim `json:"contract_units"`
	LiveEvidenceIDs  []string        `json:"live_evidence_ids"`
	MappingGroup     string          `json:"mapping_group,omitempty"`
	MappingRationale string          `json:"mapping_rationale,omitempty"`
}

type ContractClaim struct {
	ID    string   `json:"id"`
	Hash  string   `json:"hash"`
	Tests []string `json:"tests"`
}

type Infrastructure struct {
	Symbol string `json:"symbol"`
	Reason string `json:"reason"`
}

type Exclusion struct {
	OperationID string          `json:"operation_id"`
	VariantID   string          `json:"variant_id,omitempty"`
	Reason      string          `json:"reason"`
	Evidence    []model.Locator `json:"evidence"`
}

func LoadRegistry(data []byte) (Registry, error) {
	var result Registry
	if err := load(data, "coverage-registry-v1.schema.json", &result); err != nil {
		return Registry{}, err
	}
	result.originSHA256 = fmt.Sprintf("%x", sha256.Sum256(data))
	return result, nil
}

// ValidateRegistry checks structural links only. Contract hashes and live evidence
// need their separately loaded artifacts; existing test symbols never imply a pass.
func ValidateRegistry(m model.Map, r Registry, s Symbols) []model.Diagnostic {
	diagnostics := []model.Diagnostic{}
	registryHash := artifactHash(r, r.originSHA256)
	symbolsHash := artifactHash(s, s.originSHA256)
	sourceID, sourceHash, pointer := "coverage-registry", registryHash, ""
	at := func(source, hash, location string) { sourceID, sourceHash, pointer = source, hash, location }
	add := func(severity, code, message, canonical string) {
		diagnostics = append(diagnostics, model.Diagnostic{Severity: severity, Code: code, Message: message, CanonicalID: canonical, Locator: model.Locator{SourceID: sourceID, SourceSHA256: sourceHash, Pointer: stringPointer(pointer)}})
	}
	if r.SchemaVersion != "1.0" || s.SchemaVersion != "1.0" {
		add("error", "invalid_version", "Registry and symbol inventory must use version 1.0.", "")
	}
	operations := map[string]bool{}
	variants := map[string]string{}
	for _, op := range m.Operations {
		operations[op.ID] = true
		for _, v := range op.Variants {
			variants[v.ID] = op.ID
		}
	}
	symbols := map[string]Symbol{}
	for i, symbol := range s.Symbols {
		at("php-symbols", symbolsHash, fmt.Sprintf("/symbols/%d", i))
		if _, found := symbols[symbol.ID]; found {
			add("error", "duplicate_symbol", "Inventory repeats symbol "+symbol.ID, "")
		}
		symbols[symbol.ID] = symbol
		if !strings.HasPrefix(symbol.ID, symbol.DeclaringClass+"::") || !projectFile(symbol.File, "src/") && !projectFile(symbol.File, "tests/") || symbol.Line < 1 {
			add("error", "invalid_symbol", "Inventory symbol has an invalid declaration: "+symbol.ID, "")
		}
	}
	for i, d := range s.Diagnostics {
		at("php-symbols", symbolsHash, fmt.Sprintf("/diagnostics/%d", i))
		add(d.Severity, "symbol_inventory_"+d.Code, "Symbol inventory has a declaration diagnostic for "+d.Class+" in "+d.File, "")
	}
	for i, symbol := range s.Symbols {
		at("php-symbols", symbolsHash, fmt.Sprintf("/symbols/%d/providers", i))
		for _, providerID := range symbol.Providers {
			provider, ok := symbols[providerID]
			if !ok || provider.Kind != "provider" || !provider.Public || !provider.Static || provider.Abstract {
				add("error", "invalid_provider_symbol", "Provider reference is not a public static provider: "+providerID, "")
			}
		}
	}
	infrastructure := map[string]bool{}
	for i, item := range r.Infrastructure {
		at("coverage-registry", registryHash, fmt.Sprintf("/infrastructure/%d", i))
		if infrastructure[item.Symbol] {
			add("error", "duplicate_infrastructure", "Repeated infrastructure symbol "+item.Symbol, "")
		}
		infrastructure[item.Symbol] = true
		symbol, ok := symbols[item.Symbol]
		if !ok || !symbol.Public {
			add("error", "unknown_infrastructure_symbol", "Infrastructure symbol is absent or non-public: "+item.Symbol, "")
		}
		if strings.TrimSpace(item.Reason) == "" {
			add("error", "missing_reason", "Infrastructure classification requires a reason.", "")
		}
	}
	excluded := map[string]bool{}
	for i, exclusion := range r.Exclusions {
		at("coverage-registry", registryHash, fmt.Sprintf("/exclusions/%d", i))
		target := exclusion.OperationID
		if !operations[target] {
			add("error", "unknown_operation", "Excluded operation is not in the canonical map.", target)
		}
		if exclusion.VariantID != "" {
			target = exclusion.VariantID
			if variants[target] != exclusion.OperationID {
				add("error", "unknown_variant", "Excluded variant does not belong to the operation.", target)
			}
		}
		if excluded[target] {
			add("error", "duplicate_exclusion", "Repeated exclusion.", target)
		}
		excluded[target] = true
		if strings.TrimSpace(exclusion.Reason) == "" || len(exclusion.Evidence) == 0 {
			add("error", "missing_exclusion_evidence", "Exclusion requires a reason and documentation evidence.", target)
		}
		for _, evidence := range exclusion.Evidence {
			found := false
			for _, op := range m.Operations {
				if op.ID != exclusion.OperationID {
					continue
				}
				for _, locator := range op.Provenance {
					if locator.SourceID == evidence.SourceID && locator.SourceSHA256 == evidence.SourceSHA256 {
						found = true
					}
				}
				for _, variant := range op.Variants {
					if variant.OperationLocator.SourceID == evidence.SourceID && variant.OperationLocator.SourceSHA256 == evidence.SourceSHA256 {
						found = true
					}
				}
			}
			if !found || (evidence.Pointer == nil) == (evidence.ProseLocator == nil) {
				add("error", "invalid_exclusion_evidence", "Exclusion evidence must bind to the operation's pinned source hash and have one locator.", target)
				continue
			}
			if evidence.Pointer != nil {
				doc := m.Documents[evidence.SourceID]
				if _, err := source.Pointer(doc, *evidence.Pointer); doc == nil || err != nil {
					add("error", "invalid_exclusion_evidence", "Exclusion pointer evidence must resolve in the retained source document.", target)
				}
			} else if *evidence.ProseLocator == "" {
				add("error", "invalid_exclusion_evidence", "Exclusion prose evidence requires a nonempty locator.", target)
			}
		}
	}
	mapped := map[string]bool{}
	byVariant := map[string][]Entry{}
	byOperation := map[string][]Entry{}
	for i, entry := range r.Entries {
		at("coverage-registry", registryHash, fmt.Sprintf("/entries/%d", i))
		if !operations[entry.OperationID] {
			add("error", "unknown_operation", "Mapped operation is not in the canonical map.", entry.OperationID)
		}
		if variants[entry.VariantID] != entry.OperationID {
			add("error", "unknown_variant", "Mapped variant does not belong to the operation.", entry.VariantID)
		}
		if entry.Status != "implemented" && entry.Status != "partial" {
			add("error", "invalid_status", "Mapping status must be implemented or partial.", entry.VariantID)
		}
		symbol, ok := symbols[entry.Symbol]
		if !ok || !symbol.Public || symbol.Abstract || symbol.Kind != "method" || !projectFile(symbol.File, "src/") {
			add("error", "invalid_public_symbol", "Mapping requires a concrete public source method: "+entry.Symbol, entry.VariantID)
		}
		if infrastructure[entry.Symbol] {
			add("error", "infrastructure_mapping", "Infrastructure cannot count as an implemented endpoint.", entry.VariantID)
		}
		if excluded[entry.OperationID] || excluded[entry.VariantID] {
			add("error", "excluded_mapping", "Excluded canonical IDs cannot also claim implementation.", entry.VariantID)
		}
		mapped[entry.Symbol] = true
		byVariant[entry.VariantID] = append(byVariant[entry.VariantID], entry)
		byOperation[entry.OperationID] = append(byOperation[entry.OperationID], entry)
		checkTests := func(ids []string) {
			seen := map[string]bool{}
			for _, id := range ids {
				symbol, ok := symbols[id]
				if !ok || symbol.Kind != "test" || !symbol.Public || symbol.Abstract || symbol.Static || !projectFile(symbol.File, "tests/") {
					add("error", "invalid_test_symbol", "Test link requires an executable declared project test: "+id, entry.VariantID)
				}
				if seen[id] {
					add("error", "duplicate_test", "Repeated test link: "+id, entry.VariantID)
				}
				seen[id] = true
			}
		}
		checkTests(entry.Tests)
		claims := map[string]bool{}
		for _, claim := range entry.ContractUnits {
			if claims[claim.ID] {
				add("error", "duplicate_contract_claim", "Repeated contract unit claim.", entry.VariantID)
			}
			claims[claim.ID] = true
			if claim.ID == "" || len(claim.Hash) != 64 || len(claim.Tests) == 0 {
				add("error", "invalid_contract_claim", "Contract claims require an ID, hash, and declared tests.", entry.VariantID)
			}
			checkTests(claim.Tests)
		}
		evidence := map[string]bool{}
		for _, id := range entry.LiveEvidenceIDs {
			if strings.TrimSpace(id) == "" || evidence[id] {
				add("error", "invalid_live_evidence_id", "Live evidence IDs must be nonempty and unique.", entry.VariantID)
			}
			evidence[id] = true
		}
		if (entry.MappingGroup == "") != (entry.MappingRationale == "") {
			add("error", "invalid_mapping_group", "Mapping group and rationale must be declared together.", entry.VariantID)
		}
	}
	at("coverage-registry", registryHash, "/entries")
	for variant, entries := range byVariant {
		if len(entries) < 2 {
			continue
		}
		group := entries[0].MappingGroup
		seen := map[string]bool{}
		valid := strings.TrimSpace(group) != ""
		for _, entry := range entries {
			if entry.MappingGroup != group || strings.TrimSpace(entry.MappingRationale) == "" || seen[entry.Symbol] {
				valid = false
			}
			seen[entry.Symbol] = true
		}
		if !valid {
			add("error", "duplicate_mapping", "Multiple mappings require distinct methods, a shared group, and explicit rationale.", variant)
		}
	}
	for operation, entries := range byOperation {
		methods := map[string]bool{}
		for _, entry := range entries {
			methods[entry.Symbol] = true
		}
		if len(methods) < 2 {
			continue
		}
		group := entries[0].MappingGroup
		valid := strings.TrimSpace(group) != ""
		for _, entry := range entries {
			if entry.MappingGroup != group || strings.TrimSpace(entry.MappingRationale) == "" {
				valid = false
			}
		}
		if !valid {
			add("error", "duplicate_mapping", "Multiple methods for one operation require a shared mapping group and rationale.", operation)
		}
	}

	for i, symbol := range s.Symbols {
		at("php-symbols", symbolsHash, fmt.Sprintf("/symbols/%d", i))
		if symbol.Public && projectFile(symbol.File, "src/") && !mapped[symbol.ID] && !infrastructure[symbol.ID] {
			add("warning", "unclassified_symbol", "Public API symbol has no operation or infrastructure classification: "+symbol.ID, "")
		}
	}
	sort.SliceStable(diagnostics, func(i, j int) bool {
		a, b := diagnostics[i], diagnostics[j]
		return fmt.Sprint(a.CanonicalID, "\x00", a.Severity, "\x00", a.Code, "\x00", a.Message) < fmt.Sprint(b.CanonicalID, "\x00", b.Severity, "\x00", b.Code, "\x00", b.Message)
	})
	return diagnostics
}

func projectFile(file, prefix string) bool {
	return strings.HasPrefix(file, prefix) && path.Clean(file) == file && !strings.Contains(file, "\\") && strings.HasSuffix(file, ".php")
}

// Directly constructed registries/inventories have no original byte stream.
// Their diagnostic provenance hashes deterministic model.Encode bytes instead.
func artifactHash(value any, loadedHash string) string {
	if loadedHash != "" {
		return loadedHash
	}
	data, _ := model.Encode(value) // These structs contain only JSON-compatible fields.
	return fmt.Sprintf("%x", sha256.Sum256(data))
}

func stringPointer(value string) *string { return &value }
