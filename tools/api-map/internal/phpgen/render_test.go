package phpgen

import (
	"flag"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

var update = flag.Bool("update", false, "rewrite golden files")

func goldenResult() effective.Result {
	domainList := effective.TypeSpec{Type: "object", Fields: []effective.Field{
		{Name: "is_temporary", Spec: effective.TypeSpec{Type: "map", Values: &effective.TypeSpec{Type: "flag"}}},
		{Name: "main_domain", Spec: effective.TypeSpec{Type: "string", Nullable: true}},
		{Name: "sub_domains", Spec: effective.TypeSpec{Type: "list", Items: &effective.TypeSpec{Type: "string"}}},
	}, Required: []string{"main_domain"}}
	primary := effective.TypeSpec{Type: "object", Fields: []effective.Field{
		{Name: "primary_domain", Spec: effective.TypeSpec{Type: "string", Nullable: true}},
	}}
	created := effective.TypeSpec{Type: "object", Fields: []effective.Field{
		{Name: "uid", Spec: effective.TypeSpec{Type: "int", Accept: []string{"numeric-string"}}},
	}}
	return effective.Result{
		Groups: []effective.Group{
			{Family: "uapi", Name: "DomainInfo", Accessor: "domainInfo", Class: "DomainInfo", Description: "Retrieve domain information.", Operations: []effective.Operation{
				{ID: "uapi:DomainInfo/list_domains", VariantID: "uapi:DomainInfo/list_domains#GET", Family: "uapi", Module: "DomainInfo", Function: "list_domains", Method: "GET", Outcome: "flag",
					PHPName: "listDomains", Constant: "LIST_DOMAINS", Alias: "ListDomainsResult", Summary: "Return domains.", Description: "This function lists the account's domains.",
					SeeURL: "https://api.docs.cpanel.net/specifications/cpanel.openapi/domain-information/domaininfo-list_domains", Notes: []effective.Note{{Label: "Observed", Text: "is_temporary can be omitted."}},
					Params:  []effective.Param{{Wire: "hide_temporary_domains", PHP: "hideTemporaryDomains", Encoding: "bool", PHPType: "bool", DocType: "bool", Description: "Whether to hide temporary domains."}},
					Returns: &domainList},
				{ID: "uapi:DomainInfo/primary_domain", VariantID: "uapi:DomainInfo/primary_domain#GET", Family: "uapi", Module: "DomainInfo", Function: "primary_domain", Method: "GET", Outcome: "flag",
					PHPName: "primaryDomain", Constant: "PRIMARY_DOMAIN", Alias: "PrimaryDomainResult", Summary: "Return primary domain.",
					SeeURL: "https://api.docs.cpanel.net/specifications/cpanel.openapi/domain-information/domaininfo-primary_domain", Returns: &primary, Unwrap: "primary_domain"},
			}},
			{Family: "whm1", Name: "Accounts", Accessor: "accounts", Class: "Accounts", Description: "WHM API 1 functions in the documented Accounts group.", Operations: []effective.Operation{
				{ID: "whm1:createacct", VariantID: "whm1:createacct#GET", Family: "whm1", Function: "createacct", Method: "POST", Outcome: "flag",
					PHPName: "createAccount", Constant: "CREATEACCT", Alias: "CreateAccountResult", Summary: "Create cPanel account.", Subcategory: "Account Creation", Notes: []effective.Note{{Label: "Documented", Text: "You must use either the username or the domain parameter."}},
					SeeURL: "https://api.docs.cpanel.net/specifications/whm.openapi/account-creation/accounts-createacct",
					Params: []effective.Param{
						{Wire: "username", PHP: "username", Encoding: "scalar", PHPType: "string", DocType: "string", Description: "The account's username.", Required: true},
						{Wire: "password", PHP: "password", Encoding: "scalar", PHPType: "string", DocType: "string", Description: "The password.", Sensitive: true},
						{Wire: "plan", PHP: "plan", Encoding: "scalar", PHPType: "string", DocType: "'default'|'custom'"},
					},
					Returns: &created, OptionalPayload: true},
				{ID: "whm1:suspendacct", VariantID: "whm1:suspendacct#GET", Family: "whm1", Function: "suspendacct", Method: "POST", Outcome: "flag",
					PHPName: "suspendAccount", Constant: "SUSPENDACCT", Alias: "SuspendAccountResult", Summary: "Suspend account.", Subcategory: "Account Management",
					SeeURL: "https://api.docs.cpanel.net/specifications/whm.openapi/account-management/accounts-suspendacct",
					Params: []effective.Param{{Wire: "user", PHP: "users", Encoding: "numbered", PHPType: "string|array", DocType: "string|list<string>", Required: true}}},
			}},
		},
		Excluded: []effective.Exclusion{{ID: "whm1:accesshash", Reason: "Deprecated; replaced by API tokens."}},
	}
}

func TestRenderMatchesGoldenFiles(t *testing.T) {
	files := Render(goldenResult())
	paths := make([]string, 0, len(files))
	for path := range files {
		paths = append(paths, path)
	}
	sort.Strings(paths)
	want := []string{
		"docs/api/coverage.md",
		"src/Api/Uapi/DomainInfo.php",
		"src/Api/Uapi/Spec/DomainInfoSpec.php",
		"src/Api/Uapi/UapiGroups.php",
		"src/Api/Whm/Accounts.php",
		"src/Api/Whm/Spec/AccountsSpec.php",
		"src/Api/Whm/WhmGroups.php",
	}
	if strings.Join(paths, "\n") != strings.Join(want, "\n") {
		t.Fatalf("paths %v", paths)
	}
	for _, path := range paths {
		golden := filepath.Join("testdata", "golden", filepath.FromSlash(path))
		if *update {
			if err := os.MkdirAll(filepath.Dir(golden), 0o755); err != nil {
				t.Fatal(err)
			}
			if err := os.WriteFile(golden, files[path], 0o644); err != nil {
				t.Fatal(err)
			}
			continue
		}
		expected, err := os.ReadFile(golden)
		if err != nil {
			t.Fatalf("missing golden %s (run with -update and review): %v", golden, err)
		}
		if string(expected) != string(files[path]) {
			t.Fatalf("%s differs from golden output:\n%s", path, files[path])
		}
	}
}

func TestRenderIsDeterministicAndBounded(t *testing.T) {
	first := Render(goldenResult())
	second := Render(goldenResult())
	for path, content := range first {
		if string(second[path]) != string(content) {
			t.Fatalf("%s is not deterministic", path)
		}
		if strings.HasSuffix(path, ".php") {
			for i, line := range strings.Split(string(content), "\n") {
				if len(line) > lineWidth {
					t.Fatalf("%s:%d exceeds %d characters", path, i+1, lineWidth)
				}
			}
			if !strings.Contains(string(content), Marker) {
				t.Fatalf("%s lacks the generated marker", path)
			}
		}
	}
}
