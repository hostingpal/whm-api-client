package phpgen

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func TestRenderAccountSearchEmptyKeepsTheListResultAndDeclaresTheSpecKey(t *testing.T) {
	result := goldenResult()
	result.Groups[1].Operations = []effective.Operation{{
		ID: "whm1:listaccts", VariantID: "whm1:listaccts#GET", Family: "whm1", Function: "listaccts", Method: "GET", Outcome: "flag",
		PHPName: "listAccounts", Constant: "LISTACCTS", Alias: "ListAccountsResult", Unwrap: "acct", AccountSearchEmpty: true,
		Returns: &effective.TypeSpec{Type: "object", Fields: []effective.Field{{Name: "acct", Spec: effective.TypeSpec{
			Type: "list", Items: &effective.TypeSpec{Type: "object", Fields: []effective.Field{{Name: "user", Spec: effective.TypeSpec{Type: "string"}}}},
		}}}},
	}}
	files := Render(result)

	spec := string(files["src/Api/Whm/Spec/AccountsSpec.php"])
	if !strings.Contains(spec, "        'account_search_empty' => true,\n") {
		t.Fatalf("spec key missing:\n%s", spec)
	}
	group := string(files["src/Api/Whm/Accounts.php"])
	if !strings.Contains(group, "Returns an empty list for the evidenced account-search absence forms; response metadata retains the server") {
		t.Fatalf("docblock line missing:\n%s", group)
	}
	if strings.Contains(group, "Returns null when the server sends no data.") {
		t.Fatalf("a list result must not document null:\n%s", group)
	}
}
