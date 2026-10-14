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
