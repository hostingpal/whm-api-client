package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestFormRecordSourceAndCorrections(t *testing.T) {
	for _, tc := range []string{"add", "edit", "wrong hash", "wrong pointer", "missing handler evidence", "wrong media", "query", "unknown correction", "wrong allOf", "duplicate mapping", "duplicate base property", "different base", "unsupported field", "composed scalar", "bad requiredness"} {
		t.Run(tc, func(t *testing.T) {
			m := fixtureMap(t)
			ref := func(name string) map[string]any { return map[string]any{"$ref": "#/components/schemas/" + name} }
			field := func(typ string) map[string]any { return map[string]any{"type": typ} }
			base := map[string]any{"type": "object", "required": []any{"domain", "type", "name"}, "properties": map[string]any{"domain": field("string"), "name": field("string"), "type": field("string")}}
			props := map[string]any{"target": field("string"), "weight": field("integer")}
			extra := map[string]any{"type": "object", "required": []any{"target"}, "properties": props}
			branches := []any{ref("Base"), extra}
			mx := map[string]any{"allOf": branches}
			txt := map[string]any{"allOf": []any{ref("Base"), map[string]any{"type": "object", "properties": map[string]any{"txtdata": field("string")}}}}
			mapping := map[string]any{"MX": "#/components/schemas/MX", "Text record": "#/components/schemas/TXT"}
			schema := map[string]any{"discriminator": map[string]any{"propertyName": "type", "mapping": mapping}, "anyOf": []any{ref("TXT"), ref("MX")}}
			m.Documents["whm-openapi"]["components"] = map[string]any{"schemas": map[string]any{"MX": mx, "TXT": txt, "Base": base}}
			body := formBody(true, schema)
			node := map[string]any{"requestBody": body}
			ptr := "/paths/~1addzonerecord/post"
			op := model.Operation{ID: "whm1:addzonerecord", Family: "whm1"}
			v := model.Variant{Method: "POST", OperationLocator: model.Locator{SourceID: "whm-openapi", Pointer: &ptr, SourceSHA256: strings.Repeat("a", 64)}}
			d := &overlay.FormRecord{Labels: map[string]string{"Text record": "TXT"}, Variants: map[string]overlay.FormRecordChanges{"MX": {Add: map[string]overlay.FormRecordField{"preference": {Type: "int", Description: "MX preference."}}}}}
			ov := overlay.Operation{Method: "POST", FormRecord: d, Evidence: []overlay.Evidence{
				{Kind: "doc", SourceSHA256: v.OperationLocator.SourceSHA256, Pointer: ptr + "/requestBody", Version: "pinned", Date: "2026-11-18", Note: "Source-bound form."},
				{Kind: "doc", Version: "handler 1.0", Date: "2026-11-18", Note: "Serializer requires preference."},
			}}
			switch tc {
			case "edit":
				op.ID = "whm1:editzonerecord"
				d.RetainExisting = true
				base["required"] = []any{"domain", "line"}
				base["properties"].(map[string]any)["line"] = field("integer")
			case "wrong hash":
				ov.Evidence[0].SourceSHA256 = "wrong"
			case "wrong pointer":
				ov.Evidence[0].Pointer = "/wrong"
			case "missing handler evidence":
				ov.Evidence = ov.Evidence[:1]
			case "wrong media":
				body["content"] = map[string]any{"application/json": map[string]any{"schema": schema}}
			case "query":
				v.Parameters = []model.Parameter{{Name: "duplicate"}}
			case "unknown correction":
				d.Variants["Y"] = overlay.FormRecordChanges{}
			case "wrong allOf":
				mx["allOf"] = branches[:1]
			case "duplicate mapping":
				mapping["MX"] = mapping["Text record"]
			case "duplicate base property":
				props["domain"] = field("string")
			case "different base":
				branches[0] = ref("OtherBase")
			case "unsupported field":
				props["target"] = field("array")
			case "composed scalar":
				props["target"] = map[string]any{"type": "string", "not": field("string")}
			case "bad requiredness":
				extra["required"] = []any{3}
			}
			s, err := newSchemas(m)
			if err != nil {
				t.Fatal(err)
			}
			b := builder{schemas: s}
			record, params, err := b.formRecord(op, v, ov, node, "writeRecord")
			if tc != "add" && tc != "edit" {
				if err == nil {
					t.Fatal("malformed form accepted")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if record.Required != (tc == "add") || record.Untagged != (tc == "edit") || len(record.Variants) != 2 || record.Variants[0].Tag != "MX" || record.Variants[1].Tag != "TXT" {
				t.Fatalf("record %+v", record)
			}
			fields := record.Variants[0].Fields
			if len(fields) != 3 || fields[0].Name != "preference" || fields[0].Type != "int" || fields[1].Name != "target" || fields[1].Required != (tc == "add") || fields[2].Required {
				t.Fatalf("fields %+v", fields)
			}
			byName := map[string]Param{}
			for _, p := range params {
				byName[p.PHP] = p
			}
			if params[0].PHP != "domain" || !byName["record"].Sensitive || byName["record"].PHPType != "array" || (tc == "edit" && (!byName["line"].PositiveInt || byName["line"].DocType != "positive-int")) {
				t.Fatalf("params %+v", params)
			}
		})
	}
}
