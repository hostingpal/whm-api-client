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
	markdownLink = regexp.MustCompile(`\[([^\]]+)\]\(`)
	whitespace   = regexp.MustCompile(`\s+`)
	blankLine    = regexp.MustCompile(`\n\s*\n`)
	// reservedNames are the methods WhmClient and UapiClient declare or inherit
	// from AbstractApiClient (including its final protected and private
	// methods); a group accessor is a trait method of those handles and must
	// not collide with them. reserved_test.go keeps the list in step with the
	// PHP sources.
	reservedNames = map[string]bool{
		"call": true, "responseMeta": true, "username": true, "isBridged": true, "run": true, "dispatch": true,
		"request": true, "beginRequest": true, "recordResponseMeta": true, "executeGet": true, "executePost": true,
		"execute": true, "encode": true, "queryValues": true,
	}
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
	body, ok := b.formBody(op, v, ov, node)
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
		Body:        ov.Body,
		Summary:     sentence(text(node["summary"])),
		Description: firstParagraph(text(node["description"])),
		Subcategory: subcategory(op.Family, node),
		SeeURL:      b.seeURL(op, v, node),
		Notes:       notes(ov.Notes),
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
	result.Method = b.method(op, v, ov, result.Params, len(body) > 0 || ov.Body != "")
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
		for _, defect := range specDefects(*returns, "") {
			b.problem(op.ID + ": " + defect)
		}
	} else if len(ov.Response) > 0 {
		b.problem(op.ID + ": response corrections need a documented payload")
	}
	if ov.Unwrap != "" && (returns == nil || returns.Type != "object" || fieldIndex(returns, ov.Unwrap) < 0) {
		b.problem(op.ID + ": unwrap field " + ov.Unwrap + " is not a documented object field")
	} else if ov.Unwrap != "" && returns.Nullable {
		// A null payload could never be unwrapped (OptionalPayload is the way
		// to accept a missing or null data member).
		b.problem(op.ID + ": unwrap needs a non-nullable object payload")
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
func (b *builder) formBody(op model.Operation, v model.Variant, ov overlay.Operation, node map[string]any) ([]model.Parameter, bool) {
	if ov.Body == "multipart" {
		return b.multipartBody(op, v, ov, node)
	}
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
		if documented[p.Name] {
			// The second value would overwrite the first in the request.
			b.problem(op.ID + ": parameter " + p.Name + " is documented more than once")
			continue
		}
		documented[p.Name] = true
		if o, ok := ov.Params[p.Name]; ok && o.Omit {
			omitted[p.Name] = true
			if o.OmitEmpty != nil {
				b.problem(op.ID + ": omitted parameter " + p.Name + " cannot have omit_empty")
			}
			if o.Wire != "" {
				b.problem(op.ID + ": omitted parameter " + p.Name + " cannot have wire corrections")
			}
			if o.Type != "" || o.Description != "" {
				b.problem(op.ID + ": omitted parameter " + p.Name + " cannot have type or description corrections")
			}
			if p.Definition["required"] == true {
				b.problem(op.ID + ": required parameter " + p.Name + " cannot be omitted; exclude the operation and use call()")
			}
			continue
		}
		if strings.ContainsAny(p.Name, "*") && !overlay.IsSuffixNumbered(ov.Params[p.Name].Encoding) {
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
		enumDoc := b.schemas.enumDoc(p.Locator.SourceID, decl["schema"])
		if setting := ov.Params[p.Name].EnumDoc; setting != nil && !*setting {
			enumDoc = ""
		}
		param.PHPType, param.DocType, param.Encoding, composite = paramTypes(spec, enumDoc)
		param.Sensitive = sensitiveName(p.Name, spec)
		o := ov.Params[p.Name]
		if o.Required != nil {
			param.Required = *o.Required
		}
		if reason := secretDecision(p.Name, text(decl["description"]), spec, param.Sensitive); reason != "" && o.Sensitive == nil {
			b.problem(op.ID + ": parameter " + p.Name + " may carry a secret (" + reason + `); declare "sensitive" true or false`)
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
		if o.OmitEmpty != nil {
			if !*o.OmitEmpty || decl["required"] == true || param.Required || param.Encoding != "repeat" ||
				!b.schemas.stringList(p.Locator.SourceID, decl["schema"]) {
				b.problem(op.ID + ": parameter " + p.Name + ": omit_empty requires true on an optional repeat-encoded string-list source and optional final requiredness")
			} else {
				param.OmitEmpty = true
			}
		}
		if o.Wire != "" && (!overlay.IsCorrectedWireName(p.Name) || !overlay.IsCorrectedWireName(o.Wire) ||
			param.Encoding != "scalar" || (spec.Type != "string" && spec.Type != "int" && spec.Type != "number")) {
			b.problem(op.ID + ": parameter " + p.Name + ": wire correction requires simple names and a scalar string, integer or number source")
		}
		// Presence-only inputs use a native true guard and the existing 1/0
		// encoder, so no false or numeric value can reach the wire. This is an
		// explicit reviewed correction, never inferred from other singleton enums.
		if o.Type == "true" {
			if (decl["required"] == true) != param.Required || spec.Type != "int" || spec.Nullable ||
				!b.schemas.presenceOnlyInteger(p.Locator.SourceID, decl["schema"]) || o.Wire != "" ||
				(o.Encoding != "" && o.Encoding != "bool") {
				b.problem(op.ID + ": parameter " + p.Name + ": true type correction requires a concrete nonnullable scalar integer source with exact enum [1], unchanged requiredness, no wire correction and absent or bool encoding")
			} else {
				param.PHPType, param.DocType, param.Encoding = "true", "true", "bool"
			}
		} else if o.Type != "" {
			// Numeric corrections retain the source's numeric types and cannot
			// replace boolean or multiple-value encodings.
			stringInteger := o.Type == "string|int" && (spec.Type == "string" || spec.Type == "int") && param.PHPType == spec.Type
			stringNumber := o.Type == "string|int|float" && spec.Type == "number" && param.PHPType == "int|float"
			if param.Encoding != "scalar" || (!stringInteger && !stringNumber) {
				b.problem(op.ID + ": parameter " + p.Name + ": type correction requires string|int on a scalar string or integer source, or string|int|float on a scalar number source")
			} else {
				param.PHPType, param.DocType = o.Type, o.Type
			}
		}
		if o.Description != "" {
			param.Description = clean(o.Description)
			if param.Description == "" {
				b.problem(op.ID + ": parameter " + p.Name + ": description correction must be nonempty")
			}
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
		if ov.Body == "multipart" && (param.Encoding != "scalar" || param.PHPType != "string") {
			b.problem(op.ID + ": multipart fields require scalar string parameters")
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
	params := append(orderParams(required, ov.Order), orderParams(optional, ov.Order)...)
	// Resolve corrected wire names only after source-based ordering and PHP
	// binding, so a transport correction never changes named arguments.
	wireNames := map[string]bool{}
	for i := range params {
		if corrected := ov.Params[params[i].Wire].Wire; corrected != "" {
			params[i].Wire = corrected
		}
		if wireNames[params[i].Wire] {
			b.problem(op.ID + ": duplicate wire name " + params[i].Wire)
		}
		wireNames[params[i].Wire] = true
	}
	for i, p := range params {
		if !overlay.IsSuffixNumbered(p.Encoding) {
			continue
		}
		base := strings.TrimSuffix(p.Wire, "*")
		expanded := regexp.MustCompile("^" + regexp.QuoteMeta(base) + "[1-9][0-9]*$")
		for j, other := range params {
			if i == j {
				continue
			}
			name := other.Wire
			if overlay.IsSuffixNumbered(other.Encoding) {
				name = strings.TrimSuffix(name, "*")
			}
			if expanded.MatchString(name) {
				b.problem(op.ID + ": overlapping wire names " + p.Wire + " and " + other.Wire)
			}
		}
	}
	return params
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
	case "suffix-numbered":
		return "string|array", "string|list<string>", encoding
	case "suffix-numbered-scalar":
		return "string|int|array", "string|int|list<string|int>", encoding
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
	"newpass": true, "oldpass": true, "privatekey": true, "passhash": true, "keypass": true, "sshkeypass": true,
	"privkey": true, "apikey": true, "authcode": true,
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

// decisionWords are name words that mark secrets and harmless values alike:
// private, DNSSEC and remote access keys share "key" with setting and lookup
// keys, and a signature may be a credential or a public checksum.
var decisionWords = map[string]string{"key": "a key", "signature": "a signature"}

// secretDescription matches pinned descriptions of OAuth secrets carried in a
// field whose name says nothing (WHM 360Monitoring/webpros_auth_callback json).
var secretDescription = regexp.MustCompile(`(?i)authorization code|code verifier|\bPKCE\b`)

// secretDecision returns why a parameter that the name heuristic leaves
// non-sensitive may still carry a secret, or "" when it needs no decision:
// any decision word in its name, or a description of an authorization code
// or verifier. The overlay must then declare "sensitive" true or false.
func secretDecision(name, description string, spec TypeSpec, sensitive bool) string {
	switch spec.Type {
	case "flag", "bool", "int", "number":
		return ""
	}
	if sensitive {
		return ""
	}
	for _, word := range lowerWords(name) {
		if reason, ok := decisionWords[word]; ok {
			return reason
		}
	}
	if secretDescription.MatchString(description) {
		return "an authorization code or verifier"
	}
	return ""
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

// notes labels overlay notes by kind; the overlay schema admits only the kinds
// overlay.NoteKinds lists.
func notes(source []overlay.Note) []Note {
	var result []Note
	for _, note := range source {
		result = append(result, Note{Label: overlay.NoteKinds[note.Kind], Text: note.Text})
	}
	return result
}

func text(v any) string {
	s, _ := v.(string)
	return s
}

// cleanMarkdownLinks removes only complete inline links, balancing URL parentheses.
// Malformed links stay intact instead of dropping a partial URL from the prose.
func cleanMarkdownLinks(s string) string {
	var out strings.Builder
	cursor := 0
	for _, match := range markdownLink.FindAllStringSubmatchIndex(s, -1) {
		if match[0] < cursor {
			continue
		}
		depth := 1
		end := match[1]
		for end < len(s) && depth > 0 {
			switch s[end] {
			case '\\':
				if end+1 < len(s) {
					end++
				}
			case '(':
				depth++
			case ')':
				depth--
			}
			end++
		}
		if depth != 0 {
			continue
		}
		out.WriteString(s[cursor:match[0]])
		out.WriteString(s[match[2]:match[3]])
		cursor = end
	}
	out.WriteString(s[cursor:])
	return out.String()
}

func clean(s string) string {
	s = cleanMarkdownLinks(s)
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
