// Package openapi indexes the pinned OpenAPI graph without expanding schemas.
package openapi

import (
	"fmt"
	"sort"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

// Index keeps original objects and manifest identities. It never re-encodes a
// parsed tree to derive a hash: provenance hashes belong to the source bytes.
type Index struct {
	bundle  source.Bundle
	records map[string]model.Object
}
type Resolved struct {
	Value  any
	Origin model.Locator
}
type Reference struct {
	Origin model.Locator
	Ref    string
	Target model.Locator
}
type Candidate struct {
	SourceID, Path, Method            string
	OperationLocator, PathItemLocator model.Locator
	Parameters                        []model.Parameter
	Security, Servers                 model.EffectiveValue
	Operation                         model.Object
}
type Analysis struct {
	Candidates                       []Candidate
	Diagnostics                      []model.Diagnostic
	References, DiscriminatorTargets []Reference
}

// NewIndex creates a local-document index with no asserted source hashes. Use
// NewBundleIndex when exact hashes and relative URL identities are available.
func NewIndex(documents map[string]model.Object) (*Index, error) {
	b := source.Bundle{Documents: documents}
	for _, id := range keys(documents) {
		b.Records = append(b.Records, model.Object{"id": id, "file": id, "url": "", "kind": "openapi"})
	}
	return NewBundleIndex(b)
}
func NewBundleIndex(bundle source.Bundle) (*Index, error) {
	i := &Index{bundle: bundle, records: map[string]model.Object{}}
	for _, r := range bundle.Records {
		id, ok := r["id"].(string)
		if !ok || id == "" {
			return nil, fmt.Errorf("source record requires an ID")
		}
		if _, exists := i.records[id]; exists {
			return nil, fmt.Errorf("duplicate source ID %q", id)
		}
		if _, ok := r["file"].(string); !ok {
			return nil, fmt.Errorf("source record requires a file identity")
		}
		if _, ok := r["url"].(string); !ok {
			return nil, fmt.Errorf("source record requires a URL identity")
		}
		i.records[id] = r
	}
	for id, d := range bundle.Documents {
		if d == nil || i.records[id] == nil {
			return nil, fmt.Errorf("document %q has no source record", id)
		}
	}
	return i, nil
}
func (i *Index) locator(id, pointer string) model.Locator {
	hash, _ := i.records[id]["sha256"].(string)
	return model.Locator{SourceID: id, SourceSHA256: hash, Pointer: &pointer}
}
func object(v any) (model.Object, bool) {
	switch o := v.(type) {
	case model.Object:
		return o, true
	case map[string]any:
		return model.Object(o), true
	}
	return nil, false
}
func keys[M ~map[string]V, V any](m M) []string {
	k := make([]string, 0, len(m))
	for s := range m {
		k = append(k, s)
	}
	sort.Strings(k)
	return k
}
