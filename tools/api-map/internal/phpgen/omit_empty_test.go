package phpgen

import (
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"strings"
	"testing"
)

func TestRenderExplicitOmitEmptyOnlyOnSelectedParameter(t *testing.T) {
	var param effective.Param
	if err := json.Unmarshal([]byte(`{"Wire":"tag","PHP":"tags","Encoding":"repeat","PHPType":"string|array","DocType":"string|list<string>","OmitEmpty":true}`), &param); err != nil {
		t.Fatal(err)
	}
	result := effective.Result{Groups: []effective.Group{{Family: "whm1", Name: "Examples", Class: "Examples", Accessor: "examples", Operations: []effective.Operation{{Function: "example", PHPName: "example", Constant: "EXAMPLE", Method: "POST", Outcome: "flag", Params: []effective.Param{{Wire: "pkg", PHP: "packages", Encoding: "repeat", PHPType: "string|array", DocType: "string|list<string>", Required: true}, param}}}}}}
	files := Render(result)
	spec := string(files["src/Api/Whm/Spec/ExamplesSpec.php"])
	if !strings.Contains(spec, `'tags' => ['name' => 'tag', 'encoding' => 'repeat', 'omit_empty' => true],`) {
		t.Fatalf("setting absent: %s", spec)
	}
	if !strings.Contains(spec, `'packages' => ['name' => 'pkg', 'encoding' => 'repeat'],`) {
		t.Fatalf("ordinary list changed: %s", spec)
	}
	class := string(files["src/Api/Whm/Examples.php"])
	if !strings.Contains(class, "Empty list omits it.") || !strings.Contains(class, "non-omittable list argument is empty") {
		t.Fatalf("missing scoped docs: %s", class)
	}
}
