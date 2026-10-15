package cli

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/phpgen"
)

func TestOverlayLoadRejectsSymbolicLinks(t *testing.T) {
	outside := t.TempDir()
	target := filepath.Join(outside, "DomainInfo.json")
	if err := os.WriteFile(target, []byte(domainInfoOverlay), 0o600); err != nil {
		t.Fatal(err)
	}
	for name, link := range map[string]func(t *testing.T, dir string){
		"group file": func(t *testing.T, dir string) {
			if err := os.Symlink(target, filepath.Join(dir, "uapi", "DomainInfo.json")); err != nil {
				t.Fatal(err)
			}
		},
		"family directory": func(t *testing.T, dir string) {
			if err := os.Remove(filepath.Join(dir, "uapi")); err != nil {
				t.Fatal(err)
			}
			if err := os.Symlink(outside, filepath.Join(dir, "uapi")); err != nil {
				t.Fatal(err)
			}
		},
		"config": func(t *testing.T, dir string) {
			config := filepath.Join(outside, "config.json")
			if err := os.WriteFile(config, []byte(`{"schema_version":"1.0","scope":"listed"}`), 0o600); err != nil {
				t.Fatal(err)
			}
			if err := os.Remove(filepath.Join(dir, "config.json")); err != nil {
				t.Fatal(err)
			}
			if err := os.Symlink(config, filepath.Join(dir, "config.json")); err != nil {
				t.Fatal(err)
			}
		},
	} {
		t.Run(name, func(t *testing.T) {
			dir := writeOverlayDir(t, nil)
			link(t, dir)
			if _, err := overlay.Load(dir); err == nil || !strings.Contains(err.Error(), "symbolic link") {
				t.Fatalf("symbolic link accepted: %v", err)
			}
		})
	}
}

func TestGenerateRefusesSymbolicLinksAtGeneratedPaths(t *testing.T) {
	mapPath := pinnedParityMap(t)
	overlayDir := writeOverlayDir(t, map[string]string{"uapi/DomainInfo.json": domainInfoOverlay})
	for name, c := range map[string]struct {
		setup func(t *testing.T, root, outside string)
		want  string
	}{
		"generated folder": {func(t *testing.T, root, outside string) {
			if err := os.MkdirAll(filepath.Join(root, "src/Api"), 0o755); err != nil {
				t.Fatal(err)
			}
			if err := os.Symlink(outside, filepath.Join(root, "src/Api/Uapi")); err != nil {
				t.Fatal(err)
			}
		}, "src/Api/Uapi"},
		"generated file": {func(t *testing.T, root, outside string) {
			if err := os.MkdirAll(filepath.Join(root, "src/Api/Uapi"), 0o755); err != nil {
				t.Fatal(err)
			}
			if err := os.Symlink(filepath.Join(outside, "victim.php"), filepath.Join(root, "src/Api/Uapi/DomainInfo.php")); err != nil {
				t.Fatal(err)
			}
		}, "src/Api/Uapi/DomainInfo.php"},
		"stale candidate": {func(t *testing.T, root, outside string) {
			if err := os.MkdirAll(filepath.Join(root, "src/Api/Whm"), 0o755); err != nil {
				t.Fatal(err)
			}
			if err := os.Symlink(filepath.Join(outside, "victim.php"), filepath.Join(root, "src/Api/Whm/Old.php")); err != nil {
				t.Fatal(err)
			}
		}, "src/Api/Whm/Old.php"},
	} {
		t.Run(name, func(t *testing.T) {
			root, outside := t.TempDir(), t.TempDir()
			victim := filepath.Join(outside, "victim.php")
			original := "<?php\n/** " + phpgen.Marker + " */\n"
			if err := os.WriteFile(victim, []byte(original), 0o600); err != nil {
				t.Fatal(err)
			}
			c.setup(t, root, outside)
			for _, extra := range [][]string{{"--check"}, {}} {
				args := append([]string{"generate", "php", "--map", mapPath, "--overlay", overlayDir, "--root", root}, extra...)
				code, out := runCLI(t, args...)
				if code != 1 || !strings.Contains(out, "symbolic link") || !strings.Contains(out, c.want) {
					t.Fatalf("%v: %d: %s", extra, code, out)
				}
			}
			entries, err := os.ReadDir(outside)
			if err != nil || len(entries) != 1 {
				t.Fatalf("files written outside the root: %v %v", entries, err)
			}
			if got, _ := os.ReadFile(victim); string(got) != original {
				t.Fatal("file outside the root changed")
			}
		})
	}
}
