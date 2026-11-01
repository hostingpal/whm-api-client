package effective

import (
	"strings"
	"testing"
)

func TestBuildCarriesEmptyWithoutDataOnANonnullableUnwrappedList(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:listaccts"]
	op.EmptyWithoutData, op.Unwrap, op.Evidence = true, "acct", evidence()
	set.Files[1].Operations["whm1:listaccts"] = op
	result, joined := buildProblems(t, fixtureMap(t), set)
	if joined != "" {
		t.Fatal(joined)
	}
	for _, listed := range groupByClass(t, result, "Accounts").Operations {
		if listed.ID == "whm1:listaccts" {
			if !listed.EmptyWithoutData {
				t.Fatalf("empty_without_data not carried: %+v", listed)
			}
			return
		}
	}
	t.Fatal("listaccts is missing from the Accounts group")
}

func TestBuildRejectsEmptyWithoutDataBeyondNonnullableLists(t *testing.T) {
	list := map[string]any{"type": "array", "items": map[string]any{"type": "string"}}
	scalar := map[string]any{"type": "string"}
	nullableList := map[string]any{"type": "array", "nullable": true, "items": map[string]any{"type": "string"}}
	for name, c := range map[string]struct {
		payload map[string]any
		unwrap  string
	}{
		"scalar field":    {payload: map[string]any{"type": "object", "properties": map[string]any{"acct": scalar}}, unwrap: "acct"},
		"nullable list":   {payload: map[string]any{"type": "object", "properties": map[string]any{"acct": nullableList}}, unwrap: "acct"},
		"unknown field":   {payload: map[string]any{"type": "object", "properties": map[string]any{"acct": list}}, unwrap: "missing"},
		"missing unwrap":  {payload: map[string]any{"type": "object", "properties": map[string]any{"acct": list}}},
		"nullable object": {payload: map[string]any{"type": "object", "nullable": true, "properties": map[string]any{"acct": list}}, unwrap: "acct"},
	} {
		t.Run(name, func(t *testing.T) {
			m := fixtureMap(t)
			setEnvelope(t, fixtureNode(t, m, "whm1:listaccts"), map[string]any{"type": "object", "properties": map[string]any{"data": c.payload}})
			set := fixtureOverlay("listed")
			op := set.Files[1].Operations["whm1:listaccts"]
			op.EmptyWithoutData, op.Unwrap, op.Evidence, op.Response = true, c.unwrap, evidence(), nil
			set.Files[1].Operations["whm1:listaccts"] = op
			if _, joined := buildProblems(t, m, set); !strings.Contains(joined, "whm1:listaccts: empty_without_data requires") {
				t.Fatalf("empty_without_data accepted: %s", joined)
			}
		})
	}
}
