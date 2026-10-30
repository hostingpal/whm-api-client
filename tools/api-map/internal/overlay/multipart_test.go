package overlay

import (
	"strings"
	"testing"
)

func TestMultipartDecisionsNeedEvidenceAndBodyScope(t *testing.T) {
	for _, op := range []Operation{
		{Body: "multipart"},
		{BodyFields: map[string]string{"crt": "key"}},
	} {
		if len(missingEvidence("uapi:SSL/upload_key", op)) == 0 {
			t.Fatal("multipart decision lacks evidence gate")
		}
	}
	set := Set{Files: []File{{Family: "uapi", Group: "SSL", Path: "uapi/SSL.json", Operations: map[string]Operation{
		"uapi:SSL/upload_key": {BodyFields: map[string]string{"crt": "key"}},
	}}}}
	if got := strings.Join(Validate(set), "\n"); !strings.Contains(got, "body_fields requires multipart") {
		t.Fatal(got)
	}
}
