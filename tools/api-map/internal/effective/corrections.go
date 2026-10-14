package effective

import (
	"fmt"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

type segment struct {
	name     string
	suffixes []string
}

// parsePath splits "acct[].uid" into fields with list ("[]") and map ("{}") steps.
func parsePath(path string) ([]segment, error) {
	if path == "" {
		return nil, nil
	}
	var segments []segment
	for i, part := range strings.Split(path, ".") {
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

func fieldIndex(spec *TypeSpec, name string) int {
	for i := range spec.Fields {
		if spec.Fields[i].Name == name {
			return i
		}
	}
	return -1
}

// applyCorrection changes the spec at path; "add" declares an undocumented field.
func applyCorrection(root *TypeSpec, path string, c overlay.Correction) error {
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
			if index < 0 {
				if i != len(segments)-1 || c.Add == nil {
					return fmt.Errorf("path %q: field %q is not documented", path, seg.name)
				}
				current.Fields = append(current.Fields, Field{Name: seg.name, Spec: fromOverlay(*c.Add)})
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
	if c.Type != "" {
		*current = TypeSpec{Type: c.Type, Nullable: current.Nullable}
	}
	if len(c.Accept) > 0 {
		current.Accept = append([]string(nil), c.Accept...)
	}
	if c.Nullable != nil {
		current.Nullable = *c.Nullable
	}
	if c.Preserve {
		current.Preserve = true
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

func fromOverlay(s overlay.Spec) TypeSpec {
	spec := TypeSpec{Type: s.Type, Nullable: s.Nullable, Accept: s.Accept, Preserve: s.Preserve, Required: s.Required}
	if s.Items != nil {
		items := fromOverlay(*s.Items)
		spec.Items = &items
	}
	if s.Values != nil {
		values := fromOverlay(*s.Values)
		spec.Values = &values
	}
	for _, name := range sortedKeys(s.Fields) {
		spec.Fields = append(spec.Fields, Field{Name: name, Spec: fromOverlay(s.Fields[name])})
	}
	for _, option := range s.AnyOf {
		spec.AnyOf = append(spec.AnyOf, fromOverlay(option))
	}
	return spec
}
