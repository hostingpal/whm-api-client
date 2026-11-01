package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestReviewedScalarOrListMultipartUploadFamily(t *testing.T) {
	m := fixtureMap(t)
	id := "uapi:DomainInfo/primary_domain"
	node := fixtureNode(t, m, id)
	node["description"] = "A source is required by the selected upload mode."
	node["requestBody"] = map[string]any{"description": "Upload one archive as backup or list archives as backup-1, backup-2.", "content": map[string]any{"multipart/form-data": map[string]any{"schema": map[string]any{"type": "object", "properties": map[string]any{"backup": map[string]any{"type": "string", "format": "binary"}}}}}}
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
	if err := json.Unmarshal([]byte(`{"body":"multipart","multipart_upload_family":{"php":"backups","source":"backup","routes":"direct"}}`), &ov); err != nil {
		t.Fatal(err)
	}
	for _, suffix := range []string{"/description", "/requestBody/description", "/requestBody/content/multipart~1form-data/schema/properties/backup"} {
		ov.Evidence = append(ov.Evidence, overlay.Evidence{Kind: "doc", Version: "pinned", Date: "2026-10-31", Note: "Reviewed binding.", SourceSHA256: v.OperationLocator.SourceSHA256, Pointer: pointer + suffix})
	}
	addQueryParameter(t, m, id, "backup", map[string]any{"type": "string"})
	ov.Params = map[string]overlay.Param{"backup": {Omit: true}}
	for i := range m.Operations {
		if m.Operations[i].ID == id {
			for j := range m.Operations[i].Variants[0].Parameters {
				p := &m.Operations[i].Variants[0].Parameters[j]
				if p.Name == "backup" {
					p.Locator = m.Operations[i].Variants[0].OperationLocator
					loc := pointer + "/parameters/0"
					p.Locator.Pointer = &loc
					ov.Evidence = append(ov.Evidence, overlay.Evidence{Kind: "doc", Version: "pinned", Date: "2026-10-31", Note: "Separate optional server-path mode omitted.", SourceSHA256: p.Locator.SourceSHA256, Pointer: loc})
				}
			}
		}
	}

	set := fixtureOverlay("listed")
	set.Files[0].Operations[id] = ov
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	op := groupByClass(t, result, "DomainInfo").Operations[1]
	if len(op.Params) != 1 || op.Params[0].PHP != "backups" || op.Params[0].DocType != "Upload|non-empty-list<Upload>" || op.Params[0].PHPType != "Upload|array" || !op.Params[0].Sensitive || op.Params[0].Wire != "" {
		t.Fatalf("upload parameter %+v", op.Params)
	}
	for _, mutation := range []string{"evidence", "optional body", "optional field", "extra field", "query collision", "query collection", "nullable object", "multipart encoding", "nullable", "scalar", "routes", "source", "wrong hash", "wrong pointer", "missing omission", "omission evidence", "both descriptors", "backup family collision", "server path type"} {
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
			field := props["backup"].(map[string]any)
			switch mutation {
			case "server path type":
				for i := range copyMap.Operations {
					if copyMap.Operations[i].ID == id {
						for j := range copyMap.Operations[i].Variants[0].Parameters {
							p := &copyMap.Operations[i].Variants[0].Parameters[j]
							if p.Name == "backup" {
								p.Definition["schema"] = map[string]any{"type": "integer"}
							}
						}
					}
				}
			case "wrong hash":
				changed.Evidence[0].SourceSHA256 = strings.Repeat("0", 64)
			case "wrong pointer":
				changed.Evidence[0].Pointer += "/wrong"
			case "missing omission":
				changed.Params["backup"] = overlay.Param{}
			case "omission evidence":
				changed.Evidence = changed.Evidence[:len(changed.Evidence)-1]
			case "both descriptors":
				changed.MultipartUpload = &overlay.MultipartUpload{}
			case "backup family collision":
				addQueryParameter(t, copyMap, id, "backup-1", map[string]any{"type": "string"})
			case "evidence":
				changed.Evidence = changed.Evidence[:2]
			case "optional body":
				body["required"] = true
			case "optional field":
				schema["required"] = []any{"backup"}
			case "extra field":
				props["text"] = map[string]any{"type": "string"}
			case "query collision":
				addQueryParameter(t, copyMap, id, "backup", map[string]any{"type": "string"})
			case "query collection":
				addQueryParameter(t, copyMap, id, "query_list", map[string]any{"type": "array", "items": map[string]any{"type": "string"}})
			case "nullable object":
				schema["nullable"] = true
			case "multipart encoding":
				body["content"].(map[string]any)["multipart/form-data"].(map[string]any)["encoding"] = map[string]any{"backup": map[string]any{"contentType": "image/png"}}
			case "nullable":
				field["nullable"] = true
			case "scalar":
				field["format"] = "byte"
			default:
				var settings map[string]any
				raw, _ := json.Marshal(changed)
				json.Unmarshal(raw, &settings)
				d := settings["multipart_upload_family"].(map[string]any)
				d[mutation] = "wrong"
				raw, _ = json.Marshal(settings)
				json.Unmarshal(raw, &changed)
			}
			selected := fixtureOverlay("listed")
			selected.Files[0].Operations[id] = changed
			_, p := buildProblems(t, copyMap, selected)
			if !strings.Contains(p, "multipart upload family") {
				t.Fatalf("expected upload rejection, got %q", p)
			}
		})
	}
}
