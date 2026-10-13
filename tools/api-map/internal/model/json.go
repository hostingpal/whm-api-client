package model

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"unicode/utf8"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

// DecodeMap validates the wrapper before decoding typed normalized records.
// Nested retained source objects use json.Number and are not schema-expanded.
func DecodeMap(data []byte) (Map, error) {
	value, err := decodeJSON(data)
	if err != nil {
		return Map{}, err
	}
	if err := schema.Validate("api-map-v1.schema.json", value); err != nil {
		return Map{}, fmt.Errorf("invalid API map: %w", err)
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()
	var result Map
	if err := decoder.Decode(&result); err != nil {
		return Map{}, err
	}
	return result, nil
}

// Encode produces deterministic two-space-indented JSON and one final newline.
// It sorts normalized map records without mutating the caller's values; raw
// document, manifest, and effective-value arrays preserve their source order.
func Encode(value any) ([]byte, error) {
	raw, err := json.Marshal(value)
	if err != nil {
		return nil, fmt.Errorf("encode JSON: %w", err)
	}
	copy, err := decodeJSON(raw)
	if err != nil {
		return nil, err
	}
	if object, ok := copy.(map[string]any); ok {
		normalize(object)
	}
	var output bytes.Buffer
	encoder := json.NewEncoder(&output)
	encoder.SetEscapeHTML(false)
	encoder.SetIndent("", "  ")
	if err := encoder.Encode(copy); err != nil {
		return nil, fmt.Errorf("encode JSON: %w", err)
	}
	return output.Bytes(), nil
}

func normalize(object map[string]any) {
	if _, ok := object["schema_version"]; !ok {
		return
	}
	sortRecords(object, "operations", []string{"id"})
	if operations, ok := object["operations"].([]any); ok {
		for _, raw := range operations {
			op, ok := raw.(map[string]any)
			if !ok {
				continue
			}
			sortRecords(op, "provenance", []string{"source_id", "source_sha256", "pointer", "prose_locator"})
			sortRecords(op, "variants", []string{"id"})
			if variants, ok := op["variants"].([]any); ok {
				for _, raw := range variants {
					variant, ok := raw.(map[string]any)
					if !ok {
						continue
					}
					sortRecords(variant, "parameters", []string{"name", "in"})
					if risk, ok := variant["risk"].(map[string]any); ok {
						sortEvidence(risk)
					}
				}
			}
		}
	}
	sortRecords(object, "aliases", []string{"alias_id", "target_id"})
	sortRecords(object, "annotations", []string{"id"})
	for _, name := range []string{"aliases", "annotations"} {
		if records, ok := object[name].([]any); ok {
			for _, raw := range records {
				if record, ok := raw.(map[string]any); ok {
					sortEvidence(record)
				}
			}
		}
	}
	sortRecords(object, "diagnostics", []string{"canonical_id", "severity", "code", "locator.source_id", "locator.source_sha256", "locator.pointer", "locator.prose_locator", "message"})
}

func sortEvidence(record map[string]any) {
	sortRecords(record, "evidence", []string{"source_id", "source_sha256", "pointer", "prose_locator"})
}

func sortRecords(object map[string]any, name string, keys []string) {
	records, ok := object[name].([]any)
	if !ok {
		return
	}
	sort.SliceStable(records, func(i, j int) bool {
		a, aOK := records[i].(map[string]any)
		b, bOK := records[j].(map[string]any)
		if !aOK || !bOK {
			return false
		}
		for _, key := range keys {
			av := fieldString(a, key)
			bv := fieldString(b, key)
			if av != bv {
				return av < bv
			}
		}
		return false
	})
}

func fieldString(object map[string]any, key string) string {
	var value any = object
	for _, part := range strings.Split(key, ".") {
		child, ok := value.(map[string]any)
		if !ok {
			return ""
		}
		value = child[part]
	}
	result, _ := value.(string)
	return result
}

// WriteAtomic writes already validated artifact bytes without interpreting them.
// The caller must complete all format/schema/content validation before invoking
// it. This supports JSON maps, Markdown reports, and checksum files equally.
// Rename happens only after the full temporary file is written and synced.
func WriteAtomic(path string, data []byte) error {
	directory := filepath.Dir(path)
	if err := os.MkdirAll(directory, 0755); err != nil {
		return err
	}
	file, err := os.CreateTemp(directory, "."+filepath.Base(path)+"-*")
	if err != nil {
		return err
	}
	temporary := file.Name()
	defer os.Remove(temporary)
	defer file.Close()
	if err := file.Chmod(0644); err != nil {
		return err
	}
	if _, err := file.Write(data); err != nil {
		return err
	}
	if err := file.Sync(); err != nil {
		return err
	}
	if err := file.Close(); err != nil {
		return err
	}
	if err := os.Rename(temporary, path); err != nil {
		return err
	}
	return nil
}

// decodeJSON uses tokens so duplicate keys cannot be silently discarded.
func decodeJSON(data []byte) (any, error) {
	if !utf8.Valid(data) {
		return nil, fmt.Errorf("invalid UTF-8 JSON")
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()
	value, err := decodeValue(decoder, 0)
	if err != nil {
		return nil, err
	}
	if _, err := decoder.Token(); err != io.EOF {
		if err == nil {
			return nil, fmt.Errorf("trailing JSON value")
		}
		return nil, fmt.Errorf("trailing JSON data: %w", err)
	}
	return value, nil
}

func decodeValue(decoder *json.Decoder, depth int) (any, error) {
	if depth > 128 {
		return nil, fmt.Errorf("JSON nesting exceeds 128")
	}
	token, err := decoder.Token()
	if err != nil {
		return nil, err
	}
	delimiter, isDelimiter := token.(json.Delim)
	if !isDelimiter {
		return token, nil
	}
	switch delimiter {
	case '{':
		object := make(map[string]any)
		for decoder.More() {
			token, err := decoder.Token()
			if err != nil {
				return nil, err
			}
			key, ok := token.(string)
			if !ok {
				return nil, fmt.Errorf("JSON object key is not a string")
			}
			if _, exists := object[key]; exists {
				return nil, fmt.Errorf("duplicate JSON key %q", key)
			}
			value, err := decodeValue(decoder, depth+1)
			if err != nil {
				return nil, err
			}
			object[key] = value
		}
		if _, err := decoder.Token(); err != nil {
			return nil, err
		}
		return object, nil
	case '[':
		values := make([]any, 0)
		for decoder.More() {
			value, err := decodeValue(decoder, depth+1)
			if err != nil {
				return nil, err
			}
			values = append(values, value)
		}
		if _, err := decoder.Token(); err != nil {
			return nil, err
		}
		return values, nil
	default:
		return nil, fmt.Errorf("unexpected JSON delimiter %q", delimiter)
	}
}
