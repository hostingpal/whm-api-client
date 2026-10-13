package contract

import (
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
	"sort"
	"strings"
)

type Unit struct {
	ID      string        `json:"id"`
	Kind    string        `json:"kind"`
	Locator model.Locator `json:"locator"`
	Hash    string        `json:"hash"`
}

func ID(parts ...string) string                          { b, _ := json.Marshal(parts); return string(b) }
func Units(m model.Map, v model.Variant) ([]Unit, error) { u, _, e := AnalyzeUnits(m, v); return u, e }

// AnalyzeUnits exposes missing response schemas separately from claimable units.
func AnalyzeUnits(m model.Map, v model.Variant) ([]Unit, []model.Diagnostic, error) {
	units := []Unit{}
	diagnostics := []model.Diagnostic{}
	idx, e := index(m)
	if e != nil {
		return nil, nil, e
	}
	add := func(id, kind, context string, l model.Locator) error {
		h, e := HashKind(m, l, context)
		if e != nil {
			return e
		}
		units = append(units, Unit{id, kind, l, h})
		return nil
	}
	resolve := func(l model.Locator) (model.Object, model.Locator, error) {
		seen := map[string]bool{}
		for {
			if l.Pointer == nil {
				return nil, l, fmt.Errorf("missing contract pointer")
			}
			key := l.SourceID + "#" + *l.Pointer
			if seen[key] {
				return nil, l, fmt.Errorf("cyclic declaration")
			}
			seen[key] = true
			x, e := source.Pointer(m.Documents[l.SourceID], *l.Pointer)
			if e != nil {
				return nil, l, e
			}
			o := object(x)
			if o == nil {
				return nil, l, fmt.Errorf("contract declaration is not an object")
			}
			r, present := o["$ref"]
			if !present {
				return o, l, nil
			}
			ref, ok := r.(string)
			if !ok {
				return nil, l, fmt.Errorf("invalid reference")
			}
			target, e := idx.Resolve(l.SourceID, ref)
			if e != nil {
				return nil, l, e
			}
			l = target.Origin
		}
	}
	for _, p := range v.Parameters {
		if e = add(ID(v.ID, "parameter", p.Name, p.In), "parameter", "parameter", p.Locator); e != nil {
			return nil, nil, e
		}
	}
	ol := v.OperationLocator
	if ol.Pointer == nil {
		return nil, nil, fmt.Errorf("missing operation pointer")
	}
	raw, e := source.Pointer(m.Documents[ol.SourceID], *ol.Pointer)
	if e != nil {
		return nil, nil, e
	}
	op := object(raw)
	if op == nil {
		return nil, nil, fmt.Errorf("operation must be an object")
	}
	if _, present := op["requestBody"]; present {
		body, bl, e := resolve(child(ol, "requestBody"))
		if e != nil {
			return nil, nil, e
		}
		for _, media := range keys(object(body["content"])) {
			ml := child(child(bl, "content"), media)
			normalized, err := Normalize(m, ml, "media")
			if err != nil {
				return nil, nil, err
			}
			content := model.Object{"media": normalized}
			if required, present := body["required"]; present {
				content["required"] = required
			}
			raw, err := model.Encode(content)
			if err != nil {
				return nil, nil, err
			}
			units = append(units, Unit{ID: ID(v.ID, "body", media), Kind: "body", Locator: ml, Hash: fmt.Sprintf("%x", sha256.Sum256(raw))})
		}
	}
	for _, status := range keys(object(op["responses"])) {
		if strings.HasPrefix(status, "x-") {
			continue
		}
		response, rl, e := resolve(child(child(ol, "responses"), status))
		if e != nil {
			return nil, nil, e
		}
		content := object(response["content"])
		gap := func(l model.Locator) {
			diagnostics = append(diagnostics, model.Diagnostic{Severity: "warning", Code: "missing_response_schema", Message: "Documented response has no schema; excluded from response-schema denominator.", CanonicalID: v.ID, Locator: l})
		}
		if len(content) == 0 {
			gap(rl)
		}
		for _, media := range keys(content) {
			ml := child(child(rl, "content"), media)
			if schema, ok := object(content[media])["schema"]; !ok || schema == nil {
				gap(ml)
				continue
			}
			if e = add(ID(v.ID, "response", status, media), "response", "media", ml); e != nil {
				return nil, nil, e
			}
		}
	}
	sort.Slice(units, func(i, j int) bool { return units[i].ID < units[j].ID })
	return units, diagnostics, nil
}
