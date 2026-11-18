# UAPI DNSSEC and Dynamic DNS

> Agent contract notes for `dnssec()`, `dynamicDns()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The DNSSEC and Dynamic DNS groups cover all seventeen pinned functions.
`dnssec()` exposes key lifecycle, DNSKEY/private-key export, DS records and NSEC
settings; `dynamicDns()` exposes creation, inventory, ID replacement, description
updates and deletion. DNSKEY/DS reads and Dynamic DNS inventory use GET; the other
fourteen methods use form POST, including private-key export.

DNSSEC requires the DNS role and retains each method's version and registrar
requirements. Disabling DNSSEC destroys its keys and requires registrar record
removal; the client provides no retry or rollback. Dynamic domain/key maps retain
their returned keys, optional fields and per-domain results separately from the
API envelope. `enableDnssec()`, `disableDnssec()` and `fetchDsRecords()` accept
`domain` as a string or a nonempty sequential string list. Scalars retain `domain`;
arrays always use zero-based `domain-0`/`domain-1`, including singleton arrays.
Other DNSSEC methods retain their existing arguments. Remaining indexed families
and source contradictions stay under `8x0.1.21`. The separate
[2026-10-31 singleton-list capture](../../api/evidence/2026-10-31-dnssec-indexed-input.json)
at `5e0ea6b` on `11.138.0.19` passes three GETs and strict assessment with zero
discrepancies. It confirms DS singleton-list acceptance on both routes; domain
detail objects are empty, and this capture changed no DNS, key, registrar or
preference state. Separately, [review 5722987368](https://github.com/hostingpal/whm-api-client/pull/76#pullrequestreview-5722987368) of
`ec0796271da96827835bb26d8fb38091cb882e94` on `11.138.0.19` reports direct and
bridged multi-domain enable/fetch/disable, populated DS `keys` and `digests`,
scalar/singleton fetch, an empty re-read after disable, and an unknown-domain
`ApiErrorException` without a partial result. These are reviewer observations
under `8x0.2.26`, distinct from the immutable singleton capture; DNS propagation,
resolver validation and remaining variants are not established.
Omitting `keySetup` retains `classic` even with `algoNum: 13`; choose `simple`
explicitly for the documented combined-key setup above algorithm 8. Key removal
is irreversible and can break validation while registrar DS records reference it.
Reviewed key identifiers normalize canonical in-range integer strings to integers
for direct use in subsequent typed key methods. Digest algorithm identifiers also
accept that narrow conversion; other flags and numeric fields remain strict.
Export payloads expose an optional `success` flag, with no promised `error` field.

Imported key material and Dynamic DNS input IDs are sensitive parameters.
`exportZoneKey()` results and `PayloadMismatchException::$payload` can contain
private keys; do not log either. `fetchDsRecords()` may also return private-key
material, and Dynamic DNS IDs form part
of update URLs; treat those result values as sensitive and do not fetch update
URLs while inspecting inventory. `recreate()` replaces the DDNS identifier and
invalidates its previous update URL; deletion also invalidates its update URL.
`setDescription()` returns no payload. Deletion's source `number` enum `[0, 1]` becomes an integer `0|1` flag under
the existing generator; zero means the identifier did not exist for deletion.
Omitted timestamps and explicit null `last_update_time` remain distinct.

The live catalog reads only DS records for `npr.rs`, Dynamic DNS inventory for
`npr`, and WHM version. It makes no key, registrar, DNS-record or DDNS changes.
Unobserved live variants remain tracked under `8x0.2.26`; API responses alone do
not establish DNS propagation or DNSSEC validation by resolvers.
The final integrated capture at `71e31f1` passes all five GETs and strict checks
with zero discrepancies. Its DS objects and DDNS lists are empty; populated
numeric fields and mutations remain separate reviewer/offline evidence. The
original capture is unchanged; `docs/api/source-audit.md` records both hashes.


The [DNS security capture](../../api/evidence/2026-10-31-dns-security.json) at
`372df78` on `11.138.0.19` passes all five GET observations and strict assessment
with zero discrepancies. DS detail objects and the DDNS inventory are empty;
populated fields and the other fifteen methods have no committed live evidence. No DNS,
key, registrar or DDNS state changed. Follow-up `8x0.2.26` remains open.
