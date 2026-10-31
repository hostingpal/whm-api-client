package phpgen

import (
	"bytes"
	"encoding/json"
	"os"
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
		{`{"kind":"map","item_type":"string","allow_scalar":false,"empty":"allow","prefix":"x_"}`, "?array", "array<string, string>|null",
			`                'collection' => [
                    'kind' => 'map',
                    'item_type' => 'string',
                    'allow_scalar' => false,
                    'empty' => 'allow',
                    'prefix' => 'x_',
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
	for _, tc := range []struct{ descriptor, native, doc string }{
		{`{"kind":"indexed","item_type":"string","allow_scalar":true,"empty":"reject","prefix":"domain","separator":"-","start":0}`, "string|array", "string|non-empty-list<string>"},
		{`{"kind":"map","item_type":"string","allow_scalar":false,"empty":"allow","prefix":""}`, "array", "array<string, string>"},
	} {
		var collection overlay.Collection
		if err := json.Unmarshal([]byte(tc.descriptor), &collection); err != nil {
			t.Fatal(err)
		}
		param := effective.Param{Wire: "domain", PHP: "domain", Encoding: "collection", PHPType: tc.native, DocType: tc.doc, Required: true, Collection: &collection}
		result := effective.Result{Groups: []effective.Group{{Family: "uapi", Name: "Examples", Class: "Examples", Accessor: "examples", Operations: []effective.Operation{{Function: "example", PHPName: "example", Constant: "EXAMPLE", Method: "GET", Outcome: "flag", Params: []effective.Param{param}}}}}}
		class := string(Render(result)["src/Api/Uapi/Examples.php"])
		if param.Sensitive || !strings.Contains(class, "use SensitiveParameter;") || !strings.Contains(class, "#[SensitiveParameter]\n        "+tc.native+" $domain,") {
			t.Fatalf("nonsecret collection missing public-frame protection:\n%s", class)
		}
	}
}
