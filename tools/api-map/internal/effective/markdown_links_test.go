package effective

import "testing"

func TestCleanMarkdownLinks(t *testing.T) {
	for _, tc := range []struct{ input, want string }{
		{"disable [yum](https://en.wikipedia.org/wiki/Yum_(software))'s exclude behavior", "disable yum's exclude behavior"},
		{"[label](https://example.test/a_(b_(c))) and [ordinary](https://example.test)", "label and ordinary"},
		{"before [label](https://example.test/unfinished_(url) after", "before [label](https://example.test/unfinished_(url) after"},
		{"[unfinished](url", "[unfinished](url"},
		{"[label] without target", "[label] without target"},
		{"(plain text) **bold**", "(plain text) bold"},
	} {
		if got := clean(tc.input); got != tc.want {
			t.Errorf("clean(%q) = %q; want %q", tc.input, got, tc.want)
		}
	}
}
