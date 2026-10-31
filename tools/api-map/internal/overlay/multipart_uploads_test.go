package overlay

import (
	"strings"
	"testing"
)

func TestMultipartUploadsDescriptorGrammar(t *testing.T) {
	d := `{"php":"files","source":"file-0","prefix":"file-","start":0,"routes":"direct","filename_forbidden":"<>;"}`
	content := func(desc string) string {
		return `{"schema_version":"1.0","family":"uapi","group":"Fileman","operations":{"uapi:Fileman/upload_files":{"body":"multipart","multipart_uploads":` + desc + `,"evidence":` + evidenceJSON + `}}}`
	}
	if _, err := Load(writeOverlay(t, "listed", map[string]string{"uapi/Fileman.json": content(d)})); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{
		strings.Replace(d, `"php":"files",`, "", 1),
		strings.Replace(d, `"start":0`, `"start":1`, 1),
		strings.Replace(d, `"start":0`, `"start":"0"`, 1),
		strings.Replace(d, `"routes":"direct"`, `"routes":"bridge"`, 1),
		strings.Replace(d, `"filename_forbidden":"<>;"`, `"filename_forbidden":""`, 1),
		strings.Replace(d, `"source":`, `"Source":`, 1),
		strings.Replace(d, `"prefix":`, `"unknown":`, 1),
	} {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"uapi/Fileman.json": content(bad)})); err == nil {
			t.Fatalf("accepted unsupported descriptor %s", bad)
		}
	}
	for _, setting := range []Operation{{MultipartUploads: &MultipartUploads{}}, {Body: "multipart", MultipartUploads: &MultipartUploads{}, BodyFields: map[string]string{"a": "b"}}} {
		set := Set{Files: []File{{Family: "uapi", Group: "Fileman", Operations: map[string]Operation{"uapi:Fileman/upload_files": setting}}}}
		if len(Validate(set)) == 0 {
			t.Fatal("upload settings lost evidence/body restrictions")
		}
	}
}
