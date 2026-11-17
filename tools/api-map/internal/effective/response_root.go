package effective

import (
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

// missingPayloadRoot admits the existing root replacement grammar for a WHM
// envelope whose resolved source omits data, only with exact documentation and
// attributed live evidence. The ordinary correction and spec validators still
// validate the replacement; this seed never changes runtime extraction.
func (b *builder) missingPayloadRoot(op model.Operation, v model.Variant, ov overlay.Operation, payload payloadInfo) *TypeSpec {
	correction, present := ov.Response[""]
	loc := v.OperationLocator
	if !present || correction.Replace == nil || op.Family != "whm1" || ov.Void ||
		payload.spec != nil || payload.issue != "" || payload.partial || loc.Pointer == nil || loc.SourceSHA256 == "" {
		return nil
	}
	pointer := *loc.Pointer + "/responses/200/content/application~1json/schema"
	doc, live := false, false
	for _, evidence := range correction.Evidence {
		if strings.TrimSpace(evidence.Note) == "" {
			continue
		}
		if evidence.Kind == "doc" && evidence.SourceSHA256 == loc.SourceSHA256 && evidence.Pointer == pointer {
			doc = true
		}
		if evidence.Kind == "live" && strings.TrimSpace(evidence.Version) != "" && strings.TrimSpace(evidence.Date) != "" {
			live = true
		}
	}
	if !doc || !live {
		return nil
	}
	// Bind to the original 200 JSON schema slot, even if its value is a ref.
	raw, err := source.Pointer(b.schemas.m.Documents[loc.SourceID], pointer)
	if err != nil {
		return nil
	}
	// The ordinary resolver tolerates nonstring $ref values. Validate the
	// envelope and its compositions locally before admitting this exception.
	if !b.envelopeReferences(loc.SourceID, raw, 0, map[string]bool{}) {
		return nil
	}
	return &TypeSpec{}
}

// envelopeReferences checks only envelope/reference nodes, never their result
// properties. Reference siblings retain the ordinary resolver's follow behavior.
// The depth bound includes composition and reference edges; path-local cycle
// detection permits a valid reference reused by independent alternatives.
func (b *builder) envelopeReferences(sourceID string, raw any, depth int, seen map[string]bool) bool {
	if depth >= 32 {
		return false
	}
	node, ok := asObject(raw)
	if !ok {
		return false
	}
	if value, present := node["$ref"]; present {
		ref, ok := value.(string)
		if !ok || strings.TrimSpace(ref) == "" {
			return false
		}
		resolved, err := b.schemas.index.Resolve(sourceID, ref)
		if err != nil || resolved.Origin.Pointer == nil {
			return false
		}
		key := resolved.Origin.SourceID + "#" + *resolved.Origin.Pointer
		if seen[key] {
			return false
		}
		seen[key] = true
		defer delete(seen, key)
		return b.envelopeReferences(resolved.Origin.SourceID, resolved.Value, depth+1, seen)
	}
	for _, key := range []string{"oneOf", "anyOf", "allOf"} {
		parts, _ := node[key].([]any)
		for _, part := range parts {
			if !b.envelopeReferences(sourceID, part, depth+1, seen) {
				return false
			}
		}
	}
	return true
}
