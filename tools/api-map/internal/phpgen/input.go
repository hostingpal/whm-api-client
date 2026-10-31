package phpgen

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"strings"
)

func inputShape(s effective.InputSpec, indent string) string {
	var result string
	switch s.Type {
	case "flag", "bool":
		result = "bool"
	case "number":
		result = "int|float"
	case "string":
		result = "string"
		if s.Nonempty {
			result = "non-empty-string"
		}
	case "list":
		prefix := "list"
		if s.Nonempty {
			prefix = "non-empty-list"
		}
		result = prefix + "<" + inputShape(*s.Items, indent) + ">"
	case "object":
		if len(s.Fields) == 0 && s.Values == nil {
			result = "array{}"
			break
		}
		lines := []string{"array{"}
		inner := indent + "    "
		required := map[string]bool{}
		for _, name := range s.Required {
			required[name] = true
		}
		for _, f := range s.Fields {
			name := f.Name
			if !shapeKey.MatchString(name) {
				name = phpString(name)
			}
			optional := "?"
			if required[f.Name] {
				optional = ""
			}
			lines = append(lines, inner+escapeDoc(name)+optional+": "+inputShape(f.Spec, inner)+",")
		}
		if s.Values != nil {
			lines = append(lines, inner+"...<string, "+inputShape(*s.Values, inner)+">")
		}
		lines = append(lines, indent+"}")
		result = strings.Join(lines, "\n")
	case "union":
		var parts []string
		for _, a := range s.AnyOf {
			parts = append(parts, inputShape(a, indent))
		}
		result = strings.Join(parts, "|")
	default:
		result = s.Type
	}
	if s.Nullable && s.Type != "null" {
		result += "|null"
	}
	return result
}

// inputLiteral emits only the strict request grammar; no response accepted variants.
func inputLiteral(s effective.InputSpec, indent string) string {
	inner := indent + "    "
	parts := []string{"'type' => " + phpString(s.Type)}
	if s.Nullable {
		parts = append(parts, "'nullable' => true")
	}
	if s.Nonempty {
		parts = append(parts, "'nonempty' => true")
	}
	if s.Items != nil {
		parts = append(parts, "'items' => "+inputLiteral(*s.Items, inner))
	}
	if s.Values != nil {
		parts = append(parts, "'values' => "+inputLiteral(*s.Values, inner))
	}
	if s.Type == "object" {
		fields := "[]"
		if len(s.Fields) > 0 {
			lines := []string{"["}
			for _, f := range s.Fields {
				lines = append(lines, inner+"    "+phpString(f.Name)+" => "+inputLiteral(f.Spec, inner+"    ")+",")
			}
			lines = append(lines, inner+"]")
			fields = strings.Join(lines, "\n")
		}
		parts = append(parts, "'fields' => "+fields)
		if len(s.Required) > 0 {
			parts = append(parts, "'required' => "+listLiteral(s.Required, inner, len(inner)+14))
		}
	}
	if len(s.AnyOf) > 0 {
		lines := []string{"["}
		for _, a := range s.AnyOf {
			lines = append(lines, inner+"    "+inputLiteral(a, inner+"    ")+",")
		}
		lines = append(lines, inner+"]")
		parts = append(parts, "'anyOf' => "+strings.Join(lines, "\n"))
	}
	inline := "[" + strings.Join(parts, ", ") + "]"
	if !strings.Contains(inline, "\n") && len(indent)+len(inline)+20 <= lineWidth && s.Type != "object" {
		return inline
	}
	lines := []string{"["}
	for _, p := range parts {
		lines = append(lines, inner+p+",")
	}
	lines = append(lines, indent+"]")
	return strings.Join(lines, "\n")
}
