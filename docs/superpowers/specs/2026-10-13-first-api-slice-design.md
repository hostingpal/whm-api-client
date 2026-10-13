# First read-only API slice

Agreed on 2026-10-13; implementation tracked in `whm-api-client-8x0.1.4`.
This extends the [client interface](2026-10-13-php-client-design.md).

`$client->whm()->server()->version(): string` executes WHM `version`.
`$client->uapi('npr')->domains()->list(?bool $hideTemporaryDomains = null)`
executes UAPI `DomainInfo/list_domains` and returns immutable `AccountDomains`.
The same domain group uses direct cPanel or WHM routing according to credentials.
Selectors and groups perform no I/O; terminal methods execute immediately.

The optional filter preserves omission (`null`), explicit false (`0`), and true
(`1`). The DTO preserves the optional fields `main_domain`, `addon_domains`,
`parked_domains`, `sub_domains`, and `is_temporary`; `toArray()` retains field
presence, nullable main domain, empty lists, and integer temporary flags.
Convenience properties use null for omitted fields; `toArray()` distinguishes
an omitted main domain from an explicitly null one. Unknown payload extensions
are outside this reviewed DTO contract. Missing version text cannot satisfy a
string return and raises an invalid-response exception.

Execution uses the existing PSR transport, verified HTTPS supplied by the
transport implementation, documented authentication, and RFC 3986 query encoding.
Each selected client retains independent metadata. Every attempt clears old
metadata; usable WHM/UAPI metadata is recorded before payload hydration or an
exception. Both envelope layers survive bridged responses. API outcome semantics
apply specifically to these operations; no universal UAPI status policy is claimed.

Direct UAPI supports both the pinned `result` wrapper and the bare result object
observed on the test server. A present `result` field takes precedence even when
malformed; only its absence permits a top-level `status` field to identify a bare
envelope. Both forms retain the same outcome and payload validation. Bridged UAPI
continues to read `data.uapi`; its two envelope layers are unchanged.

Typed exceptions distinguish transport, HTTP, malformed-response and API-outcome
failures. Safe messages exclude credentials, raw request/response bodies and
transport exception chains. Metadata remains explicitly available on exceptions.
There are no implicit retries. Injected PSR clients must verify TLS and must not
follow redirects for authenticated requests; the API layer rejects 3xx responses.

Offline tests cover the three documented routes, request encoding, precise payload
validation, HTTP 200 API errors, outer and inner bridge failure, malformed bodies,
transport failure, consecutive attempts, retained snapshots and account isolation.
Coverage declarations map only the implemented endpoint contracts; the internal
bridge is not advertised as complete generic `uapi_cpanel` coverage.

Authorized live verification is limited to WHM version and DomainInfo/list_domains
for `npr` on `test.oblak.host`. WHM requests use the root token; direct cPanel
requests use a separate account token. Credentials are neither saved nor printed.
On 2026-10-13 the direct request authenticated and returned HTTP 200 with integer
UAPI status `1`, exposing the bare-envelope difference. The library recheck with
the updated parser returned the expected main domain, HTTP 200/UAPI status `1`
metadata, null WHM metadata, and `isBridged() === false`. This verifies direct
execution for this operation, account, server, and date. Routine checks stay
offline. Broader execution features, mutations and endpoint batches remain
separate work.

Pinned evidence: `docs/api/sources/2026-10-13/whm.openapi.json.gz` paths `/version`
and `/uapi_cpanel`, and `cpanel.openapi.json.gz` path `/DomainInfo/list_domains`.
Both leaf operations declare `x-readonly: true`; the generic bridge does not.
The domain documentation warns that userdata permission errors can return blank
values without an API error, so successful status is not a data-completeness claim.
