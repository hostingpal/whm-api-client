package phpgen

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func TestScalarTargetGuardImportsItsDeclaredException(t *testing.T) {
	for _, guarded := range []bool{false, true} {
		result := effective.Result{Groups: []effective.Group{{Family: "whm1", Name: "Example", Class: "Example", Accessor: "example", Operations: []effective.Operation{{Function: "delete_item", PHPName: "deleteItem", Constant: "DELETE_ITEM", Method: "POST", Outcome: "flag", Params: []effective.Param{{Wire: "path", PHP: "path", Encoding: "scalar", PHPType: "string", DocType: "string", Required: true, RejectEmpty: guarded}}}}}}}
		group := string(Render(result)["src/Api/Whm/Example.php"])
		hasThrow := strings.Contains(group, "@throws InvalidArgumentException")
		hasImport := strings.Contains(group, "use InvalidArgumentException;")
		if hasThrow != guarded || hasImport != guarded {
			t.Fatalf("guarded=%v declares throw=%v imports global Throwable=%v", guarded, hasThrow, hasImport)
		}
		if !strings.Contains(group, "function deleteItem(string $path): void") {
			t.Fatal("scalar signature changed")
		}
	}
}
