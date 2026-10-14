package cli

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/phpgen"
)

func overlayValidate(_ context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("overlay validate")
	mapPath := f.String("map", "", "")
	overlayDir := f.String("overlay", "", "")
	if err := f.Parse(args); err != nil || *mapPath == "" || *overlayDir == "" || f.NArg() != 0 {
		return usage(stderr)
	}
	result, ok := effectiveContract(*mapPath, *overlayDir, stderr)
	if !ok {
		return 1
	}
	operations := 0
	for _, group := range result.Groups {
		operations += len(group.Operations)
	}
	fmt.Fprintf(stdout, "overlay valid: %d groups, %d operations, %d excluded\n", len(result.Groups), operations, len(result.Excluded))
	return 0
}

func generatePHP(_ context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("generate php")
	mapPath := f.String("map", "", "")
	overlayDir := f.String("overlay", "", "")
	root := f.String("root", "", "")
	check := f.Bool("check", false, "")
	if err := f.Parse(args); err != nil || *mapPath == "" || *overlayDir == "" || *root == "" || f.NArg() != 0 {
		return usage(stderr)
	}
	result, ok := effectiveContract(*mapPath, *overlayDir, stderr)
	if !ok {
		return 1
	}
	files := phpgen.Render(result)
	stale, err := staleGenerated(*root, files)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return 1
	}
	paths := make([]string, 0, len(files))
	for path := range files {
		paths = append(paths, path)
	}
	sort.Strings(paths)
	if *check {
		var outdated []string
		for _, path := range paths {
			current, err := os.ReadFile(filepath.Join(*root, filepath.FromSlash(path)))
			if err != nil || !bytes.Equal(current, files[path]) {
				outdated = append(outdated, path)
			}
		}
		outdated = append(outdated, stale...)
		for _, path := range outdated {
			fmt.Fprintln(stderr, "api-map: generated file out of date:", path)
		}
		if len(outdated) > 0 {
			return 1
		}
		fmt.Fprintf(stdout, "generated PHP is current: %d files\n", len(files))
		return 0
	}
	for _, path := range paths {
		if err := model.WriteAtomic(filepath.Join(*root, filepath.FromSlash(path)), files[path]); err != nil {
			fmt.Fprintln(stderr, "api-map:", err)
			return 1
		}
	}
	for _, path := range stale {
		if err := os.Remove(filepath.Join(*root, filepath.FromSlash(path))); err != nil {
			fmt.Fprintln(stderr, "api-map:", err)
			return 1
		}
	}
	fmt.Fprintf(stdout, "generated %d files, removed %d stale\n", len(files), len(stale))
	return 0
}

func effectiveContract(mapPath, overlayDir string, stderr io.Writer) (effective.Result, bool) {
	raw, err := os.ReadFile(mapPath)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return effective.Result{}, false
	}
	m, err := model.DecodeMap(raw)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return effective.Result{}, false
	}
	set, err := overlay.Load(overlayDir)
	if err != nil {
		fmt.Fprintln(stderr, "api-map: overlay:", err)
		return effective.Result{}, false
	}
	problems := overlay.Validate(set)
	result, err := effective.Build(m, set)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return effective.Result{}, false
	}
	problems = append(problems, result.Problems...)
	for _, problem := range problems {
		fmt.Fprintln(stderr, "api-map: overlay:", problem)
	}
	return result, len(problems) == 0
}

// staleGenerated lists generated PHP files (carrying the marker) that the
// current render no longer produces; hand-written files are never listed.
func staleGenerated(root string, files map[string][]byte) ([]string, error) {
	var stale []string
	for _, dir := range []string{"src/Api/Uapi", "src/Api/Whm"} {
		err := filepath.WalkDir(filepath.Join(root, filepath.FromSlash(dir)), func(path string, d fs.DirEntry, err error) error {
			if err != nil {
				if errors.Is(err, fs.ErrNotExist) {
					return nil
				}
				return err
			}
			if d.IsDir() || !strings.HasSuffix(path, ".php") {
				return nil
			}
			relative, err := filepath.Rel(root, path)
			if err != nil {
				return err
			}
			relative = filepath.ToSlash(relative)
			if _, current := files[relative]; current {
				return nil
			}
			content, err := os.ReadFile(path)
			if err != nil {
				return err
			}
			if bytes.Contains(content, []byte(phpgen.Marker)) {
				stale = append(stale, relative)
			}
			return nil
		})
		if err != nil {
			return nil, err
		}
	}
	sort.Strings(stale)
	return stale, nil
}
