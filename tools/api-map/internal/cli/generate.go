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
	rootDir, err := resolvedRoot(*root)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return 1
	}
	if *check {
		current, err := generatedCurrent(rootDir, files, stderr)
		if err != nil {
			fmt.Fprintln(stderr, "api-map:", err)
			return 1
		}
		if !current {
			return 1
		}
		fmt.Fprintf(stdout, "generated PHP is current: %d files\n", len(files))
		return 0
	}
	paths := renderedPaths(files)
	targets := make(map[string]string, len(paths))
	for _, path := range paths {
		if targets[path], err = confinedGenerated(rootDir, path); err != nil {
			fmt.Fprintln(stderr, "api-map:", err)
			return 1
		}
	}
	stale, err := staleGenerated(rootDir, files)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return 1
	}
	conflicts, err := handWrittenConflicts(rootDir, paths)
	if err != nil {
		fmt.Fprintln(stderr, "api-map:", err)
		return 1
	}
	if reportConflicts(conflicts, false, stderr) {
		return 1
	}
	for _, path := range paths {
		if err := model.WriteAtomic(targets[path], files[path]); err != nil {
			fmt.Fprintln(stderr, "api-map:", err)
			return 1
		}
	}
	for _, path := range stale {
		if err := os.Remove(filepath.Join(rootDir, filepath.FromSlash(path))); err != nil {
			fmt.Fprintln(stderr, "api-map:", err)
			return 1
		}
	}
	fmt.Fprintf(stdout, "generated %d files, removed %d stale\n", len(files), len(stale))
	return 0
}

// resolvedRoot returns the absolute root with its own symbolic links resolved;
// generated paths beneath it are then confined by confinedGenerated.
func resolvedRoot(root string) (string, error) {
	absolute, err := filepath.Abs(root)
	if err != nil {
		return "", err
	}
	return filepath.EvalSymlinks(absolute)
}

// confinedGenerated returns the absolute path for a rendered or scanned
// repository-relative path. The path must be local, and no existing component
// beneath root (folders or the file itself) may be a symbolic link, so reads,
// writes and removals cannot leave the repository. A missing tail is fine; it
// is created as plain folders.
func confinedGenerated(root, name string) (string, error) {
	if !filepath.IsLocal(name) || filepath.ToSlash(filepath.Clean(name)) != name || strings.Contains(name, "\\") {
		return "", fmt.Errorf("generated path %s is not a confined relative path", name)
	}
	current := root
	for _, part := range strings.Split(name, "/") {
		current = filepath.Join(current, part)
		info, err := os.Lstat(current)
		if errors.Is(err, fs.ErrNotExist) {
			break
		}
		if err != nil {
			return "", err
		}
		if info.Mode()&os.ModeSymlink != 0 {
			return "", fmt.Errorf("generated path %s passes through a symbolic link", name)
		}
	}
	return filepath.Join(root, filepath.FromSlash(name)), nil
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
	return contractFromMap(m, set, stderr)
}

// contractFromMap validates the overlay against the map and reports every problem.
func contractFromMap(m model.Map, set overlay.Set, stderr io.Writer) (effective.Result, bool) {
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

// renderedPaths returns the sorted paths of a render.
func renderedPaths(files map[string][]byte) []string {
	paths := make([]string, 0, len(files))
	for path := range files {
		paths = append(paths, path)
	}
	sort.Strings(paths)
	return paths
}

// reportConflicts prints every rendered path that exists without the generated
// marker and reports whether there was any. A write refuses to overwrite them;
// a read-only comparison only names them.
func reportConflicts(conflicts []string, readOnly bool, stderr io.Writer) bool {
	message := "api-map: refusing to overwrite hand-written file:"
	if readOnly {
		message = "api-map: hand-written file at generated path:"
	}
	for _, path := range conflicts {
		fmt.Fprintln(stderr, message, path)
	}
	return len(conflicts) > 0
}

// outdatedFiles lists rendered files that are missing or differ under root, plus
// stale generated files the render no longer produces, sorted.
func outdatedFiles(root string, files map[string][]byte) ([]string, error) {
	var outdated []string
	for path, data := range files {
		target, err := confinedGenerated(root, path)
		if err != nil {
			return nil, err
		}
		current, err := os.ReadFile(target)
		if err != nil || !bytes.Equal(current, data) {
			outdated = append(outdated, path)
		}
	}
	stale, err := staleGenerated(root, files)
	if err != nil {
		return nil, err
	}
	outdated = append(outdated, stale...)
	sort.Strings(outdated)
	return outdated, nil
}

// generatedCurrent is the read-only comparison shared by generate php --check
// and check. It prints hand-written files at rendered paths (and stops there)
// or else every outdated file, and reports whether the tree matches the render
// exactly. root must already be resolved (see resolvedRoot).
func generatedCurrent(root string, files map[string][]byte, stderr io.Writer) (bool, error) {
	conflicts, err := handWrittenConflicts(root, renderedPaths(files))
	if err != nil {
		return false, err
	}
	if reportConflicts(conflicts, true, stderr) {
		return false, nil
	}
	outdated, err := outdatedFiles(root, files)
	if err != nil {
		return false, err
	}
	for _, path := range outdated {
		fmt.Fprintln(stderr, "api-map: generated file out of date:", path)
	}
	return len(outdated) == 0, nil
}

// staleGenerated lists generated PHP files (carrying the marker) that the
// current render no longer produces, in every generated folder
// (phpgen.FamilyDirs); hand-written files are never listed. A symbolic link in
// a generated folder is an error, so the sweep never reads outside the root.
func staleGenerated(root string, files map[string][]byte) ([]string, error) {
	var stale []string
	for _, dir := range phpgen.FamilyDirs() {
		base, err := confinedGenerated(root, dir)
		if err != nil {
			return nil, err
		}
		err = filepath.WalkDir(base, func(path string, d fs.DirEntry, err error) error {
			if err != nil {
				if errors.Is(err, fs.ErrNotExist) {
					return nil
				}
				return err
			}
			relative, err := filepath.Rel(root, path)
			if err != nil {
				return err
			}
			relative = filepath.ToSlash(relative)
			if d.Type()&fs.ModeSymlink != 0 {
				return fmt.Errorf("generated folder entry %s is a symbolic link", relative)
			}
			if d.IsDir() || !strings.HasSuffix(path, ".php") {
				return nil
			}
			if _, current := files[relative]; current {
				return nil
			}
			content, err := os.ReadFile(path)
			if err != nil {
				return err
			}
			if isGenerated(content) {
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

// isGenerated reports whether one of the first 40 lines is exactly the
// generated marker, after trimming whitespace, one comment prefix and an
// optional closing "*/".
func isGenerated(content []byte) bool {
	for i, line := range strings.Split(string(content), "\n") {
		if i >= 40 {
			return false
		}
		line = strings.TrimSpace(line)
		for _, prefix := range []string{"/**", "*", "//", "#"} {
			if strings.HasPrefix(line, prefix) {
				line = strings.TrimSpace(strings.TrimPrefix(line, prefix))
				break
			}
		}
		line = strings.TrimSpace(strings.TrimSuffix(line, "*/"))
		if line == phpgen.Marker {
			return true
		}
	}
	return false
}

// handWrittenConflicts lists rendered paths whose existing content is not
// generated; a rendered path that is not confined beneath root is an error.
func handWrittenConflicts(root string, paths []string) ([]string, error) {
	var conflicts []string
	for _, path := range paths {
		target, err := confinedGenerated(root, path)
		if err != nil {
			return nil, err
		}
		current, err := os.ReadFile(target)
		if err == nil && !isGenerated(current) {
			conflicts = append(conflicts, path)
		}
	}
	return conflicts, nil
}
