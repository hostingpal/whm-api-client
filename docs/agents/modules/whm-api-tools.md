# WHM API tokens, sessions and introspection

> Agent contract notes for `whm()->apiTools()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM API tokens, sessions and introspection

`whm()->apiTools()` exposes twelve functions from the seventeen-function API
Development Tools group: token lifecycle, session/login-link creation, application
inventory and legacy API usage. Five reads use GET and seven calls use form POST.
The remaining five execution wrappers are separate work under `8x0.4.17`.

`createToken()` and `createDashboardToken()` accept scalar ACL/IP fields or
nonempty sequential string lists. Lists use `acl-0`/`acl-1` and
`whitelist_ip-0`/`whitelist_ip-1`; scalars keep their bare fields. Dashboard ACL
remains required and non-null. Regular creation with omitted ACL grants all caller
privileges; omitted creation IP restrictions allow any IP and omitted expiration
means no expiration. The dashboard method keeps `tokenName` before `acl`.

`updateToken()` requires only `tokenName`; ACL, IP restriction, expiration and
new name are optional, preserving the previous positional order. Supplied ACL/IP
values replace the entire respective setting. Explicit null omits each option;
empty strings remain supplied values, never preservation sentinels.
`whitelistIp: 'any'` explicitly clears IP restrictions and `expiresAt: 0` explicitly
means no expiration. A rename-only call sends only its two fields, with no read
or merge. The [attributed source review of 11.138.0.19](https://github.com/hostingpal/whm-api-client/pull/72#pullrequestreview-5722427592)
reports omitted ACL/IP/expiry are preserved, correcting the earlier PR57 assumption
based on pinned reset prose. Older-version preservation is unestablished.

`revokeToken()` also accepts a nonempty sequential string list, always using
`token_name-1`/`token_name-2`, including a singleton list. It sends one request;
no atomicity or per-token success guarantee is added. All seven collection inputs
preserve exact string bytes, order, duplicates and empty string items. Empty,
associative, sparse or non-string-item lists fail locally after metadata reset.
No client format validation or privilege enum is inferred.

The zero-based update ACL choice and underscore IP spelling follow the attributed
source review: update ACL prose starts at one but its example starts at zero;
later IP examples mix `whitelist-ip`, which the inspected handler does not accept.
These calls remain form POST. This implementation and its offline fixtures establish
no live list transport, persistence or successful credential lifecycle. Separate
live acceptance remains open under `8x0.2.44`.

Raw token lookup uses sensitive form POST despite the pinned GET declaration;
this is client policy, and POST compatibility remains live-unverified. Token,
security-token and private prompt-token inputs stay sensitive. Returned tokens,
sessions and login URLs are private payloads. If token creation raises
`PayloadMismatchException` after an accepted API outcome, the credential may
already exist. Its unvalidated `$payload` may contain the only returned secret;
handle it securely if present and revoke the created credential by name through
an authorized call. Do not log that payload or retry creation. The client neither
follows URLs nor stores, refreshes, retries or revokes credentials automatically.

Token inventories preserve dynamic token names and strict ACL flags. Pinned
recognized token details have `data`; the unrecognized response omits it and
returns null. Existing explicit-null compatibility remains. A present empty
object is unobserved and is not proof of recognition; no new recognition signal
is inferred. Malformed present payloads still fail validation.

Legacy API statistics cover cPanel API 1 only. `getApiPages()` retains optional
pinned `count`, `entry` and `timestamp` fields alongside the independently
source-backed optional `result` list of records with those same fields. Its
result is not unwrapped, so neither layout is discarded. Only the date of a page
timestamp is meaningful. AppConfig WHM URLs accept strings or lists of strings;
the reviewer observed the array container, while the element type follows the
pinned string URL family. cPanel/Webmail URL fields keep their existing contracts.
The separately mentioned `cpanel[].demo` field remains untyped pending evidence
of its type and meaning. Application URLs remain payload data and are never fetched.

All twelve methods have independent offline contracts. The fixed five-GET
capture covers version plus four of the five typed reads: available functions,
token metadata and legacy call/page usage. AppConfig is a separate reviewer read.
Dynamic names and ACL keys are masked in structural evidence. No raw-token lookup,
private login-link read, token/session change or URL fetch ran in this capture.

The immutable API-tools capture at `465be19` on `11.138.0.19` records five
HTTP/API/payload/probe passes. Its strict assessment remains failed and unwaived
for a masked empty-list unknown-field observation at `$.*` in `get_api_pages`;
pinned flat fields were absent. This establishes neither key identity nor
underlying field count. The independently named `result` observation and cited
upstream source support the current correction without changing the artifact.
Token/function inventories were populated; legacy call rows were empty. All
seven POST methods remain live-unobserved. Follow-up `8x0.2.44` retains these
limits and the historical finding; no completed capture is repeated to clear it.
Artifact SHA-256:
`eef3b63351acad16364534ceec676a6ab454f72fb901ded0b72be99e9f3088bc`.
