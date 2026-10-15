package cli

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"unicode/utf8"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

type toolchainConfig struct {
	SchemaVersion string         `json:"schema_version"`
	Manifest      string         `json:"manifest"`
	Annotations   string         `json:"annotations"`
	Overlay       string         `json:"overlay"`
	SchemaDir     string         `json:"schema_dir"`
	Map           string         `json:"map"`
	MapChecksum   string         `json:"map_checksum"`
	Examples      []checkExample `json:"examples"`
}
type checkExample struct {
	Path   string `json:"path"`
	Schema string `json:"schema"`
}

func loadToolchain(root string) (toolchainConfig, error) {
	var cfg toolchainConfig
	p, err := confinedCheckPath(root, "docs/api/toolchain.json")
	if err != nil {
		return cfg, err
	}
	raw, err := os.ReadFile(p)
	if err != nil {
		return cfg, err
	}
	value, err := decodeCheckJSON(raw)
	if err != nil {
		return cfg, err
	}
	if err = schema.Validate("toolchain-v1.schema.json", value); err != nil {
		return cfg, err
	}
	if err = json.Unmarshal(raw, &cfg); err != nil {
		return cfg, err
	}
	for _, p := range []*string{&cfg.Manifest, &cfg.Annotations, &cfg.Overlay, &cfg.SchemaDir, &cfg.MapChecksum} {
		if *p == "" {
			continue
		}
		*p, err = confinedCheckPath(root, *p)
		if err != nil {
			return cfg, err
		}
	}
	// Map is a checksum label only: check never reads or creates this cache path.
	if !filepath.IsLocal(cfg.Map) || filepath.Clean(cfg.Map) != cfg.Map || strings.Contains(cfg.Map, "\\") {
		return cfg, fmt.Errorf("invalid map label")
	}
	for i := range cfg.Examples {
		cfg.Examples[i].Path, err = confinedCheckPath(root, cfg.Examples[i].Path)
		if err != nil {
			return cfg, err
		}
	}
	return cfg, nil
}

func confinedCheckPath(root, name string) (string, error) {
	if !filepath.IsLocal(name) || filepath.Clean(name) != name || strings.Contains(name, "\\") {
		return "", fmt.Errorf("path must be relative and confined")
	}
	p, err := filepath.EvalSymlinks(filepath.Join(root, name))
	if err != nil {
		return "", err
	}
	relative, err := filepath.Rel(root, p)
	if err != nil || !filepath.IsLocal(relative) {
		return "", fmt.Errorf("path escapes repository")
	}
	return p, nil
}

// Configuration/examples reject ambiguous duplicate keys, malformed UTF-8 and
// trailing JSON before schema validation. Numbers retain their original precision.
func decodeCheckJSON(raw []byte) (any, error) {
	if !utf8.Valid(raw) {
		return nil, fmt.Errorf("invalid UTF-8")
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	d.UseNumber()
	var read func(int) (any, error)
	read = func(depth int) (any, error) {
		if depth > 256 {
			return nil, fmt.Errorf("JSON nesting too deep")
		}
		token, err := d.Token()
		if err != nil {
			return nil, err
		}
		delimiter, ok := token.(json.Delim)
		if !ok {
			return token, nil
		}
		switch delimiter {
		case '{':
			value := map[string]any{}
			for d.More() {
				key, err := d.Token()
				if err != nil {
					return nil, err
				}
				name, ok := key.(string)
				if !ok {
					return nil, fmt.Errorf("invalid object key")
				}
				if _, exists := value[name]; exists {
					return nil, fmt.Errorf("duplicate object key")
				}
				value[name], err = read(depth + 1)
				if err != nil {
					return nil, err
				}
			}
			end, err := d.Token()
			if err != nil || end != json.Delim('}') {
				return nil, fmt.Errorf("invalid object end")
			}
			return value, nil
		case '[':
			value := []any{}
			for d.More() {
				v, err := read(depth + 1)
				if err != nil {
					return nil, err
				}
				value = append(value, v)
			}
			end, err := d.Token()
			if err != nil || end != json.Delim(']') {
				return nil, fmt.Errorf("invalid array end")
			}
			return value, nil
		}
		return nil, fmt.Errorf("invalid delimiter")
	}
	value, err := read(0)
	if err != nil {
		return nil, err
	}
	if _, err = d.Token(); err != io.EOF {
		return nil, fmt.Errorf("trailing JSON")
	}
	return value, nil
}
