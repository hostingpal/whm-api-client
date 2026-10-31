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

func TestRenderRequiredWhmTextAndScalarUpload(t *testing.T) {
	op := effective.Operation{PHPName: "restoreConfigFromUpload", Constant: "RESTORE_CONFIG_FROM_UPLOAD", Function: "restore_config_from_upload", Method: "POST", Body: "multipart", Outcome: "flag", MultipartUpload: &overlay.MultipartUpload{PHP: "file", Source: "file", Text: "module"}, Params: []effective.Param{{PHP: "module", PHPType: "string", DocType: "'Basic'|'Main'", Required: true, Sensitive: true}, {PHP: "file", PHPType: "Upload", DocType: "Upload", Required: true, Sensitive: true}}}
	g := effective.Group{Family: "whm1", Name: "Server Administration", Class: "Server", Operations: []effective.Operation{op}}
	group, spec := groupClass(g), specClass(g)
	for _, want := range []string{`use HostingPal\WhmApiClient\Upload;`, `@param 'Basic'|'Main' $module`, `string $module,`, `Upload $file,`, `): void`, `['module' => $module, 'file' => $file]`} {
		if !strings.Contains(group, want) {
			t.Errorf("missing %s", want)
		}
	}
	if strings.Count(group, "#[SensitiveParameter]") != 2 {
		t.Fatalf("both public value arguments must be sensitive: %s", group)
	}
	for _, want := range []string{`'multipart_upload' => [`, `'php' => 'file'`, `'source' => 'file'`, `'text' => 'module'`, `'params' => []`, `'returns' => null`, `'outcome' => 'flag'`} {
		if !strings.Contains(spec, want) {
			t.Errorf("missing %s", want)
		}
	}
	if strings.Contains(spec, `'module' => '`) || strings.Contains(spec, `'multipart_uploads'`) || strings.Contains(group, `UploadList`) {
		t.Fatal("scalar body leaked into route or list metadata")
	}
}
