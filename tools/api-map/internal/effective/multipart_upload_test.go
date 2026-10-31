package effective

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"strings"
	"testing"
)

func TestReviewedWhmScalarUploadAndRequiredText(t *testing.T) {
	m := fixtureMap(t)
	id := "whm1:createacct"
	node := fixtureNode(t, m, id)
	delete(node, "parameters")
	node["requestBody"] = map[string]any{"content": map[string]any{"multipart/form-data": map[string]any{"schema": map[string]any{"type": "object", "required": []any{"module", "file"}, "properties": map[string]any{"module": map[string]any{"type": "string", "enum": []any{"Basic", "Main"}}, "file": map[string]any{"type": "string", "format": "binary"}}}}}}
	var ov overlay.Operation
	if err := json.Unmarshal([]byte(`{"name":"createAccount","body":"multipart","multipart_upload":{"php":"file","source":"file","text":"module"}}`), &ov); err != nil {
		t.Fatal(err)
	}
	for i := range m.Operations {
		if m.Operations[i].ID == id {
			m.Operations[i].Variants[0].Parameters = nil
			m.Operations[i].Variants[0].Method = "POST"
			v := m.Operations[i].Variants[0]
			for _, suffix := range []string{"/requestBody/content/multipart~1form-data/schema/properties/file", "/requestBody/content/multipart~1form-data/schema/properties/module", "/requestBody/content/multipart~1form-data/schema/required"} {
				ov.Evidence = append(ov.Evidence, overlay.Evidence{Kind: "doc", Version: "pinned", Date: "2026-10-31", Note: "Reviewed required fields despite absent body requiredness.", SourceSHA256: v.OperationLocator.SourceSHA256, Pointer: *v.OperationLocator.Pointer + suffix})
			}
		}
	}
	set := fixtureOverlay("listed")
	set.Files[1].Operations[id] = ov
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	op := groupByClass(t, result, "Accounts").Operations[0]
	if len(op.Params) != 2 || op.Params[0].PHP != "module" || op.Params[0].DocType != "'Basic'|'Main'" || op.Params[1].PHPType != "Upload" || !op.Params[0].Required || !op.Params[0].Sensitive || !op.Params[1].Required || !op.Params[1].Sensitive {
		t.Fatalf("scalar contract %+v", op.Params)
	}
	for _, mutation := range []string{"evidence", "optional field", "extra field", "nullable", "nonbinary", "query", "both descriptors", "text enum", "body required", "wrong hash", "wrong pointer", "text nullable", "multipart encoding", "unsafe php"} {
		t.Run(mutation, func(t *testing.T) {
			raw, _ := json.Marshal(m)
			copyMap := fixtureMap(t)
			json.Unmarshal(raw, &copyMap)
			raw, _ = json.Marshal(ov)
			var changed overlay.Operation
			json.Unmarshal(raw, &changed)
			n := fixtureNode(t, copyMap, id)
			body := n["requestBody"].(map[string]any)
			schema := body["content"].(map[string]any)["multipart/form-data"].(map[string]any)["schema"].(map[string]any)
			props := schema["properties"].(map[string]any)
			switch mutation {
			case "body required":
				body["required"] = true
			case "wrong hash":
				changed.Evidence[0].SourceSHA256 = strings.Repeat("0", 64)
			case "wrong pointer":
				changed.Evidence[0].Pointer += "/wrong"
			case "text nullable":
				props["module"].(map[string]any)["nullable"] = true
			case "multipart encoding":
				body["content"].(map[string]any)["multipart/form-data"].(map[string]any)["encoding"] = map[string]any{}
			case "unsafe php":
				changed.MultipartUpload.PHP = "result"
			case "evidence":
				changed.Evidence = changed.Evidence[:2]
			case "optional field":
				schema["required"] = []any{"file"}
			case "extra field":
				props["extra"] = map[string]any{"type": "string"}
			case "nullable":
				props["file"].(map[string]any)["nullable"] = true
			case "nonbinary":
				delete(props["file"].(map[string]any), "format")
			case "query":
				addQueryParameter(t, copyMap, id, "query", map[string]any{"type": "string"})
			case "both descriptors":
				changed.MultipartUploads = &overlay.MultipartUploads{}
			case "text enum":
				props["module"].(map[string]any)["enum"] = []any{"wrong"}
			}
			selected := fixtureOverlay("listed")
			selected.Files[1].Operations[id] = changed
			_, p := buildProblems(t, copyMap, selected)
			if !strings.Contains(p, "multipart upload") {
				t.Fatalf("expected rejection, got %q", p)
			}
		})
	}
}
