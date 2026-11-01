// Package effective applies the overlay to the canonical API map and produces
// the generated client's effective contract: groups, methods, parameters and
// response type specs matching the PHP engine's spec format.
package effective

import "github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"

// TypeSpec mirrors the PHP engine's type spec (see src/Response/ShapeValidator.php).
type TypeSpec struct {
	AcceptEmptyArray *bool `json:"accept_empty_array,omitempty"`
	Literal          *string
	Type             string
	Nullable         bool
	Accept           []string
	Preserve         bool
	Items            *TypeSpec
	Values           *TypeSpec // map values, or opt-in undeclared object fields
	Fields           []Field
	Required         []string
	AnyOf            []TypeSpec
	// Issue names a documented construct the engine cannot represent at this
	// node. The builder reports it as a problem unless an overlay replace
	// covers the node; it is never rendered.
	Issue string
}

// Field is one declared object field, kept in name order for determinism.
type Field struct {
	Name string
	Spec TypeSpec
}

// Note is one overlay note line: Label ("Documented" or "Observed") says
// whether the text restates the pinned documentation or a live observation.
type Note struct {
	Label string
	Text  string
}

// RecordSpec is an ordered set of source-bound fields sharing one row index.
type RecordSpec struct {
	FirstUnsuffixed bool `json:",omitempty"`
	Alias           string
	Start           int
	Separator       string
	Fields          []RecordField
}

// RecordField preserves a source scalar type and requiredness under its reviewed prefix.
type RecordField struct {
	PHP      string
	Prefix   string
	Type     string
	Required bool
}

// Param is one generated method argument.
type Param struct {
	// Input is strict request data; Encoding=json selects one field, otherwise JSONBody owns the whole body.
	Input       *InputSpec          `json:",omitempty"`
	Records     *RecordSpec         `json:",omitempty"`
	Collection  *overlay.Collection `json:",omitempty"`
	Wire        string
	PHP         string
	Encoding    string // scalar, bool, json field, legacy list encodings or explicit collection
	PHPType     string // native type without nullability; list encodings include array
	DocType     string // PHPStan type without nullability
	Description string
	Required    bool
	Sensitive   bool
	RejectEmpty bool `json:",omitempty"` // explicit nonblank, nonzero target policy
	OmitEmpty   bool `json:",omitempty"` // explicit omission of [] for optional repeat string-list inputs
}

// Operation owns one canonical response/route contract and its public input mappings.
type Operation struct {
	ResponseFields        []string
	MultipartUploadFamily *overlay.MultipartUploadFamily `json:",omitempty"`
	MultipartUpload       *overlay.MultipartUpload       `json:",omitempty"`
	MultipartUploads      *overlay.MultipartUploads      `json:",omitempty"`
	JSONBody              *JSONBody                      `json:",omitempty"`
	AdditionalMethods     []Method                       `json:",omitempty"`

	ID          string
	VariantID   string
	Family      string
	Module      string
	Function    string
	Method      string
	Body        string
	Outcome     string
	PHPName     string
	Constant    string
	Alias       string
	Summary     string
	Description string
	Subcategory string
	SeeURL      string
	Notes       []Note
	Params      []Param
	Returns     *TypeSpec
	Unwrap      string
	// OptionalPayload makes an omitted or null data member a null result.
	OptionalPayload bool
}

// Method owns only input mapping and public symbols, never a second response contract.
type Method struct {
	PHPName  string
	Constant string
	Params   []Param
}

// Methods exposes canonical and alternate public methods for rendering and symbol checks.
func (op Operation) Methods() []Operation {
	result := []Operation{op}
	for _, m := range op.AdditionalMethods {
		alias := op
		alias.AdditionalMethods = nil
		alias.PHPName, alias.Constant, alias.Params = m.PHPName, m.Constant, m.Params
		result = append(result, alias)
	}
	return result
}

// Group is one generated group class.
type Group struct {
	Family      string
	Name        string
	Accessor    string
	Class       string
	Description string
	Operations  []Operation
}

// Exclusion records an operation left out of generation.
type Exclusion struct {
	ID     string
	Reason string
}

// Result is the effective contract plus every problem found while building it.
type Result struct {
	Groups   []Group
	Excluded []Exclusion
	Problems []string
}
