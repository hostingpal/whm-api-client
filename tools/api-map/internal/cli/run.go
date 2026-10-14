// Package cli implements the offline map tool's testable command entry point.
package cli

import (
	"context"
	"flag"
	"fmt"
	"io"
)

// Run returns 0 for success, 1 for input/contract failures, and 2 for usage.
func Run(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	if len(args) > 0 && args[0] == "check" {
		return checkCommand(ctx, args[1:], stdout, stderr)
	}
	if len(args) < 2 {
		return usage(stderr)
	}
	switch args[0] + " " + args[1] {
	case "sources verify":
		return verify(ctx, args[2:], stdout, stderr)
	case "sources refresh":
		return refresh(ctx, args[2:], stdout, stderr)
	case "map build":
		return buildMap(ctx, args[2:], stdout, stderr)
	case "drift report":
		return driftReport(ctx, args[2:], stdout, stderr)
	case "parity report":
		return parityReport(ctx, args[2:], stdout, stderr)
	case "capabilities compare":
		return capabilitiesCompare(ctx, args[2:], stdout, stderr)
	case "capabilities capture":
		return captureCapabilities(ctx, args[2:], stdout, stderr)
	case "overlay validate":
		return overlayValidate(ctx, args[2:], stdout, stderr)
	case "generate php":
		return generatePHP(ctx, args[2:], stdout, stderr)
	default:
		return usage(stderr)
	}
}
func usage(stderr io.Writer) int {
	fmt.Fprintln(stderr, "usage: api-map check --root PATH | drift report --before PATH --after PATH --output PATH --markdown PATH | sources verify --manifest PATH | sources refresh --manifest PATH --output-dir NEW_DIR | map build --manifest PATH --annotations PATH --output PATH | parity report --map PATH --registry PATH --symbols PATH --output PATH --markdown PATH [--test-evidence PATH --live-evidence PATH --revision REVISION] | capabilities compare --map PATH --profile PATH --output PATH --markdown PATH [--explanations PATH] | capabilities capture --opt-in [--host ORIGIN --user USER --token-env NAME --output PATH --cpanel-context NAME] | overlay validate --map PATH --overlay DIR | generate php --map PATH --overlay DIR --root DIR [--check]")
	return 2
}
func flags(name string) *flag.FlagSet {
	f := flag.NewFlagSet(name, flag.ContinueOnError)
	f.SetOutput(io.Discard)
	return f
}
func failure(stderr io.Writer, message string) int {
	fmt.Fprintln(stderr, "api-map: "+message)
	return 1
}
