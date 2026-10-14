package phpgen

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

const longClass = "LongGroupNameForCheckingLimits"

func longLimitsResult() effective.Result {
	literals := make([]string, 30)
	for i := range literals {
		literals[i] = fmt.Sprintf("'option_number_%02d_value'", i)
	}
	leaf := effective.TypeSpec{Type: "object", Fields: []effective.Field{
		{Name: "nested_field_name_that_keeps_going_to_force_wrapping_at_depth", Spec: effective.TypeSpec{Type: "string", Nullable: true}},
	}}
	mid := effective.TypeSpec{Type: "object", Fields: []effective.Field{{Name: "level_two_container_with_a_long_name", Spec: leaf}}}
	top := effective.TypeSpec{Type: "object", Fields: []effective.Field{{Name: "level_one_container_with_a_long_name", Spec: mid}}}
	return effective.Result{Groups: []effective.Group{{
		Family: "whm1", Name: longClass, Accessor: "veryLong", Class: longClass, Description: "Long things.",
		Operations: []effective.Operation{{
			ID: "whm1:long", VariantID: "whm1:long#GET", Family: "whm1", Function: "long", Method: "POST", Outcome: "flag",
			PHPName: "doLong", Constant: "LONG_OPERATION_CONSTANT_NAME_TEST", Alias: "ExtremelyLongResultAliasNameForTestingTheLimit",
			Summary: "Long operation.", SeeURL: "https://api.docs.cpanel.net/openapi/whm/operation/long",
			Params: []effective.Param{
				{Wire: "key", PHP: "key", Encoding: "scalar", PHPType: "string", DocType: strings.Join(literals, "|"),
					Description: strings.Repeat("A long description with many words. ", 8)},
				{Wire: "notify_contact_address_change_notification_disabled", PHP: "notifyContactAddressChangeNotificationDisabled",
					Encoding: "bool", PHPType: "bool", DocType: "bool", Description: "Flag."},
			},
			Returns: &top,
		}},
	}}}
}

func TestRenderKeepsLongInputsValidAndBounded(t *testing.T) {
	files := Render(longLimitsResult())
	if dir := os.Getenv("PHPGEN_SCRATCH"); dir != "" {
		for path, content := range files {
			target := filepath.Join(dir, filepath.FromSlash(path))
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				t.Fatal(err)
			}
			if err := os.WriteFile(target, content, 0o644); err != nil {
				t.Fatal(err)
			}
		}
	}
	for path, content := range files {
		if !strings.HasSuffix(path, ".php") {
			continue
		}
		for i, line := range strings.Split(string(content), "\n") {
			trimmed := strings.TrimSpace(line)
			if len(line) > lineWidth {
				t.Errorf("%s:%d is %d characters: %s", path, i+1, len(line), line)
			}
			if trimmed == "* @param" || strings.HasPrefix(trimmed, "* $") {
				t.Errorf("%s:%d splits a @param tag: %s", path, i+1, line)
			}
			if strings.HasPrefix(trimmed, "* @param") && !strings.Contains(trimmed, " $") {
				t.Errorf("%s:%d @param lacks a name: %s", path, i+1, line)
			}
		}
	}
	class := string(files["src/Api/Whm/"+longClass+".php"])
	if !strings.Contains(class, "@param string|null $key A long") {
		t.Errorf("long enum type was not replaced by the plain type:\n%s", class)
	}
}

func TestParamDocFallbackKeepsListElementTypes(t *testing.T) {
	long := strings.Repeat("'option_value'|", 10) + "'last'"
	for _, c := range []struct {
		param effective.Param
		want  string
	}{
		{effective.Param{PHP: "names", Encoding: "repeat", PHPType: "string|array", DocType: long}, "     * @param string|list<string>|null $names Names."},
		{effective.Param{PHP: "users", Encoding: "numbered", PHPType: "string|array", DocType: long, Required: true}, "     * @param string|list<string> $users Names."},
	} {
		w := &writer{}
		w.paramDoc("    ", c.param, "Names.")
		if got := strings.TrimSuffix(w.b.String(), "\n"); got != c.want {
			t.Errorf("got %q, want %q", got, c.want)
		}
	}
}
