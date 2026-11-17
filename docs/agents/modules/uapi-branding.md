# UAPI Branding

> Agent contract notes for `uapi()->branding()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## Branding application inventory and include content

`uapi()->branding()` exposes all five pinned Branding operations through the
selected account, on direct UAPI and the WHM bridge. Each invocation makes one
request with ordinary UAPI status handling; the cached accessor performs no I/O.

| Method | Effective request | Available from |
| --- | --- | --- |
| `getApplicationInformation(string $appKey): ?array` | GET | cPanel 11.42 |
| `getApplications(string\|array\|null $appKeys = null): array` | GET | cPanel 11.52 |
| `getAvailableApplications(string\|array\|null $nvarglist = null): array` | GET | cPanel 54 |
| `getInformationForApplications(string $docroot, string\|array\|null $appKeys = null): array` | Form POST | cPanel 11.52 |
| `includeFile(string $file, ?string $data = null, ?bool $raw = null, ?bool $skipDefault = null): ?string` | Form POST | cPanel 11.42 |

The source specifies GET for every method, including `include` with
`x-readonly=true`. The two effective POSTs follow client privacy policy for the
private server theme root and arbitrary template variables. Their live POST
acceptance remains unverified. `docroot`, `file` and `data` carry
`SensitiveParameter`; application/group selectors remain noncredential inputs,
with independent collection trace protection.

```php
$branding = $client->uapi('example')->branding();
$applications = $branding->getApplications(appKeys: 'email_accounts,boxtrapper');
$groups = $branding->getAvailableApplications(nvarglist: 'mail|files|domains');
// Synthetic server-side theme path; the client does not access this path locally.
$sitemap = $branding->getInformationForApplications(docroot: '/example/theme');
// This explicit call asks the server to render a template; it returns inert text.
$content = $branding->includeFile(file: 'header.tt', data: '{"showBanner":1}');
```

`appKey` identifies a feature in `dynamicui.conf`. Optional `appKeys` accepts a raw
comma-separated selector or a nonempty sequential string list joined with comma; omission requests all source applications. `nvarglist`
accepts a raw pipe-separated group ordering selector or a nonempty sequential
string list joined with |; omission requests no sorting.
The source documents `arglist` as its alias; no extra public alias is added.
`docroot` is an absolute server theme root containing `sitemap.json` and is
forwarded without normalization, local access or path validation. Null optional
arguments omit, while empty strings, percent/plus/Unicode and exact byte strings
remain supplied and encoded once. No new enum/range/format validation is added.

Three inventory roots are required nonnullable objects/maps with optional
fields; `getApplicationInformation()` returns null for an unknown key. `getApplications()` preserves a dynamic application map, including the
six example-only optional string fields `acontent`, `base64_png_image`, `key`,
`onclick`, `plainitemdesc` and `target`. Its `height`, `itemorder` and `width`
accept canonical in-range integer strings, because all three keyed source
examples quote them; outputs remain integers. Fractional, exponent, whitespace,
plus, leading-zero and overflow strings fail. Review 5725970479 on 11.138.0.19
found the same digit strings in the single-application result, which reads the
same rows, and mixed digit strings and native integers in available-application
items. Both now apply the same rule and return integers, and the single result
keeps the same eight extra row fields. An unknown or empty key returns null data
under status 1, so null means no such application.

`getAvailableApplications()` preserves integer-map `default_group_order`,
ordered string-list `grouporder`, group/item lists, string-map `implements`
and integer-list-map `index`. Index prose describes zero-based group and item
positions, but its list schema does not impose a two-element tuple. The nested
item example supplies optional string `key` and `plainitemdesc` missing from
formal properties; both are retained without additional inferred semantics.
The sitemap map retains optional condition/icon records with strict integer
sizes/order and optional `terms` as an ordered string list, supported by formal
string items and its array example. Omission stays distinct from explicit null.
Dynamic keys, list order and empty containers survive, including inherited
empty-array object/map compatibility and PHP numeric object-key conversion.
Unknown fields follow the existing validator policy. Missing/null inventory
roots and wrong known-field types fail.

`includeFile()` forwards theme-relative `file`, then raw string `data`, then
`raw` and `skip_default`. `data` applies only to `.tt` templates, and `raw` has no
effect for `.tt`. The client forwards malformed/scalar/list/duplicate-key
JSON-like text without parsing, reserializing, mode selection, conflicts or
filename-based omission. False sends 0, true sends 1 and null omits.
`skipDefault: true` prevents default fallback except accounts without a branding
package always use theme fallback. The server renders; the client returns exact
string content, including empty, HTML, Unicode and zero-like strings, or explicit
null. Missing data remains a mismatch. No HTML encoding/decoding or sanitization
is performed by the client.

Returned HTML, JavaScript, expressions, URLs, paths and image strings are inert
payload. The client never renders, executes, fetches, decodes or locally reads
them. Private input and response/error arguments are concealed in library-owned
trace frames; third-party transports control their own traces. Caller-accessible
`PayloadMismatchException::$payload`, results and `responseMeta()` can expose
private content. API and outer-WHM failures precede payload validation; metadata
clears before I/O and captures warnings/raw metadata before API or payload failure.
Native pre-entry argument errors retain the previous snapshot. No implicit
read/merge/sort, retry, rollback or remote-data cache is added.

Generated coverage at the Branding delivery was 1,212 canonical operations (624 UAPI, 588 WHM),
109 groups and 221 outputs (220 PHP plus coverage). Delivery
`whm-api-client-8x0.4.33` establishes synthetic offline coverage only. Live follow-up
`whm-api-client-8x0.2.75` remains OPEN; no live theme visibility, rendering,
route compatibility or server persistence is established.
