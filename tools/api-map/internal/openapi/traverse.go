package openapi

import (
	"fmt"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

// supportsReference restricts OpenAPI 3.0 reference alternatives to the
// objects that actually permit them. Path Items have their own $ref semantics.
func supportsReference(kind string) bool {
	switch kind {
	case "schema", "parameter", "response", "requestBody", "header", "example", "link", "callback", "securityScheme", "path":
		return true
	default:
		return false
	}
}

// walk interprets control fields according to their OpenAPI object position.
// Maps of arbitrary names (properties, examples, response codes) are handled
// separately, so a data key such as "$ref" or "x-frame-options" is never
// promoted into a Reference Object or vendor declaration.
func (a *analyzer) walk(id, p string, v any, kind string, depth int) {
	loc := a.index.locator(id, p)
	if depth > maxDepth {
		a.fail("DEPTH_LIMIT", fmt.Errorf("OpenAPI traversal depth limit exceeded"), loc)
		return
	}
	key := id + "#" + p + "\x00" + kind
	if a.active[key] {
		if kind == "schema" {
			a.diagnostic("info", "SCHEMA_CYCLE", "symbolic schema recursion is retained", loc)
		} else {
			a.fail("REFERENCE_CYCLE", fmt.Errorf("cyclic non-schema declaration"), loc)
		}
		return
	}
	if a.visited[key] {
		return
	}
	a.visited[key] = true
	a.active[key] = true
	defer delete(a.active, key)
	o, ok := object(v)
	if !ok {
		a.fail("MALFORMED_OBJECT", fmt.Errorf("%s must be an object", kind), loc)
		return
	}
	if supportsReference(kind) {
		if value, present := o["$ref"]; present {
			rp := p + "/$ref"
			origin := a.index.locator(id, rp)
			ref, ok := value.(string)
			if !ok {
				a.fail("MALFORMED_REF", fmt.Errorf("reference must be a string"), origin)
			} else {
				resolved, err := a.index.Resolve(id, ref)
				if err != nil {
					a.fail("UNRESOLVED_REF", err, origin)
				} else {
					a.recordReference(Reference{Origin: origin, Ref: ref, Target: resolved.Origin}, false)
					a.walk(resolved.Origin.SourceID, *resolved.Origin.Pointer, resolved.Value, kind, depth+1)
				}
			}
			// Ordinary OpenAPI 3.0 Reference Object siblings are retained data.
			// Path Item references have separate, potentially ambiguous semantics.
			if kind == "path" && len(o) > 1 {
				a.fail("INVALID_PATH_ITEM_REF", fmt.Errorf("reference siblings have ambiguous effective declarations"), loc)
			}
			return
		}
	}
	a.validateDeclarations(o, kind, loc)
	child := func(field, childKind string) {
		if val, present := o[field]; present {
			a.walk(id, p+"/"+token(field), val, childKind, depth+1)
		}
	}
	named := func(field, childKind string) {
		if val, present := o[field]; present {
			m, ok := object(val)
			if !ok {
				a.fail("MALFORMED_OBJECT", fmt.Errorf("%s must be an object", field), a.index.locator(id, p+"/"+token(field)))
				return
			}
			for _, name := range keys(m) {
				if kind == "operation" && field == "responses" && strings.HasPrefix(name, "x-") {
					continue
				}
				a.walk(id, p+"/"+token(field)+"/"+token(name), m[name], childKind, depth+1)
			}
		}
	}
	array := func(field, childKind string) {
		if val, present := o[field]; present {
			arr, ok := val.([]any)
			if !ok {
				a.fail("MALFORMED_ARRAY", fmt.Errorf("%s must be an array", field), a.index.locator(id, p+"/"+field))
				return
			}
			for n, val := range arr {
				a.walk(id, p+fmt.Sprintf("/%s/%d", field, n), val, childKind, depth+1)
			}
		}
	}
	switch kind {
	case "document":
		child("paths", "paths")
		child("components", "components")
	case "components":
		for _, pair := range [][2]string{{"schemas", "schema"}, {"parameters", "parameter"}, {"responses", "response"}, {"requestBodies", "requestBody"}, {"headers", "header"}, {"examples", "example"}, {"links", "link"}, {"callbacks", "callback"}, {"securitySchemes", "securityScheme"}} {
			named(pair[0], pair[1])
		}
	case "paths", "callback":
		for _, name := range keys(o) {
			if (kind == "callback" && !strings.HasPrefix(name, "x-")) || strings.HasPrefix(name, "/") {
				a.walk(id, p+"/"+token(name), o[name], "path", depth+1)
			}
		}
	case "path":
		array("parameters", "parameter")
		for _, m := range keys(o) {
			if methods[m] {
				child(m, "operation")
			}
		}
	case "operation":
		array("parameters", "parameter")
		child("requestBody", "requestBody")
		named("responses", "response")
		named("callbacks", "callback")
	case "parameter", "header":
		child("schema", "schema")
		named("content", "media")
		named("examples", "example")
	case "requestBody":
		named("content", "media")
	case "response":
		if _, present := o["description"].(string); !present {
			a.fail("MALFORMED_RESPONSE", fmt.Errorf("response requires a description"), loc)
		}
		named("content", "media")
		named("headers", "header")
		named("links", "link")
	case "media":
		child("schema", "schema")
		named("examples", "example")
		named("encoding", "encoding")
	case "encoding":
		named("headers", "header")
	case "schema":
		named("properties", "schema")
		child("items", "schema")
		child("not", "schema")
		array("allOf", "schema")
		array("oneOf", "schema")
		array("anyOf", "schema")
		if val, present := o["additionalProperties"]; present {
			if _, boolean := val.(bool); !boolean {
				child("additionalProperties", "schema")
			}
		}
		if value, present := o["discriminator"]; present {
			discriminator, ok := object(value)
			if !ok {
				a.fail("MALFORMED_DISCRIMINATOR", fmt.Errorf("discriminator must be an object"), loc)
				return
			}
			if _, ok := discriminator["propertyName"].(string); !ok {
				a.fail("MALFORMED_DISCRIMINATOR", fmt.Errorf("discriminator requires propertyName"), loc)
			}
			if value, present := discriminator["mapping"]; present {
				mapping, ok := object(value)
				if !ok {
					a.fail("MALFORMED_DISCRIMINATOR", fmt.Errorf("discriminator mapping must be an object"), loc)
					return
				}
				for _, name := range keys(mapping) {
					origin := a.index.locator(id, p+"/discriminator/mapping/"+token(name))
					ref, ok := mapping[name].(string)
					if !ok {
						a.fail("UNRESOLVED_DISCRIMINATOR", fmt.Errorf("mapping target must be a reference string"), origin)
						continue
					}
					targetRef := source.DiscriminatorReference(a.index.bundle.Documents[id], ref)
					target, err := a.index.Resolve(id, targetRef)
					if err != nil {
						a.fail("UNRESOLVED_DISCRIMINATOR", err, origin)
						continue
					}
					a.recordReference(Reference{Origin: origin, Ref: ref, Target: target.Origin}, true)
					a.walk(target.Origin.SourceID, *target.Origin.Pointer, target.Value, "schema", depth+1)
				}
			}
		}
	}
}
func (a *analyzer) recordReference(r Reference, discriminator bool) {
	key := r.Origin.SourceID + "#" + *r.Origin.Pointer
	if a.referenceSet[key] {
		return
	}
	a.referenceSet[key] = true
	if discriminator {
		a.analysis.DiscriminatorTargets = append(a.analysis.DiscriminatorTargets, r)
	} else {
		a.analysis.References = append(a.analysis.References, r)
	}
}
