package phpgen

import (
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func TestIntegralFixedPointStringRendersAnIntegerContract(t *testing.T) {
	spec := effective.TypeSpec{Type: "int", Accept: []string{"numeric-string", "integral-fixed-point-string"}}
	if got := nativeType(spec); got != "int" {
		t.Fatal(got)
	}
	if got := shape(spec, ""); got != "int" {
		t.Fatal(got)
	}
	want := "['type' => 'int', 'accept' => ['numeric-string', 'integral-fixed-point-string']]"
	if got := specLiteral(spec, ""); got != want {
		t.Fatalf("got %s, want %s", got, want)
	}
}
