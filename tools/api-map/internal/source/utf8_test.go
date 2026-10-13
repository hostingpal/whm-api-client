package source

import (
	"bytes"
	"context"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestSourceJSONRejectsInvalidUTF8(t *testing.T) {
	for _, field := range []string{`"description":"` + string([]byte{0xff}) + `"`, `"` + string([]byte{0xff}) + `":"description"`} {
		t.Run(field, func(t *testing.T) {
			raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1",` + field + `},"paths":{}}`
			p, _ := fixture(t, raw, "spec.json.gz")
			b, err := Load(p, DefaultLimits())
			if err == nil || !strings.Contains(err.Error(), "UTF-8") || b.Documents != nil {
				t.Errorf("malformed source was decoded: %v", err)
			}
			p, _ = fixture(t, valid, "spec.json.gz")
			before, _ := os.ReadFile(p)
			artifact := filepath.Join(filepath.Dir(p), "spec.json.gz")
			sourceBefore, _ := os.ReadFile(artifact)
			dest := filepath.Join(t.TempDir(), "new")
			client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) { return response(req, 200, raw), nil })}
			result, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: dest}, client)
			if err == nil || result != "" || !strings.Contains(err.Error(), "UTF-8") {
				t.Errorf("malformed source published: %s %v", result, err)
			}
			if _, err := os.Stat(dest); !os.IsNotExist(err) {
				t.Error("rejected destination exists")
			}
			after, _ := os.ReadFile(p)
			sourceAfter, _ := os.ReadFile(artifact)
			if !bytes.Equal(before, after) || !bytes.Equal(sourceBefore, sourceAfter) {
				t.Error("prior snapshot changed")
			}
			stages, _ := filepath.Glob(filepath.Join(filepath.Dir(dest), ".api-map-stage-*"))
			if len(stages) != 0 {
				t.Error("staged files retained")
			}
		})
	}
}
