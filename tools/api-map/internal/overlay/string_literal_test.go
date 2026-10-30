package overlay

import (
	"strings"
	"testing"
)

func TestLoadStringLiteralReplacement(t *testing.T) {
	content := strings.Replace(validWHM, `"accept": ["numeric-string"],`, `"replace": {"type":"string","literal":"unlimited"},`, 1)
	if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err != nil {
		t.Fatal(err)
	}
	for _, replacement := range []string{`"replace": {"type":"int","literal":"unlimited"},`, `"replace": {"type":"string","literal":null},`} {
		content = strings.Replace(validWHM, `"accept": ["numeric-string"],`, replacement, 1)
		if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
			t.Fatal("accepted invalid literal constraint")
		}
	}
}
