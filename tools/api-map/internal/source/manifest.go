// Package source verifies immutable source snapshots without network access.
package source

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

type Bundle struct {
	Records []model.Object
	// Documents includes OpenAPI inputs and pinned reference documents. Only
	// records with kind "openapi" are operation-bearing API specifications.
	Documents map[string]model.Object
}

type Limits struct {
	MaxInputBytes        int64
	MaxDecompressedBytes int64
	MaxSourceSetBytes    int64
	MaxDepth             int
	MaxAliasNodes        int
}

func DefaultLimits() Limits { return Limits{32 << 20, 64 << 20, 256 << 20, 128, 10000} }
func (l Limits) defaults() (Limits, error) {
	d := DefaultLimits()
	if l.MaxInputBytes < 0 || l.MaxDecompressedBytes < 0 || l.MaxSourceSetBytes < 0 || l.MaxDepth < 0 || l.MaxAliasNodes < 0 {
		return l, fmt.Errorf("limits must be positive")
	}
	if l.MaxInputBytes == 0 {
		l.MaxInputBytes = d.MaxInputBytes
	}
	if l.MaxDecompressedBytes == 0 {
		l.MaxDecompressedBytes = d.MaxDecompressedBytes
	}
	if l.MaxSourceSetBytes == 0 {
		l.MaxSourceSetBytes = d.MaxSourceSetBytes
	}
	if l.MaxDepth == 0 {
		l.MaxDepth = d.MaxDepth
	}
	if l.MaxAliasNodes == 0 {
		l.MaxAliasNodes = d.MaxAliasNodes
	}
	return l, nil
}
func boundedRead(r io.Reader, limit int64) ([]byte, error) {
	// Read into a bounded stream rather than allocating from untrusted sizes.
	if limit < 0 || limit == int64(^uint64(0)>>1) {
		return nil, fmt.Errorf("invalid byte limit")
	}
	b, err := io.ReadAll(io.LimitReader(r, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(b)) > limit {
		return nil, fmt.Errorf("source exceeds byte limit")
	}
	return b, nil
}
func confined(root, name string) (string, error) {
	if name == "" || filepath.IsAbs(name) || strings.Contains(name, "\\") {
		return "", fmt.Errorf("invalid source path")
	}
	clean := filepath.Clean(name)
	if clean == ".." || strings.HasPrefix(clean, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("source path escapes snapshot")
	}
	base, err := filepath.EvalSymlinks(root)
	if err != nil {
		return "", err
	}
	p, err := filepath.EvalSymlinks(filepath.Join(base, clean))
	if err != nil {
		return "", err
	}
	rel, err := filepath.Rel(base, p)
	if err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("source symlink escapes snapshot")
	}
	return p, nil
}
func readManifest(p string, l Limits) (model.Object, []model.Object, error) {
	f, err := os.Open(p)
	if err != nil {
		return nil, nil, err
	}
	defer f.Close()
	raw, err := boundedRead(f, l.MaxInputBytes)
	if err != nil {
		return nil, nil, err
	}
	value, err := decode(raw, "manifest.json", l)
	if err != nil {
		return nil, nil, err
	}
	if err = schema.Validate("source-manifest-v1.schema.json", value); err != nil {
		return nil, nil, fmt.Errorf("invalid source manifest: %w", err)
	}
	list := value["sources"].([]any)
	records := make([]model.Object, 0, len(list))
	ids := map[string]bool{}
	files := map[string]string{}
	identities := map[string]string{}
	for _, v := range list {
		rec := model.Object(v.(map[string]any))
		id := rec["id"].(string)
		file := filepath.Clean(rec["file"].(string))
		if _, err := time.Parse(time.RFC3339Nano, rec["retrieved_at"].(string)); err != nil {
			return nil, nil, fmt.Errorf("invalid source retrieval timestamp")
		}
		if ids[id] {
			return nil, nil, fmt.Errorf("duplicate source ID")
		}
		ids[id] = true
		for _, identity := range fileIdentities(file) {
			if previous, exists := files[identity]; exists && previous != id {
				return nil, nil, fmt.Errorf("ambiguous source file identity")
			}
			files[identity] = id
		}
		for _, k := range []string{"url", "resolved_url"} {
			if u, ok := rec[k].(string); ok {
				if previous, ok := identities[u]; ok && previous != id {
					return nil, nil, fmt.Errorf("ambiguous source URL identity")
				}
				identities[u] = id
			}
		}
		records = append(records, rec)
	}
	return value, records, nil
}
func number(rec model.Object, key string) int64 {
	v, _ := rec[key].(json.Number)
	n, _ := v.Int64()
	return n
}

// fileIdentities defines the shared local policy: exact normalized storage path
// and its optional .gz-stripped source name must identify the same sole record.
func fileIdentities(file string) []string {
	clean := filepath.ToSlash(filepath.Clean(file))
	ids := []string{clean}
	if strings.HasSuffix(clean, ".gz") {
		ids = append(ids, strings.TrimSuffix(clean, ".gz"))
	}
	return ids
}
func localTarget(records []model.Object, candidate string) (string, error) {
	candidate = filepath.ToSlash(filepath.Clean(candidate))
	target := ""
	for _, rec := range records {
		for _, identity := range fileIdentities(rec["file"].(string)) {
			if identity == candidate {
				id := rec["id"].(string)
				if target != "" && target != id {
					return "", fmt.Errorf("ambiguous source file identity")
				}
				target = id
			}
		}
	}
	return target, nil
}
