package overlay

import (
	"strings"
	"testing"
)

func withNotes(notes string, evidence string) string {
	setting := `"notes": ` + notes + `,`
	if evidence != "" {
		setting += ` "evidence": ` + evidence + `,`
	}
	return strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", `+setting, 1)
}

func TestNotesCarryADocumentedOrObservedKind(t *testing.T) {
	set := loadWHM(t, withNotes(`[{"kind": "documented", "text": "Requires the WebServer role."}]`, ""))
	notes := set.Files[0].Operations["whm1:listaccts"].Notes
	if len(notes) != 1 || notes[0].Kind != "documented" || notes[0].Text != "Requires the WebServer role." {
		t.Fatalf("notes %+v", notes)
	}
	if problems := Validate(set); len(problems) != 0 {
		t.Fatalf("documented note needs no evidence: %v", problems)
	}
	for name, notes := range map[string]string{
		"plain string": `["Requires the WebServer role."]`,
		"unknown kind": `[{"kind": "rumour", "text": "Maybe."}]`,
		"empty text":   `[{"kind": "documented", "text": ""}]`,
		"missing kind": `[{"text": "Requires the WebServer role."}]`,
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": withNotes(notes, "")})); err == nil {
				t.Fatal("accepted an invalid note")
			}
		})
	}
}

func TestObservedNotesNeedLiveEvidence(t *testing.T) {
	observed := `[{"kind": "observed", "text": "Returns ipv6 as an empty string."}]`
	live := `[{"kind": "live", "version": "11.138.0.15", "date": "2026-10-15", "note": "observed on the test server"}]`
	for name, c := range map[string]struct {
		evidence string
		want     bool
	}{
		"no evidence":   {"", true},
		"doc evidence":  {evidenceJSON, true},
		"live evidence": {live, false},
	} {
		t.Run(name, func(t *testing.T) {
			joined := strings.Join(Validate(loadWHM(t, withNotes(observed, c.evidence))), "\n")
			if got := strings.Contains(joined, "whm1:listaccts: an observed note needs live evidence"); got != c.want {
				t.Fatalf("problem reported %v, want %v: %s", got, c.want, joined)
			}
		})
	}
}
