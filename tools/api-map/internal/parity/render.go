package parity

import (
	"bytes"
	"fmt"
	"strings"
)

func Markdown(r Report) []byte {
	var b bytes.Buffer
	fmt.Fprint(&b, "# API implementation coverage\n\n")
	revision := r.Revision
	if revision == "" {
		revision = "unknown (no revision asserted)"
	}
	fmt.Fprintf(&b, "Valid registry: **%t**. Test revision: `%s`.\n\n", r.Valid, md(revision))
	fmt.Fprint(&b, "| Dimension | Covered | Net denominator | Gross | Excluded |\n| --- | ---: | ---: | ---: | ---: |\n")
	for _, row := range []struct {
		name string
		c    Count
	}{{"Functions", r.Summary.Functions}, {"Variants", r.Summary.Variants}, {"Parameters", r.Summary.Parameters}, {"Bodies", r.Summary.Bodies}, {"Response schemas", r.Summary.Responses}, {"Variants with declared tests", r.Summary.DeclaredTests}, {"Variants with passing test outcomes", r.Summary.PassingTests}, {"Variants with verified live evidence", r.Summary.LiveVerified}} {
		fmt.Fprintf(&b, "| %s | %d | %d | %d | %d |\n", row.name, row.c.Numerator, row.c.Denominator, row.c.Gross, row.c.Excluded)
	}
	fmt.Fprintf(&b, "\nResponses without documented schemas: **%d**. These gaps are outside the response-schema denominator.\n\n", r.Summary.MissingResponseSchemas)
	fmt.Fprint(&b, "Test declarations prove symbol existence only. Passing outcomes require a matching revision and an explicit completed run. Missing outcomes are not run; missing live evidence is unverified. Contract coverage is schema-level coverage.\n\n")
	fmt.Fprint(&b, "## Family and module/tag totals\n\nWHM tag subtotals overlap and are non-additive. Global totals deduplicate canonical IDs.\n\n| Family | Module/tag | Functions | Variants |\n| --- | --- | ---: | ---: |\n")
	for _, g := range r.Groups {
		label := g.Name
		if label == "" {
			label = "(all)"
		}
		if g.NonAdditive {
			label += " (non-additive)"
		}
		fmt.Fprintf(&b, "| %s | %s | %d/%d | %d/%d |\n", md(g.Family), md(label), g.Summary.Functions.Numerator, g.Summary.Functions.Denominator, g.Summary.Variants.Numerator, g.Summary.Variants.Denominator)
	}
	fmt.Fprint(&b, "\n## Live route and authentication evidence\n\n")
	if len(r.LiveRoutes) == 0 {
		fmt.Fprint(&b, "Not verified: no matching live evidence supplied.\n")
	} else {
		for _, route := range r.LiveRoutes {
			fmt.Fprintf(&b, "- %s / %s: %d/%d variants\n", md(route.Route), md(route.Authentication), route.Coverage.Numerator, route.Coverage.Denominator)
		}
	}
	fmt.Fprint(&b, "\n## Operation states\n\n| Operation | State |\n| --- | --- |\n")
	for _, op := range r.Operations {
		fmt.Fprintf(&b, "| `%s` | %s |\n", md(op.ID), op.State)
	}
	fmt.Fprint(&b, "\n## Diagnostics\n\n")
	if len(r.Diagnostics) == 0 {
		fmt.Fprint(&b, "None.\n")
	}
	for _, d := range r.Diagnostics {
		fmt.Fprintf(&b, "- **%s** `%s` `%s`: %s\n", md(d.Severity), md(d.Code), md(d.CanonicalID), md(d.Message))
	}
	return b.Bytes()
}
func md(s string) string {
	s = strings.ReplaceAll(s, "|", "\\|")
	s = strings.ReplaceAll(s, "`", "'")
	s = strings.ReplaceAll(s, "\r", " ")
	return strings.ReplaceAll(s, "\n", " ")
}
