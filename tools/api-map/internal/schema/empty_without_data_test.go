package schema

import "testing"

func TestEmptyWithoutDataOverlayGrammar(t *testing.T) {
	for _, c := range []struct {
		name  string
		value any
		extra map[string]any
		valid bool
	}{
		{"valid", true, nil, true},
		{"flag outcome", true, map[string]any{"outcome": "flag"}, true},
		{"false", false, nil, false},
		{"string", "true", nil, false},
		{"no unwrap", true, map[string]any{"unwrap": nil}, false},
		{"unchecked", true, map[string]any{"outcome": "unchecked"}, false},
		{"void", true, map[string]any{"void": true}, false},
		{"optional", true, map[string]any{"optional_payload": true}, false},
		{"rows", true, map[string]any{"rows": "acct"}, false},
		{"exclude", true, map[string]any{"exclude": map[string]any{"reason": "r", "evidence": []any{}}}, false},
	} {
		t.Run(c.name, func(t *testing.T) {
			op := map[string]any{"empty_without_data": c.value, "unwrap": "acct"}
			for k, v := range c.extra {
				if v == nil {
					delete(op, k)
				} else {
					op[k] = v
				}
			}
			file := map[string]any{"schema_version": "1.0", "family": "whm1", "group": "Accounts", "accessor": "accounts", "operations": map[string]any{"whm1:listaccts": op}}
			err := Validate("overlay-v1.schema.json", file)
			if (err == nil) != c.valid {
				t.Fatalf("valid=%v error=%v", c.valid, err)
			}
		})
	}
}
