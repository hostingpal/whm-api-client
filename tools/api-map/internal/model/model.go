// Package model defines the versioned canonical API map interchange types.
package model

// Object retains source fields; JSON numbers are represented by json.Number.
type Object map[string]any

type Locator struct {
	SourceID     string  `json:"source_id"`
	SourceSHA256 string  `json:"source_sha256"`
	Pointer      *string `json:"pointer,omitempty"`
	ProseLocator *string `json:"prose_locator,omitempty"`
}

type Diagnostic struct {
	Severity    string  `json:"severity"`
	Code        string  `json:"code"`
	Message     string  `json:"message"`
	Locator     Locator `json:"locator"`
	CanonicalID string  `json:"canonical_id,omitempty"`
}

type Map struct {
	SchemaVersion string            `json:"schema_version"`
	Sources       []Object          `json:"sources"`
	Documents     map[string]Object `json:"documents"`
	Operations    []Operation       `json:"operations"`
	Aliases       []Alias           `json:"aliases"`
	Annotations   []Annotation      `json:"annotations"`
	Diagnostics   []Diagnostic      `json:"diagnostics"`
}

type Operation struct {
	ID         string    `json:"id"`
	Family     string    `json:"family"`
	Module     *string   `json:"module"`
	Function   string    `json:"function"`
	Path       string    `json:"path"`
	Provenance []Locator `json:"provenance"`
	Variants   []Variant `json:"variants"`
}

type Variant struct {
	ID                  string         `json:"id"`
	Method              string         `json:"method"`
	UpstreamOperationID *string        `json:"upstream_operation_id"`
	OperationLocator    Locator        `json:"operation_locator"`
	PathItemLocator     Locator        `json:"path_item_locator"`
	Parameters          []Parameter    `json:"parameters"`
	Security            EffectiveValue `json:"security"`
	Servers             EffectiveValue `json:"servers"`
	Risk                Risk           `json:"risk"`
}

// EffectiveValue keeps absence distinct from an explicit null or empty value.
// An absent value has Present false, Level "none", and a nil Locator/Value.
type EffectiveValue struct {
	Present bool     `json:"present"`
	Value   any      `json:"value"`
	Locator *Locator `json:"locator"`
	Level   string   `json:"level"`
}

type Parameter struct {
	Name       string  `json:"name"`
	In         string  `json:"in"`
	Definition Object  `json:"definition"`
	Locator    Locator `json:"locator"`
}

type Risk struct {
	Classification       string    `json:"classification"`
	DestructivePotential string    `json:"destructive_potential"`
	Evidence             []Locator `json:"evidence"`
}

type Alias struct {
	AliasID  string    `json:"alias_id"`
	TargetID string    `json:"target_id"`
	Reason   string    `json:"reason"`
	Evidence []Locator `json:"evidence"`
}

type Annotation struct {
	ID         string    `json:"id"`
	Scope      string    `json:"scope"`
	TargetID   string    `json:"target_id"`
	Kind       string    `json:"kind"`
	Conclusion string    `json:"conclusion"`
	Rationale  string    `json:"rationale"`
	Evidence   []Locator `json:"evidence"`
}

type AnnotationsFile struct {
	SchemaVersion string       `json:"schema_version"`
	Annotations   []Annotation `json:"annotations"`
	Aliases       []Alias      `json:"aliases"`
}
