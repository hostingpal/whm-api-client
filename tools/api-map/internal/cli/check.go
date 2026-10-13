package cli

import (
	"bytes"
	"context"
	"crypto/sha256"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/capability"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/parity"
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

// Check only reads repository inputs. All generated files live in a disposable
// directory; neither baseline/report regeneration nor network commands are exposed.
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
	symbolsPath := filepath.Join(temp, "symbols.json")
	exporter, err := confinedCheckPath(root, "tools/php-symbols/export.php")
	if err != nil {
		return failure(stderr, "trusted PHP exporter unavailable")
	}
	// The exporter is trusted repository code. Configuration cannot replace the
	// executable, script, argv, or environment with shell commands.
	command := exec.CommandContext(ctx, "php", exporter, "--root", root, "--output", symbolsPath)
	command.Dir = root
	if err = command.Run(); err != nil {
		return failure(stderr, "PHP symbol export failed or cancelled")
	}
	raw, err = os.ReadFile(symbolsPath)
	if err != nil {
		return failure(stderr, "fresh PHP symbols read failed")
	}
	symbols, err := parity.LoadSymbols(raw)
	if err != nil {
		return failure(stderr, "fresh PHP symbols validation failed")
	}
	freshSymbols := raw
	raw, err = os.ReadFile(cfg.Registry)
	if err != nil {
		return failure(stderr, "registry read failed")
	}
	registry, err := parity.LoadRegistry(raw)
	if err != nil {
		return failure(stderr, "registry validation failed")
	}
	evidence := parity.Evidence{Revision: cfg.Revision}
	if cfg.TestEvidence != "" {
		raw, err = os.ReadFile(cfg.TestEvidence)
		if err != nil {
			return failure(stderr, "test evidence read failed")
		}
		saved, e := parity.LoadTestEvidence(raw)
		if e != nil {
			return failure(stderr, "test evidence validation failed")
		}
		evidence.TestRuns = saved.Runs
	}
	report, err := parity.Compare(m, registry, symbols, evidence)
	if err != nil {
		return failure(stderr, "parity contract comparison failed")
	}
	if err = schema.Validate("parity-report-v1.schema.json", report); err != nil {
		return failure(stderr, "parity report validation failed")
	}
	reportRaw, err := model.Encode(report)
	if err != nil {
		return failure(stderr, "parity report encoding failed")
	}
	for name, data := range map[string][]byte{"report.json": reportRaw, "report.md": parity.Markdown(report)} {
		if err = model.WriteAtomic(filepath.Join(temp, name), data); err != nil {
			return failure(stderr, "temporary report write failed")
		}
	}
	raw, err = os.ReadFile(cfg.Baseline)
	if err != nil {
		return failure(stderr, "baseline read failed")
	}
	baseline, err := parity.LoadBaseline(raw)
	if err != nil {
		return failure(stderr, "baseline validation failed")
	}
	violations := parity.CheckBaseline(report, baseline)
	for _, d := range report.Diagnostics {
		if d.Severity == "error" {
			fmt.Fprintf(stderr, "api-map: %s %s\n", d.Severity, d.Code)
		}
	}
	for _, v := range violations {
		fmt.Fprintf(stderr, "api-map: baseline %s %s: %s\n", v.Code, v.ID, v.Message)
	}
	// Invalid registry/evidence is always an input failure, even if a protected
	// mapping is also lost. Keep both diagnostics so regression cannot be concealed.
	if !report.Valid {
		return 1
	}
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
	stale := false
	for _, artifact := range []struct {
		path  string
		data  []byte
		label string
	}{{cfg.Symbols, freshSymbols, "PHP symbols"}, {cfg.ReportJSON, reportRaw, "parity JSON report"}, {cfg.ReportMarkdown, parity.Markdown(report), "parity Markdown report"}} {
		checked, e := os.ReadFile(artifact.path)
		if e != nil {
			return failure(stderr, "checked artifact read failed")
		}
		if !bytes.Equal(checked, artifact.data) {
			fmt.Fprintf(stderr, "api-map: checked %s differs; regenerate explicitly after review\n", artifact.label)
			stale = true
		}
	}
	if len(violations) > 0 {
		return 3
	}
	if stale {
		return 1
	}
	if _, err = fmt.Fprintln(stdout, "Offline API map checks passed; baseline unchanged."); err != nil {
		return failure(stderr, "check result write failed")
	}
	return 0
}
