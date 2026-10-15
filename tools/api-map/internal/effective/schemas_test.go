package effective

import (
	"bytes"
	"encoding/json"
	"os"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

func fixtureMap(t *testing.T) model.Map {
	t.Helper()
	raw, err := os.ReadFile("testdata/fixture-map.json")
	if err != nil {
		t.Fatal(err)
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	var m model.Map
	if err := decoder.Decode(&m); err != nil {
		t.Fatal(err)
	}
	return m
}

func operationByID(t *testing.T, m model.Map, id string) model.Operation {
	t.Helper()
	for _, op := range m.Operations {
		if op.ID == id {
			return op
		}
	}
	t.Fatalf("missing %s", id)
	return model.Operation{}
}

func TestPayloadExtractsUAPIResultData(t *testing.T) {
	m := fixtureMap(t)
	s, err := newSchemas(m)
	if err != nil {
		t.Fatal(err)
	}
	op := operationByID(t, m, "uapi:DomainInfo/list_domains")
	info, _, err := s.payload(op, op.Variants[0])
	spec := info.spec
	if err != nil || spec == nil || info.issue != "" || info.partial {
		t.Fatalf("payload %+v %v", info, err)
	}
	if spec.Type != "object" || len(spec.Fields) != 3 || spec.Required[0] != "main_domain" {
		t.Fatalf("unexpected spec %+v", spec)
	}
	// Fields are sorted by name: is_temporary, main_domain, sub_domains.
	if spec.Fields[0].Spec.Type != "map" || spec.Fields[0].Spec.Values.Type != "flag" {
		t.Fatalf("flag map not detected: %+v", spec.Fields[0])
	}
	if !spec.Fields[1].Spec.Nullable || spec.Fields[2].Spec.Items.Type != "string" {
		t.Fatalf("unexpected fields %+v", spec.Fields)
	}
}

func TestPayloadIsNilWithoutDataMember(t *testing.T) {
	m := fixtureMap(t)
	s, _ := newSchemas(m)
	op := operationByID(t, m, "whm1:suspendacct")
	info, _, err := s.payload(op, op.Variants[0])
	if err != nil || info.spec != nil || info.issue != "" {
		t.Fatalf("expected void payload, got %+v %v", info, err)
	}
}

func TestSpecForStopsAtCycles(t *testing.T) {
	m := fixtureMap(t)
	s, _ := newSchemas(m)
	spec := s.specFor("cpanel-openapi", map[string]any{"$ref": "#/components/schemas/Node"}, 0, nil)
	if spec.Type != "object" || spec.Fields[0].Name != "child" || spec.Fields[0].Spec.Type != "mixed" {
		t.Fatalf("cycle not cut: %+v", spec)
	}
}

func TestSpecForHandlesUnionsNullabilityAndDepth(t *testing.T) {
	m := fixtureMap(t)
	s, _ := newSchemas(m)
	union := s.specFor("cpanel-openapi", map[string]any{"oneOf": []any{
		map[string]any{"type": "string"},
		map[string]any{"type": "integer"},
	}}, 0, nil)
	if union.Type != "union" || len(union.AnyOf) != 2 {
		t.Fatalf("union %+v", union)
	}
	nullable := s.specFor("cpanel-openapi", map[string]any{"type": []any{"string", "null"}}, 0, nil)
	if nullable.Type != "string" || !nullable.Nullable {
		t.Fatalf("nullable %+v", nullable)
	}
	if deep := s.specFor("cpanel-openapi", map[string]any{"type": "string"}, maxDepth+1, nil); deep.Type != "mixed" {
		t.Fatalf("depth %+v", deep)
	}
	opaque := s.specFor("cpanel-openapi", map[string]any{"type": "object"}, 0, nil)
	if opaque.Type != "map" || opaque.Values.Type != "mixed" {
		t.Fatalf("opaque object %+v", opaque)
	}
}

func TestEnumDocListsLiteralValues(t *testing.T) {
	m := fixtureMap(t)
	s, _ := newSchemas(m)
	if doc := s.enumDoc("whm-openapi", map[string]any{"type": "string", "enum": []any{"default", "custom"}}); doc != "'default'|'custom'" {
		t.Fatal(doc)
	}
	if doc := s.enumDoc("whm-openapi", map[string]any{"type": "integer", "enum": []any{json.Number("1"), json.Number("2")}}); doc != "1|2" {
		t.Fatal(doc)
	}
}
