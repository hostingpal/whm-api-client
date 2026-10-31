package phpgen

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"strings"
	"testing"
)

func TestJSONFieldRenderStaysInParams(t *testing.T) {
	schema := effective.InputSpec{Type: "object", Fields: []effective.InputField{{Name: "label", Spec: effective.InputSpec{Type: "string"}}}}
	g := effective.Group{Family: "whm", Name: "Example", Class: "Example", Accessor: "example", Operations: []effective.Operation{{PHPName: "legacy", Constant: "LEGACY", Function: "configure", Method: "POST", Body: "form", Outcome: "flag", Params: []effective.Param{{PHP: "config", Wire: "configurations", Encoding: "scalar", PHPType: "string", DocType: "string", Required: true}}, AdditionalMethods: []effective.Method{{PHPName: "configure", Constant: "CONFIGURE", Params: []effective.Param{{PHP: "configuration", Wire: "configurations", Encoding: "json", Input: &schema, PHPType: "array", DocType: "ConfigurationInput", Required: true, Sensitive: true}}}}}}}
	f := Render(effective.Result{Groups: []effective.Group{g}})
	class := string(f["src/Api/Whm/Example.php"])
	spec := string(f["src/Api/Whm/Spec/ExampleSpec.php"])
	for _, want := range []string{"@phpstan-type ConfigurationInput array{", "label?: string", "@param ConfigurationInput $configuration", "Validated JSON value in one form field", "@throws InvalidArgumentException If a structured JSON field"} {
		if !strings.Contains(class, want) {
			t.Fatalf("missing %q: %s", want, class)
		}
	}
	for _, bad := range []string{"Validated whole JSON body", "Direct routes only"} {
		if strings.Contains(class, bad) {
			t.Fatal("body doc leaked into field")
		}
	}
	for _, want := range []string{"...self::LEGACY", "'name' => 'configurations'", "'encoding' => 'json'", "'json' => [", "'required' => true", "'schema' => [", "'type' => 'object'"} {
		if !strings.Contains(spec, want) {
			t.Fatalf("missing %q: %s", want, spec)
		}
	}
	if strings.Contains(spec, "'json_body'") {
		t.Fatal("field promoted to whole body")
	}
}
