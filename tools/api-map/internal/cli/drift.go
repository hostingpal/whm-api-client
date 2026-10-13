package cli

import (
	"context"
	"io"
	"os"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/drift"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

func driftReport(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("drift report")
	before := f.String("before", "", "old map")
	after := f.String("after", "", "new map")
	out := f.String("output", "", "JSON report")
	markdown := f.String("markdown", "", "Markdown report")
	if f.Parse(args) != nil || f.NArg() != 0 || *before == "" || *after == "" || *out == "" || *markdown == "" || *markdown == "-" {
		return usage(stderr)
	}
	// Resolve existing symlink parents as well as lexical aliases before writing.
	paths := map[string]bool{}
	for _, p := range []string{*before, *after} {
		resolved, e := outputPathIdentity(p)
		if e != nil {
			return failure(stderr, "input path resolution failed")
		}
		paths[resolved] = true
	}
	for _, p := range []string{*out, *markdown} {
		if p == "-" {
			continue
		}
		resolved, e := outputPathIdentity(p)
		if e != nil || paths[resolved] {
			return failure(stderr, "report paths must be distinct from inputs and each other")
		}
		paths[resolved] = true
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	maps := make([]model.Map, 2)
	for i, p := range []string{*before, *after} {
		raw, e := os.ReadFile(p)
		if e != nil {
			return failure(stderr, "map read failed")
		}
		m, e := model.DecodeMap(raw)
		if e != nil {
			return failure(stderr, "map validation failed")
		}
		maps[i] = m
	}
	report, e := drift.Compare(maps[0], maps[1])
	if e != nil {
		return failure(stderr, "source contract comparison failed")
	}
	if e = schema.Validate("drift-report-v1.schema.json", report); e != nil {
		return failure(stderr, "report schema validation failed")
	}
	raw, e := model.Encode(report)
	if e != nil {
		return failure(stderr, "report encoding failed")
	}
	md := drift.Markdown(report)
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	if *out == "-" {
		_, e = stdout.Write(raw)
	} else {
		e = model.WriteAtomic(*out, raw)
	}
	if e != nil {
		return failure(stderr, "report write failed")
	}
	if e = model.WriteAtomic(*markdown, md); e != nil {
		return failure(stderr, "Markdown write failed")
	}
	return 0
}
