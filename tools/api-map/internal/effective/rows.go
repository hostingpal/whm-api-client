package effective

import (
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// rowsPolicy is deliberately limited to source-bound UAPI object batches. It
// never changes field requiredness or the meaning of Unchecked on other calls.
func (b *builder) rowsPolicy(op model.Operation, v model.Variant, ov overlay.Operation, returns *TypeSpec) {
	if ov.Rows == "" {
		return
	}
	fail := func() {
		b.problem(op.ID + ": rows requires unchecked UAPI, a nonnullable object and declared nonnullable object-list field, without void, optional_payload or unwrap")
	}
	if op.Family != "uapi" || ov.Outcome != "unchecked" || ov.Void || ov.OptionalPayload || ov.Unwrap != "" || returns == nil || returns.Type != "object" || returns.Nullable {
		fail()
		return
	}
	i := fieldIndex(returns, ov.Rows)
	if i < 0 {
		fail()
		return
	}
	field := returns.Fields[i].Spec
	if field.Type != "list" || field.Nullable || field.Items == nil || field.Items.Type != "object" || field.Items.Nullable {
		fail()
		return
	}
	// Only the reviewed direct result/data schema form is supported. A later
	// source representation needs its own explicit provenance decision.
	if v.OperationLocator.Pointer == nil || !boundInputEvidence(ov.Evidence, v.OperationLocator,
		*v.OperationLocator.Pointer+"/responses/200/content/application~1json/schema/properties/result/properties/data/properties/"+strings.ReplaceAll(strings.ReplaceAll(ov.Rows, "~", "~0"), "/", "~1")) {
		b.problem(op.ID + ": rows needs exact source-bound result field evidence")
	}
}
