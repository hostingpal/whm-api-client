// Package contract counts independently claimable OpenAPI contracts.
package contract

import (
	"crypto/sha256"
	"fmt"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/openapi"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
	"sort"
	"strings"
)

func object(v any) model.Object {
	switch o := v.(type) {
	case model.Object:
		return o
	case map[string]any:
		return model.Object(o)
	}
	return nil
}
func escape(s string) string { return strings.ReplaceAll(strings.ReplaceAll(s, "~", "~0"), "/", "~1") }
func child(l model.Locator, s string) model.Locator {
	p := *l.Pointer + "/" + escape(s)
	l.Pointer = &p
	return l
}
func index(m model.Map) (*openapi.Index, error) {
	if len(m.Sources) == 0 {
		return openapi.NewIndex(m.Documents)
	}
	return openapi.NewBundleIndex(source.Bundle{Records: m.Sources, Documents: m.Documents})
}
func keys(o model.Object) []string {
	k := make([]string, 0, len(o))
	for x := range o {
		k = append(k, x)
	}
	sort.Strings(k)
	return k
}

// ChildKind identifies OpenAPI control positions. "data" is never interpreted.
// Named maps preserve arbitrary keys, including description, examples and $ref.
func ChildKind(kind, key string) string {
	if strings.HasPrefix(kind, "map:") {
		return strings.TrimPrefix(kind, "map:")
	}
	if strings.HasPrefix(kind, "array:") {
		return strings.TrimPrefix(kind, "array:")
	}
	if strings.HasPrefix(key, "x-") {
		return "data"
	}
	switch kind {
	case "document":
		switch key {
		case "paths":
			return "paths"
		case "info":
			return "info"
		case "tags":
			return "array:tag"
		case "servers":
			return "array:server"
		case "components":
			return "components"
		}
	case "paths":
		if strings.HasPrefix(key, "/") {
			return "path"
		}
	case "server":
		if key == "variables" {
			return "map:serverVariable"
		}
	case "link":
		if key == "server" {
			return "server"
		}

	case "components":
		switch key {
		case "schemas":
			return "map:schema"
		case "parameters":
			return "map:parameter"
		case "responses":
			return "map:response"
		case "requestBodies":
			return "map:requestBody"
		case "headers":
			return "map:header"
		case "securitySchemes":
			return "map:securityScheme"
		case "callbacks":
			return "map:callback"
		case "links":
			return "map:link"
		}
	case "path":
		if key == "servers" {
			return "array:server"
		}
		if key == "parameters" {
			return "array:parameter"
		}
		switch key {
		case "get", "put", "post", "delete", "options", "head", "patch", "trace":
			return "operation"
		}
	case "responses":
		return "response"
	case "operation":
		switch key {
		case "servers":
			return "array:server"
		case "parameters":
			return "array:parameter"
		case "requestBody":
			return "requestBody"
		case "responses":
			return "responses"
		case "callbacks":
			return "map:callback"
		}
	case "schema":
		switch key {
		case "properties":
			return "map:schema"
		case "items", "not", "additionalProperties":
			return "schema"
		case "allOf", "oneOf", "anyOf":
			return "array:schema"
		case "discriminator":
			return "discriminator"
		}
	case "parameter", "header":
		switch key {
		case "schema":
			return "schema"
		case "content":
			return "map:media"
		}
	case "requestBody":
		if key == "content" {
			return "map:media"
		}
	case "response":
		switch key {
		case "content":
			return "map:media"
		case "headers":
			return "map:header"
		case "links":
			return "map:link"
		}
	case "media":
		switch key {
		case "schema":
			return "schema"
		case "encoding":
			return "map:encoding"
		}
	case "encoding":
		if key == "headers" {
			return "map:header"
		}
	case "callback":
		return "path"
	}
	return "data"
}
func metadata(kind, key string) bool {
	if kind == "data" || strings.Contains(kind, ":") {
		return false
	}
	switch key {
	case "description", "summary", "example", "examples", "externalDocs", "x-codeSamples":
		return true
	}
	return false
}
func referenceKind(kind string) bool {
	switch kind {
	case "schema", "parameter", "header", "response", "requestBody", "securityScheme", "callback", "link", "path":
		return true
	}
	return false
}

// KindAt infers context from a root OpenAPI pointer. Fragment consumers should
// call Normalize with the explicit referencing context instead.
func KindAt(l model.Locator) string {
	kind := "document"
	if l.Pointer == nil {
		return "data"
	}
	for _, s := range strings.Split(strings.TrimPrefix(*l.Pointer, "/"), "/") {
		if s == "" {
			continue
		}
		s = strings.ReplaceAll(strings.ReplaceAll(s, "~1", "/"), "~0", "~")
		kind = ChildKind(kind, s)
	}
	return kind
}

// Normalize returns contract content and its reachable reference graph. Source
// byte hashes are deliberately excluded; source ID/pointer/context identify cycles.
func Normalize(m model.Map, origin model.Locator, kind string) (any, error) {
	idx, e := index(m)
	if e != nil {
		return nil, e
	}
	if origin.Pointer == nil {
		return nil, fmt.Errorf("contract requires a JSON pointer")
	}
	nodes := model.Object{}
	var walk func(any, model.Locator, string, int) (any, error)
	var target func(model.Locator, string, int) (string, error)
	target = func(l model.Locator, k string, depth int) (string, error) {
		raw, _ := model.Encode([]string{l.SourceID, *l.Pointer, k})
		key := strings.TrimSpace(string(raw))
		if _, ok := nodes[key]; ok {
			return key, nil
		}
		nodes[key] = nil
		v, e := source.Pointer(m.Documents[l.SourceID], *l.Pointer)
		if e != nil {
			return "", e
		}
		n, e := walk(v, l, k, depth+1)
		if e != nil {
			return "", e
		}
		nodes[key] = n
		return key, nil
	}
	walk = func(v any, l model.Locator, k string, depth int) (any, error) {
		if depth > 256 {
			return nil, fmt.Errorf("contract graph depth exceeds 256")
		}
		if k == "data" {
			return v, nil
		}
		if a, ok := v.([]any); ok {
			out := make([]any, len(a))
			for j, x := range a {
				n, e := walk(x, child(l, fmt.Sprint(j)), ChildKind(k, fmt.Sprint(j)), depth+1)
				if e != nil {
					return nil, e
				}
				out[j] = n
			}
			return out, nil
		}
		o := object(v)
		if o == nil {
			return v, nil
		}
		out := model.Object{}
		if referenceKind(k) {
			if ref, ok := o["$ref"]; ok {
				if k == "path" && len(o) > 1 {
					return nil, fmt.Errorf("ambiguous path reference siblings")
				}
				r, ok := ref.(string)
				if !ok {
					return nil, fmt.Errorf("invalid reference")
				}
				resolved, e := idx.Resolve(l.SourceID, r)
				if e != nil {
					return nil, e
				}
				key, e := target(resolved.Origin, k, depth+1)
				if e != nil {
					return nil, e
				}
				out["$ref"] = key
				if k != "path" {
					return out, nil
				}
			}
		}
		for _, key := range keys(o) {
			if metadata(k, key) || key == "$ref" && referenceKind(k) {
				continue
			}
			n, e := walk(o[key], child(l, key), ChildKind(k, key), depth+1)
			if e != nil {
				return nil, e
			}
			out[key] = n
		}
		if k == "discriminator" {
			mapping := object(o["mapping"])
			targets := model.Object{}
			for _, name := range keys(mapping) {
				ref, ok := mapping[name].(string)
				if !ok {
					return nil, fmt.Errorf("invalid discriminator mapping")
				}
				ref = source.DiscriminatorReference(m.Documents[l.SourceID], ref)
				r, e := idx.Resolve(l.SourceID, ref)
				if e != nil {
					return nil, e
				}
				key, e := target(r.Origin, "schema", depth+1)
				if e != nil {
					return nil, e
				}
				targets[name] = key
			}
			if mapping != nil {
				out["mapping"] = targets
			}
		}
		return out, nil
	}
	root, e := target(origin, kind, 0)
	if e != nil {
		return nil, e
	}
	return model.Object{"root": root, "nodes": nodes}, nil
}
func Hash(m model.Map, origin model.Locator) (string, error) {
	return HashKind(m, origin, KindAt(origin))
}
func HashKind(m model.Map, origin model.Locator, kind string) (string, error) {
	n, e := Normalize(m, origin, kind)
	if e != nil {
		return "", e
	}
	b, e := model.Encode(n)
	if e != nil {
		return "", e
	}
	return fmt.Sprintf("%x", sha256.Sum256(b)), nil
}
