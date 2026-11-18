# UAPI DCV checks

> Agent contract notes for `uapi()->dcv()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## DCV checks (2026-11-01)

The complete nondeprecated `uapi()->dcv()` group exposes two operations:
`checkDomainsViaDns(domain)` (cPanel 74, source POST) and
`checkDomainsViaHttp(domain, dcvFileExtension, dcvFileRandomCharacterCount,
dcvFileRelativePath, dcvMaxRedirects, dcvUserAgentString)` (cPanel 60, source GET). Both have `x-readonly:false` and
`x-rollback:none`; the existing mutation policy sends one immediate form POST on
direct and bridged routes, including HTTP's deliberate source-GET/effective-POST
difference. Successful live POST acceptance remains unestablished. Only standard
routing stays in the URL. Do not describe DNS checking as passive inventory: the
zone is altered and queried, and failure_reason can report a zone update failure.

Domain inputs are strings or nonempty sequential string lists. Scalar uses
`domain`; every DNS list uses `domain-0/domain-1/...`, every HTTP list uses
`domain-1/domain-2/...`, including singleton lists. Ordinary PHP arrays hide the
distinct source numbering. Preserve order, duplicates, empty strings and exact
bytes without local syntax checks, lowercasing, sorting or deduplication.
`checkDomainsViaHttp()` omits `dcv_file_allowed_characters`: review 5726048230
found that the 11.138.0.19 handler JSON-decodes one field and the pinned
repeated query form fails on both routes. Use generic `call()` with
caller-serialized JSON array text until a typed JSON-list encoding exists.
Malformed collections reject after metadata reset before I/O; native scalar
rejection occurs before entry/reset.

Every optional null omits. Empty strings and integer zero are supplied. The server
owns defaults: alphabet A-Z/0-9, empty extension, random count 100 (minimum 1),
relative path .well-known/pki-validation relative to the DOMAIN DOCUMENT ROOT,
max redirects null (minimum 0), and a changeable user-agent default. Zero max
redirects means NO LIMIT ON REDIRECT RETURNS; N yields N+1 redirect records so
excess failures are visible. No client-side defaults or new integer range guards.

Both payloads are required nonnull possibly-empty lists in input order with
optional row fields. DNS retains dcv_string/domain/failure_reason/zone as nonnull
strings, query_results as a possibly-empty string list or null, succeeded as
integer 0|1 (direct UAPI digit strings normalize).
API success can contain succeeded=0. HTTP has failure_reason string|null,
redirects list|null and redirects_count int|null (null on a failed check); no row domain field is pinned, so associate
positionally. Redirect content/protocol/reason/url are strings, status is int and
success is exactly ''|0|1, preserving empty string and rejecting quoted 0/1,
whitespace, arbitrary strings, other ints, floats, booleans and null. This sole
source-bound correction must not widen siblings or borrow unrelated observations.
headers is an opaque object with arbitrary values; nested redirects is
list<opaque object>|null, not a recursive model. Unknown fields survive only in
those opaque nodes; other known objects retain filtering. Omission and null remain
distinct. Missing/null roots fail. Retain the existing individual empty-array
object compatibility under 8x0.2.50 without shared runtime changes.

Use normal Flag outcome: row succeeded=0 or redirect success=0/'' is distinct
from UAPI status 0. Envelope and outer WHM failures still throw. Keep warnings/raw
metadata and mismatch payload access, with no hidden retry after failure. Domain
and alphabet collections inherit SensitiveParameter; extension, relative path and
user-agent text explicitly remain sensitive. Challenge strings, DNS values,
content, headers, URLs, metadata and PayloadMismatchException::$payload can be
private. Response frames retain protection; third-party traces are outside library
guarantees. No implicit read, polling, merge, supplied-value normalization, local
filesystem access, URL fetch, retry, cleanup or rollback is added.

Coverage is 1,214 canonical operations (626 UAPI, 588 WHM), 110 groups and 223
outputs (222 PHP plus coverage). The original deprecated
ensure_domains_can_pass_dcv exclusion remains semantically identical and uncounted.
Delivery 8x0.4.34 covers synthetic offline fixtures only; live follow-up 8x0.2.76
remains OPEN. Do not claim live DCV compatibility, DNS propagation, certificate
issuance or completed external delivery from these tests.
