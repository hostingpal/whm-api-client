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
				op.OptionalPayload || op.Variant != "") {
				problems = append(problems, id+": excluded operations take no other settings")
			}
			if op.Void && op.Unwrap != "" {
				problems = append(problems, id+": void and unwrap are exclusive")
			}
			if op.Void && op.OptionalPayload {
				problems = append(problems, id+": void and optional_payload are exclusive")
			}
			problems = append(problems, missingEvidence(id, op)...)
			for wire, param := range op.Params {
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
// the wire request, parameter requiredness, or a parameter declared not sensitive) made without
// operation-level evidence.
func missingEvidence(id string, op Operation) []string {
	if len(op.Evidence) > 0 {
		return nil
	}
	var settings []string
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
		if op.Params[wire].Required != nil {
			settings = append(settings, "required on parameter "+wire)
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
