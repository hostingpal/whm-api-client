// Package mapbuild constructs the canonical, evidence-bound map without calls.
package mapbuild

import (
	"fmt"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/openapi"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

// Build preserves source documents and indexes exact family/path identities.
func Build(bundle source.Bundle, annotations []model.Annotation, aliases []model.Alias) (model.Map, error) {
	analysis, err := openapi.Analyze(bundle)
	if err != nil {
		return model.Map{}, err
	}
	m := model.Map{SchemaVersion: "1.0", Sources: bundle.Records, Documents: bundle.Documents, Operations: []model.Operation{}, Annotations: append([]model.Annotation{}, annotations...), Aliases: append([]model.Alias{}, aliases...), Diagnostics: append([]model.Diagnostic{}, analysis.Diagnostics...)}
	records := map[string]model.Object{}
	families := map[string]string{}
	for _, r := range bundle.Records {
		id := r["id"].(string)
		records[id] = r
		if r["kind"] == "openapi" {
			switch r["api_family"] {
			case "whm-api-1":
				families[id] = "whm1"
			case "cpanel-uapi":
				families[id] = "uapi"
			default:
				return model.Map{}, fmt.Errorf("unsupported API family")
			}
		}
	}
	ops := map[string]*model.Operation{}
	variantIDs := map[string]bool{}
	upstream := map[string]map[string]model.Locator{}
	for _, c := range analysis.Candidates {
		family := families[c.SourceID]
		path := strings.TrimPrefix(c.Path, "/")
		if path == "" || strings.Contains(path, "#") {
			return model.Map{}, fmt.Errorf("invalid canonical path")
		}
		id := family + ":" + path
		o := ops[id]
		if o == nil {
			o = &model.Operation{ID: id, Family: family, Function: path, Path: c.Path, Provenance: []model.Locator{c.PathItemLocator}, Variants: []model.Variant{}}
			if family == "uapi" {
				parts := strings.Split(path, "/")
				if len(parts) != 2 || parts[0] == "" || parts[1] == "" {
					return model.Map{}, fmt.Errorf("UAPI path requires module/function")
				}
				o.Module = &parts[0]
				o.Function = parts[1]
			}
			ops[id] = o
		} else if o.Provenance[0].SourceID != c.SourceID {
			return model.Map{}, fmt.Errorf("duplicate canonical operation ID")
		}
		method := strings.ToUpper(c.Method)
		vid := id + "#" + method
		if variantIDs[vid] {
			return model.Map{}, fmt.Errorf("duplicate canonical variant ID")
		}
		variantIDs[vid] = true
		var uid *string
		if s, ok := c.Operation["operationId"].(string); ok {
			uid = &s
			if upstream[s] == nil {
				upstream[s] = map[string]model.Locator{}
			}
			upstream[s][id] = c.OperationLocator
		}
		params := append([]model.Parameter{}, c.Parameters...)
		sort.Slice(params, func(i, j int) bool {
			if params[i].Name != params[j].Name {
				return params[i].Name < params[j].Name
			}
			return params[i].In < params[j].In
		})
		if _, present := c.Operation["requestBody"]; method == "POST" && !present {
			m.Diagnostics = append(m.Diagnostics, model.Diagnostic{Severity: "warning", Code: "POST_WITHOUT_BODY", Message: "documented POST has no request-body declaration; existing parameters are retained", Locator: c.OperationLocator, CanonicalID: id})
		}
		r, diag := risk(c, id, vid, annotations)
		m.Diagnostics = append(m.Diagnostics, diag...)
		o.Variants = append(o.Variants, model.Variant{ID: vid, Method: method, UpstreamOperationID: uid, OperationLocator: c.OperationLocator, PathItemLocator: c.PathItemLocator, Parameters: params, Security: c.Security, Servers: c.Servers, Risk: r})
	}
	for _, o := range ops {
		sort.Slice(o.Variants, func(i, j int) bool { return o.Variants[i].ID < o.Variants[j].ID })
		m.Operations = append(m.Operations, *o)
	}
	sort.Slice(m.Operations, func(i, j int) bool { return m.Operations[i].ID < m.Operations[j].ID })
	if err := validateAnnotations(bundle, annotations, ops, variantIDs); err != nil {
		return model.Map{}, err
	}
	if err := validateAliases(bundle, aliases, ops, variantIDs); err != nil {
		return model.Map{}, err
	}
	for uid, ids := range upstream {
		if len(ids) < 2 {
			continue
		}
		names := make([]string, 0, len(ids))
		for id := range ids {
			names = append(names, id)
		}
		sort.Strings(names)
		m.Diagnostics = append(m.Diagnostics, model.Diagnostic{Severity: "warning", Code: "UPSTREAM_ID_COLLISION", Message: fmt.Sprintf("upstream operationId %q is shared by %s; canonical identities remain distinct", uid, strings.Join(names, ", ")), Locator: ids[names[0]], CanonicalID: names[0]})
	}
	m.Diagnostics = append(m.Diagnostics, auditDiagnostics(bundle, records, ops)...)
	// Encode/Decode supplies canonical normalized ordering without modifying input.
	data, err := model.Encode(m)
	if err != nil {
		return model.Map{}, err
	}
	return model.DecodeMap(data)
}
