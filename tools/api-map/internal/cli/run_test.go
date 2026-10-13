package cli

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const manifest = "../../../../docs/api/sources/2026-10-13/manifest.json"

func run(args ...string) (int, string, string) {
	var out, err bytes.Buffer
	code := Run(context.Background(), args, &out, &err)
	return code, out.String(), err.String()
}
func TestExitCodesAndJSON(t *testing.T) {
	for _, args := range [][]string{nil, {"wrong"}, {"map", "build"}, {"sources", "verify", "--manifest", manifest, "extra"}, {"sources", "refresh", "--manifest", manifest}} {
		c, o, e := run(args...)
		if c != 2 || o != "" || e == "" {
			t.Fatalf("usage=%d %q %q", c, o, e)
		}
	}
	c, o, e := run("sources", "verify", "--manifest", manifest)
	if c != 0 || !json.Valid([]byte(o)) || e != "" {
		t.Fatalf("verify=%d %q %q", c, o, e)
	}
	c, o, e = run("sources", "verify", "--manifest", "/tmp/private-secret-token/missing")
	if c != 1 || o != "" || strings.Contains(e, "private-secret-token") {
		t.Fatalf("failure=%d %q %q", c, o, e)
	}
}
func TestMapBuildPreservesPreviousOutput(t *testing.T) {
	p := filepath.Join(t.TempDir(), "map.json")
	annotations := filepath.Join(t.TempDir(), "annotations.json")
	if err := os.WriteFile(annotations, []byte(`{"schema_version":"1.0","annotations":[],"aliases":[]}`), 0600); err != nil {
		t.Fatal(err)
	}
	c, o, e := run("map", "build", "--manifest", manifest, "--annotations", annotations, "--output", p)
	if c != 0 || !json.Valid([]byte(o)) {
		t.Fatalf("build=%d %q %q", c, o, e)
	}
	before, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	for _, raw := range []string{`{"schema_version":"1.0","annotations":[],"annotations":[],"aliases":[]}`, `{"schema_version":"1.0","annotations":[],"aliases":[]} {}`, `{"schema_version":"1.0","annotations":[{"id":"stale","scope":"family","target_id":"whm1","kind":"auth","conclusion":"test","rationale":"test","evidence":[{"source_id":"whm--tokens","source_sha256":"0000000000000000000000000000000000000000000000000000000000000000","prose_locator":"test"}]}],"aliases":[]}`} {
		if err := os.WriteFile(annotations, []byte(raw), 0600); err != nil {
			t.Fatal(err)
		}
		c, o, e = run("map", "build", "--manifest", manifest, "--annotations", annotations, "--output", p)
		if c != 1 || o != "" || e == "" {
			t.Fatalf("failed build=%d %q %q", c, o, e)
		}
		after, err := os.ReadFile(p)
		if err != nil {
			t.Fatal(err)
		}
		if !bytes.Equal(before, after) {
			t.Fatal("failed build replaced previous output")
		}
	}
}
func TestMapStdoutIsCleanJSON(t *testing.T) {
	p := filepath.Join(t.TempDir(), "annotations.json")
	os.WriteFile(p, []byte(`{"schema_version":"1.0","annotations":[],"aliases":[]}`), 0600)
	c, o, e := run("map", "build", "--manifest", manifest, "--annotations", p, "--output", "-")
	if c != 0 || !json.Valid([]byte(o)) || !strings.Contains(e, "UPSTREAM_ID_COLLISION") {
		t.Fatalf("stdout=%d valid=%v stderr=%q", c, json.Valid([]byte(o)), e)
	}
}

func TestVerifyRejectsMalformedOperation(t *testing.T) {
	root := t.TempDir()
	raw := []byte(`{"openapi":"3.0.2","info":{"title":"synthetic","version":"1"},"paths":{"/bad":{"get":{"operationId":"bad"}}}}`)
	sum := sha256.Sum256(raw)
	hash := hex.EncodeToString(sum[:])
	rec := map[string]any{"id": "test", "kind": "openapi", "api_family": "whm-api-1", "file": "source.json", "url": "https://api.docs.cpanel.net/source.json", "sha256": hash, "stored_sha256": hash, "bytes": len(raw), "stored_bytes": len(raw), "compression": "none", "content_type": "application/json", "retrieved_at": "2026-10-13T00:00:00Z", "openapi": "3.0.2", "version": "1", "paths": 1, "operations": 1}
	m := map[string]any{"schema_version": 1, "hash_algorithm": "sha256", "snapshot_id": "synthetic", "sources": []any{rec}}
	data, err := json.Marshal(m)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "manifest.json"), data, 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "source.json"), raw, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := source.Load(filepath.Join(root, "manifest.json"), source.Limits{}); err != nil {
		t.Fatal(err)
	}
	c, o, e := run("sources", "verify", "--manifest", filepath.Join(root, "manifest.json"))
	if c != 1 || o != "" || e == "" {
		t.Fatalf("malformed verified: %d %q %q", c, o, e)
	}
}

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func TestRefreshRejectsMalformedContractsBeforePublication(t *testing.T) {
	root := t.TempDir()
	raw := []byte(`{"openapi":"3.0.2","info":{"title":"synthetic","version":"1"},"paths":{"/valid":{"get":{"operationId":"valid","responses":{"200":{"description":"ok"}}}}}}`)
	sum := sha256.Sum256(raw)
	hash := hex.EncodeToString(sum[:])
	rec := map[string]any{"id": "test", "kind": "openapi", "api_family": "whm-api-1", "file": "source.json", "url": "https://api.docs.cpanel.net/source.json", "sha256": hash, "stored_sha256": hash, "bytes": len(raw), "stored_bytes": len(raw), "compression": "none", "content_type": "application/json", "retrieved_at": "2026-10-13T00:00:00Z"}
	data, err := json.Marshal(map[string]any{"schema_version": 1, "hash_algorithm": "sha256", "snapshot_id": "synthetic", "sources": []any{rec}})
	if err != nil {
		t.Fatal(err)
	}
	manifestPath := filepath.Join(root, "manifest.json")
	os.WriteFile(manifestPath, data, 0600)
	os.WriteFile(filepath.Join(root, "source.json"), raw, 0600)
	malformed := `{"openapi":"3.0.2","info":{"title":"synthetic","version":"1"},"paths":{"/valid":{"get":{"operationId":"valid","responses":{"200":{"description":"ok"}},"callbacks":{"bad":{"{$request.query.url}":{"post":{"operationId":"callback"}}}}}}}}`
	prior := http.DefaultTransport
	t.Cleanup(func() { http.DefaultTransport = prior })
	calls := 0
	http.DefaultTransport = roundTripFunc(func(r *http.Request) (*http.Response, error) {
		calls++
		return &http.Response{StatusCode: 200, Header: http.Header{"Content-Type": []string{"application/json"}}, Body: io.NopCloser(strings.NewReader(malformed)), Request: r}, nil
	})
	dest := filepath.Join(root, "new-snapshot")
	c, o, e := run("sources", "refresh", "--manifest", manifestPath, "--output-dir", dest)
	if c != 1 || o != "" || e == "" || calls != 1 {
		t.Fatalf("refresh=%d %q %q calls=%d", c, o, e, calls)
	}
	if _, err := os.Stat(dest); !os.IsNotExist(err) {
		t.Fatal("malformed snapshot published", err)
	}
	after, err := os.ReadFile(manifestPath)
	if err != nil || !bytes.Equal(after, data) {
		t.Fatal("prior manifest changed", err)
	}
	after, err = os.ReadFile(filepath.Join(root, "source.json"))
	if err != nil || !bytes.Equal(after, raw) {
		t.Fatal("prior source changed", err)
	}
	http.DefaultTransport = roundTripFunc(func(r *http.Request) (*http.Response, error) {
		calls++
		return &http.Response{StatusCode: 200, Header: http.Header{"Content-Type": []string{"application/json"}}, Body: io.NopCloser(bytes.NewReader(raw)), Request: r}, nil
	})
	c, o, e = run("sources", "refresh", "--manifest", manifestPath, "--output-dir", dest)
	if c != 0 || !json.Valid([]byte(o)) || e != "" {
		t.Fatalf("valid refresh=%d %q %q", c, o, e)
	}
	c, o, e = run("sources", "verify", "--manifest", filepath.Join(dest, "manifest.json"))
	if c != 0 || !json.Valid([]byte(o)) || e != "" {
		t.Fatalf("new snapshot verification=%d %q %q", c, o, e)
	}
	beforeCalls := calls
	c, o, e = run("sources", "refresh", "--manifest", manifestPath, "--output-dir", dest)
	if c != 1 || o != "" || e == "" || calls != beforeCalls {
		t.Fatalf("existing snapshot refresh=%d %q %q calls=%d", c, o, e, calls)
	}

}

func TestAnnotationCaseVariantOverwritePreservesOutput(t *testing.T) {
	output := filepath.Join(t.TempDir(), "map.json")
	before := []byte("previous validated output\n")
	if err := os.WriteFile(output, before, 0600); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "annotations.json")
	alias := `{"alias_id":"uapi:old","target_id":"uapi:absent","reason":"synthetic invalid mapping","evidence":[{"source_id":"whm--tokens","source_sha256":"d7f8f62dbe58cb2dbdb4ad37b7edc6bac5469a114b7eddee7af7dc8cd51e924d","prose_locator":"#using-an-api-token"}]}`
	annotation := `{"id":"invalid","scope":"operation","target_id":"uapi:absent","kind":"review","conclusion":"test","rationale":"synthetic invalid annotation","evidence":[{"source_id":"whm--tokens","source_sha256":"d7f8f62dbe58cb2dbdb4ad37b7edc6bac5469a114b7eddee7af7dc8cd51e924d","prose_locator":"#using-an-api-token"}]}`
	for _, raw := range []string{`{"schema_version":"1.0","annotations":[],"aliases":[],"ALIASES":[]}`, `{"schema_version":"1.0","annotations":[],"aliases":[` + alias + `],"ALIASES":[]}`, `{"schema_version":"1.0","annotations":[` + annotation + `],"ANNOTATIONS":[],"aliases":[]}`} {
		t.Run(raw, func(t *testing.T) {
			if err := os.WriteFile(path, []byte(raw), 0600); err != nil {
				t.Fatal(err)
			}
			c, o, e := run("map", "build", "--manifest", manifest, "--annotations", path, "--output", output)
			if c != 1 || o != "" || e == "" {
				t.Fatalf("invalid original annotations accepted: %d %q %q", c, o, e)
			}
			after, err := os.ReadFile(output)
			if err != nil || !bytes.Equal(after, before) {
				t.Fatal("failed case-variant build replaced output", err)
			}
		})
	}
}
