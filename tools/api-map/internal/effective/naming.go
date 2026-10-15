package effective

import (
	"regexp"
	"strings"
	"unicode"
)

var (
	separators = regexp.MustCompile(`[^A-Za-z0-9]+`)
	methodName = regexp.MustCompile(`^[a-z][A-Za-z0-9]*$`)
	className  = regexp.MustCompile(`^[A-Z][A-Za-z0-9]*$`)
)

// Words splits a name at separators and case boundaries; a run of capitals is
// one word unless its last capital starts a lowercase word (LangPHP -> Lang PHP,
// XMLHttp -> XML Http).
func Words(s string) []string {
	var words []string
	for _, part := range separators.Split(s, -1) {
		if part == "" {
			continue
		}
		runes := []rune(part)
		start := 0
		for i := 1; i < len(runes); i++ {
			previous, current := runes[i-1], runes[i]
			nextLower := i+1 < len(runes) && unicode.IsLower(runes[i+1])
			lowerToUpper := (unicode.IsLower(previous) || unicode.IsDigit(previous)) && unicode.IsUpper(current)
			acronymEnd := unicode.IsUpper(previous) && unicode.IsUpper(current) && nextLower
			if lowerToUpper || acronymEnd {
				words = append(words, string(runes[start:i]))
				start = i
			}
		}
		words = append(words, string(runes[start:]))
	}
	return words
}

func title(word string) string {
	lower := strings.ToLower(word)
	return strings.ToUpper(lower[:1]) + lower[1:]
}

// Camel returns lowerCamelCase.
func Camel(s string) string {
	words := Words(s)
	for i, word := range words {
		if i == 0 {
			words[i] = strings.ToLower(word)
		} else {
			words[i] = title(word)
		}
	}
	return strings.Join(words, "")
}

// Pascal returns UpperCamelCase.
func Pascal(s string) string {
	words := Words(s)
	for i, word := range words {
		words[i] = title(word)
	}
	return strings.Join(words, "")
}

// Constant returns UPPER_SNAKE_CASE.
func Constant(s string) string {
	return strings.ToUpper(strings.Join(Words(s), "_"))
}

// IsMethodName reports whether s is a valid generated method or argument name.
func IsMethodName(s string) bool { return methodName.MatchString(s) }

// IsClassName reports whether s is a valid generated class name.
func IsClassName(s string) bool { return className.MatchString(s) }
