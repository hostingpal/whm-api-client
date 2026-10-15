package cli

import (
	"bytes"
	"context"
	"crypto/sha256"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/capability"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/overlay"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/phpgen"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

func checkCommand(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("check")
	root := f.String("root", "", "repository root")
	if f.Parse(args) != nil || f.NArg() != 0 || *root == "" {
		return usage(stderr)
	}
	return Check(ctx, *root, stdout, stderr)
}

// Check only reads repository inputs; generated maps live in a disposable directory and generated code is compared, never written.
func Check(ctx context.Context, root string, stdout, stderr io.Writer) int {
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	absolute, err := filepath.Abs(root)
	if err != nil {
		return failure(stderr, "root resolution failed")
	}
	root, err = filepath.EvalSymlinks(absolute)
	if err != nil {
		return failure(stderr, "root resolution failed")
	}
	cfg, err := loadToolchain(root)
	if err != nil {
		return failure(stderr, "toolchain configuration validation failed")
	}
	if err = schema.VerifyPublished(cfg.SchemaDir); err != nil {
		return failure(stderr, "published and embedded schemas differ")
	}
	temp, err := os.MkdirTemp("", "api-map-check-")
	if err != nil {
		return failure(stderr, "temporary output creation failed")
	}
	defer os.RemoveAll(temp)
	mapPath := filepath.Join(temp, "map.json")
	mapAgain := filepath.Join(temp, "map-again.json")
	for _, out := range []string{mapPath, mapAgain} {
		if code := buildMap(ctx, []string{"--manifest", cfg.Manifest, "--annotations", cfg.Annotations, "--output", out}, io.Discard, stderr); code != 0 {
			return code
		}
	}
	raw, err := os.ReadFile(mapPath)
	if err != nil {
		return failure(stderr, "generated map read failed")
	}
	again, err := os.ReadFile(mapAgain)
	if err != nil || !bytes.Equal(raw, again) {
		return failure(stderr, "map generation is not reproducible")
	}
	sum, err := os.ReadFile(cfg.MapChecksum)
	if err != nil {
		return failure(stderr, "map checksum read failed")
	}
	fields := strings.Fields(string(sum))
	if len(fields) != 2 || fields[0] != fmt.Sprintf("%x", sha256.Sum256(raw)) || fields[1] != cfg.Map {
		return failure(stderr, "map checksum differs; review source changes before regeneration")
	}
	m, err := model.DecodeMap(raw)
	if err != nil {
		return failure(stderr, "generated map validation failed")
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	set, err := overlay.Load(cfg.Overlay)
	if err != nil {
		fmt.Fprintln(stderr, "api-map: overlay:", err)
		return 1
	}
	result, ok := contractFromMap(m, set, stderr)
	if !ok {
		return 1
	}
	// Computed before the examples, acted on after them; the comparison is read-only.
	current, compareErr := generatedCurrent(root, phpgen.Render(result), stderr)
	for _, example := range cfg.Examples {
		if ctx.Err() != nil {
			return failure(stderr, "operation cancelled")
		}
		data, e := os.ReadFile(example.Path)
		if e != nil {
			return failure(stderr, "synthetic example read failed")
		}
		value, e := decodeCheckJSON(data)
		if e != nil || schema.Validate(example.Schema, value) != nil {
			return failure(stderr, "synthetic example schema validation failed")
		}
		if example.Schema == "capability-profile-v1.schema.json" {
			if _, e = capability.DecodeProfile(data); e != nil {
				return failure(stderr, "synthetic capability profile validation failed")
			}
		}
		if example.Schema == "api-map-v1.schema.json" {
			if _, e = model.DecodeMap(data); e != nil {
				return failure(stderr, "synthetic map validation failed")
			}
		}
		if example.Schema == "capability-explanations-v1.schema.json" {
			if _, e = capability.LoadExplanations(data); e != nil {
				return failure(stderr, "synthetic explanations validation failed")
			}
		}
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	if compareErr != nil {
		return failure(stderr, "generated code comparison failed")
	}
	if !current {
		return 1
	}
	if _, err = fmt.Fprintln(stdout, "Offline API map checks passed; generated code is current."); err != nil {
		return failure(stderr, "check result write failed")
	}
	return 0
}
