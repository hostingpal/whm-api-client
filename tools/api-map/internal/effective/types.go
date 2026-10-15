// Package effective applies the overlay to the canonical API map and produces
// the generated client's effective contract: groups, methods, parameters and
// response type specs matching the PHP engine's spec format.
package effective

// TypeSpec mirrors the PHP engine's type spec (see src/Response/ShapeValidator.php).
type TypeSpec struct {
	Type     string
	Nullable bool
	Accept   []string
	Preserve bool
	Items    *TypeSpec
	Values   *TypeSpec
	Fields   []Field
	Required []string
	AnyOf    []TypeSpec
}

// Field is one declared object field, kept in name order for determinism.
type Field struct {
	Name string
	Spec TypeSpec
}

// Param is one generated method argument.
type Param struct {
	Wire        string
	PHP         string
	Encoding    string // scalar, bool, repeat or numbered
	PHPType     string // native type without nullability: string, int, int|float, bool, array, string|array, string|int
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
	Notes       []string
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
