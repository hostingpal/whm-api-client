# UAPI Market

> Agent contract notes for `uapi()->market()` reads and actions. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI Market reads (2026-10-31)

`uapi()->market()` exposes nine of the nineteen pinned Market functions, all GET:
`getAllProducts()`, `getCertificateStatusDetails(orderItemId, provider)`,
`getCompletionUrl(productName)`, `getLoginUrl(provider, urlAfterLogin)`,
`getPendingSslCertificates(provider)`, `getProductInfo(productName)`,
`getProviderSpecificDcvConstraints(provider)`, `getProvidersList()` and
`getSslCertificateIfAvailable(orderItemId, provider)`. The ten checkout, order,
queue and token mutations remain separate. These UAPI contracts are independently
sourced; WHM Market types and observations do not establish their behavior.

Catalog, login URL, pending certificates, providers and certificate availability
are documented from cPanel 56; completion URL, product information and DCV
constraints from cPanel 62. Status-details literally specifies **cPStore 76**,
which establishes no universal cPanel server version floor. Provider enablement,
network availability and order state can affect reads. Status-details takes an
integer orderItemId while certificate availability takes a string; queue,
certificate and product-info IDs remain strings, and catalog product_id remains
an integer. Inputs retain exact bytes and are encoded once without new URL
validation, normalization or sensitivity policy.

All known object fields remain optional. Catalog rows retain the formal merged
base/SSL fields, strict integer 0/1 flags, integer counts/durations, nullable
base_name/max_users/server prices/price and distinct units. Identity verification
preserves the formal object plus same-operation documented list records and null;
both record branches keep optional string label/name and opaque additional values.
Only x_price_per_wildcard_domain and its maximum/minimum fields additionally
accept and preserve documented numeric strings, alongside native integers/floats;
no rounding, float conversion or widening of other prices/IDs is added.

getLoginUrl returns a required string. Provider/pending lists may be empty;
pending identity_verification remains an opaque map, domains/vhost_names string
lists and timestamps integers. Status labels remain strings without a closed
enum. Certificate availability preserves null certificate_pem versus omission;
encrypted_action_urls retains optional nullable string evClickThroughStatus and
ovCallbackStatus alongside documented opaque extra entries. Known fields remain
strict. Missing/null/wrong roots mismatch unless explicitly nullable; existing
legacy empty-array object/map compatibility remains.

Returned completion/login/redirect/action URLs, security tokens, certificates,
CSRs and provider identity data are sensitive payloads; do not log them. They are
returned as data without secondary requests, provider login, redirects, checkout,
order creation/cancellation, certificate requests/installation or queue processing.
A successful read does not establish purchase, payment, issuance, installation,
trust or served TLS. No live call or returned-URL fetch ran. Provider/catalog/price,
response and actual certificate availability behavior remain open under
`whm-api-client-8x0.2.63`; delivery is `whm-api-client-8x0.4.21`.

This adds exactly nine canonical UAPI operations and one group over `31ed8cf`:
1,137 operations (549 UAPI, 588 WHM), 96 groups and 195 generated outputs (194 PHP
files plus coverage). Existing runtime/generator, earlier overlays/generated
contracts, pinned sources, map checksum, historical evidence and audit prefix are
unchanged. Required PHP 8.3 CI and inherited acceptance work remain external-owned
and unwaived.


### UAPI Market read compatibility and ordered integration (2026-11-01)

The preceding 2026-10-31 Market section records the original slice over `31ed8cf`.
The combined candidate integrates approved master `14d9f9f09d36bbe887468863efb19a1bb80e54e6`
with original Market head `9a3212839ebd07f1caad02f99e674a91f877b98b`:
1,174 canonical operations (578 UAPI, 596 WHM), 104 groups and 211 generated
outputs (210 PHP files plus coverage). The nine read signatures and request
bodies remain unchanged; the ten Market mutations remain separate work.

[Review 5725042192](https://github.com/hostingpal/whm-api-client/pull/96#pullrequestreview-5725042192)
by seebeen applies to original `31ed8cf..9a32128`, dated 2026-10-31, on cPanel
`11.138.0.19`, GET as `npr`, with identical direct UAPI and WHM bridge results.
It identified three response families needing six narrow leaf corrections:
catalog `license_term` and `product_id` canonical bounded digit strings normalize
losslessly to integers; `requires_ip` accepts only exact quoted `0`/`1` in
addition to native integer flags. No new minimum constraint is imposed. Other
flags, prices and IDs remain strict. The three wildcard-price fields still
preserve exact numeric strings without rounding or conversion, and identity
verification object/list/null behavior and opaque extras are unchanged.

DCV `dcv_file_allowed_characters` is an optional nonnullable list of `string|int`
items, preserving native JSON types, order and duplicates without a closed
character enum. Certificate availability now permits an outer null
`encrypted_action_urls` and a null `status_message`, preserving omission and
empty-string distinctions. The encrypted object still validates optional nullable
string `evClickThroughStatus` and `ovCallbackStatus` and preserves opaque extras.
The pinned availability schema declares only `certificate_pem`,
`encrypted_action_urls`, `status_code` and `status_message`. `certificate_pem`
remains nullable and `status_code` a strict nonnullable string. Reviewer-reported
null `certificate_id` and `parent_cert_expiry` are unknown fields here; they remain
filtered because null-only observations establish no additional field contract.

The same review reports token-authenticated `getCompletionUrl()` failing through
both routes because server information was unavailable from the environment.
Reviewer inspection of `_build_url` supports an environment dependency, not a
universal unsupported-token or unsupported-route claim. GET/signature/dispatch
remain unchanged, with no precheck, fallback or environment probing.

The review confirms provider rows, string login URL and empty pending list;
product-info success, status-details success and populated pending rows remain
unobserved. This integration is offline and adds independent literal regression
fixtures with explicitly synthetic representative values, not live captures.
Returned URLs, PEM/CSR, provider and order data remain inert sensitive payloads;
no URL was followed and no provider, checkout, certificate or queue workflow ran.
Runtime follow-up `whm-api-client-8x0.2.63`, the 55 inherited source diagnostics,
PHP 8.3 CI, current-head review and all earlier acceptance work remain unwaived.
Historical PR95 provenance finding I1 / `whm-api-client-qrk` remains open; the
prospective PR96 recorder does not repair that missing history.


### UAPI Market actions (2026-10-31)

`uapi()->market()` now exposes seventeen of the nineteen pinned functions: the
nine published GETs plus eight form POST actions, `cancelPendingSslCertificate()`,
`createShoppingCartNonSsl()`, `getBuildCartUrl()`, `getLicenseInfo()`,
`processSslPendingQueue()`, `setStatusOfPendingQueueItems()`,
`setUrlAfterCheckout()` and `validateLoginToken()`. Non-SSL cart creation,
build-cart URL and license information require cPanel 62; the other five require
cPanel 56. All eight are documented mutations with no rollback, including the
get-named methods that save session data. Queue processing acts on the account
pending queue and can install certificates. Each call makes one form POST on
direct UAPI or the WHM bridge, without an implicit read, merge, retry, session
cache, restore or rollback.

`setStatusOfPendingQueueItems(orderItemId, provider, status)` accepts a string or
nonempty sequential string list. A scalar uses one literal `order_item_id`;
every list, including a singleton, repeats that literal key in caller order,
preserving duplicates, empty strings and exact bytes. Provider and status remain
single fields. Empty, sparse, keyed, nested and non-string lists fail before HTTP
after metadata reset; native signature errors precede reset. The pinned prose
and examples expressly permit repeated keys as an alternative to numbered names.
No caller numbering, bracket keys, sorting, deduplication or numeric conversion
is introduced. The documented confirmed status denotes provider-confirmed payment;
the client does not verify payment. Inspect payload `error_type` and missing
integer `order_item_ids` separately from `responseMeta()` API status.

Known response fields remain optional. Order, product and certificate IDs remain
strings; input `setUrlAfterCheckout` orderId and returned missing order_item_ids
remain integers. Flags stay native integer 0|1 and timestamps stay integers.
Nullable certificate_pem and error_type preserve null versus omission; an empty
last_status_message stays empty. Provider-specific order_items_ref objects and
license objects preserve opaque fields. A validateLoginToken object without
access_token is valid, while an absent root data member is a mismatch.

Three source contradictions remain explicit. Cancellation retains the formal
list of order records despite object prose; an actual object will fail strict
validation. License information retains its formal nullable opaque object despite
no-data prose and a null example; an omitted root data member will fail validation.
Queue `deleted` prose says 0=deleted and 1=not deleted, opposite `expired`; both
remain strict integer flags and their semantics need live/source confirmation.
No union, void weakening or semantic correction is inferred.

Access/login tokens remain sensitive parameters through generated and runner
frames. Returned access tokens, checkout/session URLs, PEM/CSR, provider messages
and identity data are sensitive payloads; do not log them or fetch returned URLs.
Inputs retain exact bytes, optional null omits domain and an explicit empty domain
remains supplied. Supply validateLoginToken with the caller's urlAfterLogin used
for getLoginUrl; the client stores or verifies no session linkage. Cancellation
removes the order/polling per source without a refund or reversibility promise.
No live API/provider/login/cart/order/payment/queue/certificate call or URL fetch
ran. Actual session, checkout, payment, installation and queue compatibility remain
under OPEN `whm-api-client-8x0.2.64`; delivery is `whm-api-client-8x0.4.22`.

Exactly eight canonical UAPI operations and zero groups are added over
`9a3212839ebd07f1caad02f99e674a91f877b98b`: 1,145 operations (557 UAPI, 588 WHM),
96 groups and 195 generated outputs (194 PHP files plus coverage). The published
nine read contracts remain unchanged. `create_shopping_cart` and
`request_ssl_certificates` remain deferred under `8x0.1.22` and `8x0.29.6`; their
composed/content JSON values are form/query parameters, not JSON request bodies.
This slice adds no runtime/generator or generic JSON-body capability. Required
PHP 8.3 CI and the 55 inherited diagnostics remain visible and unwaived.


### UAPI Market actions ordered integration (2026-11-01)

The preceding dated Market sections describe historical deliveries and their
validation. This candidate integrates approved master
`e6611d6b30eda72d6e1fd746b290bf847fd8b664` with original action head
`3872d384a2e4f83a348758085808452230459d8c`: 1,182 canonical operations
(586 UAPI, 596 WHM), 104 groups and 211 generated outputs (210 PHP files plus
coverage). It preserves the nine corrected master reads and adds exactly the
original eight form POST actions. Their strict response contracts, signatures,
parameter order, sensitivity and repeated-string encoding remain unchanged.

[Review 5725131701](https://github.com/hostingpal/whm-api-client/pull/97#pullrequestreview-5725131701)
by seebeen covers only original `9a32128..3872d38`, with limited results on
cPanel `11.138.0.19` through direct UAPI and the WHM bridge. Its three bounded
caller caveats are now included in generated method documentation:

- `cancelPendingSslCertificate('999', 'cPStore')` returned `[]` with status 1
  through both routes. In that observed case no matching item was removed.
  Confirm returned matching rows; envelope success alone does not prove
  cancellation. The formal list shape and no-refund/reversibility limits remain.
- Token-authenticated `getBuildCartUrl()` failed through both routes because
  server information was unavailable from the request environment. This is a
  build, route and environment scoped observation, not universal token or route
  incompatibility. Session writes and optional domain omission/empty bytes remain.
- Reviewer source inspection reports matches updated in memory before leftover
  IDs reject before `finish()`. Partial-match persistence was not observed.
  An error proves neither rollback nor atomicity; no persistence, no-change,
  transaction or retry guarantee follows. Payload errors and API outcome handling
  retain their existing boundaries.

The reviewer deliberately did not run `validateLoginToken()`,
`createShoppingCartNonSsl()`, `setUrlAfterCheckout()` or `getLicenseInfo()` because
they contact the Store or need a Store session. Populated cancellation and queue
rows and license success remain unobserved. The reviewer's disposable-account
cleanup and temporary-token revocation are attributed reports, not checks by this
integration. This integration made no live, provider, session, cart, order,
payment, queue or certificate request and followed no URL or source refresh.

Combined validation is offline: strict Composer validation, full PHP quality
gates, exception-argument-enabled Market/read-compatibility/sensitive-trace focus,
fresh Go tests, vet, schema/source/map checks, generation freshness, offline
toolchain checks and staged/working whitespace checks. Exact execution results
belong to the integration handoff; historical counts above are not rerun claims.
The three original source contradictions and `whm-api-client-8x0.2.64` remain
open; the 55 inherited diagnostics, PHP 8.3 CI, source/runtime/stress/platform/
metadata/release gaps and deferred consumer integration remain unwaived.
Historical PR95 provenance finding I1 / `whm-api-client-qrk` remains open.
