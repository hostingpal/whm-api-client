package source

import (
	"fmt"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

type referenceUse struct{ ref, kind string }

func objectValue(v any) (map[string]any, bool) {
	switch x := v.(type) {
	case model.Object:
		return map[string]any(x), true
	case map[string]any:
		return x, true
	default:
		return nil, false
	}
}

// discover interprets control fields by their OpenAPI object position. Schema
// property names are arbitrary; example/default/value and extensions are data.
func discover(doc model.Object, v any, kind string, depth, max int) ([]referenceUse, error) {
	if depth > max {
		return nil, fmt.Errorf("reference traversal depth limit exceeded")
	}
	o, ok := objectValue(v)
	if !ok {
		return nil, nil
	}
	out := []referenceUse{}
	if supportsReference(kind) {
		if value, present := o["$ref"]; present {
			ref, ok := value.(string)
			if !ok {
				return nil, fmt.Errorf("reference must be a string")
			}
			out = append(out, referenceUse{ref, kind})
			// Path Item $ref is its own fixed field, distinct from a Reference
			// Object. Its siblings remain visible for downstream ambiguity checks.
			if kind != "path" {
				return out, nil
			}
		}
	}
	add := func(value any, childKind string) error {
		uses, err := discover(doc, value, childKind, depth+1, max)
		out = append(out, uses...)
		return err
	}
	child := func(field, childKind string) error {
		if value, present := o[field]; present {
			return add(value, childKind)
		}
		return nil
	}
	named := func(field, childKind string) error {
		values, ok := objectValue(o[field])
		if !ok {
			return nil
		}
		keys := make([]string, 0, len(values))
		for key := range values {
			keys = append(keys, key)
		}
		sort.Strings(keys)
		for _, key := range keys {
			if kind == "operation" && field == "responses" && strings.HasPrefix(key, "x-") {
				continue
			}
			if err := add(values[key], childKind); err != nil {
				return err
			}
		}
		return nil
	}
	array := func(field, childKind string) error {
		values, _ := o[field].([]any)
		for _, value := range values {
			if err := add(value, childKind); err != nil {
				return err
			}
		}
		return nil
	}
	pairs := func(fields [][2]string, names bool) error {
		for _, pair := range fields {
			var err error
			if names {
				err = named(pair[0], pair[1])
			} else {
				err = child(pair[0], pair[1])
			}
			if err != nil {
				return err
			}
		}
		return nil
	}
	var err error
	switch kind {
	case "document":
		err = pairs([][2]string{{"paths", "paths"}, {"components", "components"}}, false)
	case "components":
		err = pairs([][2]string{{"schemas", "schema"}, {"parameters", "parameter"}, {"responses", "response"}, {"requestBodies", "requestBody"}, {"headers", "header"}, {"examples", "example"}, {"links", "link"}, {"callbacks", "callback"}, {"securitySchemes", "securityScheme"}}, true)
	case "paths", "callback":
		keys := make([]string, 0, len(o))
		for key := range o {
			keys = append(keys, key)
		}
		sort.Strings(keys)
		for _, key := range keys {
			if (kind == "callback" && !strings.HasPrefix(key, "x-")) || strings.HasPrefix(key, "/") {
				if err = add(o[key], "path"); err != nil {
					break
				}
			}
		}
	case "path":
		if err = array("parameters", "parameter"); err == nil {
			for _, method := range []string{"get", "put", "post", "delete", "options", "head", "patch", "trace"} {
				if err = child(method, "operation"); err != nil {
					break
				}
			}
		}
	case "operation":
		if err = array("parameters", "parameter"); err == nil {
			err = child("requestBody", "requestBody")
		}
		if err == nil {
			err = pairs([][2]string{{"responses", "response"}, {"callbacks", "callback"}}, true)
		}
	case "parameter", "header":
		if err = child("schema", "schema"); err == nil {
			err = pairs([][2]string{{"content", "media"}, {"examples", "example"}}, true)
		}
	case "requestBody":
		err = named("content", "media")
	case "response":
		err = pairs([][2]string{{"content", "media"}, {"headers", "header"}, {"links", "link"}}, true)
	case "media":
		if err = child("schema", "schema"); err == nil {
			err = pairs([][2]string{{"examples", "example"}, {"encoding", "encoding"}}, true)
		}
	case "encoding":
		err = named("headers", "header")
	case "schema":
		if err = named("properties", "schema"); err == nil {
			err = pairs([][2]string{{"items", "schema"}, {"not", "schema"}}, false)
		}
		if err == nil {
			for _, field := range []string{"allOf", "oneOf", "anyOf"} {
				if err = array(field, "schema"); err != nil {
					break
				}
			}
		}
		if err == nil {
			if _, boolean := o["additionalProperties"].(bool); !boolean {
				err = child("additionalProperties", "schema")
			}
		}
		if err == nil {
			if discriminator, ok := objectValue(o["discriminator"]); ok {
				if mapping, ok := objectValue(discriminator["mapping"]); ok {
					keys := make([]string, 0, len(mapping))
					for key := range mapping {
						keys = append(keys, key)
					}
					sort.Strings(keys)
					for _, key := range keys {
						ref, ok := mapping[key].(string)
						if !ok {
							return nil, fmt.Errorf("discriminator mapping must be a string")
						}
						ref = DiscriminatorReference(doc, ref)
						out = append(out, referenceUse{ref, "schema"})
					}
				}
			}
		}
	}
	return out, err
}

// References discovers reference strings in an OpenAPI root document. Pinned
// fragments are interpreted internally with the referencing object's context.
func References(doc model.Object) []string {
	uses, err := discover(doc, doc, "document", 0, DefaultLimits().MaxDepth)
	if err != nil {
		return nil
	}
	unique := map[string]bool{}
	for _, use := range uses {
		unique[use.ref] = true
	}
	out := make([]string, 0, len(unique))
	for ref := range unique {
		out = append(out, ref)
	}
	sort.Strings(out)
	return out
}
func walkBundleReferences(b *Bundle, l Limits, pin bool, depths map[string]int) error {
	type node struct {
		id, pointer, kind string
		depth             int
	}
	queue := []node{}
	for _, rec := range b.Records {
		if rec["kind"] == "openapi" && b.Documents[rec["id"].(string)] != nil {
			queue = append(queue, node{id: rec["id"].(string), kind: "document"})
		}
	}
	seen := map[string]bool{}
	for len(queue) > 0 {
		current := queue[0]
		queue = queue[1:]
		key := current.id + "#" + current.pointer + "\x00" + current.kind
		if seen[key] {
			continue
		}
		seen[key] = true
		if current.depth > l.MaxDepth {
			return fmt.Errorf("reference traversal depth limit exceeded")
		}
		doc := b.Documents[current.id]
		if doc == nil {
			if pin {
				continue
			}
			return fmt.Errorf("reference document is missing")
		}
		value, err := Pointer(doc, current.pointer)
		if err != nil {
			return err
		}
		uses, err := discover(doc, value, current.kind, 0, l.MaxDepth)
		if err != nil {
			return err
		}
		var origin model.Object
		for _, rec := range b.Records {
			if rec["id"] == current.id {
				origin = rec
				break
			}
		}
		for _, use := range uses {
			if pin {
				if err = pinReference(use.ref, origin, &b.Records, depths, l.MaxDepth); err != nil {
					return err
				}
			}
			id, pointer, err := resolveReference(*b, current.id, use.ref, !pin)
			if err != nil {
				return err
			}
			queue = append(queue, node{id: id, pointer: pointer, kind: use.kind, depth: current.depth + 1})
		}
	}
	return nil
}

// Ordinary Reference Objects occur only where OpenAPI 3.0 explicitly allows
// them. Path Item $ref is supported separately; unsupported raw $ref fields
// never suppress the containing object's genuine declarations.
func supportsReference(kind string) bool {
	switch kind {
	case "schema", "parameter", "response", "requestBody", "header", "example", "link", "callback", "securityScheme", "path":
		return true
	default:
		return false
	}
}

// DiscriminatorReference prefers an exact existing local component identity
// before interpreting a mapping as a URI. Bare names remain local identities
// even when missing, so validation reports their unresolved component pointer.
// Discovery, refresh pinning, analysis and hashing share this policy.
func DiscriminatorReference(doc model.Object, ref string) string {
	pointer := "/components/schemas/" + strings.ReplaceAll(strings.ReplaceAll(ref, "~", "~0"), "/", "~1")
	if _, err := Pointer(doc, pointer); err == nil || !strings.ContainsAny(ref, "/#:.") {
		return "#" + pointer
	}
	return ref
}
