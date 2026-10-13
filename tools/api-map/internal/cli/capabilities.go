package cli

import (
	"context"
	"crypto/tls"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"time"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/capability"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
)

func captureCapabilities(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	// The production transport always verifies certificate chains and hostnames.
	transport := &http.Transport{Proxy: http.ProxyFromEnvironment, DialContext: (&net.Dialer{Timeout: 15 * time.Second}).DialContext, TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}, TLSHandshakeTimeout: 10 * time.Second, DisableKeepAlives: true}
	defer transport.CloseIdleConnections()
	return captureCapabilitiesWithClient(ctx, args, stdout, stderr, &http.Client{Transport: transport})
}

// The client parameter keeps command execution testable with explicit trusted
// local roots while the public command always supplies a verified transport.
func captureCapabilitiesWithClient(ctx context.Context, args []string, stdout, stderr io.Writer, client *http.Client) int {
	f := flags("capabilities capture")
	optIn := f.Bool("opt-in", false, "explicitly permit two live WHM requests")
	host := f.String("host", "https://test.oblak.host:2087", "HTTPS origin")
	user := f.String("user", "root", "WHM username")
	tokenEnv := f.String("token-env", "OBLAK_TEST_WHM_KEY", "environment variable containing the token")
	output := f.String("output", ".cache/api-map/capability-profile.json", "profile path, or - for stdout")
	cpanelContext := f.String("cpanel-context", "", "optional recorded account context")
	if f.Parse(args) != nil || f.NArg() != 0 || *output == "" {
		return usage(stderr)
	}
	if !*optIn {
		return failure(stderr, "capability capture requires --opt-in")
	}
	if !environmentName(*tokenEnv) {
		return failure(stderr, "invalid token environment name")
	}
	token, exists := os.LookupEnv(*tokenEnv)
	if !exists || token == "" {
		return failure(stderr, "token environment variable is empty or unset")
	}
	profile, captureErr := capability.Capture(ctx, capability.CaptureConfig{Origin: *host, Username: *user, CPanelContext: *cpanelContext, OptIn: *optIn, Token: token}, client, time.Now)
	raw, err := model.Encode(profile)
	if err != nil {
		return failure(stderr, "capability profile encoding failed")
	}
	if _, err := capability.DecodeProfile(raw); err != nil {
		return failure(stderr, "capability profile validation failed")
	}
	if *output == "-" {
		if _, err := stdout.Write(raw); err != nil {
			return failure(stderr, "capability profile write failed")
		}
	} else {
		if err := model.WriteAtomic(*output, raw); err != nil {
			return failure(stderr, "capability profile write failed")
		}
	}
	if captureErr != nil {
		// Capture returns only fixed local categories; never forward arbitrary errors.
		if safe, ok := captureErr.(*capability.Failure); ok {
			return failure(stderr, safe.Error())
		}
		return failure(stderr, "capability capture failed")
	}
	if *output != "-" {
		if _, err := fmt.Fprintln(stdout, `{"capture_status":"success"}`); err != nil {
			return failure(stderr, "capability result write failed")
		}
	}
	return 0
}
func environmentName(name string) bool {
	if name == "" {
		return false
	}
	for i, r := range name {
		if !(r >= 'A' && r <= 'Z' || r >= 'a' && r <= 'z' || r == '_' || i > 0 && r >= '0' && r <= '9') {
			return false
		}
	}
	return true
}
