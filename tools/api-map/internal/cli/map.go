package cli

import (
	"context"
	"fmt"
	"io"
	"path/filepath"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/mapbuild"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func buildMap(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("map build")
	manifest := f.String("manifest", "", "pinned manifest")
	annotations := f.String("annotations", "", "reviewed annotations")
	output := f.String("output", "", "map path, or - for stdout")
	if f.Parse(args) != nil || f.NArg() != 0 || *manifest == "" || *annotations == "" || *output == "" {
		return usage(stderr)
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	b, err := source.Load(*manifest, source.Limits{})
	if err != nil {
		return failure(stderr, "source verification failed")
	}
	inputs := []string{*manifest, *annotations}
	for _, record := range b.Records {
		inputs = append(inputs, filepath.Join(filepath.Dir(*manifest), record["file"].(string)))
	}
	if err := distinctOutputs(inputs, []string{*output}); err != nil {
		return failure(stderr, "map output path must be distinct from inputs")
	}
	a, err := mapbuild.LoadAnnotations(*annotations)
	if err != nil {
		return failure(stderr, "annotation validation failed")
	}
	m, err := mapbuild.Build(b, a.Annotations, a.Aliases)
	if err != nil {
		return failure(stderr, "map validation failed")
	}
	raw, err := model.Encode(m)
	if err != nil {
		return failure(stderr, "map encoding failed")
	}
	if _, err := model.DecodeMap(raw); err != nil {
		return failure(stderr, "map schema validation failed")
	}
	if ctx.Err() != nil {
		return failure(stderr, "operation cancelled")
	}
	for _, d := range m.Diagnostics {
		fmt.Fprintln(stderr, "api-map: "+d.Severity+" "+d.Code)
	}
	if *output == "-" {
		if _, err := stdout.Write(raw); err != nil {
			return failure(stderr, "map write failed")
		}
		return 0
	}
	if err := model.WriteAtomic(*output, raw); err != nil {
		return failure(stderr, "map write failed")
	}
	variants := 0
	for _, o := range m.Operations {
		variants += len(o.Variants)
	}
	summary, err := model.Encode(model.Object{"operations": len(m.Operations), "variants": variants, "diagnostics": len(m.Diagnostics)})
	if err != nil {
		return failure(stderr, "result encoding failed")
	}
	if _, err := stdout.Write(summary); err != nil {
		return failure(stderr, "result write failed")
	}
	return 0
}
