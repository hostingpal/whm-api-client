package overlay

// The complete-response outcome exception belongs only to the reviewed account
// search. It cannot be generalized to other endpoints by attaching a boolean.
func accountSearchEmptyProblems(id, family string, op Operation) []string {
	if !op.AccountSearchEmpty {
		return nil
	}
	var problems []string
	if id != "whm1:listaccts" || family != "whm1" || op.Function != "" || (op.Variant != "" && op.Variant != "GET") || (op.Method != "" && op.Method != "GET") || op.Body != "" || op.Outcome == "unchecked" || op.Unwrap != "acct" || op.Void || op.OptionalPayload || op.Rows != "" || op.Exclude != nil || op.EmptyWithoutData {
		problems = append(problems, id+": account_search_empty requires WHM listaccts GET/form flag with acct unwrap, without other result policies")
	}
	for _, evidence := range op.Evidence {
		if evidence.Kind == "live" {
			return problems
		}
	}
	return append(problems, id+": account_search_empty needs live evidence")
}
