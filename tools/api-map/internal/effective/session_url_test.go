package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
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

func TestSessionURLContractRejectsUnsupportedSettings(t *testing.T) {
	valid := Operation{ID: "uapi:Session/create_temp_user", Module: "Session", Function: "create_temp_user",
		PHPName: "createTempUser", Method: "POST", Outcome: "flag"}
	for _, test := range []struct {
		name   string
		change func(*Operation, *overlay.Operation, **TypeSpec)
	}{
		{"flag omitted", func(_ *Operation, ov *overlay.Operation, _ **TypeSpec) { ov.SessionURL = false }},
		{"reserved method", func(op *Operation, _ *overlay.Operation, _ **TypeSpec) { op.PHPName = "responseMeta" }},
		{"GET", func(op *Operation, _ *overlay.Operation, _ **TypeSpec) { op.Method = "GET" }},
		{"other module", func(op *Operation, _ *overlay.Operation, _ **TypeSpec) { op.Module = "Other" }},
		{"other function", func(op *Operation, _ *overlay.Operation, _ **TypeSpec) { op.Function = "other" }},
		{"parameters", func(op *Operation, _ *overlay.Operation, _ **TypeSpec) { op.Params = []Param{{}} }},
		{"body", func(op *Operation, _ *overlay.Operation, _ **TypeSpec) { op.Body = "json" }},
		{"unchecked", func(op *Operation, _ *overlay.Operation, _ **TypeSpec) { op.Outcome = "unchecked" }},
		{"rows", func(_ *Operation, ov *overlay.Operation, _ **TypeSpec) { ov.Rows = "rows" }},
		{"optional", func(_ *Operation, ov *overlay.Operation, _ **TypeSpec) { ov.OptionalPayload = true }},
		{"void", func(_ *Operation, ov *overlay.Operation, _ **TypeSpec) { ov.Void = true }},
		{"empty without data", func(_ *Operation, ov *overlay.Operation, _ **TypeSpec) { ov.EmptyWithoutData = true }},
		{"unwrap", func(_ *Operation, ov *overlay.Operation, _ **TypeSpec) { ov.Unwrap = "field" }},
		{"aliases", func(_ *Operation, ov *overlay.Operation, _ **TypeSpec) {
			ov.AdditionalMethods = []overlay.AdditionalMethod{}
		}},
		{"absent payload", func(_ *Operation, _ *overlay.Operation, r **TypeSpec) { *r = nil }},
		{"scalar payload", func(_ *Operation, _ *overlay.Operation, r **TypeSpec) { *r = &TypeSpec{Type: "int"} }},
	} {
		t.Run(test.name, func(t *testing.T) {
			b := &builder{problems: map[string]bool{}}
			op, ov, returns := valid, overlay.Operation{SessionURL: true}, &TypeSpec{Type: "object"}
			if !b.sessionURL(op, ov, returns) || len(b.problems) != 0 {
				t.Fatal("valid contract rejected")
			}
			test.change(&op, &ov, &returns)
			if b.sessionURL(op, ov, returns) || len(b.problems) == 0 {
				t.Fatal("invalid contract accepted")
			}
		})
	}
}
