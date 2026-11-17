# UAPI account cache management

> Agent contract notes for `cacheBuster()`, `nginxCaching()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI account cache management (2026-10-31)

CacheBuster (2) and NginxCaching (4) cover their complete pinned modules through
`uapi()->cacheBuster()` and `uapi()->nginxCaching()`. All methods are parameterless.
CacheBuster is available from cPanel 11.46; read uses GET and update uses form
POST. Both return optional strict integer `cache_id` fields in an array shape.
The source describes eight-digit random IDs and read declares 10000000–99999999;
existing validation adds no range enforcement or integer-string normalization.
Preserve omission, not a required field or unwrapped integer. Cache versions have
no inferred credential sensitivity, uniqueness, monotonicity or security guarantee.
Applications explicitly call update when changing a resource and append the
returned ID to its query string; the client fetches no resource or modifies URLs.

NginxCaching requires cPanel 100 and the installed ea-nginx package. All four
methods use form POST: clearCache deletes cache contents, disableCache and
enableCache change caching, resetCacheConfig resets configuration with no pinned
resulting defaults. They target the selected account without invented user or
all-user arguments. Do not reuse WHM NginxManager inputs, outputs, defaults or
historical observations. Browser cache versioning and NGINX cache state are
separate mechanisms, with no promised cross-invalidation.

The exact NGINX data schemas contain only properties:{} and no type. Preserve
existing mixed lowering: every present JSON value, including null/scalars/lists
and recursively converted opaque objects, is accepted. Missing data remains a
PayloadMismatchException under normal Flag; do not narrow to void, null-only or
opaque-object-only output or invent observed fields. Callers inspect values.
All five mutations declare no rollback; no implicit read, restore, retry,
rollback or package installation is added. No live call, server mutation, package
installation or resource fetch ran; installed-package behavior, cache effects,
browser behavior, returned fields and reset defaults remain open under
`whm-api-client-8x0.2.62`. Delivery is `whm-api-client-8x0.4.20`.

Coverage adds exactly six canonical UAPI operations and two groups over `259c8d6`:
1,128 operations (540 UAPI, 588 WHM), 95 groups and 193 generated outputs (192 PHP
files plus coverage). Existing runtime/generator/source/evidence contracts and
historical totals remain unchanged. Required PHP 8.3 CI and inherited acceptance
work remain external-owned and unwaived.


Ordered PR95 integration with approved master `0526623` yields current totals of
1,165 canonical operations (569 UAPI, 596 WHM), 103 groups and 209 generated
outputs (208 PHP files plus coverage). Original slice totals above are historical.
PR95 review 5724999867 concerns original `31ed8cf` only: CacheBuster native integer
results were observed through both routes on cPanel 11.138.0.19; all four NGINX
calls failed with ea-nginx absent. Source-predicted null is not observed NGINX
success and does not narrow pinned mixed output. Closed delivery `8x0.4.20` is
distinct from open installed-plugin/runtime follow-up `8x0.2.62`. This integration
is offline; combined reviews and PHP 8.3 CI remain separate.
