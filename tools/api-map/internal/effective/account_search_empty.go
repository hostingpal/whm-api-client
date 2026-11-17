package effective

import "github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"

// Recheck the actual selected wire method/body and corrected return shape.
func (b *builder) accountSearchEmpty(op Operation, ov overlay.Operation, returns *TypeSpec) bool {
	if !ov.AccountSearchEmpty {
		return false
	}
	live := false
	for _, evidence := range ov.Evidence {
		live = live || evidence.Kind == "live"
	}
	if !live {
		b.problem(op.ID + ": account_search_empty needs live evidence")
		return false
	}
	valid := op.ID == "whm1:listaccts" && op.Family == "whm1" && op.Function == "listaccts" && op.Module == "" && op.Method == "GET" && op.Body == "" && op.Outcome == "flag" && op.Unwrap == "acct" && !ov.Void && !ov.OptionalPayload && ov.Rows == "" && !ov.EmptyWithoutData && ov.Exclude == nil
	if valid && returns != nil && returns.Type == "object" && !returns.Nullable {
		if i := fieldIndex(returns, "acct"); i >= 0 {
			field := returns.Fields[i].Spec
			if field.Type == "list" && !field.Nullable && field.Items != nil && field.Items.Type == "object" && !field.Items.Nullable {
				return true
			}
		}
	}
	b.problem(op.ID + ": account_search_empty requires WHM listaccts GET/form flag with a nonnullable acct object-list unwrap")
	return false
}
