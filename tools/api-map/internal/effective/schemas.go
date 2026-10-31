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

// flatMarker marks a schema key whose discriminator mapping is being expanded:
// the mapped schemas usually extend the base through allOf, and inside them the
// base is read as a plain object instead of being expanded again.
const flatMarker = "flat|"

// withSeen returns a copy of seen with the given keys added and removed.
func withSeen(seen map[string]bool, add string, remove string) map[string]bool {
	next := make(map[string]bool, len(seen)+1)
	for k := range seen {
		if k != remove {
			next[k] = true
		}
	}
	if add != "" {
		next[add] = true
	}
	return next
}

// specFor converts an OpenAPI schema node into a type spec. A nullable schema,
// a type list with "null" and an enum with a null member all make it nullable.
func (s schemas) specFor(sourceID string, node any, depth int, seen map[string]bool) TypeSpec {
	obj, sourceID, key, ok := s.follow(sourceID, node)
	if !ok || depth > maxDepth || (key != "" && seen[key]) {
		return TypeSpec{Type: "mixed"}
	}
	flat := key != "" && seen[flatMarker+key]
	if key != "" {
		seen = withSeen(seen, key, "")
	}
	spec := s.shape(sourceID, obj, key, flat, depth, seen)
	if isNullable(obj) || enumHasNull(obj) {
		spec.Nullable = true
	}
	return spec
}

// shape converts a resolved schema object; key identifies it when it was
// reached through a reference, and flat suppresses discriminator expansion.
func (s schemas) shape(sourceID string, obj map[string]any, key string, flat bool, depth int, seen map[string]bool) TypeSpec {
	if parts, ok := obj["allOf"].([]any); ok && len(parts) > 0 {
		return s.allOf(sourceID, obj, parts, depth, seen)
	}
	for _, unionKey := range []string{"oneOf", "anyOf"} {
		if options, ok := obj[unionKey].([]any); ok && len(options) > 0 {
			return s.union(sourceID, options, depth, seen)
		}
	}
	if targets := discriminatorTargets(obj); len(targets) > 0 && !flat {
		return s.discriminated(sourceID, obj, key, targets, depth, seen)
	}
	if isFlagEnum(obj) {
		// A 0/1 enum documented as strings arrives as "0"/"1"; it is accepted
		// and normalized to the 0|1 flag like any other flag.
		if typeName(obj) == "string" || enumHasString(obj) {
			return TypeSpec{Type: "flag", Accept: []string{"string-flag"}}
		}
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
		if _, dynamic := asObject(obj["additionalProperties"]); dynamic {
			// The engine's objects drop undeclared keys and its maps type every
			// value alike, so neither keeps both the declared fields and the
			// dynamic keys.
			spec.Issue = "declares properties and additionalProperties, which the engine cannot represent together"
		}
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

// allOf merges the parts of an allOf together with the schema's own sibling
// keywords (properties and required beside allOf). Parts that carry only
// annotations (description, title, example, nullable, a bare object type)
// constrain nothing and are skipped, but their required names still apply. A
// single contributing part is returned as is; several must all be objects.
func (s schemas) allOf(sourceID string, obj map[string]any, parts []any, depth int, seen map[string]bool) TypeSpec {
	sibling := map[string]any{}
	for k, v := range obj {
		if k != "allOf" {
			sibling[k] = v
		}
	}
	var contributing []any
	var required []string
	for _, part := range append(append([]any{}, parts...), sibling) {
		resolved, _, _, ok := s.follow(sourceID, part)
		if ok {
			required = append(required, stringList(resolved["required"])...)
			if !contributes(resolved) {
				continue
			}
		}
		contributing = append(contributing, part)
	}
	if len(contributing) == 0 {
		return TypeSpec{Type: "mixed"}
	}
	// A referenced part is extended here, so its discriminator mapping (a list
	// of its own subtypes, possibly including this schema) is not expanded:
	// the part contributes its own properties only.
	partSeen := func(part any) map[string]bool {
		if _, _, key, ok := s.follow(sourceID, part); ok && key != "" {
			return withSeen(seen, flatMarker+key, "")
		}
		return seen
	}
	if len(contributing) == 1 && len(required) == 0 {
		return s.specFor(sourceID, contributing[0], depth+1, partSeen(contributing[0]))
	}
	merged := TypeSpec{Type: "object"}
	for _, part := range contributing {
		spec := s.specFor(sourceID, part, depth+1, partSeen(part))
		if spec.Type != "object" {
			if len(contributing) == 1 {
				return spec
			}
			return TypeSpec{Type: "mixed"}
		}
		merged.Fields = mergeFields(merged.Fields, spec.Fields)
		merged.Required = mergeNames(merged.Required, spec.Required)
		if merged.Issue == "" {
			merged.Issue = spec.Issue
		}
	}
	for _, name := range required {
		if fieldIndex(&merged, name) >= 0 {
			merged.Required = mergeNames(merged.Required, []string{name})
		}
	}
	return merged
}

// contributes reports whether a schema constrains its value beyond
// annotations: a bare "type": "object" adds nothing inside an allOf.
func contributes(obj map[string]any) bool {
	for _, key := range []string{"$ref", "properties", "items", "allOf", "oneOf", "anyOf", "enum", "additionalProperties"} {
		if _, ok := obj[key]; ok {
			return true
		}
	}
	name := typeName(obj)
	return name != "" && name != "object"
}

// union converts oneOf/anyOf options. An option that admits only null makes
// the union nullable instead of becoming a mixed branch.
func (s schemas) union(sourceID string, options []any, depth int, seen map[string]bool) TypeSpec {
	union := TypeSpec{Type: "union"}
	for _, option := range options {
		if resolved, _, _, ok := s.follow(sourceID, option); ok && nullOnly(resolved) {
			union.Nullable = true
			continue
		}
		union.AnyOf = append(union.AnyOf, s.specFor(sourceID, option, depth+1, seen))
	}
	return simplifyUnion(union)
}

// discriminated expands a base schema whose discriminator maps values to other
// schemas (without a oneOf or anyOf listing them) into the union of the base
// and the mapped schemas, which simplifyUnion merges into one object. Inside
// the mapped schemas the base is read flat, so their allOf can extend it.
func (s schemas) discriminated(sourceID string, obj map[string]any, key string, targets []string, depth int, seen map[string]bool) TypeSpec {
	union := TypeSpec{Type: "union", AnyOf: []TypeSpec{s.shape(sourceID, obj, key, true, depth, seen)}}
	targetSeen := seen
	if key != "" {
		targetSeen = withSeen(seen, flatMarker+key, key)
	}
	for _, target := range targets {
		union.AnyOf = append(union.AnyOf, s.specFor(sourceID, map[string]any{"$ref": target}, depth+1, targetSeen))
	}
	return simplifyUnion(union)
}

// discriminatorTargets lists the distinct references of a discriminator
// mapping, sorted. A bare schema name refers to #/components/schemas/<name>.
func discriminatorTargets(obj map[string]any) []string {
	discriminator, _ := asObject(obj["discriminator"])
	mapping, _ := asObject(discriminator["mapping"])
	set := map[string]bool{}
	for _, value := range mapping {
		ref, ok := value.(string)
		if !ok || ref == "" {
			continue
		}
		if !strings.Contains(ref, "#") && !strings.Contains(ref, "/") {
			ref = "#/components/schemas/" + ref
		}
		set[ref] = true
	}
	return sortedKeys(set)
}

func stringList(v any) []string {
	items, _ := v.([]any)
	var names []string
	for _, item := range items {
		if name, ok := item.(string); ok {
			names = append(names, name)
		}
	}
	return names
}

// operationNode returns the operation object a variant points to, or nil when
// the locator does not resolve to an object.
func (s schemas) operationNode(v model.Variant) map[string]any {
	loc := v.OperationLocator
	if loc.Pointer == nil {
		return nil
	}
	node, err := source.Pointer(s.m.Documents[loc.SourceID], *loc.Pointer)
	if err != nil {
		return nil
	}
	operation, _ := asObject(node)
	return operation
}

// payload returns the documented success payload (WHM data, UAPI result.data),
// and the operation object. A nil spec without an issue means the envelope
// documents no data member (a void operation).
func (s schemas) payload(op model.Operation, v model.Variant) (payloadInfo, map[string]any, error) {
	loc := v.OperationLocator
	if loc.Pointer == nil {
		return payloadInfo{}, nil, fmt.Errorf("%s: missing operation pointer", v.ID)
	}
	node, err := source.Pointer(s.m.Documents[loc.SourceID], *loc.Pointer)
	if err != nil {
		return payloadInfo{}, nil, fmt.Errorf("%s: %w", v.ID, err)
	}
	operation, ok := asObject(node)
	if !ok {
		return payloadInfo{}, nil, fmt.Errorf("%s: operation is not an object", v.ID)
	}
	responses, _ := asObject(operation["responses"])
	response, responseSource, _, ok := s.follow(loc.SourceID, responses["200"])
	if !ok {
		return payloadInfo{issue: "documents no resolvable 200 response"}, operation, nil
	}
	content, _ := asObject(response["content"])
	media, ok := asObject(content["application/json"])
	if !ok {
		return payloadInfo{issue: "documents no JSON 200 response"}, operation, nil
	}
	envelope, envelopeSource, _, ok := s.follow(responseSource, media["schema"])
	if !ok {
		return payloadInfo{issue: "the 200 response schema cannot be resolved"}, operation, nil
	}
	return s.envelopeData(op, envelopeSource, envelope), operation, nil
}

// payloadInfo is the documented success payload. partial marks data that only
// some envelope alternatives document; issue explains an envelope that could
// not be resolved (never silently treated as void).
type payloadInfo struct {
	spec    *TypeSpec
	partial bool
	issue   string
}

// envelopeData reads the data member of a 200 envelope: WHM data, UAPI
// result.data, or the data of a bare UAPI result object. An envelope given as
// oneOf/anyOf alternatives yields the union of the alternatives' data.
func (s schemas) envelopeData(op model.Operation, sourceID string, envelope map[string]any) payloadInfo {
	if props, ok := asObject(envelope["properties"]); ok && len(props) > 0 {
		data, has := props["data"]
		if op.Family == "uapi" {
			if result, hasResult := props["result"]; hasResult {
				resultObject, resultSource, _, ok := s.follow(sourceID, result)
				resultProps, propsOK := asObject(resultObject["properties"])
				if !ok || !propsOK {
					return payloadInfo{issue: "the UAPI result envelope cannot be resolved"}
				}
				data, has = resultProps["data"]
				sourceID = resultSource
			}
		}
		if !has {
			return payloadInfo{}
		}
		spec := s.specFor(sourceID, data, 0, nil)
		return payloadInfo{spec: &spec}
	}
	for _, key := range []string{"oneOf", "anyOf"} {
		options, ok := envelope[key].([]any)
		if !ok || len(options) == 0 {
			continue
		}
		union := TypeSpec{Type: "union"}
		partial := false
		for _, option := range options {
			branch, branchSource, _, ok := s.follow(sourceID, option)
			if !ok {
				return payloadInfo{issue: "a 200 envelope alternative cannot be resolved"}
			}
			info := s.envelopeData(op, branchSource, branch)
			if info.issue != "" {
				return info
			}
			if info.spec == nil {
				partial = true
				continue
			}
			union.AnyOf = append(union.AnyOf, *info.spec)
			partial = partial || info.partial
		}
		if len(union.AnyOf) == 0 {
			return payloadInfo{}
		}
		spec := simplifyUnion(union)
		return payloadInfo{spec: &spec, partial: partial}
	}
	return payloadInfo{issue: "the 200 envelope documents neither properties nor alternatives"}
}

// presenceOnlyInteger identifies only the concrete source schema supported by
// the reviewed presence-input correction. It does not infer input behavior.
func (s schemas) presenceOnlyInteger(sourceID string, node any) bool {
	obj, _, _, ok := s.follow(sourceID, node)
	if !ok || obj["type"] != "integer" || isNullable(obj) {
		return false
	}
	for _, key := range []string{"allOf", "anyOf", "oneOf", "discriminator"} {
		if _, present := obj[key]; present {
			return false
		}
	}
	// enumDoc preserves quoted strings, duplicates, null and other literals
	// distinctly, so only the exact singleton integer literal matches.
	return s.enumDoc(sourceID, node) == "1"
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
