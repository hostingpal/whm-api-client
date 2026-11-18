# WHM DNS management

> Agent contract notes for `whm()->dns()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM DNS management

The WHM DNS group covers 43 of 44 pinned functions. It includes two single-record
form POST operations, `addZoneRecord()` and `editZoneRecord()`. The remaining
exclusion is the deprecated `dumpzone`. Both writes return `void` on success.
The record array uses wire keys such as `txtdata`. The client sends them as form
fields and checks that the supplied fields belong to one record type.

```php
$dns = $client->whm()->dns();
$dns->addZoneRecord(
    domain: 'example.test',
    name: 'verify.example.test.',
    record: ['type' => 'TXT', 'txtdata' => 'proof of ownership'],
);
```

For an edit, read the zone immediately beforehand. Identify the intended row
uniquely by owner, type and current data, and take the SOA serial from that same
read. Pass `line_index + 1` as the positive `line` and the serial as `serialnum`.
The handler merges supplied fields into the current row. For example,
`record: ['address' => '192.0.2.42']` replaces the address of the selected A
record while preserving its name, type and TTL. A stale serial refuses the edit.
An add has no serial check. Read the zone after a write with an uncertain outcome
before deciding whether another change is needed.

The typed methods accept only the 18 mapped record families and their declared wire
fields. For a server-supported type or field outside this set, use `whm()->call()` with form
POST.

The pinned TTL minimum is 1. The client forwards an explicit zero. On cPanel 11.138.0.24
the inspected A-record serializer falls back to the effective default TTL for zero. On
edit this can replace an existing nondefault TTL.

For LOC, the 11.138.0.24 serializer takes bare integer size and precision in
centimeters. It appends only truthy values in altitude, size, horizontal precision,
vertical precision order. Skipping an earlier position shifts later values on the wire.
For a LOC record with latitude and longitude set, this optional tail represents
altitude 5 m, size 10 m, horizontal precision 20 m and vertical precision 30 m:

```php
['altitude' => '5m', 'size' => 1000, 'horiz_pre' => 2000, 'vert_pre' => 3000]
```

This example follows the installed serializer. The live runs did not populate LOC.

### Contract check on cPanel 11.138.0.24

On 2026-11-18 two one-off runs used `whm()->call()` with form POST and
`OutcomePolicy::Unchecked` at client revision `1e3f79c3` on `test.oblak.host`.
The disposable account, token, domain and zone were confirmed absent after each
run. Successful writes returned WHM result 1 with the data member absent.

- An A add omitted line, class and TTL. An edit using only the one-based line,
  domain and replacement address retained the owner, type and TTL. An explicit
  A edit supplying TTL retained its address. The stale-serial edit returned
  result 0 and readback confirmed the preceding TTL and address.
- TXT and PTR adds produced identifiable rows. On a fresh zone, CAA, NS, and
  DS under the delegated child produced identifiable rows. The working DS fields
  were `keytag`, `algorithm`, `digtype`, and `digest`. A missing A address
  returned result 0. An earlier DS and CAA call following an A6 add both returned
  result 0. Those observations do not isolate the cause.
- Both A6 adds (prefix zero and an IPv6 address) returned result 1. Neither
  subsequent `parseDnsZone()` identified an A6 row for the supplied owner.
  Check persisted zone state before relying on this variant.

An initial generated-method run exercised a pre-commit version in this worktree (base `1e3f79c3`,
generated `DnsSpec.php` SHA-256
`1267254fb3c94ad6c600944e08e6a5f55c25b2fc59d813203c76167de1ed11b8`).
A, PTR and TXT adds succeeded. Untagged address and explicit A TTL-only edits
passed readback. A stale serial raised `ApiErrorException`, and readback confirmed
the preceding address and TTL. Account, token, domain and zone cleanup passed.

A fourth run on the same server exercised commit
`963c98f8b76c6c1d154eb47ff10c3bb2eed1c80a`, generated `DnsSpec.php` SHA-256
`3b4baa617281b2a422df3fe3bb0f3756a298373f12d05a523ef93d421996a254`.
The six form POSTs covered A, PTR and TXT additions, an untagged A address edit,
a tagged A TTL edit and stale-serial refusal with the integer guard. Fresh reads
confirmed the changes, preserved fields, and unchanged A record after refusal.
The account, token, domain and zone were confirmed absent after cleanup.

The installed `Whostmgr::DNS` and `Cpanel::ZoneFile` sources explain several
pin discrepancies. Add appends, and the serializer defaults class to IN.
Edit reads the one-based line and overlays only supplied fields before validating
the result. It accepts `serialnum` for optimistic concurrency. The serializer
spells the SOA line-count key `Lines` and consumes `minimum`. It uses `address`
for A6. Apex CNAME flattening is implemented by the edit handler. These source
readings extend beyond the variants exercised by the HTTP checks.

`massEditDnsZone(int $serial, string $zone, ?array $add = null, ?array $edit = null,
?array $remove = null): array` sends repeated literal form fields in one POST
(cPanel 96+). **Both `remove` indices and `edit.line_index` are 0-based. Use
`line_index` from `parseDnsZone()`.** Reviewed `dumpzone` `Line` values are 1-based;
passing them directly can delete another record while the API reports success.
Prepare the current SOA serial and record indexes yourself:

```php
$result = $client->whm()->dns()->massEditDnsZone(
    serial: $currentSerial,
    zone: 'example.test',
    add: [json_encode([
        'dname' => 'example.test.', 'ttl' => 14400,
        'record_type' => 'TXT', 'data' => ['example text'],
    ], JSON_THROW_ON_ERROR)],
    remove: [$lineIndex],
);
```

`add`/`edit` require nonempty sequential string lists of caller-serialized JSON;
`remove` requires a nonempty sequential integer list. Order, duplicates, zero and
string bytes survive without parsing, joining or index conversion. Null or omitted
families send no fields; omitting all three still sends serial/zone once. On reviewed
cPanel `11.138.0.19`, upstream rejects that empty change set with `ApiErrorException`
and no zone change. The client adds no minimum-one-edit guard or local no-op. Empty
lists reject locally. Record fields and type-dependent data formats remain the caller's
responsibility; edit's `line_index` is zero-based. All three collection arguments
receive the generator's existing trace redaction; add/edit may contain private data.

Stale serials and malformed `add` JSON also raise `ApiErrorException` without a zone
change on reviewed `11.138.0.19`. Each surviving record retains its line count per
the pinned source; this does not promise unchanged offsets or safe index reuse.
Optional `new_serial` is returned as an integer: native integers and evidenced
canonical in-range decimal strings normalize losslessly; omission stays absent.
Callers must track lines per record for later edits. Temporary domains are
unsupported. No reread, serial refresh, retry, rollback, atomicity or DNS propagation
guarantee is added.

`Flag` raises on `result=0` before payload validation, so unvalidated failure
`data.by_line` details are not exposed. The reviewer identified the `InvalidZoneFile`
detail path in server source but did not exercise it. The pinned operation gives
`A` and `TXT` as examples without a complete record-type enum; reviewer inspection
of the `11.138.0.19` handler found a fixed set excluding `SOA`. These observations
are attributed in [the correction audit](../../api/source-audit.md#whm-dns-mass-edit-review-correction-2026-11-01),
separately from committed captures.

DNSSEC enable/disable uses repeated domain fields; other selectors retain their
own scalar contracts. `addZoneKey()` and each `enableDnssecForDomains()` row return
integer `new_key_id` values, accepting bounded canonical integer strings so the
IDs can be passed to the key-selector methods. `exportZoneDnskey()` normalizes its
`key_id` by the same rule. `importZoneKey()` preserves both the pinned
`import_key_id` integer and the source-backed optional `new_key_id`, with canonical
integer-string compatibility on the latter. Omitted fields remain absent.

`exportZoneKey()` preserves optional string `key_content` alongside key tag/type.
That field comes from separately reviewed upstream source, not a new raw export
observation. It is private key material: protect returned values and raw
`PayloadMismatchException::$payload` rather than logging them. Import input stays
sensitive; no export/import replay, automatic retry or rollback is performed.

`removeZoneRecord()` takes a one-based zone-file line. When selecting from
`parseDnsZone()`, pass `line_index + 1`, never the zero-based index itself. This
mapping is source-backed and has not been verified by a live record removal.
Use a current zone read and the applicable serial number; a stale or incorrect
line can remove the wrong record. `deleteDomain()` can succeed with `type: null`
when no domain exists, so a successful envelope does not establish deletion.
Domain deletion has no rollback and does not delete an addon's associated
subdomain. `resetZone()` requires `domain` or `zone`; it preserves valid TXT
records but resets other records to defaults, including subdomain records.

The WHM pinned schema lists `nsec3_iterations: 7` and no salt default. These are
pinned WHM declarations, not a promise about every server version's behavior;
omitted values are sent as omissions for the server to resolve. DNSSEC and
resolver/nameserver changes can immediately affect live hosting. Check per-domain
results independently of WHM metadata. No automatic polling or inference of DNS
propagation, authority, trust or DNSSEC validity is added.

All methods have offline contracts. The separate WHM reviewer report on
`11.138.0.19` exercised selected DNSSEC and disposable-account domain/zone calls,
with account, home directory and all three throwaway zones removed. No new
committed capture accompanies these corrections. The focused raw-export/import/
remove-record pass was rejected before execution with `[Modify Shared Resources]`
and was not retried. Those corrections remain source/offline evidence. Server-wide
writes and other remaining variants stay under `8x0.2.42`; existing artifacts are
unchanged.
