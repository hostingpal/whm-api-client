package phpgen

import (
	"strings"
	"testing"
)

func TestRenderEmptyWithoutDataKeepsTheListResultAndDeclaresTheSpecKey(t *testing.T) {
	result := goldenResult()
	op := &result.Groups[1].Operations[0]
	op.OptionalPayload, op.EmptyWithoutData = false, true
	files := Render(result)

	spec := string(files["src/Api/Whm/Spec/AccountsSpec.php"])
	if !strings.Contains(spec, "        'empty_without_data' => true,\n") {
		t.Fatalf("spec key missing:\n%s", spec)
	}
	group := string(files["src/Api/Whm/Accounts.php"])
	if !strings.Contains(group, "Returns an empty list when the server reports success and omits data.") {
		t.Fatalf("docblock line missing:\n%s", group)
	}
	if strings.Contains(group, "Returns null when the server sends no data.") {
		t.Fatalf("a list result must not document null:\n%s", group)
	}
}
