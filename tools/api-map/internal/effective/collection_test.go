package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

const indexedDescriptor = `{"kind":"indexed","item_type":"string","allow_scalar":true,"empty":"reject","prefix":"domain","separator":"-","start":0}`

func TestCollectionPreciseEffectiveTypes(t *testing.T) {
	for _, tc := range []struct{ source, descriptor, native, doc string }{
		{`{"type":"string"}`, indexedDescriptor, "string|array", "string|non-empty-list<string>"},
		{`{"type":"integer"}`, strings.ReplaceAll(strings.ReplaceAll(indexedDescriptor, `"string"`, `"int"`), `"prefix":"domain","separator":"-","start":0`, `"prefix":"setting_id","separator":"","start":1`), "int|array", "int|non-empty-list<int>"},
		{`{"type":"integer","enum":[0,1]}`, strings.Replace(indexedDescriptor, `"item_type":"string"`, `"item_type":"bool"`, 1), "bool|array", "bool|non-empty-list<bool>"},
		{`{"type":"string"}`, strings.Replace(indexedDescriptor, `"item_type":"string"`, `"item_type":"string|int"`, 1), "string|int|array", "string|int|non-empty-list<string|int>"},
		{`{"type":"integer"}`, `{"kind":"repeat","item_type":"int","allow_scalar":false,"empty":"reject"}`, "array", "non-empty-list<int>"},
	} {
		t.Run(tc.native+tc.descriptor, func(t *testing.T) {
			m := fixtureMap(t)
			var source map[string]any
			if err := json.Unmarshal([]byte(tc.source), &source); err != nil {
				t.Fatal(err)
			}
			for _, op := range m.Operations {
				if op.ID == "whm1:createacct" {
					op.Variants[0].Parameters[0].Definition["schema"] = source
				}
			}
			set := fixtureOverlay("listed")
			var op overlay.Operation
			raw := `{"params":{"username":{"collection":` + tc.descriptor + `}},"evidence":[{"kind":"doc","version":"synthetic fixture only","date":"2026-10-31","note":"Fixture indexed parameter prose","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0/description"}]}`
			if err := json.Unmarshal([]byte(raw), &op); err != nil {
				t.Fatal(err)
			}
			op.Name = "createAccount"
			set.Files[1].Operations["whm1:createacct"] = op
			result, problems := buildProblems(t, m, set)
			if problems != "" {
				t.Fatal(problems)
			}
			p := groupByClass(t, result, "Accounts").Operations[0].Params[0]
			if p.PHPType != tc.native || p.DocType != tc.doc || p.Encoding != "collection" || p.Sensitive || !p.Required {
				t.Fatalf("incorrect collection contract: %+v", p)
			}
		})
	}
}

func TestCollectionRejectsIncompatibleSourcesAndUnboundEvidence(t *testing.T) {
	for _, tc := range []struct{ source, evidence string }{
		{`{"type":"number"}`, `"source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0"`},
		{`{"type":"integer"}`, `"source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0"`},
		{`{"type":"boolean"}`, `"source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0"`},
		{`{"type":"object"}`, `"source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0"`},
		{`{"type":"string"}`, `"source_sha256":"wrong","pointer":"/paths/~1createacct/get/parameters/0"`},
		{`{"type":"string"}`, `"source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1"`},
		{`{"type":"string"}`, `"source_sha256":"y","pointer":"/paths/~1other/get/parameters/0"`},
		{`{"type":"string"}`, `"note":"unbound evidence"`},
	} {
		m := fixtureMap(t)
		var source map[string]any
		_ = json.Unmarshal([]byte(tc.source), &source)
		for _, op := range m.Operations {
			if op.ID == "whm1:createacct" {
				op.Variants[0].Parameters[0].Definition["schema"] = source
			}
		}
		set := fixtureOverlay("listed")
		var op overlay.Operation
		if err := json.Unmarshal([]byte(`{"name":"createAccount","params":{"username":{"collection":`+indexedDescriptor+`}},"evidence":[{"kind":"doc","version":"synthetic","date":"2026-10-31",`+tc.evidence+`}]}`), &op); err != nil {
			t.Fatal(err)
		}
		set.Files[1].Operations["whm1:createacct"] = op
		_, problems := buildProblems(t, m, set)
		if !strings.Contains(problems, "collection") {
			t.Fatalf("incompatible collection accepted: %s %s: %s", tc.source, tc.evidence, problems)
		}
	}
}

func TestCollectionRejectsAllStaticOverlappingMappings(t *testing.T) {
	for _, tc := range []struct{ name, wire, encoding string }{
		{"fixed", "domain-0", "scalar"},
		{"legacy numbered", "domain", "numbered"},
		{"repeat", "domain-1", "repeat"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", tc.wire, map[string]any{"type": "string"})
			set := fixtureOverlay("listed")
			var op overlay.Operation
			raw := `{"name":"createAccount","params":{"username":{"collection":` + indexedDescriptor + `},"` + tc.wire + `":{"php":"other","encoding":"` + tc.encoding + `"}},"evidence":[{"kind":"doc","version":"synthetic","date":"2026-10-31","note":"Fixture only","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0"}]}`
			if err := json.Unmarshal([]byte(raw), &op); err != nil {
				t.Fatal(err)
			}
			set.Files[1].Operations["whm1:createacct"] = op
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "overlapping") {
				t.Fatalf("overlapping %s accepted: %s", tc.name, problems)
			}
		})
	}
}

func TestCollectionSourceListsUnionsAndOptionalNullability(t *testing.T) {
	for _, schema := range []string{
		`{"type":"string","nullable":true}`,
		`{"type":"array","items":{"type":"string"}}`,
		`{"anyOf":[{"type":"string"},{"type":"integer"}]}`,
	} {
		m := fixtureMap(t)
		var source map[string]any
		_ = json.Unmarshal([]byte(schema), &source)
		descriptor := indexedDescriptor
		if strings.Contains(schema, `anyOf`) {
			descriptor = strings.Replace(descriptor, `"item_type":"string"`, `"item_type":"string|int"`, 1)
		}
		for _, op := range m.Operations {
			if op.ID == "whm1:createacct" {
				op.Variants[0].Parameters[1].Definition["schema"] = source
			}
		}
		set := fixtureOverlay("listed")
		var op overlay.Operation
		if err := json.Unmarshal([]byte(`{"name":"createAccount","params":{"password":{"collection":`+descriptor+`}},"evidence":[{"kind":"doc","version":"synthetic","date":"2026-10-31","note":"Fixture only","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1"}]}`), &op); err != nil {
			t.Fatal(err)
		}
		set.Files[1].Operations["whm1:createacct"] = op
		result, problems := buildProblems(t, m, set)
		if problems != "" {
			t.Fatal(problems)
		}
		p := groupByClass(t, result, "Accounts").Operations[0].Params[1]
		if p.Required || !p.Sensitive || !strings.Contains(p.DocType, "non-empty-list<") {
			t.Fatalf("optional/list/union contract lost: %+v", p)
		}
	}
}

func TestCollectionRejectsStaticPatternPairsInBothOrders(t *testing.T) {
	for _, pair := range [][2]string{{"domain", "domain"}, {"setting", "setting1"}, {"setting1", "setting"}} {
		var problems []string
		c := func(prefix string) Param {
			var p overlay.Param
			descriptor := strings.Replace(indexedDescriptor, `"prefix":"domain","separator":"-"`, `"prefix":"`+prefix+`","separator":""`, 1)
			if err := json.Unmarshal([]byte(`{"collection":`+descriptor+`}`), &p); err != nil {
				t.Fatal(err)
			}
			return Param{Wire: prefix, PHP: prefix, Encoding: "collection", Collection: p.Collection}
		}
		b := builder{problems: map[string]bool{}}
		b.collectionOverlaps("fixture", []Param{c(pair[0]), c(pair[1])})
		for problem := range b.problems {
			problems = append(problems, problem)
		}
		if len(problems) == 0 {
			t.Fatalf("overlapping indexed families accepted: %v", pair)
		}
	}
}

func TestCollectionRequiresMetadataEvenForDirectBuilderCall(t *testing.T) {
	b := builder{problems: map[string]bool{}}
	for _, raw := range []string{`{}`, `{"kind":"repeat","item_type":"int","empty":"reject"}`, `{"kind":"indexed","item_type":"string","allow_scalar":true,"empty":"reject","prefix":"a","separator":"-","start":-1}`} {
		var p overlay.Param
		if err := json.Unmarshal([]byte(`{"collection":`+raw+`}`), &p); err != nil {
			t.Fatal(err)
		}
		b.collection(model.Operation{ID: "fixture"}, model.Parameter{Name: "arg"}, overlay.Operation{Params: map[string]overlay.Param{"arg": p}}, TypeSpec{Type: "string"}, &Param{})
	}
	if len(b.problems) == 0 {
		t.Fatal("incomplete descriptors accepted by effective builder")
	}
}

func TestCollectionRejectsTypedRepeatLegacyNumberedOverlapInBothOrders(t *testing.T) {
	for _, reverse := range []bool{false, true} {
		name := "legacy first"
		if reverse {
			name = "collection first"
		}
		t.Run(name, func(t *testing.T) {
			m := fixtureMap(t)
			addQueryParameter(t, m, "whm1:createacct", "username-1", map[string]any{"type": "integer"})
			for _, sourceOp := range m.Operations {
				if sourceOp.ID != "whm1:createacct" {
					continue
				}
				params := sourceOp.Variants[0].Parameters
				// Both optional arguments can appear in either signature order.
				params[0].Definition["required"] = false
				params[len(params)-1].Locator = params[0].Locator
				pointer := "/paths/~1createacct/get/parameters/3"
				params[len(params)-1].Locator.Pointer = &pointer
			}
			set := fixtureOverlay("listed")
			var op overlay.Operation
			raw := `{"name":"createAccount","params":{"username":{"encoding":"numbered"},"username-1":{"php":"remove","collection":{"kind":"repeat","item_type":"int","allow_scalar":false,"empty":"reject"}}},"evidence":[{"kind":"doc","version":"synthetic fixture only","date":"2026-10-31","note":"Fixture only: typed repeat overlaps legacy numbered expansion","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/3"}]}`
			if err := json.Unmarshal([]byte(raw), &op); err != nil {
				t.Fatal(err)
			}
			op.Order = []string{"username", "username-1"}
			if reverse {
				op.Order = []string{"username-1", "username"}
			}
			set.Files[1].Operations["whm1:createacct"] = op
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "overlapping") {
				t.Fatalf("new typed repeat overlapping legacy numbered family accepted; problems=%q", problems)
			}
		})
	}
}

// Trace redaction for ordinary lists must not turn an inventory GET into POST;
// actual secret names and explicit sensitive decisions still require POST.
func TestCollectionTraceRedactionPreservesTransportPolicy(t *testing.T) {
	for _, tc := range []struct {
		name, wire, decision, method, want, problem string
	}{
		{"plain inferred GET", "username", "", "", "GET", ""},
		{"plain explicit GET", "username", "", "GET", "GET", ""},
		{"explicit nonsecret GET", "username", `,"sensitive":false`, "GET", "GET", ""},
		{"explicit secret inferred POST", "username", `,"sensitive":true`, "", "POST", ""},
		{"explicit secret refuses GET", "username", `,"sensitive":true`, "GET", "", "secret-bearing parameters require POST"},
		{"secret name inferred POST", "password", "", "", "POST", ""},
		{"secret name refuses GET", "password", "", "GET", "", "secret-bearing parameters require POST"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			for i := range m.Operations {
				if m.Operations[i].ID != "whm1:createacct" {
					continue
				}
				v := &m.Operations[i].Variants[0]
				v.Parameters = v.Parameters[:1]
				v.Parameters[0].Name = tc.wire
				v.Parameters[0].Definition["name"] = tc.wire
				v.Risk.Classification = "read-only"
			}
			var op overlay.Operation
			raw := `{"name":"createAccount","params":{"` + tc.wire + `":{"collection":` + indexedDescriptor + tc.decision + `}},"evidence":[{"kind":"doc","version":"synthetic fixture only","date":"2026-10-31","note":"Fixture collection transport policy","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/0"}]}`
			if err := json.Unmarshal([]byte(raw), &op); err != nil {
				t.Fatal(err)
			}
			op.Method = tc.method
			set := fixtureOverlay("listed")
			set.Files[1].Operations["whm1:createacct"] = op
			result, problems := buildProblems(t, m, set)
			if tc.problem != "" {
				if !strings.Contains(problems, tc.problem) {
					t.Fatalf("secret transport policy bypassed: %s", problems)
				}
				return
			}
			if problems != "" {
				t.Fatal(problems)
			}
			got := groupByClass(t, result, "Accounts").Operations[0]
			if got.Method != tc.want || got.Params[0].Sensitive != (tc.wire == "password" || tc.decision == `,"sensitive":true`) {
				t.Fatalf("transport/redaction policy lost: %+v", got)
			}
		})
	}
}
