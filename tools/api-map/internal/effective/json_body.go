package effective

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// InputSpec is the strict request grammar, independent of response compatibility.
type InputSpec struct {
	Type     string
	Nullable bool
	Nonempty bool
	Fields   []InputField
	Required []string
	Items    *InputSpec
	Values   *InputSpec
	AnyOf    []InputSpec
}
type InputField struct {
	Name string
	Spec InputSpec
}
type JSONBody struct {
	PHP, Alias, Routes string
	Required           bool
	Schema             InputSpec
}

func boundInputEvidence(evidence []overlay.Evidence, locator model.Locator, pointer string) bool {
	if locator.SourceSHA256 == "" || pointer == "" {
		return false
	}
	for _, e := range evidence {
		if e.Kind == "doc" && e.SourceSHA256 == locator.SourceSHA256 && e.Pointer == pointer && e.Note != "" {
			return true
		}
	}
	return false
}

func (b *builder) jsonBody(op model.Operation, v model.Variant, ov overlay.Operation, node map[string]any, params *[]Param) *JSONBody {
	fail := func(message string) *JSONBody { b.problem(op.ID + ": JSON body " + message); return nil }
	d := ov.JSONBody
	if d == nil || !IsMethodName(d.PHP) || reservedArguments[d.PHP] || !IsClassName(d.Alias) || !strings.HasSuffix(d.Alias, "Input") || d.Routes != "direct" {
		return fail("requires PHP argument, Input alias and direct routes")
	}
	if len(ov.AdditionalMethods) > 0 {
		return fail("does not support additional methods")
	}
	if v.OperationLocator.Pointer == nil {
		return fail("needs exact source locator")
	}
	pointer := *v.OperationLocator.Pointer + "/requestBody"
	if !boundInputEvidence(ov.Evidence, v.OperationLocator, pointer) {
		return fail("needs doc evidence matching source_sha256 and requestBody pointer")
	}
	body, source, resolved, ok := b.schemas.follow(v.OperationLocator.SourceID, node["requestBody"])
	content, _ := asObject(body["content"])
	media, found := asObject(content["application/json"])
	if !ok || !found || len(content) != 1 {
		return fail("requires a selected application/json source body")
	}
	if resolved != "" {
		_, resolvedPointer, found := strings.Cut(resolved, "#")
		if !found {
			return fail("unresolved source requestBody pointer")
		}
		pointer = resolvedPointer
	}
	required := body["required"] == true
	if d.Required != nil {
		required = *d.Required
	}
	used := map[string]bool{}
	schema, err := b.schemas.inputFor(source, media["schema"], 0, 0, "", d.Corrections, used, v.OperationLocator, pointer+"/content/application~1json/schema")
	if err != nil {
		return fail(err.Error())
	}
	for p := range d.Corrections {
		if !used[p] {
			return fail("correction does not select a source schema node")
		}
	}
	if !required && inputHasNull(schema) {
		return fail("optional nullable roots are unsupported")
	}
	if schema.Type == "object" {
		for _, name := range schema.Required {
			if reservedJSONName(name, op.Family) {
				return fail("requires a reserved root routing member")
			}
		}
	}
	for _, p := range *params {
		if p.PHP == d.PHP {
			return fail("argument name collides with a query parameter")
		}
	}
	bodyParam := Param{PHP: d.PHP, Required: required, Sensitive: true, Input: &schema, PHPType: InputNative(schema), DocType: d.Alias}
	*params = append(*params, bodyParam)
	sort.SliceStable(*params, func(i, j int) bool { return (*params)[i].Required && !(*params)[j].Required })
	return &JSONBody{PHP: d.PHP, Alias: d.Alias, Routes: d.Routes, Required: required, Schema: schema}
}
func reservedJSONName(name, family string) bool {
	return strings.HasPrefix(name, "api.") || family == "uapi" && (name == "cpanel.user" || name == "cpanel.module" || name == "cpanel.function")
}
func inputHasNull(s InputSpec) bool {
	if s.Nullable || s.Type == "null" {
		return true
	}
	for _, a := range s.AnyOf {
		if inputHasNull(a) {
			return true
		}
	}
	return false
}

func (s schemas) inputFor(source string, raw any, containers, descriptors int, path string, corrections map[string]overlay.InputCorrection, used map[string]bool, locator model.Locator, pointer string) (InputSpec, error) {
	fail := func(m string) (InputSpec, error) { return InputSpec{}, fmt.Errorf("%s", m) }
	obj, source, resolved, ok := s.follow(source, raw)
	if !ok || descriptors > 512 {
		return fail("unresolved or excessive schema depth")
	}
	if resolved != "" {
		_, resolvedPointer, found := strings.Cut(resolved, "#")
		if !found {
			return fail("unresolved source schema pointer")
		}
		pointer = resolvedPointer
	}
	if len(corrections) > 0 && locator.SourceID != "" && source != locator.SourceID {
		return fail("cross-document input corrections require separate source provenance")
	}
	for _, key := range []string{"allOf", "not", "discriminator"} {
		if _, ok := obj[key]; ok {
			return fail("unsupported composition")
		}
	}
	if obj["format"] == "binary" || obj["format"] == "byte" {
		return fail("binary schemas are unsupported")
	}
	if value, exists := obj["nullable"]; exists {
		if _, ok := value.(bool); !ok {
			return fail("nullable must be boolean")
		}
	}
	if value, exists := obj["properties"]; exists {
		if _, ok := asObject(value); !ok {
			return fail("properties must be an object")
		}
	}
	if value, exists := obj["required"]; exists {
		names, ok := value.([]any)
		if !ok {
			return fail("required must be a string list")
		}
		seen := map[string]bool{}
		for _, rawName := range names {
			name, ok := rawName.(string)
			if !ok || seen[name] {
				return fail("required must be a unique string list")
			}
			seen[name] = true
		}
	}
	result := InputSpec{Nullable: obj["nullable"] == true}
	correction, hasCorrection := corrections[path]
	if hasCorrection {
		used[path] = true
		if !boundInputEvidence(correction.Evidence, locator, pointer) {
			return fail("correction needs exact source evidence")
		}
	}
	recur := func(value any, suffix string) (InputSpec, error) {
		return s.inputFor(source, value, containers, descriptors+1, path+suffix, corrections, used, locator, pointer+suffix)
	}
	for _, key := range []string{"oneOf", "anyOf"} {
		if branches, exists := obj[key]; exists {
			if obj["type"] != nil || obj["properties"] != nil || obj["items"] != nil || (key == "oneOf" && obj["anyOf"] != nil) || (key == "anyOf" && obj["oneOf"] != nil) {
				return fail("unsupported sibling composition")
			}
			options, ok := branches.([]any)
			if !ok || len(options) < 2 {
				return fail("scalar alternatives require two branches")
			}
			result.Type = "union"
			domains := map[string]bool{}
			for i, branch := range options {
				a, err := recur(branch, fmt.Sprintf("/%s/%d", key, i))
				if err != nil {
					return fail(err.Error())
				}
				d := []string{a.Type}
				if a.Type == "number" {
					d = []string{"int", "float"}
				}
				if a.Type == "flag" {
					d = []string{"bool"}
				}
				if a.Nullable && a.Type != "null" {
					d = append(d, "null")
				}
				if a.Type == "object" || a.Type == "list" || a.Type == "union" {
					return fail("alternatives must have disjoint scalar domains")
				}
				for _, v := range d {
					if domains[v] {
						return fail("alternatives must have disjoint scalar domains")
					}
					domains[v] = true
				}
				result.AnyOf = append(result.AnyOf, a)
			}
			if hasCorrection {
				return fail("correction cannot target alternatives")
			}
			return result, nil
		}
	}
	typ, ok := obj["type"].(string)
	if !ok {
		return fail("requires an explicit formal type")
	}
	switch typ {
	case "string":
		result.Type = "string"
	case "integer":
		result.Type = "int"
		if inputFlagEnum(obj["enum"]) {
			result.Type = "flag"
		}
	case "number":
		result.Type = "number"
	case "boolean":
		result.Type = "bool"
	case "null":
		result.Type = "null"
	case "object", "array":
		if containers >= 511 {
			return fail("schema exceeds nesting limit")
		}
		containers++
		recur = func(value any, suffix string) (InputSpec, error) {
			return s.inputFor(source, value, containers, descriptors+1, path+suffix, corrections, used, locator, pointer+suffix)
		}
		if typ == "array" {
			result.Type = "list"
			item, err := recur(obj["items"], "/items")
			if err != nil {
				return fail(err.Error())
			}
			result.Items = &item
		} else {
			result.Type = "object"
			props, _ := asObject(obj["properties"])
			for _, name := range sortedKeys(props) {
				field, err := recur(props[name], "/properties/"+strings.ReplaceAll(strings.ReplaceAll(name, "~", "~0"), "/", "~1"))
				if err != nil {
					return fail(err.Error())
				}
				result.Fields = append(result.Fields, InputField{Name: name, Spec: field})
			}
			result.Required = stringList(obj["required"])
			if hasCorrection && correction.Required != nil {
				result.Required = correction.Required
			}
			for _, name := range result.Required {
				if _, exists := props[name]; !exists {
					return fail("required field not declared")
				}
			}
			if extra, exists := obj["additionalProperties"]; exists && extra != false {
				if _, ok := asObject(extra); !ok {
					return fail("additional values require an explicit schema")
				}
				value, err := recur(extra, "/additionalProperties")
				if err != nil {
					return fail(err.Error())
				}
				result.Values = &value
			}
		}
	default:
		return fail("unsupported explicit type")
	}
	if hasCorrection {
		if correction.Required != nil && result.Type != "object" {
			return fail("required correction needs an object")
		}
		if correction.Nonempty != nil {
			if !*correction.Nonempty || result.Type != "list" {
				return fail("nonempty correction needs a list and true")
			}
			result.Nonempty = true
		}
	}
	return result, nil
}

// InputNative returns precise native input types, including bool for wire flags.
func InputNative(s InputSpec) string {
	var parts []string
	var add func(InputSpec)
	add = func(s InputSpec) {
		if s.Type == "union" {
			for _, a := range s.AnyOf {
				add(a)
			}
		} else {
			p := s.Type
			switch p {
			case "flag":
				p = "bool"
			case "object", "list":
				p = "array"
			case "number":
				p = "int|float"
			}
			parts = append(parts, strings.Split(p, "|")...)
		}
		if s.Nullable {
			parts = append(parts, "null")
		}
	}
	add(s)
	seen := map[string]bool{}
	var result []string
	nullable := false
	for _, p := range parts {
		if p == "null" {
			nullable = true
			continue
		}
		if !seen[p] {
			seen[p] = true
			result = append(result, p)
		}
	}
	if nullable {
		if len(result) == 0 {
			return "null"
		}
		if len(result) == 1 {
			return "?" + result[0]
		}
		result = append(result, "null")
	}
	return strings.Join(result, "|")
}

// Only the concrete formal integer [0,1] pair selects the bool-to-integer policy.
// Quoted or nullable response-style variants never enter this request decision.
func inputFlagEnum(raw any) bool {
	values, ok := raw.([]any)
	if !ok || len(values) != 2 {
		return false
	}
	seen := map[string]bool{}
	for _, value := range values {
		switch v := value.(type) {
		case json.Number:
			if v != "0" && v != "1" {
				return false
			}
		case float64:
			if v != 0 && v != 1 {
				return false
			}
		case int:
			if v != 0 && v != 1 {
				return false
			}
		default:
			return false
		}
		seen[fmt.Sprint(value)] = true
	}
	return seen["0"] && seen["1"]
}
