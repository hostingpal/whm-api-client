package openapi

import (
	"fmt"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

const maxDepth = 256

func token(s string) string { return strings.ReplaceAll(strings.ReplaceAll(s, "~", "~0"), "/", "~1") }
func (i *Index) Resolve(sourceID, ref string) (Resolved, error) {
	if i.bundle.Documents[sourceID] == nil {
		return Resolved{}, fmt.Errorf("reference origin is not a parsed document")
	}
	id, p, err := source.ResolveReference(i.bundle, sourceID, ref)
	if err != nil {
		return Resolved{}, err
	}
	value, err := source.Pointer(i.bundle.Documents[id], p)
	if err != nil {
		return Resolved{}, err
	}
	return Resolved{Value: value, Origin: i.locator(id, p)}, nil
}

// declaration follows Reference Objects, but returns original concrete objects;
// schemas use Resolve directly and remain symbolic, including valid recursion.
func (i *Index) declaration(o model.Object, loc model.Locator, kind string) (model.Object, model.Locator, error) {
	seen := map[string]bool{}
	for depth := 0; depth <= maxDepth; depth++ {
		key := loc.SourceID + "#" + *loc.Pointer
		if seen[key] {
			return nil, loc, fmt.Errorf("cyclic non-schema declaration")
		}
		seen[key] = true
		v, present := o["$ref"]
		if !present {
			return o, loc, nil
		}
		ref, ok := v.(string)
		if !ok {
			return nil, loc, fmt.Errorf("reference must be a string")
		}
		if kind == "path" && len(o) > 1 {
			return nil, loc, fmt.Errorf("reference siblings have ambiguous effective declarations")
		}
		r, err := i.Resolve(loc.SourceID, ref)
		if err != nil {
			return nil, loc, err
		}
		o, ok = object(r.Value)
		if !ok {
			return nil, r.Origin, fmt.Errorf("reference declaration must be an object")
		}
		loc = r.Origin
	}
	return nil, loc, fmt.Errorf("reference depth limit exceeded")
}
