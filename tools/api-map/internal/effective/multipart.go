package effective

import (
	"reflect"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// multipartBody is deliberately limited to reviewed inline string fields. Query
// declarations may duplicate matching body fields; their requiredness is retained.
// Any contradictory source name needs an explicit, evidence-backed body_fields map.
func (b *builder) multipartBody(op model.Operation, v model.Variant, ov overlay.Operation, node map[string]any) ([]model.Parameter, bool) {
	if op.Family != "uapi" {
		b.problem(op.ID + ": multipart bodies require UAPI")
		return nil, false
	}
	body, source, _, ok := b.schemas.follow(v.OperationLocator.SourceID, node["requestBody"])
	content, _ := asObject(body["content"])
	media, found := asObject(content["multipart/form-data"])
	if !ok || !found {
		b.problem(op.ID + ": multipart body is not documented")
		return nil, false
	}
	schema, source, _, ok := b.schemas.follow(source, media["schema"])
	props, found := asObject(schema["properties"])
	if !ok || !found || len(props) == 0 || schema["type"] != "object" || schema["additionalProperties"] != nil || schema["oneOf"] != nil || schema["anyOf"] != nil || schema["allOf"] != nil {
		b.problem(op.ID + ": multipart body requires plain object properties")
		return nil, false
	}
	query := map[string]model.Parameter{}
	for _, p := range v.Parameters {
		if p.In == "query" {
			query[p.Name] = p
		}
	}
	for from, to := range ov.BodyFields {
		if _, exists := props[from]; !exists {
			b.problem(op.ID + ": body_fields names an undocumented multipart field")
			return nil, false
		}
		if _, exists := query[to]; !exists {
			b.problem(op.ID + ": body_fields target must name a documented query parameter")
			return nil, false
		}
	}
	required := map[string]bool{}
	for _, name := range stringList(schema["required"]) {
		required[name] = body["required"] == true
	}
	matched := map[string]bool{}
	params := []model.Parameter{}
	for _, sourceName := range sortedKeys(props) {
		name := sourceName
		if target, exists := ov.BodyFields[sourceName]; exists {
			name = target
		}
		if matched[name] {
			b.problem(op.ID + ": multipart fields resolve to duplicate names")
			return nil, false
		}
		matched[name] = true
		field, _, _, valid := b.schemas.follow(source, props[sourceName])
		if !valid || !multipartString(field) {
			b.problem(op.ID + ": multipart fields must have inline string schemas; binary file fields are unsupported")
			return nil, false
		}
		if ov.Params[name].Omit {
			b.problem(op.ID + ": multipart fields cannot be omitted")
			return nil, false
		}
		if q, exists := query[name]; exists {
			qschema, _, _, valid := b.schemas.follow(q.Locator.SourceID, q.Definition["schema"])
			if !valid || !reflect.DeepEqual(multipartContract(field), multipartContract(qschema)) {
				b.problem(op.ID + ": query and multipart field schemas disagree for " + name)
				return nil, false
			}
			if required[sourceName] && q.Definition["required"] != true {
				b.problem(op.ID + ": multipart required field conflicts with optional query declaration")
				return nil, false
			}
			continue
		}
		params = append(params, model.Parameter{Name: name, In: "body", Definition: model.Object{
			"name": name, "in": "body", "required": required[sourceName], "description": field["description"], "schema": props[sourceName],
		}, Locator: model.Locator{SourceID: source}})
	}
	for _, name := range sortedKeys(query) {
		if !matched[name] {
			b.problem(op.ID + ": query parameter " + name + " has no matching multipart field")
			return nil, false
		}
	}
	return params, true
}

func multipartString(schema map[string]any) bool {
	return schema["type"] == "string" && schema["format"] != "binary" && schema["nullable"] != true && schema["oneOf"] == nil && schema["anyOf"] == nil && schema["allOf"] == nil
}

// Documentation annotations do not change the duplicate field's wire contract.
func multipartContract(schema map[string]any) map[string]any {
	result := map[string]any{}
	for k, v := range schema {
		switch k {
		case "description", "example", "examples", "default", "title", "deprecated":
			continue
		}
		result[k] = v
	}
	return result
}
