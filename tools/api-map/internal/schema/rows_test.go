package schema

import "testing"

func TestRowsOverlayGrammar(t *testing.T) {
	for _, c := range []struct {
		name  string
		rows  any
		extra map[string]any
		valid bool
	}{
		{"valid", "uploads", nil, true},
		{"empty", "", nil, false}, {"null", nil, nil, false}, {"integer", 0, nil, false},
		{"object", map[string]any{}, nil, false},
		{"flag", "uploads", map[string]any{"outcome": "flag"}, false},
		{"no outcome", "uploads", map[string]any{"outcome": nil}, false},
		{"optional", "uploads", map[string]any{"optional_payload": true}, false},
		{"unwrap", "uploads", map[string]any{"unwrap": "uploads"}, false},
		{"void", "uploads", map[string]any{"void": true}, false},
	} {
		t.Run(c.name, func(t *testing.T) {
			op := map[string]any{"rows": c.rows, "outcome": "unchecked"}
			for k, v := range c.extra {
				if v == nil {
					delete(op, k)
				} else {
					op[k] = v
				}
			}
			file := map[string]any{"schema_version": "1.0", "family": "uapi", "group": "Fileman", "operations": map[string]any{"uapi:Fileman/upload_files": op}}
			err := Validate("overlay-v1.schema.json", file)
			if (err == nil) != c.valid {
				t.Fatalf("valid=%v error=%v", c.valid, err)
			}
		})
	}
}
