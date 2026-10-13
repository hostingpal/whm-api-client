package cli

import (
	"context"
	"io"
	"os"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/capability"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

func capabilitiesCompare(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	f := flags("capabilities compare")
	mapPath := f.String("map", "", "canonical map")
	profilePath := f.String("profile", "", "saved capability profile")
	output := f.String("output", "", "JSON report, or - for stdout")
	markdown := f.String("markdown", "", "Markdown report")
	explanationsPath := f.String("explanations", "", "reviewed difference explanations")
	if f.Parse(args) != nil || f.NArg() != 0 || *mapPath == "" || *profilePath == "" || *output == "" || *markdown == "" || *markdown == "-" {
		return usage(stderr)
	}
	paths := map[string]bool{}
	for _, path := range []string{*mapPath, *profilePath, *explanationsPath} {
		if path != "" {
			resolved, err := outputPathIdentity(path)
			if err != nil {
				return failure(stderr, "input path resolution failed")
			}
			paths[resolved] = true
		}
	}
	for _, path := range []string{*output, *markdown} {
		if path == "-" {
			continue
		}
		resolved, err := outputPathIdentity(path)
		if err != nil || paths[resolved] {
			return failure(stderr, "report output paths must be distinct from inputs and each other")
		}
		paths[resolved] = true
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
	raw, err = os.ReadFile(*profilePath)
	if err != nil {
		return failure(stderr, "capability profile read failed")
	}
	p, err := capability.DecodeProfile(raw)
	if err != nil {
		return failure(stderr, "capability profile validation failed")
	}
	var explanations []capability.Explanation
	if *explanationsPath != "" {
		raw, err = os.ReadFile(*explanationsPath)
		if err != nil {
			return failure(stderr, "explanations read failed")
		}
		explanations, err = capability.LoadExplanations(raw)
		if err != nil {
			return failure(stderr, "explanations validation failed")
		}
	}
	report, err := capability.Compare(m, p, explanations)
	if err != nil {
		return failure(stderr, "capability comparison validation failed")
	}
	raw, err = model.Encode(report)
	if err != nil {
		return failure(stderr, "report encoding failed")
	}
	md := capability.Markdown(report)
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
	if err = model.WriteAtomic(*markdown, md); err != nil {
		return failure(stderr, "Markdown write failed")
	}
	return 0
}
