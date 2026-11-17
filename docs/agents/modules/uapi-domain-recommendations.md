# UAPI DomainRecommendations

> Agent contract notes for `uapi()->domainRecommendations()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI DomainRecommendations (2026-11-01)

`uapi()->domainRecommendations()` covers all six pinned functions for the selected
account: `domainAvailability()`, `domainSuggestions()`, `getStoreConfig()`,
`isEnabled()`, `purchaseDomain()` and `supportedTlds()`. All require the
`domain_recommendation` feature. Availability, suggestions, store configuration and
enablement require cPanel 134; checkout and supported TLDs require cPanel 138.
Five methods use GET. `purchaseDomain(string $domain, int $period)` uses form POST
because its selected formal GET is marked `x-readonly=false`, `x-rollback=none`.
A server accepting only the documented GET can fail until route-specific evidence
resolves transport. Offline validation does not establish live POST acceptance.

Availability and checkout expect an FQDN including its TLD. Suggestions accept
free text, normally a base name without a TLD. Reviewed 11.138.0.19 source also
adds an available full-domain search on the first page. Optional `page` is one-based;
omitted `page` and `pageSize` use store defaults. Strings retain their bytes and
are encoded once; no local IDN, domain, TLD, range or enum normalization occurs.
Optional null inputs omit; false becomes 0, true becomes 1, and zero and empty
strings stay supplied. The server caches availability per domain, suggestions by
search/page/page-size/requested-TLD combination and supported TLDs for up to two
minutes. Omitted `useCache` retains server default 1; explicit false bypasses its
cache and may contact the configured store. The pinned suggestion parameters and
examples contain no TLD argument despite the cache-key prose. Reviewed handler
source reads `get_multiple('tld')` and its POD shows `tld-0=com&tld-1=net`.
That source-only finding adds no typed argument or hidden parameter.
The client adds no cache, fallback, pagination, store lookup, merge or retry.

Availability preserves explicit null without inferring a reason; missing data is
a mismatch. Its availability, premium and TLD flags are native booleans, while
suggestion `is_premium` additionally accepts native false/true and normalizes to
integer 0/1. Enablement `is_enabled` remains a strict integer 0|1 flag. Optional
`is_searched_term` is a strict native integer 0|1: 1 identifies an injected
own-search row; ordinary rows may omit it. No string, float or null flag
conversion is added. Availability flags remain strict native booleans.
Payload `is_enabled=0` can succeed under envelope status 1; account feature denial
still raises an API error. Inspect response metadata and warnings separately.
`getStoreConfig()` exposes only allowlisted `store_type`; credentials, URLs and
future fields are filtered. An empty object can mean an unconfigured store.
`WHMCS` is a string value, without a closed store-type enum.

Prices remain exact strings, including decimal precision, formatting, whitespace,
prefixes and suffixes. Null, empty price objects and omitted fields stay distinct;
no currency parsing, conversion or rounding occurs. Availability's premium object
is opaque. Suggestions preserve returned dynamic domain and registration-term
keys and empty maps; metadata permits unknown fields. Every suggestion row
requires `pricing`, integer `is_premium` and nullable `premium_cost_pricing`, even
though source prose calls premium details optional. Omission remains a visible
mismatch until evidence resolves that discrepancy; no flag-dependent fields are
inferred. Availability and price at actual checkout are not guaranteed.

Reviewed 11.138.0.19 source refuses an unavailable domain, creates a real upstream
cart, adds a domain item and requests checkout. It emits backend
`Domain-Purchase-Initiated` analytics with the domain by default, before the cart
call; analytics may occur despite later validation or checkout failure. Do not
assume atomicity or retry automatically. No `skip_backend_track` input is added.
These are source-only facts, not configured-store or transaction observations.

`purchaseDomain()` returns an optional private checkout URL for caller-controlled
use. The method does not fetch it, navigate, register a domain, make payment or
establish ownership. There is no implicit retry or rollback. Treat the returned
URL and response snapshots as private payload. Implementation and offline tests
make no commercial transaction or server-store setting change. Live/store
configuration/purchase behavior and source gaps remain open under
`whm-api-client-8x0.2.68`; delivery is `whm-api-client-8x0.4.26`.

Current combined generated coverage is 1,220 operations (624 UAPI, 596 WHM),
108 groups and 219 outputs (218 PHP files plus coverage), adding these six
operations to approved master `922a095b1a852b3537107945c2ecd8a75c96eefb`.

The source review and serializer experiment support only the bounded response
compatibility above; actual configured-store success remains unobserved. The
reviewer never called `purchaseDomain()`: its permission check refused the call,
and no alternate route or retry was attempted. POST/store/cart/analytics runtime
behavior remains open under `8x0.2.68`.
