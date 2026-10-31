package effective

import (
	"regexp"
	"strconv"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

func (b *builder) collection(op model.Operation, source model.Parameter, ov overlay.Operation, spec TypeSpec, param *Param) {
	o := ov.Params[source.Name]
	c := o.Collection
	if err := overlay.ValidateCollection(c); err != nil {
		b.problem(op.ID + ": parameter " + source.Name + ": collection " + err.Error())
		return
	}
	if o.Omit || o.Encoding != "" || o.Type != "" || o.Wire != "" || o.OmitEmpty != nil || o.RejectEmpty != nil {
		b.problem(op.ID + ": parameter " + source.Name + ": collection has incompatible parameter settings")
	}
	bound := false
	if source.Locator.Pointer != nil && source.Locator.SourceSHA256 != "" {
		for _, e := range ov.Evidence {
			bound = bound || (e.Kind == "doc" && e.SourceSHA256 == source.Locator.SourceSHA256 &&
				(e.Pointer == *source.Locator.Pointer || e.Pointer == *source.Locator.Pointer+"/description"))
		}
	}
	if !bound {
		b.problem(op.ID + ": parameter " + source.Name + ": collection needs doc evidence matching source_sha256 and parameter/prose pointer")
	}
	item := spec
	if spec.Type == "list" && spec.Items != nil {
		item = *spec.Items
	}
	compatible := func(t TypeSpec) bool {
		switch c.ItemType {
		case "string":
			return t.Type == "string"
		case "int":
			return t.Type == "int"
		case "bool":
			return t.Type == "bool" || t.Type == "flag"
		case "string|int":
			return t.Type == "string" || t.Type == "int"
		}
		return false
	}
	ok := compatible(item)
	if item.Type == "union" && c.ItemType == "string|int" {
		ok = len(item.AnyOf) > 0
		for _, branch := range item.AnyOf {
			ok = ok && !branch.Nullable && compatible(branch)
		}
	}
	if !ok || (spec.Type == "list" && item.Nullable) {
		b.problem(op.ID + ": parameter " + source.Name + ": collection item_type is incompatible with the source scalar/list type")
	}
	param.Collection = c
	param.Encoding = "collection"
	param.PHPType = "array"
	param.DocType = "non-empty-list<" + c.ItemType + ">"
	if *c.AllowScalar {
		param.PHPType = c.ItemType + "|array"
		param.DocType = c.ItemType + "|" + param.DocType
	}
	// Every collection-bearing generated frame redacts its complete argument.
	param.Sensitive = true
}

// indexedBase returns literal text preceding a canonical numeric index.
func indexedBase(p Param) (string, int, bool) {
	if p.Collection != nil && p.Collection.Kind == "indexed" && overlay.ValidateCollection(p.Collection) == nil {
		return *p.Collection.Prefix + *p.Collection.Separator, *p.Collection.Start, true
	}
	if p.Encoding == "numbered" {
		return p.Wire + "-", 1, true
	}
	if overlay.IsSuffixNumbered(p.Encoding) {
		return strings.TrimSuffix(p.Wire, "*"), 1, true
	}
	return "", 0, false
}

func indexContains(base string, start int, name string) bool {
	if !strings.HasPrefix(name, base) {
		return false
	}
	suffix := strings.TrimPrefix(name, base)
	if !regexp.MustCompile(`^(0|[1-9][0-9]*)$`).MatchString(suffix) {
		return false
	}
	n, err := strconv.ParseUint(suffix, 10, 64)
	return err != nil || n >= uint64(start)
}

func (b *builder) collectionOverlaps(id string, params []Param) {
	for i, p := range params {
		base, start, indexed := indexedBase(p)
		if !indexed || p.Collection == nil {
			continue
		}
		for j, other := range params {
			if i == j {
				continue
			}
			otherBase, _, otherIndexed := indexedBase(other)
			overlap := indexContains(base, start, other.Wire)
			if otherIndexed {
				// Appending sufficiently many digits makes either start reachable.
				overlap = overlap || base == otherBase || indexContains(base, 0, otherBase+"1") || indexContains(otherBase, 0, base+"1")
			}
			if overlap {
				b.problem(id + ": collection has overlapping wire names for parameters " + p.PHP + " and " + other.PHP)
			}
		}
	}
}
