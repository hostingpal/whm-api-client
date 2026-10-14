// Package phpgen renders the effective contract as PHP group classes, spec
// classes, handle accessor traits and a coverage summary.
package phpgen

import (
	"regexp"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

const lineWidth = 120

var shapeKey = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)

func phpString(s string) string {
	return "'" + strings.ReplaceAll(strings.ReplaceAll(s, `\`, `\\`), "'", `\'`) + "'"
}

func escapeDoc(s string) string { return strings.ReplaceAll(s, "*/", `*\/`) }

func wrap(text string, width int) []string {
	var lines []string
	current := ""
	for _, word := range strings.Fields(text) {
		switch {
		case current == "":
			current = word
		case len(current)+1+len(word) > width:
			lines = append(lines, current)
			current = word
		default:
			current += " " + word
		}
	}
	if current != "" {
		lines = append(lines, current)
	}
	return lines
}

// shape renders a PHPStan type; objects with fields span lines indented by indent.
func shape(spec effective.TypeSpec, indent string) string {
	base := baseShape(spec, indent)
	if spec.Nullable && base != "mixed" {
		base += "|null"
	}
	return base
}

func baseShape(spec effective.TypeSpec, indent string) string {
	switch spec.Type {
	case "string":
		return "string"
	case "int":
		return "int"
	case "number":
		if spec.Preserve {
			return "int|float|numeric-string"
		}
		return "int|float"
	case "bool":
		return "bool"
	case "flag":
		return "0|1"
	case "list":
		return "list<" + shape(*spec.Items, indent) + ">"
	case "map":
		return "array<array-key, " + shape(*spec.Values, indent) + ">"
	case "union":
		var parts []string
		seen := map[string]bool{}
		for _, option := range spec.AnyOf {
			part := shape(option, indent)
			if part == "mixed" {
				return "mixed"
			}
			if !seen[part] {
				seen[part] = true
				parts = append(parts, part)
			}
		}
		return strings.Join(parts, "|")
	case "object":
		if len(spec.Fields) == 0 {
			return "array<string, mixed>"
		}
		inner := indent + "    "
		lines := []string{"array{"}
		required := map[string]bool{}
		for _, name := range spec.Required {
			required[name] = true
		}
		for _, field := range spec.Fields {
			key := field.Name
			if !shapeKey.MatchString(key) {
				key = phpString(key)
			}
			key = escapeDoc(key)
			optional := "?"
			if required[field.Name] {
				optional = ""
			}
			lines = append(lines, inner+key+optional+": "+shape(field.Spec, inner)+",")
		}
		lines = append(lines, indent+"}")
		return strings.Join(lines, "\n")
	}
	return "mixed"
}

// nativeType renders the PHP return or property type for a spec. Null is
// accepted when the spec or any nested union branch accepts it; the ?T
// shorthand applies only to a single non-null type at the top level.
func nativeType(spec effective.TypeSpec) string {
	types, nullable, mixed := nativeParts(spec)
	if mixed {
		return "mixed"
	}
	if nullable {
		if len(types) == 1 {
			return "?" + types[0]
		}
		types = append(types, "null")
	}
	return strings.Join(types, "|")
}

// nativeParts returns the distinct non-null PHP types for spec, whether null is
// accepted, and whether the spec collapses to mixed. Nested unions contribute
// their parts and nullability recursively, so ?T never appears inside a union.
func nativeParts(spec effective.TypeSpec) ([]string, bool, bool) {
	switch spec.Type {
	case "string":
		return []string{"string"}, spec.Nullable, false
	case "int", "flag":
		return []string{"int"}, spec.Nullable, false
	case "number":
		types := []string{"int", "float"}
		if spec.Preserve {
			types = append(types, "string")
		}
		return types, spec.Nullable, false
	case "bool":
		return []string{"bool"}, spec.Nullable, false
	case "list", "map", "object":
		return []string{"array"}, spec.Nullable, false
	case "union":
		var types []string
		seen := map[string]bool{}
		nullable := spec.Nullable
		for _, option := range spec.AnyOf {
			optionTypes, optionNullable, optionMixed := nativeParts(option)
			if optionMixed {
				return nil, false, true
			}
			nullable = nullable || optionNullable
			for _, part := range optionTypes {
				if !seen[part] {
					seen[part] = true
					types = append(types, part)
				}
			}
		}
		return types, nullable, false
	}
	return nil, false, true
}

// isArrayLike reports whether the spec's PHPStan type is an array shape worth an alias.
func isArrayLike(spec effective.TypeSpec) bool {
	switch spec.Type {
	case "list", "map", "object":
		return true
	case "union":
		for _, option := range spec.AnyOf {
			if isArrayLike(option) {
				return true
			}
		}
	}
	return false
}

func listLiteral(values []string, indent string) string {
	quoted := make([]string, len(values))
	for i, value := range values {
		quoted[i] = phpString(value)
	}
	inline := "[" + strings.Join(quoted, ", ") + "]"
	if len(indent)+len(inline) <= 100 {
		return inline
	}
	lines := []string{"["}
	for _, value := range quoted {
		lines = append(lines, indent+"    "+value+",")
	}
	lines = append(lines, indent+"]")
	return strings.Join(lines, "\n")
}

// specLiteral renders a type spec as a PHP array literal. Scalars stay inline;
// containers break across lines with trailing commas (PHPCS TrailingArrayComma).
func specLiteral(spec effective.TypeSpec, indent string) string {
	type pair struct{ key, value string }
	inner := indent + "    "
	pairs := []pair{{"type", phpString(spec.Type)}}
	if spec.Nullable {
		pairs = append(pairs, pair{"nullable", "true"})
	}
	if len(spec.Accept) > 0 {
		pairs = append(pairs, pair{"accept", listLiteral(spec.Accept, inner)})
	}
	if spec.Preserve {
		pairs = append(pairs, pair{"preserve", "true"})
	}
	if spec.Items != nil {
		pairs = append(pairs, pair{"items", specLiteral(*spec.Items, inner)})
	}
	if spec.Values != nil {
		pairs = append(pairs, pair{"values", specLiteral(*spec.Values, inner)})
	}
	if spec.Type == "object" {
		fields := "[]"
		if len(spec.Fields) > 0 {
			lines := []string{"["}
			for _, field := range spec.Fields {
				lines = append(lines, inner+"    "+phpString(field.Name)+" => "+specLiteral(field.Spec, inner+"    ")+",")
			}
			lines = append(lines, inner+"]")
			fields = strings.Join(lines, "\n")
		}
		pairs = append(pairs, pair{"fields", fields})
		if len(spec.Required) > 0 {
			pairs = append(pairs, pair{"required", listLiteral(spec.Required, inner)})
		}
	}
	if len(spec.AnyOf) > 0 {
		lines := []string{"["}
		for _, option := range spec.AnyOf {
			lines = append(lines, inner+"    "+specLiteral(option, inner+"    ")+",")
		}
		lines = append(lines, inner+"]")
		pairs = append(pairs, pair{"anyOf", strings.Join(lines, "\n")})
	}
	parts := make([]string, len(pairs))
	for i, p := range pairs {
		parts[i] = phpString(p.key) + " => " + p.value
	}
	inline := "[" + strings.Join(parts, ", ") + "]"
	if !strings.Contains(inline, "\n") && spec.Type != "object" && len(spec.AnyOf) == 0 && len(indent)+len(inline) <= 100 {
		return inline
	}
	lines := []string{"["}
	for _, part := range parts {
		lines = append(lines, inner+part+",")
	}
	lines = append(lines, indent+"]")
	return strings.Join(lines, "\n")
}
