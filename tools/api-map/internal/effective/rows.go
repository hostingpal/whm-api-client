package effective

import (
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// rowsPolicy is deliberately limited to source-bound UAPI object or root-list batches. It
// never changes field requiredness or the meaning of Unchecked on other calls.
func (b *builder) rowsPolicy(op model.Operation, v model.Variant, ov overlay.Operation, returns *TypeSpec) {
	if ov.Rows == "" {
		return
	}
	fail := func() {
		b.problem(op.ID + ": rows requires unchecked UAPI, a nonnullable object-list root selected by $ or declared object-list field, without void, optional_payload or unwrap")
	}
	if op.Family != "uapi" || ov.Outcome != "unchecked" || ov.Void || ov.OptionalPayload || ov.Unwrap != "" || returns == nil || returns.Nullable {
		fail()
		return
	}
	root := returns.Type == "list" && ov.Rows == "$"
	field := *returns
	if !root {
		i := fieldIndex(returns, ov.Rows)
		if returns.Type != "object" || i < 0 {
			fail()
			return
		}
		field = returns.Fields[i].Spec
	}
	if field.Type != "list" || field.Items == nil || field.Items.Type != "object" || field.Items.Nullable {
		fail()
		return
	}
	if field.Nullable && !observedNullableRows(ov) {
		b.problem(op.ID + ": nullable rows field needs a nullable correction with live evidence for that exact field")
	}
	// Only the reviewed direct result/data schema form is supported. A later
	// source representation needs its own explicit provenance decision.
	suffix := "/responses/200/content/application~1json/schema/properties/result/properties/data"
	if !root {
		suffix += "/properties/" + strings.ReplaceAll(strings.ReplaceAll(ov.Rows, "~", "~0"), "/", "~1")
	}
	if v.OperationLocator.Pointer == nil || !boundInputEvidence(ov.Evidence, v.OperationLocator, *v.OperationLocator.Pointer+suffix) {
		b.problem(op.ID + ": rows needs exact source-bound result field evidence")
	}
}

// A rows selector names one literal object field. A correction on a nested
// path or its list items cannot attest to nullability of the selected list.
func observedNullableRows(ov overlay.Operation) bool {
	for path, correction := range ov.Response {
		steps, err := parsePath(path)
		if err == nil && len(steps) == 1 && steps[0].name == ov.Rows && len(steps[0].suffixes) == 0 &&
			correction.Nullable != nil && *correction.Nullable && hasLiveObservation(correction.Evidence) {
			return true
		}
	}
	return false
}
