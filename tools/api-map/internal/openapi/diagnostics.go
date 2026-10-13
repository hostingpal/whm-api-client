package openapi

import (
	"fmt"
	"sort"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

type analyzer struct {
	index           *Index
	analysis        Analysis
	visited, active map[string]bool
	diagnosticSet   map[string]bool
	referenceSet    map[string]bool
}

func (a *analyzer) diagnostic(severity, code, message string, loc model.Locator) {
	key := severity + code + loc.SourceID + "#" + *loc.Pointer
	if a.diagnosticSet[key] {
		return
	}
	a.diagnosticSet[key] = true
	a.analysis.Diagnostics = append(a.analysis.Diagnostics, model.Diagnostic{Severity: severity, Code: code, Message: message, Locator: loc})
}
func (a *analyzer) fail(code string, err error, loc model.Locator) {
	a.diagnostic("error", code, err.Error(), loc)
}
func (a *analyzer) result() (Analysis, error) {
	sort.Slice(a.analysis.Diagnostics, func(i, j int) bool {
		x, y := a.analysis.Diagnostics[i], a.analysis.Diagnostics[j]
		if x.Locator.SourceID != y.Locator.SourceID {
			return x.Locator.SourceID < y.Locator.SourceID
		}
		if *x.Locator.Pointer != *y.Locator.Pointer {
			return *x.Locator.Pointer < *y.Locator.Pointer
		}
		return x.Code < y.Code
	})
	for _, d := range a.analysis.Diagnostics {
		if d.Severity == "error" {
			return a.analysis, fmt.Errorf("OpenAPI analysis failed: %s at %s#%s", d.Code, d.Locator.SourceID, *d.Locator.Pointer)
		}
	}
	return a.analysis, nil
}
