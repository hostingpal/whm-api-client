package effective

import (
	"strings"
	"testing"
)

func TestSessionURLCannotMoveAnotherOperationToTheSessionHandle(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:listaccts"]
	op.SessionURL = true
	set.Files[1].Operations["whm1:listaccts"] = op
	_, problems := buildProblems(t, fixtureMap(t), set)
	if !strings.Contains(problems, "whm1:listaccts: session_url requires") {
		t.Fatalf("unexpected validation result: %q", problems)
	}
}
