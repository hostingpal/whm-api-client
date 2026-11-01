package effective

import (
	"fmt"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

// responseFields derives a payload from all documented WHM siblings. It never
// invents a data wrapper or changes the immutable source schema. Referenced 200
// roots are deliberately rejected: their provenance needs a separate design.
func (b *builder) responseFields(op model.Operation, v model.Variant, ov overlay.Operation) payloadInfo {
	fail := func(message string) payloadInfo {
		b.problem(op.ID + ": response_fields " + message)
		return payloadInfo{issue: "response_fields source projection is invalid"}
	}
	if err := overlay.ValidateResponseFields(op.Family, ov); err != nil {
		return fail(err.Error())
	}
	loc := v.OperationLocator
	if loc.Pointer == nil {
		return fail("needs an operation pointer")
	}
	pointer := *loc.Pointer + "/responses/200/content/application~1json/schema"
	bound := false
	for _, e := range ov.Evidence {
		if strings.TrimSpace(e.Note) != "" && boundInputEvidence([]overlay.Evidence{e}, loc, pointer) {
			bound = true
		}
	}
	if !bound {
		return fail("needs doc evidence matching source_sha256 and exact 200 schema pointer")
	}
	raw, err := source.Pointer(b.schemas.m.Documents[loc.SourceID], pointer)
	if err != nil {
		return fail("needs a resolvable inline 200 JSON schema")
	}
	root, ok := asObject(raw)
	if !ok || root["type"] != "object" || isNullable(root) || enumHasNull(root) {
		return fail("needs an explicit nonnullable object envelope")
	}
	for _, key := range []string{"$ref", "allOf", "anyOf", "oneOf", "discriminator"} {
		if _, present := root[key]; present {
			return fail("does not support referenced or composed root envelopes")
		}
	}
	if extra, present := root["additionalProperties"]; present && extra != false {
		return fail("cannot preserve root additionalProperties")
	}
	props, ok := asObject(root["properties"])
	if !ok {
		return fail("needs an envelope properties map")
	}
	metadata, _, _, ok := b.schemas.follow(loc.SourceID, props["metadata"])
	metaProps, hasProps := asObject(metadata["properties"])
	if !ok || !hasProps || len(metaProps) == 0 || isNullable(metadata) || enumHasNull(metadata) ||
		(metadata["type"] != nil && metadata["type"] != "object") {
		return fail("needs ordinary object metadata")
	}
	for _, key := range []string{"allOf", "anyOf", "oneOf", "discriminator"} {
		if _, present := metadata[key]; present {
			return fail("needs ordinary object metadata")
		}
	}
	if _, present := metaProps["result"]; !present {
		return fail("needs ordinary WHM metadata.result")
	}
	if _, present := props["data"]; present {
		return fail("forbids a documented data member")
	}
	selected := map[string]bool{}
	for _, name := range ov.ResponseFields {
		selected[name] = true
		if _, present := props[name]; !present {
			return fail("selects an undocumented field")
		}
	}
	if len(props) != len(selected)+1 {
		return fail("must select every documented sibling except metadata")
	}
	if err := b.schemas.projectionSchema(loc.SourceID, root, 0, nil); err != nil {
		return fail(err.Error())
	}
	spec := TypeSpec{Type: "object"}
	for _, name := range ov.ResponseFields {
		spec.Fields = append(spec.Fields, Field{Name: name, Spec: b.schemas.specFor(loc.SourceID, props[name], 0, nil)})
	}
	for _, name := range requiredNames(root, props) {
		if name != "metadata" {
			spec.Required = append(spec.Required, name)
		}
	}
	return payloadInfo{spec: &spec}
}

// projectionSchema rejects malformed/unresolved nested schemas before normal
// lowering, which otherwise intentionally degrades unresolved schemas to mixed.
// This stricter check is local to explicit response_fields projections.
func (s schemas) projectionSchema(sourceID string, node any, depth int, seen map[string]bool) error {
	obj, nextID, key, ok := s.follow(sourceID, node)
	if !ok || depth > maxDepth || (key != "" && seen[key]) {
		return fmt.Errorf("contains an unresolved or recursive schema")
	}
	if key != "" {
		seen = withSeen(seen, key, "")
	}
	if raw, present := obj["nullable"]; present {
		if _, ok := raw.(bool); !ok {
			return fmt.Errorf("contains malformed nullable setting")
		}
	}
	if raw, present := obj["type"]; present {
		kinds := []any{raw}
		if list, ok := raw.([]any); ok {
			kinds = list
		}
		if len(kinds) == 0 {
			return fmt.Errorf("contains malformed schema type")
		}
		seenTypes := map[string]bool{}
		for _, kind := range kinds {
			name, ok := kind.(string)
			if !ok || seenTypes[name] {
				return fmt.Errorf("contains malformed schema type")
			}
			switch name {
			case "object", "array", "string", "integer", "number", "boolean", "null":
			default:
				return fmt.Errorf("contains unsupported schema type")
			}
			seenTypes[name] = true
		}
	}
	props, hasProps := asObject(obj["properties"])
	if hasProps && typeName(obj) != "" && typeName(obj) != "object" {
		return fmt.Errorf("contains properties on a nonobject schema")
	}

	if _, present := obj["properties"]; present && !hasProps {
		return fmt.Errorf("contains malformed properties")
	}
	if raw, present := obj["required"]; present {
		required, ok := raw.([]any)
		if !ok {
			return fmt.Errorf("contains malformed required entries")
		}
		names := map[string]bool{}
		for _, entry := range required {
			name, ok := entry.(string)
			if !ok || names[name] {
				return fmt.Errorf("contains malformed required entries")
			}
			if _, declared := props[name]; !declared {
				return fmt.Errorf("contains undeclared required entries")
			}
			names[name] = true
		}
	}
	for _, child := range props {
		if err := s.projectionSchema(nextID, child, depth+1, seen); err != nil {
			return err
		}
	}
	if child, present := obj["items"]; present {
		if err := s.projectionSchema(nextID, child, depth+1, seen); err != nil {
			return err
		}
	}
	if child, present := obj["additionalProperties"]; present {
		if _, boolean := child.(bool); !boolean {
			if err := s.projectionSchema(nextID, child, depth+1, seen); err != nil {
				return err
			}
		}
	}
	for _, name := range []string{"allOf", "anyOf", "oneOf"} {
		if raw, present := obj[name]; present {
			parts, ok := raw.([]any)
			if !ok || len(parts) == 0 {
				return fmt.Errorf("contains malformed schema alternatives")
			}
			for _, part := range parts {
				if err := s.projectionSchema(nextID, part, depth+1, seen); err != nil {
					return err
				}
			}
		}
	}
	return nil
}

func projectionMatches(spec *TypeSpec, names []string) bool {
	if spec == nil || spec.Type != "object" || spec.Nullable || spec.Values != nil || len(spec.Fields) != len(names) {
		return false
	}
	for _, name := range names {
		if fieldIndex(spec, name) < 0 {
			return false
		}
	}
	return true
}
