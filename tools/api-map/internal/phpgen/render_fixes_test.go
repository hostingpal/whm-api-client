package phpgen

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func TestParamDocEscapesCommentTerminatorsInTypes(t *testing.T) {
	for _, p := range []effective.Param{
		{PHP: "mode", Encoding: "scalar", PHPType: "string", DocType: "'a*/b'|'c'", Required: true},
		{PHP: "mode", Encoding: "scalar", PHPType: "string", DocType: "'*/" + strings.Repeat("x", 120) + "'"},
	} {
		w := &writer{}
		w.paramDoc("    ", p, "Ends */ here.")
		if strings.Contains(w.b.String(), "*/") {
			t.Errorf("docblock terminator in %q", w.b.String())
		}
	}
}

func TestShapeDeduplicatesUnionMembers(t *testing.T) {
	str, nullableStr := effective.TypeSpec{Type: "string"}, effective.TypeSpec{Type: "string", Nullable: true}
	cases := map[string]effective.TypeSpec{
		"string|null":     {Type: "union", AnyOf: []effective.TypeSpec{str, nullableStr}},
		"string|int|null": {Type: "union", AnyOf: []effective.TypeSpec{{Type: "union", AnyOf: []effective.TypeSpec{str, {Type: "int", Nullable: true}}}, str}},
		"string|int":      {Type: "union", AnyOf: []effective.TypeSpec{str, {Type: "int"}, str}},
		"int|string|null": {Type: "union", Nullable: true, AnyOf: []effective.TypeSpec{{Type: "int"}, nullableStr}},
		"mixed":           {Type: "union", AnyOf: []effective.TypeSpec{str, {Type: "mixed"}}},
	}
	for want, spec := range cases {
		if got := shape(spec, ""); got != want {
			t.Errorf("shape(%+v) = %q, want %q", spec, got, want)
		}
	}
}

func TestRenderAlwaysRendersBothGroupTraits(t *testing.T) {
	result := goldenResult()
	result.Groups = result.Groups[:1]
	files := Render(result)
	trait, ok := files["src/Api/Whm/WhmGroups.php"]
	if !ok {
		t.Fatal("a family without groups lost its trait")
	}
	if !strings.Contains(string(trait), "trait WhmGroups\n{\n}\n") || !strings.Contains(string(trait), Marker) {
		t.Fatalf("empty trait:\n%s", trait)
	}
	if _, ok := files["src/Api/Uapi/UapiGroups.php"]; !ok {
		t.Fatal("UAPI trait missing")
	}
}

func TestFamilyDirsCoverEveryRenderedFolder(t *testing.T) {
	dirs := FamilyDirs()
	for path := range Render(goldenResult()) {
		if !strings.HasSuffix(path, ".php") {
			continue
		}
		covered := false
		for _, dir := range dirs {
			covered = covered || strings.HasPrefix(path, dir+"/")
		}
		if !covered {
			t.Errorf("%s is outside the generated folders %v", path, dirs)
		}
	}
}

func TestRenderCarriesExplicitMultipartBodyToRuntimeSpec(t *testing.T) {
	result := goldenResult()
	result.Groups[0].Operations[0].Body = "multipart"
	files := Render(result)
	body := string(files["src/Api/Uapi/Spec/DomainInfoSpec.php"])
	if !strings.Contains(body, "'body' => 'multipart'") {
		t.Fatal("missing multipart body format in generated spec")
	}
}
