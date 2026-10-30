package effective

import (
	"fmt"
	"slices"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

type segment struct {
	name     string
	suffixes []string
}

// parsePath splits "acct[].uid" into fields with list ("[]") and map ("{}") steps.
// A backslash escapes a literal dot in a field name; other escapes are invalid.
// The empty path would address the payload root, which is not a field path.
func parsePath(path string) ([]segment, error) {
	if path == "" {
		return nil, fmt.Errorf("path %q is not a field path", path)
	}
	var parts []string
	var part strings.Builder
	for i := 0; i < len(path); i++ {
		switch path[i] {
		case '\\':
			if i+1 == len(path) || path[i+1] != '.' {
				return nil, fmt.Errorf("invalid escape in response path %q", path)
			}
			i++
			part.WriteByte('.')
		case '.':
			parts = append(parts, part.String())
			part.Reset()
		default:
			part.WriteByte(path[i])
		}
	}
	parts = append(parts, part.String())
	var segments []segment
	for i, part := range parts {
		seg := segment{}
		for strings.HasSuffix(part, "[]") || strings.HasSuffix(part, "{}") {
			seg.suffixes = append([]string{part[len(part)-2:]}, seg.suffixes...)
			part = part[:len(part)-2]
		}
		seg.name = part
		if seg.name == "" && i > 0 {
			return nil, fmt.Errorf("invalid response path %q", path)
		}
		segments = append(segments, seg)
	}
	return segments, nil
}

// specDefects lists, with payload paths, the parts of a final spec that the
// runtime SpecChecker rejects before every call: required names that are not
// declared fields (possible after an overlay replace or add), and containers
// without their element spec or branches.
func specDefects(spec TypeSpec, path string) []string {
	var defects []string
	label := fmt.Sprintf("payload path %q", path)
	if path == "" {
		label = "the payload root"
	}
	switch spec.Type {
	case "object":
		for _, name := range spec.Required {
			if fieldIndex(&spec, name) < 0 {
				defects = append(defects, label+" requires undeclared field "+name)
			}
		}
	case "list":
		if spec.Items == nil {
			defects = append(defects, label+" is a list without items")
		}
	case "map":
		if spec.Values == nil {
			defects = append(defects, label+" is a map without values")
		}
	case "union":
		if len(spec.AnyOf) == 0 {
			defects = append(defects, label+" is a union without branches")
		}
	}
	for _, field := range spec.Fields {
		child := field.Name
		if path != "" {
			child = path + "." + field.Name
		}
		defects = append(defects, specDefects(field.Spec, child)...)
	}
	if spec.Items != nil {
		defects = append(defects, specDefects(*spec.Items, path+"[]")...)
	}
	if spec.Values != nil {
		defects = append(defects, specDefects(*spec.Values, path+"{}")...)
	}
	for i, option := range spec.AnyOf {
		for _, defect := range specDefects(option, path) {
			defects = append(defects, fmt.Sprintf("%s (union branch %d)", defect, i+1))
		}
	}
	return defects
}

// specIssues lists the unresolved issues in a spec with their payload paths,
// in the correction path syntax; a union branch has no path of its own and is
// named after the union's path.
func specIssues(spec TypeSpec, path string) []string {
	var issues []string
	if spec.Issue != "" {
		label := fmt.Sprintf("payload path %q", path)
		if path == "" {
			label = "the payload root"
		}
		issues = append(issues, label+" "+spec.Issue)
	}
	for _, field := range spec.Fields {
		child := field.Name
		if path != "" {
			child = path + "." + field.Name
		}
		issues = append(issues, specIssues(field.Spec, child)...)
	}
	if spec.Items != nil {
		issues = append(issues, specIssues(*spec.Items, path+"[]")...)
	}
	if spec.Values != nil {
		issues = append(issues, specIssues(*spec.Values, path+"{}")...)
	}
	for i, option := range spec.AnyOf {
		for _, issue := range specIssues(option, path) {
			issues = append(issues, fmt.Sprintf("%s (union branch %d)", issue, i+1))
		}
	}
	return issues
}

func fieldIndex(spec *TypeSpec, name string) int {
	for i := range spec.Fields {
		if spec.Fields[i].Name == name {
			return i
		}
	}
	return -1
}

// applyCorrection changes the spec at path. "add" declares an undocumented
// field; "replace" swaps a documented node for an overlay type spec, keeping
// nothing from the documented node. The node's final accept and preserve
// settings must suit its type.
func applyCorrection(root *TypeSpec, path string, c overlay.Correction) error {
	if c.Replace != nil && (c.Type != "" || c.Add != nil) {
		return fmt.Errorf("path %q: replace cannot be combined with type or add", path)
	}
	if c.Type != "" && !scalarTypes[c.Type] {
		return fmt.Errorf("path %q: type %q is not a scalar type; use replace", path, c.Type)
	}
	if path == "" && c.Replace != nil && len(c.Accept) == 0 && c.Nullable == nil && !c.Preserve && c.Required == nil {
		// Only a replace may address the payload root, for a documented root
		// the engine cannot represent.
		replacement, err := fromOverlay(path, *c.Replace)
		if err != nil {
			return err
		}
		*root = replacement
		return nil
	}
	segments, err := parsePath(path)
	if err != nil {
		return err
	}
	current := root
	var parent *TypeSpec
	field := ""
	for i, seg := range segments {
		if seg.name != "" {
			if current.Type != "object" {
				return fmt.Errorf("path %q: %q is not an object field", path, seg.name)
			}
			index := fieldIndex(current, seg.name)
			if index >= 0 && i == len(segments)-1 && len(seg.suffixes) == 0 && c.Add != nil {
				return fmt.Errorf("path %q: field %q is documented; use type, accept or nullable instead of add", path, seg.name)
			}
			if index < 0 {
				if i != len(segments)-1 || c.Add == nil {
					return fmt.Errorf("path %q: field %q is not documented", path, seg.name)
				}
				added, err := fromOverlay(path, *c.Add)
				if err != nil {
					return err
				}
				current.Fields = append(current.Fields, Field{Name: seg.name, Spec: added})
				sort.Slice(current.Fields, func(a, b int) bool { return current.Fields[a].Name < current.Fields[b].Name })
				index = fieldIndex(current, seg.name)
			}
			parent, field = current, seg.name
			current = &current.Fields[index].Spec
		}
		for _, suffix := range seg.suffixes {
			if suffix == "[]" {
				if current.Items == nil {
					return fmt.Errorf("path %q: not a list", path)
				}
				current = current.Items
			} else {
				if current.Values == nil {
					return fmt.Errorf("path %q: not a map", path)
				}
				current = current.Values
			}
			parent, field = nil, ""
		}
	}
	if c.Replace != nil {
		replacement, err := fromOverlay(path, *c.Replace)
		if err != nil {
			return err
		}
		*current = replacement
	}
	if c.Type != "" {
		*current = TypeSpec{Type: c.Type, Nullable: current.Nullable}
	}
	// accept adds wire variants; tokens the schema already implies (such as
	// string-flag for a 0/1 enum documented as strings) are kept.
	for _, token := range c.Accept {
		if !slices.Contains(current.Accept, token) {
			current.Accept = append(append([]string(nil), current.Accept...), token)
		}
	}
	if c.Nullable != nil {
		current.Nullable = *c.Nullable
	}
	if c.Preserve {
		current.Preserve = true
	}
	if err := checkAccept(path, *current); err != nil {
		return err
	}
	if c.Required != nil {
		if parent == nil {
			return fmt.Errorf("path %q: required applies to object fields", path)
		}
		parent.Required = setName(parent.Required, field, *c.Required)
	}
	return nil
}

func setName(names []string, name string, present bool) []string {
	set := map[string]bool{}
	for _, existing := range names {
		set[existing] = true
	}
	if present {
		set[name] = true
	} else {
		delete(set, name)
	}
	return sortedKeys(set)
}

// scalarTypes are the types a correction's "type" may set; containers need
// their companion keys and are declared with "replace" or "add".
var scalarTypes = map[string]bool{"string": true, "int": true, "number": true, "bool": true, "flag": true, "mixed": true}

// acceptable lists the wire variants each type admits (src/Response/ShapeValidator.php).
var acceptable = map[string]map[string]bool{
	"int":    {"numeric-string": true, "integral-fixed-point-string": true},
	"number": {"numeric-string": true},
	"bool":   {"int-flag": true, "string-flag": true},
	"flag":   {"string-flag": true, "bool": true, "empty-string": true},
}

// checkAccept rejects accept tokens the spec's type does not admit and
// preserve on anything but number.
func checkAccept(path string, spec TypeSpec) error {
	if spec.Literal != nil && spec.Type != "string" {
		return fmt.Errorf("path %q: literal does not apply to type %q", path, spec.Type)
	}
	for _, token := range spec.Accept {
		if !acceptable[spec.Type][token] {
			return fmt.Errorf("path %q: accept %q does not apply to type %q", path, token, spec.Type)
		}
	}
	if spec.Preserve && spec.Type != "number" {
		return fmt.Errorf("path %q: preserve does not apply to type %q", path, spec.Type)
	}
	return nil
}

// fromOverlay converts an overlay type spec. It rejects unknown types, missing
// companion keys (list items, map values, union anyOf, object fields) and
// accept or preserve settings the type does not admit, so a hand-built overlay
// cannot yield a spec the renderer or the engine cannot handle. path locates
// the node in problem messages.
func fromOverlay(path string, s overlay.Spec) (TypeSpec, error) {
	switch {
	case !scalarTypes[s.Type] && s.Type != "list" && s.Type != "map" && s.Type != "object" && s.Type != "union":
		return TypeSpec{}, fmt.Errorf("path %q: unknown type %q", path, s.Type)
	case s.Type == "list" && s.Items == nil:
		return TypeSpec{}, fmt.Errorf("path %q: list needs items", path)
	case s.Type == "map" && s.Values == nil:
		return TypeSpec{}, fmt.Errorf("path %q: map needs values", path)
	case s.Type == "union" && len(s.AnyOf) == 0:
		return TypeSpec{}, fmt.Errorf("path %q: union needs anyOf", path)
	case s.Type == "object" && s.Fields == nil:
		return TypeSpec{}, fmt.Errorf("path %q: object needs fields", path)
	}
	spec := TypeSpec{Type: s.Type, Literal: s.Literal, Nullable: s.Nullable, Accept: s.Accept, Preserve: s.Preserve, Required: s.Required}
	if err := checkAccept(path, spec); err != nil {
		return TypeSpec{}, err
	}
	if s.Items != nil {
		items, err := fromOverlay(path+"[]", *s.Items)
		if err != nil {
			return TypeSpec{}, err
		}
		spec.Items = &items
	}
	if s.Values != nil {
		values, err := fromOverlay(path+"{}", *s.Values)
		if err != nil {
			return TypeSpec{}, err
		}
		spec.Values = &values
	}
	for _, name := range sortedKeys(s.Fields) {
		field, err := fromOverlay(path+"."+name, s.Fields[name])
		if err != nil {
			return TypeSpec{}, err
		}
		spec.Fields = append(spec.Fields, Field{Name: name, Spec: field})
	}
	for _, option := range s.AnyOf {
		converted, err := fromOverlay(path, option)
		if err != nil {
			return TypeSpec{}, err
		}
		spec.AnyOf = append(spec.AnyOf, converted)
	}
	return spec, nil
}
