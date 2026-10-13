package openapi

import (
	"fmt"
	"regexp"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

var responseKeyPattern = regexp.MustCompile(`^(default|[1-5][0-9]{2}|[1-5]XX)$`)

func (a *analyzer) validateDeclarations(o model.Object, kind string, loc model.Locator) {
	if _, present := o["$ref"]; present && !supportsReference(kind) {
		a.diagnostic("warning", "UNINTERPRETED_FIELD", "unsupported $ref field is retained as source data", a.index.locator(loc.SourceID, *loc.Pointer+"/$ref"))
	}

	if kind == "document" || kind == "path" || kind == "operation" {
		if v, present := o["servers"]; present {
			arr, ok := v.([]any)
			if !ok {
				a.fail("MALFORMED_SERVERS", fmt.Errorf("servers must be an array"), loc)
			} else {
				for n, v := range arr {
					srv, ok := object(v)
					url, uok := srv["url"].(string)
					if !ok || !uok || url == "" {
						a.fail("MALFORMED_SERVER", fmt.Errorf("server requires url"), a.index.locator(loc.SourceID, *loc.Pointer+fmt.Sprintf("/servers/%d", n)))
					}
				}
			}
		}
		if kind != "path" {
			if v, present := o["security"]; present {
				arr, ok := v.([]any)
				if !ok {
					a.fail("MALFORMED_SECURITY", fmt.Errorf("security must be an array"), loc)
				} else {
					for n, v := range arr {
						sec, ok := object(v)
						sloc := a.index.locator(loc.SourceID, *loc.Pointer+fmt.Sprintf("/security/%d", n))
						if !ok {
							a.fail("MALFORMED_SECURITY", fmt.Errorf("security requirement must be an object"), sloc)
							continue
						}
						for _, val := range sec {
							scopes, ok := val.([]any)
							if !ok {
								a.fail("MALFORMED_SECURITY", fmt.Errorf("security scopes must be an array"), sloc)
								continue
							}
							for _, scope := range scopes {
								if _, ok := scope.(string); !ok {
									a.fail("MALFORMED_SECURITY", fmt.Errorf("security scope must be a string"), sloc)
								}
							}
						}
					}
				}
			}
		}
	}
	if kind == "operation" {
		responses, ok := object(o["responses"])
		rloc := a.index.locator(loc.SourceID, *loc.Pointer+"/responses")
		if !ok {
			a.fail("MALFORMED_RESPONSES", fmt.Errorf("operation requires a responses object"), rloc)
		} else {
			declarations := 0
			for _, key := range keys(responses) {
				if strings.HasPrefix(key, "x-") {
					continue
				}
				if !responseKeyPattern.MatchString(key) {
					a.fail("MALFORMED_RESPONSE_KEY", fmt.Errorf("response key must be an HTTP status code, uppercase range, or default"), a.index.locator(loc.SourceID, *rloc.Pointer+"/"+token(key)))
				} else {
					declarations++
				}
			}
			if declarations == 0 {
				a.fail("MALFORMED_RESPONSES", fmt.Errorf("operation requires at least one response declaration"), rloc)
			}
		}
	}
	if kind == "parameter" || kind == "header" {
		_, schema := o["schema"]
		_, content := o["content"]
		if schema == content {
			a.fail("MALFORMED_PARAMETER", fmt.Errorf("parameter requires exactly one of schema or content"), loc)
		}
		if content {
			media, ok := object(o["content"])
			if !ok || len(media) != 1 {
				a.fail("MALFORMED_PARAMETER", fmt.Errorf("parameter content requires exactly one media type"), loc)
			}
		}
		if kind == "parameter" {
			name, nok := o["name"].(string)
			in, iok := o["in"].(string)
			if !nok || name == "" || !iok || (in != "query" && in != "header" && in != "path" && in != "cookie") {
				a.fail("MALFORMED_PARAMETER", fmt.Errorf("parameter requires a name and supported location"), loc)
			}
			if in == "path" && o["required"] != true {
				a.fail("MALFORMED_PARAMETER", fmt.Errorf("path parameters must be required"), loc)
			}
		}
	}
	if kind == "requestBody" {
		m, ok := object(o["content"])
		if !ok || len(m) == 0 {
			a.fail("MALFORMED_REQUEST_BODY", fmt.Errorf("request body requires nonempty content"), loc)
		}
	}
	if kind == "path" || kind == "operation" {
		supported := map[string]bool{}
		fields := []string{"$ref", "summary", "description", "servers", "parameters"}
		if kind == "operation" {
			fields = []string{"tags", "summary", "description", "externalDocs", "operationId", "parameters", "requestBody", "responses", "callbacks", "deprecated", "security", "servers"}
		}
		for _, k := range fields {
			supported[k] = true
		}
		for _, k := range keys(o) {
			if supported[k] || (kind == "path" && methods[k]) || strings.HasPrefix(k, "x-") {
				continue
			}
			fieldloc := a.index.locator(loc.SourceID, *loc.Pointer+"/"+token(k))
			if kind == "path" && methods[strings.ToLower(k)] {
				a.fail("MALFORMED_METHOD", fmt.Errorf("HTTP method keys must be lowercase"), fieldloc)
			} else {
				a.diagnostic("warning", "UNINTERPRETED_FIELD", "unknown OpenAPI control field is retained in source document", fieldloc)
			}
		}
	}
}
