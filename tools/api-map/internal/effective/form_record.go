package effective

import (
	"fmt"
	"regexp"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

var formFieldName = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)
var formRecordTag = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9_-]*$`)

// formRecord lowers the reviewed WHM anyOf into a common scalar parameter list
// and a single typed RDATA argument. Each alternative must extend the same
// referenced object and contribute exactly one object of scalar fields.
func (b *builder) formRecord(op model.Operation, v model.Variant, ov overlay.Operation, node map[string]any, method string) (*FormRecord, []Param, error) {
	d := ov.FormRecord
	if op.Family != "whm1" || v.Method != "POST" || ov.Method != "POST" || len(v.Parameters) != 0 ||
		!IsMethodName(d.PHP) || reservedArguments[d.PHP] {
		return nil, nil, fmt.Errorf("requires WHM form POST without query parameters and a valid PHP argument")
	}
	if v.OperationLocator.Pointer == nil || !boundInputEvidence(ov.Evidence, v.OperationLocator, *v.OperationLocator.Pointer+"/requestBody") {
		return nil, nil, fmt.Errorf("needs doc evidence bound to the selected requestBody pointer and source hash")
	}
	// The pinned source establishes composition. Handler source or live evidence
	// separately justifies the field and partial-update corrections.
	handlerEvidence := false
	for _, e := range ov.Evidence {
		if (e.Kind == "doc" || e.Kind == "live") && e.SourceSHA256 == "" && e.Version != "" && e.Date != "" && e.Note != "" {
			handlerEvidence = true
		}
	}
	if !handlerEvidence {
		return nil, nil, fmt.Errorf("requires separate versioned handler or live evidence")
	}
	source := v.OperationLocator.SourceID
	body, bodySource, _, ok := b.schemas.follow(source, node["requestBody"])
	content, _ := asObject(body["content"])
	media, found := asObject(content["application/x-www-form-urlencoded"])
	if !ok || bodySource != source || !found || len(content) != 1 || len(media) != 1 {
		return nil, nil, fmt.Errorf("requires a single unencoded form media schema")
	}
	schema, schemaSource, _, ok := b.schemas.follow(source, media["schema"])
	disc, _ := asObject(schema["discriminator"])
	mapping, _ := asObject(disc["mapping"])
	alternatives, _ := schema["anyOf"].([]any)
	discriminator := text(disc["propertyName"])
	if !ok || schemaSource != source || len(schema) != 2 || !formFieldName.MatchString(discriminator) || len(mapping) == 0 || len(mapping) != len(alternatives) {
		return nil, nil, fmt.Errorf("requires a named discriminator with one mapping per alternative")
	}
	refs := map[string]bool{}
	for _, alt := range alternatives {
		r, _ := asObject(alt)
		ref := text(r["$ref"])
		if len(r) != 1 || !strings.HasPrefix(ref, "#/components/schemas/") || refs[ref] {
			return nil, nil, fmt.Errorf("invalid or duplicate anyOf reference")
		}
		refs[ref] = true
	}
	record := &FormRecord{Alias: Pascal(method) + "Input", PHP: d.PHP, Discriminator: discriminator, Untagged: d.RetainExisting, ChangeParams: d.ChangeParams}
	var commonProps map[string]any
	var commonMap map[string]FormField
	var baseReference string
	tags := map[string]bool{}
	for _, label := range sortedKeys(mapping) {
		ref := text(mapping[label])
		if !refs[ref] {
			return nil, nil, fmt.Errorf("discriminator mapping does not match anyOf references")
		}
		delete(refs, ref)
		tag := label
		if name, changed := d.Labels[label]; changed {
			tag = name
		}
		if !formRecordTag.MatchString(tag) || tags[tag] {
			return nil, nil, fmt.Errorf("invalid or duplicate variant %q", tag)
		}
		tags[tag] = true
		alt, altSource, _, ok := b.schemas.follow(source, map[string]any{"$ref": ref})
		parts, _ := alt["allOf"].([]any)
		if !ok || altSource != source || len(alt) != 1 || len(parts) != 2 {
			return nil, nil, fmt.Errorf("%s needs an allOf base and fields", tag)
		}
		first, _ := asObject(parts[0])
		baseRef := text(first["$ref"])
		if len(first) != 1 || !strings.HasPrefix(baseRef, "#/components/schemas/") || (baseReference != "" && baseReference != baseRef) {
			return nil, nil, fmt.Errorf("%s has inconsistent base reference", tag)
		}
		if commonMap == nil {
			baseReference = baseRef
			base, baseSource, baseKey, ok := b.schemas.follow(source, first)
			if !ok || baseSource != source || baseKey != source+baseRef {
				return nil, nil, fmt.Errorf("unresolved base")
			}
			var err error
			commonMap, err = formFields(base, d.Common)
			if err != nil {
				return nil, nil, fmt.Errorf("base: %w", err)
			}
			commonProps, _ = asObject(base["properties"])
			selector, ok := commonMap[discriminator]
			if !ok || selector.Type != "string" || selector.Required && d.RetainExisting {
				return nil, nil, fmt.Errorf("base discriminator must be a string and optional for untagged updates")
			}
			record.Required = selector.Required
			delete(commonMap, discriminator)
		}
		spec, ok := asObject(parts[1])
		if !ok {
			return nil, nil, fmt.Errorf("%s has no field object", tag)
		}
		fields, err := formFields(spec, d.Variants[tag])
		if err != nil {
			return nil, nil, fmt.Errorf("%s: %w", tag, err)
		}
		variant := FormVariant{Tag: tag}
		for _, name := range sortedKeys(fields) {
			if _, overlap := commonMap[name]; overlap || name == discriminator {
				return nil, nil, fmt.Errorf("%s field %s collides with common input", tag, name)
			}
			f := fields[name]
			// The edit handler merges supplied fields over the current line.
			if d.RetainExisting {
				f.Required = false
			}
			variant.Fields = append(variant.Fields, f)
		}
		record.Variants = append(record.Variants, variant)
	}
	for label := range d.Labels {
		if _, ok := mapping[label]; !ok {
			return nil, nil, fmt.Errorf("unknown mapping correction %q", label)
		}
	}
	for tag := range d.Variants {
		if !tags[tag] {
			return nil, nil, fmt.Errorf("unknown variant correction %q", tag)
		}
	}
	sort.Slice(record.Variants, func(i, j int) bool { return record.Variants[i].Tag < record.Variants[j].Tag })
	params, err := b.formRecordParams(op, v, ov, record, commonMap, commonProps, strings.TrimPrefix(baseReference, "#"))
	return record, params, err
}

// Common arguments use the ordinary parameter lowering and its provenance and guard checks.
// Only the composite record itself bypasses scalar parameter serialization.
func (b *builder) formRecordParams(op model.Operation, v model.Variant, ov overlay.Operation, record *FormRecord, common map[string]FormField, props map[string]any, basePointer string) ([]Param, error) {
	var body []model.Parameter
	for _, name := range sortedKeys(common) {
		f := common[name]
		raw, pinned := props[name]
		locator := model.Locator{SourceID: v.OperationLocator.SourceID}
		if pinned {
			pointer := basePointer + "/properties/" + name
			locator.SourceSHA256 = v.OperationLocator.SourceSHA256
			locator.Pointer = &pointer
		} else {
			typ := f.Type
			if typ == "int" {
				typ = "integer"
			}
			raw = map[string]any{"type": typ}
		}
		body = append(body, model.Parameter{Name: name, In: "body", Locator: locator, Definition: model.Object{
			"required": f.Required, "schema": raw, "description": f.Description,
		}})
	}
	scalarOverlay := ov
	scalarOverlay.Order = nil
	for _, name := range ov.Order {
		if name != record.PHP {
			scalarOverlay.Order = append(scalarOverlay.Order, name)
		}
	}
	params := b.params(op, v, scalarOverlay, body)
	mutable := map[string]bool{}
	occupied := map[string]bool{record.Discriminator: true}
	for _, v := range record.Variants {
		for _, f := range v.Fields {
			occupied[f.Name] = true
		}
	}
	for _, p := range params {
		if p.Encoding != "scalar" || occupied[p.Wire] || p.PHP == record.PHP || p.Source == record.PHP {
			return nil, fmt.Errorf("common parameter %s must remain a distinct scalar", p.Source)
		}
		mutable[p.PHP] = !p.Required
	}
	for _, name := range record.ChangeParams {
		if !record.Untagged || !mutable[name] {
			return nil, fmt.Errorf("change_params must name distinct optional scalar companions of an untagged record")
		}
		delete(mutable, name)
	}
	params = append(params, Param{Source: record.PHP, PHP: record.PHP, Wire: record.PHP, Required: record.Required, PHPType: "array", DocType: record.Alias, Encoding: "form_record", Sensitive: true, Description: "Discriminator and its variant-specific fields, sent as individual form values."})
	bySource := map[string]Param{}
	for _, p := range params {
		bySource[p.Source] = p
	}
	var wireOrder []string
	optional := false
	for _, name := range ov.Order {
		if p, found := bySource[name]; found {
			if optional && p.Required {
				return nil, fmt.Errorf("order places required argument %s after an optional argument", name)
			}
			optional = !p.Required
			wireOrder = append(wireOrder, p.Wire)
		}
	}
	params = orderParams(params, wireOrder)
	sort.SliceStable(params, func(i, j int) bool { return params[i].Required && !params[j].Required })
	return params, nil
}

// formFields preserves source requiredness unless the overlay explicitly
// replaces it. Added handler fields need a scalar type and a description.
func formFields(node map[string]any, changes overlay.FormRecordChanges) (map[string]FormField, error) {
	props, ok := asObject(node["properties"])
	if !ok || node["type"] != "object" || len(props) == 0 {
		return nil, fmt.Errorf("expected an object with named properties")
	}
	for key := range node {
		if key != "properties" && key != "required" && key != "type" && key != "description" {
			return nil, fmt.Errorf("unsupported object keyword %q", key)
		}
	}
	fields := map[string]FormField{}
	for _, name := range sortedKeys(props) {
		p, _ := asObject(props[name])
		typ := formScalar(p)
		if !formFieldName.MatchString(name) || typ == "" {
			return nil, fmt.Errorf("unsupported form property %q", name)
		}
		fields[name] = FormField{Name: name, Type: typ, Description: text(p["description"])}
	}
	for _, name := range changes.Omit {
		if _, ok := fields[name]; !ok {
			return nil, fmt.Errorf("cannot omit missing field %q", name)
		}
		delete(fields, name)
	}
	for _, name := range sortedKeys(changes.Add) {
		f := changes.Add[name]
		if _, exists := fields[name]; exists || !formFieldName.MatchString(name) || (f.Type != "int" && f.Type != "string") || strings.TrimSpace(f.Description) == "" {
			return nil, fmt.Errorf("invalid added field %q", name)
		}
		fields[name] = FormField{Name: name, Type: f.Type, Description: f.Description}
	}
	required := stringList(node["required"])
	if raw, present := node["required"]; present {
		names, ok := raw.([]any)
		if !ok || len(names) != len(required) {
			return nil, fmt.Errorf("required must list field names")
		}
	}
	if changes.Required != nil {
		required = *changes.Required
	}
	for _, name := range required {
		f, ok := fields[name]
		if !ok || f.Required {
			return nil, fmt.Errorf("invalid required field %q", name)
		}
		f.Required = true
		fields[name] = f
	}
	return fields, nil
}

// class and flatten_to use oneOf alternatives with different annotations
// of the same string type. No other form composition or nullable value is accepted.
func formScalar(p map[string]any) string {
	for key := range p {
		switch key {
		case "description", "type", "example", "format", "minimum", "enum", "oneOf", "deprecated", "default":
		default:
			return ""
		}
	}
	if p["format"] == "binary" || p["format"] == "byte" {
		return ""
	}
	if raw, hasOptions := p["oneOf"]; hasOptions {
		options, ok := raw.([]any)
		if !ok || len(options) == 0 || p["type"] != nil {
			return ""
		}
		for _, raw := range options {
			part, _ := asObject(raw)
			if part["type"] != "string" || formScalar(part) != "string" {
				return ""
			}
		}
		return "string"
	}
	switch p["type"] {
	case "integer":
		return "int"
	case "string":
		return "string"
	}
	return ""
}
