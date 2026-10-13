# WHM API 1 and cPanel UAPI source audit

Audit date: 2026-10-13. Beads: `whm-api-client-gwi.1`.
Exact inputs and retrieval times: [source manifest](sources/2026-10-13/manifest.json).

## Scope and evidence

Use the official [WHM OpenAPI](https://api.docs.cpanel.net/_spec/specifications/whm.openapi.json)
and [UAPI OpenAPI](https://api.docs.cpanel.net/_spec/specifications/cpanel.openapi.json)
as structured inventories, supplemented by the pinned guides below. Both
specifications declare OpenAPI 3.0.2 and product version 11.138.0.10. This
establishes a documentation baseline, not tested compatibility with every
version or server configuration.

| Family | Paths | HTTP operations | GET | POST |
| --- | ---: | ---: | ---: | ---: |
| WHM API 1 | 630 | 630 | 610 | 20 |
| cPanel UAPI | 693 | 695 | 662 | 33 |

There are 1,323 distinct documented paths and 1,325 HTTP variants across both
families. UAPI's two additional variants are POST forms of `WebApp/redeploy`
and `WebApp/stage`. They must not inflate a function-coverage denominator.
Path counts are not automatically a canonical function count: aliases and
namespace relationships require explicit evidence.

The [structural inventory](source-inventory.json) records detailed measurements,
source hashes, reference diagnostics, extension distributions, and operation
pointers. These measurements inspect the source graph without expanding
references; they are not runtime observations.

The existing PHP `HttpTransport::send` is infrastructure, so implemented API
operation coverage is currently zero. Earlier Beads observations of server
11.138.0.15 and 675 advertised WHM functions are historical runtime evidence;
this audit made no server calls and does not reproduce those counts.

## Authentication and family boundaries

| Route | Token header | Normal HTTPS base |
| --- | --- | --- |
| WHM API 1 | `Authorization: whm username:token` | `https://host:2087/json-api` |
| Direct cPanel UAPI | `Authorization: cpanel username:token` | `https://host:2083/execute` |
| UAPI through WHM | WHM credentials and account context | WHM `uapi_cpanel` operation |

WHM tokens identify root or a reseller; direct UAPI tokens identify a cPanel
account. A successful WHM bridge call does not verify direct cPanel-token
authentication. The WHM token guide also documents HTTPS port 443 through a
service subdomain. Cookie/session authentication uses a session security-token
path; it is not a mandatory component of token-authenticated URLs.
[WHM tokens](https://api.docs.cpanel.net/whm/tokens),
[cPanel tokens](https://api.docs.cpanel.net/cpanel/tokens).

Both OpenAPI documents declare only HTTP `BasicAuth`. Preserve that source
declaration and add separately attributed token-authentication evidence; do not
silently rewrite the upstream document. Token examples disable TLS certificate
checks, and the WHM PHP explanation incorrectly names the header scheme
`token` while the main instruction and code use `whm`. Those examples are not
a secure client configuration. The project retains verified HTTPS and secret
redaction requirements.

WHM API **1** and cPanel API **1** are different families. Legacy cPanel API 1,
cPanel API 2, WHM API 0, UI `.html` routes, and custom third-party UAPI modules
are outside this official WHM API 1/UAPI inventory. Preserve documented
deprecated UAPI operations in the inventory, with explicit exclusion reasons
if a coverage report later excludes them. API 1 replacement guidance and API 2
deprecation must not remove supported WHM API 1 operations.
[API 1 migration](https://api.docs.cpanel.net/guides/guide-to-replacing-cpanel-api-1-functions-with-uapi-equivalents),
[API 2](https://api.docs.cpanel.net/cpanel-api-2).

## Request contracts

Preserve exact function/module case and parameter spelling. Values are
URI-encoded for URL calls. Boolean inputs are documented as `1` and `0`, not
the words `true` and `false`. Literal dots in `api.version`, `cpanel.user`,
`api.filter.a.field`, or `services.email.enabled` are part of parameter names.
Do not normalize these names into nested object syntax.
[WHM introduction](https://api.docs.cpanel.net/whm/introduction),
[UAPI introduction](https://api.docs.cpanel.net/cpanel/introduction),
[WHM testing](https://api.docs.cpanel.net/guides/guide-to-testing-custom-code/guide-to-testing-custom-code-whm-api-calls),
[UAPI testing](https://api.docs.cpanel.net/guides/guide-to-testing-custom-code/guide-to-testing-custom-code-cpanel-api-and-uapi-calls).

Methods, parameter locations, content types, defaults, requiredness, and
nullability belong to each HTTP variant. The specifications include JSON,
form-urlencoded, and multipart bodies, as well as POST operations whose
parameters are declared in the query. GET is not evidence of read-only
behavior: 363 WHM GET operations explicitly have `x-readonly: false`.

WHM generally requires `api.version=1`; the JSON request schemas for
`batch_create_cphulk_records` and `personalization_set` also describe an
`api.version` body property. Preserve this location evidence instead of
inventing a universal query-only rule. `restore_config_from_upload` describes
multipart input; `addzonerecord` describes form input.

Repeated values do not have one global array encoding. Examples include
repeated `zone` or `command`, indexed `acl-0`/`acl-1` or `command-0`/`command-1`,
and numbered `action1`/`match1` fields for UAPI `Email/store_filter`. Preserve
the original schema and explanatory prose; generic bracket-array serialization
would lose these distinctions.

Null, omission, an empty string, and zero remain distinct. A nullable schema
does not specify whether query null should be omitted, encoded as an empty
value, or sent as text. `SiteQuality/get_all_scores` even declares required,
nullable `verbose` with a null default. Such combinations are source facts
with unresolved wire semantics. Empty strings have documented meanings:
WHM `enqueue_deferred_ssl_installations` uses an empty `cab` to request CA
bundle retrieval, and `set_default_dmarc_record` uses an empty `record` to
remove a custom default. Do not discard empty values.

## Response and error contracts

Separate transport failures, HTTP status, and application results. A documented
HTTP 200 schema is not a claim that all possible failures return HTTP 200.
WHM commonly returns `metadata.result` (1/0), `metadata.reason`, command/version
metadata, and operation-specific data. Reason strings may describe success
as well as failure; preserve them without using their wording as a status code.

The pinned UAPI schemas commonly contain `apiversion`, `func`, `module`, and
`result`, whose members include `data`, `status`, `errors`, `messages`,
`warnings`, and `metadata`. Examples from CLI, direct HTTP, and bridges may
show different wrappers. Preserve the actual schema and required fields;
do not make every example field universally mandatory. Null error arrays and
warnings are meaningful data.

`Features/has_feature` is a specific exception to a universal success rule:
its documented `result.status` describes enabled, unavailable to the user, or
not-installed feature states (1, 0, null). A zero may accompany null errors.
The map must retain endpoint semantics rather than turn every non-1 status
into the same failure. Exact evidence is under
`/paths/~1Features~1has_feature/get/responses/200/content/application~1json/schema`.

WHM `uapi_cpanel` uses literal `cpanel.user`, `cpanel.module`, and
`cpanel.function` inputs. Its response has outer WHM `metadata` and inner
`data.uapi`, with its own status/errors/data. Preserve both layers; outer
success does not establish inner success. The legacy `cpanel` bridge defaults
to API 2 unless `cpanel_jsonapi_apiversion=3` is supplied; it must not silently
be treated as UAPI. The bridge guide also contains a `json_api` path spelling
that conflicts with the `json-api` route in the spec and examples.
[Bridge guide](https://api.docs.cpanel.net/whm/use-whm-api-to-call-cpanel-api-and-uapi).

## Output transformations

| Family | Filtering | Sorting | Pagination |
| --- | --- | --- | --- |
| WHM | `api.filter.enable`, lettered `.field`, `.arg0`, `.type` | `api.sort.enable`, lettered `.field`, `.method`, `.reverse` | `api.chunk.enable`, `.size`, `.start` |
| UAPI | `api.filter`, `api.filter_column`, `api.filter_term`, `api.filter_type` | `api.sort`, `api.sort_column`, `api.sort_method`, `api.sort_reverse` | `api.paginate`, `api.paginate_start`, `api.paginate_size`, `api.paginate_page` |

WHM's dedicated pagination guide defines start as one-based. WHM filtering
distinguishes numeric `==` from string `eq`; lettered sorting controls
priority. Projection uses `api.columns.enable` and `api.columns.a`, `.b`, etc.
The selected sort column must remain in the projected output.
[Filtering](https://api.docs.cpanel.net/whm/filter-output),
[sorting](https://api.docs.cpanel.net/whm/sort-output),
[pagination](https://api.docs.cpanel.net/whm/paginate-output),
[columns](https://api.docs.cpanel.net/whm/output-columns).

UAPI filtering defaults to `contains`; numeric sorting needs a numeric method.
Its pagination examples identify fifth-record start as 5 and second page as 2,
but do not settle zero handling or precedence if both page and start are
provided. Retain that uncertainty. Custom-module guidance associates these
transformations with arrays of objects; do not assume all endpoint return
types support every transform.
[Filters](https://api.docs.cpanel.net/cpanel/filters),
[sorting](https://api.docs.cpanel.net/cpanel/sorting),
[pagination](https://api.docs.cpanel.net/cpanel/paginate),
[custom modules](https://api.docs.cpanel.net/cpanel/custom).

## Structural ingestion requirements

Upstream `operationId` is not a globally unique identifier: 29 values occur
in both API families. Within each source, this snapshot has no missing or
duplicate operation IDs. WHM has ten slash-qualified function paths, including
`360Monitoring/*` and `EcosystemFeatures/*`; the latter's operation IDs omit
the namespace. Use full, case-sensitive paths with a family discriminator,
rather than deriving identity from tags or the final path segment.

All 278 WHM and 81 UAPI `$ref` occurrences resolve locally. UAPI's
`ServiceAccount` schema recursively references itself through
`merge_candidates.items`; eager unlimited expansion cannot terminate. Preserve
the reference graph. Discriminator mappings add 100 WHM and 11 UAPI local
references and must also be checked. Future remote references require explicit
fetch policy rather than implicit network access during generation.

Seven WHM and five UAPI parameters use `content.application/json.schema`
instead of a direct `schema` member. Preserve composition, discriminators,
boolean/object `additionalProperties`, null defaults/enums, and explicit
`nullable: false` as well as true or absence. There are no path-level parameter
overrides in these inputs, but an ingester must handle them when present.

WHM `GET /enqueue_transfer_item` declares a form request body. Seven WHM and
eight UAPI POST operations lack a request-body declaration. These source
shapes require diagnostics; dropping bodies on GET or fabricating bodies for
POST would misrepresent the documentation. Data keys such as `x-frame-options`
and `x-cache` are not vendor extensions merely because they start with `x-`.

## Availability, deprecation, and risk

Keep all vendor extensions and their exact value types, including availability,
read-only, CLI/internal-only, plugin/package, and rollback evidence wherever
present. Also preserve prose: WordPressBackup/WordPressRestore describe plugin
requirements; UAPI `DCV/ensure_domains_can_pass_dcv` and `Themes/get_theme_base`
have `deprecated: true` and prose explaining their replacement or lack of
useful output. Preserve those details alongside the flags. Version strings are
inconsistent in format and must not be coerced into an assumed semantic version.

| Operation metadata | WHM | UAPI |
| --- | ---: | ---: |
| `x-readonly: true` | 236 | 277 |
| `x-readonly: false` | 380 | 337 |
| `x-readonly` absent | 14 | 81 |
| Structured `deprecated: true` | 8 | 5 |
| `x-cpanel-plugin` present | 0 | 34 |

UAPI also has 350 `x-rollback` declarations (`none`, `clean`, `lossy`) and
individual RPM/minimum-compatible-version fields. Availability versions mix
strings and integers. CLI/internal-only metadata can be explicitly false or
absent; absence must not be rewritten as false. Deprecation also appears on
parameters and schemas, not just operations.

Missing `x-readonly` stays unknown. A false value demonstrates that an operation
is not declared read-only, but does not by itself identify whether it is
destructive. Names, GET methods, and success examples cannot establish safety
or retryability. Classification requires source evidence or a reviewed,
separately attributed annotation; nothing in this audit authorizes execution.

WHM `applist` is documented inventory evidence, qualified by version,
permissions, plugins, and enabled server roles. No complete UAPI function
inventory endpoint was located in these sources. `Features/list_features`
lists account features, not callable API operations. Absence from an advertised
list cannot prove lack of support. Custom UAPI modules can extend the installed
surface beyond the official inventory.

## Discrepancies requiring preservation or review

| Evidence | Treatment |
| --- | --- |
| Both specs advertise BasicAuth; token guides document token schemes | Preserve both, attribute prose supplements |
| WHM token PHP explanation says `token`; header instruction/code says `whm` | Use the explicit header contract; retain discrepancy |
| Examples disable TLS checks | Do not copy this into project transport policy |
| WHM `php_ini_set_content` is POST with query parameters, while prose recommends POST to avoid URL length limits | Keep source locations; request-body placement remains unresolved |
| UAPI `SSL/install_ssl` is GET in OpenAPI; official tutorial uses POST | Record tutorial transport evidence without silently adding a canonical variant |
| Nullability/default/required combinations do not define query-null encoding | Preserve all fields; mark wire semantics unresolved |
| UAPI feature status differs from generic success/failure examples | Preserve operation-specific response meaning |
| Bridge guide prints `json_api` | Preserve typo evidence; route sources establish `json-api` |
| Structured flags cannot capture all replacement guidance or plugin prerequisites described in prose | Preserve descriptions and separately reviewed annotations |

The [SSL tutorial](https://api.docs.cpanel.net/guides/quickstart-development-guide/tutorial-call-uapis-ssl-install-ssl-function-in-custom-code)
and [upload tutorial](https://api.docs.cpanel.net/guides/quickstart-development-guide/tutorial-use-uapis-fileman-upload-files-function-in-custom-code)
are pinned as supplementary evidence. No universal retry, null-wire-encoding,
or complete plugin-to-operation contract was found; the tool must report gaps
instead of generating one.

The WHM API Shell UI documentation returned HTTP 403 to the snapshot downloader
and is recorded as unavailable supplementary material in the manifest. It is
not a normative input; the pinned API pagination guide supplies the start-index
contract. Retrieval failure did not cause an empty file to be accepted.

## Refresh and handoff

Use the [refresh policy](README.md#refresh-policy) to add new immutable source
sets. The next design must retain original evidence alongside normalized
fields and diagnostics. Source audit completion does not close the canonical
map, ingestion CLI, parity, server capability, or drift-reporting tasks.

## Runtime observations: 2026-10-13

This evidence was collected after the pinned-source audit above. It supplements
that historical record without changing the OpenAPI snapshots, source manifests,
canonical map, or contract hashes.

An explicitly authorized direct request to
`https://test.oblak.host:2083/execute/DomainInfo/list_domains`, authenticated as
`npr` with its cPanel account token, returned HTTP 200. Its JSON root contained
`status`, `data`, `errors`, `messages`, `warnings`, and `metadata`, without the
`result` wrapper declared by the pinned operation schema. The observed status
was integer `1`; `data.main_domain` matched the expected account domain;
errors, messages, and warnings were null; metadata decoded as a JSON object.
No credential or full response payload is recorded here.

The initial library request rejected the bare envelope because its reader expected
the pinned wrapper. The direct parser now supports both forms, preferring a
present `result` field even if malformed and recognizing the bare form only when
a top-level `status` field exists. Outcome and payload validation remain required;
the WHM bridge's `data.uapi` contract is unchanged.

The subsequent live library call, `Client` configured for direct cPanel access
followed by `uapi()->domains()->list()`, returned the expected main domain.
`responseMeta()` contained HTTP 200, UAPI status `1`, and null WHM metadata;
`isBridged()` was false. The returned domain payload included `main_domain`,
`addon_domains`, `parked_domains`, and `sub_domains`; `is_temporary` was absent.
This verifies direct library execution for this operation, account, server, and
date. It does not establish a universal envelope for other UAPI operations or
servers, and this prose observation is not a revision-bound saved live-evidence
artifact.

The domain/PHP inventory slice was then exercised through the actual PHP client
with verified TLS, redirects disabled and no retries. UAPI
`LangPHP/php_get_installed_versions` returned HTTP 200/status `1` and four package
names both directly as `npr` on port 2083 and through WHM on port 2087. The bridged
result also retained outer WHM result `1`; direct access had no WHM metadata.

WHM `get_domain_info` returned HTTP 200, but the initial typed payload reader
rejected it: the pinned schema declares integer `port` and `port_ssl`, while the
server returned strings `"80"` and `"443"`. Sanitized diagnostics showed the other
reviewed fields had their expected types, including nullable IPv6 and integer
flags. Compatibility accepts canonical nonnegative decimal strings within PHP
integer range in addition to pinned integers. Public port properties normalize
these values to integers, while `toArray()` preserves their original wire type.
Whitespace, signs, leading zeros, fractions, exponents and overflowing strings
are rejected. Synthetic offline regression cases record the representation
without copying server account data or changing pinned schemas/contract hashes.

The corrected library recheck returned a typed domain inventory containing the
known `npr.rs` account, HTTP 200 and WHM result `1`. The separately invoked PHPUnit
live group then passed all six checks for WHM `version`/`get_domain_info` and direct
plus WHM-routed UAPI `DomainInfo/list_domains`/`LangPHP/php_get_installed_versions`
(45 assertions). This verifies these operations and routes on this server at the
time of the run; it is not saved revision-bound evidence for the parity report.

The server identity/health slice subsequently exercised WHM `gethostname`,
`systemloadavg`, and `getdiskusage` as `root` on `test.oblak.host:2087`, through
the actual PHP client with verified TLS, redirects disabled, and no retries.
`gethostname` returned the configured hostname with HTTP 200 and WHM result `1`.
The other two operations also reported HTTP 200/result `1`, but initially failed
typed payload validation. Restricted diagnostics confirmed these differences:

- `systemloadavg` supplied `one`, `five`, and `fifteen` as decimal strings such
  as `"1.20"`, although the pinned schema declares numbers. `ServerLoadAverage`
  accepts finite decimal strings in addition to native integers/floats and preserves
  their exact representation in properties and exports. The accepted string syntax
  is an optional sign, digits, and an optional decimal fraction; whitespace,
  exponent notation, nonnumeric values, and overflow to infinity are rejected.
- `getdiskusage` supplied integer `percentage: 0`, contrary to its pinned minimum
  of 1. The parser accepts nonnegative integers for that field. All four inode
  fields (`inodes_available`, `inodes_ipercentage`, `inodes_total`, `inodes_used`)
  were explicitly present with null values on one partition, although the pinned
  schema does not declare them nullable. The parser preserves these nulls and
  their presence, retaining the pinned integer minima for nonnull inode values.
  Other numeric fields remain strict integers with their reviewed minima.

Sanitized synthetic fixtures cover these differences without retaining device
labels or full server responses. Partition order varied across calls, so live
assertions inspect every record and require at least one complete inode sample
without depending on the first record. After correction, the full opted-in live
suite passed nine tests and 147 assertions, including all three new WHM methods
and the six prior checks. The default offline suite remains independent of live
credentials and networking. This observation is scoped to the server/date and
working-tree implementation tested; it is not revision-bound saved evidence and
does not change pinned snapshots, canonical map hashes, or their source contracts.


### PHP and feature inventory observations (22-operation slice)

The expanded read-only slice was compared with the immutable 2026-10-13 sources.
Two pinned feature-schema discrepancies are handled explicitly: `cpuser_setting`
is nullable according to both `nullable: true` and prose, despite an enum that
only lists integers 0 and 1; `is_disabled` is required by the list-data schema,
although the `disabled` list prose says that return is not used. The parser permits
null for `cpuser_setting` and retains the required `is_disabled` check. Disabled-list
`value` polarity is preserved rather than normalized.

Authorized actual PHP requests to `test.oblak.host` returned successful HTTP 200 /
WHM result 1 envelopes but exposed these additional wire representations:

- `php_get_handlers`: `lsapi` occurs in available/selected handlers, beyond the
  pinned `suphp`, `cgi`, `dso`, and `none` enum. Only `lsapi` is added.
- `get_feature_metadata`: `is_plugin` uses exact strings `"0"` and `"1"`.
- `get_featurelist_data`: `is_disabled` and `value` use exact strings `"0"` and
  `"1"`; `is_standalone_experience` and `is_meridian_only` were booleans (`false`),
  and `suppress_plugin_label` was boolean (`true`).
- `get_users_features_settings`: `feature_list_setting` was string `"1"`, and
  `cpuser_setting` was explicitly null.

Compatibility is restricted to those fields: the four observed string-flag fields
accept exact `"0"`/`"1"` as well as pinned integers; the three observed boolean-flag
fields accept booleans as well as their pinned types. Structured array returns
preserve the original wire type, with precise PHPStan unions. Other flags, including
`has_feature` and nonnull `cpuser_setting`, remain pinned integers. Synthetic
regressions cover accepted and rejected representations without retaining raw
server responses. Pinned snapshots, map hashes and source contracts are unchanged.

UAPI `Features/list_features` permits arbitrary feature names. Canonical numeric
JSON object names become integer keys in PHP arrays; its return type is therefore
`array<array-key, 0|1>` without rejecting valid upstream names. DNS lookup explicitly
permits null data; an internal presence marker now distinguishes that from an
omitted data member. UAPI impacted-domain inputs support the documented repeated
`domain` parameter using literal repeated keys, including on the WHM route.

After these corrections, the full explicitly opted-in live suite passed **39 tests
and 277 assertions**, including 14 new WHM operations and each of the eight new
UAPI operations through both direct cPanel-token and WHM-token routes. All requests
used verified TLS, redirects disabled, and no retries. The final offline suite
passed **1,087 tests and 4,757 assertions**, with formatting, maximum-level static
analysis, Composer validation and offline API-map checks also passing. Coverage
now declares 29 implemented operations; these live observations remain scoped to
the tested server/date and working tree, without saved revision-bound evidence.
