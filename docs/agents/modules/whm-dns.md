# WHM DNS management

> Agent contract notes for `whm()->dns()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM DNS management

`whm()->dns()` exposes 41 of the 44 pinned DNS functions: sixteen GET reads and
25 form POST methods for zone inventory, DNSSEC keys, domains, MX records,
resolvers, nameservers and maintenance. Deprecated `dumpzone` remains excluded.
Two record-write operations remain under `8x0.1.30`: `addzonerecord` and
`editzonerecord` require composed form schemas.

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
separately from committed captures. Composed `addzonerecord`/`editzonerecord` forms
remain deferred under `whm-api-client-8x0.1.30`.

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
