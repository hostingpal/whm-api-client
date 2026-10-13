//go:build ignore

// Synchronize the published schemas into the executable's embedded registry.
package main

import (
	"fmt"
	"os"
	"path/filepath"
)

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run() error {
	published, err := filepath.Glob("../../../../docs/api/schema/*.schema.json")
	if err != nil {
		return err
	}
	if len(published) == 0 {
		return fmt.Errorf("no published schemas found")
	}
	if err := os.MkdirAll("assets", 0755); err != nil {
		return err
	}
	names := make(map[string]bool, len(published))
	for _, source := range published {
		data, err := os.ReadFile(source)
		if err != nil {
			return err
		}
		name := filepath.Base(source)
		names[name] = true
		if err := os.WriteFile(filepath.Join("assets", name), data, 0644); err != nil {
			return err
		}
	}
	previous, err := filepath.Glob("assets/*.schema.json")
	if err != nil {
		return err
	}
	for _, dest := range previous {
		if !names[filepath.Base(dest)] {
			if err := os.Remove(dest); err != nil {
				return err
			}
		}
	}
	return nil
}
