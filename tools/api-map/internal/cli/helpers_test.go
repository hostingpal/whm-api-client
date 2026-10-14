package cli

import (
	"bytes"
	"context"
	"path/filepath"
	"testing"
)

func pinnedParityMap(t *testing.T) string {
	t.Helper()
	root := "../../../.."
	path := filepath.Join(t.TempDir(), "map.json")
	var out, errout bytes.Buffer
	if code := Run(context.Background(), []string{"map", "build", "--manifest", filepath.Join(root, "docs/api/sources/2026-10-13/manifest.json"), "--annotations", filepath.Join(root, "docs/api/annotations.json"), "--output", path}, &out, &errout); code != 0 {
		t.Fatalf("pinned map build exit %d: %s", code, errout.String())
	}
	return path
}
