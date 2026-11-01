package overlay

import (
	"strings"
	"testing"
)

const accountSearchEvidenceJSON = `[{"kind": "live", "version": "11.138.0.19", "date": "2026-11-01", "note": "data omitted with result 1"}]`

func accountSearchEmpty(settings string) string {
	return strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "account_search_empty": true, `+settings+`,`, 1)
}

func TestValidateAccountSearchEmptyNeedsLiveEvidence(t *testing.T) {
	valid := accountSearchEmpty(`"unwrap": "acct", "evidence": ` + accountSearchEvidenceJSON)
	if problems := Validate(loadWHM(t, valid)); len(problems) != 0 {
		t.Fatalf("live evidence not honoured: %v", problems)
	}
	for name, settings := range map[string]string{
		"no evidence":  `"unwrap": "acct"`,
		"doc evidence": `"unwrap": "acct", "evidence": ` + evidenceJSON,
	} {
		t.Run(name, func(t *testing.T) {
			joined := strings.Join(Validate(loadWHM(t, accountSearchEmpty(settings))), "\n")
			if !strings.Contains(joined, "whm1:listaccts: account_search_empty needs live evidence") {
				t.Fatalf("missing live evidence accepted: %s", joined)
			}
		})
	}
}

// The schema rejects most of these at load time; Validate must still hold for a
// set built in memory, and only Validate can see the file's family.
func TestValidateLimitsAccountSearchEmptyToAWHMFlagUnwrap(t *testing.T) {
	const want = "whm1:listaccts: account_search_empty requires WHM listaccts GET/form flag with acct unwrap, without other result policies"
	for name, change := range map[string]func(*File, *Operation){
		"wrong method":       func(_ *File, op *Operation) { op.Method = "POST" },
		"wrong body":         func(_ *File, op *Operation) { op.Body = "json" },
		"wrong function":     func(_ *File, op *Operation) { op.Function = "Other/listaccts" },
		"other empty policy": func(_ *File, op *Operation) { op.EmptyWithoutData = true },
		"wrong unwrap":       func(_ *File, op *Operation) { op.Unwrap = "other" },
		"no unwrap":          func(_ *File, op *Operation) { op.Unwrap = "" },
		"unchecked":          func(_ *File, op *Operation) { op.Outcome = "unchecked" },
		"void":               func(_ *File, op *Operation) { op.Void = true },
		"optional payload":   func(_ *File, op *Operation) { op.OptionalPayload = true },
		"rows":               func(_ *File, op *Operation) { op.Rows = "acct" },
		"excluded":           func(_ *File, op *Operation) { op.Exclude = &Exclusion{Reason: "r"} },
		"UAPI":               func(file *File, _ *Operation) { file.Family = "uapi" },
	} {
		t.Run(name, func(t *testing.T) {
			set := loadWHM(t, accountSearchEmpty(`"unwrap": "acct", "evidence": `+accountSearchEvidenceJSON))
			op := set.Files[0].Operations["whm1:listaccts"]
			change(&set.Files[0], &op)
			set.Files[0].Operations["whm1:listaccts"] = op
			if joined := strings.Join(Validate(set), "\n"); !strings.Contains(joined, want) {
				t.Fatalf("account_search_empty accepted: %s", joined)
			}
		})
	}
}

func TestValidateAccountSearchEmptyCannotMoveToAnotherOperation(t *testing.T) {
	set := loadWHM(t, accountSearchEmpty(`"unwrap": "acct", "evidence": `+accountSearchEvidenceJSON))
	op := set.Files[0].Operations["whm1:listaccts"]
	delete(set.Files[0].Operations, "whm1:listaccts")
	set.Files[0].Operations["whm1:accountsummary"] = op
	if joined := strings.Join(Validate(set), "\n"); !strings.Contains(joined, "whm1:accountsummary: account_search_empty requires") {
		t.Fatalf("policy on another operation accepted: %s", joined)
	}
}
