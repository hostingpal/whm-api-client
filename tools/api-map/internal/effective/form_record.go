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
var formRecordTag = regexp.MustCompile(`^[A-Z][A-Z0-9]*$`)

// formRecord lowers the reviewed WHM anyOf into a common scalar parameter list
// and a single typed RDATA argument. Each alternative must extend the same
// referenced object and contribute exactly one object of scalar fields.
func (b *builder) formRecord(op model.Operation, v model.Variant, ov overlay.Operation, node map[string]any, method string) (*FormRecord, []Param, error) {
	d := ov.FormRecord
	edit := op.ID == "whm1:editzonerecord"
	if (!edit && op.ID != "whm1:addzonerecord") || op.Family != "whm1" || v.Method != "POST" || ov.Method != "POST" || len(v.Parameters) != 0 || d.RetainExisting != edit {
		return nil, nil, fmt.Errorf("requires the reviewed DNS POST variant and matching edit policy")
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
	body, _, _, ok := b.schemas.follow(source, node["requestBody"])
	content, _ := asObject(body["content"])
	media, found := asObject(content["application/x-www-form-urlencoded"])
	if !ok || !found || len(content) != 1 || len(media) != 1 {
		return nil, nil, fmt.Errorf("requires a single unencoded form media schema")
	}
	schema, source, _, ok := b.schemas.follow(source, media["schema"])
	disc, _ := asObject(schema["discriminator"])
	mapping, _ := asObject(disc["mapping"])
	alternatives, _ := schema["anyOf"].([]any)
	if !ok || len(schema) != 2 || disc["propertyName"] != "type" || len(mapping) == 0 || len(mapping) != len(alternatives) {
		return nil, nil, fmt.Errorf("requires type discriminator with one mapping per alternative")
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
	record := &FormRecord{Alias: Pascal(method) + "Input", Required: !edit, Untagged: edit}
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
		alt, _, _, ok := b.schemas.follow(source, map[string]any{"$ref": ref})
		parts, _ := alt["allOf"].([]any)
		if !ok || len(alt) != 1 || len(parts) != 2 {
			return nil, nil, fmt.Errorf("%s needs an allOf base and fields", tag)
		}
		first, _ := asObject(parts[0])
		baseRef := text(first["$ref"])
		if len(first) != 1 || !strings.HasPrefix(baseRef, "#/components/schemas/") || (baseReference != "" && baseReference != baseRef) {
			return nil, nil, fmt.Errorf("%s has inconsistent base reference", tag)
		}
		if commonMap == nil {
			baseReference = baseRef
			base, _, _, ok := b.schemas.follow(source, first)
			if !ok {
				return nil, nil, fmt.Errorf("unresolved base")
			}
			var err error
			commonMap, err = formFields(base, d.Common)
			if err != nil {
				return nil, nil, fmt.Errorf("base: %w", err)
			}
			discriminator, ok := commonMap["type"]
			if !ok || discriminator.Type != "string" || discriminator.Required != record.Required {
				return nil, nil, fmt.Errorf("base discriminator does not match record policy")
			}
			delete(commonMap, "type")
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
			if _, overlap := commonMap[name]; overlap || name == "type" || name == "record" {
				return nil, nil, fmt.Errorf("%s field %s collides with common input", tag, name)
			}
			f := fields[name]
			// The edit handler merges supplied fields over the current line.
			if edit {
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
	target, typ := "name", "string"
	if edit {
		target, typ = "line", "int"
	}
	if domain := commonMap["domain"]; !domain.Required || domain.Type != "string" || !commonMap[target].Required || commonMap[target].Type != typ {
		return nil, nil, fmt.Errorf("requires a domain and the %s target", target)
	}
	var params []Param
	for _, name := range sortedKeys(commonMap) {
		f := commonMap[name]
		if name == "record" || !IsMethodName(name) || reservedArguments[name] {
			return nil, nil, fmt.Errorf("invalid common parameter %q", name)
		}
		param := Param{
			PHP: name, Wire: name, Source: name, Required: f.Required, PHPType: f.Type, DocType: f.Type,
			Encoding: "scalar", Description: firstSentence(f.Description),
		}
		if edit && name == "line" {
			param.PositiveInt = true
			param.DocType = "positive-int"
		}
		params = append(params, param)
	}
	params = append(params, Param{PHP: "record", Wire: "record", Required: record.Required, PHPType: "array", DocType: record.Alias, Encoding: "form_record", Sensitive: true, Description: "Record type and its fields, sent as individual form values."})
	params = orderParams(params, []string{"domain", "line", "name", "record"})
	sort.SliceStable(params, func(i, j int) bool { return params[i].Required && !params[j].Required })
	return record, params, nil
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
