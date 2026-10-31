package effective

import (
	"regexp"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func (b *builder) additionalMethods(op model.Operation, v model.Variant, ov overlay.Operation, canonical Operation) []Method {
	var methods []Method
	sources := map[string]Param{}
	for _, p := range canonical.Params {
		sources[p.Wire] = p
	}
	locators := map[string]model.Parameter{}
	for _, p := range v.Parameters {
		locators[p.Name] = p
	}
	for _, m := range ov.AdditionalMethods {
		fail := func(message string) { b.problem(op.ID + ": additional method " + m.Name + ": " + message) }
		method := Method{PHPName: m.Name, Constant: Constant(m.Name)}
		if !IsMethodName(m.Name) || reservedLower[strings.ToLower(m.Name)] || method.Constant == "CLASS" || !constantName.MatchString(method.Constant) || len(m.Inputs) == 0 {
			fail("invalid name or empty inputs")
		}
		used, names := map[string]bool{}, map[string]bool{}
		bind := func(source string) (Param, bool) {
			p, ok := sources[source]
			if !ok || used[source] {
				fail("unknown or duplicated source parameter " + source)
				return Param{}, false
			}
			used[source] = true
			sourceParam := locators[source]
			bound := false
			for _, e := range ov.Evidence {
				bound = bound || (e.Kind == "doc" && sourceParam.Locator.Pointer != nil && e.SourceSHA256 != "" && e.Note != "" && e.SourceSHA256 == sourceParam.Locator.SourceSHA256 && (e.Pointer == *sourceParam.Locator.Pointer || e.Pointer == *sourceParam.Locator.Pointer+"/description"))
			}
			if !bound {
				fail("input needs doc evidence matching source_sha256 and parameter/prose pointer")
			}
			return p, true
		}
		for _, input := range m.Inputs {
			if !IsMethodName(input.PHP) || reservedArguments[input.PHP] || names[input.PHP] {
				fail("invalid or duplicated input name")
			}
			names[input.PHP] = true
			if (input.Source == "") == (input.Records == nil) {
				fail("input requires exactly one source or records mapping")
				continue
			}
			if input.Records == nil {
				if p, ok := bind(input.Source); ok {
					p.PHP = input.PHP
					if input.JSON != nil {
						schema, description, err := b.jsonField(locators[input.Source], input.JSON, ov.Params[input.Source], canonical, p, ov.Evidence)
						if err != nil {
							fail("JSON field " + err.Error())
							continue
						}
						if !p.Required && inputHasNull(schema) {
							fail("JSON field optional nullable roots are unsupported")
							continue
						}
						p.Input, p.Encoding, p.PHPType, p.DocType, p.Sensitive = &schema, "json", InputNative(schema), input.JSON.Alias, true
						p.Description = description
						if schema.Type == "object" {
							p.Description += " Omitted members stay absent. Empty object input [] serializes to {}."
						}
					}
					method.Params = append(method.Params, p)
				}
				continue
			}
			if input.JSON != nil {
				fail("JSON selection requires a source mapping")
				continue
			}
			c := input.Records
			if canonical.Body != "" && canonical.Body != "form" {
				fail("record inputs require the canonical form body")
			}
			if !IsClassName(c.Alias) || c.Start == nil || *c.Start < 0 || *c.Start > 2147483647 || c.Separator == nil || (*c.Separator != "" && *c.Separator != "-") || c.Sensitive == nil || len(c.Fields) == 0 || (c.FirstUnsuffixed != nil && (!*c.FirstUnsuffixed || *c.Start != 0 || *c.Separator != "-")) {
				fail("invalid record descriptor")
				continue
			}
			records := &RecordSpec{Alias: c.Alias, Start: *c.Start, Separator: *c.Separator, FirstUnsuffixed: c.FirstUnsuffixed != nil && *c.FirstUnsuffixed}
			fields := map[string]bool{}
			for _, f := range c.Fields {
				if !IsMethodName(f.PHP) || fields[f.PHP] || !regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`).MatchString(f.Prefix) {
					fail("invalid or duplicated record field")
				}
				fields[f.PHP] = true
				p, ok := bind(f.Source)
				if !ok {
					continue
				}
				if p.Encoding != "scalar" && p.Encoding != "bool" || p.Collection != nil || !containsRecordType(p.PHPType) || p.RejectEmpty || p.OmitEmpty {
					fail("record field requires an unguarded strict scalar source")
				}
				if p.Sensitive && !*c.Sensitive {
					fail("record sensitivity cannot discard source secrecy")
				}
				if f.Required != nil && !*f.Required {
					fail("record required override may only strengthen source requiredness")
				}
				records.Fields = append(records.Fields, RecordField{PHP: f.PHP, Prefix: f.Prefix, Type: p.PHPType, Required: p.Required || f.Required != nil && *f.Required})
			}
			p := Param{PHP: input.PHP, Wire: input.PHP, Encoding: "records", PHPType: "array", DocType: "non-empty-list<" + c.Alias + ">", Required: true, Sensitive: *c.Sensitive, Records: records, Description: "Records in input order; optional fields retain their original row index."}
			method.Params = append(method.Params, p)
		}
		if len(used) != len(sources) {
			fail("mapping must account for every canonical source parameter")
		}
		optional := false
		wires := map[string]bool{}
		for _, p := range method.Params {
			if wires[p.Wire] {
				fail("duplicate wire name in input mapping")
			}
			wires[p.Wire] = true
			if optional && p.Required {
				fail("required input follows optional input")
			}
			optional = optional || !p.Required
			if p.Sensitive && canonical.Method != "POST" {
				fail("secret-bearing input requires canonical POST")
			}
		}
		// Flatten each record field into its indexed family for static overlap checks.
		var expanded []Param
		bases := map[string]bool{}
		for _, p := range method.Params {
			if p.Records == nil {
				expanded = append(expanded, p)
				continue
			}
			for _, f := range p.Records.Fields {
				prefix, sep, start, allow := f.Prefix, p.Records.Separator, p.Records.Start, false
				if p.Records.FirstUnsuffixed {
					// The first literal name is separate from the tail family; index zero is never emitted.
					bases[f.Prefix] = true
					expanded = append(expanded, Param{PHP: p.PHP + "." + f.PHP, Wire: f.Prefix, Encoding: "scalar"})
					start = 1
				}
				expanded = append(expanded, Param{PHP: p.PHP + "." + f.PHP, Wire: f.Prefix, Encoding: "collection", Collection: &overlay.Collection{Kind: "indexed", ItemType: f.Type, AllowScalar: &allow, Empty: "reject", Prefix: &prefix, Separator: &sep, Start: &start}})
			}
		}
		// The indexed checker does not compare two literal bases, or legacy-only
		// tails against a literal base. Check each opted-in literal explicitly.
		for _, p := range expanded {
			if !bases[p.Wire] || p.Encoding != "scalar" || p.Collection != nil {
				continue
			}
			for _, other := range expanded {
				if p.PHP == other.PHP && p.Encoding == other.Encoding && p.Wire == other.Wire {
					continue
				}
				exact := !overlay.IsSuffixNumbered(other.Encoding)
				if other.Collection != nil {
					exact = other.Collection.Kind == "repeat" || other.Collection.AllowScalar != nil && *other.Collection.AllowScalar
				}
				base, start, indexed := indexedBase(other)
				if exact && p.Wire == other.Wire || indexed && indexContains(base, start, p.Wire) {
					fail("record has overlapping wire names for parameters " + p.PHP + " and " + other.PHP)
				}
			}
		}
		b.collectionOverlaps(op.ID, expanded)
		methods = append(methods, method)
	}
	return methods
}
func containsRecordType(s string) bool {
	return s == "string" || s == "int" || s == "bool" || s == "string|int"
}
