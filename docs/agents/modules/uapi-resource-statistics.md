# UAPI resource and web statistics

> Agent contract notes for `bandwidth()`, `resourceUsage()`, `statsBar()`, `stats()`, `statsManager()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The resource and web statistics slice exposes eleven GET operations across
`bandwidth()`, `resourceUsage()`, `statsBar()`, `stats()` and `statsManager()`.
Bandwidth protocols, retention and grouped queries complement resource limits,
statistics-bar values, domain bandwidth, daily AwStats counters, error logs,
report-file inventory and analyzer configuration. `StatsManager/save_configuration`
is the first generated typed JSON-body operation. Generic `call()` remains form-only.

`statsManager()->saveConfiguration($configuration)` replaces the account's analyzer
configuration. Explicitly call `getConfiguration()` first and prepare the complete
intended domain/analyzer set. Round-trip `enabled_by_user` (the user choice) into
input `enabled`, rather than the effective `enabled` returned by the read. Analyzers
absent from the read receive defaults. A partial-looking input can reset other
settings to defaults. The client makes no implicit read, merge, patch, retry, restore or rollback.
The [PR73 reviewer](https://github.com/hostingpal/whm-api-client/pull/73#pullrequestreview-5722705880)
reports that the 11.138.0.19 handler builds defaults for every domain/analyzer,
applies changes, then saves; its POD says unspecified configurations are disabled.
This is attributed upstream evidence, without a promise that every default is
universally disabled.

The required input is `array{changes: non-empty-list<array{domain: non-empty-string,
analyzers: list<array{name: string, enabled: bool}>}>}`. Every listed field is
required; only the zero-byte domain string is rejected. `'0'`, `'00'` and whitespace
are forwarded unchanged; this is not domain validation. `analyzers: []` and empty
analyzer names remain valid inputs. Native bools map to integer 0/1 wire flags.
Names remain strings without a closed analyzer enum. Unknown members, malformed
lists, nulls and wrong scalar types fail locally before I/O. Requiredness is an
operation-scoped correction to the formally optional pinned 11.138.0.10 input,
bound to the attributed 11.138.0.19 handler and raw server observations. Valid
structure does not establish completeness, ownership, availability or persistence.

This method sends one direct-cPanel UAPI POST with exactly `Content-Type:
application/json`. The WHM bridge rejects it locally after clearing metadata.
Available from cPanel 90 with the WebServer role; root/reseller control can prevent
user changes. Returned domain/analyzer fields remain optional, with native integer
0/1 flags. On 11.138.0.19 the source uses the same row builder as `getConfiguration()`:
`name`, `enabled`, `enabled_by_user`. `locked` and analyzer `available_for_user` belong
to response metadata: `locked=1` is reseller/root-only and `locked=0` is user-manageable.
Inspect `responseMeta()` warnings for skipped `not_owned`, `not_available` and
`unrecognized` entries even with status 1. An empty analyzer name produces an
`unrecognized` warning, while the handler silently skips an empty domain. The client
rejects the empty string locally; it does not establish validity of other domains.
No payload `raw_warning` field is invented;
success does not prove every requested setting applied. Check persistence separately.

The successful live-write gate in `8x0.1.18` remains **OPEN**. The reviewer's
well-formed raw direct-JSON requests bypassed this client and were blocked by
`_can_self_manage` on 11.138.0.19: “You may not modify the weblog analyzer
configuration”. Passing the gate requires a root/reseller server-wide statistics
setting change; this work authorizes no such change and ran no live calls.
Eight other pinned JSON-body operations remain deferred; remaining
selection/source/route audit is tracked under `8x0.29.6`.

Bandwidth grouping remains a pipe-separated scalar string. Domain, protocol
and required StatsBar display selectors also accept nonempty sequential string
lists joined once with |; all supplied values are encoded once. Omitted options preserve server defaults; explicit
false flags are sent as zero. Keep bytes, timestamps, per-resource units and
formatted display strings distinct. Resource `maximum: null` means unlimited;
zero and omission remain separate. Dynamic domain/date/grouping keys remain keys
in returned arrays and are wildcarded in structural evidence. The pinned
Bandwidth grouping prose and response examples establish one to three dynamic
key levels ending in integer or fractional byte values. The overlay repairs the
formal schema's object-only leaves while retaining nullable second/third values
and empty maps; numeric strings and opaque fourth-level leaves are not inferred.
The result shape does not enforce correspondence with the supplied grouping.
Resource maximum/usage, StatsBar percent and bandwidth month-start values accept
lossless canonical integer strings only in their reviewed fields. A null
resource URL remains distinct from omission; fractional strings are not rounded
or silently coerced. StatsBar preserves observed null `count` values and string
or object `role` values whose dynamic values are strings or string lists. The
role map follows the final structural capture; it does not identify the map keys
or their meaning. Other percentage and flag rules stay unchanged.
`listSites()` normalizes only exact quoted `ssl` flags to integer 0/1, preserving
omission and the native flags. These field corrections follow the
[PR38 reviewer observations](https://github.com/hostingpal/whm-api-client/pull/38#pullrequestreview-5719939412)
on cPanel `11.138.0.19`; they do not establish every display or analyzer variant.
The live/error-log follow-up remains `8x0.2.25`.

Statistics require the documented features, roles and analyzer availability.
`getSiteErrors()` returns log text as payload data; report URLs are returned
without being fetched. Analyzer configuration metadata stays in `responseMeta()`.
The fixed live catalog targets only `npr`/`npr.rs`, bounds the bandwidth query to
October 2026 UTC, and limits the error-log read to one entry. No configuration,
statistics generation or log contents are changed by these reads.

On `test.oblak.host` with cPanel `11.138.0.19`, the reviewer found that
`getSiteErrors(log: 'error')` and the default failed because `npr` could not read
the root-owned mode-0600 `/var/cpanel/cpanel.config`. The same operation with
`log: 'suexec'` returned populated typed rows on both routes. This is an attributed
environment observation, not a general API restriction. The server file was left
unchanged and the earlier failed artifacts remain failed and unwaived.
The subsequent 27-GET review capture passes every HTTP/API outcome and 25 payload
checks, including all three query depths, Analog sites and populated `suexec`
rows. Its two StatsBar role mismatches establish the bounded-map correction;
two additional masked unknown-field findings remain strict and unwaived. The
artifact stays failed. After Claude verified the corrected code, a separate
27-GET capture at `023d2dd` passed all HTTP/API/payload/probe checks on that
contract. It remains strictly failed only for the same two masked unknown-field
findings, which are unwaived. See `docs/api/source-audit.md` for both immutable
artifacts, exact revisions, hashes and coverage limits.

The corrected [statistics capture](../../api/evidence/2026-10-31-resource-statistics-corrected.json)
at `a439c3c` on `11.138.0.19` records 23 HTTP-200 GETs. Twenty-one API/payload/probe
checks pass with zero discrepancies; both `Stats/get_site_errors` API calls fail,
so strict status remains failed. Ten new functions pass on both routes. Daily
statistics, site/report lists and analyzer lists are empty; populated variants
remain offline coverage. No settings or log content changed. Follow-up `8x0.2.25`
retains the unresolved log errors and other unobserved variants.
