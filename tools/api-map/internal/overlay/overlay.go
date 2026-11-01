// Package overlay loads the evidence-bound naming decisions and response
// corrections that turn the canonical API map into the generated client's
// effective contract. Group files live in docs/api/overlay/{uapi,whm}/*.json.
package overlay

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"unicode/utf8"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

// Config selects which operations are generated.
type Config struct {
	SchemaVersion string `json:"schema_version"`
	// Scope is "listed" (only operations named in group files) or "all".
	Scope string `json:"scope"`
}

// File is one group's overlay.
type File struct {
	SchemaVersion string               `json:"schema_version"`
	Family        string               `json:"family"`
	Group         string               `json:"group"`
	Accessor      string               `json:"accessor,omitempty"`
	Class         string               `json:"class,omitempty"`
	Description   string               `json:"description,omitempty"`
	Operations    map[string]Operation `json:"operations"`
	Path          string               `json:"-"`
}

// AdditionalMethod changes only the input mapping of a canonical operation.
// Source mappings reuse canonical parameters; record mappings combine source fields.
type AdditionalMethod struct {
	Name   string        `json:"name"`
	Inputs []MethodInput `json:"inputs"`
}

// JSONField selects an embedded source schema, or the evidence-bound fixed
// object of JSON scalar values for a concrete string format:json source.
type JSONField struct {
	Alias     string `json:"alias"`
	ScalarMap *bool  `json:"scalar_map,omitempty"`
}
type MethodInput struct {
	JSON    *JSONField `json:"json,omitempty"`
	PHP     string     `json:"php"`
	Source  string     `json:"source,omitempty"`
	Records *Records   `json:"records,omitempty"`
}
type Records struct {
	FirstUnsuffixed *bool         `json:"first_unsuffixed,omitempty"`
	Alias           string        `json:"alias"`
	Start           *int          `json:"start"`
	Separator       *string       `json:"separator"`
	Sensitive       *bool         `json:"sensitive"`
	Fields          []RecordField `json:"fields"`
}
type RecordField struct {
	Required *bool  `json:"required,omitempty"`
	Source   string `json:"source"`
	PHP      string `json:"php"`
	Prefix   string `json:"prefix"`
}

// Operation holds per-operation decisions; zero values keep generator defaults.
// MultipartUploads selects one required, source-bound binary list family.
// Fixed documented parameters retain their query location.
type MultipartUploads struct {
	PHP               string `json:"php"`
	Source            string `json:"source"`
	Prefix            string `json:"prefix"`
	Start             *int   `json:"start"`
	Routes            string `json:"routes"`
	FilenameForbidden string `json:"filename_forbidden"`
}

// MultipartUpload selects the reviewed WHM required text and scalar Upload body.
type MultipartUpload struct {
	PHP    string `json:"php"`
	Source string `json:"source"`
	Text   string `json:"text"`
}

// MultipartUploadFamily selects an evidenced scalar or nonempty one-based Upload list.
type MultipartUploadFamily struct {
	PHP    string `json:"php"`
	Source string `json:"source"`
	Routes string `json:"routes"`
}

type Operation struct {
	ResponseFields        []string               `json:"response_fields,omitempty"`
	MultipartUploadFamily *MultipartUploadFamily `json:"multipart_upload_family,omitempty"`
	MultipartUpload       *MultipartUpload       `json:"multipart_upload,omitempty"`
	MultipartUploads      *MultipartUploads      `json:"multipart_uploads,omitempty"`
	AdditionalMethods     []AdditionalMethod     `json:"additional_methods,omitempty"`
	Name                  string                 `json:"name,omitempty"`
	// Function qualifies a bare WHM function with one source-evidenced namespace.
	// It preserves the canonical identity and leaf; UAPI corrections are forbidden.
	Function string `json:"function,omitempty"`
	// Variant selects the documented variant (by HTTP method) of an operation
	// the map documents with several variants.
	Variant    string            `json:"variant,omitempty"`
	Method     string            `json:"method,omitempty"`
	JSONBody   *JSONBody         `json:"json_body,omitempty"`
	Body       string            `json:"body,omitempty"`
	BodyFields map[string]string `json:"body_fields,omitempty"`
	Outcome    string            `json:"outcome,omitempty"`
	Unwrap     string            `json:"unwrap,omitempty"`
	Void       bool              `json:"void,omitempty"`
	// OptionalPayload makes an omitted or null data member a valid response
	// that the generated method returns as null.
	OptionalPayload bool                  `json:"optional_payload,omitempty"`
	Order           []string              `json:"order,omitempty"`
	Params          map[string]Param      `json:"params,omitempty"`
	Response        map[string]Correction `json:"response,omitempty"`
	Notes           []Note                `json:"notes,omitempty"`
	Exclude         *Exclusion            `json:"exclude,omitempty"`
	Probe           json.RawMessage       `json:"probe,omitempty"`
	// Evidence backs behaviour-changing settings: function, variant, method, outcome,
	// void, optional_payload, a parameter encoding and "sensitive": false on a
	// parameter, parameter requiredness, scalar type/description corrections or
	// suppressed enum documentation.
	Evidence []Evidence `json:"evidence,omitempty"`
}

// JSONBody selects one whole application/json body; source schemas remain immutable.
// Corrections address schema nodes by JSON pointer relative to the root schema.
type JSONBody struct {
	PHP         string                     `json:"php"`
	Alias       string                     `json:"alias"`
	Routes      string                     `json:"routes"`
	Required    *bool                      `json:"required,omitempty"`
	Corrections map[string]InputCorrection `json:"corrections,omitempty"`
}
type InputCorrection struct {
	Required []string   `json:"required,omitempty"`
	Nonempty *bool      `json:"nonempty,omitempty"`
	Evidence []Evidence `json:"evidence"`
}

// Note is one line appended to the generated docblock. Kind separates facts
// stated in the pinned documentation ("documented") from runtime behaviour
// seen on a server ("observed", which needs live evidence) and deliberate client
// decisions ("policy", which do not claim upstream or live evidence).
type Note struct {
	Kind string `json:"kind"`
	Text string `json:"text"`
}

// NoteKinds maps each note kind to the label that starts its docblock line.
var NoteKinds = map[string]string{"documented": "Documented", "observed": "Observed", "policy": "Policy"}

// Collection declares strict ordinary list/map input independently of legacy markers.
// Indexed names are literal Prefix + Separator + (Start + PHP position).
// Map names are literal Prefix + caller string key; map scalars are forbidden.
// Empty lists always reject; maps explicitly allow/reject empty input.
// Optional top-level null still omits the parameter.
type Collection struct {
	Kind        string  `json:"kind"`
	ItemType    string  `json:"item_type"`
	AllowScalar *bool   `json:"allow_scalar"`
	Empty       string  `json:"empty"`
	Prefix      *string `json:"prefix,omitempty"`
	Separator   *string `json:"separator,omitempty"`
	Start       *int    `json:"start,omitempty"`
	Delimiter   *string `json:"delimiter,omitempty"`
}

// Param renames, re-encodes or omits one documented parameter, keyed by wire
// name. Omitted parameters (aliases, wildcard patterns such as MXCHECK-*) stay
// reachable through call() and are left out of the generated signature. EnumDoc
// false suppresses only the literal enum PHPDoc, without changing native types or
// encoding, and needs evidence. An absent or true value preserves enum PHPDoc.
// Type permits an exact-schema-evidenced string correction for a concrete
// enum-only all-string scalar without type/format or structural alternatives,
// preserving literal enum documentation and scalar encoding; or reviewed string|int widening of scalar string/integer inputs,
// string|int|float widening of scalar number inputs, or a true presence guard
// for concrete nonnullable scalar integer inputs with exact source enum [1]
// and unchanged requiredness (optional ?true or required true).
// Description replaces the parameter text without truncating later sentences.
// Wire corrects a simple scalar parameter name on the wire without changing its
// public PHP name or source-based order. All corrections require operation evidence
// and cannot target omitted inputs.
type Param struct {
	Collection  *Collection `json:"collection,omitempty"`
	Wire        string      `json:"wire,omitempty"`
	Type        string      `json:"type,omitempty"`
	Description string      `json:"description,omitempty"`
	PHP         string      `json:"php,omitempty"`
	Required    *bool       `json:"required,omitempty"`
	EnumDoc     *bool       `json:"enum_doc,omitempty"`
	Encoding    string      `json:"encoding,omitempty"`
	Sensitive   *bool       `json:"sensitive,omitempty"`
	Omit        bool        `json:"omit,omitempty"`
	// OmitEmpty explicitly omits [] on optional repeated string-list inputs.
	OmitEmpty *bool `json:"omit_empty,omitempty"`
	// RejectEmpty requires an explicit nonblank, nonzero target.
	RejectEmpty *bool `json:"reject_empty,omitempty"`
}

// Correction changes the documented response type at one payload path.
type Correction struct {
	AcceptEmptyArray *bool      `json:"accept_empty_array,omitempty"`
	Type             string     `json:"type,omitempty"`
	Accept           []string   `json:"accept,omitempty"`
	Nullable         *bool      `json:"nullable,omitempty"`
	Preserve         bool       `json:"preserve,omitempty"`
	Required         *bool      `json:"required,omitempty"`
	Add              *Spec      `json:"add,omitempty"`
	Replace          *Spec      `json:"replace,omitempty"`
	Evidence         []Evidence `json:"evidence"`
}

// Spec is the JSON form of a type spec, used to declare undocumented fields.
type Spec struct {
	AcceptEmptyArray *bool           `json:"accept_empty_array,omitempty"`
	Literal          *string         `json:"literal,omitempty"`
	Type             string          `json:"type"`
	Nullable         bool            `json:"nullable,omitempty"`
	Accept           []string        `json:"accept,omitempty"`
	Preserve         bool            `json:"preserve,omitempty"`
	Items            *Spec           `json:"items,omitempty"`
	Values           *Spec           `json:"values,omitempty"`
	Fields           map[string]Spec `json:"fields,omitempty"`
	Required         []string        `json:"required,omitempty"`
	AnyOf            []Spec          `json:"anyOf,omitempty"`
}

// Exclusion removes an operation from generation for a documented reason.
type Exclusion struct {
	Reason   string     `json:"reason"`
	Evidence []Evidence `json:"evidence"`
}

// Evidence binds a decision to documentation or a live observation.
type Evidence struct {
	// Only collection overrides require structured parameter provenance. Existing
	// evidence keeps its original contract and needs no provenance migration.
	SourceSHA256 string `json:"source_sha256,omitempty"`
	Pointer      string `json:"pointer,omitempty"`
	Kind         string `json:"kind"`
	Version      string `json:"version"`
	Date         string `json:"date"`
	Note         string `json:"note"`
	Fingerprint  string `json:"fingerprint,omitempty"`
}

// Set is a loaded overlay directory.
type Set struct {
	Config Config
	Files  []File
}

// Load reads config.json and every group file in deterministic order. Entries
// inside dir (config.json, the family folders and the group files) must be
// regular files and folders, never symbolic links, so an overlay cannot pull
// in files from outside its directory.
func Load(dir string) (Set, error) {
	var set Set
	for _, name := range []string{"config.json", "uapi", "whm"} {
		if err := rejectLink(filepath.Join(dir, name), name); err != nil {
			return Set{}, err
		}
	}
	raw, err := readLimited(filepath.Join(dir, "config.json"))
	if err != nil {
		return Set{}, fmt.Errorf("overlay config: %w", err)
	}
	if err := rejectDuplicateKeys(raw); err != nil {
		return Set{}, fmt.Errorf("overlay config: %w", err)
	}
	// Exact-case key check: Go's struct decoding matches keys case-insensitively,
	// so a mixed-case key such as "Scope" could otherwise override "scope".
	if err := requireConfigKeys(raw); err != nil {
		return Set{}, fmt.Errorf("overlay config: %w", err)
	}
	if err := strictDecode(raw, &set.Config); err != nil {
		return Set{}, fmt.Errorf("overlay config: %w", err)
	}
	if set.Config.SchemaVersion != "1.0" || (set.Config.Scope != "listed" && set.Config.Scope != "all") {
		return Set{}, fmt.Errorf("overlay config: schema_version must be 1.0 and scope listed or all")
	}
	for _, family := range []string{"uapi", "whm"} {
		paths, err := filepath.Glob(filepath.Join(dir, family, "*.json"))
		if err != nil {
			return Set{}, err
		}
		sort.Strings(paths)
		for _, path := range paths {
			if err := rejectLink(path, family+"/"+filepath.Base(path)); err != nil {
				return Set{}, err
			}
			file, err := loadFile(path)
			if err != nil {
				return Set{}, fmt.Errorf("%s/%s: %w", family, filepath.Base(path), err)
			}
			set.Files = append(set.Files, file)
		}
	}
	return set, nil
}

// rejectLink fails when path is a symbolic link; a missing path is left to the
// caller (a missing family folder simply has no group files).
func rejectLink(path, name string) error {
	info, err := os.Lstat(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	if info.Mode()&os.ModeSymlink != 0 {
		return fmt.Errorf("overlay entry %s is a symbolic link", name)
	}
	return nil
}

// requireConfigKeys accepts only the exact keys schema_version and scope, both
// with JSON string values.
func requireConfigKeys(raw []byte) error {
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	var object map[string]any
	if err := decoder.Decode(&object); err != nil {
		return err
	}
	if object == nil {
		return fmt.Errorf("config must be a JSON object")
	}
	for _, key := range []string{"schema_version", "scope"} {
		value, ok := object[key]
		if !ok {
			return fmt.Errorf("missing %s", key)
		}
		if _, isString := value.(string); !isString {
			return fmt.Errorf("%s must be a string", key)
		}
	}
	for key := range object {
		if key != "schema_version" && key != "scope" {
			return fmt.Errorf("unknown config key %q", key)
		}
	}
	return nil
}

func loadFile(path string) (File, error) {
	raw, err := readLimited(path)
	if err != nil {
		return File{}, err
	}
	if err := rejectDuplicateKeys(raw); err != nil {
		return File{}, err
	}
	// Validate the original object before Go's case-insensitive struct
	// matching can accept schema-forbidden property names.
	var original any
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&original); err != nil {
		return File{}, err
	}
	if err := schema.Validate("overlay-v1.schema.json", original); err != nil {
		return File{}, err
	}
	var file File
	if err := strictDecode(raw, &file); err != nil {
		return File{}, err
	}
	file.Path = path
	return file, nil
}

func readLimited(path string) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	raw, err := io.ReadAll(io.LimitReader(f, (8<<20)+1))
	if err != nil || len(raw) > 8<<20 || !utf8.Valid(raw) {
		return nil, fmt.Errorf("invalid overlay input")
	}
	return raw, nil
}

func strictDecode(raw []byte, target any) error {
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		return err
	}
	if decoder.More() {
		return fmt.Errorf("trailing overlay data")
	}
	return nil
}

func rejectDuplicateKeys(raw []byte) error {
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := walkTokens(decoder); err != nil {
		return err
	}
	if _, err := decoder.Token(); err != io.EOF {
		return fmt.Errorf("trailing overlay data")
	}
	return nil
}

func walkTokens(decoder *json.Decoder) error {
	token, err := decoder.Token()
	if err != nil {
		return err
	}
	delimiter, ok := token.(json.Delim)
	if !ok {
		return nil
	}
	switch delimiter {
	case '{':
		seen := map[string]bool{}
		for decoder.More() {
			key, err := decoder.Token()
			if err != nil {
				return err
			}
			name, _ := key.(string)
			if seen[name] {
				return fmt.Errorf("duplicate key %q", name)
			}
			seen[name] = true
			if err := walkTokens(decoder); err != nil {
				return err
			}
		}
	case '[':
		for decoder.More() {
			if err := walkTokens(decoder); err != nil {
				return err
			}
		}
	}
	_, err = decoder.Token()
	return err
}
