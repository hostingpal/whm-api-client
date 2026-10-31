package phpgen

import (
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"strings"
	"testing"
)

func TestRenderUploadListAndExplicitObjectPolicy(t *testing.T) {
	zero := 0
	deny := false
	op := effective.Operation{PHPName: "uploadFiles", Constant: "UPLOAD_FILES", Body: "multipart", Method: "POST", Outcome: "unchecked", MultipartUploads: &overlay.MultipartUploads{PHP: "files", Prefix: "file-", Start: &zero, Routes: "direct", FilenameForbidden: "<>;"}, Params: []effective.Param{{PHP: "files", PHPType: "array", DocType: "non-empty-list<Upload>", Sensitive: true, Required: true}, {PHP: "dir", Wire: "dir", Encoding: "scalar", PHPType: "string", DocType: "string"}}, Returns: &effective.TypeSpec{Type: "object", AcceptEmptyArray: &deny, Fields: []effective.Field{{Name: "diskinfo", Spec: effective.TypeSpec{Type: "object", Values: &effective.TypeSpec{Type: "string"}, Fields: []effective.Field{{Name: "filesused", Spec: effective.TypeSpec{Type: "int"}}}}}}}}
	g := effective.Group{Family: "uapi", Name: "Fileman", Class: "Fileman", Operations: []effective.Operation{op}}
	group := groupClass(g)
	spec := specClass(g)
	for _, want := range []string{`use HostingPal\WhmApiClient\Upload;`, `@param non-empty-list<Upload> $files`, `#[SensitiveParameter]`, `array $files`, `?string $dir = null`, `...<array-key, string>`} {
		if !strings.Contains(group, want) {
			t.Errorf("missing %s", want)
		}
	}
	for _, want := range []string{`'multipart_uploads' => [`, `'prefix' => 'file-'`, `'routes' => 'direct'`, `'outcome' => 'unchecked'`, `'accept_empty_array' => false`, `'dir' => ['name' => 'dir', 'encoding' => 'scalar']`} {
		if !strings.Contains(spec, want) {
			t.Errorf("missing %s", want)
		}
	}
	if strings.Contains(spec, `'files' => ['name'`) {
		t.Fatal("uploads leaked into query parameter specs")
	}
}
