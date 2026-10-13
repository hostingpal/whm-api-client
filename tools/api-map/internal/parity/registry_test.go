package parity

import (
	"crypto/sha256"
	"fmt"
	"os"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

func fixtureMap() model.Map {
	return model.Map{Operations: []model.Operation{{ID: "whm1:known", Variants: []model.Variant{{ID: "whm1:known#GET"}}}}}
}

func fixtureSymbols() Symbols {
	return Symbols{SchemaVersion: "1.0", Symbols: []Symbol{
		{ID: "Client::send", Kind: "method", DeclaringClass: "Client", File: "src/Client.php", Line: 5, Public: true},
		{ID: "ClientTest::testSend", Kind: "test", DeclaringClass: "ClientTest", File: "tests/ClientTest.php", Line: 5, Public: true},
		{ID: "ClientTest::values", Kind: "provider", DeclaringClass: "ClientTest", File: "tests/ClientTest.php", Line: 10, Public: true, Static: true},
	}, Diagnostics: []SymbolDiagnostic{}}
}

func fixtureRegistry() Registry {
	return Registry{SchemaVersion: "1.0", Entries: []Entry{{OperationID: "whm1:known", VariantID: "whm1:known#GET", Symbol: "Client::send", Status: "partial", Tests: []string{"ClientTest::testSend"}, ContractUnits: []ContractClaim{}, LiveEvidenceIDs: []string{}}}, Infrastructure: []Infrastructure{}, Exclusions: []Exclusion{}}
}

func hasCode(ds []model.Diagnostic, code string) bool {
	for _, d := range ds {
		if d.Code == code {
			return true
		}
	}
	return false
}

func TestRegistryKnownMapping(t *testing.T) {
	if ds := ValidateRegistry(fixtureMap(), fixtureRegistry(), fixtureSymbols()); len(ds) != 0 {
		t.Fatalf("valid registry: %+v", ds)
	}
}

func TestRegistryRejectsUnknownOperationDespiteRealSymbol(t *testing.T) {
	r := fixtureRegistry()
	r.Entries[0].OperationID = "whm1:invented"
	if ds := ValidateRegistry(fixtureMap(), r, fixtureSymbols()); !hasCode(ds, "unknown_operation") {
		t.Fatalf("missing unknown operation: %+v", ds)
	}
}

func TestRegistryRejectsProviderAsTest(t *testing.T) {
	r := fixtureRegistry()
	r.Entries[0].Tests = []string{"ClientTest::values"}
	if ds := ValidateRegistry(fixtureMap(), r, fixtureSymbols()); !hasCode(ds, "invalid_test_symbol") {
		t.Fatalf("provider accepted as test: %+v", ds)
	}
}

func TestRegistryRejectsWrongVariantPrivateAndNonSourceMethods(t *testing.T) {
	for _, mutate := range []func(*Registry, *Symbols){
		func(r *Registry, _ *Symbols) { r.Entries[0].VariantID = "uapi:Other/function#GET" },
		func(_ *Registry, s *Symbols) { s.Symbols[0].Public = false },
		func(_ *Registry, s *Symbols) { s.Symbols[0].File = "tests/Client.php" },
		func(_ *Registry, s *Symbols) { s.Symbols[1].Abstract = true },
	} {
		r, s := fixtureRegistry(), fixtureSymbols()
		mutate(&r, &s)
		if ds := ValidateRegistry(fixtureMap(), r, s); len(ds) == 0 {
			t.Fatal("invalid link accepted")
		}
	}
}

func TestRegistryDuplicateRequiresSharedGroupAndRationale(t *testing.T) {
	r := fixtureRegistry()
	second := r.Entries[0]
	second.Symbol = "Client::other"
	r.Entries = append(r.Entries, second)
	s := fixtureSymbols()
	other := s.Symbols[0]
	other.ID = "Client::other"
	s.Symbols = append(s.Symbols, other)
	if ds := ValidateRegistry(fixtureMap(), r, s); !hasCode(ds, "duplicate_mapping") {
		t.Fatalf("duplicate accepted: %+v", ds)
	}
	for i := range r.Entries {
		r.Entries[i].MappingGroup = "split"
		r.Entries[i].MappingRationale = "Different supported call signatures."
	}
	if ds := ValidateRegistry(fixtureMap(), r, s); len(ds) != 0 {
		t.Fatalf("intentional mapping rejected: %+v", ds)
	}
	r.Entries[1].MappingRationale = ""
	if ds := ValidateRegistry(fixtureMap(), r, s); !hasCode(ds, "duplicate_mapping") {
		t.Fatalf("missing rationale accepted: %+v", ds)
	}
}

func TestRegistryInfrastructureExcludedAndUnclassified(t *testing.T) {
	r := fixtureRegistry()
	r.Entries = []Entry{}
	if ds := ValidateRegistry(fixtureMap(), r, fixtureSymbols()); !hasCode(ds, "unclassified_symbol") {
		t.Fatalf("unclassified method hidden: %+v", ds)
	}
	r.Infrastructure = []Infrastructure{{Symbol: "Client::send", Reason: "Generic HTTP transport."}, {Symbol: "ClientTest::values", Reason: "Offline data provider."}}
	if ds := ValidateRegistry(fixtureMap(), r, fixtureSymbols()); len(ds) != 0 {
		t.Fatalf("infrastructure rejected: %+v", ds)
	}
	r.Entries = fixtureRegistry().Entries
	if ds := ValidateRegistry(fixtureMap(), r, fixtureSymbols()); !hasCode(ds, "infrastructure_mapping") {
		t.Fatalf("infrastructure counted as endpoint: %+v", ds)
	}
}

func TestSymbolsAndRegistryStrictLoading(t *testing.T) {
	symbols, err := os.ReadFile("../../../../docs/api/coverage/symbols.json")
	if err != nil {
		t.Fatal(err)
	}
	s, err := LoadSymbols(symbols)
	if err != nil {
		t.Fatal(err)
	}
	registry, err := os.ReadFile("../../../../docs/api/coverage/registry.json")
	if err != nil {
		t.Fatal(err)
	}
	r, err := LoadRegistry(registry)
	if err != nil {
		t.Fatal(err)
	}
	if len(r.Entries) != 0 {
		t.Fatal("transport inflated endpoint coverage")
	}
	if ds := ValidateRegistry(fixtureMap(), r, s); len(ds) != 0 {
		t.Fatalf("initial registry: %+v", ds)
	}
	for _, bad := range []string{`{"schema_version":"1.0","entries":[],"infrastructure":[],"exclusions":[],"extra":true}`, string(registry) + `{}`, strings.Replace(string(registry), `"1.0"`, `"2.0"`, 1)} {
		if _, err := LoadRegistry([]byte(bad)); err == nil {
			t.Fatal("invalid registry accepted")
		}
	}
	if _, err := LoadSymbols([]byte(`{"schema_version":"1.0","symbols":[],"diagnostics":[],"extra":true}`)); err == nil {
		t.Fatal("invalid symbols accepted")
	}
}

func TestRegistryMultipleMethodsAcrossVariantsRequireGroup(t *testing.T) {
	m := fixtureMap()
	m.Operations[0].Variants = append(m.Operations[0].Variants, model.Variant{ID: "whm1:known#POST"})
	r := fixtureRegistry()
	second := r.Entries[0]
	second.VariantID = "whm1:known#POST"
	second.Symbol = "Client::other"
	r.Entries = append(r.Entries, second)
	s := fixtureSymbols()
	other := s.Symbols[0]
	other.ID = "Client::other"
	s.Symbols = append(s.Symbols, other)
	if ds := ValidateRegistry(m, r, s); !hasCode(ds, "duplicate_mapping") {
		t.Fatalf("multiple methods for operation accepted without rationale: %+v", ds)
	}
	for i := range r.Entries {
		r.Entries[i].MappingGroup = "transports"
		r.Entries[i].MappingRationale = "Each method implements one documented transport."
	}
	if ds := ValidateRegistry(m, r, s); len(ds) != 0 {
		t.Fatalf("group rejected: %+v", ds)
	}
}

func TestRegistryExclusionRequiresPinnedEvidenceAndReason(t *testing.T) {
	m := fixtureMap()
	pointer := "/paths/~1known"
	m.Documents = map[string]model.Object{"synthetic": {"paths": model.Object{"/known": model.Object{}}}}
	locator := model.Locator{SourceID: "synthetic", SourceSHA256: strings.Repeat("a", 64), Pointer: &pointer}
	m.Operations[0].Provenance = []model.Locator{locator}
	r := fixtureRegistry()
	r.Entries = []Entry{}
	r.Infrastructure = []Infrastructure{{Symbol: "Client::send", Reason: "Transport."}}
	r.Exclusions = []Exclusion{{OperationID: "whm1:known", Reason: "Unsupported upstream contract.", Evidence: []model.Locator{locator}}}
	if ds := ValidateRegistry(m, r, fixtureSymbols()); len(ds) != 0 {
		t.Fatalf("evidenced exclusion rejected: %+v", ds)
	}
	r.Exclusions[0].Evidence[0].SourceSHA256 = strings.Repeat("b", 64)
	if ds := ValidateRegistry(m, r, fixtureSymbols()); !hasCode(ds, "invalid_exclusion_evidence") {
		t.Fatalf("unbound evidence accepted: %+v", ds)
	}
	r.Exclusions[0].Reason = ""
	if ds := ValidateRegistry(m, r, fixtureSymbols()); !hasCode(ds, "missing_exclusion_evidence") {
		t.Fatalf("reasonless exclusion accepted: %+v", ds)
	}
}

func TestRegistryContractClaimCannotUseProviderAsTest(t *testing.T) {
	r := fixtureRegistry()
	r.Entries[0].ContractUnits = []ContractClaim{{ID: "whm1:known#GET:parameter:name:query", Hash: strings.Repeat("a", 64), Tests: []string{"ClientTest::values"}}}
	if ds := ValidateRegistry(fixtureMap(), r, fixtureSymbols()); !hasCode(ds, "invalid_test_symbol") {
		t.Fatalf("provider accepted in contract tests: %+v", ds)
	}
}

func TestSymbolsRejectsDuplicateKeysAndInvalidNestedRecords(t *testing.T) {
	for _, raw := range []string{
		`{"schema_version":"1.0","schema_version":"2.0","symbols":[],"diagnostics":[]}`,
		`{"schema_version":"1.0","symbols":[{"id":"Client::send","kind":"method","declaring_class":"Client","file":"src/../outside.php","line":1,"public":true,"static":false,"abstract":false,"providers":[]}],"diagnostics":[]}`,
		`{"schema_version":"1.0","symbols":[],"diagnostics":[]} {}`,
	} {
		if _, err := LoadSymbols([]byte(raw)); err == nil {
			t.Fatalf("invalid inventory accepted: %s", raw)
		}
	}
}

func TestRegistryDiagnosticsRetainInputArtifactProvenance(t *testing.T) {
	r := fixtureRegistry()
	r.Entries[0].OperationID = "whm1:invented"
	raw, err := model.Encode(r)
	if err != nil {
		t.Fatal(err)
	}
	raw = append([]byte("\n"), raw...)
	loaded, err := LoadRegistry(raw)
	if err != nil {
		t.Fatal(err)
	}
	wantHash := fmt.Sprintf("%x", sha256.Sum256(raw))
	ds := ValidateRegistry(fixtureMap(), loaded, fixtureSymbols())
	found := false
	for _, d := range ds {
		if d.Code != "unknown_operation" {
			continue
		}
		found = true
		if d.Locator.SourceID != "coverage-registry" || d.Locator.SourceSHA256 != wantHash || d.Locator.Pointer == nil || *d.Locator.Pointer != "/entries/0" || d.Locator.ProseLocator != nil {
			t.Fatalf("diagnostic lost actual registry input provenance: %+v", d)
		}
	}
	if !found {
		t.Fatal("expected unknown operation diagnostic")
	}
	s := fixtureSymbols()
	s.Symbols[1].Providers = []string{"ClientTest::missing"}
	canonical, err := model.Encode(s)
	if err != nil {
		t.Fatal(err)
	}
	wantHash = fmt.Sprintf("%x", sha256.Sum256(canonical))
	ds = ValidateRegistry(fixtureMap(), fixtureRegistry(), s)
	found = false
	for _, d := range ds {
		if d.Code != "invalid_provider_symbol" {
			continue
		}
		found = true
		if d.Locator.SourceID != "php-symbols" || d.Locator.SourceSHA256 != wantHash || d.Locator.Pointer == nil || *d.Locator.Pointer != "/symbols/1/providers" {
			t.Fatalf("synthetic inventory provenance missing: %+v", d)
		}
	}
	if !found {
		t.Fatal("expected provider diagnostic")
	}
}
