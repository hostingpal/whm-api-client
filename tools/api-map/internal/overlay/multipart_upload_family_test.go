package overlay

import (
	"strings"
	"testing"
)

func TestMultipartUploadFamilyGrammarAndMutualExclusion(t *testing.T) {
	d := `{"php":"backups","source":"backup","routes":"direct"}`
	content := func(desc, extra string) string {
		return `{"schema_version":"1.0","family":"uapi","group":"Backup","operations":{"uapi:Backup/restore_files":{"body":"multipart","multipart_upload_family":` + desc + extra + `,"evidence":` + evidenceJSON + `}}}`
	}
	if _, err := Load(writeOverlay(t, "listed", map[string]string{"uapi/Backup.json": content(d, "")})); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{strings.Replace(d, `"routes":"direct"`, `"routes":"bridge"`, 1), strings.Replace(d, `"source":"backup"`, `"source":"file-0"`, 1), strings.Replace(d, `"php":"backups",`, "", 1), strings.Replace(d, `"routes":"direct"`, `"routes":"direct","start":1`, 1)} {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"uapi/Backup.json": content(bad, "")})); err == nil {
			t.Fatalf("accepted %s", bad)
		}
	}
	for _, extra := range []string{`,"multipart_upload":{"php":"file","source":"file","text":"module"}`, `,"multipart_uploads":{"php":"files","source":"file-0","prefix":"file-","start":0,"routes":"direct","filename_forbidden":"<>;"}`} {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"uapi/Backup.json": content(d, extra)})); err == nil {
			t.Fatalf("accepted combined descriptor %s", extra)
		}
	}
}
