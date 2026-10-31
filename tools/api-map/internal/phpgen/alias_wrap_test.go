package phpgen

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func TestRenderWrapsLongGenericAliasesWithoutChangingTypes(t *testing.T) {
	leaf := effective.TypeSpec{Type: "map", Values: &effective.TypeSpec{Type: "number", Nullable: true}}
	middle := effective.TypeSpec{Type: "map", Values: &effective.TypeSpec{Type: "union", Nullable: true, AnyOf: []effective.TypeSpec{{Type: "number"}, leaf}}}
	nested := effective.TypeSpec{Type: "map", Values: &effective.TypeSpec{Type: "union", AnyOf: []effective.TypeSpec{{Type: "number"}, middle}}}
	literal := `literal with spaces, <angles>, pipes|and an escaped ' quote`
	for _, tc := range []struct {
		name, alias, want string
		spec              effective.TypeSpec
	}{
		{
			name: "nested numeric maps", alias: "QueryResult", spec: nested,
			want: " * @phpstan-type QueryResult array<\n" +
				" *     array-key, int|float|array<array-key, int|float|array<array-key, int|float|null>|null>>\n",
		},
		{
			name: "quoted map value", alias: "LongAliasNameToExerciseLiteralPreservation",
			spec: effective.TypeSpec{Type: "map", Values: &effective.TypeSpec{Type: "string", Literal: &literal}},
			want: " * @phpstan-type LongAliasNameToExerciseLiteralPreservation array<\n" +
				" *     array-key, 'literal with spaces, <angles>, pipes|and an escaped \\' quote'>\n",
		},
		{
			name: "quoted list value", alias: "LongAliasNameToExerciseLiteralPreservation",
			spec: effective.TypeSpec{Type: "list", Items: &effective.TypeSpec{Type: "string", Literal: &literal}},
			want: " * @phpstan-type LongAliasNameToExerciseLiteralPreservation list<\n" +
				" *     'literal with spaces, <angles>, pipes|and an escaped \\' quote'>\n",
		},
	} {
		t.Run(tc.name, func(t *testing.T) {
			result := goldenResult()
			result.Groups = result.Groups[:1]
			result.Groups[0].Operations = result.Groups[0].Operations[:1]
			result.Groups[0].Operations[0].Alias = tc.alias
			result.Groups[0].Operations[0].Returns = &tc.spec
			files := Render(result)
			class := string(files["src/Api/Uapi/DomainInfo.php"])
			if !strings.Contains(class, tc.want) {
				t.Errorf("alias lost its exact type or expected wrapping; want:\n%sgot:\n%s", tc.want, class)
			}
			for i, line := range strings.Split(class, "\n") {
				if len(line) > lineWidth {
					t.Errorf("generated class line %d is %d characters: %s", i+1, len(line), line)
				}
			}

		})
	}
}
