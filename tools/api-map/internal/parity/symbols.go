// Package parity validates declarations linking canonical operations to PHP symbols.
package parity

import (
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"io"
	"unicode/utf8"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

type Symbol struct {
	ID             string   `json:"id"`
	Kind           string   `json:"kind"`
	DeclaringClass string   `json:"declaring_class"`
	File           string   `json:"file"`
	Line           int      `json:"line"`
	Public         bool     `json:"public"`
	Static         bool     `json:"static"`
	Abstract       bool     `json:"abstract"`
	Providers      []string `json:"providers"`
}

type SymbolDiagnostic struct {
	Severity string `json:"severity"`
	Code     string `json:"code"`
	Message  string `json:"message"`
	Class    string `json:"class"`
	File     string `json:"file"`
}

type Symbols struct {
	originSHA256  string
	SchemaVersion string             `json:"schema_version"`
	Symbols       []Symbol           `json:"symbols"`
	Diagnostics   []SymbolDiagnostic `json:"diagnostics"`
}

func LoadSymbols(data []byte) (Symbols, error) {
	var result Symbols
	if err := load(data, "php-symbols-v1.schema.json", &result); err != nil {
		return Symbols{}, err
	}
	result.originSHA256 = fmt.Sprintf("%x", sha256.Sum256(data))
	return result, nil
}

func load(data []byte, name string, dest any) error {
	if !utf8.Valid(data) {
		return fmt.Errorf("invalid UTF-8 JSON")
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()
	value, err := readValue(decoder, 0)
	if err != nil {
		return err
	}
	if _, err := decoder.Token(); err != io.EOF {
		return fmt.Errorf("trailing JSON data")
	}
	if err := schema.Validate(name, value); err != nil {
		return fmt.Errorf("invalid %s: %w", name, err)
	}
	decoder = json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()
	decoder.DisallowUnknownFields()
	return decoder.Decode(dest)
}

// Token decoding rejects duplicate keys before schema validation can lose them.
func readValue(d *json.Decoder, depth int) (any, error) {
	if depth > 128 {
		return nil, fmt.Errorf("JSON nesting exceeds 128")
	}
	token, err := d.Token()
	if err != nil {
		return nil, err
	}
	delim, ok := token.(json.Delim)
	if !ok {
		return token, nil
	}
	switch delim {
	case '{':
		object := map[string]any{}
		for d.More() {
			token, err := d.Token()
			if err != nil {
				return nil, err
			}
			key, ok := token.(string)
			if !ok {
				return nil, fmt.Errorf("non-string object key")
			}
			if _, ok := object[key]; ok {
				return nil, fmt.Errorf("duplicate JSON key %q", key)
			}
			value, err := readValue(d, depth+1)
			if err != nil {
				return nil, err
			}
			object[key] = value
		}
		if _, err := d.Token(); err != nil {
			return nil, err
		}
		return object, nil
	case '[':
		values := []any{}
		for d.More() {
			value, err := readValue(d, depth+1)
			if err != nil {
				return nil, err
			}
			values = append(values, value)
		}
		if _, err := d.Token(); err != nil {
			return nil, err
		}
		return values, nil
	default:
		return nil, fmt.Errorf("unexpected JSON delimiter")
	}
}
