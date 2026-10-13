package drift

import (
	"fmt"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

// Markdown renders findings only; upstream changes are not PHP regressions.
func Markdown(r Report) []byte {
	var b strings.Builder
	b.WriteString("# Source contract drift\n\n")
	fmt.Fprintf(&b, "Provenance changed: %t.\n\n", r.ProvenanceChanged)
	for _, side := range []struct {
		name    string
		sources []model.Object
	}{{"Before sources", r.BeforeSources}, {"After sources", r.AfterSources}} {
		fmt.Fprintf(&b, "## %s\n\n", side.name)
		for _, source := range side.sources {
			version, ok := source["version"]
			if !ok {
				version = "unspecified"
			}
			fmt.Fprintf(&b, "- %s — version %s; source SHA-256 %s\n", escapeMarkdown(fmt.Sprint(source["id"])), escapeMarkdown(fmt.Sprint(version)), escapeMarkdown(fmt.Sprint(source["sha256"])))
		}
		b.WriteString("\n")
	}
	for _, section := range []struct {
		name string
		ids  []string
	}{{"Added operations", r.AddedOperations}, {"Removed operations", r.RemovedOperations}, {"Added variants", r.AddedVariants}, {"Removed variants", r.RemovedVariants}} {
		fmt.Fprintf(&b, "## %s\n\n", section.name)
		if len(section.ids) == 0 {
			b.WriteString("None.\n\n")
		} else {
			for _, id := range section.ids {
				fmt.Fprintf(&b, "- %s\n", escapeMarkdown(id))
			}
			b.WriteString("\n")
		}
	}
	b.WriteString("## Confirmed aliases\n\n")
	if len(r.ConfirmedAliases) == 0 {
		b.WriteString("None.\n\n")
	}
	for _, a := range r.ConfirmedAliases {
		fmt.Fprintf(&b, "- %s → %s: %s\n", escapeMarkdown(a.AliasID), escapeMarkdown(a.TargetID), escapeMarkdown(a.Reason))
	}
	b.WriteString("\n## Categorized changes\n\n")
	if len(r.Changes) == 0 {
		b.WriteString("None.\n")
	}
	for _, c := range r.Changes {
		fmt.Fprintf(&b, "- **%s** %s — %s\n  - Before: %s\n  - After: %s\n", c.Category, escapeMarkdown(c.VariantID), escapeMarkdown(c.ID), describe(c.Before), describe(c.After))
	}
	return []byte(b.String())
}
func escapeMarkdown(s string) string {
	return strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;", "\n", " ", "\r", " ", "\\", "\\\\", "`", "\\`", "*", "\\*", "_", "\\_", "[", "\\[", "]", "\\]").Replace(s)
}
func describe(s Snapshot) string {
	if s.Absent {
		return "absent"
	}
	where := ""
	if s.Locator != nil {
		where = s.Locator.SourceID
		if s.Locator.Pointer != nil {
			where += "#" + *s.Locator.Pointer
		} else if s.Locator.ProseLocator != nil {
			where += " " + *s.Locator.ProseLocator
		}
	}
	return escapeMarkdown(where) + " (SHA-256 " + s.Hash + ")"
}
