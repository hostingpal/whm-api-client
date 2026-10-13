package cli

import (
	"fmt"
	"os"
	"path/filepath"
)

// Resolve both existing symlinks and missing output names beneath symlink parents.
func outputPathIdentity(path string) (string, error) {
	absolute, err := filepath.Abs(path)
	if err != nil {
		return "", err
	}
	resolved, err := filepath.EvalSymlinks(absolute)
	if err == nil {
		return resolved, nil
	}
	if !os.IsNotExist(err) {
		return "", err
	}
	parent := filepath.Dir(absolute)
	if parent == absolute {
		return absolute, nil
	}
	base, err := outputPathIdentity(parent)
	if err != nil {
		return "", err
	}
	return filepath.Join(base, filepath.Base(absolute)), nil
}

// distinctOutputs protects inputs and companion outputs without forbidding
// replacement of an unrelated generated artifact. A dash denotes stdout.
func distinctOutputs(inputs, outputs []string) error {
	paths := map[string]bool{}
	for _, p := range inputs {
		if p == "" {
			continue
		}
		resolved, err := outputPathIdentity(p)
		if err != nil {
			return err
		}
		paths[resolved] = true
	}
	for _, p := range outputs {
		if p == "-" {
			continue
		}
		resolved, err := outputPathIdentity(p)
		if err != nil {
			return err
		}
		if paths[resolved] {
			return fmt.Errorf("output paths must be distinct from inputs and each other")
		}
		paths[resolved] = true
	}
	return nil
}
