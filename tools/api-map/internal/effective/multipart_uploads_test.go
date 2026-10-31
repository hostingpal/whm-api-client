package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestReviewedRequiredMultipartUploadList(t *testing.T) {
	m := fixtureMap(t)
	id := "uapi:DomainInfo/primary_domain"
	node := fixtureNode(t, m, id)
	node["description"] = "Direct-only upload; numbered file parts."
	node["requestBody"] = map[string]any{"required": true, "description": "Number files from file-0.", "content": map[string]any{"multipart/form-data": map[string]any{"schema": map[string]any{"type": "object", "required": []any{"file-0"}, "properties": map[string]any{"file-0": map[string]any{"type": "string", "format": "binary"}}}}}}
	for i := range m.Operations {
		if m.Operations[i].ID == id {
			m.Operations[i].Variants[0].Method = "POST"
			m.Operations[i].Variants[0].ID = id + "#POST"
		}
	}
	v := m.Operations[0].Variants[0]
	// Locate the selected fixture's exact provenance.
	for _, o := range m.Operations {
		if o.ID == id {
			v = o.Variants[0]
		}
	}
	pointer := *v.OperationLocator.Pointer
	var ov overlay.Operation
	if err := json.Unmarshal([]byte(`{"body":"multipart","multipart_uploads":{"php":"files","source":"file-0","prefix":"file-","start":0,"routes":"direct","filename_forbidden":"<>;"}}`), &ov); err != nil {
		t.Fatal(err)
	}
	for _, suffix := range []string{"/description", "/requestBody/description", "/requestBody/content/multipart~1form-data/schema/properties/file-0"} {
		ov.Evidence = append(ov.Evidence, overlay.Evidence{Kind: "doc", Version: "pinned", Date: "2026-10-31", Note: "Reviewed binding.", SourceSHA256: v.OperationLocator.SourceSHA256, Pointer: pointer + suffix})
	}
	set := fixtureOverlay("listed")
	set.Files[0].Operations[id] = ov
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	op := groupByClass(t, result, "DomainInfo").Operations[1]
	if len(op.Params) != 1 || op.Params[0].PHP != "files" || op.Params[0].DocType != "non-empty-list<Upload>" || !op.Params[0].Sensitive || op.Params[0].Wire != "" {
		t.Fatalf("upload parameter %+v", op.Params)
	}
	for _, mutation := range []string{"evidence", "optional body", "optional field", "extra field", "query collision", "query collection", "nullable object", "multipart encoding", "nullable", "scalar", "start", "routes", "source"} {
		t.Run(mutation, func(t *testing.T) {
			// Reuse clean deep copies so negative cases do not contaminate each other.
			raw, _ := json.Marshal(m)
			var copyMap = fixtureMap(t)
			if err := json.Unmarshal(raw, &copyMap); err != nil {
				t.Fatal(err)
			}
			raw, _ = json.Marshal(ov)
			var changed overlay.Operation
			if err := json.Unmarshal(raw, &changed); err != nil {
				t.Fatal(err)
			}
			n := fixtureNode(t, copyMap, id)
			body := n["requestBody"].(map[string]any)
			schema := body["content"].(map[string]any)["multipart/form-data"].(map[string]any)["schema"].(map[string]any)
			props := schema["properties"].(map[string]any)
			field := props["file-0"].(map[string]any)
			switch mutation {
			case "evidence":
				changed.Evidence = changed.Evidence[:2]
			case "optional body":
				body["required"] = false
			case "optional field":
				schema["required"] = []any{}
			case "extra field":
				props["text"] = map[string]any{"type": "string"}
			case "query collision":
				addQueryParameter(t, copyMap, id, "file-0", map[string]any{"type": "string"})
			case "query collection":
				addQueryParameter(t, copyMap, id, "query_list", map[string]any{"type": "array", "items": map[string]any{"type": "string"}})
			case "nullable object":
				schema["nullable"] = true
			case "multipart encoding":
				body["content"].(map[string]any)["multipart/form-data"].(map[string]any)["encoding"] = map[string]any{"file-0": map[string]any{"contentType": "image/png"}}
			case "nullable":
				field["nullable"] = true
			case "scalar":
				field["format"] = "byte"
			default:
				var settings map[string]any
				raw, _ := json.Marshal(changed)
				json.Unmarshal(raw, &settings)
				d := settings["multipart_uploads"].(map[string]any)
				if mutation == "start" {
					d["start"] = 1
				} else {
					d[mutation] = "wrong"
				}
				raw, _ = json.Marshal(settings)
				json.Unmarshal(raw, &changed)
			}
			selected := fixtureOverlay("listed")
			selected.Files[0].Operations[id] = changed
			_, p := buildProblems(t, copyMap, selected)
			if !strings.Contains(p, "multipart uploads") {
				t.Fatalf("expected upload rejection, got %q", p)
			}
		})
	}
}
