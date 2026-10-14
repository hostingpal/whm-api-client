package overlay

import (
	"path/filepath"
	"sort"
	"strings"
)

// Validate reports structural problems the JSON schema cannot express. Problems
// that need the API map (unknown operations, group membership, undocumented
// parameters) are reported by the effective builder.
func Validate(set Set) []string {
	var problems []string
	seen := map[string]string{}
	for _, file := range set.Files {
		name := filepath.Base(filepath.Dir(file.Path)) + "/" + filepath.Base(file.Path)
		inUAPIDirectory := filepath.Base(filepath.Dir(file.Path)) == "uapi"
		if inUAPIDirectory != (file.Family == "uapi") {
			problems = append(problems, name+": family does not match its directory")
		}
		if file.Family == "whm1" && file.Accessor == "" {
			problems = append(problems, name+": WHM groups need an accessor")
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
			if op.Exclude != nil && (op.Name != "" || len(op.Params) > 0 || len(op.Response) > 0 || op.Unwrap != "") {
				problems = append(problems, id+": excluded operations take no other settings")
			}
			if op.Void && op.Unwrap != "" {
				problems = append(problems, id+": void and unwrap are exclusive")
			}
		}
	}
	sort.Strings(problems)
	return problems
}
