package effective

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

var canonicalCollectionIndex = regexp.MustCompile(`^(0|[1-9][0-9]*)$`)

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
	itemDoc := c.ItemType
	if (o.EnumDoc == nil || *o.EnumDoc) && c.ItemType != "bool" {
		node := source.Definition["schema"]
		sourceID := source.Locator.SourceID
		if spec.Type == "list" {
			obj, resolvedID, _, resolved := b.schemas.follow(sourceID, node)
			if !resolved || obj["enum"] != nil || obj["items"] == nil {
				b.problem(op.ID + ": parameter " + source.Name + ": collection enum documentation cannot represent a composed or literal list schema")
				return
			}
			node, sourceID = obj["items"], resolvedID
		}
		docs, err := b.schemas.collectionItemDocs(sourceID, node, 0)
		if err != nil {
			b.problem(op.ID + ": parameter " + source.Name + ": collection enum documentation cannot represent " + err.Error())
			return
		}
		var alternatives []string
		for _, kind := range strings.Split(c.ItemType, "|") {
			// An explicit string|int compatibility decision admits the other kind,
			// but establishes no enum literals for it.
			alternatives = append(alternatives, firstNonEmpty(docs[kind], kind))
		}
		itemDoc = strings.Join(alternatives, "|")
	}
	param.Collection = c
	param.Encoding = "collection"
	param.PHPType = "array"
	param.DocType = "non-empty-list<" + itemDoc + ">"
	if *c.AllowScalar {
		param.PHPType = c.ItemType + "|array"
		param.DocType = itemDoc + "|" + param.DocType
	}
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
	if !canonicalCollectionIndex.MatchString(suffix) {
		return false
	}
	n, err := strconv.ParseUint(suffix, 10, 64)
	return err != nil || n >= uint64(start)
}

func (b *builder) collectionOverlaps(id string, params []Param) {
	for i, p := range params {
		base, start, indexed := indexedBase(p)
		if !indexed {
			continue
		}
		for j, other := range params {
			// Legacy indexed families must also be checked against new repeats.
			// Leave comparisons between two legacy-only parameters unchanged.
			if i == j || (p.Collection == nil && other.Collection == nil) {
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

// collectionItemDocs follows the actual item schema, retaining source literals
// separately by kind so explicit compatibility widening cannot invent literals
// for the added kind. Null is an omission alternative, never a list item.
func (s schemas) collectionItemDocs(sourceID string, node any, depth int) (map[string]string, error) {
	obj, sourceID, _, ok := s.follow(sourceID, node)
	if !ok || depth > maxDepth {
		return nil, fmt.Errorf("an unresolved or recursive item schema")
	}
	if nullOnly(obj) {
		return map[string]string{}, nil
	}
	if parts, composed := obj["allOf"].([]any); composed {
		sibling := map[string]any{}
		for key, value := range obj {
			if key != "allOf" {
				sibling[key] = value
			}
		}
		var contributing []any
		for _, part := range append(append([]any{}, parts...), sibling) {
			resolved, _, _, ok := s.follow(sourceID, part)
			if !ok || contributes(resolved) {
				contributing = append(contributing, part)
			}
		}
		if len(contributing) == 1 {
			return s.collectionItemDocs(sourceID, contributing[0], depth+1)
		}
		return nil, fmt.Errorf("intersecting allOf item constraints; use a reviewed enum_doc:false override to widen documentation")
	}
	for _, unionKey := range []string{"anyOf", "oneOf"} {
		if branches, ok := obj[unionKey].([]any); ok {
			if _, hasEnum := obj["enum"]; hasEnum {
				return nil, fmt.Errorf("an enum combined with %s", unionKey)
			}
			result := map[string]string{}
			for _, branch := range branches {
				docs, err := s.collectionItemDocs(sourceID, branch, depth+1)
				if err != nil {
					return nil, err
				}
				for kind, doc := range docs {
					prior := result[kind]
					switch {
					case prior == kind || doc == kind:
						result[kind] = kind
					case prior == "":
						result[kind] = doc
					default:
						result[kind] = prior + "|" + doc
					}
				}
			}
			return result, nil
		}
	}
	kind := typeName(obj)
	if kind == "integer" {
		kind = "int"
	}
	if kind != "string" && kind != "int" {
		return nil, fmt.Errorf("item kind %q", kind)
	}
	doc := kind
	if raw, present := obj["enum"]; present {
		values, ok := raw.([]any)
		if !ok || len(values) == 0 {
			return nil, fmt.Errorf("an empty or malformed %s enum", kind)
		}
		var literals []any
		for _, value := range values {
			if value == nil {
				continue
			}
			if kind == "string" {
				literal, ok := value.(string)
				if !ok {
					return nil, fmt.Errorf("a non-string literal in a string enum")
				}
				if strings.Contains(literal, "*/") {
					return nil, fmt.Errorf("a string literal containing a PHPDoc comment terminator")
				}
			} else {
				// Only canonical integer tokens have truthful integer-literal PHPDoc.
				switch value.(type) {
				case string, bool:
					return nil, fmt.Errorf("a non-integer literal in an integer enum")
				}
				token := fmt.Sprint(value)
				if strings.HasPrefix(token, "-") {
					token = strings.TrimPrefix(token, "-")
				}
				if !canonicalCollectionIndex.MatchString(token) {
					return nil, fmt.Errorf("a non-integer literal in an integer enum")
				}
			}
			literals = append(literals, value)
		}
		if len(literals) == 0 {
			return nil, fmt.Errorf("an enum without non-null %s literals", kind)
		}
		doc = s.enumDoc(sourceID, map[string]any{"enum": literals})
		if doc == "" {
			return nil, fmt.Errorf("unsupported %s enum literals", kind)
		}
	}
	return map[string]string{kind: doc}, nil
}
