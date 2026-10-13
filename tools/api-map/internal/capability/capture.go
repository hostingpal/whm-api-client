package capability

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"sort"
	"strings"
	"time"
)

const maxResponseBytes = 8 * 1024 * 1024

// Capture never discovers endpoints. It sends version followed by applist, stops
// on failure, refuses redirects, and preserves verified TLS. An injected custom
// RoundTripper is trusted to honor TLS and context; standard transports are
// checked and cloned. No caller-owned client or transport is mutated.
func Capture(ctx context.Context, config CaptureConfig, client *http.Client, now func() time.Time) (Profile, error) {
	if now == nil {
		now = time.Now
	}
	p := Profile{SchemaVersion: "1.0", CapturedAt: now().UTC().Format(time.RFC3339), CaptureStatus: "failed", AdvertisedNames: []string{}, Evidence: []Evidence{}, UAPIInventory: "unavailable", DirectUAPIAuth: "unverified"}
	fail := func(category string) (Profile, error) { return p, &Failure{Category: category} }
	if !config.OptIn {
		return fail("opt_in_required")
	}
	origin, valid := normalizeOrigin(config.Origin)
	if !valid || !accountName(config.Username) || !safeText(origin, config.Token) || !safeText(config.Username, config.Token) || !safeText(config.Token, "") || strings.TrimSpace(config.Token) == "" {
		return fail("invalid_configuration")
	}
	if config.CPanelContext != "" && (!accountName(config.CPanelContext) || !safeText(config.CPanelContext, config.Token)) {
		return fail("invalid_configuration")
	}
	p.Origin = origin
	p.Context.Username = config.Username
	if config.CPanelContext != "" {
		value := config.CPanelContext
		p.Context.CPanelContext = &value
	}
	clone, valid := captureClient(client)
	if !valid {
		return fail("insecure_transport")
	}
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	for _, function := range []string{"version", "applist"} {
		evidence := Evidence{Function: function, Route: "/json-api/" + function + "?api.version=1", Status: "failed"}
		data, category := observe(ctx, clone, origin, config.Username, config.Token, &evidence)
		if category == "" {
			if function == "version" {
				value, ok := data["version"].(string)
				if !ok || !safeText(value, config.Token) {
					category = "invalid_data"
				} else {
					p.ServerVersion = value
				}
			} else {
				values, ok := data["app"].([]any)
				names := []string{}
				if !ok || len(values) == 0 {
					category = "invalid_data"
				} else {
					for _, value := range values {
						name, ok := value.(string)
						if !ok || !safeText(name, config.Token) {
							category = "invalid_data"
							break
						}
						names = append(names, name)
					}
				}
				if category == "" {
					sort.Strings(names)
					p.AdvertisedNames = names
				}
			}
		}
		if category != "" {
			evidence.ErrorCategory = category
			p.Evidence = append(p.Evidence, evidence)
			return fail(category)
		}
		evidence.Status = "success"
		p.Evidence = append(p.Evidence, evidence)
		p.CaptureStatus = "partial"
	}
	p.CaptureStatus = "success"
	return p, nil
}

func captureClient(client *http.Client) (*http.Client, bool) {
	if client == nil {
		client = &http.Client{}
	}
	cloned := *client
	transport := client.Transport
	if transport == nil {
		transport = http.DefaultTransport
	}
	if standard, ok := transport.(*http.Transport); ok {
		if standard == nil || (standard.TLSClientConfig != nil && standard.TLSClientConfig.InsecureSkipVerify) {
			return nil, false
		}
		tr := standard.Clone()
		// Fresh HTTP/1 connections prevent automatic reused-connection retries
		// and HTTP/2 refused-stream replay, preserving the two-call allowlist.
		tr.DisableKeepAlives = true
		tr.ForceAttemptHTTP2 = false
		tr.Protocols = new(http.Protocols)
		tr.Protocols.SetHTTP1(true)
		tr.TLSNextProto = map[string]func(string, *tls.Conn) http.RoundTripper{}
		if tr.TLSClientConfig == nil {
			tr.TLSClientConfig = &tls.Config{}
		}
		tr.TLSClientConfig.NextProtos = []string{"http/1.1"}
		cloned.Transport = tr
	} else {
		cloned.Transport = transport
	}
	cloned.Jar = nil
	cloned.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	if cloned.Timeout <= 0 || cloned.Timeout > 15*time.Second {
		cloned.Timeout = 15 * time.Second
	}
	return &cloned, true
}

func observe(ctx context.Context, client *http.Client, origin, user, token string, evidence *Evidence) (map[string]any, string) {
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, origin+evidence.Route, nil)
	if err != nil {
		return nil, "invalid_configuration"
	}
	req.Header.Set("Authorization", "whm "+user+":"+token)
	resp, err := client.Do(req)
	if err != nil {
		if errors.Is(err, context.Canceled) {
			return nil, "cancelled"
		}
		var timeout net.Error
		if errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &timeout) && timeout.Timeout()) {
			return nil, "timeout"
		}
		return nil, "transport"
	}
	defer resp.Body.Close()
	evidence.HTTPStatus = resp.StatusCode
	if resp.StatusCode >= 300 && resp.StatusCode < 400 {
		return nil, "redirect"
	}
	if resp.StatusCode == 401 || resp.StatusCode == 403 {
		return nil, "authentication"
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, "http_status"
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseBytes+1))
	if err != nil {
		return nil, "response_read"
	}
	if len(body) > maxResponseBytes {
		return nil, "response_too_large"
	}
	decoder := json.NewDecoder(bytes.NewReader(body))
	decoder.UseNumber()
	var envelope map[string]any
	if decoder.Decode(&envelope) != nil {
		return nil, "invalid_json"
	}
	var trailing any
	if decoder.Decode(&trailing) != io.EOF {
		return nil, "invalid_json"
	}
	metadata, ok := envelope["metadata"].(map[string]any)
	if !ok {
		return nil, "invalid_data"
	}
	number, ok := metadata["result"].(json.Number)
	if !ok {
		return nil, "invalid_data"
	}
	result, err := number.Int64()
	if err != nil {
		return nil, "invalid_data"
	}
	evidence.APIResult = &result
	if result != 1 {
		return nil, "api_result"
	}
	data, ok := envelope["data"].(map[string]any)
	if !ok {
		return nil, "invalid_data"
	}
	return data, ""
}
