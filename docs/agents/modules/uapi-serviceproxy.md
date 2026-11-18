# UAPI ServiceProxy

> Agent contract notes for `uapi()->serviceProxy()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The complete `uapi()->serviceProxy()` group exposes all three pinned ServiceProxy
operations from cPanel 96: GET inventory and form POST set/unset calls for the
selected account. `getServiceProxyBackends()` returns a list with optional string
`backend` and optional nullable string `service_group`; null denotes the general
backend, while omission remains distinct. Treat returned addresses and configuration
as private infrastructure information.

[Review 5725574081](https://github.com/hostingpal/whm-api-client/pull/103#pullrequestreview-5725574081) of original
`4462b68f73730ede842dd617a926bba23565af04` reports all three operations and
`setBackends()` succeeding through direct UAPI on cPanel `11.138.0.19`, while the
WHM bridge raised `ApiErrorException` because it could not load ServiceProxy.
The reviewer attributes this to an absent on-disk module and different compiled-
binary/direct versus disk-loader paths from their server-source inspection; that
explanation was not independently inspected locally. This build-scoped observation
keeps routing available and adds no fallback; it does not establish every version.

`setServiceProxyBackends(?string $general = null, ?string $serviceGroup = null,
?string $serviceGroupBackend = null)` preserves the source optionality. Omitted
settings retain existing configuration; null omits and empty strings are sent.
Supplying a service group or its backend requires the counterpart on the server;
the canonical method adds no cross-field guard. General-only updates remain valid
client input. The pinned source names `Mail`; strings remain open in the interface,
without promising server acceptance of other groups or doing local DNS/IP checks.
The server requires routable backends and rejects its documented non-routable
address classes and obfuscated encodings. In that direct-UAPI review, tested
loopback, RFC 1918 and cloud-metadata literals failed with generic log-ID text and
no caller-visible cause. Use the error ID with server logs to investigate; this
does not explain failures for untested destinations.

The no-argument canonical setter and `general: ''` returned `null` without changing
stored values in that review. Omission/null and empty-string forwarding stay as
documented. A null API-success result alone does not prove a configuration change;
readback remains caller-controlled.

The additional generated `setBackends(array $backends, ?string $general = null)`
accepts a nonempty sequential list of complete string pairs. Both row fields are
required; partial pairs, nulls, unknown fields and wrong types fail before I/O.
For example, these synthetic inputs execute one request when called:

```php
$account->serviceProxy()->setBackends(backends: [
    ['serviceGroup' => 'Mail', 'backend' => 'mx1.example.test'],
    ['serviceGroup' => 'Mail', 'backend' => 'mx2.example.test'],
], general: 'proxy.example.test');
```

Rows emit `service_group`/`service_group_backend`, then `service_group-1`/
`service_group_backend-1`, retaining exact values, row order and duplicate groups;
optional `general` follows. The reviewed direct two-`Mail`-row call stored the
second backend, establishing acceptance of those bare/underscore `-1` names and
last-duplicate behavior for that tested case on `11.138.0.19`. This does not establish
other versions or atomicity. The formal names and naming prose use underscores,
while one pinned multiple-group example uses `service-group-1/-2`; that historical
source discrepancy remains recorded. Supplied backend/general addresses and the whole paired
collection are sensitive parameters. Avoid logging returned configuration or
`PayloadMismatchException::$payload`; library frames conceal sensitive inputs and
responses, but third-party transports control their own traces.

`unsetAllServiceProxyBackends()` removes all service proxy configuration for the
selected account. Both mutations preserve nullable opaque object results, including
unknown fields and explicit null; missing data is a mismatch. Existing empty-array
object-validator compatibility is retained. With the Web Server role active, writes
rebuild vhosts and restart the web server. A rebuild failure can still return API
success with a failure warning in metadata: inspect `responseMeta()` warnings and
raw metadata. Success does not guarantee application or persistence. The client
adds no implicit read, merge, save, restore, retry, rollback or backend contact.
Delivery `8x0.4.28` provides generated/offline-tested coverage. The runtime
observations above belong to the reviewer at the original commit; this integration
performs no live requests or capture. Live follow-up `8x0.2.70` remains open.
