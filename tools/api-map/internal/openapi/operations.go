package openapi

import (
	"fmt"
	"regexp"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

var methods = map[string]bool{"get": true, "put": true, "post": true, "delete": true, "options": true, "head": true, "patch": true, "trace": true}
var versionPattern = regexp.MustCompile(`^3\.0\.[0-9]+$`)

func Analyze(bundle source.Bundle) (Analysis, error) {
	idx, err := NewBundleIndex(bundle)
	if err != nil {
		return Analysis{}, err
	}
	a := &analyzer{index: idx, visited: map[string]bool{}, active: map[string]bool{}, diagnosticSet: map[string]bool{}, referenceSet: map[string]bool{}}
	a.analysis = Analysis{Candidates: []Candidate{}, Diagnostics: []model.Diagnostic{}, References: []Reference{}, DiscriminatorTargets: []Reference{}}
	for _, id := range keys(bundle.Documents) {
		if idx.records[id]["kind"] != "openapi" {
			continue
		}
		d := bundle.Documents[id]
		root := idx.locator(id, "")
		version, _ := d["openapi"].(string)
		if !versionPattern.MatchString(version) {
			a.fail("UNSUPPORTED_OPENAPI", fmt.Errorf("supported dialect is OpenAPI 3.0"), root)
			continue
		}
		info, ok := object(d["info"])
		title, tok := info["title"].(string)
		ver, vok := info["version"].(string)
		if !ok || !tok || !vok || title == "" || ver == "" {
			a.fail("MALFORMED_INFO", fmt.Errorf("info requires title and version"), root)
		}
		paths, ok := object(d["paths"])
		if !ok {
			a.fail("MALFORMED_PATHS", fmt.Errorf("paths must be an object"), root)
			continue
		}
		a.walk(id, "", d, "document", 0)
		for _, path := range keys(paths) {
			ploc := idx.locator(id, "/paths/"+token(path))
			if !strings.HasPrefix(path, "/") {
				if !strings.HasPrefix(path, "x-") {
					a.fail("MALFORMED_PATHS", fmt.Errorf("invalid Paths Object key"), ploc)
				}
				continue
			}
			item, ok := object(paths[path])
			if !ok {
				a.fail("MALFORMED_PATH_ITEM", fmt.Errorf("path item must be an object"), ploc)
				continue
			}
			item, decl, err := idx.declaration(item, ploc, "path")
			if err != nil {
				a.fail("INVALID_PATH_ITEM_REF", err, ploc)
				continue
			}
			params := a.parameters(item, decl)
			for _, method := range keys(item) {
				if !methods[method] {
					continue
				}
				oloc := idx.locator(decl.SourceID, *decl.Pointer+"/"+method)
				op, ok := object(item[method])
				if !ok {
					a.fail("MALFORMED_OPERATION", fmt.Errorf("operation must be an object"), oloc)
					continue
				}
				if v, present := op["operationId"]; !present {
					a.diagnostic("warning", "MISSING_OPERATION_ID", "upstream operationId is absent; canonical identity uses exact path", oloc)
				} else if s, ok := v.(string); !ok || s == "" {
					a.fail("MALFORMED_OPERATION_ID", fmt.Errorf("operationId must be a nonempty string"), oloc)
				}
				if _, present := op["requestBody"]; present && method == "get" {
					a.diagnostic("warning", "GET_BODY", "documented GET request body is retained", oloc)
				}
				c := Candidate{SourceID: id, Path: path, Method: method, OperationLocator: oloc, PathItemLocator: ploc, Operation: op, Parameters: mergeParameters(params, a.parameters(op, oloc))}
				c.Security = a.effective("security", []model.Object{op, d}, []model.Locator{oloc, root}, []string{"operation", "root"})
				c.Servers = a.effective("servers", []model.Object{op, item, d}, []model.Locator{oloc, decl, root}, []string{"operation", "path", "root"})
				a.analysis.Candidates = append(a.analysis.Candidates, c)
			}
		}
	}
	return a.result()
}
