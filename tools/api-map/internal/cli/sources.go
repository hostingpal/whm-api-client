package cli

import (
	"context"
	"io"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/openapi"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func verify(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("sources verify")
	manifest := f.String("manifest", "", "pinned manifest")
	if f.Parse(args) != nil || f.NArg() != 0 || *manifest == "" {
		return usage(stderr)
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	b, err := source.Load(*manifest, source.Limits{})
	if err != nil {
		return failure(stderr, "source verification failed")
	}
	if _, err := openapi.Analyze(b); err != nil {
		return failure(stderr, "source contract validation failed")
	}
	raw, err := model.Encode(model.Object{"verified_sources": len(b.Records)})
	if err != nil {
		return failure(stderr, "result encoding failed")
	}
	if _, err := stdout.Write(raw); err != nil {
		return failure(stderr, "result write failed")
	}
	return 0
}
func refresh(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("sources refresh")
	manifest := f.String("manifest", "", "pinned manifest")
	output := f.String("output-dir", "", "new immutable snapshot")
	if f.Parse(args) != nil || f.NArg() != 0 || *manifest == "" || *output == "" {
		return usage(stderr)
	}
	result, err := source.Refresh(ctx, source.RefreshRequest{ManifestPath: *manifest, SnapshotDir: *output, Validate: func(b source.Bundle) error { _, err := openapi.Analyze(b); return err }}, nil)
	if err != nil {
		return failure(stderr, "source refresh failed")
	}
	raw, err := model.Encode(model.Object{"manifest": result})
	if err != nil {
		return failure(stderr, "result encoding failed")
	}
	if _, err := stdout.Write(raw); err != nil {
		return failure(stderr, "result write failed")
	}
	return 0
}
