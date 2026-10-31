package effective

import (
	"reflect"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestFormatOnlyAlternativesPreserveOuterScalar(t *testing.T) {
	s, err := newSchemas(fixtureMap(t))
	if err != nil {
		t.Fatal(err)
	}
	// The four pinned WordPressBackup site schemas: input anyOf and response oneOf.
	for _, key := range []string{"anyOf", "oneOf"} {
		for _, reverse := range []bool{false, true} {
			domain := map[string]any{"format": "domain", "example": "example.com"}
			path := map[string]any{"format": "url-path", "example": "example.com/wordpress"}
			options := []any{domain, path}
			if reverse {
				options = []any{path, domain}
			}
			schema := map[string]any{"type": "string", key: options}
			got := s.specFor("fixture", schema, 0, nil)
			if !reflect.DeepEqual(got, TypeSpec{Type: "string"}) {
				t.Errorf("%s reverse=%v: %+v", key, reverse, got)
			}
			php, doc, encoding, composite := paramTypes(got, "")
			if php != "string" || doc != "string" || encoding != "scalar" || composite {
				t.Errorf("input: %s %s %s %v", php, doc, encoding, composite)
			}
		}
	}
	for _, tc := range []struct {
		schema map[string]any
		want   TypeSpec
	}{
		{map[string]any{"type": "string", "enum": []any{"0", "1", nil}, "oneOf": []any{map[string]any{"format": "domain"}}}, TypeSpec{Type: "flag", Nullable: true, Accept: []string{"string-flag"}}},
		{map[string]any{"type": "integer", "nullable": true, "anyOf": []any{map[string]any{"format": "int64"}}}, TypeSpec{Type: "int", Nullable: true}},
	} {
		if got := s.specFor("fixture", tc.schema, 0, nil); !reflect.DeepEqual(got, tc.want) {
			t.Errorf("enum/nullability: %+v want %+v", got, tc.want)
		}
	}
}

func TestScalarInheritanceDoesNotReinterpretOtherComposition(t *testing.T) {
	s, err := newSchemas(fixtureMap(t))
	if err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct {
		name   string
		schema map[string]any
		want   TypeSpec
	}{
		{"explicit incompatible", map[string]any{"type": "string", "oneOf": []any{map[string]any{"type": "string"}, map[string]any{"type": "integer"}}}, TypeSpec{Type: "union", AnyOf: []TypeSpec{{Type: "string"}, {Type: "int"}}}},
		{"richer typeless", map[string]any{"type": "string", "anyOf": []any{map[string]any{"format": "domain", "minLength": 1}}}, TypeSpec{Type: "mixed"}},
		{"allOf", map[string]any{"type": "string", "allOf": []any{map[string]any{"type": "integer"}}}, TypeSpec{Type: "mixed"}},
		{"no outer type", map[string]any{"oneOf": []any{map[string]any{"format": "domain"}}}, TypeSpec{Type: "mixed"}},
		{"multiple outer types", map[string]any{"type": []any{"string", "integer"}, "oneOf": []any{map[string]any{"format": "domain"}}}, TypeSpec{Type: "mixed"}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			if got := s.specFor("fixture", tc.schema, 0, nil); !reflect.DeepEqual(got, tc.want) {
				t.Fatalf("%+v want %+v", got, tc.want)
			}
		})
	}
}

func TestJSONContentQueryRemainsSerializedScalarText(t *testing.T) {
	for _, schema := range []map[string]any{
		{"type": "object", "properties": map[string]any{"color": map[string]any{"type": "string"}}},
		{"type": "array", "items": map[string]any{"type": "string"}},
		{"properties": map[string]any{"url": map[string]any{"type": "string"}}},
	} {
		m := fixtureMap(t)
		op := operationByID(t, m, "whm1:createacct")
		for _, p := range op.Variants[0].Parameters {
			if p.Name == "username" || p.Name == "password" {
				delete(p.Definition, "schema")
				p.Definition["content"] = map[string]any{"application/json": map[string]any{"schema": schema}}
			}
		}
		set := fixtureOverlay("listed")
		ov := set.Files[1].Operations["whm1:createacct"]
		ov.Params = map[string]overlay.Param{"username": {Encoding: "scalar"}, "password": {Encoding: "scalar"}}
		ov.Evidence = evidence()
		set.Files[1].Operations["whm1:createacct"] = ov
		result, problems := buildProblems(t, m, set)
		if problems != "" {
			t.Fatal(problems)
		}
		params := groupByClass(t, result, "Accounts").Operations[0].Params
		for i, p := range params[:2] {
			if p.PHPType != "string" || p.DocType != "string" || p.Encoding != "scalar" || p.Required != (i == 0) || p.Sensitive != (i == 1) {
				t.Fatalf("serialized text lost boundary: %+v", p)
			}
		}
	}
}

func TestContentQueryRejectsUnreviewedMediaAndCollectionMappings(t *testing.T) {
	for _, tc := range []struct {
		name     string
		content  map[string]any
		schema   bool
		override overlay.Param
	}{
		{"unknown media", map[string]any{"text/plain": map[string]any{"schema": map[string]any{"type": "string"}}}, false, overlay.Param{}},
		{"multiple media", map[string]any{"application/json": map[string]any{"schema": map[string]any{}}, "text/plain": map[string]any{}}, false, overlay.Param{}},
		{"schema and content", map[string]any{"application/json": map[string]any{"schema": map[string]any{}}}, true, overlay.Param{}},
		{"missing schema", map[string]any{"application/json": map[string]any{}}, false, overlay.Param{}},
		{"repeat", map[string]any{"application/json": map[string]any{"schema": map[string]any{"type": "array"}}}, false, overlay.Param{Encoding: "repeat"}},
		{"collection override", map[string]any{"application/json": map[string]any{"schema": map[string]any{"type": "array", "items": map[string]any{"type": "string"}}}}, false, overlay.Param{Collection: &overlay.Collection{Kind: "indexed", ItemType: "string"}}},
		{"type override", map[string]any{"application/json": map[string]any{"schema": map[string]any{"type": "string"}}}, false, overlay.Param{Type: "string|int"}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m := fixtureMap(t)
			op := operationByID(t, m, "whm1:createacct")
			p := op.Variants[0].Parameters[0]
			if !tc.schema {
				delete(p.Definition, "schema")
			}
			p.Definition["content"] = tc.content
			set := fixtureOverlay("listed")
			ov := set.Files[1].Operations["whm1:createacct"]
			ov.Params = map[string]overlay.Param{p.Name: tc.override}
			ov.Evidence = evidence()
			set.Files[1].Operations["whm1:createacct"] = ov
			_, problems := buildProblems(t, m, set)
			if !strings.Contains(problems, "content") {
				t.Fatalf("unreviewed content accepted: %s", problems)
			}
		})
	}
}

func TestContentQueryDoesNotPromoteUnlistedOrExcludedOperations(t *testing.T) {
	m := fixtureMap(t)
	set := fixtureOverlay("listed")
	delete(set.Files[1].Operations, "whm1:listaccts")
	for i, op := range m.Operations {
		if op.ID != "whm1:listaccts" && op.ID != "whm1:suspendacct" {
			continue
		}
		// Unsupported media would produce a problem if either operation were built.
		m.Operations[i].Variants[0].Parameters = append(op.Variants[0].Parameters, model.Parameter{
			Name: "text", In: "query", Definition: model.Object{"content": map[string]any{"text/plain": map[string]any{}}},
		})
	}
	result, problems := buildProblems(t, m, set)
	if problems != "" {
		t.Fatal(problems)
	}
	for _, g := range result.Groups {
		for _, op := range g.Operations {
			if op.ID == "whm1:listaccts" || op.ID == "whm1:suspendacct" {
				t.Fatalf("promoted operation %s", op.ID)
			}
		}
	}
	if len(result.Excluded) != 1 || result.Excluded[0].ID != "whm1:suspendacct" {
		t.Fatalf("excluded operations changed: %+v", result.Excluded)
	}
}

// These cases reach the format-only helper; a nonempty allOf is handled earlier
// by shape and is only a characterization case in the preceding table.
func TestFormatOnlyAlternativesRejectCompetingComposition(t *testing.T) {
	s, err := newSchemas(fixtureMap(t))
	if err != nil {
		t.Fatal(err)
	}
	for _, unionKey := range []string{"oneOf", "anyOf"} {
		for _, competing := range []string{"oneOf", "anyOf", "allOf", "discriminator"} {
			if competing == unionKey {
				continue
			}
			t.Run(unionKey+"/"+competing, func(t *testing.T) {
				options := []any{map[string]any{"format": "domain"}}
				node := map[string]any{"type": "string", unionKey: options, competing: []any{}}
				if s.formatOnlyScalarAlternatives("fixture", node, unionKey, options) {
					t.Fatal("competing composition accepted by format-only helper")
				}
				if got := s.specFor("fixture", node, 0, nil); !reflect.DeepEqual(got, TypeSpec{Type: "mixed"}) {
					t.Fatalf("competing composition lowered to %+v", got)
				}
			})
		}
	}
}

// Full Build must reject content queries before scalar lowering can make them
// appear compatible with inline multipart fields. Matching sibling schema also
// remains unsupported, independently of the multipart source-schema check.
func TestContentQueryCannotBecomeMultipartField(t *testing.T) {
	for _, sibling := range []bool{false, true} {
		m := fixtureMap(t)
		const id = "uapi:DomainInfo/primary_domain"
		addQueryParameter(t, m, id, "text", map[string]any{"type": "string"})
		op := operationByID(t, m, id)
		for _, p := range op.Variants[0].Parameters {
			if p.Name != "text" {
				continue
			}
			if !sibling {
				delete(p.Definition, "schema")
			}
			p.Definition["content"] = map[string]any{"application/json": map[string]any{"schema": map[string]any{"type": "object"}}}
		}
		fixtureNode(t, m, id)["requestBody"] = map[string]any{"content": map[string]any{
			"multipart/form-data": map[string]any{"schema": map[string]any{"type": "object", "properties": map[string]any{"text": map[string]any{"type": "string"}}}},
		}}
		set := fixtureOverlay("listed")
		ov := set.Files[0].Operations[id]
		ov.Body, ov.Evidence = "multipart", evidence()
		set.Files[0].Operations[id] = ov
		_, problems := buildProblems(t, m, set)
		want := "query and multipart field schemas disagree for text"
		if sibling {
			want = "content requires only application/json"
		}
		if !strings.Contains(problems, want) {
			t.Fatalf("sibling=%v: want %q, got %q", sibling, want, problems)
		}
	}
}
