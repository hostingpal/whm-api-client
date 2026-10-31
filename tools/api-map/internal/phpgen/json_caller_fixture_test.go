package phpgen

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

// TestJSONCallerFixtureMatchesOfficialRender keeps the executable PHP caller-mode
// fixture reproducible without adopting synthetic operations in production.
// Regenerate only this fixture with -run '^TestJSONCallerFixtureMatchesOfficialRender$' -update.
func TestJSONCallerFixtureMatchesOfficialRender(t *testing.T) {
	g := effective.Group{Family: "uapi", Name: "CallerJson", Class: "CallerJson", Accessor: "callerJson", Description: "Test-only generated JSON input methods; no production operations."}
	for _, typ := range []string{"object"} {
		s := effective.InputSpec{Type: typ}
		if typ == "object" {
			for _, name := range []string{"", "00", "01", "+1", "-0", "-01", "1.0", "1e1", " 1", "1 ", "9223372036854775808", "-9223372036854775809"} {
				s.Fields = append(s.Fields, effective.InputField{Name: name, Spec: effective.InputSpec{Type: "string"}})
				s.Required = append(s.Required, name)
			}
		}
		name := "send" + strings.ToUpper(typ[:1]) + typ[1:]
		g.Operations = append(g.Operations, effective.Operation{
			ID: "uapi:CallerJson/" + typ, VariantID: "uapi:CallerJson/" + typ + "#POST",
			Family: "uapi", Module: "CallerJson", Function: typ, PHPName: name, Constant: strings.ToUpper(typ),
			Summary: "Exercise the generated native argument boundary with a mock transport.",
			SeeURL:  "https://api.example.test/CallerJson/" + typ,
			Method:  "POST", Body: "json", Outcome: "flag",
			JSONBody: &effective.JSONBody{PHP: "body", Alias: "Body" + typ + "Input", Routes: "direct", Required: true, Schema: s},
			Params:   []effective.Param{{PHP: "body", PHPType: effective.InputNative(s), DocType: "Body" + typ + "Input", Required: true, Sensitive: true, Input: &s}},
			Returns:  &effective.TypeSpec{Type: "int"},
		})
	}
	files := Render(effective.Result{Groups: []effective.Group{g}})
	for _, path := range []string{"src/Api/Uapi/CallerJson.php", "src/Api/Uapi/Spec/CallerJsonSpec.php"} {
		fixture := filepath.Join("..", "..", "..", "..", "tests", "Fixtures", "TypedJson", strings.TrimPrefix(path, "src/Api/"))
		if *update {
			if err := os.MkdirAll(filepath.Dir(fixture), 0o755); err != nil {
				t.Fatal(err)
			}
			if err := os.WriteFile(fixture, files[path], 0o644); err != nil {
				t.Fatal(err)
			}
		}
		got, err := os.ReadFile(fixture)
		if err != nil || string(got) != string(files[path]) {
			t.Fatalf("%s must match official Render output (regenerate with the scoped -update command): %v", fixture, err)
		}
	}
}
