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

func TestRenderPolicyNoteWithoutChangingExecutableSpec(t *testing.T) {
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
	op := overlay.Operation{
		Name: "createAccount",
		Notes: []overlay.Note{
			{Kind: "documented", Text: "Requires a username."},
			{Kind: "observed", Text: "The reviewed response included a numeric identifier."},
		},
		Evidence: []overlay.Evidence{{Kind: "live", Version: "fixture", Date: "2026-10-31", Note: "Synthetic evidence for label validation."}},
	}
	set := overlay.Set{Config: overlay.Config{Scope: "listed"}, Files: []overlay.File{{
		Family: "whm1", Group: "Accounts", Accessor: "accounts", Operations: map[string]overlay.Operation{"whm1:createacct": op},
	}}}
	baseline, err := effective.Build(m, set)
	if err != nil || len(baseline.Problems) != 0 {
		t.Fatalf("baseline: %v %v", err, baseline.Problems)
	}
	op.Notes = append(op.Notes, overlay.Note{Kind: "policy", Text: "Uses POST for conservative credential handling."})
	set.Files[0].Operations["whm1:createacct"] = op
	if problems := overlay.Validate(set); len(problems) != 0 {
		t.Fatalf("overlay: %v", problems)
	}
	result, err := effective.Build(m, set)
	if err != nil || len(result.Problems) != 0 {
		t.Fatalf("build: %v %v", err, result.Problems)
	}
	files := Render(result)
	class := string(files["src/Api/Whm/Accounts.php"])
	for _, want := range []string{
		"Documented: Requires a username.",
		"Observed: The reviewed response included a numeric identifier.",
		"Policy: Uses POST for conservative credential handling.",
	} {
		if !strings.Contains(class, want) {
			t.Errorf("missing %q in generated PHPDoc:\n%s", want, class)
		}
	}
	path := "src/Api/Whm/Spec/AccountsSpec.php"
	if !bytes.Equal(Render(baseline)[path], files[path]) {
		t.Fatal("adding a policy note changed the executable operation spec")
	}
}
