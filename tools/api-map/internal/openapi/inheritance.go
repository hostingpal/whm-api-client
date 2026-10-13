package openapi

import (
	"fmt"
	"sort"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

func (a *analyzer) parameters(o model.Object, loc model.Locator) []model.Parameter {
	out := []model.Parameter{}
	v, present := o["parameters"]
	if !present {
		return out
	}
	arr, ok := v.([]any)
	if !ok {
		a.fail("MALFORMED_PARAMETERS", fmt.Errorf("parameters must be an array"), loc)
		return out
	}
	seen := map[string]bool{}
	for n, v := range arr {
		p := *loc.Pointer + fmt.Sprintf("/parameters/%d", n)
		decl := a.index.locator(loc.SourceID, p)
		param, ok := object(v)
		if !ok {
			a.fail("MALFORMED_PARAMETER", fmt.Errorf("parameter must be an object"), decl)
			continue
		}
		param, resolved, err := a.index.declaration(param, decl, "parameter")
		if err != nil {
			a.fail("INVALID_PARAMETER_REF", err, decl)
			continue
		}
		name, nok := param["name"].(string)
		in, iok := param["in"].(string)
		if !nok || name == "" || !iok || (in != "query" && in != "header" && in != "path" && in != "cookie") {
			a.fail("MALFORMED_PARAMETER", fmt.Errorf("parameter requires a name and supported location"), resolved)
			continue
		}
		key := in + "\x00" + name
		if seen[key] {
			a.fail("DUPLICATE_PARAMETER", fmt.Errorf("duplicate parameter declaration in one scope"), decl)
			continue
		}
		seen[key] = true
		out = append(out, model.Parameter{Name: name, In: in, Definition: param, Locator: resolved})
	}
	return out
}
func mergeParameters(path, op []model.Parameter) []model.Parameter {
	merged := map[string]model.Parameter{}
	for _, ps := range [][]model.Parameter{path, op} {
		for _, p := range ps {
			merged[p.In+"\x00"+p.Name] = p
		}
	}
	out := make([]model.Parameter, 0, len(merged))
	for _, k := range keys(merged) {
		out = append(out, merged[k])
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Name != out[j].Name {
			return out[i].Name < out[j].Name
		}
		return out[i].In < out[j].In
	})
	return out
}
func (a *analyzer) effective(key string, objects []model.Object, locs []model.Locator, levels []string) model.EffectiveValue {
	for n, o := range objects {
		if v, present := o[key]; present {
			loc := a.index.locator(locs[n].SourceID, *locs[n].Pointer+"/"+key)
			if _, ok := v.([]any); !ok {
				a.fail("MALFORMED_"+key, fmt.Errorf("%s must be an array", key), loc)
			}
			return model.EffectiveValue{Present: true, Value: v, Locator: &loc, Level: levels[n]}
		}
	}
	return model.EffectiveValue{Level: "none"}
}
