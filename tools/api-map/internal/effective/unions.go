package effective

import (
	"reflect"
	"sort"
)

// simplifyUnion normalizes a union so the engine's first-match rule cannot
// lose documented data:
//
//   - nested unions are flattened and branch nullability moves to the union
//     (null satisfies the union either way);
//   - two or more object branches merge into one object at the first object
//     branch's position: an all-optional object branch would otherwise match
//     every object and drop the fields only later branches declare; list
//     branches and map branches likewise merge their element specs;
//   - identical branches are removed;
//   - a single remaining branch replaces the union, and no branch at all
//     (every option admitted only null) leaves a nullable mixed.
func simplifyUnion(union TypeSpec) TypeSpec {
	nullable := union.Nullable
	var options []TypeSpec
	var flatten func(spec TypeSpec)
	flatten = func(spec TypeSpec) {
		if spec.Type == "union" {
			nullable = nullable || spec.Nullable
			for _, option := range spec.AnyOf {
				flatten(option)
			}
			return
		}
		if spec.Nullable {
			nullable = true
			spec.Nullable = false
		}
		options = append(options, spec)
	}
	for _, option := range union.AnyOf {
		flatten(option)
	}
	byType := map[string][]TypeSpec{}
	for _, option := range options {
		byType[option.Type] = append(byType[option.Type], option)
	}
	var branches []TypeSpec
	merged := map[string]bool{}
	for _, option := range options {
		if same := byType[option.Type]; len(same) > 1 && mergeable[option.Type] {
			if merged[option.Type] {
				continue
			}
			option, merged[option.Type] = mergeBranches(same), true
		}
		duplicate := false
		for _, existing := range branches {
			if reflect.DeepEqual(existing, option) {
				duplicate = true
				break
			}
		}
		if !duplicate {
			branches = append(branches, option)
		}
	}
	switch len(branches) {
	case 0:
		return TypeSpec{Type: "mixed", Nullable: true}
	case 1:
		single := branches[0]
		single.Nullable = single.Nullable || nullable
		return single
	}
	return TypeSpec{Type: "union", Nullable: nullable, AnyOf: branches}
}

// mergeable lists the branch types merged when a union has several of them:
// the engine's first match would otherwise validate every object (or every
// list or map element) against the first branch and drop the fields only
// later branches declare.
var mergeable = map[string]bool{"object": true, "list": true, "map": true}

// mergeBranches merges union branches of one mergeable type: objects field
// by field, lists and maps by combining their element specs.
func mergeBranches(branches []TypeSpec) TypeSpec {
	if branches[0].Type == "object" {
		return mergeObjects(branches)
	}
	merged := branches[0]
	for _, branch := range branches[1:] {
		merged = combineSpecs(merged, branch)
	}
	return merged
}

// mergeObjects merges object specs into one: every field of any object, with
// the specs of a field declared in several objects combined; a field is
// required only when every object requires it. The first issue is kept.
func mergeObjects(objects []TypeSpec) TypeSpec {
	merged := TypeSpec{Type: "object"}
	specs := map[string]TypeSpec{}
	requiredCount := map[string]int{}
	for _, object := range objects {
		merged.Nullable = merged.Nullable || object.Nullable
		if merged.Issue == "" {
			merged.Issue = object.Issue
		}
		for _, field := range object.Fields {
			if existing, ok := specs[field.Name]; ok {
				specs[field.Name] = combineSpecs(existing, field.Spec)
			} else {
				specs[field.Name] = field.Spec
			}
		}
		counted := map[string]bool{}
		for _, name := range object.Required {
			if !counted[name] {
				counted[name] = true
				requiredCount[name]++
			}
		}
	}
	names := make([]string, 0, len(specs))
	for name := range specs {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		merged.Fields = append(merged.Fields, Field{Name: name, Spec: specs[name]})
		if requiredCount[name] == len(objects) {
			merged.Required = append(merged.Required, name)
		}
	}
	return merged
}

// combineSpecs combines two documented specs for the same field: equal specs
// (up to nullability) stay one, objects merge, lists and maps combine their
// elements, and anything else becomes a simplified union.
func combineSpecs(a, b TypeSpec) TypeSpec {
	nullable := a.Nullable || b.Nullable
	plainA, plainB := a, b
	plainA.Nullable, plainB.Nullable = false, false
	switch {
	case reflect.DeepEqual(plainA, plainB):
		plainA.Nullable = nullable
		return plainA
	case a.Type == "object" && b.Type == "object":
		return mergeObjects([]TypeSpec{a, b})
	case a.Type == "list" && b.Type == "list" && a.Items != nil && b.Items != nil:
		items := combineSpecs(*a.Items, *b.Items)
		return TypeSpec{Type: "list", Nullable: nullable, Items: &items}
	case a.Type == "map" && b.Type == "map" && a.Values != nil && b.Values != nil:
		values := combineSpecs(*a.Values, *b.Values)
		return TypeSpec{Type: "map", Nullable: nullable, Values: &values}
	}
	return simplifyUnion(TypeSpec{Type: "union", AnyOf: []TypeSpec{a, b}})
}

// nullOnly reports whether a schema admits nothing but null (an enum whose only
// member is null, or the null type).
func nullOnly(obj map[string]any) bool {
	if values, ok := obj["enum"].([]any); ok && len(values) > 0 {
		for _, value := range values {
			if value != nil {
				return false
			}
		}
		return true
	}
	switch t := obj["type"].(type) {
	case string:
		return t == "null"
	case []any:
		for _, item := range t {
			if item != "null" {
				return false
			}
		}
		return len(t) > 0
	}
	return false
}

// enumHasNull reports whether a schema's enum lists null.
func enumHasNull(obj map[string]any) bool {
	values, _ := obj["enum"].([]any)
	for _, value := range values {
		if value == nil {
			return true
		}
	}
	return false
}

// enumHasString reports whether a schema's enum lists a string value.
func enumHasString(obj map[string]any) bool {
	values, _ := obj["enum"].([]any)
	for _, value := range values {
		if _, ok := value.(string); ok {
			return true
		}
	}
	return false
}
