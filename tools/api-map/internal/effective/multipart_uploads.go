package effective

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"regexp"
	"strconv"
	"strings"
)

// Only explicit reviewed required binary lists are lowered. Ordinary scalar,
// map, inline-string multipart and unsupported binary shapes remain unchanged.
func (b *builder) multipartUploads(op model.Operation, v model.Variant, ov overlay.Operation, node map[string]any, params *[]Param) *overlay.MultipartUploads {
	fail := func(message string) *overlay.MultipartUploads {
		b.problem(op.ID + ": multipart uploads " + message)
		return nil
	}
	d := ov.MultipartUploads
	if ov.Body != "multipart" || op.Family != "uapi" || v.Method != "POST" || d == nil || !IsMethodName(d.PHP) || reservedArguments[d.PHP] || d.Routes != "direct" || d.Start == nil || *d.Start != 0 || !regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_-]*$`).MatchString(d.Prefix) || d.Source != d.Prefix+strconv.Itoa(*d.Start) || d.FilenameForbidden != "<>;" || ov.MultipartUploadFamily != nil || len(ov.BodyFields) > 0 || len(ov.AdditionalMethods) > 0 {
		return fail("require a direct UAPI POST, required zero-based binary list and explicit filename policy")
	}
	if v.OperationLocator.Pointer == nil {
		return fail("need exact source provenance")
	}
	pointer := *v.OperationLocator.Pointer
	for _, suffix := range []string{"/description", "/requestBody/description", "/requestBody/content/multipart~1form-data/schema/properties/" + strings.ReplaceAll(strings.ReplaceAll(d.Source, "~", "~0"), "/", "~1")} {
		if !boundInputEvidence(ov.Evidence, v.OperationLocator, pointer+suffix) {
			return fail("need exact source-bound field, numbering and route evidence")
		}
	}
	body, source, _, ok := b.schemas.follow(v.OperationLocator.SourceID, node["requestBody"])
	content, _ := asObject(body["content"])
	media, found := asObject(content["multipart/form-data"])
	if !ok || !found || len(content) != 1 || media["encoding"] != nil || body["required"] != true || text(body["description"]) == "" || text(node["description"]) == "" {
		return fail("need a required documented multipart body")
	}
	schema, source, _, ok := b.schemas.follow(source, media["schema"])
	props, found := asObject(schema["properties"])
	required := stringList(schema["required"])
	if !ok || !found || schema["type"] != "object" || schema["nullable"] == true || len(props) != 1 || len(required) != 1 || required[0] != d.Source || schema["additionalProperties"] != nil || schema["oneOf"] != nil || schema["anyOf"] != nil || schema["allOf"] != nil {
		return fail("support exactly one required binary source family")
	}
	field, _, _, ok := b.schemas.follow(source, props[d.Source])
	if !ok || field["type"] != "string" || field["format"] != "binary" || field["nullable"] == true || field["oneOf"] != nil || field["anyOf"] != nil || field["allOf"] != nil {
		return fail("need a nonnullable binary string source")
	}
	for _, p := range *params {
		if (p.Encoding != "scalar" && p.Encoding != "bool") || p.PHP == d.PHP || strings.HasPrefix(p.Wire, d.Prefix) || reservedJSONName(p.Wire, op.Family) {
			return fail("query arguments collide with upload family or routing")
		}
	}
	*params = append([]Param{{PHP: d.PHP, PHPType: "array", DocType: "non-empty-list<Upload>", Required: true, Sensitive: true, Description: "Files to upload, in order. Each filename determines the stored name; duplicate names remain separate parts."}}, *params...)
	return d
}
