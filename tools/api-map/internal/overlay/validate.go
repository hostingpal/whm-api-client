package overlay

import (
	"fmt"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

// DisplayName returns the file's name relative to the overlay directory, such
// as "whm/Accounts.json", for problem messages.
func (f File) DisplayName() string {
	return filepath.Base(filepath.Dir(f.Path)) + "/" + filepath.Base(f.Path)
}

// Validate reports structural problems the JSON schema cannot express. Problems
// that need the API map (unknown operations, group membership, undocumented
// parameters) are reported by the effective builder.
func Validate(set Set) []string {
	var problems []string
	seen := map[string]string{}
	groups := map[string]string{}
	for _, file := range set.Files {
		name := file.DisplayName()
		inUAPIDirectory := filepath.Base(filepath.Dir(file.Path)) == "uapi"
		if inUAPIDirectory != (file.Family == "uapi") {
			problems = append(problems, name+": family does not match its directory")
		}
		if file.Family == "whm1" && file.Accessor == "" {
			problems = append(problems, name+": WHM groups need an accessor")
		}
		groupKey := file.Family + "|" + file.Group
		if other, duplicate := groups[groupKey]; duplicate {
			problems = append(problems, fmt.Sprintf("group %q appears in %s and %s", file.Group, other, name))
		} else {
			groups[groupKey] = name
		}
		ids := make([]string, 0, len(file.Operations))
		for id := range file.Operations {
			ids = append(ids, id)
		}
		sort.Strings(ids)
		for _, id := range ids {
			if !strings.HasPrefix(id, file.Family+":") {
				problems = append(problems, name+": "+id+" is not a "+file.Family+" operation")
			}
			if other, duplicate := seen[id]; duplicate {
				problems = append(problems, id+" appears in "+other+" and "+name)
			}
			seen[id] = name
			op := file.Operations[id]
			if op.Exclude != nil && (op.Name != "" || len(op.Params) > 0 || len(op.Response) > 0 || op.Unwrap != "" ||
				len(op.AdditionalMethods) > 0 || op.Function != "" || op.OptionalPayload || op.Variant != "" || op.Body != "" || len(op.BodyFields) > 0) {
				problems = append(problems, id+": excluded operations take no other settings")
			}
			if op.Void && op.Unwrap != "" {
				problems = append(problems, id+": void and unwrap are exclusive")
			}
			if op.Void && op.OptionalPayload {
				problems = append(problems, id+": void and optional_payload are exclusive")
			}
			if op.Body != "" && (op.Body != "multipart" || file.Family != "uapi") {
				problems = append(problems, id+": reviewed multipart bodies require UAPI")
			}
			if len(op.BodyFields) > 0 && op.Body != "multipart" {
				problems = append(problems, id+": body_fields requires multipart")
			}
			if op.Function != "" && (file.Family != "whm1" || !qualifiedFunction.MatchString(op.Function)) {
				problems = append(problems, id+": function requires one WHM namespace and identifier leaf")
			}
			problems = append(problems, missingEvidence(id, op)...)
			for wire, param := range op.Params {
				if param.Collection != nil {
					if err := ValidateCollection(param.Collection); err != nil {
						problems = append(problems, id+": parameter "+wire+": collection "+err.Error())
					}
					if param.Omit || param.Encoding != "" || param.Type != "" || param.Wire != "" || param.OmitEmpty != nil || param.RejectEmpty != nil {
						problems = append(problems, id+": parameter "+wire+": collection cannot combine with omit, encoding, type, wire, omit_empty or reject_empty")
					}
				}
				if param.RejectEmpty != nil {
					if !*param.RejectEmpty || param.Omit || param.OmitEmpty != nil {
						problems = append(problems, id+": reject_empty must be true and cannot have omit or omit_empty")
					}
				}
				if param.OmitEmpty != nil {
					if !*param.OmitEmpty {
						problems = append(problems, id+": omit_empty must be true")
					}
					if param.Omit {
						problems = append(problems, id+": omitted parameter "+wire+" cannot have omit_empty")
					}
				}
				if param.Omit && param.Wire != "" {
					problems = append(problems, id+": omitted parameter "+wire+" cannot have wire corrections")
				}
				if param.Omit && (param.Type != "" || param.Description != "") {
					problems = append(problems, id+": omitted parameter "+wire+" cannot have type or description corrections")
				}
				if IsSuffixNumbered(param.Encoding) && !suffixPattern.MatchString(wire) {
					problems = append(problems, id+": suffix-numbered parameter "+wire+" requires an identifier followed by one terminal *")
				}
			}
			if observedWithoutLive(op) {
				problems = append(problems, id+": an observed note needs live evidence")
			}
		}
	}
	sort.Strings(problems)
	return problems
}

// observedWithoutLive reports an "observed" note on an operation whose
// evidence holds no live observation.
func observedWithoutLive(op Operation) bool {
	observed := false
	for _, note := range op.Notes {
		observed = observed || note.Kind == "observed"
	}
	if !observed {
		return false
	}
	for _, evidence := range op.Evidence {
		if evidence.Kind == "live" {
			return false
		}
	}
	return true
}

// missingEvidence reports behaviour-changing settings (variant, request method, outcome
// check, void result, optional payload, a parameter encoding, which changes
// the wire request, parameter requiredness, scalar type/description corrections,
// suppressed enum documentation, or a parameter declared not sensitive) made
// without operation-level evidence.
func missingEvidence(id string, op Operation) []string {
	if len(op.Evidence) > 0 {
		return nil
	}
	var settings []string
	if op.Function != "" {
		settings = append(settings, "function")
	}
	if op.Body != "" {
		settings = append(settings, "body")
	}
	if len(op.BodyFields) > 0 {
		settings = append(settings, "body_fields")
	}
	if op.Variant != "" {
		settings = append(settings, "variant")
	}
	if op.Method != "" {
		settings = append(settings, "method")
	}
	if op.Outcome != "" {
		settings = append(settings, "outcome")
	}
	if op.Void {
		settings = append(settings, "void")
	}
	if op.OptionalPayload {
		settings = append(settings, "optional_payload")
	}
	wires := make([]string, 0, len(op.Params))
	for wire := range op.Params {
		wires = append(wires, wire)
	}
	sort.Strings(wires)
	for _, wire := range wires {
		if op.Params[wire].Wire != "" {
			settings = append(settings, "wire on parameter "+wire)
		}
		if op.Params[wire].Type != "" {
			settings = append(settings, "type on parameter "+wire)
		}
		if op.Params[wire].Description != "" {
			settings = append(settings, "description on parameter "+wire)
		}
		if enumDoc := op.Params[wire].EnumDoc; enumDoc != nil && !*enumDoc {
			settings = append(settings, "enum_doc false on parameter "+wire)
		}
		if op.Params[wire].Required != nil {
			settings = append(settings, "required on parameter "+wire)
		}
		if op.Params[wire].RejectEmpty != nil {
			settings = append(settings, "reject_empty on parameter "+wire)
		}
		if op.Params[wire].OmitEmpty != nil {
			settings = append(settings, "omit_empty on parameter "+wire)
		}
		if op.Params[wire].Collection != nil {
			settings = append(settings, "collection on parameter "+wire)
		}
		if op.Params[wire].Encoding != "" {
			settings = append(settings, "encoding on parameter "+wire)
		}
		if sensitive := op.Params[wire].Sensitive; sensitive != nil && !*sensitive {
			settings = append(settings, "sensitive false on parameter "+wire)
		}
	}
	problems := make([]string, len(settings))
	for i, setting := range settings {
		problems[i] = id + ": " + setting + " needs evidence"
	}
	return problems
}

var suffixPattern = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*\*$`)

// IsSuffixNumbered identifies reviewed one-based terminal-wildcard encodings.
func IsSuffixNumbered(encoding string) bool {
	return encoding == "suffix-numbered" || encoding == "suffix-numbered-scalar"
}

// IsCorrectedWireName limits wire corrections to simple identifiers. Routing
// fields (api.version and cpanel.*), indexed names and wildcard families are excluded.
func IsCorrectedWireName(name string) bool {
	return correctedWireName.MatchString(name)
}

var mapPrefix = regexp.MustCompile(`^([A-Za-z_][A-Za-z0-9_-]*)?$`)

var correctedWireName = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)

// qualifiedFunction allows exactly Namespace/function, with no path syntax.
var qualifiedFunction = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*/[A-Za-z_][A-Za-z0-9_]*$`)

// ValidateCollection checks explicit facts even for callers constructing overlays
// directly. JSON loading additionally rejects unknown keys and wrong JSON types.
func ValidateCollection(c *Collection) error {
	if c == nil || (c.Kind != "indexed" && c.Kind != "repeat" && c.Kind != "map") ||
		(c.ItemType != "string" && c.ItemType != "int" && c.ItemType != "bool" && c.ItemType != "string|int") ||
		c.AllowScalar == nil || (c.Empty != "reject" && (c.Kind != "map" || c.Empty != "allow")) {
		return fmt.Errorf("requires kind, strict item_type, allow_scalar and an explicit supported empty policy")
	}
	if c.Kind == "map" {
		if *c.AllowScalar || c.Prefix == nil || !mapPrefix.MatchString(*c.Prefix) || c.Separator != nil || c.Start != nil {
			return fmt.Errorf("map requires literal prefix, no scalar fallback or index rules")
		}
	} else if c.Kind == "repeat" {
		if c.Prefix != nil || c.Separator != nil || c.Start != nil {
			return fmt.Errorf("repeat cannot declare index rules")
		}
	} else if c.Prefix == nil || !IsCorrectedWireName(*c.Prefix) || c.Separator == nil ||
		(*c.Separator != "" && *c.Separator != "-") || c.Start == nil || *c.Start < 0 || *c.Start > 2147483647 {
		return fmt.Errorf("indexed requires identifier prefix, empty/hyphen separator and nonnegative 32-bit start")
	}
	return nil
}
