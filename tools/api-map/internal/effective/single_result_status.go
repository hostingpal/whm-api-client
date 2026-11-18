package effective

import (
	"slices"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// singleResultStatus binds the documented rebuild outcome to the selected variant
// and its effective payload. New operations need independent evidence for this policy.
func (b *builder) singleResultStatus(op Operation, v model.Variant, ov overlay.Operation, ret *TypeSpec) bool {
	if !ov.SingleResultStatus {
		return false
	}
	const field = "/responses/200/content/application~1json/schema/properties/data/items/properties/result/properties/status"
	loc := v.OperationLocator
	if loc.Pointer == nil || !boundInputEvidence(ov.Evidence, loc, *loc.Pointer+field) {
		b.problem(op.ID + ": single_result_status needs source-bound result.status evidence")
	}
	if op.ID != "uapi:ZoneEdit/resetzone" || op.Method != "POST" || op.Body != "" || op.Outcome != "flag" ||
		ov.Void || ov.OptionalPayload || op.Unwrap != "" || op.Rows != "" || ov.EmptyWithoutData || ov.AccountSearchEmpty ||
		ret == nil || ret.Type != "list" || ret.Nullable || ret.Items == nil || ret.Items.Type != "object" || ret.Items.Nullable {
		b.problem(op.ID + ": single_result_status requires the ZoneEdit/resetzone form POST with a list of rebuild rows")
		return false
	}
	result := fieldIndex(ret.Items, "result")
	if result >= 0 && slices.Contains(ret.Items.Required, "result") {
		obj := ret.Items.Fields[result].Spec
		status := fieldIndex(&obj, "status")
		if obj.Type == "object" && !obj.Nullable && status >= 0 && slices.Contains(obj.Required, "status") {
			flag := obj.Fields[status].Spec
			if flag.Type == "flag" && !flag.Nullable && len(flag.Accept) == 0 {
				return true
			}
		}
	}
	b.problem(op.ID + ": single_result_status requires a strict result.status flag")
	return false
}
