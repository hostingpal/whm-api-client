package source

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

type roundTrip func(*http.Request) (*http.Response, error)

func (f roundTrip) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func response(r *http.Request, status int, body string) *http.Response {
	return &http.Response{StatusCode: status, Header: make(http.Header), Body: io.NopCloser(strings.NewReader(body)), Request: r}
}
func TestRefreshIsAtomicAndAllowlisted(t *testing.T) {
	for _, name := range []string{"success", "second failure", "existing", "redirect escape", "corrupt", "redirect loop"} {
		t.Run(name, func(t *testing.T) {
			p, r := fixture(t, valid, "spec.gz")
			r2 := map[string]any{}
			for k, v := range r {
				r2[k] = v
			}
			r2["id"] = "other"
			r2["url"] = "https://docs.cpanel.net/other.json"
			r2["file"] = "other.gz"
			data, _ := os.ReadFile(filepath.Join(filepath.Dir(p), "spec.gz"))
			_ = os.WriteFile(filepath.Join(filepath.Dir(p), "other.gz"), data, 0600)
			writeManifest(t, p, []map[string]any{r, r2})
			dest := filepath.Join(t.TempDir(), "new")
			if name == "existing" {
				_ = os.Mkdir(dest, 0700)
				_ = os.WriteFile(filepath.Join(dest, "sentinel"), []byte("old"), 0600)
			}
			calls := 0
			client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
				calls++
				if req.Header.Get("Authorization") != "" || req.Header.Get("Cookie") != "" {
					t.Fatal("credentials sent")
				}
				if req.URL.Host != "api.docs.cpanel.net" && req.URL.Host != "docs.cpanel.net" {
					t.Fatal("off-list request")
				}
				if name == "second failure" && calls == 2 {
					return nil, fmt.Errorf("fixture failure")
				}
				if name == "redirect escape" || name == "redirect loop" {
					resp := response(req, 302, "")
					location := "https://example.com/escape"
					if name == "redirect loop" {
						location = req.URL.String()
					}
					resp.Header.Set("Location", location)
					return resp, nil
				}
				if name == "corrupt" {
					return response(req, 200, `{"openapi":"3.0.2","paths":false}`), nil
				}
				return response(req, 200, valid), nil
			})}
			result, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: dest, Now: func() time.Time { return time.Date(2026, 10, 14, 0, 0, 0, 0, time.UTC) }}, client)
			if name == "success" {
				if err != nil {
					t.Fatal(err)
				}
				b, e := Load(result, DefaultLimits())
				if e != nil {
					t.Fatal(e)
				}
				if b.Records[0]["paths"] != jsonNumber("2") || b.Records[0]["operations"] != jsonNumber("1") || b.Records[0]["version"] != "1" {
					t.Fatalf("metadata: %#v", b.Records[0])
				}
				return
			}
			if err == nil || result != "" {
				t.Fatalf("expected failure: %q %v", result, err)
			}
			if _, e := os.Stat(filepath.Join(dest, "manifest.json")); !os.IsNotExist(e) {
				t.Fatal("published partial manifest")
			}
			if name == "existing" {
				v, _ := os.ReadFile(filepath.Join(dest, "sentinel"))
				if string(v) != "old" {
					t.Fatal("replaced existing snapshot")
				}
			}
		})
	}
}
func jsonNumber(s string) any { return json.Number(s) }
func TestRefreshRejectsUntrustedTLS(t *testing.T) {
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = io.WriteString(w, valid) }))
	defer s.Close()
	p, _ := fixture(t, valid, "spec.gz")
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.Proxy = nil
	tr.DialContext = func(ctx context.Context, network, address string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, network, s.Listener.Addr().String())
	}
	if _, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, &http.Client{Transport: tr}); err == nil {
		t.Fatal("accepted untrusted TLS")
	}
}

func TestRefreshPinsNewExternalReference(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	destination := filepath.Join(t.TempDir(), "new")
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		if req.URL.String() == "https://api.docs.cpanel.net/spec.json" {
			return response(req, 200, `{"openapi":"3.0.2","info":{"title":"New","version":"2"},"paths":{},"components":{"schemas":{"Remote":{"$ref":"https://docs.cpanel.net/common.json#/Thing"}}}}`), nil
		}
		if req.URL.String() == "https://docs.cpanel.net/common.json" {
			return response(req, 200, `{"Thing":{"type":"string"}}`), nil
		}
		return nil, fmt.Errorf("unexpected URL: %s", req.URL)
	})}
	result, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: destination}, client)
	if err != nil {
		t.Fatal(err)
	}
	b, err := Load(result, DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	if len(b.Records) != 2 || b.Records[1]["kind"] != "reference" {
		t.Fatalf("missing pinned external reference: %#v", b.Records)
	}
	target, ptr, err := ResolveReference(b, "spec", "https://docs.cpanel.net/common.json#/Thing")
	if err != nil || ptr != "/Thing" || b.Documents[target] == nil {
		t.Fatalf("external identity: %s %s %v", target, ptr, err)
	}
}
func TestRefreshRejectsUnsafeTLSConfiguration(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.TLSClientConfig = &tls.Config{InsecureSkipVerify: true}
	if _, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, &http.Client{Transport: tr}); err == nil {
		t.Fatal("disabled certificate validation accepted")
	}
}
func TestRefreshBoundsExternalReferenceChains(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		n := strings.TrimPrefix(req.URL.Path, "/ref")
		if req.URL.Path == "/spec.json" {
			return response(req, 200, `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"X":{"$ref":"https://docs.cpanel.net/ref1#/X"}}}}`), nil
		}
		i, _ := strconv.Atoi(n)
		if i > 7 {
			return nil, fmt.Errorf("unexpected unbounded chain")
		}
		return response(req, 200, fmt.Sprintf(`{"X":{"$ref":"https://docs.cpanel.net/ref%d#/X"}}`, i+1)), nil
	})}
	_, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new"), Limits: Limits{MaxDepth: 6}}, client)
	if err == nil || !strings.Contains(err.Error(), "reference traversal") {
		t.Fatalf("expected reference traversal bound: %v", err)
	}
}

func TestRefreshTransportWithoutResponseRequest(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		deadline, ok := req.Context().Deadline()
		if !ok || time.Until(deadline) > 30*time.Second {
			t.Fatal("overall request deadline missing")
		}
		r := response(req, 200, valid)
		r.Request = nil
		return r, nil
	})}
	path, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, client)
	if err != nil || path == "" {
		t.Fatalf("valid custom transport failed: %s %v", path, err)
	}
}
func TestRefreshConcurrentDestinationRemainsUntouched(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	dest := filepath.Join(t.TempDir(), "new")
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		if err := os.Mkdir(dest, 0700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(dest, "sentinel"), []byte("other writer"), 0600); err != nil {
			t.Fatal(err)
		}
		return response(req, 200, valid), nil
	})}
	path, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: dest}, client)
	if err == nil || path != "" {
		t.Fatal("replaced raced destination")
	}
	sentinel, err := os.ReadFile(filepath.Join(dest, "sentinel"))
	if err != nil || string(sentinel) != "other writer" {
		t.Fatalf("concurrent destination lost: %v", err)
	}
}

func TestRefreshHandlesBoundedGzipHTTPInput(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	var compressed bytes.Buffer
	g := gzip.NewWriter(&compressed)
	_, _ = g.Write([]byte(valid))
	_ = g.Close()
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		r := response(req, 200, compressed.String())
		r.Header.Set("Content-Encoding", "gzip")
		return r, nil
	})}
	result, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, client)
	if err != nil {
		t.Fatal(err)
	}
	b, err := Load(result, DefaultLimits())
	if err != nil || b.Documents["spec"]["x-number"] != json.Number("9007199254740993") {
		t.Fatalf("compressed refresh: %#v %v", b, err)
	}
}

func TestRefreshRejectsTransparentDecompression(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		r := response(req, 200, valid)
		r.Uncompressed = true
		return r, nil
	})}
	if _, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, client); err == nil {
		t.Fatal("unmeasurable compressed input accepted")
	}
}
func TestRefreshRejectsGzipExpansionBeyondLimit(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	var compressed bytes.Buffer
	g := gzip.NewWriter(&compressed)
	_, _ = g.Write([]byte(strings.Replace(valid, `"x-empty":""`, `"x-empty":"`+strings.Repeat("a", 4096)+`"`, 1)))
	_ = g.Close()
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		r := response(req, 200, compressed.String())
		r.Header.Set("Content-Encoding", "gzip")
		return r, nil
	})}
	dest := filepath.Join(t.TempDir(), "new")
	if _, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: dest, Limits: Limits{MaxDecompressedBytes: 1024}}, client); err == nil {
		t.Fatal("gzip expansion limit ignored")
	}
	if _, err := os.Stat(dest); !os.IsNotExist(err) {
		t.Fatal("published oversized gzip snapshot")
	}
}

func TestRefreshDoesNotFetchReferenceLookingData(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	calls := 0
	raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"S":{"default":{"$ref":"https://docs.cpanel.net/data.json"},"example":{"$ref":null},"x-data":{"$ref":"../data.json"}}}},"x-data":{"$ref":"https://example.com/data.json"}}`
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		calls++
		if calls > 1 {
			return nil, fmt.Errorf("data was downloaded")
		}
		return response(req, 200, raw), nil
	})}
	if _, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, client); err != nil {
		t.Fatal(err)
	}
	if calls != 1 {
		t.Fatal("literal data triggered HTTP")
	}
}

func TestRefreshPinnedFragmentsUseOriginContext(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		switch req.URL.String() {
		case "https://api.docs.cpanel.net/spec.json":
			return response(req, 200, `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"schemas":{"Remote":{"$ref":"https://docs.cpanel.net/common.json#/Thing"}}}}`), nil
		case "https://docs.cpanel.net/common.json":
			return response(req, 200, `{"Thing":{"default":{"$ref":"https://example.com/literal"},"properties":{"example":{"$ref":"https://docs.cpanel.net/child.json#/Child"}}}}`), nil
		case "https://docs.cpanel.net/child.json":
			return response(req, 200, `{"Child":{"type":"string"}}`), nil
		default:
			return nil, fmt.Errorf("literal data downloaded: %s", req.URL)
		}
	})}
	path, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, client)
	if err != nil {
		t.Fatal(err)
	}
	b, err := Load(path, DefaultLimits())
	if err != nil || len(b.Records) != 3 {
		t.Fatalf("fragment context not preserved: %d %v", len(b.Records), err)
	}
}

func TestRefreshReferenceSiblingsNeverDownload(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	calls := 0
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
		calls++
		if calls > 1 {
			return nil, fmt.Errorf("Reference Object sibling was downloaded")
		}
		return response(req, 200, refSiblingFixture), nil
	})}
	result, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, client)
	if err != nil {
		t.Fatal(err)
	}
	b, err := Load(result, DefaultLimits())
	if err != nil || len(b.Records) != 1 || calls != 1 {
		t.Fatalf("ignored siblings affected snapshot: %d %d %v", len(b.Records), calls, err)
	}
}
func TestRefreshPinsXPrefixedComponentReferences(t *testing.T) {
	for _, kind := range []string{"responses", "callbacks"} {
		t.Run(kind, func(t *testing.T) {
			p, _ := fixture(t, valid, "spec.gz")
			raw := `{"openapi":"3.0.2","info":{"title":"X","version":"1"},"paths":{},"components":{"` + kind + `":{"x-ValidComponent":{"$ref":"https://docs.cpanel.net/fragment.json#/Target"}}}}`
			client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) {
				switch req.URL.String() {
				case "https://api.docs.cpanel.net/spec.json":
					return response(req, 200, raw), nil
				case "https://docs.cpanel.net/fragment.json":
					if kind == "responses" {
						return response(req, 200, `{"Target":{"description":"OK","content":{"application/json":{"schema":{"$ref":"#/Nested"}}}},"Nested":{"type":"string"}}`), nil
					}
					return response(req, 200, `{"Target":{"{$request.body#/callbackUrl}":{"post":{"responses":{"200":{"$ref":"#/Nested"}}}}},"Nested":{"description":"OK"}}`), nil
				default:
					return nil, fmt.Errorf("unexpected URL")
				}
			})}
			result, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: filepath.Join(t.TempDir(), "new")}, client)
			if err != nil {
				t.Fatal(err)
			}
			b, err := Load(result, DefaultLimits())
			if err != nil || len(b.Records) != 2 {
				t.Fatalf("x-component not pinned: %d %v", len(b.Records), err)
			}
		})
	}
}
func TestRefreshRejectsAmbiguousFileIdentityBeforeHTTP(t *testing.T) {
	p, records := ambiguousFileFixture(t)
	writeManifest(t, p, records)
	calls := 0
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) { calls++; return response(req, 200, valid), nil })}
	dest := filepath.Join(t.TempDir(), "new")
	result, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: dest}, client)
	if err == nil || result != "" || calls != 0 {
		t.Fatalf("ambiguous inputs affected refresh: %s %d %v", result, calls, err)
	}
	if _, err = os.Stat(dest); !os.IsNotExist(err) {
		t.Fatal("ambiguous snapshot published")
	}
}

func TestRefreshValidatorRejectionPreservesSnapshots(t *testing.T) {
	p, _ := fixture(t, valid, "spec.gz")
	before, err := os.ReadFile(p)
	if err != nil {
		t.Fatal(err)
	}
	oldArtifact, err := os.ReadFile(filepath.Join(filepath.Dir(p), "spec.gz"))
	if err != nil {
		t.Fatal(err)
	}
	calls := 0
	client := &http.Client{Transport: roundTrip(func(req *http.Request) (*http.Response, error) { return response(req, 200, valid), nil })}
	dest := filepath.Join(t.TempDir(), "new")
	result, err := Refresh(context.Background(), RefreshRequest{ManifestPath: p, SnapshotDir: dest, Validate: func(b Bundle) error {
		calls++
		if len(b.Records) != 1 || b.Documents["spec"] == nil {
			t.Fatal("validator received incomplete bundle")
		}
		if _, err := os.Stat(dest); !os.IsNotExist(err) {
			t.Fatal("destination claimed before validation")
		}
		return fmt.Errorf("semantic validator rejection")
	}}, client)
	if err == nil || result != "" || calls != 1 {
		t.Fatalf("validator did not reject: %s %d %v", result, calls, err)
	}
	if _, err := os.Stat(dest); !os.IsNotExist(err) {
		t.Fatal("rejected new destination exists")
	}
	after, _ := os.ReadFile(p)
	newArtifact, _ := os.ReadFile(filepath.Join(filepath.Dir(p), "spec.gz"))
	if !bytes.Equal(before, after) || !bytes.Equal(oldArtifact, newArtifact) {
		t.Fatal("prior snapshot changed")
	}
	stages, _ := filepath.Glob(filepath.Join(filepath.Dir(dest), ".api-map-stage-*"))
	if len(stages) != 0 {
		t.Fatal("failed validation left staged files")
	}
}
