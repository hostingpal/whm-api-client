package mapbuild

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

const (
	whmSpecHash   = "6edb13c51d53f3c2be1fa83cc93e31d3a81f4f8506b3b45284fa00b986041594"
	uapiSpecHash  = "172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc"
	whmTokenHash  = "d7f8f62dbe58cb2dbdb4ad37b7edc6bac5469a114b7eddee7af7dc8cd51e924d"
	uapiTokenHash = "23196e776440d6c2697d2d760216bbfbbd8884a4a71e6ab53fbeb2ce948800c1"
)

type auditReview struct {
	code, message, id string
	// Every dependency must still match the review. The first locator attributes
	// the unchanged conclusion; others bind comparisons to their exact sources.
	evidence []model.Locator
}

func auditProse(id, hash, locator string) model.Locator {
	return model.Locator{SourceID: id, SourceSHA256: hash, ProseLocator: &locator}
}
func auditPointer(id, hash, pointer string) model.Locator {
	return model.Locator{SourceID: id, SourceSHA256: hash, Pointer: &pointer}
}

// Reviewed facts require all source dependencies. A changed or missing source
// triggers review-needed evidence without repeating the old factual conclusion.
func auditDiagnostics(bundle source.Bundle, records map[string]model.Object, ops map[string]*model.Operation) []model.Diagnostic {
	reviews := []auditReview{
		{"TOKEN_SCHEME_DISCREPANCY", "WHM PHP explanation names token; explicit header instruction and code use whm. Preserve the discrepancy.", "", []model.Locator{auditProse("whm--tokens", whmTokenHash, "#php")}},
		{"TLS_EXAMPLE_DISCREPANCY", "Token examples disable TLS verification; examples do not establish project transport policy.", "", []model.Locator{auditProse("whm--tokens", whmTokenHash, "#example-scripts")}},
		{"TLS_EXAMPLE_DISCREPANCY", "Token examples disable TLS verification; examples do not establish project transport policy.", "", []model.Locator{auditProse("cpanel--tokens", uapiTokenHash, "#using-an-api-token")}},
		{"TRANSPORT_DISCREPANCY", "SSL tutorial describes POST while OpenAPI declares GET; no canonical transport is added.", "uapi:SSL/install_ssl", []model.Locator{
			auditProse("guides--quickstart-development-guide--tutorial-call-uapis-ssl-install-ssl-function-in-custom-code", "5a9fab48ecf676bbb40933d9a4071ab8e427c8f230710f1304a4acb5c955bd11", "#create-a-post-request-for-the-call-1"),
			auditPointer("cpanel-openapi", uapiSpecHash, "/paths/~1SSL~1install_ssl/get"),
		}},
		{"BRIDGE_ROUTE_DISCREPANCY", "Bridge guide prints json_api; established route evidence uses json-api.", "", []model.Locator{
			auditProse("whm--use-whm-api-to-call-cpanel-api-and-uapi", "d033ee6482ca0627dea1d791d4be584d4d129a2d477b6b4874044534159e9024", "#function"),
			auditProse("whm--introduction", "86e7c0bc77fb4827b5768a1113b21c6a59c527609ac67d38bcedfa3b3a91730a", "#json-api"),
		}},
		// Both placement/prose claims below are contained in their source operation.
		{"BODY_PLACEMENT_UNRESOLVED", "POST is documented with query parameters despite prose URL-length guidance; request-body placement remains unresolved.", "whm1:php_ini_set_content", []model.Locator{auditPointer("whm-openapi", whmSpecHash, "/paths/~1php_ini_set_content/post")}},
		{"RESPONSE_SEMANTICS_UNRESOLVED", "Feature status has operation-specific meaning; generic success/failure examples do not define its contract.", "uapi:Features/has_feature", []model.Locator{auditPointer("cpanel-openapi", uapiSpecHash, "/paths/~1Features~1has_feature/get")}},
		{"NULL_WIRE_SEMANTICS_UNRESOLVED", "Required nullable query fields and explicit null defaults do not define query-null wire encoding.", "uapi:SiteQuality/get_all_scores", []model.Locator{auditPointer("cpanel-openapi", uapiSpecHash, "/paths/~1SiteQuality~1get_all_scores/post")}},
	}
	out := []model.Diagnostic{}
	for _, review := range reviews {
		if review.id != "" && ops[review.id] == nil {
			continue
		}
		current := true
		var changed *model.Locator
		var fallback *model.Locator
		for _, e := range review.evidence {
			rec := records[e.SourceID]
			if rec != nil {
				loc := currentAuditLocator(bundle, e.SourceID, rec)
				if fallback == nil {
					fallback = &loc
				}
				if rec["sha256"] != e.SourceSHA256 && changed == nil {
					changed = &loc
				}
			}
			if rec == nil || rec["sha256"] != e.SourceSHA256 {
				current = false
				continue
			}
			if e.Pointer != nil {
				if _, err := source.Pointer(bundle.Documents[e.SourceID], *e.Pointer); err != nil {
					current = false
					if changed == nil {
						loc := currentAuditLocator(bundle, e.SourceID, rec)
						changed = &loc
					}
				}
			}
		}
		if !current {
			if changed == nil {
				changed = fallback
			}
			if changed != nil {
				out = append(out, model.Diagnostic{Severity: "warning", Code: "AUDIT_REVIEW_NEEDED", Message: "reviewed " + review.code + " source dependencies changed or are missing; review required before reusing its conclusion", CanonicalID: review.id, Locator: *changed})
			}
			continue
		}
		out = append(out, model.Diagnostic{Severity: "warning", Code: review.code, Message: review.message, CanonicalID: review.id, Locator: review.evidence[0]})
	}
	return out
}

// Review-needed locators use the current document root, never stale anchors.
func currentAuditLocator(bundle source.Bundle, id string, record model.Object) model.Locator {
	hash, _ := record["sha256"].(string)
	if bundle.Documents[id] != nil {
		return auditPointer(id, hash, "")
	}
	return auditProse(id, hash, "document: audit dependency requires review")
}
