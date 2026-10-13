# Server Capability Profiles Implementation Plan

> **For agentic workers:** Use the execution skill selected at handoff. Read the
> [overview](2026-10-13-api-map-implementation-plan.md) and approved spec first.
> Beads is the sole task tracker.

**Goal:** Collect narrowly scoped WHM inventory only when requested, then
compare saved profiles with documentation independently of PHP coverage.

**Architecture:** A fixed two-call HTTPS collector emits a sanitized profile.
A pure comparator accepts that profile and the canonical map; neither comparison
nor tests contact the supplied real server.

**Tech stack:** Go `net/http`, `httptest`, local TLS fixtures, and shared model
and schema packages. **Spec:** [approved design](../specs/2026-10-13-api-map-design.md).

## Global constraints

All overview constraints apply. Live calls require explicit opt-in. Only
`version` and `applist` are callable; HTTPS verification stays enabled, redirects
and retries are disabled, and credentials never enter artifacts/logs. No direct
UAPI inventory endpoint is invented. This plan depends on `gwi.3` and may run
alongside parity work.

## Review focus

Cover API failure inside HTTP 200; unauthorized inventories; hostile bodies or
transport errors containing the token; server/API-version confusion; and
namespaced/case-sensitive advertised names. Failed inventory must never be
interpreted as an empty successful list or as proof of unsupported APIs.

## Task 7 — Implement the opt-in collector

**Beads:** `whm-api-client-gwi.5.1`.

**Files:** Create `internal/capability/{profile.go,capture.go,sanitize.go,
capture_test.go,testdata/}`, `internal/cli/capabilities.go`, and published/embedded
`capability-profile-v1.schema.json`. Add synthetic profiles under
`docs/api/examples/capabilities/`. Real output defaults to ignored `.cache/api-map/`.

**Interfaces:** `capability.Capture(ctx context.Context, config CaptureConfig,
client *http.Client, now func() time.Time) (Profile, error)`.
`CaptureConfig` contains HTTPS origin, WHM username, optional cPanel context,
explicit opt-in, and token. The token field is excluded from JSON/debug output.
`Profile` contains schema version, origin, context, capture time, server version,
capture status, exact advertised names, safe operation evidence, and
`uapi_inventory:"unavailable"` / `direct_uapi_auth:"unverified"`.

1. Start a local TLS server and write `TestCaptureAllowlistAndOptIn`. Assert
   zero requests without opt-in or with invalid origin/empty credentials.
   Success sends exactly these requests, with no session path:

   ```text
   GET /json-api/version?api.version=1
   GET /json-api/applist?api.version=1
   Authorization: whm root:synthetic-test-token
   ```

   Source-backed success fixtures are:

   ```json
   {"data":{"version":"11.88.0.9999"},"metadata":{"command":"version","reason":"OK","result":1,"version":1}}
   {"data":{"app":["accountsummary","listaccts"]},"metadata":{"command":"applist","reason":"OK","result":1,"version":1}}
   ```

   Assert server version is `data.version`, not integer `metadata.version`.
   Preserve mixed-case/slash-qualified name strings without asserting how a
   specific real server would advertise them. Reject malformed usable-data
   fields as collector validity failures, not claims about upstream requiredness.
2. Add `TestCaptureFailsSafely`: HTTP 401/403/500; API `result:0` within HTTP 200;
   invalid/trailing JSON; truncated/oversized body; wrong field types; untrusted
   certificate; redirect to a second server; timeout/cancellation; and a transport
   error/body/header/reason containing the synthetic token. Assert no retries,
   no redirected request, no successful empty inventory, and no secret/raw
   message in errors, JSON, stderr, or profile evidence.
3. Run `go -C tools/api-map test ./internal/capability -run TestCapture` and
   confirm failures before implementing capture.
4. Implement a cloned client that refuses redirects, preserves verified TLS,
   limits each response to 8 MiB, and applies a 15-second request timeout within
   a 30-second capture context. Normalize origin to scheme/host/port only;
   reject userinfo, paths other than `/`, query, fragment, and plain HTTP.
   The production CLI constructs a verified-TLS transport; reject an injected
   standard transport with `InsecureSkipVerify` enabled rather than trusting it.
   A failed first operation stops capture; a failed second operation yields a
   failed/partial profile with no successful inventory claim. Keep only local
   error category, numeric HTTP/API status if available, and allowlisted function.
   Never copy `metadata.reason` or arbitrary transport error strings.
5. Add `capabilities capture --opt-in --host ORIGIN --user USER --token-env NAME
   --output PATH [--cpanel-context NAME]`. Default profile values are
   `https://test.oblak.host:2087`, root, and token environment name
   `OBLAK_TEST_WHM_KEY`; default cPanel context is absent unless explicitly
   provided. Do not accept a token value as a CLI argument or read shell files.
   Failure exits 1 and may write only a clearly failed sanitized profile.
6. Run collector/CLI tests and schema validation. Tests use `httptest` clients
   with explicit trusted local roots; the real server is not contacted.

## Task 8 — Compare saved profiles with documentation

**Beads:** `whm-api-client-gwi.5.2`; depends on Task 7.

**Files:** Create `internal/capability/{compare.go,render.go,compare_test.go}`,
published/embedded `capability-report-v1.schema.json`, and synthetic
`docs/api/examples/capabilities/comparison.{json,md}`.

**Interfaces:** `capability.Compare(m model.Map, p Profile, explanations []Explanation)
(Comparison, error)` and `capability.Markdown(report Comparison) []byte`.
An explanation names the exact difference, category, certainty, rationale, and
evidence; categories are version, plugin, role/permission, namespace/alias, unknown.

1. Write `TestCompareKeepsEvidenceSeparate` with documented names
   `accountsummary`, `listaccts`, `EcosystemFeatures/is_ready` and advertised
   `accountsummary`, `listaccts`, `is_ready`, `custom_plugin_call`.
   Assert two shared, one docs-only, two runtime-only, no automatic alias, and
   no unsupported assertion. A reviewed explicit alias may add an explained
   relationship but preserves the original difference sets.
2. Add tests proving case is preserved, duplicate advertised strings do not
   inflate counts, failed capture returns unavailable comparison rather than
   100% docs-only, and unknown version formats retain unknown explanations.
   UAPI inventory stays unavailable; feature lists cannot supply its denominator.
   Changing the saved host does not change the public documentation denominator
   or any PHP implementation count.
3. Run the comparator tests to see failures, then implement the pure set
   comparison and evidenced explanation records. Keep server version, API family,
   account context, capture time, and capture status visible in both outputs.
4. Add `capabilities compare --map PATH --profile PATH --output PATH --markdown
   PATH [--explanations PATH]`. Regenerate synthetic JSON/Markdown examples and
   confirm repeated output is identical. Run all Go tests and close `gwi.5`
   only after both children pass; report real-server verification as not run.
