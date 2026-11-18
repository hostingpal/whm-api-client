# UAPI DNS

> Agent contract notes for `uapi()->dns()`. Index: [docs/agents](../README.md).

`uapi()->dns()` covers all eleven pinned DNS functions for the selected account: nine
GET reads and two form POST zone writes, `massEditZone()` and `swapIpInZones()`. Both
writes require cPanel 96 and declare no rollback. The pinned source lists them as GET
with `x-readonly=false`; the client sends form POST.

Everything marked "observed" below comes from one-off runs on 2026-11-18 against
`test.oblak.host` on cPanel `11.138.0.24`, on the disposable account `wldnszed`
(created and removed by each run, absence confirmed). The first run used the generic
`call()` at master `8c2834de` and recorded value types and error text only. The second
run called the generated methods of this slice. Both used direct UAPI and the WHM
bridge. No capture artifact is committed; a recipe is tracked under `8x0.2.97`.
"Source read" means the server's own Perl on that version, not the pinned documents.

## massEditZone

`massEditZone(int $serial, string $zone, ?array $add = null, ?array $edit = null,
?array $remove = null): array` has the same shape as `whm()->dns()->massEditDnsZone()`.
Read [WHM DNS management](whm-dns.md) for the shared rules.

- `add` and `edit` are nonempty sequential lists of caller-serialized JSON strings and
  `remove` is a nonempty sequential integer list. Items become repeated literal fields
  in caller order. Null omits a family; empty lists fail locally. Record text is a
  sensitive parameter.
- `remove` indexes and `edit.line_index` are 0-based. Use `line_index` from
  `parseZone()`.
- `new_serial` is pinned as an integer. Observed: a string of digits on both routes,
  on every success. It is normalized to `int`, and the returned value matched the
  zone's serial afterwards.
- Observed: two repeated `add` fields both landed, and one call with an edit and a
  removal changed exactly those records, on both routes.
- Observed failures, each an `ApiErrorException` with the zone unchanged: no change
  supplied, a stale serial, malformed `add` JSON, a zone the account does not control.
  The stale-serial failure carries a `data` object (`type`, `detail`) that `Flag` does
  not expose. The malformed-JSON error text quotes the supplied record text, so
  `errors()` can contain record data.
- Source read: the function needs one of the `simplezoneedit`, `zoneedit` or
  `changemx` features and is refused in demo mode. Accepted record types depend on
  those features. Only `TXT` records were exercised live.

## swapIpInZones

`swapIpInZones(string|array $domain, string $destIp, ?string $sourceIp = null,
?string $ftpIp = null): array` returns a list of rows with optional string
`new_value`, `old_value`, `record_name`, `record_type` and `zone_name`.

- `domain` is one string or a nonempty sequential string list, sent as repeated
  literal `domain` fields. The pinned text also describes numbered names, but names
  `domain-0` as the first while its example leaves the first unsuffixed; no numbered
  family is adopted.
- The pinned `data` node has array prose and an `items` schema but no `type: array`,
  which would lower the result to `mixed`. The overlay restores the list container
  around the pinned item fields.
- **Observed: the WHM bridge fails.** Every bridged call that reached the zone update
  returned UAPI status 0 with `Undefined subroutine &Cpanel::AdminBin::Call::call`.
  Source read: `swap_ip_in_zones` in `Cpanel/API/DNS.pm` calls that module without
  loading it, unlike the functions around it. Direct UAPI works. Input errors (invalid
  address, domain not controlled, missing domain) report normally on both routes. The
  client keeps the bridge route and adds no fallback. This is one build; re-check it
  after cPanel updates before describing it as general.
- Observed on direct UAPI: one domain and a two-item domain list each returned six
  rows (four `A`, two `TXT`), every row with all five string fields.
- Observed: a `sourceIp` that matched no record still returned two `TXT` rows and
  increased the zone serial. Source read: every SPF `TXT` record is rewritten and
  reported whether or not an address matched, and each processed zone gets a new
  serial and is synchronized and reloaded. An empty list is therefore not what a
  "nothing replaced" call returns.
- Source read: `A` records whose address equals a source address are replaced; names
  that start with `ftp.` receive `ftpIp`, which defaults to `destIp`. With several
  domains and no `sourceIp`, the address is detected once, from whichever zone the
  server processes first, and reused for the rest. Unobserved: both test domains
  shared one zone.
- Source read: temporary domains are skipped with a warning in `responseMeta()`; if
  all are skipped the result is an empty list. Unobserved.
- Source read: the function declares no feature requirement; the admin layer checks
  that the caller owns every domain.

Neither method rereads, retries or rolls back. A `PayloadMismatchException` after a
successful envelope follows zones that already changed. Changes to public zones are
published and cached by resolvers.
