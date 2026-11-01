package phpgen

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"strings"
	"testing"
)

func TestRenderScalarOrListUploadFamily(t *testing.T) {
	deny := false
	op := effective.Operation{PHPName: "restoreFiles", Constant: "RESTORE_FILES", Function: "restore_files", Module: "Backup", Body: "multipart", Method: "POST", Outcome: "flag", MultipartUploadFamily: &overlay.MultipartUploadFamily{PHP: "backups", Source: "backup", Routes: "direct"}, Params: []effective.Param{{PHP: "backups", PHPType: "Upload|array", DocType: "Upload|non-empty-list<Upload>", Required: true, Sensitive: true}, {PHP: "directory", Wire: "directory", PHPType: "string", DocType: "string", Encoding: "scalar", Sensitive: true}, {PHP: "timeout", Wire: "timeout", PHPType: "int", DocType: "int", Encoding: "scalar", Sensitive: true}, {PHP: "verbose", Wire: "verbose", PHPType: "bool", DocType: "bool", Encoding: "bool", Sensitive: true}}, Returns: &effective.TypeSpec{Type: "object", AcceptEmptyArray: &deny, Fields: []effective.Field{{Name: "messages", Spec: effective.TypeSpec{Type: "list", Items: &effective.TypeSpec{Type: "string"}}}}}}
	g := effective.Group{Family: "uapi", Name: "Backup", Class: "Backup", Operations: []effective.Operation{op}}
	group, spec := groupClass(g), specClass(g)
	for _, want := range []string{`use HostingPal\WhmApiClient\Upload;`, `Upload|array $backups`, `Upload|non-empty-list<Upload>`, `?string $directory = null`, `?int $timeout = null`, `?bool $verbose = null`} {
		if !strings.Contains(group, want) {
			t.Errorf("missing %s", want)
		}
	}
	if strings.Count(group, "#[SensitiveParameter]") != 4 {
		t.Fatalf("public values not sensitive: %s", group)
	}
	for _, want := range []string{`'multipart_upload_family' => [`, `'source' => 'backup'`, `'routes' => 'direct'`, `'outcome' => 'flag'`, `'accept_empty_array' => false`, `'timeout' => ['name' => 'timeout', 'encoding' => 'scalar']`} {
		if !strings.Contains(spec, want) {
			t.Errorf("missing %s", want)
		}
	}
	if strings.Contains(spec, `'backups' => ['name'`) {
		t.Fatal("binary source leaked to query")
	}
}
