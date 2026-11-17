# Server-wide live battery, 2026-11-17

Run against `test.oblak.host` on cPanel `11.138.0.24` at revision `8be370a`, inside the
snapshot window described in [docs/live-testing.md](../../../live-testing.md), before the
explicit restore from Hetzner image `457432750`. The two scripts here are the exact
one-off harnesses used: they drive the typed client through a guarded cURL transport
(only `test.oblak.host` on ports 2083/2087, verified TLS, no redirects) and need
`WHM_API_LIVE=1`, `WHM_API_LIVE_MUTATIONS=1` and `WHM_API_LIVE_SERVER_WIDE=1`. The
summaries keep outcome classes only; upstream error text stayed in an uncommitted local
log, and the returned API token secret was never written.

## Wave 1: batch-adopted server-wide methods (`wave1-summary.json`, 29/29 passed)

- Read-only: `version`, `systemNeedsReboot()`, WebPros auth status, PHP-FPM config reads.
- Server-wide: WebPros auth clear, `set360AgentPolling(interval: 60)`, `saveSpamdConfig()`
  with a list `allowedIps`.
- API tokens: `createToken()` with list `acl` and `whitelistIp`; a rename-only
  `updateToken()` preserved both ACLs and both whitelist addresses (the partial-update
  behaviour PR127 documented from source); `revokeToken()` with a list that included an
  unknown name succeeded and removed the real token.
- Reseller fixture on disposable `wsresel`: `setupReseller()`, list
  `setResellerNameservers()`, `getResellerIps()`, `unsetupReseller()`, removal confirmed.
- Lockout group last: `phpFpmConfigSet()` with `validate_only: true`, `updateSqlConfig()`
  (`mysqld` `max_allowed_packet=64M`), then `version` again.

The first wave-1 run used two invalid inputs of the harness's own: the WHM ACL name `basic`
(rejected as invalid or unauthorized, so the dependent rename also failed) and
`trinary_admin_value: 1` for `pm_max_children` (the handler only allows 0). In that run,
`revokeToken()` with two nonexistent names still returned success, live-confirming the
PR127 caveat that revocation success does not prove a token existed. The committed
summary is the corrected rerun.

## Wave 2: features disabled on this box (`wave2-summary.json`, 23/23 passed)

- Greylisting enabled, its status, netblock, common-provider, trusted-host and
  configuration reads observed, then disabled again. While disabled, the read-only
  catalog records API errors for these reads.
- ModSecurity: `updateSettings()` record list re-saving one setting's current state,
  `deploySettingsChanges()`, and readback showing the state unchanged.
- cPHulk (already enabled, so it was left enabled): list `createRecord()` on the
  whitelist with documentation-range addresses, `readRecords('white')`, list
  `deleteRecord()`.

In two of three wave-2 runs, `readCpgreylistTrustedHosts()` immediately after
`enableCpgreylist()` hit a transport failure (no HTTP response), while a raw request and the
third run succeeded. This is recorded as a transient effect of enabling the service, not a
client contract finding.
