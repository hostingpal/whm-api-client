package effective

import (
	"reflect"
	"testing"
)

func TestWordsSplitSeparatorsCaseAndAcronyms(t *testing.T) {
	cases := map[string][]string{
		"php_get_installed_versions": {"php", "get", "installed", "versions"},
		"LangPHP":                    {"Lang", "PHP"},
		"DNSSEC":                     {"DNSSEC"},
		"DomainInfo":                 {"Domain", "Info"},
		"listaccts":                  {"listaccts"},
		"Web Server Configuration":   {"Web", "Server", "Configuration"},
		"360Monitoring/callback":     {"360", "Monitoring", "callback"},
		"EA4":                        {"EA4"},
	}
	for input, want := range cases {
		if got := Words(input); !reflect.DeepEqual(got, want) {
			t.Errorf("Words(%q) = %v, want %v", input, got, want)
		}
	}
}

func TestNameForms(t *testing.T) {
	if got := Camel("hide_temporary_domains"); got != "hideTemporaryDomains" {
		t.Fatal(got)
	}
	if got := Camel("LangPHP"); got != "langPhp" {
		t.Fatal(got)
	}
	if got := Pascal("LangPHP"); got != "LangPhp" {
		t.Fatal(got)
	}
	if got := Pascal("DNS"); got != "Dns" {
		t.Fatal(got)
	}
	if got := Constant("php_get_handlers"); got != "PHP_GET_HANDLERS" {
		t.Fatal(got)
	}
	if got := Constant("listaccts"); got != "LISTACCTS" {
		t.Fatal(got)
	}
	if !IsMethodName("listAccounts") || IsMethodName("ListAccounts") || IsMethodName("360monitoring") {
		t.Fatal("method name rule")
	}
	if !IsClassName("DomainInfo") || IsClassName("domainInfo") {
		t.Fatal("class name rule")
	}
}
