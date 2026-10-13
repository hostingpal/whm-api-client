// Package drift compares pinned source contracts without inferring renames or
// treating upstream additions as implementation regressions.
package drift

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/contract"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/mapbuild"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

type Snapshot struct {
	Absent  bool           `json:"absent"`
	Locator *model.Locator `json:"locator"`
	Hash    string         `json:"hash"`
}
type Change struct {
	ID        string   `json:"id"`
	Category  string   `json:"category"`
	VariantID string   `json:"variant_id"`
	Before    Snapshot `json:"before"`
	After     Snapshot `json:"after"`
}
type Report struct {
	SchemaVersion     string         `json:"schema_version"`
	BeforeSources     []model.Object `json:"before_sources"`
	AfterSources      []model.Object `json:"after_sources"`
	ProvenanceChanged bool           `json:"provenance_changed"`
	AddedOperations   []string       `json:"added_operations"`
	RemovedOperations []string       `json:"removed_operations"`
	AddedVariants     []string       `json:"added_variants"`
	RemovedVariants   []string       `json:"removed_variants"`
	ConfirmedAliases  []model.Alias  `json:"confirmed_aliases"`
	Changes           []Change       `json:"changes"`
}

// Compare validates both maps before emitting a deterministic set of findings.
// Source records are provenance, never a substitute for normalized contracts.
func Compare(before, after model.Map) (Report, error) {
	if err := validate(before); err != nil {
		return Report{}, fmt.Errorf("before map: %w", err)
	}
	if err := validate(after); err != nil {
		return Report{}, fmt.Errorf("after map: %w", err)
	}
	r := Report{SchemaVersion: "1.0", BeforeSources: before.Sources, AfterSources: after.Sources, ProvenanceChanged: !equal(before.Sources, after.Sources), AddedOperations: []string{}, RemovedOperations: []string{}, AddedVariants: []string{}, RemovedVariants: []string{}, ConfirmedAliases: append([]model.Alias{}, after.Aliases...), Changes: []Change{}}
	bo, bv := identities(before)
	ao, av := identities(after)
	r.AddedOperations, r.RemovedOperations = delta(bo, ao)
	r.AddedVariants, r.RemovedVariants = delta(bv, av)
	bs, err := collect(before)
	if err != nil {
		return Report{}, err
	}
	as, err := collect(after)
	if err != nil {
		return Report{}, err
	}
	for _, key := range union(bs.entries, as.entries) {
		b, bok := bs.entries[key]
		a, aok := as.entries[key]
		if bok && aok && b.snapshot.Hash == a.snapshot.Hash {
			continue
		}
		entry := a
		if !aok {
			entry = b
		}
		r.Changes = append(r.Changes, Change{ID: key, Category: entry.category, VariantID: entry.variant, Before: snapshot(b, bok), After: snapshot(a, aok)})
	}
	// Units own shared contract hashing. Retained fields are compared separately
	// with OpenAPI position awareness, including raw extension/data payloads.
	for _, sid := range union(before.Documents, after.Documents) {
		bl := rootLocator(before, sid)
		al := rootLocator(after, sid)
		b, bok := before.Documents[sid]
		a, aok := after.Documents[sid]
		walkDiff(&r, b, bok, a, aok, bl, al, "document", "uninterpreted", bs.covered, as.covered, bv, av)
	}
	sort.Slice(r.Changes, func(i, j int) bool { return r.Changes[i].ID < r.Changes[j].ID })
	sort.Slice(r.ConfirmedAliases, func(i, j int) bool { return r.ConfirmedAliases[i].AliasID < r.ConfirmedAliases[j].AliasID })
	return r, nil
}
func validate(m model.Map) error {
	if err := schema.Validate("api-map-v1.schema.json", m); err != nil {
		return err
	}
	if err := validateSources(m); err != nil {
		return err
	}
	rebuilt, err := mapbuild.Build(source.Bundle{Records: m.Sources, Documents: m.Documents}, m.Annotations, m.Aliases)
	if err != nil {
		return err
	}
	// Rebuilding checks references, alias cycles/evidence, canonical identities,
	// effective inheritance and risk; stale derived fields must not be trusted.
	a, _ := model.Encode(model.Map{SchemaVersion: "1.0", Operations: m.Operations})
	b, _ := model.Encode(model.Map{SchemaVersion: "1.0", Operations: rebuilt.Operations})
	if !bytes.Equal(a, b) {
		return fmt.Errorf("map operations disagree with retained sources")
	}
	return nil
}

// validateSources checks retained-bundle completeness independently of whether
// any operation or annotation happens to refer to a source record. Prose has no
// parsed document; OpenAPI and pinned reference records must retain theirs.
func validateSources(m model.Map) error {
	records := map[string]string{}
	for _, record := range m.Sources {
		for _, field := range []string{"id", "file", "url", "sha256", "kind"} {
			value, ok := record[field].(string)
			if !ok || strings.TrimSpace(value) == "" {
				return fmt.Errorf("source requires nonempty %s", field)
			}
		}
		id, kind, hash := record["id"].(string), record["kind"].(string), record["sha256"].(string)
		if _, exists := records[id]; exists {
			return fmt.Errorf("duplicate source ID")
		}
		decoded, err := hex.DecodeString(hash)
		if err != nil || len(decoded) != sha256.Size || hash != strings.ToLower(hash) {
			return fmt.Errorf("invalid source SHA-256")
		}
		document, present := m.Documents[id]
		switch kind {
		case "openapi", "reference":
			if !present || document == nil {
				return fmt.Errorf("parsed source document is missing")
			}
		case "prose":
			if present {
				return fmt.Errorf("prose source cannot have a parsed document")
			}
		default:
			return fmt.Errorf("unsupported source kind")
		}
		records[id] = kind
	}
	for id := range m.Documents {
		if _, ok := records[id]; !ok {
			return fmt.Errorf("document has no source record")
		}
	}
	return nil
}

func identities(m model.Map) (map[string]bool, map[string]model.Variant) {
	ops := map[string]bool{}
	vs := map[string]model.Variant{}
	for _, o := range m.Operations {
		ops[o.ID] = true
		for _, v := range o.Variants {
			vs[v.ID] = v
		}
	}
	return ops, vs
}
func delta[A, B any](a map[string]A, b map[string]B) ([]string, []string) {
	added, removed := []string{}, []string{}
	for k := range b {
		if _, ok := a[k]; !ok {
			added = append(added, k)
		}
	}
	for k := range a {
		if _, ok := b[k]; !ok {
			removed = append(removed, k)
		}
	}
	sort.Strings(added)
	sort.Strings(removed)
	return added, removed
}
func union[A, B any](a map[string]A, b map[string]B) []string {
	keys := map[string]bool{}
	for k := range a {
		keys[k] = true
	}
	for k := range b {
		keys[k] = true
	}
	out := make([]string, 0, len(keys))
	for k := range keys {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}
func equal(a, b any) bool {
	x, _ := model.Encode(model.Object{"value": a})
	y, _ := model.Encode(model.Object{"value": b})
	return bytes.Equal(x, y)
}
func object(v any) model.Object {
	switch o := v.(type) {
	case model.Object:
		return o
	case map[string]any:
		return model.Object(o)
	}
	return nil
}
func digest(v any) string {
	raw, _ := model.Encode(model.Object{"value": v})
	return fmt.Sprintf("%x", sha256.Sum256(raw))
}
func present(l model.Locator, v any) Snapshot { return Snapshot{Locator: &l, Hash: digest(v)} }
func rootLocator(m model.Map, sid string) model.Locator {
	p := ""
	l := model.Locator{SourceID: sid, Pointer: &p}
	for _, s := range m.Sources {
		if s["id"] == sid {
			l.SourceSHA256, _ = s["sha256"].(string)
		}
	}
	return l
}
func child(l model.Locator, key string) model.Locator {
	p := *l.Pointer + "/" + strings.ReplaceAll(strings.ReplaceAll(key, "~", "~0"), "/", "~1")
	l.Pointer = &p
	return l
}

type item struct {
	snapshot          Snapshot
	category, variant string
}
type collected struct {
	entries map[string]item
	covered map[string][]string
}

func snapshot(i item, ok bool) Snapshot {
	if !ok {
		return Snapshot{Absent: true}
	}
	return i.snapshot
}
func collect(m model.Map) (collected, error) {
	c := collected{map[string]item{}, map[string][]string{}}
	for _, o := range m.Operations {
		for _, v := range o.Variants {
			units, err := contract.Units(m, v)
			if err != nil {
				return c, err
			}
			for _, u := range units {
				l := u.Locator
				c.entries["unit:"+u.ID] = item{Snapshot{Locator: &l, Hash: u.Hash}, u.Kind, v.ID}
				n, err := contract.Normalize(m, l, contract.KindAt(l))
				if err != nil {
					return c, err
				}
				for key := range object(object(n)["nodes"]) {
					var parts []string
					if err := json.Unmarshal([]byte(key), &parts); err != nil || len(parts) != 3 {
						return c, fmt.Errorf("invalid normalized graph node")
					}
					c.covered[parts[0]] = append(c.covered[parts[0]], parts[1])
				}
			}
			for _, effective := range []struct {
				name, category string
				value          model.EffectiveValue
			}{{"security", "auth", v.Security}, {"servers", "availability", v.Servers}} {
				e := effective.value
				if e.Present {
					l := v.OperationLocator
					if e.Locator != nil {
						l = *e.Locator
					}
					normalized, err := contract.Normalize(m, l, contract.ChildKind("operation", effective.name))
					if err != nil {
						return c, err
					}
					c.entries["effective:"+contract.ID(v.ID, effective.name)] = item{present(l, model.Object{"value": normalized, "level": e.Level}), effective.category, v.ID}
				}
			}
		}
	}
	for _, a := range m.Annotations {
		l := a.Evidence[0]
		evidence := []model.Object{}
		for _, e := range a.Evidence {
			evidence = append(evidence, model.Object{"source_id": e.SourceID, "pointer": e.Pointer, "prose_locator": e.ProseLocator})
		}
		value := model.Object{"scope": a.Scope, "target_id": a.TargetID, "kind": a.Kind, "conclusion": a.Conclusion, "rationale": a.Rationale, "evidence": evidence}
		category := a.Kind
		switch category {
		case "risk", "availability", "deprecation", "auth", "documentation":
		case "rollback":
			category = "risk"
		default:
			category = "uninterpreted"
		}
		v := ""
		if a.Scope == "variant" {
			v = a.TargetID
		}
		c.entries["annotation:"+a.ID] = item{present(l, value), category, v}
	}
	return c, nil
}

// fieldCategory distinguishes control fields from arbitrary named-map keys and
// raw JSON. In particular properties.description and extension.description are
// never treated as prose merely because of their spelling.
func fieldCategory(kind, key, inherited string) string {
	if kind == "data" || strings.Contains(kind, ":") {
		return inherited
	}
	switch key {
	case "description", "summary", "example", "examples", "externalDocs", "x-codeSamples":
		return "documentation"
	case "deprecated":
		return "deprecation"
	case "x-readonly", "x-rollback":
		return "risk"
	case "x-cpanel-available-version", "x-cpanel-minimum-compatible-version", "x-cpanel-rpm-version", "x-cpanel-rpm", "x-cpanel-internal-only", "x-cpanel-cli-support", "x-cpanel-plugin", "x-cpanel-api-version":
		return "availability"
	}
	if strings.HasPrefix(key, "x-") {
		return "uninterpreted"
	}
	switch kind {
	case "document":
		switch key {
		case "security":
			return "effective"
		case "servers":
			return "effective"
		}
	case "operation":
		switch key {
		case "parameters":
			return "parameter"
		case "requestBody":
			return "body"
		case "responses":
			return "response"
		case "security", "servers":
			return "effective"
		}
	case "path":
		switch key {
		case "parameters":
			return "parameter"
		case "servers":
			return "effective"
		}
	case "components":
		if key == "securitySchemes" {
			return "auth"
		}
		if knownField(kind, key) {
			return "contract"
		}
	}
	if kind == "securityScheme" {
		return "auth"
	}
	if knownField(kind, key) {
		return inherited
	}
	return "uninterpreted"
}
func knownField(kind, key string) bool {
	fields := map[string]string{
		"info":           "title termsOfService contact license version",
		"server":         "url variables",
		"serverVariable": "enum default",
		"tag":            "name",
		"document":       "openapi info paths components tags security servers",
		"path":           "$ref get put post delete options head patch trace parameters servers",
		"operation":      "operationId tags parameters requestBody responses callbacks security servers",
		"components":     "schemas parameters responses requestBodies headers securitySchemes callbacks links examples",
		"schema":         "$ref title type format multipleOf maximum exclusiveMaximum minimum exclusiveMinimum maxLength minLength pattern maxItems minItems uniqueItems maxProperties minProperties required enum properties items not additionalProperties allOf oneOf anyOf nullable readOnly writeOnly discriminator xml default",
		"parameter":      "$ref name in required style explode allowEmptyValue allowReserved schema content deprecated",
		"header":         "$ref required style explode allowEmptyValue allowReserved schema content deprecated",
		"media":          "schema encoding",
		"requestBody":    "$ref required content",
		"response":       "$ref content headers links",
		"discriminator":  "propertyName mapping",
		"encoding":       "contentType headers style explode allowReserved",
		"link":           "$ref operationRef operationId parameters requestBody server",
	}
	if kind == "responses" || kind == "callback" {
		return true
	}
	return strings.Contains(" "+fields[kind]+" ", " "+key+" ")
}
func covered(l model.Locator, roots map[string][]string) bool {
	for _, p := range roots[l.SourceID] {
		if *l.Pointer == p || strings.HasPrefix(*l.Pointer, p+"/") {
			return true
		}
	}
	return false
}

// metadataAt considers consumers on both sides. Shared source locations are
// reported once without choosing an arbitrary canonical variant. An added or
// removed consumer must never hide a change for a surviving consumer.
func metadataAt(bl, al model.Locator, before, after map[string]model.Variant) (variant string, skip bool) {
	consumers := map[string]bool{}
	for _, side := range []struct {
		locator  model.Locator
		variants map[string]model.Variant
	}{{bl, before}, {al, after}} {
		for id, v := range side.variants {
			origin := v.OperationLocator
			if origin.SourceID == side.locator.SourceID && origin.Pointer != nil &&
				(*side.locator.Pointer == *origin.Pointer || strings.HasPrefix(*side.locator.Pointer, *origin.Pointer+"/")) {
				consumers[id] = true
			}
		}
	}
	surviving := false
	for id := range consumers {
		_, old := before[id]
		_, current := after[id]
		surviving = surviving || old && current
		if len(consumers) == 1 {
			variant = id
		}
	}
	return variant, len(consumers) > 0 && !surviving
}
func walkDiff(r *Report, b any, bok bool, a any, aok bool, bl, al model.Locator, kind, category string, bc, ac map[string][]string, bv, av map[string]model.Variant) {
	if bok && aok && equal(b, a) {
		return
	}
	variant, skip := metadataAt(bl, al, bv, av)
	if skip {
		return
	}
	if kind != "data" {
		bo, ao := object(b), object(a)
		if (bo != nil || !bok) && (ao != nil || !aok) && len(union(bo, ao)) > 0 {
			for _, key := range union(bo, ao) {
				x, xok := bo[key]
				y, yok := ao[key]
				walkDiff(r, x, xok, y, yok, child(bl, key), child(al, key), contract.ChildKind(kind, key), fieldCategory(kind, key, category), bc, ac, bv, av)
			}
			return
		}
		ba, barray := b.([]any)
		aa, aarray := a.([]any)
		if (barray || !bok) && (aarray || !aok) {
			n := len(ba)
			if len(aa) > n {
				n = len(aa)
			}
			for i := 0; i < n; i++ {
				var x, y any
				xok, yok := i < len(ba), i < len(aa)
				if xok {
					x = ba[i]
				}
				if yok {
					y = aa[i]
				}
				key := fmt.Sprint(i)
				walkDiff(r, x, xok, y, yok, child(bl, key), child(al, key), contract.ChildKind(kind, key), category, bc, ac, bv, av)
			}
			if n > 0 {
				return
			}
		}
	}
	if category == "effective" {
		return
	}
	if category == "parameter" || category == "body" || category == "response" || category == "contract" {
		if (!bok || covered(bl, bc)) && (!aok || covered(al, ac)) {
			return
		}
	}
	if category == "contract" {
		category = "uninterpreted"
	}
	before, after := Snapshot{Absent: true}, Snapshot{Absent: true}
	if bok {
		before = present(bl, b)
	}
	if aok {
		after = present(al, a)
	}
	r.Changes = append(r.Changes, Change{ID: "source:" + contract.ID(al.SourceID, *al.Pointer), Category: category, VariantID: variant, Before: before, After: after})
}
