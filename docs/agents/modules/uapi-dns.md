# UAPI DNS

> Agent contract notes for `uapi()->dns()` and `uapi()->zoneEdit()`. Index: [docs/agents](../README.md).

`uapi()->dns()` covers all eleven pinned DNS functions for the selected account: nine
GET reads and two form POST zone writes, `massEditZone()` and `swapIpInZones()`. Both
writes require cPanel 96 and declare no rollback. The pinned source lists them as GET
with `x-readonly=false`; the client sends form POST.

The original implementation observations below come from one-off runs on 2026-11-18 against
`test.oblak.host` on cPanel `11.138.0.24`, on the disposable account `wldnszed`
(created and removed by each run, absence confirmed). The first run used the generic
`call()` at master `8c2834de` and recorded value types and error text only. The second
run called the generated methods of this slice. Both used direct UAPI and the WHM
bridge. Those two runs produced no committed capture.
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
- Observed through this method, on both routes: no change supplied and a stale serial
  each raised `ApiErrorException` with the zone unchanged. Observed only with the
  generic `call()`: malformed `add` JSON and a zone the account does not control each
  returned UAPI status 0 with the zone unchanged; under `Flag` this method raises the
  same exception, which the offline tests cover. The stale-serial failure carries a
  `data` object (`type`, `detail`) that `Flag` does not expose. The malformed-JSON
  error text quotes the supplied record text, so `errors()` can contain record data.
- A `PayloadMismatchException` after a successful envelope follows a zone that already
  changed. Source read: the handler rejects an empty change set and reports success
  only after the admin call applied the changes and produced the new serial.
- Source read: the function needs one of the `simplezoneedit`, `zoneedit` or
  `changemx` features and is refused in demo mode. Accepted record types depend on
  those features. The original runs used `TXT` records. The two-zone recipe exercised `A` records.

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
  loading it, unlike the functions around it. Direct UAPI works. Input errors report
  normally on both routes: an invalid address and a domain the account does not
  control raised `ApiErrorException` through this method, and a request with no
  `domain`, which this method cannot send, returned status 0 to the generic `call()`.
  The client keeps the bridge route and adds no fallback. This is one build; re-check
  it after cPanel updates before describing it as general.
- Observed on direct UAPI: one domain and a two-item domain list each returned six
  rows (four `A`, two `TXT`), every row with all five string fields.
- Observed: a `sourceIp` that matched no record still returned two `TXT` rows and
  increased the zone serial. Source read: every SPF `TXT` record is rewritten and
  reported whether or not an address matched, and each processed zone gets a new
  serial and is synchronized and reloaded. A call that replaces no address can still
  return SPF `TXT` rows. The reverse also holds: a call that skips every temporary
  domain replaces no address and returns an empty list (see below).
- Pinned rule for an omitted `sourceIp`, per zone: the zone root's `A` record if its
  address is not a loopback address; otherwise the first `A` record in the zone file
  whose address is not a loopback address, which can belong to another name; with no
  `A` record or only loopback addresses, the zone file is not updated. Do not shorten
  this to "the apex record".
- Source read: `A` records whose address equals a source address are replaced; names
  that start with `ftp.` receive `ftpIp`, which defaults to `destIp`.
- Source read: detection follows the pinned order but runs once per call, not once per
  zone. The first zone processed that yields an address decides it for every zone
  processed after it, and that order is not the caller's. A zone without a candidate is
  skipped only until an address has been found; after that it is processed with the
  found address and its SPF records are rewritten. The two-zone recipe below independently confirmed reuse of one source across zones with distinct apices.
- A separate one-off test on 2026-11-18 used the generated `swapIpInZones()` at
  `1e3f79c3` on cPanel `11.138.0.24`. cPanel assigned a temporary addon domain
  to a new disposable account. The client's account-scoped inventory returned one
  addon, and `isTemporaryDomain()` confirmed its type. For that domain alone,
  direct UAPI and the WHM bridge each returned HTTP 200, UAPI status 1, an empty
  list, and a warning naming the skipped domain. This case exits before the zone
  update. The lifecycle confirmed account, token, primary domain and zone absence.
  A separate server check found zero temporary-domain assignments for the account.
  This observation is independent of the two-zone recipe.
- Source read: the function declares no feature requirement; the admin layer checks
  that the caller owns every domain.

- After a `PayloadMismatchException` that follows a successful envelope, the zones may
  already have changed or none may have: a successful call can skip every domain. Read
  the zones before deciding whether to retry.

Neither method rereads, retries or rolls back. Changes to public zones are published
and cached by resolvers.

## Reproducible account-scoped evidence

The `dns-zone-writes.php` recipe captured 30 typed probes on cPanel
`11.138.0.24`. On each route it added, edited and removed an A record, deriving
the serial and edit index from live readback. It gave each of two distinct
zones a different apex address and put both candidate addresses in both zones.
Direct UAPI returned matching A change rows for both zones. Only the markers
for one detected source changed, and both SOA serials advanced. The WHM bridge
returned the known inner API error. Both complete typed zone snapshots stayed
unchanged across that request. See the [live recipe](../../../tools/live-verify/README.md#uapi-dns-zone-writes-on-a-disposable-account)
for the evidence policy and command. The v4 run inspected the original responses
and verified that the optional resource-record field `dname_raw` was a string equal
to decoded `dname_b64`. The typed parse now preserves this field when present.

## `uapi()->zoneEdit()->resetZone()`

The cPanel 138 pin documents a destructive rebuild from the server template, with
no rollback. The DNS role must be enabled. The installed 11.138.0.24 handler in
`Cpanel/API/ZoneEdit.pm` sets `returns => 'list'` on the FromAPI2 adapter.
`Cpanel/ZoneEdit.pm::api2_resetzone` wraps the admin reply in a single result
row. `Cpanel/Admin/Modules/Cpanel/zone.pm::RESET` verifies ownership and the
`zoneedit` feature, then selects the rebuild status, message and MX check
from `Whostmgr/DNS/Rebuild.pm`. The helper also returns serialized zone text,
which the admin handler discards. On this build it incorporates uncommented TXT
lines from readable old zone data into the new template. Rely on a backup for custom records.

The flat pinned OpenAPI response describes the inner UAPI envelope. A return
requires a successful dispatch and exactly one result row with a strict integer
rebuild status of 1. Either outer WHM rejection, inner UAPI rejection, or rebuild
status 0 raises `ApiErrorException`. The message never includes freeform rebuild
text. Missing or ambiguous outcomes raise `PayloadMismatchException` with
`outcomeConfirmed` false. When status 1 is established, malformed ancillary data
raises the same exception with `outcomeConfirmed` true. The pin leaves MX metadata
fields unspecified. The client preserves the map, explicit null and omission.

At `aee6a547` on 2026-11-18, each route reset the dedicated domain of fresh
account `wldnsreset` on cPanel `11.138.0.24`. A distinct custom A record was read
before each reset and absent afterwards. Each HTTP 200 response had one rebuild
row with integer status 1, an empty status message and a non-null MX object.
The account, token, primary domain and zone were confirmed absent after cleanup.
Rebuild failure is source-backed and covered by synthetic fixtures. See the
[sanitized probes](../../api/evidence/2026-11-18-uapi-zone-reset.json).

Reproducible readback capture is tracked in `whm-api-client-8x0.2.99`.

At `323496bc`, the revised policy also passed on both routes with a fresh
disposable zone. The [v2 capture](../../api/evidence/2026-11-18-uapi-zone-reset-v2.json)
includes all six typed readbacks and both reset responses. Each route's record
was absent before planting, present before reset and absent afterwards.
