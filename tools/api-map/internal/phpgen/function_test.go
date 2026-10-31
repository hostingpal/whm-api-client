package phpgen

import (
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"testing"
)

func TestQualifiedWireFunctionKeepsCanonicalDocumentationAndChangesEffectiveHash(t *testing.T) {
	result := goldenResult()
	op := &result.Groups[1].Operations[0]
	before, _ := json.Marshal(op)
	op.Function = "Example/createacct"
	after, _ := json.Marshal(op)
	if sha256.Sum256(before) == sha256.Sum256(after) {
		t.Fatal("wire change missing from effective contract hash")
	}
	files := Render(result)
	spec := files["src/Api/Whm/Spec/AccountsSpec.php"]
	if !bytes.Contains(spec, []byte("'function' => 'Example/createacct'")) {
		t.Fatal("qualified request spec lost")
	}
	group := files["src/Api/Whm/Accounts.php"]
	for _, want := range []string{"API-map identity: whm1:createacct#GET (wire name Example/createacct", "https://api.docs.cpanel.net/specifications/whm.openapi/account-creation/accounts-createacct", "function createAccount("} {
		if !bytes.Contains(group, []byte(want)) {
			t.Fatalf("canonical docs/public name lost: %s", want)
		}
	}
}
