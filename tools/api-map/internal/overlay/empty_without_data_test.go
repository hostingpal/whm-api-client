package overlay

import (
	"strings"
	"testing"
)

const liveEvidenceJSON = `[{"kind": "live", "version": "11.138.0.19", "date": "2026-11-01", "note": "data omitted with result 1"}]`

func emptyWithoutData(settings string) string {
	return strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "empty_without_data": true, `+settings+`,`, 1)
}

func TestValidateEmptyWithoutDataNeedsLiveEvidence(t *testing.T) {
	valid := emptyWithoutData(`"unwrap": "acct", "evidence": ` + liveEvidenceJSON)
	if problems := Validate(loadWHM(t, valid)); len(problems) != 0 {
		t.Fatalf("live evidence not honoured: %v", problems)
	}
	for name, settings := range map[string]string{
		"no evidence":  `"unwrap": "acct"`,
		"doc evidence": `"unwrap": "acct", "evidence": ` + evidenceJSON,
	} {
		t.Run(name, func(t *testing.T) {
			joined := strings.Join(Validate(loadWHM(t, emptyWithoutData(settings))), "\n")
			if !strings.Contains(joined, "whm1:listaccts: empty_without_data needs live evidence") {
				t.Fatalf("missing live evidence accepted: %s", joined)
			}
		})
	}
}

// The schema rejects most of these at load time; Validate must still hold for a
// set built in memory, and only Validate can see the file's family.
func TestValidateLimitsEmptyWithoutDataToAWHMFlagUnwrap(t *testing.T) {
	const want = "whm1:listaccts: empty_without_data requires a WHM flag outcome and unwrap, without void, optional_payload, rows or exclude"
	for name, change := range map[string]func(*File, *Operation){
		"no unwrap":        func(_ *File, op *Operation) { op.Unwrap = "" },
		"unchecked":        func(_ *File, op *Operation) { op.Outcome = "unchecked" },
		"void":             func(_ *File, op *Operation) { op.Void = true },
		"optional payload": func(_ *File, op *Operation) { op.OptionalPayload = true },
		"rows":             func(_ *File, op *Operation) { op.Rows = "acct" },
		"excluded":         func(_ *File, op *Operation) { op.Exclude = &Exclusion{Reason: "r"} },
		"UAPI":             func(file *File, _ *Operation) { file.Family = "uapi" },
	} {
		t.Run(name, func(t *testing.T) {
			set := loadWHM(t, emptyWithoutData(`"unwrap": "acct", "evidence": `+liveEvidenceJSON))
			op := set.Files[0].Operations["whm1:listaccts"]
			change(&set.Files[0], &op)
			set.Files[0].Operations["whm1:listaccts"] = op
			if joined := strings.Join(Validate(set), "\n"); !strings.Contains(joined, want) {
				t.Fatalf("empty_without_data accepted: %s", joined)
			}
		})
	}
}
