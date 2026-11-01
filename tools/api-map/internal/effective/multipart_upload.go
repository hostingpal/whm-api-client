package effective

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"reflect"
)

// Lower only the reviewed WHM required module text plus one scalar Upload.
// Schema requiredness here is explicit policy; legacy body requiredness is unchanged.
func (b *builder) multipartUpload(op model.Operation, v model.Variant, ov overlay.Operation, node map[string]any, params *[]Param) *overlay.MultipartUpload {
	fail := func(message string) *overlay.MultipartUpload {
		b.problem(op.ID + ": multipart upload " + message)
		return nil
	}
	d := ov.MultipartUpload
	if ov.Body != "multipart" || op.Family != "whm1" || v.Method != "POST" || d == nil || !IsMethodName(d.PHP) || reservedArguments[d.PHP] || d.Source != "file" || d.Text != "module" || d.PHP == d.Text || ov.MultipartUploads != nil || ov.JSONBody != nil || len(ov.Params) > 0 || len(ov.Order) > 0 || len(ov.BodyFields) > 0 || len(ov.AdditionalMethods) > 0 || len(*params) > 0 || len(v.Parameters) > 0 {
		return fail("requires WHM POST with only the reviewed scalar source and text body")
	}
	if v.OperationLocator.Pointer == nil {
		return fail("needs source provenance")
	}
	pointer := *v.OperationLocator.Pointer
	for _, suffix := range []string{"/requestBody/content/multipart~1form-data/schema/properties/file", "/requestBody/content/multipart~1form-data/schema/properties/module", "/requestBody/content/multipart~1form-data/schema/required"} {
		if !boundInputEvidence(ov.Evidence, v.OperationLocator, pointer+suffix) {
			return fail("needs exact source-bound field and schema-requiredness evidence")
		}
	}
	body, source, _, ok := b.schemas.follow(v.OperationLocator.SourceID, node["requestBody"])
	content, _ := asObject(body["content"])
	media, found := asObject(content["multipart/form-data"])
	if !ok || !found || len(content) != 1 || media["encoding"] != nil || body["required"] != nil {
		return fail("requires the reviewed multipart body with absent body requiredness")
	}
	schema, source, _, ok := b.schemas.follow(source, media["schema"])
	props, found := asObject(schema["properties"])
	required := stringList(schema["required"])
	if !ok || !found || schema["type"] != "object" || schema["nullable"] == true || len(props) != 2 || len(required) != 2 || !hasRequired(required, d.Source) || !hasRequired(required, d.Text) || schema["additionalProperties"] != nil || schema["oneOf"] != nil || schema["anyOf"] != nil || schema["allOf"] != nil {
		return fail("requires exactly two schema-required body fields")
	}
	file, _, _, ok := b.schemas.follow(source, props[d.Source])
	if !ok || file["type"] != "string" || file["format"] != "binary" || file["nullable"] == true || file["oneOf"] != nil || file["anyOf"] != nil || file["allOf"] != nil {
		return fail("requires a nonnullable binary string source")
	}
	textField, _, _, ok := b.schemas.follow(source, props[d.Text])
	if !ok || !multipartString(textField) || !reflect.DeepEqual(stringList(textField["enum"]), []string{"Basic", "Main"}) {
		return fail("requires the reviewed Basic/Main text schema")
	}
	*params = []Param{{PHP: d.Text, PHPType: "string", DocType: "'Basic'|'Main'", Required: true, Sensitive: true, Description: firstParagraph(text(textField["description"]))}, {PHP: d.PHP, PHPType: "Upload", DocType: "Upload", Required: true, Sensitive: true, Description: firstParagraph(text(file["description"]))}}
	return d
}

func hasRequired(names []string, name string) bool {
	for _, v := range names {
		if v == name {
			return true
		}
	}
	return false
}
