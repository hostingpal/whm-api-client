package mapbuild

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"unicode/utf8"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

// LoadAnnotations rejects duplicate keys and trailing values before decoding.
func LoadAnnotations(path string) (model.AnnotationsFile, error) {
	f, err := os.Open(path)
	if err != nil {
		return model.AnnotationsFile{}, err
	}
	defer f.Close()
	raw, err := io.ReadAll(io.LimitReader(f, (8<<20)+1))
	if err != nil || len(raw) > 8<<20 || !utf8.Valid(raw) {
		return model.AnnotationsFile{}, fmt.Errorf("invalid annotations input")
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	if err := checkJSON(dec, 0); err != nil {
		return model.AnnotationsFile{}, err
	}
	if _, err := dec.Token(); err != io.EOF {
		return model.AnnotationsFile{}, fmt.Errorf("trailing annotation data")
	}
	// Validate the original object before Go's case-insensitive struct matching
	// can accept or overwrite schema-forbidden property names.
	var original any
	dec = json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	if err := dec.Decode(&original); err != nil {
		return model.AnnotationsFile{}, err
	}
	if err := schema.Validate("annotations-v1.schema.json", original); err != nil {
		return model.AnnotationsFile{}, err
	}
	var a model.AnnotationsFile
	dec = json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&a); err != nil {
		return a, err
	}
	return a, nil
}
func checkJSON(dec *json.Decoder, depth int) error {
	if depth > 128 {
		return fmt.Errorf("annotation nesting limit exceeded")
	}
	tok, err := dec.Token()
	if err != nil {
		return err
	}
	d, ok := tok.(json.Delim)
	if !ok {
		return nil
	}
	switch d {
	case '{':
		seen := map[string]bool{}
		for dec.More() {
			key, err := dec.Token()
			if err != nil {
				return err
			}
			s, ok := key.(string)
			if !ok || seen[s] {
				return fmt.Errorf("duplicate annotation key")
			}
			seen[s] = true
			if err := checkJSON(dec, depth+1); err != nil {
				return err
			}
		}
	case '[':
		for dec.More() {
			if err := checkJSON(dec, depth+1); err != nil {
				return err
			}
		}
	default:
		return fmt.Errorf("invalid annotation JSON")
	}
	_, err = dec.Token()
	return err
}
func validateEvidence(bundle source.Bundle, evidence []model.Locator) error {
	if len(evidence) == 0 {
		return fmt.Errorf("supplement requires evidence")
	}
	for _, e := range evidence {
		var record model.Object
		for _, r := range bundle.Records {
			if r["id"] == e.SourceID {
				record = r
				break
			}
		}
		if record == nil || record["sha256"] != e.SourceSHA256 {
			return fmt.Errorf("stale or unknown evidence source")
		}
		if (e.Pointer == nil) == (e.ProseLocator == nil) {
			return fmt.Errorf("evidence requires one locator")
		}
		if e.Pointer != nil {
			doc := bundle.Documents[e.SourceID]
			if doc == nil {
				return fmt.Errorf("pointer evidence requires parsed document")
			}
			if _, err := source.Pointer(doc, *e.Pointer); err != nil {
				return fmt.Errorf("unresolved evidence pointer")
			}
		} else if *e.ProseLocator == "" {
			return fmt.Errorf("empty prose locator")
		}
	}
	return nil
}
func validateAnnotations(bundle source.Bundle, annotations []model.Annotation, ops map[string]*model.Operation, variants map[string]bool) error {
	wrapper := model.AnnotationsFile{SchemaVersion: "1.0", Annotations: append([]model.Annotation{}, annotations...), Aliases: []model.Alias{}}
	if err := schema.Validate("annotations-v1.schema.json", wrapper); err != nil {
		return fmt.Errorf("invalid annotations: %w", err)
	}
	seen := map[string]bool{}
	for _, a := range annotations {
		if seen[a.ID] {
			return fmt.Errorf("duplicate annotation ID")
		}
		seen[a.ID] = true
		if err := validateEvidence(bundle, a.Evidence); err != nil {
			return err
		}
		switch a.Scope {
		case "family":
			found := false
			for _, o := range ops {
				if o.Family == a.TargetID {
					found = true
					break
				}
			}
			if !found {
				return fmt.Errorf("unknown annotation family")
			}
		case "operation":
			if ops[a.TargetID] == nil {
				return fmt.Errorf("unknown annotation operation")
			}
		case "variant":
			if !variants[a.TargetID] {
				return fmt.Errorf("unknown annotation variant")
			}
		}
		if a.Kind == "risk" {
			if a.Scope == "family" {
				return fmt.Errorf("risk annotation requires operation or variant scope")
			}
			switch a.Conclusion {
			case "read-only", "mutating", "destructive", "unknown":
			default:
				return fmt.Errorf("unsupported risk conclusion")
			}
		}
	}
	return nil
}
