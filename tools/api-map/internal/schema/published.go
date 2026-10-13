package schema

import (
	"bytes"
	"fmt"
	"os"
	"path/filepath"
)

// VerifyPublished compares the entire schema inventory and exact embedded bytes.
func VerifyPublished(directory string) error {
	embedded, err := assets.ReadDir("assets")
	if err != nil {
		return err
	}
	published, err := filepath.Glob(filepath.Join(directory, "*.schema.json"))
	if err != nil {
		return err
	}
	if len(embedded) != len(published) {
		return fmt.Errorf("schema inventories differ")
	}
	for _, entry := range embedded {
		got, err := os.ReadFile(filepath.Join(directory, entry.Name()))
		if err != nil {
			return err
		}
		want, err := assets.ReadFile("assets/" + entry.Name())
		if err != nil {
			return err
		}
		if !bytes.Equal(got, want) {
			return fmt.Errorf("schema bytes differ: %s", entry.Name())
		}
	}
	return nil
}
