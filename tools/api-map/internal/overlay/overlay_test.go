package overlay

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const validWHM = `{
  "schema_version": "1.0",
  "family": "whm1",
  "group": "Accounts",
  "accessor": "accounts",
  "operations": {
    "whm1:listaccts": {
      "name": "listAccounts",
      "response": {
        "acct[].uid": {
          "accept": ["numeric-string"],
          "evidence": [{"kind": "live", "version": "11.138.0.15", "date": "2026-10-14", "note": "uid returned as a decimal string"}]
        }
      }
    }
  }
}`

func writeOverlay(t *testing.T, scope string, files map[string]string) string {
	t.Helper()
	dir := t.TempDir()
	for _, family := range []string{"uapi", "whm"} {
		if err := os.MkdirAll(filepath.Join(dir, family), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	config := `{"schema_version":"1.0","scope":"` + scope + `"}`
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(config), 0o600); err != nil {
		t.Fatal(err)
	}
	for name, content := range files {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	return dir
}

func TestLoadReadsConfigAndGroupFiles(t *testing.T) {
	set, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": validWHM}))
	if err != nil {
		t.Fatal(err)
	}
	if set.Config.Scope != "listed" || len(set.Files) != 1 {
		t.Fatalf("unexpected set %+v", set)
	}
	op := set.Files[0].Operations["whm1:listaccts"]
	if op.Name != "listAccounts" || op.Response["acct[].uid"].Accept[0] != "numeric-string" {
		t.Fatalf("unexpected operation %+v", op)
	}
	if problems := Validate(set); len(problems) != 0 {
		t.Fatalf("unexpected problems %v", problems)
	}
}

func TestLoadRejectsSchemaViolations(t *testing.T) {
	for name, content := range map[string]string{
		"unknown field":    strings.Replace(validWHM, `"accessor"`, `"unknown": 1, "accessor"`, 1),
		"missing evidence": strings.Replace(validWHM, `"evidence": [{"kind": "live", "version": "11.138.0.15", "date": "2026-10-14", "note": "uid returned as a decimal string"}]`, `"evidence": []`, 1),
		"duplicate key":    strings.Replace(validWHM, `"group": "Accounts",`, `"group": "Accounts", "group": "Accounts",`, 1),
		"case variant":     strings.Replace(validWHM, `"accessor"`, `"ACCESSOR"`, 1),
		"bad method name":  strings.Replace(validWHM, `"listAccounts"`, `"ListAccounts"`, 1),
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
				t.Fatal("accepted invalid overlay")
			}
		})
	}
}

func TestLoadRejectsInvalidConfig(t *testing.T) {
	if _, err := Load(writeOverlay(t, "everything", nil)); err == nil {
		t.Fatal("accepted invalid scope")
	}
}

func TestValidateReportsStructuralProblems(t *testing.T) {
	noAccessor := strings.Replace(validWHM, `"accessor": "accounts",`, ``, 1)
	set, err := Load(writeOverlay(t, "listed", map[string]string{
		"whm/Accounts.json": noAccessor,
		"whm/Other.json":    strings.Replace(validWHM, `"group": "Accounts"`, `"group": "Other"`, 1),
		"uapi/Wrong.json":   validWHM,
	}))
	if err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(Validate(set), "\n")
	for _, want := range []string{"need an accessor", "appears in", "does not match its directory"} {
		if !strings.Contains(joined, want) {
			t.Fatalf("missing %q in %s", want, joined)
		}
	}
}

func writeRawConfig(t *testing.T, config string) string {
	t.Helper()
	dir := t.TempDir()
	for _, family := range []string{"uapi", "whm"} {
		if err := os.MkdirAll(filepath.Join(dir, family), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(filepath.Join(dir, "config.json"), []byte(config), 0o600); err != nil {
		t.Fatal(err)
	}
	return dir
}

func TestLoadRejectsNonExactConfigKeys(t *testing.T) {
	for name, config := range map[string]string{
		"case variant key":       `{"schema_version":"1.0","SCOPE":"all"}`,
		"mixed case duplicate":   `{"schema_version":"1.0","scope":"listed","Scope":"all"}`,
		"extra key":              `{"schema_version":"1.0","scope":"listed","extra":true}`,
		"trailing data":          `{"schema_version":"1.0","scope":"listed"} {}`,
		"non-string scope value": `{"schema_version":"1.0","scope":1}`,
		"missing scope":          `{"schema_version":"1.0"}`,
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := Load(writeRawConfig(t, config)); err == nil {
				t.Fatalf("accepted config %s", config)
			}
		})
	}
}

const evidenceJSON = `[{"kind": "doc", "version": "11.138.0.15", "date": "2026-10-14", "note": "documented behaviour"}]`

func loadWHM(t *testing.T, content string) Set {
	t.Helper()
	set, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content}))
	if err != nil {
		t.Fatal(err)
	}
	return set
}

func TestValidateRequiresEvidenceForBehaviourOverrides(t *testing.T) {
	for name, c := range map[string]struct{ setting, want string }{
		"method":           {`"method": "POST"`, "whm1:listaccts: method needs evidence"},
		"outcome":          {`"outcome": "unchecked"`, "whm1:listaccts: outcome needs evidence"},
		"void":             {`"void": true`, "whm1:listaccts: void needs evidence"},
		"optional payload": {`"optional_payload": true`, "whm1:listaccts: optional_payload needs evidence"},
		"sensitive false":  {`"params": {"search": {"sensitive": false}}`, "whm1:listaccts: sensitive false on parameter search needs evidence"},
		"encoding":         {`"params": {"search": {"encoding": "repeat"}}`, "whm1:listaccts: encoding on parameter search needs evidence"},
		"required false":   {`"params": {"search": {"required": false}}`, "whm1:listaccts: required on parameter search needs evidence"},
		"required true":    {`"params": {"search": {"required": true}}`, "whm1:listaccts: required on parameter search needs evidence"},
		"variant":          {`"variant": "POST"`, "whm1:listaccts: variant needs evidence"},
	} {
		t.Run(name, func(t *testing.T) {
			without := strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", `+c.setting+`,`, 1)
			joined := strings.Join(Validate(loadWHM(t, without)), "\n")
			if !strings.Contains(joined, c.want) {
				t.Fatalf("missing %q in %s", c.want, joined)
			}
			with := strings.Replace(without, `"name": "listAccounts",`, `"name": "listAccounts", "evidence": `+evidenceJSON+`,`, 1)
			if problems := Validate(loadWHM(t, with)); len(problems) != 0 {
				t.Fatalf("evidence not honoured: %v", problems)
			}
		})
	}
	sensitive := strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "params": {"search": {"sensitive": true}},`, 1)
	if problems := Validate(loadWHM(t, sensitive)); len(problems) != 0 {
		t.Fatalf("sensitive true needs no evidence: %v", problems)
	}
}

func TestValidateKeepsOptionalPayloadOffVoidAndExcludedOperations(t *testing.T) {
	void := strings.Replace(validWHM, `"name": "listAccounts",`,
		`"name": "listAccounts", "void": true, "optional_payload": true, "evidence": `+evidenceJSON+`,`, 1)
	if joined := strings.Join(Validate(loadWHM(t, void)), "\n"); !strings.Contains(joined, "whm1:listaccts: void and optional_payload are exclusive") {
		t.Fatalf("void with optional_payload accepted: %s", joined)
	}
	excluded := strings.Replace(validWHM, `"whm1:listaccts": {`,
		`"whm1:createacct": {"optional_payload": true, "evidence": `+evidenceJSON+`, "exclude": {"reason": "r", "evidence": `+evidenceJSON+`}}, "whm1:listaccts": {`, 1)
	if joined := strings.Join(Validate(loadWHM(t, excluded)), "\n"); !strings.Contains(joined, "whm1:createacct: excluded operations take no other settings") {
		t.Fatalf("excluded operation with optional_payload accepted: %s", joined)
	}
}

func TestValidateReportsDuplicateGroupFiles(t *testing.T) {
	second := strings.Replace(validWHM, `"whm1:listaccts"`, `"whm1:createacct"`, 1)
	set, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": validWHM, "whm/MoreAccounts.json": second}))
	if err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(Validate(set), "\n")
	if want := `group "Accounts" appears in whm/Accounts.json and whm/MoreAccounts.json`; !strings.Contains(joined, want) {
		t.Fatalf("missing %q in %s", want, joined)
	}
}

func TestLoadRequiresTypeSpecCompanionKeys(t *testing.T) {
	correction := func(key, spec string) string {
		return strings.Replace(validWHM, `"accept": ["numeric-string"],`, `"`+key+`": `+spec+`,`, 1)
	}
	for name, content := range map[string]string{
		"list without items":         correction("add", `{"type": "list"}`),
		"map without values":         correction("add", `{"type": "map"}`),
		"union without anyOf":        correction("add", `{"type": "union"}`),
		"object without fields":      correction("add", `{"type": "object"}`),
		"nested list without items":  correction("add", `{"type": "object", "fields": {"a": {"type": "list"}}}`),
		"replace map without values": correction("replace", `{"type": "map"}`),
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content})); err == nil {
				t.Fatal("accepted a type spec without its companion key")
			}
		})
	}
	for name, content := range map[string]string{
		"list with items":  correction("add", `{"type": "list", "items": {"type": "string"}}`),
		"empty object":     correction("add", `{"type": "object", "fields": {}}`),
		"replace with map": correction("replace", `{"type": "map", "values": {"type": "int", "accept": ["numeric-string"]}}`),
		"union with anyOf": correction("add", `{"type": "union", "anyOf": [{"type": "string"}, {"type": "int"}]}`),
		"replace scalar":   correction("replace", `{"type": "int"}`),
	} {
		t.Run(name, func(t *testing.T) {
			set, err := Load(writeOverlay(t, "listed", map[string]string{"whm/Accounts.json": content}))
			if err != nil {
				t.Fatal(err)
			}
			c := set.Files[0].Operations["whm1:listaccts"].Response["acct[].uid"]
			if c.Add == nil && c.Replace == nil {
				t.Fatalf("spec not decoded: %+v", c)
			}
		})
	}
}

func TestSuffixNumberedEncodingRequiresOneTerminalWildcard(t *testing.T) {
	for _, encoding := range []string{"suffix-numbered", "suffix-numbered-scalar"} {
		for _, wire := range []string{"filter*", "action*", "dest*", "match*", "opt*", "part*", "val*"} {
			content := strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "params": {"`+wire+`": {"encoding": "`+encoding+`"}}, "evidence": `+evidenceJSON+`,`, 1)
			if problems := Validate(loadWHM(t, content)); len(problems) != 0 {
				t.Fatal(problems)
			}
		}
		for _, wire := range []string{"filter", "*", "filter**", "filter*x", "filter-1*", "filter.*", "1filter*"} {
			content := strings.Replace(validWHM, `"name": "listAccounts",`, `"name": "listAccounts", "params": {"`+wire+`": {"encoding": "`+encoding+`"}}, "evidence": `+evidenceJSON+`,`, 1)
			if problems := Validate(loadWHM(t, content)); len(problems) == 0 {
				t.Fatalf("accepted %s on %s", encoding, wire)
			}
		}
	}
}
