package mapbuild

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/openapi"
)

func risk(c openapi.Candidate, id, vid string, annotations []model.Annotation) (model.Risk, []model.Diagnostic) {
	r := model.Risk{Classification: "unknown", DestructivePotential: "unknown", Evidence: []model.Locator{}}
	var claims []string
	if v, ok := c.Operation["x-readonly"]; ok {
		loc := c.OperationLocator
		p := *loc.Pointer + "/x-readonly"
		loc.Pointer = &p
		r.Evidence = append(r.Evidence, loc)
		if b, ok := v.(bool); ok {
			if b {
				claims = append(claims, "read-only")
			} else {
				claims = append(claims, "mutating")
			}
		} else {
			return r, []model.Diagnostic{{Severity: "warning", Code: "RISK_INVALID", Message: "x-readonly is not boolean; risk remains unknown", Locator: loc, CanonicalID: id}}
		}
	}
	for _, a := range annotations {
		if a.Kind == "risk" && ((a.Scope == "operation" && a.TargetID == id) || (a.Scope == "variant" && a.TargetID == vid)) {
			claims = append(claims, a.Conclusion)
			r.Evidence = append(r.Evidence, a.Evidence...)
		}
	}
	selected := "unknown"
	set := map[string]bool{}
	for _, claim := range claims {
		set[claim] = true
	}
	conflict := (set["unknown"] && len(set) > 1) || (set["read-only"] && (set["mutating"] || set["destructive"]))
	if set["read-only"] {
		selected = "read-only"
	} else if set["destructive"] {
		selected = "destructive"
	} else if set["mutating"] {
		selected = "mutating"
	}
	if conflict {
		return r, []model.Diagnostic{{Severity: "warning", Code: "RISK_CONFLICT", Message: "conflicting source and reviewed risk evidence; classification remains unknown", Locator: c.OperationLocator, CanonicalID: id}}
	}
	r.Classification = selected
	switch selected {
	case "read-only":
		r.DestructivePotential = "no"
	case "destructive":
		r.DestructivePotential = "yes"
	}
	return r, nil
}
