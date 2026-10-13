package source

import (
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"strconv"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

func digest(raw []byte) string { s := sha256.Sum256(raw); return hex.EncodeToString(s[:]) }

// Load verifies every byte and reference identity before returning any bundle.
// No source loading path creates an HTTP client or performs opportunistic fetches.
func Load(manifestPath string, limits Limits) (Bundle, error) {
	l, err := limits.defaults()
	if err != nil {
		return Bundle{}, err
	}
	_, records, err := readManifest(manifestPath, l)
	if err != nil {
		return Bundle{}, err
	}
	b := Bundle{Records: records, Documents: map[string]model.Object{}}
	var total, storedTotal int64
	for _, rec := range records {
		p, err := confined(filepath.Dir(manifestPath), rec["file"].(string))
		if err != nil {
			return Bundle{}, err
		}
		f, err := os.Open(p)
		if err != nil {
			return Bundle{}, err
		}
		info, err := f.Stat()
		if err != nil || !info.Mode().IsRegular() {
			f.Close()
			return Bundle{}, fmt.Errorf("source is not a regular file")
		}
		if info.Size() != number(rec, "stored_bytes") || info.Size() > l.MaxInputBytes || info.Size() > l.MaxSourceSetBytes-storedTotal {
			f.Close()
			return Bundle{}, fmt.Errorf("stored source size mismatch or limit exceeded")
		}
		stored, err := boundedRead(f, min(l.MaxInputBytes, l.MaxSourceSetBytes-storedTotal))
		f.Close()
		if err != nil {
			return Bundle{}, err
		}
		storedTotal += int64(len(stored))
		if digest(stored) != rec["stored_sha256"] {
			return Bundle{}, fmt.Errorf("stored source hash mismatch")
		}
		raw := stored
		if rec["compression"] == "gzip" {
			reader, err := gzip.NewReader(bytes.NewReader(stored))
			if err != nil {
				return Bundle{}, err
			}
			raw, err = boundedRead(reader, min(l.MaxDecompressedBytes, l.MaxSourceSetBytes-total))
			closeErr := reader.Close()
			if err != nil {
				return Bundle{}, err
			}
			if closeErr != nil {
				return Bundle{}, closeErr
			}
		}
		if int64(len(raw)) > l.MaxDecompressedBytes || int64(len(raw)) > l.MaxSourceSetBytes-total {
			return Bundle{}, fmt.Errorf("source set limit exceeded")
		}
		total += int64(len(raw))
		if int64(len(raw)) != number(rec, "bytes") || digest(raw) != rec["sha256"] {
			return Bundle{}, fmt.Errorf("raw source size or hash mismatch")
		}
		if rec["kind"] == "openapi" || rec["kind"] == "reference" {
			doc, err := decode(raw, rec["file"].(string), l)
			if err != nil {
				return Bundle{}, err
			}
			if rec["kind"] == "openapi" {
				if _, _, _, _, err := metadata(doc); err != nil {
					return Bundle{}, err
				}
			}
			b.Documents[rec["id"].(string)] = doc
		}
	}
	if err := walkBundleReferences(&b, l, false, nil); err != nil {
		return Bundle{}, err
	}

	return b, nil
}

// ResolveReference returns a manifest source ID and decoded JSON pointer. Both
// original URL and resolved URL are source identities; local files never escape
// the snapshot. Reference documents are pinned with kind "reference".
func ResolveReference(b Bundle, sourceID, ref string) (string, string, error) {
	return resolveReference(b, sourceID, ref, true)
}
func resolveReference(b Bundle, sourceID, ref string, requireDocument bool) (string, string, error) {
	var origin model.Object
	for _, r := range b.Records {
		if r["id"] == sourceID {
			origin = r
			break
		}
	}
	if origin == nil {
		return "", "", fmt.Errorf("unknown source ID")
	}
	u, err := url.Parse(ref)
	if err != nil {
		return "", "", fmt.Errorf("invalid reference")
	}
	pointer := u.Fragment
	if pointer != "" && !strings.HasPrefix(pointer, "/") {
		return "", "", fmt.Errorf("only JSON pointer reference fragments are supported")
	}
	u.Fragment = ""
	u.RawFragment = ""
	if u.String() == "" {
		return sourceID, pointer, nil
	}
	if u.User != nil || u.Opaque != "" {
		return "", "", fmt.Errorf("invalid reference identity")
	}
	if u.Scheme == "" && u.Host == "" && !strings.HasPrefix(u.Path, "/") {
		local := path.Clean(path.Join(path.Dir(origin["file"].(string)), u.Path))
		if local == ".." || strings.HasPrefix(local, "../") || strings.Contains(local, "\\") {
			return "", "", fmt.Errorf("reference path escapes snapshot")
		}
		if u.RawQuery == "" {
			id, err := localTarget(b.Records, local)
			if err != nil {
				return "", "", err
			}
			if id != "" {
				if requireDocument && b.Documents[id] == nil {
					return "", "", fmt.Errorf("reference target is not a parsed document")
				}
				return id, pointer, nil
			}
		}
	}
	baseString, _ := origin["resolved_url"].(string)
	if baseString == "" {
		baseString = origin["url"].(string)
	}
	base, err := url.Parse(baseString)
	if err != nil {
		return "", "", err
	}
	target := base.ResolveReference(u).String()
	for _, r := range b.Records {
		if r["url"] == target || r["resolved_url"] == target {
			id := r["id"].(string)
			if requireDocument && b.Documents[id] == nil {
				return "", "", fmt.Errorf("reference target is not a parsed document")
			}
			return id, pointer, nil
		}
	}
	return "", "", fmt.Errorf("reference target is not pinned in manifest")
}

// Pointer evaluates RFC 6901 pointers without coercion, preserving null values.
func Pointer(doc model.Object, pointer string) (any, error) {
	var cur any = doc
	if pointer == "" {
		return cur, nil
	}
	if !strings.HasPrefix(pointer, "/") {
		return nil, fmt.Errorf("invalid JSON pointer")
	}
	for _, part := range strings.Split(pointer[1:], "/") {
		var key strings.Builder
		for i := 0; i < len(part); i++ {
			if part[i] != '~' {
				key.WriteByte(part[i])
				continue
			}
			i++
			if i >= len(part) || (part[i] != '0' && part[i] != '1') {
				return nil, fmt.Errorf("invalid JSON pointer escape")
			}
			if part[i] == '0' {
				key.WriteByte('~')
			} else {
				key.WriteByte('/')
			}
		}
		token := key.String()
		switch x := cur.(type) {
		case model.Object:
			v, ok := x[token]
			if !ok {
				return nil, fmt.Errorf("missing JSON pointer key")
			}
			cur = v
		case map[string]any:
			v, ok := x[token]
			if !ok {
				return nil, fmt.Errorf("missing JSON pointer key")
			}
			cur = v
		case []any:
			if token == "" || (len(token) > 1 && token[0] == '0') {
				return nil, fmt.Errorf("invalid JSON pointer array index")
			}
			for _, digit := range token {
				if digit < '0' || digit > '9' {
					return nil, fmt.Errorf("invalid JSON pointer array index")
				}
			}
			index, err := strconv.Atoi(token)
			if err != nil || index < 0 || index >= len(x) {
				return nil, fmt.Errorf("JSON pointer array index out of range")
			}
			cur = x[index]
		default:
			return nil, fmt.Errorf("JSON pointer traverses a scalar")
		}
	}
	return cur, nil
}

var methods = map[string]bool{"get": true, "put": true, "post": true, "delete": true, "options": true, "head": true, "patch": true, "trace": true}

func metadata(doc model.Object) (openapi, version string, paths, operations int, err error) {
	openapi, ok := doc["openapi"].(string)
	if !ok || !strings.HasPrefix(openapi, "3.0.") {
		return "", "", 0, 0, fmt.Errorf("source must declare OpenAPI 3.0")
	}
	info, ok := doc["info"].(map[string]any)
	if !ok {
		return "", "", 0, 0, fmt.Errorf("OpenAPI info must be an object")
	}
	version, ok = info["version"].(string)
	if !ok {
		return "", "", 0, 0, fmt.Errorf("OpenAPI info.version must be a string")
	}
	if _, ok = info["title"].(string); !ok {
		return "", "", 0, 0, fmt.Errorf("OpenAPI info.title must be a string")
	}
	items, ok := doc["paths"].(map[string]any)
	if !ok {
		return "", "", 0, 0, fmt.Errorf("OpenAPI paths must be an object")
	}
	for name, value := range items {
		if !strings.HasPrefix(name, "/") {
			if !strings.HasPrefix(name, "x-") {
				return "", "", 0, 0, fmt.Errorf("invalid Paths Object key")
			}
			continue
		}
		paths++
		item, ok := value.(map[string]any)
		if !ok {
			return "", "", 0, 0, fmt.Errorf("path item must be an object")
		}
		for key, v := range item {
			if methods[key] {
				if _, ok := v.(map[string]any); !ok {
					return "", "", 0, 0, fmt.Errorf("operation must be an object")
				}
				operations++
			}
		}
	}
	return openapi, version, paths, operations, nil
}
