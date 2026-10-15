package cli

import (
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/phpgen"
)

// TestStressRenderFullSurface renders every operation of the pinned map (scope
// "all") with the repository overlay into API_MAP_STRESS_DIR/out, writing a
// summary and every problem to problems.txt, so the full surface can be linted
// and analysed. Operations with a problem other than a missing curated name
// are left out, as a real run would not generate them. It is opt-in, writes
// only below API_MAP_STRESS_DIR and never runs in ordinary checks.
func TestStressRenderFullSurface(t *testing.T) {
	dir := os.Getenv("API_MAP_STRESS_DIR")
	if dir == "" {
		t.Skip("set API_MAP_STRESS_DIR to render the full surface")
	}
	raw, err := os.ReadFile(pinnedParityMap(t))
	if err != nil {
		t.Fatal(err)
	}
	m, err := model.DecodeMap(raw)
	if err != nil {
		t.Fatal(err)
	}
	set, err := overlay.Load("../../../../docs/api/overlay")
	if err != nil {
		t.Fatal(err)
	}
	set.Config.Scope = "all"
	result, err := effective.Build(m, set)
	if err != nil {
		t.Fatal(err)
	}
	problems := append(overlay.Validate(set), result.Problems...)
	sort.Strings(problems)
	// Operations with a problem other than a missing curated name are left
	// out of the render: their PHP would not be generated in a real run.
	blocked := map[string]bool{}
	for _, problem := range problems {
		id, rest, found := strings.Cut(problem, ": ")
		if found && strings.Contains(id, ":") && rest != "needs an explicit overlay name" {
			blocked[id] = true
		}
	}
	operations := 0
	groups := result.Groups[:0]
	for _, group := range result.Groups {
		kept := group.Operations[:0]
		for _, op := range group.Operations {
			if !blocked[op.ID] {
				kept = append(kept, op)
			}
		}
		// A real run never renders an empty group: its operations block it.
		if len(kept) > 0 {
			group.Operations = kept
			groups = append(groups, group)
		}
		operations += len(kept)
	}
	result.Groups = groups
	summary := []string{
		"operations in map: " + strconv.Itoa(len(m.Operations)),
		"rendered operations: " + strconv.Itoa(operations),
		"excluded: " + strconv.Itoa(len(result.Excluded)),
		"operations with blocking problems: " + strconv.Itoa(len(blocked)),
		"problems: " + strconv.Itoa(len(problems)),
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	report := strings.Join(summary, "\n") + "\n\n" + strings.Join(problems, "\n") + "\n"
	if err := os.WriteFile(filepath.Join(dir, "problems.txt"), []byte(report), 0o644); err != nil {
		t.Fatal(err)
	}
	for path, content := range phpgen.Render(result) {
		target := filepath.Join(dir, "out", filepath.FromSlash(path))
		if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(target, content, 0o644); err != nil {
			t.Fatal(err)
		}
	}
	t.Log(strings.Join(summary, "; "))
}
