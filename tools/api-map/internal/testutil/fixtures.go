// Package testutil provides shared fixture helpers for offline tool tests.
package testutil

import (
	"os"
	"testing"
)

// Read reads a fixture relative to the caller package's working directory.
func Read(t testing.TB, path string) []byte {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return data
}
