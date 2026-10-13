package capability

import (
	"bytes"
	"fmt"
	"strings"
)

// Markdown renders the saved observation and exact differences without
// converting docs-only names into support or implementation assertions.
func Markdown(report Comparison) []byte {
	var b bytes.Buffer
	p := report.Profile
	fmt.Fprintln(&b, "# Saved capability comparison")
	fmt.Fprintf(&b, "\nOrigin: %s\n\nServer version: %s\n\nAccount: %s\n\n", text(p.Origin), text(p.ServerVersion), text(p.Context.Username))
	context := "unprovided"
	if p.Context.CPanelContext != nil {
		context = *p.Context.CPanelContext
	}
	fmt.Fprintf(&b, "cPanel context: %s\n\nCaptured at: %s\n\nCapture status: %s\n\nDirect UAPI authentication: %s\n", text(context), text(p.CapturedAt), text(p.CaptureStatus), text(p.DirectUAPIAuth))
	fmt.Fprintln(&b, "\nThe documentation denominator is independent of this host. Advertised names describe this saved account observation; differences do not establish operation support or PHP coverage.")
	fmt.Fprintln(&b, "\n| API family | Runtime inventory | Documented operations | Unique advertised names |\n| --- | --- | ---: | ---: |")
	for _, inventory := range []InventoryComparison{report.WHM, report.UAPI} {
		count := "unavailable"
		if inventory.AdvertisedCount != nil {
			count = fmt.Sprint(*inventory.AdvertisedCount)
		}
		fmt.Fprintf(&b, "| %s | %s | %d | %s |\n", text(inventory.Family), text(inventory.InventoryStatus), inventory.DocumentedCount, count)
	}
	for _, set := range []struct {
		name  string
		names []string
	}{{"Shared WHM names", report.WHM.Shared}, {"Docs-only WHM names", report.WHM.DocsOnly}, {"Runtime-only WHM names", report.WHM.RuntimeOnly}} {
		fmt.Fprintf(&b, "\n## %s\n\n", set.name)
		if set.names == nil {
			fmt.Fprintln(&b, "unavailable")
		} else if len(set.names) == 0 {
			fmt.Fprintln(&b, "None.")
		} else {
			for _, name := range set.names {
				fmt.Fprintf(&b, "- %s\n", text(name))
			}
		}
	}
	fmt.Fprintln(&b, "\n## Capture evidence\n\n| Function | Route | Status | HTTP status | API result | Error category |\n| --- | --- | --- | --- | --- | --- |")
	for _, e := range p.Evidence {
		httpStatus := "unrecorded"
		if e.HTTPStatus != 0 {
			httpStatus = fmt.Sprint(e.HTTPStatus)
		}
		result := "unrecorded"
		if e.APIResult != nil {
			result = fmt.Sprint(*e.APIResult)
		}
		fmt.Fprintf(&b, "| %s | %s | %s | %s | %s | %s |\n", text(e.Function), text(e.Route), text(e.Status), httpStatus, result, text(e.ErrorCategory))
	}
	fmt.Fprint(&b, "\n## Explanations\n\n")
	if len(report.Explanations) == 0 {
		fmt.Fprintln(&b, "No available inventory differences.")
	}
	for _, e := range report.Explanations {
		fmt.Fprintf(&b, "- %s %s: %s (%s). %s\n", text(e.Side), text(e.Name), text(e.Category), text(e.Certainty), text(e.Rationale))
		if e.RelatedName != "" {
			fmt.Fprintf(&b, "  Related exact name: %s. Original difference sets are preserved.\n", text(e.RelatedName))
		}
		for _, loc := range e.Evidence {
			location := ""
			if loc.Pointer != nil {
				location = *loc.Pointer
			} else if loc.ProseLocator != nil {
				location = *loc.ProseLocator
			}
			fmt.Fprintf(&b, "  Evidence: %s, SHA-256 %s, locator %s.\n", text(loc.SourceID), text(loc.SourceSHA256), text(location))
		}
	}
	return b.Bytes()
}

func text(value string) string {
	// Escape Markdown punctuation, markup and line separators in saved strings.
	var b strings.Builder
	for _, r := range value {
		switch r {
		case '\n', '\r', '\t':
			b.WriteRune(' ')
		case '&':
			b.WriteString("&amp;")
		case '<':
			b.WriteString("&lt;")
		case '>':
			b.WriteString("&gt;")
		case '\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '!', '|':
			b.WriteRune('\\')
			b.WriteRune(r)
		default:
			b.WriteRune(r)
		}
	}
	return b.String()
}
