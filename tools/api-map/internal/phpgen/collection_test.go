package phpgen

import (
	"bytes"
	"encoding/json"
	"os"
	"strconv"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func TestRenderExplicitCollectionNativeDocAndCompleteSpec(t *testing.T) {
	for _, tc := range []struct{ descriptor, native, doc, spec string }{
		{`{"kind":"indexed","item_type":"string","allow_scalar":true,"empty":"reject","prefix":"domain","separator":"-","start":0}`, "string|array|null", "string|non-empty-list<string>|null",
			`                'collection' => [
                    'kind' => 'indexed',
                    'item_type' => 'string',
                    'allow_scalar' => true,
                    'empty' => 'reject',
                    'prefix' => 'domain',
                    'separator' => '-',
                    'start' => 0,
                ],`},
		{`{"kind":"repeat","item_type":"int","allow_scalar":false,"empty":"reject"}`, "?array", "non-empty-list<int>|null",
			`                'collection' => [
                    'kind' => 'repeat',
                    'item_type' => 'int',
                    'allow_scalar' => false,
                    'empty' => 'reject',
                ],`},
	} {
		raw, err := os.ReadFile("../effective/testdata/fixture-map.json")
		if err != nil {
			t.Fatal(err)
		}
		var m model.Map
		decoder := json.NewDecoder(bytes.NewReader(raw))
		decoder.UseNumber()
		if err := decoder.Decode(&m); err != nil {
			t.Fatal(err)
		}
		for _, op := range m.Operations {
			if op.ID == "whm1:createacct" && strings.Contains(tc.descriptor, `"item_type":"int"`) {
				op.Variants[0].Parameters[1].Definition["schema"] = map[string]any{"type": "integer"}
			}
		}
		var file overlay.File
		raw = []byte(`{"family":"whm1","group":"Accounts","accessor":"accounts","operations":{"whm1:createacct":{"name":"createAccount","params":{"password":{"collection":` + tc.descriptor + `}},"evidence":[{"kind":"doc","version":"synthetic fixture only","date":"2026-10-31","note":"Fixture collection prose","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1"}]}}}`)
		if err := json.Unmarshal(raw, &file); err != nil {
			t.Fatal(err)
		}
		result, err := effective.Build(m, overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{file}})
		if err != nil || len(result.Problems) > 0 {
			t.Fatalf("build: %v %v", err, result.Problems)
		}
		files := Render(result)
		class := string(files["src/Api/Whm/Accounts.php"])
		if !strings.Contains(class, "@param "+tc.doc+" $password") || !strings.Contains(class, "#[SensitiveParameter]\n        "+tc.native+" $password = null,") {
			t.Fatalf("imprecise signature or docs:\n%s", class)
		}
		spec := string(files["src/Api/Whm/Spec/AccountsSpec.php"])
		complete := "            'password' => [\n                'name' => 'password',\n                'encoding' => 'collection',\n" + tc.spec + "\n            ],"
		if !strings.Contains(spec, complete) {
			t.Fatalf("incorrect complete descriptor:\n%s", spec)
		}
	}
}

func TestRenderNonsecretCollectionStillRedactsPublicFrame(t *testing.T) {
	var param effective.Param
	if err := json.Unmarshal([]byte(`{"Wire":"domain","PHP":"domain","Encoding":"collection","PHPType":"string|array","DocType":"string|non-empty-list<string>","Required":true,"collection":{"kind":"indexed","item_type":"string","allow_scalar":true,"empty":"reject","prefix":"domain","separator":"-","start":0}}`), &param); err != nil {
		t.Fatal(err)
	}
	result := effective.Result{Groups: []effective.Group{{Family: "uapi", Name: "Examples", Class: "Examples", Accessor: "examples", Operations: []effective.Operation{{Function: "example", PHPName: "example", Constant: "EXAMPLE", Method: "GET", Outcome: "flag", Params: []effective.Param{param}}}}}}
	class := string(Render(result)["src/Api/Uapi/Examples.php"])
	if param.Sensitive || !strings.Contains(class, "use SensitiveParameter;") || !strings.Contains(class, "#[SensitiveParameter]\n        string|array $domain,") {
		t.Fatalf("nonsecret collection missing public-frame protection:\n%s", class)
	}
}

func TestRenderCollectionEnumDocsKeepNullOutsideItems(t *testing.T) {
	for _, tc := range []struct {
		schema, item, doc string
		scalar            bool
	}{
		{`{"type":"string","enum":["alpha","beta"]}`, "string", "'alpha'|'beta'|non-empty-list<'alpha'|'beta'>|null", true},
		{`{"type":"array","items":{"type":"integer","enum":[2,7]}}`, "int", "non-empty-list<2|7>|null", false},
		{`{"type":"string","enum":["a'b","c\\d"]}`, "string", `'a\'b'|'c\\d'|non-empty-list<'a\'b'|'c\\d'>|null`, true},
		{`{"type":"string","nullable":true,"enum":["alpha",null]}`, "string", "'alpha'|non-empty-list<'alpha'>|null", true},
	} {
		raw, err := os.ReadFile("../effective/testdata/fixture-map.json")
		if err != nil {
			t.Fatal(err)
		}
		var m model.Map
		d := json.NewDecoder(bytes.NewReader(raw))
		d.UseNumber()
		if err = d.Decode(&m); err != nil {
			t.Fatal(err)
		}
		var schema any
		d = json.NewDecoder(strings.NewReader(tc.schema))
		d.UseNumber()
		if err = d.Decode(&schema); err != nil {
			t.Fatal(err)
		}
		for _, op := range m.Operations {
			if op.ID == "whm1:createacct" {
				op.Variants[0].Parameters[1].Definition["schema"] = schema
			}
		}
		var file overlay.File
		raw = []byte(`{"family":"whm1","group":"Accounts","accessor":"accounts","operations":{"whm1:createacct":{"name":"createAccount","params":{"password":{"collection":{"kind":"repeat","item_type":"` + tc.item + `","allow_scalar":` + strconv.FormatBool(tc.scalar) + `,"empty":"reject"}}},"evidence":[{"kind":"doc","version":"synthetic fixture only","date":"2026-10-31","note":"Fixture optional enum collection","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1"}]}}}`)
		if err = json.Unmarshal(raw, &file); err != nil {
			t.Fatal(err)
		}
		result, err := effective.Build(m, overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{file}})
		if err != nil || len(result.Problems) > 0 {
			t.Fatalf("build: %v %v", err, result.Problems)
		}
		class := string(Render(result)["src/Api/Whm/Accounts.php"])
		if !strings.Contains(class, "@param "+tc.doc+" $password") {
			t.Fatalf("literal/nullability contract lost; expected %s:\n%s", tc.doc, class)
		}
	}
}

func TestRenderCollectionCommentTerminatorRequiresExplicitEnumOverride(t *testing.T) {
	for _, override := range []bool{false, true} {
		raw, err := os.ReadFile("../effective/testdata/fixture-map.json")
		if err != nil {
			t.Fatal(err)
		}
		var m model.Map
		d := json.NewDecoder(bytes.NewReader(raw))
		d.UseNumber()
		if err = d.Decode(&m); err != nil {
			t.Fatal(err)
		}
		for _, op := range m.Operations {
			if op.ID == "whm1:createacct" {
				op.Variants[0].Parameters[1].Definition["schema"] = map[string]any{"type": "string", "enum": []any{"a*/b"}}
			}
		}
		setting := ""
		if override {
			setting = `,"enum_doc":false`
		}
		var file overlay.File
		raw = []byte(`{"family":"whm1","group":"Accounts","accessor":"accounts","operations":{"whm1:createacct":{"name":"createAccount","params":{"password":{"collection":{"kind":"repeat","item_type":"string","allow_scalar":true,"empty":"reject"}` + setting + `}},"evidence":[{"kind":"doc","version":"synthetic fixture only","date":"2026-10-31","note":"Fixture rendered terminator boundary","source_sha256":"y","pointer":"/paths/~1createacct/get/parameters/1"}]}}}`)
		if err = json.Unmarshal(raw, &file); err != nil {
			t.Fatal(err)
		}
		result, err := effective.Build(m, overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{file}})
		if err != nil {
			t.Fatal(err)
		}
		if !override {
			want := "whm1:createacct: parameter password: collection enum documentation cannot represent a string literal containing a PHPDoc comment terminator"
			if len(result.Problems) != 1 || result.Problems[0] != want {
				class := string(Render(result)["src/Api/Whm/Accounts.php"])
				t.Fatalf("generation must reject a*/b instead of rendering a false literal; problems=%v; class:\n%s", result.Problems, class)
			}
			continue
		}
		if len(result.Problems) != 0 {
			t.Fatalf("explicit enum_doc:false must preserve policy: %v", result.Problems)
		}
		class := string(Render(result)["src/Api/Whm/Accounts.php"])
		if !strings.Contains(class, "@param string|non-empty-list<string>|null $password") || strings.Contains(class, `a*\/b`) {
			t.Fatalf("explicit documentation widening must render truthful broad docs:\n%s", class)
		}
	}
}
