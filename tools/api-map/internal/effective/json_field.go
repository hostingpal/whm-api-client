package effective

import (
	"errors"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// jsonField selects either a formal embedded content schema or the explicitly
// source-prose-bound, fixed scalar map. It never replaces the canonical input.
func (b *builder) jsonField(source model.Parameter, field *overlay.JSONField, correction overlay.Param, canonical Operation, p Param, evidence []overlay.Evidence) (InputSpec, string, error) {
	invalid := func(message string) (InputSpec, string, error) { return InputSpec{}, "", errors.New(message) }
	if canonical.Body != "" && canonical.Body != "form" || source.In != "query" || source.Locator.Pointer == nil || !IsClassName(field.Alias) || !strings.HasSuffix(field.Alias, "Input") {
		return invalid("requires a canonical form body, selected JSON-content query parameter and Input alias")
	}
	pointer := *source.Locator.Pointer
	description := "Structured PHP value from the selected source schema. String contents are preserved without fetching URLs or opening files; source defaults remain upstream."
	var raw any
	if field.ScalarMap != nil {
		if !*field.ScalarMap {
			return invalid("scalar_map must be true when present")
		}
		schema, ok := asObject(source.Definition["schema"])
		_, content := source.Definition["content"]
		if !ok || content || schema["type"] != "string" || schema["format"] != "json" || p.PHPType != "string" || p.Encoding != "scalar" || p.Collection != nil || p.Input != nil || p.OmitEmpty || p.RejectEmpty || correction.Type != "" || correction.Omit {
			return invalid("scalar_map requires an unguarded concrete string format:json query source")
		}
		for key := range schema {
			switch key {
			case "type", "format", "example", "description", "title":
			default:
				return invalid("scalar_map rejects extra source schema constraints")
			}
		}
		prose, schemaBound := false, false
		for _, e := range evidence {
			if e.Kind != "doc" || strings.TrimSpace(e.Note) == "" || e.SourceSHA256 == "" || e.SourceSHA256 != source.Locator.SourceSHA256 {
				continue
			}
			prose = prose || e.Pointer == pointer || e.Pointer == pointer+"/description"
			schemaBound = schemaBound || e.Pointer == pointer+"/schema"
		}
		if !prose || !schemaBound {
			return invalid("scalar_map needs doc evidence matching source_sha256 and parameter/prose plus schema pointers")
		}
		raw = map[string]any{"type": "object", "additionalProperties": map[string]any{"anyOf": []any{map[string]any{"type": "string"}, map[string]any{"type": "number"}, map[string]any{"type": "boolean"}, map[string]any{"type": "null"}}}}
		description = "Structured object from the explicitly selected source prose: dynamic string keys with string, number, boolean or null values. Scalar null stays JSON null without promising upstream conversion or acceptance."
		pointer += "/schema"
	} else {
		content, ok := asObject(source.Definition["content"])
		media, found := asObject(content["application/json"])
		_, sibling := source.Definition["schema"]
		if sibling || !ok || !found || len(content) != 1 {
			return invalid("requires a canonical form body, selected JSON-content query parameter and Input alias")
		}
		raw = media["schema"]
		pointer += "/content/application~1json/schema"
	}
	schema, err := b.schemas.inputFor(source.Locator.SourceID, raw, 0, 0, "", nil, nil, source.Locator, pointer)
	return schema, description, err
}
