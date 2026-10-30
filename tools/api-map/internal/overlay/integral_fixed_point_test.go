package overlay

import (
	"strings"
	"testing"
)

func TestLoadIntegralFixedPointStringCorrections(t *testing.T) {
	for _, replacement := range []string{
		`"accept": ["numeric-string", "integral-fixed-point-string"],`,
		`"replace": {"type": "int", "accept": ["numeric-string", "integral-fixed-point-string"]},`,
	} {
		content := strings.Replace(validWHM, `"accept": ["numeric-string"],`, replacement, 1)
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err != nil {
			t.Fatal(err)
		}
	}
}
