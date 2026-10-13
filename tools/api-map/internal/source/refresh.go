package source

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/tls"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

type RefreshRequest struct {
	ManifestPath string
	SnapshotDir  string
	Limits       Limits
	Now          func() time.Time
	// Validate optionally performs semantic validation of the complete staged
	// bundle. Failure occurs before the new destination is claimed or selected.
	Validate func(Bundle) error
}

func allowed(u *url.URL) error {
	if u.Scheme != "https" || (u.Hostname() != "api.docs.cpanel.net" && u.Hostname() != "docs.cpanel.net") || (u.Port() != "" && u.Port() != "443") || u.User != nil || u.Opaque != "" || u.Fragment != "" {
		return fmt.Errorf("source URL is not allowlisted HTTPS")
	}
	return nil
}

// publicTransport enforces the network boundary even for injected transports.
// It also records the request identity for custom transports that omit it.
type publicTransport struct{ next http.RoundTripper }

func (t publicTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	if err := allowed(req.URL); err != nil {
		return nil, err
	}
	req.Header.Del("Authorization")
	req.Header.Del("Cookie")
	req.Header.Del("Proxy-Authorization")
	resp, err := t.next.RoundTrip(req)
	if resp != nil {
		resp.Request = req
	}
	return resp, err
}
func publicClient(in *http.Client) (*http.Client, error) {
	if in == nil {
		in = &http.Client{}
	}
	c := *in
	c.Jar = nil
	c.Timeout = 30 * time.Second
	if tr, ok := c.Transport.(*http.Transport); ok {
		copy := tr.Clone()
		copy.DisableCompression = true
		if copy.TLSClientConfig != nil {
			copy.TLSClientConfig = copy.TLSClientConfig.Clone()
			if copy.TLSClientConfig.InsecureSkipVerify || copy.TLSClientConfig.VerifyConnection != nil || copy.TLSClientConfig.VerifyPeerCertificate != nil {
				return nil, fmt.Errorf("TLS verification must remain enabled")
			}
			copy.TLSClientConfig.ServerName = ""
			copy.TLSClientConfig.Certificates = nil
			copy.TLSClientConfig.GetClientCertificate = nil
		}
		c.Transport = copy
	} else if c.Transport == nil {
		if defaultTransport, ok := http.DefaultTransport.(*http.Transport); ok {
			tr := defaultTransport.Clone()
			tr.DisableCompression = true
			tr.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12}
			c.Transport = tr
		} else {
			c.Transport = http.DefaultTransport
		}
	}
	c.Transport = publicTransport{next: c.Transport}
	c.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if len(via) > 3 {
			return fmt.Errorf("source redirect limit exceeded")
		}
		if err := allowed(req.URL); err != nil {
			return err
		}
		req.Header.Del("Authorization")
		req.Header.Del("Cookie")
		req.Header.Del("Proxy-Authorization")
		return nil
	}
	return &c, nil
}

// Refresh stages a fully validated snapshot, then exclusively claims the new
// directory and publishes its manifest last. A snapshot is selectable only when
// manifest.json exists; existing destinations are never removed or overwritten.
func Refresh(ctx context.Context, request RefreshRequest, client *http.Client) (result string, err error) {
	l, err := request.Limits.defaults()
	if err != nil {
		return "", err
	}
	if request.SnapshotDir == "" {
		return "", fmt.Errorf("new snapshot directory is required")
	}
	dest, err := filepath.Abs(request.SnapshotDir)
	if err != nil {
		return "", err
	}
	if _, e := os.Lstat(dest); e == nil {
		return "", fmt.Errorf("snapshot destination already exists")
	} else if !os.IsNotExist(e) {
		return "", e
	}
	// Verify old cache offline before issuing any download.
	old, err := Load(request.ManifestPath, l)
	if err != nil {
		return "", err
	}
	manifest, _, err := readManifest(request.ManifestPath, l)
	if err != nil {
		return "", err
	}
	c, err := publicClient(client)
	if err != nil {
		return "", err
	}
	parent := filepath.Dir(dest)
	if err = os.MkdirAll(parent, 0755); err != nil {
		return "", err
	}
	stage, err := os.MkdirTemp(parent, ".api-map-stage-")
	if err != nil {
		return "", err
	}
	defer os.RemoveAll(stage)
	now := request.Now
	if now == nil {
		now = time.Now
	}
	timestamp := now().UTC().Format(time.RFC3339Nano)
	records := make([]model.Object, 0, len(old.Records))
	for _, r := range old.Records {
		copy := model.Object{}
		for k, v := range r {
			copy[k] = v
		}
		records = append(records, copy)
	}
	referenceDepth := map[string]int{}
	docs := map[string]model.Object{}
	var total, storedTotal int64
	for i := 0; i < len(records); i++ {
		rec := records[i]
		u, err := url.Parse(rec["url"].(string))
		if err != nil {
			return "", err
		}
		if err = allowed(u); err != nil {
			return "", err
		}
		downloadCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
		req, err := http.NewRequestWithContext(downloadCtx, http.MethodGet, u.String(), nil)
		if err != nil {
			cancel()
			return "", err
		}
		req.Header.Set("Accept-Encoding", "identity")
		resp, err := c.Do(req)
		if err != nil {
			cancel()
			return "", fmt.Errorf("source download failed: %w", err)
		}
		if resp.StatusCode != http.StatusOK {
			resp.Body.Close()
			cancel()
			return "", fmt.Errorf("source download HTTP status %d", resp.StatusCode)
		}
		if err = allowed(resp.Request.URL); err != nil {
			resp.Body.Close()
			cancel()
			return "", err
		}
		if resp.ContentLength > l.MaxInputBytes {
			resp.Body.Close()
			cancel()
			return "", fmt.Errorf("source download exceeds input limit")
		}
		raw, err := readHTTPInput(resp, l, l.MaxSourceSetBytes-total)
		resp.Body.Close()
		cancel()
		if err != nil {
			return "", err
		}
		total += int64(len(raw))
		rec["resolved_url"] = resp.Request.URL.String()
		rec["retrieved_at"] = timestamp
		rec["content_type"] = resp.Header.Get("Content-Type")
		rec["bytes"] = len(raw)
		rec["sha256"] = digest(raw)
		if rec["kind"] == "openapi" || rec["kind"] == "reference" {
			doc, err := decode(raw, rec["file"].(string), l)
			if err != nil {
				return "", err
			}
			docs[rec["id"].(string)] = doc
			if rec["kind"] == "openapi" {
				openapi, version, paths, ops, err := metadata(doc)
				if err != nil {
					return "", err
				}
				rec["openapi"] = openapi
				rec["version"] = version
				rec["paths"] = paths
				rec["operations"] = ops
			}
			bundle := Bundle{Records: records, Documents: docs}
			if err := walkBundleReferences(&bundle, l, true, referenceDepth); err != nil {
				return "", err
			}
			records = bundle.Records
		}
		// All refreshed artifacts use reproducible gzip headers.
		var compressed bytes.Buffer
		writer := gzip.NewWriter(&compressed)
		if _, err = writer.Write(raw); err != nil {
			return "", err
		}
		if err = writer.Close(); err != nil {
			return "", err
		}
		if int64(compressed.Len()) > l.MaxInputBytes || int64(compressed.Len()) > l.MaxSourceSetBytes-storedTotal {
			return "", fmt.Errorf("stored download exceeds input limit")
		}
		storedTotal += int64(compressed.Len())
		rec["compression"] = "gzip"
		rec["stored_bytes"] = compressed.Len()
		rec["stored_sha256"] = digest(compressed.Bytes())
		name := rec["file"].(string)
		if !strings.HasSuffix(name, ".gz") {
			name += ".gz"
			rec["file"] = name
		}
		if filepath.IsAbs(name) || filepath.Clean(name) == ".." || strings.HasPrefix(filepath.Clean(name), ".."+string(filepath.Separator)) {
			return "", fmt.Errorf("unsafe refreshed source path")
		}
		if err = os.MkdirAll(filepath.Dir(filepath.Join(stage, name)), 0755); err != nil {
			return "", err
		}
		if err = os.WriteFile(filepath.Join(stage, name), compressed.Bytes(), 0644); err != nil {
			return "", err
		}
	}
	// Load below validates references across the complete staged source set.
	manifest["snapshot_id"] = filepath.Base(dest)
	manifest["sources"] = records
	if err = schema.Validate("source-manifest-v1.schema.json", manifest); err != nil {
		return "", err
	}
	data, err := model.Encode(manifest)
	if err != nil {
		return "", err
	}
	manifestPath := filepath.Join(stage, "manifest.json")
	if err = os.WriteFile(manifestPath, data, 0644); err != nil {
		return "", err
	}
	stagedBundle, err := Load(manifestPath, l)
	if err != nil {
		return "", err
	}
	if request.Validate != nil {
		if err = request.Validate(stagedBundle); err != nil {
			return "", fmt.Errorf("validate refreshed source set: %w", err)
		}
	}
	// Exclusive mkdir closes the destination check/publication race. Publishing
	// the manifest via a hard link is atomic and refuses an existing final file.
	if err = os.Mkdir(dest, 0755); err != nil {
		return "", fmt.Errorf("claim immutable snapshot: %w", err)
	}
	published := false
	defer func() {
		if !published {
			_ = os.RemoveAll(dest)
		}
	}()
	for _, rec := range records {
		name := rec["file"].(string)
		if err = os.MkdirAll(filepath.Dir(filepath.Join(dest, name)), 0755); err != nil {
			return "", err
		}
		if err = os.Rename(filepath.Join(stage, name), filepath.Join(dest, name)); err != nil {
			return "", err
		}
	}
	final := filepath.Join(dest, "manifest.json")
	if err = os.Link(manifestPath, final); err != nil {
		return "", err
	}
	published = true
	return final, nil
}
func pinReference(ref string, origin model.Object, records *[]model.Object, depths map[string]int, maxDepth int) error {
	u, err := url.Parse(ref)
	if err != nil {
		return fmt.Errorf("invalid external reference")
	}
	u.Fragment = ""
	u.RawFragment = ""
	if u.String() == "" {
		return nil
	}
	if u.Scheme == "" && u.Host == "" && !strings.HasPrefix(u.Path, "/") {
		candidate := filepath.Clean(filepath.Join(filepath.Dir(origin["file"].(string)), u.Path))
		if candidate == ".." || strings.HasPrefix(candidate, ".."+string(filepath.Separator)) || strings.Contains(candidate, "\\") {
			return fmt.Errorf("reference path escapes snapshot")
		}
		if u.RawQuery == "" {
			id, err := localTarget(*records, candidate)
			if err != nil {
				return err
			}
			if id != "" {
				return nil
			}
		}
	}
	base, _ := url.Parse(origin["resolved_url"].(string))
	u = base.ResolveReference(u)
	if err = allowed(u); err != nil {
		return err
	}
	for _, r := range *records {
		if r["url"] == u.String() || r["resolved_url"] == u.String() {
			return nil
		}
	}
	// The exact URL determines stable identity and filename; reference documents
	// are retained but never analyzed as API operation specifications.
	suffix := ".json"
	if strings.HasSuffix(u.Path, ".yaml") || strings.HasSuffix(u.Path, ".yml") {
		suffix = ".yaml"
	}
	id := "reference-" + digest([]byte(u.String()))
	depth := depths[origin["id"].(string)] + 1
	if depth > maxDepth {
		return fmt.Errorf("external reference traversal limit exceeded")
	}
	depths[id] = depth
	*records = append(*records, model.Object{"id": id, "kind": "reference", "url": u.String(), "file": id + suffix + ".gz"})
	return nil
}

func readHTTPInput(resp *http.Response, l Limits, remaining int64) ([]byte, error) {
	// Injected transports must return the actual wire body so its input bound
	// can be enforced. Go marks transparently decoded responses Uncompressed.
	if resp.Uncompressed {
		return nil, fmt.Errorf("transport decompressed input before byte verification")
	}
	encoding := strings.TrimSpace(strings.ToLower(resp.Header.Get("Content-Encoding")))
	if encoding == "" || encoding == "identity" {
		return boundedRead(resp.Body, min(l.MaxInputBytes, l.MaxDecompressedBytes, remaining))
	}
	if encoding != "gzip" {
		return nil, fmt.Errorf("unsupported HTTP content encoding")
	}
	input, err := boundedRead(resp.Body, l.MaxInputBytes)
	if err != nil {
		return nil, err
	}
	reader, err := gzip.NewReader(bytes.NewReader(input))
	if err != nil {
		return nil, err
	}
	defer reader.Close()
	return boundedRead(reader, min(l.MaxDecompressedBytes, remaining))
}
