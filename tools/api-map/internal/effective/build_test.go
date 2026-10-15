package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func evidence() []overlay.Evidence {
	return []overlay.Evidence{{Kind: "live", Version: "11.138.0.15", Date: "2026-10-14", Note: "observed"}}
}

func fixtureOverlay(scope string) overlay.Set {
	return overlay.Set{
		Config: overlay.Config{SchemaVersion: "1.0", Scope: scope},
		Files: []overlay.File{
			{Family: "uapi", Group: "DomainInfo", Path: "uapi/DomainInfo.json", Operations: map[string]overlay.Operation{
				"uapi:DomainInfo/list_domains":   {},
				"uapi:DomainInfo/primary_domain": {Unwrap: "primary_domain"},
			}},
			{Family: "whm1", Group: "Accounts", Accessor: "accounts", Path: "whm/Accounts.json", Operations: map[string]overlay.Operation{
				"whm1:createacct": {Name: "createAccount"},
				"whm1:listaccts": {Name: "listAccounts", Params: map[string]overlay.Param{"MXCHECK-*": {Omit: true}},
					Response: map[string]overlay.Correction{"acct[].uid": {Accept: []string{"numeric-string"}, Evidence: evidence()}}},
				"whm1:suspendacct": {Exclude: &overlay.Exclusion{Reason: "Covered by a later test.", Evidence: evidence()}},
			}},
		},
	}
}

func groupByClass(t *testing.T, result Result, class string) Group {
	t.Helper()
	for _, group := range result.Groups {
		if group.Class == class {
			return group
		}
	}
	t.Fatalf("missing group %s in %+v", class, result.Groups)
	return Group{}
}

func TestBuildAssemblesGroupsOperationsAndParams(t *testing.T) {
	result, err := Build(fixtureMap(t), fixtureOverlay("listed"))
	if err != nil {
		t.Fatal(err)
	}
	if len(result.Problems) != 0 {
		t.Fatalf("problems: %v", result.Problems)
	}
	domain := groupByClass(t, result, "DomainInfo")
	if domain.Accessor != "domainInfo" || domain.Description != "Retrieve domain information." {
		t.Fatalf("uapi group %+v", domain)
	}
	list := domain.Operations[0]
	if list.PHPName != "listDomains" || list.Constant != "LIST_DOMAINS" || list.Method != "GET" || list.Outcome != "flag" {
		t.Fatalf("list %+v", list)
	}
	if list.Params[0].PHP != "hideTemporaryDomains" || list.Params[0].Encoding != "bool" || list.Params[0].PHPType != "bool" {
		t.Fatalf("params %+v", list.Params)
	}
	if list.SeeURL != "https://api.docs.cpanel.net/specifications/cpanel.openapi/domain-information/domaininfo-list_domains" {
		t.Fatal(list.SeeURL)
	}
	if list.Description != "This function lists the account's domains." || list.Summary != "Return domains." {
		t.Fatalf("docs %q %q", list.Summary, list.Description)
	}
	if domain.Operations[1].Unwrap != "primary_domain" {
		t.Fatalf("primary %+v", domain.Operations[1])
	}

	accounts := groupByClass(t, result, "Accounts")
	create := accounts.Operations[0]
	if create.PHPName != "createAccount" || create.Method != "POST" || create.Subcategory != "Account Creation" {
		t.Fatalf("create %+v", create)
	}
	if create.Params[0].PHP != "username" || !create.Params[0].Required || !create.Params[1].Sensitive || create.Params[2].DocType != "'default'|'custom'" {
		t.Fatalf("create params %+v", create.Params)
	}
	listAccounts := accounts.Operations[1]
	uid := listAccounts.Returns.Fields[0].Spec.Items.Fields[0]
	if uid.Name != "uid" || uid.Spec.Accept[0] != "numeric-string" {
		t.Fatalf("correction not applied %+v", uid)
	}
	if len(result.Excluded) != 1 || result.Excluded[0].ID != "whm1:suspendacct" {
		t.Fatalf("excluded %+v", result.Excluded)
	}
}

func TestBuildRequiresExplicitNamesForSeparatorlessFunctions(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:listaccts"]
	op.Name = ""
	set.Files[1].Operations["whm1:listaccts"] = op
	result, err := Build(fixtureMap(t), set)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(result.Problems, "\n"), "whm1:listaccts: needs an explicit overlay name") {
		t.Fatalf("problems %v", result.Problems)
	}
}

func TestBuildReportsUnknownOverlayTargets(t *testing.T) {
	set := fixtureOverlay("listed")
	set.Files[0].Operations["uapi:DomainInfo/missing"] = overlay.Operation{}
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{"nonexistent": {PHP: "nonexistent"}}
	op.Response = map[string]overlay.Correction{"nope": {Type: "int", Evidence: evidence()}}
	set.Files[1].Operations["whm1:createacct"] = op
	set.Files[1].Group = "Wrong Group"
	result, err := Build(fixtureMap(t), set)
	if err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(result.Problems, "\n")
	for _, want := range []string{
		"uapi:DomainInfo/missing: not in the API map",
		"whm1:createacct: overlay parameter nonexistent is not documented",
		`whm1:createacct: path "nope": field "nope" is not documented`,
		`whm1:createacct: belongs to group "Accounts", not "Wrong Group"`,
	} {
		if !strings.Contains(joined, want) {
			t.Fatalf("missing %q in %s", want, joined)
		}
	}
}

func TestBuildRejectsPatternParametersUnlessOmitted(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:listaccts"]
	op.Params = nil
	set.Files[1].Operations["whm1:listaccts"] = op
	result, err := Build(fixtureMap(t), set)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(result.Problems, "\n"), "parameter MXCHECK-* is a name pattern") {
		t.Fatalf("problems %v", result.Problems)
	}

	omitted, err := Build(fixtureMap(t), fixtureOverlay("listed"))
	if err != nil || len(omitted.Problems) != 0 {
		t.Fatalf("omit not honoured: %v %v", err, omitted.Problems)
	}
	for _, group := range omitted.Groups {
		for _, operation := range group.Operations {
			if operation.ID == "whm1:listaccts" && len(operation.Params) != 0 {
				t.Fatalf("omitted parameter generated: %+v", operation.Params)
			}
		}
	}
}

func TestBuildHonoursParameterOrder(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Order = []string{"plan", "password"}
	set.Files[1].Operations["whm1:createacct"] = op
	result, err := Build(fixtureMap(t), set)
	if err != nil || len(result.Problems) != 0 {
		t.Fatalf("%v %v", err, result.Problems)
	}
	create := groupByClass(t, result, "Accounts").Operations[0]
	var names []string
	for _, p := range create.Params {
		names = append(names, p.Wire)
	}
	if strings.Join(names, ",") != "username,plan,password" {
		t.Fatalf("order %v", names)
	}
}

func TestBuildCarriesOptionalPayloadOnlyForDocumentedPayloads(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.OptionalPayload, op.Evidence = true, evidence()
	set.Files[1].Operations["whm1:createacct"] = op
	result, joined := buildProblems(t, fixtureMap(t), set)
	if joined != "" {
		t.Fatal(joined)
	}
	if create := groupByClass(t, result, "Accounts").Operations[0]; create.ID != "whm1:createacct" || !create.OptionalPayload || create.Returns == nil {
		t.Fatalf("optional payload not carried: %+v", create)
	}

	op.Void = true
	set.Files[1].Operations["whm1:createacct"] = op
	if _, joined := buildProblems(t, fixtureMap(t), set); !strings.Contains(joined, "whm1:createacct: optional_payload needs a documented payload") {
		t.Fatalf("optional payload on a void operation accepted: %s", joined)
	}
}

func TestBuildAllScopeNeedsWHMGroupAccessors(t *testing.T) {
	set := fixtureOverlay("all")
	set.Files = set.Files[:1]
	result, err := Build(fixtureMap(t), set)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(result.Problems, "\n"), `WHM group "Accounts" needs an overlay file with an accessor`) {
		t.Fatalf("problems %v", result.Problems)
	}
}

func TestApplyCorrectionAddsUndocumentedFieldsAndRequiredness(t *testing.T) {
	root := &TypeSpec{Type: "object", Fields: []Field{{Name: "a", Spec: TypeSpec{Type: "string"}}}}
	if err := applyCorrection(root, "b", overlay.Correction{Add: &overlay.Spec{Type: "int"}, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	required := true
	if err := applyCorrection(root, "a", overlay.Correction{Required: &required, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	if len(root.Fields) != 2 || root.Fields[1].Spec.Type != "int" || root.Required[0] != "a" {
		t.Fatalf("corrections %+v", root)
	}
	if err := applyCorrection(root, "a[]", overlay.Correction{Evidence: evidence()}); err == nil {
		t.Fatal("accepted a list suffix on a string")
	}
}

func TestSensitiveNameDecisions(t *testing.T) {
	cases := []struct {
		name string
		spec string
		want bool
	}{
		{"passphrase", "string", true},
		{"oldpass", "string", true},
		{"client_secret", "string", true},
		{"access_token", "string", true},
		{"PUSHBULLET_ACCESS_TOKEN", "string", true},
		{"pass_hash", "string", true},
		{"private_key", "string", true},
		{"token_name", "string", false},
		{"passwd", "int", false},
		{"notify_password_change", "flag", false},
		{"username", "string", false},
		// camelCase names split at case boundaries.
		{"promptToken", "string", true},
		{"newPassword", "string", true},
		{"tokenName", "string", false},
		// One-time codes documented as integers are secrets; other integers are not.
		{"tfa_token", "int", true},
		{"acl-passwd", "int", false},
		{"tfa_token", "flag", false},
	}
	for _, c := range cases {
		if got := sensitiveName(c.name, TypeSpec{Type: c.spec}); got != c.want {
			t.Errorf("%s (%s): got %v", c.name, c.spec, got)
		}
	}
}

// addQueryParameter documents an optional query parameter on a fixture operation.
func addQueryParameter(t *testing.T, m model.Map, id, name string, schema map[string]any) {
	t.Helper()
	for i := range m.Operations {
		if m.Operations[i].ID != id {
			continue
		}
		v := &m.Operations[i].Variants[0]
		v.Parameters = append(v.Parameters, model.Parameter{
			Name: name, In: "query",
			Definition: model.Object{"name": name, "in": "query", "required": false, "schema": schema},
			Locator:    model.Locator{SourceID: v.OperationLocator.SourceID},
		})
		return
	}
	t.Fatalf("fixture operation %s missing", id)
}

func TestBuildRequiresSensitiveDecisionsForKeyParameters(t *testing.T) {
	m := fixtureMap(t)
	addQueryParameter(t, m, "whm1:createacct", "key", map[string]any{"type": "string"})
	addQueryParameter(t, m, "whm1:createacct", "remote_access_key", map[string]any{"type": "string"})
	addQueryParameter(t, m, "whm1:createacct", "abort_on_existing_key", map[string]any{"type": "integer", "enum": []any{0, 1}})
	addQueryParameter(t, m, "whm1:createacct", "private_key", map[string]any{"type": "string"})
	_, joined := buildProblems(t, m, fixtureOverlay("listed"))
	for _, want := range []string{
		`whm1:createacct: parameter key may carry a key; declare "sensitive" true or false`,
		`whm1:createacct: parameter remote_access_key may carry a key; declare "sensitive" true or false`,
	} {
		if !strings.Contains(joined, want) {
			t.Errorf("missing %q in %s", want, joined)
		}
	}
	for _, unwanted := range []string{"parameter abort_on_existing_key", "parameter private_key"} {
		if strings.Contains(joined, unwanted) {
			t.Errorf("unexpected decision request %q in %s", unwanted, joined)
		}
	}

	yes, no := true, false
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{"key": {Sensitive: &no}, "remote_access_key": {Sensitive: &yes}}
	op.Evidence = evidence()
	set.Files[1].Operations["whm1:createacct"] = op
	result, joined := buildProblems(t, m, set)
	if joined != "" {
		t.Fatal(joined)
	}
	for _, p := range groupByClass(t, result, "Accounts").Operations[0].Params {
		if want := p.Wire == "remote_access_key" || p.Wire == "private_key" || p.Wire == "password"; p.Sensitive != want {
			t.Errorf("%s: sensitive %v", p.Wire, p.Sensitive)
		}
	}
}

func TestApplyCorrectionRejectsAddOnDocumentedField(t *testing.T) {
	root := &TypeSpec{Type: "object", Fields: []Field{{Name: "a", Spec: TypeSpec{Type: "string"}}}}
	err := applyCorrection(root, "a", overlay.Correction{Add: &overlay.Spec{Type: "int"}, Evidence: evidence()})
	if err == nil || !strings.Contains(err.Error(), `field "a" is documented; use type, accept or nullable instead of add`) {
		t.Fatalf("err %v", err)
	}
}

func TestBuildConstantFallsBackToPHPName(t *testing.T) {
	m := fixtureMap(t)
	b := &builder{problems: map[string]bool{}}
	op := m.Operations[0]
	op.ID, op.Function = "whm1:360Monitoring/register", "360Monitoring/register"
	if got := b.constant(op, "registerServer"); got != "REGISTER_SERVER" || len(b.problems) != 0 {
		t.Fatalf("%s %v", got, b.problems)
	}
	op.Function = "createacct"
	if got := b.constant(op, "createAccount"); got != "CREATEACCT" {
		t.Fatal(got)
	}
	op.Function = "360x"
	b.constant(op, "360x")
	if len(b.problems) != 1 {
		t.Fatalf("problems %v", b.problems)
	}
}

func finishProblems(groups map[string]*Group) string {
	b := &builder{problems: map[string]bool{}}
	b.finish(groups)
	return strings.Join(b.result.Problems, "\n")
}

func TestFinishReportsCaseInsensitiveCollisions(t *testing.T) {
	dup := finishProblems(map[string]*Group{
		"a": {Family: "uapi", Name: "DNSSEC", Accessor: "dnssec", Class: "Dns"},
		"b": {Family: "uapi", Name: "DNS", Accessor: "dns", Class: "DNS"},
	})
	if !strings.Contains(dup, "share class") {
		t.Fatal(dup)
	}
	methods := finishProblems(map[string]*Group{
		"a": {Family: "uapi", Name: "X", Accessor: "x", Class: "X", Operations: []Operation{
			{ID: "1", PHPName: "listIPs", Constant: "A"}, {ID: "2", PHPName: "listIps", Constant: "B"}}},
	})
	if !strings.Contains(methods, "duplicate method") {
		t.Fatal(methods)
	}
	run := finishProblems(map[string]*Group{"a": {Family: "uapi", Name: "Run", Accessor: "run", Class: "Run"}})
	if !strings.Contains(run, "reserved or invalid") {
		t.Fatal(run)
	}
}

// fixtureNode returns the fixture map's operation object for id, so a test can
// add upstream members such as requestBody or deprecated.
func fixtureNode(t *testing.T, m model.Map, id string) map[string]any {
	t.Helper()
	loc := operationByID(t, m, id).Variants[0].OperationLocator
	node, err := source.Pointer(m.Documents[loc.SourceID], *loc.Pointer)
	if err != nil {
		t.Fatal(err)
	}
	object, ok := asObject(node)
	if !ok {
		t.Fatalf("%s is not an object", id)
	}
	return object
}

func generatedIDs(result Result) map[string]bool {
	ids := map[string]bool{}
	for _, group := range result.Groups {
		for _, op := range group.Operations {
			ids[op.ID] = true
		}
	}
	return ids
}

func buildProblems(t *testing.T, m model.Map, set overlay.Set) (Result, string) {
	t.Helper()
	result, err := Build(m, set)
	if err != nil {
		t.Fatal(err)
	}
	return result, strings.Join(result.Problems, "\n")
}

func TestBuildRejectsDocumentedRequestBodies(t *testing.T) {
	m := fixtureMap(t)
	body := map[string]any{"content": map[string]any{"multipart/form-data": map[string]any{"schema": map[string]any{"type": "object"}}}}
	fixtureNode(t, m, "whm1:createacct")["requestBody"] = body
	fixtureNode(t, m, "whm1:suspendacct")["requestBody"] = body
	result, joined := buildProblems(t, m, fixtureOverlay("listed"))
	want := "whm1:createacct: documents a request body that is not form-encoded; exclude it until such bodies are supported"
	if !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}
	if generatedIDs(result)["whm1:createacct"] {
		t.Fatal("operation with a request body was generated")
	}
	if strings.Contains(joined, "whm1:suspendacct") {
		t.Fatalf("excluded operation reported: %s", joined)
	}
}

func TestBuildReportsDeprecatedOperations(t *testing.T) {
	m := fixtureMap(t)
	fixtureNode(t, m, "whm1:listaccts")["deprecated"] = true
	fixtureNode(t, m, "whm1:suspendacct")["deprecated"] = true
	_, joined := buildProblems(t, m, fixtureOverlay("listed"))
	if want := "whm1:listaccts: flagged deprecated upstream; exclude it"; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}
	if strings.Contains(joined, "whm1:suspendacct") {
		t.Fatalf("excluded operation reported: %s", joined)
	}
}

func TestBuildRejectsReservedArgumentNames(t *testing.T) {
	set := fixtureOverlay("listed")
	op := set.Files[1].Operations["whm1:createacct"]
	op.Params = map[string]overlay.Param{"username": {PHP: "result"}, "plan": {PHP: "this"}}
	set.Files[1].Operations["whm1:createacct"] = op
	_, joined := buildProblems(t, fixtureMap(t), set)
	for _, want := range []string{
		"whm1:createacct: parameter username needs an overlay php name",
		"whm1:createacct: parameter plan needs an overlay php name",
	} {
		if !strings.Contains(joined, want) {
			t.Fatalf("missing %q in %s", want, joined)
		}
	}
}

func TestBuildReportsUndocumentedGroupFiles(t *testing.T) {
	set := fixtureOverlay("listed")
	set.Files = append(set.Files,
		overlay.File{Family: "uapi", Group: "Nonexistent", Path: "uapi/Nonexistent.json", Operations: map[string]overlay.Operation{}},
		overlay.File{Family: "uapi", Group: "Accounts", Path: "uapi/Accounts.json", Operations: map[string]overlay.Operation{}},
		overlay.File{Family: "whm1", Group: "Imaginary", Accessor: "imaginary", Path: "whm/Imaginary.json", Operations: map[string]overlay.Operation{}},
	)
	_, joined := buildProblems(t, fixtureMap(t), set)
	for _, want := range []string{
		`uapi/Nonexistent.json: group "Nonexistent" is not a documented uapi group`,
		`uapi/Accounts.json: group "Accounts" is not a documented uapi group`,
		`whm/Imaginary.json: group "Imaginary" is not a documented whm1 group`,
	} {
		if !strings.Contains(joined, want) {
			t.Fatalf("missing %q in %s", want, joined)
		}
	}
	if strings.Contains(joined, "DomainInfo.json") || strings.Contains(joined, "whm/Accounts.json") {
		t.Fatalf("documented groups reported: %s", joined)
	}

	// An exclusion-only file for a documented group stays valid.
	exclusionOnly := fixtureOverlay("listed")
	exclusionOnly.Files[0].Operations = map[string]overlay.Operation{
		"uapi:DomainInfo/list_domains": {Exclude: &overlay.Exclusion{Reason: "Later.", Evidence: evidence()}},
	}
	if _, joined := buildProblems(t, fixtureMap(t), exclusionOnly); joined != "" {
		t.Fatalf("exclusion-only group file rejected: %s", joined)
	}
}

// addSecretParameter documents a password parameter on the read-only
// uapi:DomainInfo/list_domains fixture operation.
func addSecretParameter(t *testing.T, m model.Map) {
	t.Helper()
	for i := range m.Operations {
		if m.Operations[i].ID != "uapi:DomainInfo/list_domains" {
			continue
		}
		v := &m.Operations[i].Variants[0]
		v.Parameters = append(v.Parameters, model.Parameter{
			Name: "password", In: "query",
			Definition: model.Object{"name": "password", "in": "query", "required": false, "schema": map[string]any{"type": "string"}},
			Locator:    v.Parameters[0].Locator,
		})
		return
	}
	t.Fatal("fixture operation missing")
}

func TestBuildSendsSecretBearingReadOnlyOperationsByPost(t *testing.T) {
	m := fixtureMap(t)
	addSecretParameter(t, m)
	result, joined := buildProblems(t, m, fixtureOverlay("listed"))
	if joined != "" {
		t.Fatal(joined)
	}
	list := groupByClass(t, result, "DomainInfo").Operations[0]
	if list.ID != "uapi:DomainInfo/list_domains" || list.Method != "POST" || !list.Params[1].Sensitive {
		t.Fatalf("read-only operation with a secret %+v", list)
	}

	set := fixtureOverlay("listed")
	op := set.Files[0].Operations["uapi:DomainInfo/list_domains"]
	op.Method, op.Evidence = "GET", evidence()
	set.Files[0].Operations["uapi:DomainInfo/list_domains"] = op
	if problems := overlay.Validate(set); len(problems) != 0 {
		t.Fatalf("evidence rule should be satisfied: %v", problems)
	}
	if _, joined := buildProblems(t, m, set); !strings.Contains(joined, "uapi:DomainInfo/list_domains: secret-bearing parameters require POST") {
		t.Fatalf("GET override accepted: %s", joined)
	}
}

func TestApplyCorrectionRejectsEmptyPath(t *testing.T) {
	root := &TypeSpec{Type: "object", Fields: []Field{{Name: "a", Spec: TypeSpec{Type: "string"}}}}
	yes := true
	err := applyCorrection(root, "", overlay.Correction{Nullable: &yes, Evidence: evidence()})
	if err == nil || err.Error() != `path "" is not a field path` {
		t.Fatalf("err %v", err)
	}
	if root.Nullable {
		t.Fatal("empty path changed the payload root")
	}
}

func TestApplyCorrectionChecksAcceptAndPreserveAgainstType(t *testing.T) {
	documented := func() *TypeSpec {
		return &TypeSpec{Type: "object", Fields: []Field{
			{Name: "b", Spec: TypeSpec{Type: "bool"}},
			{Name: "f", Spec: TypeSpec{Type: "flag"}},
			{Name: "i", Spec: TypeSpec{Type: "int"}},
			{Name: "m", Spec: TypeSpec{Type: "mixed"}},
			{Name: "n", Spec: TypeSpec{Type: "number"}},
			{Name: "s", Spec: TypeSpec{Type: "string"}},
		}}
	}
	for _, c := range []struct {
		path       string
		correction overlay.Correction
	}{
		{"i", overlay.Correction{Accept: []string{"numeric-string"}}},
		{"n", overlay.Correction{Accept: []string{"numeric-string"}, Preserve: true}},
		{"b", overlay.Correction{Accept: []string{"int-flag", "string-flag"}}},
		{"f", overlay.Correction{Accept: []string{"string-flag", "bool"}}},
		{"s", overlay.Correction{Type: "number", Accept: []string{"numeric-string"}, Preserve: true}},
		{"x", overlay.Correction{Add: &overlay.Spec{Type: "int", Accept: []string{"numeric-string"}}}},
	} {
		c.correction.Evidence = evidence()
		if err := applyCorrection(documented(), c.path, c.correction); err != nil {
			t.Errorf("%s %+v: %v", c.path, c.correction, err)
		}
	}
	for _, c := range []struct {
		path       string
		correction overlay.Correction
		want       string
	}{
		{"s", overlay.Correction{Accept: []string{"numeric-string"}}, `path "s": accept "numeric-string" does not apply to type "string"`},
		{"i", overlay.Correction{Accept: []string{"int-flag"}}, `path "i": accept "int-flag" does not apply to type "int"`},
		{"f", overlay.Correction{Accept: []string{"numeric-string"}}, `path "f": accept "numeric-string" does not apply to type "flag"`},
		{"b", overlay.Correction{Accept: []string{"bool"}}, `path "b": accept "bool" does not apply to type "bool"`},
		{"m", overlay.Correction{Accept: []string{"string-flag"}}, `path "m": accept "string-flag" does not apply to type "mixed"`},
		{"i", overlay.Correction{Type: "string", Accept: []string{"numeric-string"}}, `path "i": accept "numeric-string" does not apply to type "string"`},
		{"i", overlay.Correction{Preserve: true}, `path "i": preserve does not apply to type "int"`},
		{"x", overlay.Correction{Add: &overlay.Spec{Type: "object", Fields: map[string]overlay.Spec{
			"y": {Type: "string", Accept: []string{"int-flag"}}}}}, `path "x.y": accept "int-flag" does not apply to type "string"`},
	} {
		c.correction.Evidence = evidence()
		err := applyCorrection(documented(), c.path, c.correction)
		if err == nil || err.Error() != c.want {
			t.Errorf("%s %+v: got %v, want %s", c.path, c.correction, err, c.want)
		}
	}
}

func TestApplyCorrectionReplaceRefinesDocumentedNodes(t *testing.T) {
	documented := func() *TypeSpec {
		return &TypeSpec{Type: "object", Fields: []Field{
			{Name: "data", Spec: TypeSpec{Type: "map", Nullable: true, Values: &TypeSpec{Type: "mixed"}}},
			{Name: "rows", Spec: TypeSpec{Type: "list", Items: &TypeSpec{Type: "mixed"}}},
		}}
	}
	root := documented()
	replacement := overlay.Spec{Type: "object", Fields: map[string]overlay.Spec{
		"count": {Type: "int", Accept: []string{"numeric-string"}},
		"names": {Type: "list", Items: &overlay.Spec{Type: "string"}},
	}, Required: []string{"count"}}
	if err := applyCorrection(root, "data", overlay.Correction{Replace: &replacement, Evidence: evidence()}); err != nil {
		t.Fatal(err)
	}
	data := root.Fields[0].Spec
	if data.Type != "object" || data.Nullable || data.Values != nil || len(data.Fields) != 2 || data.Fields[0].Name != "count" ||
		data.Fields[0].Spec.Accept[0] != "numeric-string" || data.Fields[1].Spec.Items.Type != "string" || data.Required[0] != "count" {
		t.Fatalf("replace kept documented details or lost overlay ones: %+v", data)
	}
	err := applyCorrection(root, "rows[]", overlay.Correction{Replace: &overlay.Spec{Type: "string"}, Evidence: evidence()})
	if err != nil || root.Fields[1].Spec.Items.Type != "string" {
		t.Fatalf("replace on list items: %v %+v", err, root.Fields[1].Spec)
	}
	for _, c := range []struct {
		path       string
		correction overlay.Correction
		want       string
	}{
		{"data", overlay.Correction{Replace: &overlay.Spec{Type: "string"}, Type: "int"}, `path "data": replace cannot be combined with type or add`},
		{"extra", overlay.Correction{Replace: &overlay.Spec{Type: "string"}, Add: &overlay.Spec{Type: "string"}}, `path "extra": replace cannot be combined with type or add`},
		{"missing", overlay.Correction{Replace: &overlay.Spec{Type: "string"}}, `path "missing": field "missing" is not documented`},
		{"data", overlay.Correction{Replace: &overlay.Spec{Type: "list"}}, `path "data": list needs items`},
		{"data", overlay.Correction{Replace: &overlay.Spec{Type: "map"}}, `path "data": map needs values`},
		{"data", overlay.Correction{Replace: &overlay.Spec{Type: "union"}}, `path "data": union needs anyOf`},
		{"data", overlay.Correction{Replace: &overlay.Spec{Type: "object"}}, `path "data": object needs fields`},
		{"data", overlay.Correction{Replace: &overlay.Spec{Type: "date"}}, `path "data": unknown type "date"`},
		{"extra", overlay.Correction{Add: &overlay.Spec{Type: "list"}}, `path "extra": list needs items`},
		{"extra", overlay.Correction{Add: &overlay.Spec{Type: "map", Values: &overlay.Spec{Type: "map"}}}, `path "extra{}": map needs values`},
		{"data", overlay.Correction{Type: "list"}, `path "data": type "list" is not a scalar type; use replace`},
	} {
		c.correction.Evidence = evidence()
		err := applyCorrection(documented(), c.path, c.correction)
		if err == nil || err.Error() != c.want {
			t.Errorf("%s %+v: got %v, want %s", c.path, c.correction, err, c.want)
		}
	}
}

func TestBuildLinksCanonicalDocumentationPages(t *testing.T) {
	result, joined := buildProblems(t, fixtureMap(t), fixtureOverlay("listed"))
	if joined != "" {
		t.Fatal(joined)
	}
	want := map[string]string{
		"uapi:DomainInfo/primary_domain": "https://api.docs.cpanel.net/specifications/cpanel.openapi/domain-information/domaininfo-primary_domain",
		"whm1:createacct":                "https://api.docs.cpanel.net/specifications/whm.openapi/account-creation/accounts-createacct",
		"whm1:listaccts":                 "https://api.docs.cpanel.net/specifications/whm.openapi/account-management/accounts-listaccts",
	}
	for _, group := range result.Groups {
		for _, op := range group.Operations {
			if url, ok := want[op.ID]; ok && op.SeeURL != url {
				t.Errorf("%s: got %s, want %s", op.ID, op.SeeURL, url)
			}
		}
	}
}

func TestDocumentationSlugKeepsParentheses(t *testing.T) {
	for tag, want := range map[string]string{
		"Spam Prevention (BoxTrapper)": "spam-prevention-(boxtrapper)",
		"Plugin-Based Features":        "plugin-based-features",
		"360 Monitoring":               "360-monitoring",
		"PHP":                          "php",
	} {
		if got, ok := docSlug(tag); !ok || got != want {
			t.Errorf("docSlug(%q) = %q %v, want %q", tag, got, ok, want)
		}
	}
	if _, ok := docSlug("Mail & Spam"); ok {
		t.Error("accepted a tag outside the verified slug alphabet")
	}
}

func TestBuildReportsUnlinkableOperations(t *testing.T) {
	m := fixtureMap(t)
	fixtureNode(t, m, "whm1:listaccts")["tags"] = []any{"Accounts"}
	for i := range m.Operations {
		if m.Operations[i].ID == "whm1:createacct" {
			m.Operations[i].Variants[0].UpstreamOperationID = nil
		}
	}
	_, joined := buildProblems(t, m, fixtureOverlay("listed"))
	for _, want := range []string{
		"whm1:listaccts: no tag in x-tagGroups to derive the documentation link from",
		"whm1:createacct: no upstream operation id to derive the documentation link from",
	} {
		if !strings.Contains(joined, want) {
			t.Errorf("missing %q in %s", want, joined)
		}
	}
}
