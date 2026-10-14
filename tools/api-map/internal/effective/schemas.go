package effective

import (
	"fmt"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/openapi"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

// maxDepth bounds schema expansion; deeper nodes become mixed.
const maxDepth = 8

type schemas struct {
	m     model.Map
	index *openapi.Index
}

func newSchemas(m model.Map) (schemas, error) {
	var index *openapi.Index
	var err error
	if len(m.Sources) == 0 {
		index, err = openapi.NewIndex(m.Documents)
	} else {
		index, err = openapi.NewBundleIndex(source.Bundle{Records: m.Sources, Documents: m.Documents})
	}
	return schemas{m: m, index: index}, err
}

func asObject(v any) (map[string]any, bool) {
	switch o := v.(type) {
	case model.Object:
		return o, true
	case map[string]any:
		return o, true
	}
	return nil, false
}

// follow resolves a $ref chain and returns the concrete object, the document it
// came from, and a cycle key for the last reference followed.
func (s schemas) follow(sourceID string, node any) (map[string]any, string, string, bool) {
	key := ""
	for range 32 {
		obj, ok := asObject(node)
		if !ok {
			return nil, sourceID, key, false
		}
		ref, isRef := obj["$ref"].(string)
		if !isRef {
			return obj, sourceID, key, true
		}
		resolved, err := s.index.Resolve(sourceID, ref)
		if err != nil || resolved.Origin.Pointer == nil {
			return nil, sourceID, key, false
		}
		sourceID = resolved.Origin.SourceID
		key = sourceID + "#" + *resolved.Origin.Pointer
		node = resolved.Value
	}
	return nil, sourceID, key, false
}

// specFor converts an OpenAPI schema node into a type spec.
func (s schemas) specFor(sourceID string, node any, depth int, seen map[string]bool) TypeSpec {
	obj, sourceID, key, ok := s.follow(sourceID, node)
	if !ok || depth > maxDepth || (key != "" && seen[key]) {
		return TypeSpec{Type: "mixed"}
	}
	if key != "" {
		next := make(map[string]bool, len(seen)+1)
		for k := range seen {
			next[k] = true
		}
		next[key] = true
		seen = next
	}
	spec := s.shape(sourceID, obj, depth, seen)
	if isNullable(obj) {
		spec.Nullable = true
	}
	return spec
}

func (s schemas) shape(sourceID string, obj map[string]any, depth int, seen map[string]bool) TypeSpec {
	if parts, ok := obj["allOf"].([]any); ok && len(parts) > 0 {
		return s.allOf(sourceID, parts, depth, seen)
	}
	for _, key := range []string{"oneOf", "anyOf"} {
		if options, ok := obj[key].([]any); ok && len(options) > 0 {
			union := TypeSpec{Type: "union"}
			for _, option := range options {
				union.AnyOf = append(union.AnyOf, s.specFor(sourceID, option, depth+1, seen))
			}
			return union
		}
	}
	if isFlagEnum(obj) {
		return TypeSpec{Type: "flag"}
	}
	switch typeName(obj) {
	case "string":
		return TypeSpec{Type: "string"}
	case "integer":
		return TypeSpec{Type: "int"}
	case "number":
		return TypeSpec{Type: "number"}
	case "boolean":
		return TypeSpec{Type: "bool"}
	case "array":
		item := TypeSpec{Type: "mixed"}
		if items, ok := obj["items"]; ok {
			item = s.specFor(sourceID, items, depth+1, seen)
		}
		return TypeSpec{Type: "list", Items: &item}
	}
	if props, ok := asObject(obj["properties"]); ok && len(props) > 0 {
		spec := TypeSpec{Type: "object"}
		for _, name := range sortedKeys(props) {
			spec.Fields = append(spec.Fields, Field{Name: name, Spec: s.specFor(sourceID, props[name], depth+1, seen)})
		}
		spec.Required = requiredNames(obj, props)
		return spec
	}
	if extra, ok := asObject(obj["additionalProperties"]); ok {
		values := s.specFor(sourceID, extra, depth+1, seen)
		return TypeSpec{Type: "map", Values: &values}
	}
	if typeName(obj) == "object" {
		return TypeSpec{Type: "map", Values: &TypeSpec{Type: "mixed"}}
	}
	return TypeSpec{Type: "mixed"}
}

func (s schemas) allOf(sourceID string, parts []any, depth int, seen map[string]bool) TypeSpec {
	merged := TypeSpec{Type: "object"}
	for _, part := range parts {
		spec := s.specFor(sourceID, part, depth+1, seen)
		if spec.Type != "object" {
			if len(parts) == 1 {
				return spec
			}
			return TypeSpec{Type: "mixed"}
		}
		merged.Fields = mergeFields(merged.Fields, spec.Fields)
		merged.Required = mergeNames(merged.Required, spec.Required)
	}
	return merged
}

// payload returns the documented success payload (WHM data, UAPI result.data),
// nil when the envelope documents none, and the operation object.
func (s schemas) payload(op model.Operation, v model.Variant) (*TypeSpec, map[string]any, error) {
	loc := v.OperationLocator
	if loc.Pointer == nil {
		return nil, nil, fmt.Errorf("%s: missing operation pointer", v.ID)
	}
	node, err := source.Pointer(s.m.Documents[loc.SourceID], *loc.Pointer)
	if err != nil {
		return nil, nil, fmt.Errorf("%s: %w", v.ID, err)
	}
	operation, ok := asObject(node)
	if !ok {
		return nil, nil, fmt.Errorf("%s: operation is not an object", v.ID)
	}
	responses, _ := asObject(operation["responses"])
	response, responseSource, _, ok := s.follow(loc.SourceID, responses["200"])
	if !ok {
		return nil, operation, nil
	}
	content, _ := asObject(response["content"])
	media, _ := asObject(content["application/json"])
	envelope, envelopeSource, _, ok := s.follow(responseSource, media["schema"])
	if !ok {
		return nil, operation, nil
	}
	props, _ := asObject(envelope["properties"])
	data, has := props["data"]
	if op.Family == "uapi" {
		if result, hasResult := props["result"]; hasResult {
			if resultObject, resultSource, _, ok := s.follow(envelopeSource, result); ok {
				resultProps, _ := asObject(resultObject["properties"])
				data, has = resultProps["data"]
				envelopeSource = resultSource
			}
		}
	}
	if !has {
		return nil, operation, nil
	}
	spec := s.specFor(envelopeSource, data, 0, nil)
	return &spec, operation, nil
}

// enumDoc renders a schema's enum as a PHPStan literal union, or "" when absent.
func (s schemas) enumDoc(sourceID string, node any) string {
	obj, _, _, ok := s.follow(sourceID, node)
	if !ok {
		return ""
	}
	values, ok := obj["enum"].([]any)
	if !ok || len(values) == 0 {
		return ""
	}
	parts := make([]string, 0, len(values))
	for _, value := range values {
		switch v := value.(type) {
		case string:
			parts = append(parts, "'"+strings.ReplaceAll(strings.ReplaceAll(v, `\`, `\\`), "'", `\'`)+"'")
		case fmt.Stringer:
			parts = append(parts, v.String())
		case float64, int:
			parts = append(parts, fmt.Sprint(v))
		default:
			return ""
		}
	}
	return strings.Join(parts, "|")
}

func typeName(obj map[string]any) string {
	switch t := obj["type"].(type) {
	case string:
		return t
	case []any:
		for _, item := range t {
			if name, ok := item.(string); ok && name != "null" {
				return name
			}
		}
	}
	return ""
}

func isNullable(obj map[string]any) bool {
	if nullable, ok := obj["nullable"].(bool); ok && nullable {
		return true
	}
	if types, ok := obj["type"].([]any); ok {
		for _, t := range types {
			if t == "null" {
				return true
			}
		}
	}
	return false
}

// isFlagEnum detects 0/1 enums, ignoring a null member of nullable enums.
func isFlagEnum(obj map[string]any) bool {
	values, ok := obj["enum"].([]any)
	if !ok {
		return false
	}
	found := map[string]bool{}
	for _, value := range values {
		if value != nil {
			found[fmt.Sprint(value)] = true
		}
	}
	return len(found) == 2 && found["0"] && found["1"]
}

func requiredNames(obj map[string]any, props map[string]any) []string {
	var names []string
	required, _ := obj["required"].([]any)
	for _, item := range required {
		if name, ok := item.(string); ok {
			if _, declared := props[name]; declared {
				names = append(names, name)
			}
		}
	}
	sort.Strings(names)
	return names
}

func sortedKeys[V any](m map[string]V) []string {
	keys := make([]string, 0, len(m))
	for key := range m {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	return keys
}

func mergeFields(base, extra []Field) []Field {
	byName := map[string]TypeSpec{}
	for _, field := range base {
		byName[field.Name] = field.Spec
	}
	for _, field := range extra {
		byName[field.Name] = field.Spec
	}
	merged := make([]Field, 0, len(byName))
	for _, name := range sortedKeys(byName) {
		merged = append(merged, Field{Name: name, Spec: byName[name]})
	}
	return merged
}

func mergeNames(base, extra []string) []string {
	set := map[string]bool{}
	for _, name := range append(append([]string{}, base...), extra...) {
		set[name] = true
	}
	return sortedKeys(set)
}
