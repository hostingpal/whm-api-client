package effective

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// emptyWithoutData requires the unwrapped field to be a nonnullable list, the only
// result an omitted data member can stand for without inventing other values.
// Overlay validation has already limited the family, outcome and evidence.
func (b *builder) emptyWithoutData(op model.Operation, ov overlay.Operation, returns *TypeSpec) bool {
	if !ov.EmptyWithoutData {
		return false
	}
	if returns != nil && returns.Type == "object" && !returns.Nullable {
		if i := fieldIndex(returns, ov.Unwrap); i >= 0 {
			if field := returns.Fields[i].Spec; field.Type == "list" && !field.Nullable {
				return true
			}
		}
	}
	b.problem(op.ID + ": empty_without_data requires unwrap of a nonnullable list in a nonnullable object payload")
	return false
}
