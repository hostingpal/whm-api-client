// Package effective applies the overlay to the canonical API map and produces
// the generated client's effective contract: groups, methods, parameters and
// response type specs matching the PHP engine's spec format.
package effective

// TypeSpec mirrors the PHP engine's type spec (see src/Response/ShapeValidator.php).
type TypeSpec struct {
	Literal  *string
	Type     string
	Nullable bool
	Accept   []string
	Preserve bool
	Items    *TypeSpec
	Values   *TypeSpec
	Fields   []Field
	Required []string
	AnyOf    []TypeSpec
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

// Param is one generated method argument.
type Param struct {
	Wire        string
	PHP         string
	Encoding    string // scalar, bool, repeat, numbered or a suffix-numbered encoding
	PHPType     string // native type without nullability; list encodings include array
	DocType     string // PHPStan type without nullability
	Description string
	Required    bool
	Sensitive   bool
}

// Operation is one generated method and its spec constant.
type Operation struct {
	ID          string
	VariantID   string
	Family      string
	Module      string
	Function    string
	Method      string
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
