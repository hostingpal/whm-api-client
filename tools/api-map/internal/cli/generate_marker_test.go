package cli

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/phpgen"
)

func TestGenerateRecognisesOnlyMarkerLines(t *testing.T) {
	mapPath := pinnedParityMap(t)
	overlay := writeOverlayDir(t, map[string]string{"uapi/DomainInfo.json": domainInfoOverlay})
	root := t.TempDir()
	dir := filepath.Join(root, "src/Api/Uapi")
	if err := os.MkdirAll(filepath.Join(dir, "Spec"), 0o755); err != nil {
		t.Fatal(err)
	}
	quoting := filepath.Join(dir, "Quoting.php")
	body := "<?php\n" + strings.Repeat("// filler\n", 45) + "// see: " + phpgen.Marker + "\n"
	prose := filepath.Join(dir, "Prose.php")
	if err := os.WriteFile(quoting, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(prose, []byte("<?php\n// docs say \""+phpgen.Marker+"\" in prose\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	staleSpec := filepath.Join(dir, "Spec/Old.php")
	if err := os.WriteFile(staleSpec, []byte("<?php\n/**\n * "+phpgen.Marker+"\n */\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	code, out := runCLI(t, "generate", "php", "--map", mapPath, "--overlay", overlay, "--root", root, "--check")
	if code != 1 || !strings.Contains(out, "src/Api/Uapi/Spec/Old.php") || strings.Contains(out, "Quoting.php") || strings.Contains(out, "Prose.php") {
		t.Fatalf("check %d: %s", code, out)
	}
	if code, out = runCLI(t, "generate", "php", "--map", mapPath, "--overlay", overlay, "--root", root); code != 0 {
		t.Fatalf("write %d: %s", code, out)
	}
	if _, err := os.Stat(staleSpec); !os.IsNotExist(err) {
		t.Fatal("stale Spec file kept")
	}
	for _, kept := range []string{quoting, prose} {
		if _, err := os.Stat(kept); err != nil {
			t.Fatalf("hand-written file removed: %s", kept)
		}
	}
}

func TestGenerateRefusesToOverwriteHandWrittenFile(t *testing.T) {
	mapPath := pinnedParityMap(t)
	overlay := writeOverlayDir(t, map[string]string{"uapi/DomainInfo.json": domainInfoOverlay})
	root := t.TempDir()
	target := filepath.Join(root, "src/Api/Uapi/DomainInfo.php")
	if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
		t.Fatal(err)
	}
	const original = "<?php\n// hand-written\n"
	if err := os.WriteFile(target, []byte(original), 0o600); err != nil {
		t.Fatal(err)
	}
	for _, extra := range [][]string{{}, {"--check"}} {
		args := append([]string{"generate", "php", "--map", mapPath, "--overlay", overlay, "--root", root}, extra...)
		code, out := runCLI(t, args...)
		if code != 1 || !strings.Contains(out, "refusing to overwrite hand-written file: src/Api/Uapi/DomainInfo.php") {
			t.Fatalf("%v: %d: %s", extra, code, out)
		}
		if got, _ := os.ReadFile(target); string(got) != original {
			t.Fatal("hand-written file changed")
		}
	}
	if _, err := os.Stat(filepath.Join(root, "docs/api/coverage.md")); !os.IsNotExist(err) {
		t.Fatal("files written despite refusal")
	}
}

func TestEveryRenderedFileIsRecognisedAsGenerated(t *testing.T) {
	mapPath := pinnedParityMap(t)
	overlay := writeOverlayDir(t, map[string]string{"uapi/DomainInfo.json": domainInfoOverlay})
	result, ok := effectiveContract(mapPath, overlay, &bytes.Buffer{})
	if !ok {
		t.Fatal("effective contract failed")
	}
	files := phpgen.Render(result)
	if len(files) < 2 {
		t.Fatalf("expected several rendered files, got %d", len(files))
	}
	for path, content := range files {
		if !isGenerated(content) {
			t.Fatalf("rendered file not recognised: %s", path)
		}
	}
}
