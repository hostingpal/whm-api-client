package effective

import (
	"os"
	"regexp"
	"strings"
	"testing"
)

var phpMethod = regexp.MustCompile(`(?m)^\s*(?:(?:final|public|protected|private|static|abstract)\s+)*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(`)

// TestReservedNamesCoverHandleMethods keeps the reserved accessor names in step
// with the hand-written handles: a generated group accessor is a trait method
// of WhmClient or UapiClient and must not collide with any method they declare
// or inherit (final protected AbstractApiClient methods cannot be redeclared).
func TestReservedNamesCoverHandleMethods(t *testing.T) {
	for _, path := range []string{"src/Api/AbstractApiClient.php", "src/WhmClient.php", "src/UapiClient.php"} {
		raw, err := os.ReadFile("../../../../" + path)
		if err != nil {
			t.Fatal(err)
		}
		matches := phpMethod.FindAllStringSubmatch(string(raw), -1)
		if len(matches) == 0 {
			t.Fatalf("no methods found in %s", path)
		}
		for _, match := range matches {
			name := match[1]
			if strings.HasPrefix(name, "__") {
				continue // magic methods are never valid accessor names
			}
			if !reservedLower[strings.ToLower(name)] {
				t.Errorf("%s declares %s, which is not a reserved name", path, name)
			}
		}
	}
}
