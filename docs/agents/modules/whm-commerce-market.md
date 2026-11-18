# WHM Commerce and Market

> Agent contract notes for `whm()->commerce()`, `whm()->market()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM Commerce and Market

`whm()->commerce()` exposes three nondeprecated Commerce Integration functions;
`whm()->market()` covers all nine cPanel Market functions. Three reads use GET:
Commerce configuration, commission configuration and provider inventory. Nine
operations use form POST, including the product reads whose source declares
`x-readonly=false`. The two deprecated ecommerce functions remain excluded.
Source version floors are Commerce 11.122, token validation 62 and Market 56.

Commerce configuration uses `Sitejet/get_commerce` and `Sitejet/set_commerce`,
as shown in pinned CLI and URL examples. Canonical map identities, formal paths
and documentation links stay unchanged. The no-argument getter uses GET by client
policy based on its read purpose and the reviewer's successful GET; the source
has no `x-readonly` marker. The setter remains POST and source-backed only.
`validate_login_token` stays bare: its path and both examples agree despite its
`Market-` operation ID. No token call has established live compatibility.

[PR64 review 5721097744](https://github.com/hostingpal/whm-api-client/pull/64#pullrequestreview-5721097744)
separately reports bare `get_commerce` failing and the Sitejet GET succeeding on
`11.138.0.19`, with string `is_enabled` and null `storeurl`. It also reports null
local/remote commission IDs. These reviewer observations are separate from the
immutable runner capture. The getter preserves original integer 0|1 flags and
exact strings, including empty strings; URLs and commission IDs remain optional
nullable strings. No blanket coercion or boolean widening is added.

Both product reads preserve known strict fields and additional product values.
The unadjusted read retains `base_name` and `license_term` as additional data;
its category accepts null by alignment with the adjusted contract. The actual
pinned twelve-row example has integer values for all three `x_price_per_domain`
fields and four null identity-verification lists. These are source/offline
regressions; prices preserve integer/string distinctions. Deep option strings,
other price fields, names and flags retain their existing contracts.
`setProviderCommissionId(provider, commissionId)` and
`setProductAttribute(provider, productId, attribute, value)` put target identifiers
first; prefer named arguments. Sensitive input annotations remain in place.

Tokens, redirect/storefront URLs and product-attribute values are treated as
sensitive by client policy. Returned URLs, icons, schemas and product content
are payload data. The client sends one request, never fetches returned URLs and
adds no retry or rollback. All twelve methods have offline contracts. The
completed two-GET runner catalog covers version and provider inventory only;
remaining live variants and all POST behavior stay under `8x0.2.52`.

The immutable Market provider capture at `38edb29` on `11.138.0.19` passes both
GET checks and strict assessment with zero discrepancies. Provider inventory
plus version is observed; this capture contains no commerce or commission read.
