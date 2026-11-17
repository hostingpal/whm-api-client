package overlay

// emptyWithoutDataProblems limits the setting to an unwrapped WHM flag result,
// where only a confirmed success reaches payload handling. An unchecked outcome
// would let a failed response without data read as an empty result. Live evidence
// is mandatory because the setting exists only to accept an observed wire form.
func emptyWithoutDataProblems(id, family string, op Operation) []string {
	if !op.EmptyWithoutData {
		return nil
	}
	var problems []string
	if family != "whm1" || op.Outcome == "unchecked" || op.Unwrap == "" || op.Void || op.OptionalPayload || op.Rows != "" || op.Exclude != nil {
		problems = append(problems, id+": empty_without_data requires a WHM flag outcome and unwrap, without void, optional_payload, rows or exclude")
	}
	for _, evidence := range op.Evidence {
		if evidence.Kind == "live" {
			return problems
		}
	}
	return append(problems, id+": empty_without_data needs live evidence")
}
