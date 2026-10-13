// Package schema validates normalized wrappers using only embedded schemas.
// Raw OpenAPI objects inside these wrappers retain their original dialect.
package schema

import (
	"bytes"
	"embed"
	"encoding/json"
	"fmt"
	"path"
	"strings"
	"sync"

	"github.com/santhosh-tekuri/jsonschema/v6"
)

//go:generate go run sync.go
//go:embed assets/*.schema.json
var assets embed.FS

const registryBase = "https://hostingpal.github.io/whm-api-client/schema/"

type embeddedLoader struct{}

// Load resolves only exact embedded registry URLs, never files or network URLs.
func (embeddedLoader) Load(url string) (any, error) {
	if !strings.HasPrefix(url, registryBase) {
		return nil, fmt.Errorf("schema is not embedded: %s", url)
	}
	name := strings.TrimPrefix(url, registryBase)
	if name != path.Base(name) {
		return nil, fmt.Errorf("schema is not embedded: %s", url)
	}
	data, err := assets.ReadFile("assets/" + name)
	if err != nil {
		return nil, fmt.Errorf("schema is not embedded: %s", name)
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()
	var value any
	if err := decoder.Decode(&value); err != nil {
		return nil, fmt.Errorf("invalid embedded schema %s: %w", name, err)
	}
	return value, nil
}

var compiled = struct {
	sync.Mutex
	schemas map[string]*jsonschema.Schema
}{schemas: make(map[string]*jsonschema.Schema)}

func compile(name string) (*jsonschema.Schema, error) {
	compiled.Lock()
	defer compiled.Unlock()
	if s, ok := compiled.schemas[name]; ok {
		return s, nil
	}
	compiler := jsonschema.NewCompiler()
	compiler.UseLoader(embeddedLoader{})
	compiler.DefaultDraft(jsonschema.Draft2020)
	entries, err := assets.ReadDir("assets")
	if err != nil {
		return nil, err
	}
	for _, entry := range entries {
		url := registryBase + entry.Name()
		value, err := (embeddedLoader{}).Load(url)
		if err != nil {
			return nil, err
		}
		if err := compiler.AddResource(url, value); err != nil {
			return nil, err
		}
	}
	s, err := compiler.Compile(registryBase + name)
	if err != nil {
		return nil, err
	}
	compiled.schemas[name] = s
	return s, nil
}

// Validate accepts an embedded schema filename or its exact registry URL.
// It converts typed Go values to JSON-compatible objects without number rounding.
func Validate(name string, value any) error {
	name = strings.TrimPrefix(name, registryBase)
	if name != path.Base(name) {
		return fmt.Errorf("schema is not embedded: %s", name)
	}
	if _, err := assets.ReadFile("assets/" + name); err != nil {
		return fmt.Errorf("schema is not embedded: %s", name)
	}
	s, err := compile(name)
	if err != nil {
		return fmt.Errorf("compile schema %s: %w", name, err)
	}
	data, err := json.Marshal(value)
	if err != nil {
		return fmt.Errorf("encode schema input: %w", err)
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()
	var normalized any
	if err := decoder.Decode(&normalized); err != nil {
		return fmt.Errorf("decode schema input: %w", err)
	}
	return s.Validate(normalized)
}
