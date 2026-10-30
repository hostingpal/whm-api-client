package effective

import (
	"encoding/json"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestBuildReviewedMultipartStringFields(t *testing.T) {
	for _, alias := range []bool{false, true} {
		m := fixtureMap(t)
		addQueryParameter(t, m, "uapi:DomainInfo/primary_domain", "key", map[string]any{"type": "string", "format": "pem-private-key"})
		wire := "key"
		setting := `{"body":"multipart"}`
		if alias {
			wire = "crt"
			setting = `{"body":"multipart","body_fields":{"crt":"key"}}`
		}
		fixtureNode(t, m, "uapi:DomainInfo/primary_domain")["requestBody"] = map[string]any{"content": map[string]any{
			"multipart/form-data": map[string]any{"schema": map[string]any{"type": "object", "properties": map[string]any{
				wire:            map[string]any{"type": "string", "format": "pem-private-key", "description": "Opaque private value."},
				"friendly_name": map[string]any{"type": "string"},
			}}},
		}}
		set := fixtureOverlay("listed")
		var ov overlay.Operation
		if err := json.Unmarshal([]byte(setting), &ov); err != nil {
			t.Fatal(err)
		}
		ov.Evidence = evidence()
		sensitive := true
		if ov.Params == nil {
			ov.Params = map[string]overlay.Param{}
		}
		key := ov.Params["key"]
		key.Sensitive = &sensitive
		ov.Params["key"] = key
		set.Files[0].Operations["uapi:DomainInfo/primary_domain"] = ov
		result, problems := buildProblems(t, m, set)
		if problems != "" {
			t.Fatal(problems)
		}
		op := groupByClass(t, result, "DomainInfo").Operations[1]
		if op.Method != "POST" || wireNames(op.Params) != "key,friendly_name" || !op.Params[0].Sensitive {
			t.Fatalf("multipart operation %+v", op)
		}
	}
}

func TestBuildRejectsUnreviewedMultipartAliasesAndNonStringFields(t *testing.T) {
	for _, tc := range []struct{ setting, field, kind, want string }{
		{`{"body":"multipart"}`, "crt", "string", "query parameter key has no matching multipart field"},
		{`{"body":"multipart","body_fields":{"missing":"key"}}`, "key", "string", "body_fields names an undocumented multipart field"},
		{`{"body":"multipart","body_fields":{"crt":"missing"}}`, "crt", "string", "body_fields target must name a documented query parameter"},
		{`{"body":"multipart"}`, "key", "integer", "multipart fields must have string schemas"},
		{`{"body":"multipart","params":{"key":{"omit":true}}}`, "key", "string", "multipart fields cannot be omitted"},
	} {
		m := fixtureMap(t)
		addQueryParameter(t, m, "uapi:DomainInfo/primary_domain", "key", map[string]any{"type": "string"})
		fixtureNode(t, m, "uapi:DomainInfo/primary_domain")["requestBody"] = map[string]any{"content": map[string]any{
			"multipart/form-data": map[string]any{"schema": map[string]any{"type": "object", "properties": map[string]any{tc.field: map[string]any{"type": tc.kind}}}},
		}}
		set := fixtureOverlay("listed")
		var ov overlay.Operation
		if err := json.Unmarshal([]byte(tc.setting), &ov); err != nil {
			t.Fatal(err)
		}
		ov.Evidence = evidence()
		sensitive := true
		if ov.Params == nil {
			ov.Params = map[string]overlay.Param{}
		}
		key := ov.Params["key"]
		key.Sensitive = &sensitive
		ov.Params["key"] = key
		set.Files[0].Operations["uapi:DomainInfo/primary_domain"] = ov
		_, problems := buildProblems(t, m, set)
		if !strings.Contains(problems, tc.want) {
			t.Fatalf("want %s in %s", tc.want, problems)
		}
	}
}
