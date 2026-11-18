package overlay

import (
	"strings"
	"testing"
)

func TestFormRecordExclusivityAndEvidence(t *testing.T) {
	for _, other := range []string{"body_fields", "json_body", "multipart_upload", "multipart_upload_family", "multipart_uploads", "additional_methods", "exclude", "body"} {
		op := Operation{Method: "POST", FormRecord: &FormRecord{}, Evidence: []Evidence{{Kind: "doc", Version: "pinned", Date: "2026-11-18", Note: "form"}}}
		switch other {
		case "body_fields":
			op.BodyFields = map[string]string{"a": "b"}
		case "json_body":
			op.JSONBody = &JSONBody{}
		case "multipart_upload":
			op.MultipartUpload = &MultipartUpload{}
		case "multipart_uploads":
			op.MultipartUploads = &MultipartUploads{}
		case "multipart_upload_family":
			op.MultipartUploadFamily = &MultipartUploadFamily{}
		case "additional_methods":
			op.AdditionalMethods = []AdditionalMethod{{Name: "another"}}
		case "exclude":
			op.Exclude = &Exclusion{}
		case "body":
			op.Body = "multipart"
		}
		set := loadWHM(t, validWHM)
		set.Files[0].Operations = map[string]Operation{"whm1:addzonerecord": op}
		if got := strings.Join(Validate(set), " "); !strings.Contains(got, "form_record requires") {
			t.Errorf("%s not rejected by form_record: %s", other, got)
		}
	}
	if got := strings.Join(missingEvidence("op", Operation{FormRecord: &FormRecord{}}), " "); !strings.Contains(got, "form_record needs evidence") {
		t.Fatal(got)
	}
}
