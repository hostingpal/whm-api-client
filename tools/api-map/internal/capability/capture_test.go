package capability

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"reflect"
	"strings"
	"testing"
	"time"
)

const testToken = "synthetic-test-token"
const versionBody = `{"data":{"version":"11.88.0.9999"},"metadata":{"command":"version","reason":"OK","result":1,"version":1}}`
const appBody = `{"data":{"app":["accountsummary","listaccts"]},"metadata":{"command":"applist","reason":"OK","result":1,"version":1}}`

func fixedNow() time.Time { return time.Date(2026, 10, 13, 12, 0, 0, 0, time.UTC) }
func config(host string) CaptureConfig {
	return CaptureConfig{Origin: host, Username: "root", OptIn: true, Token: testToken}
}
func noSecrets(t *testing.T, profile Profile, err error) {
	t.Helper()
	data, e := json.Marshal(profile)
	if e != nil {
		t.Fatal(e)
	}
	for _, text := range []string{string(data), fmt.Sprint(err), fmt.Sprintf("%+v", profile), fmt.Sprintf("%#v", profile)} {
		if strings.Contains(text, testToken) || strings.Contains(text, "raw-secret-message") {
			t.Fatalf("unsafe output: %s", text)
		}
	}
}
func TestCaptureAllowlistAndOptIn(t *testing.T) {
	var requests []string
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests = append(requests, r.Method+" "+r.URL.RequestURI())
		if r.Header.Get("Authorization") != "whm root:"+testToken {
			t.Error("incorrect authorization")
		}
		switch r.URL.Path {
		case "/json-api/version":
			io.WriteString(w, versionBody)
		case "/json-api/applist":
			io.WriteString(w, appBody)
		default:
			t.Error("unexpected route")
		}
	}))
	defer server.Close()
	cases := []CaptureConfig{config(server.URL), config(server.URL + "/cpsess123"), config("http://example.invalid"), config(server.URL + "?key=x"), config(server.URL + "#fragment"), config("https://root@localhost"), config("https://localhost:bad"), config(server.URL)}
	cases[0].OptIn = false
	cases[7].Token = ""
	emptyUser := config(server.URL)
	emptyUser.Username = ""
	cases = append(cases, emptyUser)
	newlineUser := config(server.URL)
	newlineUser.Username = "root\n"
	cases = append(cases, newlineUser)
	for _, cfg := range cases {
		p, e := Capture(context.Background(), cfg, server.Client(), fixedNow)
		if e == nil {
			t.Fatalf("accepted invalid config %#v", cfg)
		}
		noSecrets(t, p, e)
	}
	if len(requests) != 0 {
		t.Fatalf("invalid input sent requests: %v", requests)
	}
	p, e := Capture(context.Background(), config(server.URL+"/"), server.Client(), fixedNow)
	if e != nil {
		t.Fatal(e)
	}
	if p.CaptureStatus != "success" || p.ServerVersion != "11.88.0.9999" || p.Origin != server.URL || p.Context.Username != "root" || p.Context.CPanelContext != nil || p.CapturedAt != fixedNow().Format(time.RFC3339) {
		t.Fatalf("wrong profile: %+v", p)
	}
	if !reflect.DeepEqual(p.AdvertisedNames, []string{"accountsummary", "listaccts"}) || p.UAPIInventory != "unavailable" || p.DirectUAPIAuth != "unverified" {
		t.Fatalf("wrong inventory: %+v", p)
	}
	if !reflect.DeepEqual(requests, []string{"GET /json-api/version?api.version=1", "GET /json-api/applist?api.version=1"}) {
		t.Fatalf("calls: %v", requests)
	}
	noSecrets(t, p, e)
}
func TestCapturePreservesExactNames(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/json-api/version" {
			io.WriteString(w, versionBody)
		} else {
			io.WriteString(w, `{"data":{"app":["Module/mixedCase","MixedCase"]},"metadata":{"result":1}}`)
		}
	}))
	defer server.Close()
	cfg := config(server.URL)
	cfg.CPanelContext = "synthetic_context"
	p, e := Capture(context.Background(), cfg, server.Client(), fixedNow)
	if e != nil {
		t.Fatal(e)
	}
	if !reflect.DeepEqual(p.AdvertisedNames, []string{"MixedCase", "Module/mixedCase"}) || p.Context.CPanelContext == nil || *p.Context.CPanelContext != "synthetic_context" {
		t.Fatalf("lost identity/context: %+v", p)
	}
}
func TestCaptureConfigNeverFormatsToken(t *testing.T) {
	cfg := config("https://example.invalid")
	data, e := json.Marshal(cfg)
	if e != nil {
		t.Fatal(e)
	}
	for _, text := range []string{string(data), fmt.Sprint(cfg), fmt.Sprintf("%+v", cfg), fmt.Sprintf("%#v", cfg), fmt.Sprintf("%v", &cfg)} {
		if strings.Contains(text, testToken) {
			t.Fatalf("token printed: %s", text)
		}
	}
}
func TestCaptureFailsSafely(t *testing.T) {
	tests := []struct {
		name, body string
		code       int
	}{
		{"401", "raw-secret-message " + testToken, 401}, {"403", "raw-secret-message", 403}, {"500", "raw-secret-message", 500},
		{"api failure", `{"data":{"version":"11"},"metadata":{"result":0,"reason":"raw-secret-message synthetic-test-token"}}`, 200},
		{"invalid json", "raw-secret-message " + testToken, 200}, {"trailing json", versionBody + ` {}`, 200}, {"truncated", `{"data":`, 200},
		{"wrong version type", `{"data":{"version":1},"metadata":{"result":1}}`, 200},
		{"missing version", `{"data":{},"metadata":{"result":1}}`, 200}, {"empty version", `{"data":{"version":""},"metadata":{"result":1}}`, 200},
		{"wrong metadata result", `{"data":{"version":"11"},"metadata":{"result":"1"}}`, 200},
		{"missing metadata", `{"data":{"version":"11"}}`, 200}, {"null metadata", `{"data":{"version":"11"},"metadata":null}`, 200},
		{"secret version", `{"data":{"version":"synthetic-test-token"},"metadata":{"result":1}}`, 200},
		{"oversized", strings.Repeat("x", 8*1024*1024+1), 200},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			requests := 0
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				requests++
				w.Header().Set("X-Diagnostic", testToken)
				w.WriteHeader(tc.code)
				io.WriteString(w, tc.body)
			}))
			defer server.Close()
			p, e := Capture(context.Background(), config(server.URL), server.Client(), fixedNow)
			if e == nil || p.CaptureStatus != "failed" || len(p.AdvertisedNames) != 0 || requests != 1 {
				t.Fatalf("unsafe success/retry: profile=%+v error=%v requests=%d", p, e, requests)
			}
			noSecrets(t, p, e)
		})
	}
	for _, body := range []string{`{"data":{"app":[]},"metadata":{"result":1}}`, `{"data":{"app":null},"metadata":{"result":1}}`, `{"data":{"app":[1]},"metadata":{"result":1}}`, `{"data":{"app":[""]},"metadata":{"result":1}}`, `{"data":{"app":["synthetic-test-token"]},"metadata":{"result":1}}`, `{"data":{"app":"listaccts"},"metadata":{"result":1}}`, `{"data":{"app":["listaccts"]},"metadata":{"result":0,"reason":"raw-secret-message"}}`} {
		t.Run("second fails", func(t *testing.T) {
			requests := 0
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				requests++
				if requests == 1 {
					io.WriteString(w, versionBody)
				} else {
					io.WriteString(w, body)
				}
			}))
			defer server.Close()
			p, e := Capture(context.Background(), config(server.URL), server.Client(), fixedNow)
			if e == nil || p.CaptureStatus != "partial" || len(p.AdvertisedNames) != 0 || p.ServerVersion != "11.88.0.9999" || len(p.Evidence) != 2 || requests != 2 {
				t.Fatalf("unsafe partial: %+v %v requests=%d", p, e, requests)
			}
			noSecrets(t, p, e)
		})
	}
}

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func TestCaptureTransportSafety(t *testing.T) {
	t.Run("redirect", func(t *testing.T) {
		redirected := 0
		target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { redirected++; io.WriteString(w, versionBody) }))
		defer target.Close()
		source := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, target.URL, http.StatusFound) }))
		defer source.Close()
		client := source.Client()
		original := client.CheckRedirect
		p, e := Capture(context.Background(), config(source.URL), client, fixedNow)
		if e == nil || redirected != 0 || client.CheckRedirect != nil || original != nil {
			t.Fatalf("redirect allowed/client mutated: %+v %v", p, e)
		}
		noSecrets(t, p, e)
	})
	t.Run("untrusted TLS", func(t *testing.T) {
		server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { t.Error("untrusted request arrived") }))
		defer server.Close()
		p, e := Capture(context.Background(), config(server.URL), &http.Client{}, fixedNow)
		if e == nil {
			t.Fatal("untrusted TLS accepted")
		}
		noSecrets(t, p, e)
	})
	t.Run("insecure TLS", func(t *testing.T) {
		calls := 0
		server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { calls++ }))
		defer server.Close()
		client := server.Client()
		tr := client.Transport.(*http.Transport).Clone()
		tr.TLSClientConfig = &tls.Config{InsecureSkipVerify: true}
		client.Transport = tr
		p, e := Capture(context.Background(), config(server.URL), client, fixedNow)
		if e == nil || calls != 0 {
			t.Fatal("insecure transport accepted")
		}
		noSecrets(t, p, e)
	})
	t.Run("transport error", func(t *testing.T) {
		calls := 0
		client := &http.Client{Transport: roundTripFunc(func(*http.Request) (*http.Response, error) {
			calls++
			return nil, errors.New("raw-secret-message " + testToken)
		})}
		p, e := Capture(context.Background(), config("https://example.invalid"), client, fixedNow)
		if e == nil || calls != 1 {
			t.Fatal("unsafe transport/retry")
		}
		noSecrets(t, p, e)
	})
	for _, cancel := range []bool{false, true} {
		t.Run(fmt.Sprint("context ", cancel), func(t *testing.T) {
			server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { <-r.Context().Done() }))
			defer server.Close()
			ctx, stop := context.WithTimeout(context.Background(), 30*time.Millisecond)
			defer stop()
			if cancel {
				stop()
			}
			p, e := Capture(ctx, config(server.URL), server.Client(), fixedNow)
			if e == nil {
				t.Fatal("ignored cancellation/deadline")
			}
			noSecrets(t, p, e)
		})
	}
}
func TestCaptureSourceFixtures(t *testing.T) {
	for _, name := range []string{"version-success.json", "applist-success.json"} {
		data, e := os.ReadFile("testdata/" + name)
		if e != nil {
			t.Fatal(e)
		}
		if !json.Valid(data) {
			t.Fatal("invalid fixture")
		}
	}
}

func TestCaptureRejectsBlankTokenBeforeRequest(t *testing.T) {
	calls := 0
	client := &http.Client{Transport: roundTripFunc(func(*http.Request) (*http.Response, error) { calls++; return nil, errors.New("unexpected request") })}
	cfg := config("https://example.invalid")
	cfg.Token = "   "
	p, e := Capture(context.Background(), cfg, client, fixedNow)
	if e == nil || calls != 0 {
		t.Fatalf("blank token sent request: calls=%d error=%v", calls, e)
	}
	noSecrets(t, p, e)
}
func TestCaptureReadFailuresAndRequestDeadline(t *testing.T) {
	for _, readError := range []bool{false, true} {
		t.Run(fmt.Sprint(readError), func(t *testing.T) {
			calls := 0
			client := &http.Client{Transport: roundTripFunc(func(r *http.Request) (*http.Response, error) {
				calls++
				deadline, ok := r.Context().Deadline()
				if !ok || time.Until(deadline) > 15*time.Second {
					t.Fatal("missing request deadline")
				}
				body := io.NopCloser(strings.NewReader(versionBody))
				if readError {
					body = io.NopCloser(failingReader{})
				}
				return &http.Response{StatusCode: 200, Body: body, Header: http.Header{}}, nil
			})}
			p, e := Capture(context.Background(), config("https://example.invalid"), client, fixedNow)
			if e == nil {
				t.Fatal("malformed inventory/body read accepted")
			}
			if readError && calls != 1 {
				t.Fatal("retried body failure")
			}
			noSecrets(t, p, e)
		})
	}
}

type failingReader struct{}

func (failingReader) Read([]byte) (int, error) {
	return 0, errors.New("synthetic-test-token raw-secret-message")
}

func TestCaptureTruncatedHTTPBody(t *testing.T) {
	calls := 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.Header().Set("Content-Length", fmt.Sprint(len(versionBody)+100))
		io.WriteString(w, versionBody)
	}))
	defer server.Close()
	p, e := Capture(context.Background(), config(server.URL), server.Client(), fixedNow)
	if e == nil || calls != 1 || len(p.Evidence) != 1 || p.Evidence[0].ErrorCategory != "response_read" {
		t.Fatalf("ignored truncation or retried: %+v %v calls=%d", p, e, calls)
	}
	noSecrets(t, p, e)
}
func TestCaptureFailureProfilesValidate(t *testing.T) {
	for _, kind := range []string{"opt_in", "configuration", "insecure_transport", "cancelled", "transport"} {
		t.Run(kind, func(t *testing.T) {
			cfg := config("https://example.invalid")
			client := &http.Client{Transport: roundTripFunc(func(*http.Request) (*http.Response, error) {
				return nil, errors.New("raw-secret-message synthetic-test-token")
			})}
			ctx := context.Background()
			switch kind {
			case "opt_in":
				cfg.OptIn = false
			case "configuration":
				cfg.Origin = "http://example.invalid"
			case "insecure_transport":
				client.Transport = &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}}
			case "cancelled":
				var cancel context.CancelFunc
				ctx, cancel = context.WithCancel(ctx)
				cancel()
			}
			p, e := Capture(ctx, cfg, client, fixedNow)
			if e == nil {
				t.Fatal("expected failure")
			}
			raw, err := json.Marshal(p)
			if err != nil {
				t.Fatal(err)
			}
			if _, err := DecodeProfile(raw); err != nil {
				t.Fatalf("invalid failure profile: %+v %v", p, err)
			}
		})
	}
}

func TestCaptureUsesFreshHTTP1Connections(t *testing.T) {
	calls := 0
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if r.ProtoMajor != 1 {
			t.Errorf("HTTP/2 can transparently replay refused streams: protocol=%s", r.Proto)
		}
		if r.URL.Path == "/json-api/version" {
			io.WriteString(w, versionBody)
		} else {
			io.WriteString(w, appBody)
		}
	}))
	server.EnableHTTP2 = true
	server.StartTLS()
	defer server.Close()
	client := server.Client()
	transport := client.Transport.(*http.Transport)
	originalKeepAlive := transport.DisableKeepAlives
	p, e := Capture(context.Background(), config(server.URL), client, fixedNow)
	if e != nil || calls != 2 || transport.DisableKeepAlives != originalKeepAlive {
		t.Fatalf("failed capture/mutated transport: %+v %v calls=%d", p, e, calls)
	}
}
