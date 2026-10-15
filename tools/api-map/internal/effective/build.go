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
		variant, ok := b.variant(op, entry.op)
		if !ok {
			continue
		}
		operation, groupName, ok := b.operation(op, variant, entry.op)
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
			groups[key] = b.group(op.Family, groupName, variant)
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

// variant picks the variant to generate: the only one, or the one the overlay
// selects by HTTP method. An operation with several variants needs that
// decision, and the selected method must be documented.
func (b *builder) variant(op model.Operation, ov overlay.Operation) (model.Variant, bool) {
	methods := make([]string, len(op.Variants))
	for i, v := range op.Variants {
		methods[i] = v.Method
	}
	documented := strings.Join(methods, ", ")
	if ov.Variant == "" {
		if len(op.Variants) == 1 {
			return op.Variants[0], true
		}
		b.problem(op.ID + ": operations with several variants need an overlay variant decision (" + documented + ")")
		return model.Variant{}, false
	}
	for _, v := range op.Variants {
		if v.Method == ov.Variant {
			return v, true
		}
	}
	b.problem(op.ID + ": variant " + ov.Variant + " is not documented (" + documented + ")")
	return model.Variant{}, false
}

func (b *builder) operation(op model.Operation, v model.Variant, ov overlay.Operation) (Operation, string, bool) {
	payload, node, err := b.schemas.payload(op, v)
	if err != nil {
		b.problem(err.Error())
		return Operation{}, "", false
	}
	returns := payload.spec
	// Query parameters and form-encoded body fields are generated; any other
	// documented body (multipart or JSON, possibly carrying secrets) must
	// never be dropped silently.
	body, ok := b.formBody(op, v, node)
	if !ok {
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
		SeeURL:      b.seeURL(op, v, node),
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
	result.Params = b.params(op, v, ov, body)
	result.Method = b.method(op, v, ov, result.Params, len(body) > 0)
	if payload.issue != "" && !ov.Void {
		b.problem(op.ID + ": " + payload.issue + "; declare void with evidence or exclude the operation")
	}
	if payload.partial && !ov.OptionalPayload && !ov.Void {
		b.problem(op.ID + ": data is documented in only some 200 envelope alternatives; set optional_payload")
	}
	if ov.Void {
		returns = nil
	}
	if returns != nil {
		for _, path := range sortedKeys(ov.Response) {
			if err := applyCorrection(returns, path, ov.Response[path]); err != nil {
				b.problem(op.ID + ": " + err.Error())
			}
		}
		for _, issue := range specIssues(*returns, "") {
			b.problem(op.ID + ": " + issue + "; replace it in the overlay")
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

// formBody returns the fields of a documented application/x-www-form-urlencoded
// request body as parameters (in "body"), in name order. A field is required
// only when the body is required and its schema requires the field. It reports
// false, with a problem, for any other body, which is never dropped silently.
func (b *builder) formBody(op model.Operation, v model.Variant, node map[string]any) ([]model.Parameter, bool) {
	raw, documented := node["requestBody"]
	if !documented {
		return nil, true
	}
	body, bodySource, _, ok := b.schemas.follow(v.OperationLocator.SourceID, raw)
	content, _ := asObject(body["content"])
	media, isForm := asObject(content["application/x-www-form-urlencoded"])
	if !ok || !isForm {
		b.problem(op.ID + ": documents a request body that is not form-encoded; exclude it until such bodies are supported")
		return nil, false
	}
	schema, schemaSource, _, ok := b.schemas.follow(bodySource, media["schema"])
	props, hasProps := asObject(schema["properties"])
	if !ok || !hasProps || len(props) == 0 {
		b.problem(op.ID + ": documents a form request body without plain properties; exclude it until such bodies are supported")
		return nil, false
	}
	bodyRequired := body["required"] == true
	required := map[string]bool{}
	for _, name := range stringList(schema["required"]) {
		required[name] = bodyRequired
	}
	query := map[string]bool{}
	for _, p := range v.Parameters {
		query[p.Name] = true
	}
	params := make([]model.Parameter, 0, len(props))
	for _, name := range sortedKeys(props) {
		if query[name] {
			b.problem(op.ID + ": parameter " + name + " is documented in both the query and the request body")
			return nil, false
		}
		field, _, _, _ := b.schemas.follow(schemaSource, props[name])
		params = append(params, model.Parameter{
			Name: name,
			In:   "body",
			Definition: model.Object{
				"name": name, "in": "body", "required": required[name],
				"description": field["description"], "schema": props[name],
			},
			Locator: model.Locator{SourceID: schemaSource},
		})
	}
	return params, true
}

// params builds the generated arguments from the variant's query parameters
// followed by the form body fields; required arguments come first.
func (b *builder) params(op model.Operation, v model.Variant, ov overlay.Operation, body []model.Parameter) []Param {
	var required, optional []Param
	used := map[string]bool{}
	documented := map[string]bool{}
	omitted := map[string]bool{}
	for _, p := range append(append([]model.Parameter{}, v.Parameters...), body...) {
		if p.In != "query" && p.In != "body" {
			continue
		}
		documented[p.Name] = true
		if o, ok := ov.Params[p.Name]; ok && o.Omit {
			omitted[p.Name] = true
			if p.Definition["required"] == true {
				b.problem(op.ID + ": required parameter " + p.Name + " cannot be omitted; exclude the operation and use call()")
			}
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
		var composite bool
		param.PHPType, param.DocType, param.Encoding, composite = paramTypes(spec, b.schemas.enumDoc(p.Locator.SourceID, decl["schema"]))
		param.Sensitive = sensitiveName(p.Name, spec)
		o := ov.Params[p.Name]
		if keyDecision(p.Name, spec, param.Sensitive) && o.Sensitive == nil {
			b.problem(op.ID + ": parameter " + p.Name + ` may carry a key; declare "sensitive" true or false`)
		}
		if composite && o.Encoding == "" {
			b.problem(op.ID + ": parameter " + p.Name + " has an object or list schema; declare its encoding or omit it")
		}
		if o.PHP != "" {
			param.PHP = o.PHP
		}
		if o.Encoding != "" {
			param.PHPType, param.DocType, param.Encoding = encodingTypes(o.Encoding, param)
		}
		if o.Sensitive != nil {
			param.Sensitive = *o.Sensitive
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
	isRequired := map[string]bool{}
	for _, p := range required {
		isRequired[p.Wire] = true
	}
	firstOptional := ""
	for _, wire := range ov.Order {
		switch {
		case !documented[wire]:
			b.problem(op.ID + ": order lists undocumented parameter " + wire)
		case omitted[wire]:
			b.problem(op.ID + ": order lists omitted parameter " + wire)
		case isRequired[wire] && firstOptional != "":
			// Required arguments always precede optional ones, so this order
			// cannot be honoured.
			b.problem(op.ID + ": order lists optional parameter " + firstOptional + " before required parameter " + wire)
		case !isRequired[wire] && firstOptional == "":
			firstOptional = wire
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

// method picks GET for read-only operations and POST otherwise. Secret-bearing
// parameters and form body fields always need POST, which form-encodes the
// operation parameters in the body.
func (b *builder) method(op model.Operation, v model.Variant, ov overlay.Operation, params []Param, body bool) string {
	sensitive := false
	for _, p := range params {
		sensitive = sensitive || p.Sensitive
	}
	if ov.Method != "" {
		if ov.Method == "GET" && sensitive {
			b.problem(op.ID + ": secret-bearing parameters require POST")
		}
		if ov.Method == "GET" && body {
			b.problem(op.ID + ": request body parameters require POST")
		}
		return ov.Method
	}
	if v.Risk.Classification == "read-only" && !sensitive && !body {
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

// paramTypes returns the native type, the PHPStan type and the encoding of a
// query parameter, and whether its schema is composite (an object, a map, or
// a union with a list, map or object branch), which no scalar encoding can
// represent without an overlay decision. A union of scalar branches becomes
// the union of their PHP types; an untyped schema or branch stays string|int.
func paramTypes(spec TypeSpec, enumDoc string) (string, string, string, bool) {
	switch spec.Type {
	case "flag", "bool":
		return "bool", "bool", "bool", false
	case "int":
		return "int", firstNonEmpty(enumDoc, "int"), "scalar", false
	case "number":
		return "int|float", "int|float", "scalar", false
	case "string":
		return "string", firstNonEmpty(enumDoc, "string"), "scalar", false
	case "list":
		return "string|array", "string|list<string>", "repeat", false
	case "union":
		kinds := map[string]bool{}
		if scalarKinds(spec, kinds) {
			native := scalarUnion(kinds)
			return native, native, "scalar", false
		}
	case "mixed":
		return "string|int", "string|int", "scalar", false
	}
	return "string|int", "string|int", "scalar", true
}

// scalarKinds collects the scalar kinds (string, int, number, unknown) of a
// union's branches and reports false when a branch is a list, map or object.
// Flags and booleans travel as 0/1 integers.
func scalarKinds(spec TypeSpec, kinds map[string]bool) bool {
	switch spec.Type {
	case "string":
		kinds["string"] = true
	case "int", "flag", "bool":
		kinds["int"] = true
	case "number":
		kinds["number"] = true
	case "mixed":
		kinds["unknown"] = true
	case "union":
		for _, option := range spec.AnyOf {
			if !scalarKinds(option, kinds) {
				return false
			}
		}
	default:
		return false
	}
	return true
}

func scalarUnion(kinds map[string]bool) string {
	var types []string
	if kinds["string"] || kinds["unknown"] {
		types = append(types, "string")
	}
	if kinds["int"] || kinds["number"] || kinds["unknown"] {
		types = append(types, "int")
	}
	if kinds["number"] {
		types = append(types, "float")
	}
	return strings.Join(types, "|")
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

// lowerWords splits a name at separators and case boundaries (so promptToken
// is "prompt token") and lower-cases the words.
func lowerWords(name string) []string {
	words := Words(name)
	for i, word := range words {
		words[i] = strings.ToLower(word)
	}
	return words
}

// sensitiveName decides from the lower-cased words of the name. Flag and bool
// parameters are never secrets; an integer is a secret only as a one-time code
// whose last word is "token" (such as tfa_token), because integer "passwd"
// parameters are password-strength settings.
func sensitiveName(name string, spec TypeSpec) bool {
	words := lowerWords(name)
	switch spec.Type {
	case "flag", "bool":
		return false
	case "int":
		return len(words) > 0 && words[len(words)-1] == "token"
	}
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

// keyDecision reports whether a parameter the name heuristic leaves
// non-sensitive ends in the word "key": private keys and remote access keys
// share that word with setting and lookup keys, so the overlay must decide.
func keyDecision(name string, spec TypeSpec, sensitive bool) bool {
	switch spec.Type {
	case "flag", "bool", "int", "number":
		return false
	}
	words := lowerWords(name)
	return !sensitive && len(words) > 0 && words[len(words)-1] == "key"
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

// docSlugAlphabet is the tag alphabet whose documentation slug rule was
// verified against https://api.docs.cpanel.net/ (letters, digits, spaces,
// hyphens and parentheses; spaces become hyphens, everything is lower-cased).
var docSlugAlphabet = regexp.MustCompile(`^[A-Za-z0-9 ()-]+$`)

// docSlug returns the documentation portal's path segment for a tag, and false
// for a tag outside the verified alphabet.
func docSlug(tag string) (string, bool) {
	if !docSlugAlphabet.MatchString(tag) {
		return "", false
	}
	return strings.ToLower(strings.ReplaceAll(tag, " ", "-")), true
}

// seeURL links the variant's canonical operation page:
// /specifications/{cpanel|whm}.openapi/<slug of the operation's first tag that
// appears in x-tagGroups>/<lower-cased upstream operation id>. The legacy
// /openapi/<site>/operation/<function> links redirect by function name only,
// which is ambiguous for UAPI function names shared across modules and does
// not resolve names containing "/".
func (b *builder) seeURL(op model.Operation, v model.Variant, node map[string]any) string {
	site := "whm"
	if op.Family == "uapi" {
		site = "cpanel"
	}
	if v.UpstreamOperationID == nil || *v.UpstreamOperationID == "" {
		b.problem(op.ID + ": no upstream operation id to derive the documentation link from")
		return ""
	}
	groups := tagGroups(b.schemas.m.Documents[v.OperationLocator.SourceID])
	tags, _ := node["tags"].([]any)
	for _, tag := range tags {
		name := fmt.Sprint(tag)
		if _, grouped := groups[name]; !grouped {
			continue
		}
		slug, ok := docSlug(name)
		if !ok {
			b.problem(fmt.Sprintf("%s: tag %q has no verified documentation slug", op.ID, name))
			return ""
		}
		return "https://api.docs.cpanel.net/specifications/" + site + ".openapi/" + slug + "/" +
			strings.ToLower(*v.UpstreamOperationID)
	}
	b.problem(op.ID + ": no tag in x-tagGroups to derive the documentation link from")
	return ""
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
