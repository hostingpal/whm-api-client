package effective

import (
	"fmt"
	"regexp"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
)

var (
	markdownLink  = regexp.MustCompile(`\[([^\]]+)\]\([^)]*\)`)
	whitespace    = regexp.MustCompile(`\s+`)
	blankLine     = regexp.MustCompile(`\n\s*\n`)
	reservedNames = map[string]bool{"call": true, "responseMeta": true, "username": true, "isBridged": true, "run": true, "dispatch": true, "request": true}
	// reservedArguments are PHP variables a generated method body already uses.
	reservedArguments = map[string]bool{"this": true, "result": true}
)

type overlayEntry struct {
	file *overlay.File
	op   overlay.Operation
}

type builder struct {
	schemas  schemas
	set      overlay.Set
	entries  map[string]overlayEntry
	files    map[string]*overlay.File
	problems map[string]bool
	result   Result
}

// Build applies the overlay to the map and returns the effective contract.
func Build(m model.Map, set overlay.Set) (Result, error) {
	s, err := newSchemas(m)
	if err != nil {
		return Result{}, err
	}
	b := &builder{schemas: s, set: set, entries: map[string]overlayEntry{}, files: map[string]*overlay.File{}, problems: map[string]bool{}}
	for i := range set.Files {
		file := &set.Files[i]
		b.files[file.Family+"|"+file.Group] = file
		for id, op := range file.Operations {
			b.entries[id] = overlayEntry{file: file, op: op}
		}
	}
	known := map[string]bool{}
	groups := map[string]*Group{}
	operations := append([]model.Operation(nil), m.Operations...)
	sort.Slice(operations, func(i, j int) bool { return operations[i].ID < operations[j].ID })
	for _, op := range operations {
		known[op.ID] = true
		entry, listed := b.entries[op.ID]
		if listed && entry.op.Exclude != nil {
			b.result.Excluded = append(b.result.Excluded, Exclusion{ID: op.ID, Reason: entry.op.Exclude.Reason})
			continue
		}
		if set.Config.Scope == "listed" && !listed {
			continue
		}
		if len(op.Variants) != 1 {
			b.problem(op.ID + ": operations with several variants need an overlay decision")
			continue
		}
		operation, groupName, ok := b.operation(op, op.Variants[0], entry.op)
		if !ok {
			continue
		}
		if groupName == "" {
			b.problem(op.ID + ": has no group")
			continue
		}
		if listed && entry.file.Group != groupName {
			b.problem(fmt.Sprintf("%s: belongs to group %q, not %q", op.ID, groupName, entry.file.Group))
		}
		key := op.Family + "|" + groupName
		if groups[key] == nil {
			groups[key] = b.group(op.Family, groupName, op.Variants[0])
		}
		groups[key].Operations = append(groups[key].Operations, operation)
	}
	for id := range b.entries {
		if !known[id] {
			b.problem(id + ": not in the API map")
		}
	}
	b.checkGroupFiles(m)
	b.finish(groups)
	return b.result, nil
}

// checkGroupFiles reports overlay files whose (family, group) is not the group
// of any operation in the map. Groups are collected from every operation,
// regardless of scope and exclusions, so exclusion-only files stay valid.
func (b *builder) checkGroupFiles(m model.Map) {
	documented := map[string]bool{}
	for _, op := range m.Operations {
		for _, v := range op.Variants {
			if name := b.groupOf(op, v, b.schemas.operationNode(v)); name != "" {
				documented[op.Family+"|"+name] = true
			}
		}
	}
	for _, file := range b.set.Files {
		if !documented[file.Family+"|"+file.Group] {
			b.problem(fmt.Sprintf("%s: group %q is not a documented %s group", file.DisplayName(), file.Group, file.Family))
		}
	}
}

var reservedLower = func() map[string]bool {
	m := map[string]bool{}
	for name := range reservedNames {
		m[strings.ToLower(name)] = true
	}
	return m
}()

func (b *builder) problem(message string) { b.problems[message] = true }

func (b *builder) operation(op model.Operation, v model.Variant, ov overlay.Operation) (Operation, string, bool) {
	returns, node, err := b.schemas.payload(op, v)
	if err != nil {
		b.problem(err.Error())
		return Operation{}, "", false
	}
	// Only query parameters are generated; a documented body (multipart, JSON
	// or form, possibly carrying secrets) must never be dropped silently.
	if _, hasBody := node["requestBody"]; hasBody {
		b.problem(op.ID + ": documents a request body; exclude it until body parameters are supported")
		return Operation{}, "", false
	}
	if deprecated, _ := node["deprecated"].(bool); deprecated {
		b.problem(op.ID + ": flagged deprecated upstream; exclude it")
	}
	result := Operation{
		ID:          op.ID,
		VariantID:   v.ID,
		Family:      op.Family,
		Function:    op.Function,
		Outcome:     firstNonEmpty(ov.Outcome, "flag"),
		Summary:     sentence(text(node["summary"])),
		Description: firstParagraph(text(node["description"])),
		Subcategory: subcategory(op.Family, node),
		SeeURL:      seeURL(op),
		Notes:       ov.Notes,
		Unwrap:      ov.Unwrap,

		OptionalPayload: ov.OptionalPayload,
	}
	if strings.TrimSuffix(result.Description, ".") == strings.TrimSuffix(result.Summary, ".") {
		result.Description = ""
	}
	if op.Module != nil {
		result.Module = *op.Module
	}
	result.PHPName = b.methodName(op, ov)
	result.Constant = b.constant(op, result.PHPName)
	result.Alias = Pascal(result.PHPName) + "Result"
	result.Params = b.params(op, v, ov)
	result.Method = b.method(op, v, ov, result.Params)
	if ov.Void {
		returns = nil
	}
	if returns != nil {
		for _, path := range sortedKeys(ov.Response) {
			if err := applyCorrection(returns, path, ov.Response[path]); err != nil {
				b.problem(op.ID + ": " + err.Error())
			}
		}
	} else if len(ov.Response) > 0 {
		b.problem(op.ID + ": response corrections need a documented payload")
	}
	if ov.Unwrap != "" && (returns == nil || returns.Type != "object" || fieldIndex(returns, ov.Unwrap) < 0) {
		b.problem(op.ID + ": unwrap field " + ov.Unwrap + " is not a documented object field")
	}
	if ov.OptionalPayload && returns == nil {
		b.problem(op.ID + ": optional_payload needs a documented payload")
	}
	result.Returns = returns
	return result, b.groupOf(op, v, node), true
}

var constantName = regexp.MustCompile(`^[A-Z_][A-Z0-9_]*$`)

// constant picks the spec constant: the function name when it is a valid PHP
// constant, otherwise one derived from the PHP method name.
func (b *builder) constant(op model.Operation, phpName string) string {
	name := Constant(op.Function)
	if constantName.MatchString(name) && name != "CLASS" {
		return name
	}
	name = Constant(phpName)
	if !constantName.MatchString(name) || name == "CLASS" {
		b.problem(fmt.Sprintf("%s: spec constant %s is not a valid PHP constant", op.ID, name))
	}
	return name
}

func (b *builder) methodName(op model.Operation, ov overlay.Operation) string {
	if ov.Name != "" {
		return ov.Name
	}
	trimmed := strings.TrimLeft(op.Function, "_")
	if !strings.Contains(trimmed, "_") || trimmed != op.Function || strings.Contains(op.Function, "/") {
		b.problem(op.ID + ": needs an explicit overlay name")
	}
	return Camel(op.Function)
}

func (b *builder) params(op model.Operation, v model.Variant, ov overlay.Operation) []Param {
	var required, optional []Param
	used := map[string]bool{}
	documented := map[string]bool{}
	for _, p := range v.Parameters {
		if p.In != "query" {
			continue
		}
		documented[p.Name] = true
		if o, ok := ov.Params[p.Name]; ok && o.Omit {
			continue
		}
		if strings.ContainsAny(p.Name, "*") {
			b.problem(op.ID + ": parameter " + p.Name + " is a name pattern; omit it and use call()")
			continue
		}
		decl := p.Definition
		spec := b.schemas.specFor(p.Locator.SourceID, decl["schema"], 0, nil)
		param := Param{
			Wire:        p.Name,
			PHP:         Camel(p.Name),
			Required:    decl["required"] == true,
			Description: firstSentence(text(decl["description"])),
		}
		param.PHPType, param.DocType, param.Encoding = paramTypes(spec, b.schemas.enumDoc(p.Locator.SourceID, decl["schema"]))
		param.Sensitive = sensitiveName(p.Name, spec)
		if o, ok := ov.Params[p.Name]; ok {
			if o.PHP != "" {
				param.PHP = o.PHP
			}
			if o.Encoding != "" {
				param.PHPType, param.DocType, param.Encoding = encodingTypes(o.Encoding, param)
			}
			if o.Sensitive != nil {
				param.Sensitive = *o.Sensitive
			}
		}
		if !IsMethodName(param.PHP) || reservedArguments[param.PHP] {
			b.problem(op.ID + ": parameter " + p.Name + " needs an overlay php name")
		}
		if used[param.PHP] {
			b.problem(op.ID + ": duplicate argument name " + param.PHP)
		}
		used[param.PHP] = true
		if param.Required {
			required = append(required, param)
		} else {
			optional = append(optional, param)
		}
	}
	for _, wire := range sortedKeys(ov.Params) {
		if !documented[wire] {
			b.problem(op.ID + ": overlay parameter " + wire + " is not documented")
		}
	}
	for _, wire := range ov.Order {
		if !documented[wire] {
			b.problem(op.ID + ": order lists undocumented parameter " + wire)
		}
	}
	return append(orderParams(required, ov.Order), orderParams(optional, ov.Order)...)
}

// orderParams moves parameters named in order (by wire name) to the front, in
// that order, keeping the documented order for the rest. Required parameters
// always precede optional ones.
func orderParams(params []Param, order []string) []Param {
	rank := map[string]int{}
	for i, wire := range order {
		rank[wire] = i
	}
	sort.SliceStable(params, func(i, j int) bool {
		ri, iListed := rank[params[i].Wire]
		rj, jListed := rank[params[j].Wire]
		switch {
		case iListed && jListed:
			return ri < rj
		case iListed != jListed:
			return iListed
		}
		return false
	})
	return params
}

func (b *builder) method(op model.Operation, v model.Variant, ov overlay.Operation, params []Param) string {
	sensitive := false
	for _, p := range params {
		sensitive = sensitive || p.Sensitive
	}
	if ov.Method != "" {
		if ov.Method == "GET" && sensitive {
			b.problem(op.ID + ": secret-bearing parameters require POST")
		}
		return ov.Method
	}
	if v.Risk.Classification == "read-only" && !sensitive {
		return "GET"
	}
	return "POST"
}

func (b *builder) groupOf(op model.Operation, v model.Variant, node map[string]any) string {
	if op.Family == "uapi" {
		if op.Module == nil {
			return ""
		}
		return *op.Module
	}
	groups := tagGroups(b.schemas.m.Documents[v.OperationLocator.SourceID])
	tags, _ := node["tags"].([]any)
	for _, tag := range tags {
		if name, ok := groups[fmt.Sprint(tag)]; ok {
			return name
		}
	}
	return ""
}

func (b *builder) group(family, name string, v model.Variant) *Group {
	group := &Group{Family: family, Name: name}
	file := b.files[family+"|"+name]
	if family == "uapi" {
		group.Accessor = Camel(name)
		group.Class = Pascal(name)
		group.Description = tagDescription(b.schemas.m.Documents[v.OperationLocator.SourceID], name)
		if group.Description == "" {
			group.Description = "UAPI " + name + " module functions."
		}
	} else {
		group.Accessor = Camel(name)
		if file == nil || file.Accessor == "" {
			b.problem(fmt.Sprintf("WHM group %q needs an overlay file with an accessor", name))
		}
		group.Description = "WHM API 1 functions in the documented " + name + " group."
	}
	if file != nil {
		if file.Accessor != "" {
			group.Accessor = file.Accessor
		}
		if file.Description != "" {
			group.Description = file.Description
		}
	}
	group.Class = Pascal(group.Accessor)
	if file != nil && file.Class != "" {
		group.Class = file.Class
	}
	if family == "uapi" && (file == nil || file.Class == "") {
		group.Class = Pascal(name)
	}
	return group
}

func (b *builder) finish(groups map[string]*Group) {
	accessors := map[string]string{}
	classes := map[string]string{}
	for _, key := range sortedKeys(groups) {
		group := groups[key]
		sort.Slice(group.Operations, func(i, j int) bool { return group.Operations[i].PHPName < group.Operations[j].PHPName })
		if reservedLower[strings.ToLower(group.Accessor)] || !IsMethodName(group.Accessor) {
			b.problem(fmt.Sprintf("group %q: accessor %q is reserved or invalid", group.Name, group.Accessor))
		}
		if !IsClassName(group.Class) {
			b.problem(fmt.Sprintf("group %q: class %q is invalid", group.Name, group.Class))
		}
		classKey := group.Family + "|" + strings.ToLower(group.Class)
		if other, taken := classes[classKey]; taken {
			b.problem(fmt.Sprintf("groups %q and %q share class %q", other, group.Name, group.Class))
		}
		classes[classKey] = group.Name
		accessorKey := group.Family + "|" + strings.ToLower(group.Accessor)
		if other, taken := accessors[accessorKey]; taken {
			b.problem(fmt.Sprintf("groups %q and %q share accessor %q", other, group.Name, group.Accessor))
		}
		accessors[accessorKey] = group.Name
		names := map[string]bool{}
		constants := map[string]bool{}
		for _, op := range group.Operations {
			lower := strings.ToLower(op.PHPName)
			if names[lower] {
				b.problem(fmt.Sprintf("group %q: duplicate method %s", group.Name, op.PHPName))
			}
			if constants[op.Constant] {
				b.problem(fmt.Sprintf("group %q: duplicate spec constant %s", group.Name, op.Constant))
			}
			if !IsMethodName(op.PHPName) {
				b.problem(op.ID + ": method name " + op.PHPName + " is invalid")
			}
			names[lower] = true
			constants[op.Constant] = true
		}
		b.result.Groups = append(b.result.Groups, *group)
	}
	sort.SliceStable(b.result.Groups, func(i, j int) bool {
		if b.result.Groups[i].Family != b.result.Groups[j].Family {
			return b.result.Groups[i].Family < b.result.Groups[j].Family
		}
		return b.result.Groups[i].Class < b.result.Groups[j].Class
	})
	sort.Slice(b.result.Excluded, func(i, j int) bool { return b.result.Excluded[i].ID < b.result.Excluded[j].ID })
	b.result.Problems = sortedKeys(b.problems)
}

func paramTypes(spec TypeSpec, enumDoc string) (string, string, string) {
	switch spec.Type {
	case "flag", "bool":
		return "bool", "bool", "bool"
	case "int":
		return "int", firstNonEmpty(enumDoc, "int"), "scalar"
	case "number":
		return "int|float", "int|float", "scalar"
	case "string":
		return "string", firstNonEmpty(enumDoc, "string"), "scalar"
	case "list":
		return "string|array", "string|list<string>", "repeat"
	}
	return "string|int", "string|int", "scalar"
}

func encodingTypes(encoding string, current Param) (string, string, string) {
	switch encoding {
	case "numbered":
		return "string|array", "string|list<string>", "numbered"
	case "repeat":
		return "string|array", "string|list<string>", "repeat"
	case "bool":
		return "bool", "bool", "bool"
	}
	if current.Encoding == "scalar" {
		return current.PHPType, current.DocType, "scalar"
	}
	return "string|int", "string|int", "scalar"
}

var secretWords = map[string]bool{
	"pass": true, "passwd": true, "password": true, "passphrase": true, "secret": true,
	"newpass": true, "oldpass": true, "privatekey": true, "passhash": true,
}

// sensitiveName decides from the lower-cased words of the name; flag, bool and
// int parameters are never secrets.
func sensitiveName(name string, spec TypeSpec) bool {
	switch spec.Type {
	case "flag", "bool", "int":
		return false
	}
	words := Words(strings.ToLower(name))
	for _, word := range words {
		if secretWords[word] {
			return true
		}
	}
	if len(words) == 0 {
		return false
	}
	return words[len(words)-1] == "token" || secretWords[strings.Join(words, "")]
}

func tagGroups(doc model.Object) map[string]string {
	result := map[string]string{}
	groups, _ := doc["x-tagGroups"].([]any)
	for _, g := range groups {
		obj, _ := asObject(g)
		name, _ := obj["name"].(string)
		tags, _ := obj["tags"].([]any)
		for _, tag := range tags {
			result[fmt.Sprint(tag)] = name
		}
	}
	return result
}

func tagDescription(doc model.Object, name string) string {
	tags, _ := doc["tags"].([]any)
	for _, tag := range tags {
		obj, _ := asObject(tag)
		if obj["name"] == name {
			return firstParagraph(text(obj["description"]))
		}
	}
	return ""
}

func subcategory(family string, node map[string]any) string {
	if family == "uapi" {
		return ""
	}
	tags, _ := node["tags"].([]any)
	if len(tags) == 0 {
		return ""
	}
	return fmt.Sprint(tags[len(tags)-1])
}

func seeURL(op model.Operation) string {
	site := "whm"
	if op.Family == "uapi" {
		site = "cpanel"
	}
	return "https://api.docs.cpanel.net/openapi/" + site + "/operation/" + op.Function
}

func text(v any) string {
	s, _ := v.(string)
	return s
}

func clean(s string) string {
	s = markdownLink.ReplaceAllString(s, "$1")
	s = strings.NewReplacer("**", "", "__", "").Replace(s)
	return strings.TrimSpace(whitespace.ReplaceAllString(s, " "))
}

func firstParagraph(s string) string {
	for _, paragraph := range blankLine.Split(s, -1) {
		if cleaned := clean(paragraph); cleaned != "" {
			return cleaned
		}
	}
	return ""
}

func firstSentence(s string) string {
	paragraph := firstParagraph(s)
	if i := strings.Index(paragraph, ". "); i >= 0 {
		return paragraph[:i+1]
	}
	return paragraph
}

func sentence(s string) string {
	cleaned := clean(s)
	if cleaned != "" && !strings.HasSuffix(cleaned, ".") {
		cleaned += "."
	}
	return cleaned
}

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if value != "" {
			return value
		}
	}
	return ""
}
