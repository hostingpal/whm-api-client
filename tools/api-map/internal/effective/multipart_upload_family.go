package effective

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"

	"strings"
)

// Lower only an explicit evidence-bound scalar-or-list binary source family.
// Formal requiredness stays absent; choosing the upload mode requires a source.
func (b *builder) multipartUploadFamily(op model.Operation, v model.Variant, ov overlay.Operation, node map[string]any, params *[]Param) *overlay.MultipartUploadFamily {
	fail := func(message string) *overlay.MultipartUploadFamily {
		b.problem(op.ID + ": multipart upload family " + message)
		return nil
	}
	d := ov.MultipartUploadFamily
	if ov.Body != "multipart" || op.Family != "uapi" || v.Method != "POST" || d == nil || !IsMethodName(d.PHP) || reservedArguments[d.PHP] || d.Routes != "direct" || (d.Source != "file" && d.Source != "backup") || ov.MultipartUploads != nil || ov.MultipartUpload != nil || ov.JSONBody != nil || len(ov.BodyFields) > 0 || len(ov.AdditionalMethods) > 0 {
		return fail("requires direct UAPI POST and the reviewed scalar-or-list binary family")
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
	if !ok || !found || len(content) != 1 || media["encoding"] != nil || body["required"] != nil || text(body["description"]) == "" || text(node["description"]) == "" {
		return fail("need a documented multipart body with absent formal requiredness")
	}
	schema, source, _, ok := b.schemas.follow(source, media["schema"])
	props, found := asObject(schema["properties"])
	if !ok || !found || schema["type"] != "object" || schema["nullable"] == true || len(props) != 1 || schema["required"] != nil || schema["additionalProperties"] != nil || schema["oneOf"] != nil || schema["anyOf"] != nil || schema["allOf"] != nil {
		return fail("support exactly one binary source family with absent schema requiredness")
	}
	field, _, _, ok := b.schemas.follow(source, props[d.Source])
	if !ok || field["type"] != "string" || field["format"] != "binary" || field["nullable"] == true || field["oneOf"] != nil || field["anyOf"] != nil || field["allOf"] != nil {
		return fail("need a nonnullable binary string source")
	}
	for _, p := range *params {
		if (p.Encoding != "scalar" && p.Encoding != "bool") || p.PHP == d.PHP || (p.Wire == d.Source || strings.HasPrefix(p.Wire, d.Source+"-") || p.Wire == "backup" || strings.HasPrefix(p.Wire, "backup-")) || reservedJSONName(p.Wire, op.Family) {
			return fail("query arguments collide with upload family or routing")
		}
	}
	// Server-path backup is a separate documented source mode, omitted explicitly.
	for _, p := range v.Parameters {
		if p.Name == "backup" {
			path, _, _, pathOK := b.schemas.follow(p.Locator.SourceID, p.Definition["schema"])
			if !pathOK || path["type"] != "string" || path["nullable"] == true || path["oneOf"] != nil || path["anyOf"] != nil || path["allOf"] != nil {
				return fail("requires the reviewed nonnullable string server-path query alternative")
			}
			if p.In != "query" || p.Definition["required"] == true || !ov.Params["backup"].Omit || p.Locator.Pointer == nil || !boundInputEvidence(ov.Evidence, p.Locator, *p.Locator.Pointer) {
				return fail("requires exact source-bound omission of the server-path query alternative")
			}
		}
	}
	for i := range *params {
		(*params)[i].Sensitive = true
	}
	*params = append([]Param{{PHP: d.PHP, PHPType: "Upload|array", DocType: "Upload|non-empty-list<Upload>", Required: true, Sensitive: true, Description: "An uploaded archive or a nonempty sequential list. Scalar uses the bare part name; lists use one-based suffixes, including singleton lists."}}, *params...)
	return d
}
