package effective

import (
	"reflect"
	"strings"
	"time"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

// restoreOpaqueRetrieval permits only an explicitly observed missing data
// contract on a plain metadata-only WHM GET retrieval. It invents no fields,
// permits no optional payload and leaves documented-payload corrections alone.
func restoreOpaqueRetrieval(op model.Operation, v model.Variant, ov overlay.Operation, payload payloadInfo, node map[string]any, method string) bool {
	if op.Family != "whm1" || v.Method != "GET" || method != "GET" || node["x-readonly"] != true ||
		payload.spec != nil || !payload.metadataOnly || payload.issue != "" || payload.partial ||
		ov.Void || ov.OptionalPayload || ov.Unwrap != "" || ov.Exclude != nil || len(ov.Response) != 1 {
		return false
	}
	c, ok := ov.Response[""]
	if !ok || c.Replace == nil || !reflect.DeepEqual(*c.Replace, overlay.Spec{Type: "mixed"}) ||
		c.Type != "" || c.Add != nil || c.Accept != nil || c.Nullable != nil || c.Preserve || c.Required != nil {
		return false
	}
	for _, evidence := range c.Evidence {
		_, err := time.Parse("2006-01-02", evidence.Date)
		if evidence.Kind == "live" && strings.TrimSpace(evidence.Version) != "" &&
			strings.TrimSpace(evidence.Note) != "" && err == nil {
			return true
		}
	}
	return false
}
