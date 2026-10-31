package overlay

import (
	"strings"
	"testing"
)

func TestScalarMultipartUploadDescriptorGrammar(t *testing.T) {
	d := `{"php":"file","source":"file","text":"module"}`
	content := func(desc string) string {
		return `{"schema_version":"1.0","family":"whm1","group":"Server Administration","accessor":"server","operations":{"whm1:restore_config_from_upload":{"body":"multipart","multipart_upload":` + desc + `,"evidence":` + evidenceJSON + `}}}`
	}
	if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/server.json": content(d)})); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{strings.Replace(d, `"text":"module"`, `"text":"invalid!"`, 1), strings.Replace(d, `"php":"file",`, "", 1), strings.Replace(d, `"source":"file"`, `"unknown":"file"`, 1)} {
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/server.json": content(bad)})); err == nil {
			t.Fatalf("accepted %s", bad)
		}
	}
}
