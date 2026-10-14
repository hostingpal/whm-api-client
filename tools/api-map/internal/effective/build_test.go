package effective

import (
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
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
	if list.SeeURL != "https://api.docs.cpanel.net/openapi/cpanel/operation/list_domains" {
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
