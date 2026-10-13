package cli

import (
	"bytes"
	"context"
	"fmt"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/capability"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCapabilitiesCaptureRequiresOptIn(t *testing.T) {
	var stdout, stderr bytes.Buffer
	code := Run(context.Background(), []string{"capabilities", "capture"}, &stdout, &stderr)
	if code != 1 || !strings.Contains(stderr.String(), "opt-in") {
		t.Fatalf("code=%d stderr=%q", code, stderr.String())
	}
	if stdout.Len() != 0 {
		t.Fatal("unexpected stdout")
	}
}

func TestCapabilitiesCaptureCLI(t *testing.T) {
	for _, tc := range []struct {
		name   string
		second string
		status string
		code   int
	}{
		{"success", `{"data":{"app":["listaccts","MixedCase"]},"metadata":{"result":1,"reason":"synthetic-test-token raw-secret-message"}}`, "success", 0},
		{"partial", `{"data":{"app":[]},"metadata":{"result":1,"reason":"synthetic-test-token raw-secret-message"}}`, "partial", 1},
		{"secret data", `{"data":{"app":["synthetic-test-token"]},"metadata":{"result":1}}`, "partial", 1},
	} {
		t.Run(tc.name, func(t *testing.T) {
			requests := 0
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				requests++
				if r.URL.Path == "/json-api/version" {
					io.WriteString(w, `{"data":{"version":"11.88.0.9999"},"metadata":{"result":1}}`)
				} else {
					io.WriteString(w, tc.second)
				}
			}))
			defer server.Close()
			t.Setenv("API_MAP_SYNTHETIC_TOKEN", "synthetic-test-token")
			output := filepath.Join(t.TempDir(), "profile.json")
			var stdout, stderr bytes.Buffer
			args := []string{"--opt-in", "--host", server.URL, "--user", "root", "--token-env", "API_MAP_SYNTHETIC_TOKEN", "--output", output, "--cpanel-context", "synthetic_context"}
			code := captureCapabilitiesWithClient(context.Background(), args, &stdout, &stderr, server.Client())
			if code != tc.code || requests != 2 {
				t.Fatalf("code=%d requests=%d stderr=%s", code, requests, stderr.String())
			}
			data, e := os.ReadFile(output)
			if e != nil {
				t.Fatal(e)
			}
			p, e := capability.DecodeProfile(data)
			if e != nil {
				t.Fatal(e)
			}
			if p.CaptureStatus != tc.status || p.Context.CPanelContext == nil || *p.Context.CPanelContext != "synthetic_context" {
				t.Fatalf("bad profile: %+v", p)
			}
			for _, text := range []string{string(data), stdout.String(), stderr.String()} {
				if strings.Contains(text, "synthetic-test-token") || strings.Contains(text, "raw-secret-message") {
					t.Fatalf("unsafe output: %s", text)
				}
			}
		})
	}
}
func TestCapabilitiesCaptureCLIRejectsUnsafeArguments(t *testing.T) {
	requests := 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { requests++ }))
	defer server.Close()
	t.Setenv("API_MAP_SYNTHETIC_TOKEN", "synthetic-test-token")
	t.Setenv("API_MAP_EMPTY_TOKEN", "")
	for _, args := range [][]string{
		{"--host", server.URL, "--token-env", "API_MAP_SYNTHETIC_TOKEN"},
		{"--opt-in", "--host", server.URL, "--token-env", "API_MAP_EMPTY_TOKEN"},
		{"--opt-in", "--host", server.URL, "--token-env", "bad-name"},
		{"--opt-in", "--host", server.URL, "--token-env", "API_MAP_SYNTHETIC_TOKEN", "--token", "synthetic-test-token"},
		{"--opt-in", "--host", server.URL + "/cpsess123", "--token-env", "API_MAP_SYNTHETIC_TOKEN"},
		{"--opt-in", "--host", server.URL, "--token-env", "API_MAP_SYNTHETIC_TOKEN", "extra"},
	} {
		output := filepath.Join(t.TempDir(), "profile.json")
		args = append(args, "--output", output)
		var stdout, stderr bytes.Buffer
		if code := captureCapabilitiesWithClient(context.Background(), args, &stdout, &stderr, server.Client()); code == 0 {
			t.Fatal("invalid CLI accepted")
		}
		if requests != 0 {
			t.Fatal("unsafe CLI sent request")
		}
		if strings.Contains(stderr.String(), "synthetic-test-token") {
			t.Fatal("stderr leaked argument")
		}
		if data, e := os.ReadFile(output); e == nil {
			p, e := capability.DecodeProfile(data)
			if e != nil || p.CaptureStatus != "failed" {
				t.Fatal("invalid CLI wrote successful profile")
			}
		}
	}
}
func TestCapabilitiesCaptureCLIWriteFailureSafe(t *testing.T) {
	t.Setenv("API_MAP_SYNTHETIC_TOKEN", "synthetic-test-token")
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/json-api/version" {
			io.WriteString(w, `{"data":{"version":"11"},"metadata":{"result":1}}`)
		} else {
			io.WriteString(w, `{"data":{"app":["listaccts"]},"metadata":{"result":1}}`)
		}
	}))
	defer server.Close()
	var stdout, stderr bytes.Buffer
	if code := captureCapabilitiesWithClient(context.Background(), []string{"--opt-in", "--host", server.URL, "--token-env", "API_MAP_SYNTHETIC_TOKEN", "--output", t.TempDir()}, &stdout, &stderr, server.Client()); code != 1 {
		t.Fatalf("write failure code=%d", code)
	}
	if stdout.Len() != 0 || strings.Contains(stderr.String(), "synthetic-test-token") {
		t.Fatal("unsafe write failure")
	}
}

func TestCapabilitiesCaptureDefaultProfileAndWriterFailure(t *testing.T) {
	t.Setenv("OBLAK_TEST_WHM_KEY", "synthetic-test-token")
	client := &http.Client{Transport: capabilityRoundTrip(func(r *http.Request) (*http.Response, error) {
		if r.URL.Host != "test.oblak.host:2087" || r.Header.Get("Authorization") != "whm root:synthetic-test-token" {
			t.Error("incorrect named profile defaults")
		}
		body := `{"data":{"version":"11"},"metadata":{"result":1}}`
		if r.URL.Path == "/json-api/applist" {
			body = `{"data":{"app":["listaccts"]},"metadata":{"result":1}}`
		}
		return &http.Response{StatusCode: 200, Body: io.NopCloser(strings.NewReader(body)), Header: http.Header{}}, nil
	})}
	var stderr bytes.Buffer
	output := filepath.Join(t.TempDir(), "profile.json")
	if code := captureCapabilitiesWithClient(context.Background(), []string{"--opt-in", "--output", output}, capabilityFailingWriter{}, &stderr, client); code != 1 {
		t.Fatalf("ignored stdout write failure: %d", code)
	}
	if strings.Contains(stderr.String(), "synthetic-test-token") {
		t.Fatal("writer error leaked token")
	}
}

type capabilityRoundTrip func(*http.Request) (*http.Response, error)

func (f capabilityRoundTrip) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }

type capabilityFailingWriter struct{}

func (capabilityFailingWriter) Write([]byte) (int, error) {
	return 0, fmt.Errorf("synthetic-test-token raw-secret-message")
}
