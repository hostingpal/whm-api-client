package cli

import (
	"context"
	"fmt"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/parity"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
	"io"
	"os"
)

func parityReport(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("parity report")
	mapPath := f.String("map", "", "canonical map")
	registryPath := f.String("registry", "", "coverage registry")
	symbolsPath := f.String("symbols", "", "PHP symbols")
	output := f.String("output", "", "JSON report")
	markdown := f.String("markdown", "", "Markdown report")
	testPath := f.String("test-evidence", "", "saved test outcomes")
	livePath := f.String("live-evidence", "", "saved live records")
	revision := f.String("revision", "", "independently supplied revision being assessed")
	if f.Parse(args) != nil || f.NArg() != 0 || *mapPath == "" || *registryPath == "" || *symbolsPath == "" || *output == "" || *markdown == "" {
		return usage(stderr)
	}
	if *markdown == "-" {
		return usage(stderr)
	}
	if err := distinctOutputs([]string{*mapPath, *registryPath, *symbolsPath, *testPath, *livePath}, []string{*output, *markdown}); err != nil {
		return failure(stderr, "report output paths must be distinct from inputs and each other")
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	raw, err := os.ReadFile(*mapPath)
	if err != nil {
		return failure(stderr, "map read failed")
	}
	m, err := model.DecodeMap(raw)
	if err != nil {
		return failure(stderr, "map validation failed")
	}
	raw, err = os.ReadFile(*registryPath)
	if err != nil {
		return failure(stderr, "registry read failed")
	}
	r, err := parity.LoadRegistry(raw)
	if err != nil {
		return failure(stderr, "registry validation failed")
	}
	raw, err = os.ReadFile(*symbolsPath)
	if err != nil {
		return failure(stderr, "symbols read failed")
	}
	s, err := parity.LoadSymbols(raw)
	if err != nil {
		return failure(stderr, "symbols validation failed")
	}
	e := parity.Evidence{Revision: *revision}
	if *testPath != "" {
		raw, err = os.ReadFile(*testPath)
		if err != nil {
			return failure(stderr, "test evidence read failed")
		}
		t, err := parity.LoadTestEvidence(raw)
		if err != nil {
			return failure(stderr, "test evidence validation failed")
		}
		e.TestRuns = t.Runs
	}
	if *livePath != "" {
		raw, err = os.ReadFile(*livePath)
		if err != nil {
			return failure(stderr, "live evidence read failed")
		}
		l, err := parity.LoadLiveEvidence(raw)
		if err != nil {
			return failure(stderr, "live evidence validation failed")
		}
		e.Live = l.Records
		e.LiveProvided = true
	}
	report, err := parity.Compare(m, r, s, e)
	if err != nil {
		return failure(stderr, "contract comparison failed")
	}
	if err = schema.Validate("parity-report-v1.schema.json", report); err != nil {
		return failure(stderr, "report schema validation failed")
	}
	raw, err = model.Encode(report)
	if err != nil {
		return failure(stderr, "report encoding failed")
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	if *output == "-" {
		_, err = stdout.Write(raw)
	} else {
		err = model.WriteAtomic(*output, raw)
	}
	if err != nil {
		return failure(stderr, "report write failed")
	}
	if err = model.WriteAtomic(*markdown, parity.Markdown(report)); err != nil {
		return failure(stderr, "Markdown write failed")
	}
	for _, d := range report.Diagnostics {
		fmt.Fprintf(stderr, "api-map: %s %s\n", d.Severity, d.Code)
	}
	if !report.Valid {
		return 1
	}
	return 0
}
