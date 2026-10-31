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

At the initial 2026-10-13 source audit, PHP `HttpTransport::send` was infrastructure
and implemented API operation coverage was zero. That source audit made no server
calls; the earlier observation of 675 advertised WHM functions is historical.
At the PR20 checkpoint on 2026-10-16, [generated coverage](coverage.md) records
74 operations in 13 groups, and the [saved evidence](evidence/2026-10-16-discrepancy-policy.json)
covers 43 route-specific probes across 25 operations on cPanel 11.138.0.15.
It passes with 2 acknowledged Quota discrepancies, 0 unexpected and 0 stale;
the unknown field contracts remain unresolved under `whm-api-client-8x0.4.3.1`.
Generated coverage, offline expectations and live observations are separate claims.

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

The PHP client currently supports GET queries and form-encoded POST bodies.
The inventory's 13 JSON body operations (8 WHM, 5 UAPI) and 9 multipart body
operations (1 WHM, 8 UAPI) remain unsupported. Selected implementations are
tracked under `whm-api-client-8x0.1.18` and `whm-api-client-8x0.1.19`;
generic `call()` does not supply these body formats.

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
time of the run; it is not saved revision-bound evidence.

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
analysis, Composer validation and offline API-map checks also passing. The slice
brought the typed surface to 29 operations; these live observations remain scoped to
the tested server/date and working tree, without saved revision-bound evidence.

## Runtime observations: 2026-10-14

The account lifecycle slice (`whm-api-client-8x0.1.16`) was verified against the
same immutable 2026-10-13 sources with authorized read-only requests to
`test.oblak.host` through the actual PHP client (verified TLS, redirects disabled,
no retries, no mutations).

POST execution: the pinned WHM OpenAPI warns that the server records the URL query
string in its access log, recommends POST for secret-bearing functions, and shows a
documented `POST /json-api/<function>?api.version=1` request with the parameters
in the body; the pinned UAPI tutorials send form-encoded POST bodies to
`/execute/...`. The library therefore keeps the routing fields in the query string
and form-encodes operation parameters. Runtime confirmation on 2026-10-14: WHM
`version` and a filtered `listaccts` over POST returned the same payloads as GET
(HTTP 200, result 1); direct UAPI `DomainInfo/primary_domain` as `npr` over POST and
the WHM bridge `uapi_cpanel` over POST both returned HTTP 200/status 1 with payloads
identical to GET. No mutating function was executed.

Account inventory (`listaccts`, `accountsummary`): every observed field matched its
pinned type except `uid`, which was a decimal string rather than the pinned integer.
`suspendtime` was integer `0` for an unsuspended account (pinned nullable timestamp
or string), `standalone_feature` and `standalone_feature_name` were empty strings,
and several limits (`maxaddons`, `maxparked`,
`min_defer_fail_to_trigger_protection`) were numeric strings within the pinned
`string|integer` union. Compatibility is restricted to `uid`: canonical nonnegative
decimal strings within PHP's integer range are normalized to the pinned integer;
other representations are rejected with metadata retained.

Packages (`listpkgs`): the observed record carried only `CPMOD`, `DIGESTAUTH`,
`FEATURELIST`, `IP`, `LANG`, `name` and an undocumented `PACKAGE_TYPE`, which the
typed record ignores. All pinned fields are optional, so no compatibility was needed.

After these corrections the full explicitly opted-in read-only live suite passed
**46 tests and 328 assertions** (39 previous plus 7 new). The offline suite passed
**1,256 tests and 5,431 assertions** with formatting, maximum-level static analysis,
Composer validation and offline API-map checks, and the typed surface reached 39
operations. `removeacct`, `passwd` and `modifyacct` leave their alias or pattern
parameters (`user`, `enabledigest`, `MXCHECK-<domain>`) to `call()`.
The approval-gated mutating lifecycle test was not executed; these observations
remain scoped to the tested server/date without saved revision-bound evidence.

## Runtime observations 2026-10-15 (generated client cutover)

After the hand-written groups were replaced by the groups generated from the
pinned map and `docs/api/overlay`, the explicitly opted-in read-only live suite
was re-run against `test.oblak.host` (server version `11.138.0.15`) through the
actual PHP client with verified TLS, redirects disabled and no retries. Every
request was read-only; the approval-gated mutating lifecycle test was not run.

Live corrections added: **none**. Every declared field present in the responses of
the 32 read-only generated operations (22 WHM; 10 UAPI, each on both the direct and
bridged routes) validated against the generated specs, which already carry the
2026-10-13/14 corrections (load-window numeric strings, nullable inode counters,
decimal-string ports and `uid`, string/boolean feature flags, and the
`accountsummary` `acct[].suspendtime` string/integer/null union). The two
candidates flagged in review passed unchanged: `listaccts` `acct[].suspendtime`
matched its pinned nullable integer type, and `acct[].child_nodes` items in
`listaccts` and `accountsummary` matched their pinned object shape (or were not
supplied). Undeclared fields are dropped by the generated validator rather than
rejected, so this run does not establish their types.

Suspended accounts were never observed, so `listaccts` `acct[].suspendtime` carries a
`doc` correction that gives it the `accountsummary` contract: the pinned
`accountsummary` record declares the same field of the same account record as a
nullable string and returned integer `0` live, so both operations accept a string, an
integer or `null`. `acct[].child_nodes` keeps its pinned item shape in both
operations: the pinned item schema lists the `alias` and `workload` properties without
an explicit `type`, the generator renders it as an object, and only empty lists were
observed, so a non-object item is rejected and nonempty lists remain unverified.

The only live failure was outside the payload layer: the test-only request scope
guard approved the filtered `listaccts` GET by exact URI with the old parameter
order, while the generated encoder emits the restored signature order
(`search`, `searchtype`, `searchmethod`). The approved URI was updated to the same
three parameters in the generated order; the request scope is unchanged. The full
live suite then passed **46 tests and 329 assertions**. These observations remain
scoped to the tested server and date without saved revision-bound evidence.

## Runtime observations 2026-10-15 (DNS slice)

The 21 read-only DNS operations generated from the overlay (14 WHM API 1 `DNS`
functions and 7 UAPI `DNS` functions) were verified against `test.oblak.host`
(server version `11.138.0.15`) through the actual PHP client and the opt-in `live`
group, with verified TLS, redirects disabled and no retries, against the test
account's zone `npr.rs`. The UAPI functions ran on both the direct cPanel route
(`npr` token) and the WHM bridge. Every request was a GET approved by exact URI;
the `getzonerecord` line and the `lookupnsip`/`lookupnsips` host were derived during
the run from `parse_dns_zone` and `get_nameserver_config` responses and approved only
for those values. No mutating function was called. Field types were established
from type-only skeletons of the same approved requests made through generic
`call()`; no payload values were recorded.

Live corrections added (overlay `live` evidence, version `11.138.0.15`):

| Operation | Path | Observed | Pinned | Correction |
| --- | --- | --- | --- | --- |
| WHM `has_local_authority` | `records[].error` | explicit `null` on a successful check | string, returned only on failure | nullable |
| UAPI `DNS/has_local_authority` (both routes) | `[].error` | explicit `null` on a successful check | string, returned only on failure | nullable |
| WHM `is_alias_available` | `resolver` | explicit `null` | string | nullable |
| UAPI `DNS/is_alias_available` (both routes) | `resolver` | explicit `null` | string | nullable |
| WHM `listmxs` | `record[].preference` | decimal string | integer | accept canonical decimal strings, normalized to `int` |
| UAPI `DNS/ensure_domains_reside_only_locally` (both routes) | `[]` | one-item list whose item is `null` | non-nullable string items, although the data description documents `null` for a domain that only resolves locally | nullable items (doc and live evidence) |
| WHM `getzonerecord` | `record[].preference` (MX) | decimal string | integer | accept canonical decimal strings, normalized to `int` |
| WHM `getzonerecord` | `record[].serial`, `record[].refresh`, `record[].retry`, `record[].expire` (SOA) | decimal strings | integers | accept canonical decimal strings, normalized to `int` |

WHM `lookupnsips` returned `ipv6` as an empty string for a nameserver without an
IPv6 address, while the pinned description says the field is returned only when an
IPv6 address exists. The type matches the pinned string, so no correction was made;
the behavior is recorded as an overlay note that appears in the method's PHPDoc.

Every other field present matched its pinned type: `listzones`; `parse_dns_zone`
and `parse_zone` (record, control and comment lines, all base64 fields decodable,
with the union branches merged by the generator);
`resolvedomainname` (an IPv4 string); `get_nameserver_config`; `lookupnsip`;
`is_https_available` and `is_svcb_available` on all routes; `export_zone_files`
(one item for the one requested zone, base64 text); `fetch_ds_records_for_domains`
(one item whose `ds_records` was an empty object, as documented for a domain without
DS records); and `ensure_domains_use_recognized_nameservers` (a one-item list with a
`null` item, as documented). Requesting the `parse_dns_zone` `line_index` plus one
from `getzonerecord` returned the same line (`Line` equal to the requested number,
same record type), confirming the 0-based index and the 1-based line number.
`listmxs` records and resource records from `parse_dns_zone`/`parse_zone` also carried
an undocumented string field `dname_raw`, which the generated validator drops; this
run does not establish its contract.

### `getzonerecord` record data

In the pinned WHM schema each `getzonerecord` `record[]` item is
`getzonerecordResponseBase` (`Line`, `class`, `name`, `ttl`, `type`) with a
discriminator mapping on `type` to 19 per-type schemas (`A`, `A6`, `AAAA`, `AFSDB`,
`ALIAS`, `CAA`, `CNAME`, `DNAME`, `DS`, `HINFO`, `LOC`, `MX`, `NS`, `PTR`, `RP`, `SOA`,
`SRV`, `SSHFP`, `TXT`) that carry the record data (addresses, `exchange`/`preference`,
`txtdata`, the SOA timers, and so on). The validator drops undeclared fields, so a
contract built from the base schema alone would silently remove the record data. Live
verification therefore compares the observed fields against the rendered effective
contract (the generated spec), not only against the pinned schema or the validated
result.

The generator expands the discriminator mapping and merges the base and every
per-type field into one object whose 50 fields are all optional, as no field is required upstream:
integers stay `int`, the 0/1 enums (`CAA` `flag`, `CNAME` `flatten`, `TXT`
`unencoded`) become flags, and every other field, including `class` and
`flatten_to`, is a string. A temporary types-only probe then requested every
resource-record line of the test zone through generic `call()` and through the typed
method, recording field names and wire types only; it was deleted afterwards. The
zone held `A`, `CNAME`, `MX`, `NS`, `SOA` and `TXT` records; other record types were
not observed. Observed against the merged contract:

- `MX` `preference` and `SOA` `serial`, `refresh`, `retry` and `expire` arrived as
  decimal strings although pinned as integers; they gained `live` corrections that
  accept canonical decimal strings and normalize them to `int` (table above).
- Every other declared field present matched its merged type: the base fields,
  `A` `address`, `CNAME` `cname`, `MX` `exchange`, `NS` `nsdname`, `SOA` `Lines`,
  `mname` and `rname`, and `TXT` `txtdata` and the integer flag `unencoded`.
- Undocumented fields, dropped by the validator and not established here: a string
  `dname_raw` on every record, a decimal-string `minimum` on the `SOA` record and a
  list `char_str_list` on `TXT` records.

With the corrections the typed method validates every record line of the test zone,
and the live test requires the derived record to carry at least one field beyond the
five base fields.

### DNS slice counts

With every correction above, the full explicitly opted-in read-only live suite passed
**74 tests and 707 assertions with no skips**: the 46 tests that existed before the
slice (3 server identity and health, 6 read-only operation, 30 inventory and 7
account tests) plus 28 DNS tests. The offline suite passed **1,540 tests and 8,001
assertions**, with `composer api-map:check` and `composer api-map:generate:check`
current. These observations remain scoped to the tested server and date without
saved revision-bound evidence.

The generic merges of union branches and discriminator mappings produce the same
specs for `getzonerecord`, `parse_dns_zone` and `parse_zone` that earlier doc-evidence
`replace` corrections produced, so the overlay carries no `replace` for them; the
`live` corrections above remain.

## Runtime observations 2026-10-15 (account lifecycle)

With the owner's explicit approval, the approval-gated mutating lifecycle test
(`AccountLifecycleLiveTest`, group `live-mutating`) ran against `test.oblak.host`
(server version `11.138.0.15`) through the actual PHP client with verified TLS,
redirects disabled, no retries and the request guard bound to one throwaway account,
`wacltest`, on `wacltest.example.com` (an RFC 2606 domain). A read-only probe first
confirmed that neither `example.com` nor `wacltest.example.com` had a zone on the
server. The test created the account with the first creatable package, then ran
`accountsummary`, `suspendacct`, `unsuspendacct`, `passwd`, `changepackage`,
`modifyacct` and `removeacct`, all mutations over POST.

Live corrections added: **none**. Every response validated against its generated
contract, so no `PayloadMismatchException` was raised; the `accountsummary` checks saw
the suspension flag change from 0 to 1 and back, and the modified contact email.
The first run failed one test assertion after a successful creation: the test
required every `createacct` field to be a nonempty string, and at least one was an
empty string (valid for the pinned string fields). The server configures two
nameservers, consistent with the unused `nameserver3`/`nameserver4` fields being
empty. The test's cleanup removed the account. The test now requires only the account
IP and the first nameserver to be set; the full lifecycle then passed **1 test and 20
assertions**. Read-only checks after both runs confirmed that the server reports the
account as nonexistent and that its zone is gone. These observations remain scoped to
the tested server and date without saved revision-bound evidence.

## Sanitized evidence runner — 2026-10-15

The first runner slice exercised WHM `version` and `systemloadavg`, plus UAPI
`DomainInfo/list_domains` through direct and WHM-bridged routes on `test.oblak.host`.
All four generated calls returned successful API outcomes and passed payload
validation, with no structural discrepancies on cPanel `11.138.0.15`.
[Core evidence](evidence/2026-10-15-core.json) records revision
`788cdbd34b470c77af10a95374fcf70c2434eab6`, actual routes/methods and source,
generator, overlay and effective-contract hashes. No overlay correction was needed.
The observer captured the same response bytes before normalization; only sanitized
paths/types were persisted. Empty lists do not establish their item types.

The subsequent Email batch added `uapi:Email/list_pops` and repeated the four core
probes. All six passed on the same cPanel version, with zero discrepancies and no
response corrections. [Core and Email evidence](evidence/2026-10-15-core-email.json)
records revision `240c844ee37567697ee869cfe0e07e3515394a96`. Both Email routes
returned nonempty lists with observed `email`/`login` strings and
`suspended_incoming`/`suspended_login` integers. Live arguments were omitted;
explicit booleans, regex encoding, empty lists, optional fields and failure
semantics are independently covered by 54 offline endpoint cases. This establishes
these observations for the recorded route, server version and contracts, not
complete Email coverage or all possible records. The evidence/audit commit adds
only these saved observations; executable code and contracts remain those of the
recorded revision.

## Account, domain, PHP and Email inventory evidence — 2026-10-15

The next bounded batch adds evidence for eight existing operations: WHM
`listaccts`, `accountsummary`, `get_domain_info`, `php_get_installed_versions` and
`php_get_system_default_version`, plus UAPI `DomainInfo/primary_domain`,
`LangPHP/php_get_installed_versions` and `LangPHP/php_get_domain_handler` on both
direct and WHM-bridged routes. The account list uses an exact username search for
`npr`; the summary targets that account explicitly. Both PHP handler requests use
`type=home`. The code catalog pins these arguments and rejects changed recipes
before any network request.

The same delivery adds three generated Email operations from the pinned map:
`list_mail_domains`, `list_domain_forwarders` and `count_pops`, bringing typed
coverage to 64 operations. Each has independent synthetic request, payload,
empty/zero, failure and metadata tests. All three have no-argument GET probes on
both UAPI routes; optional flags and filters remain independently tested offline.

[Inventory and Email evidence](evidence/2026-10-15-inventory-email.json) records
revision `95631264b8cf66b92e97026c0e6487d65dae6ea1`, actual methods/routes and source,
map, generator, overlay and effective-contract hashes. All 23 probes across 15
operations passed API, payload and probe checks on `test.oblak.host`, cPanel
`11.138.0.15`, with zero structural discrepancies and no response corrections.
The initial sandboxed attempt received no HTTP responses; the authorized run with
network access produced the saved evidence. Each probe captures its original
response, with no replay to recover observations.

Both `list_mail_domains` routes returned nonempty lists with string `domain`
fields and absent `select` fields. Both `list_domain_forwarders` routes returned
empty lists, which establish no observations of `dest` or `forward` item fields.
Both `count_pops` payloads were integers; their values are not retained, and this
does not establish whether the main account is included. Nonempty forwarder
records and explicit mail-domain options remain offline-tested only. Saved data
contains sanitized structural paths and types, not live domains, account values,
request arguments, raw responses or credentials. The evidence/audit commit changes
no executable code or contracts from the recorded revision.

## SSL and AutoSSL inventory evidence — 2026-10-16

The SSL slice adds six generated UAPI operations: `installed_hosts`,
`get_autossl_excluded_domains`, `get_autossl_problems`,
`get_autossl_renewal_status`, `is_autossl_check_in_progress` and
`can_ssl_redirect`. Each has a reviewed GET recipe through both direct cPanel and
WHM-bridged UAPI for account `npr`; renewal status explicitly targets `npr.rs`.
The renewal operation requires cPanel 134 or later. Independent endpoint fixtures
cover optional and nullable certificate fields, literal dotted keys, integer
flags, domain encoding, empty lists, failures, metadata and account isolation.
That slice brought the generated surface to 70 operations in 11 groups.

[SSL inventory evidence](evidence/2026-10-16-ssl-inventory.json) records revision
`a5aacbf8e342c443a210792f22b76698be93ca40`, actual methods/routes and source, map,
generator, overlay and effective-contract hashes. The run occurred on October 16
in Europe/Belgrade; the artifact timestamps are October 15 in UTC. All 35 probes
across 21 operations, including the 12 new SSL probes, passed API, payload and
probe checks on `test.oblak.host`, cPanel `11.138.0.15`, with HTTP 200 and zero
structural discrepancies. No response correction was needed. Each probe captured
its original response before normalization and field filtering, without replay.

Both `installed_hosts` routes returned nonempty lists. Certificate issuer and
subject fields retained the documented literal dotted keys. `modulus` and
`modulus_length` were explicitly null; `ecdsa_curve_name`, `ecdsa_public` and
`key_algorithm` were absent. The observed timestamps and flags were integers,
while certificate text, paths and names were strings. Both renewal responses
included all four integer flags. Check-progress and redirect-capability payloads
were integers; saved evidence does not retain their values or establish that a
check was running, a renewal will happen or redirects are enabled.

Exclusion and problem lists were empty on both routes, so their item fields remain
offline-tested only. Non-null modulus values and the absent certificate fields
also remain unobserved in this run. These observations are scoped to this server,
version, account and recipe; they do not establish all certificate variants or
complete SSL coverage. Saved evidence contains structural paths and types only,
with no certificate bodies, account/domain values, paths, arbitrary diagnostics,
request arguments or credentials. The evidence/audit commit changes no executable
code or contracts from the recorded revision.


## Runtime observations (2026-10-16, Quota inventory)

The two documented UAPI Quota operations are generated as
`uapi()->quota()->getQuotaInfo()` and `getLocalQuotaInfo()`, with four exact GET
recipes for direct cPanel and WHM-bridged access to the designated `npr` test
account on `test.oblak.host`. Account-total disk fields are in MB; local disk
fields are in bytes. Zero limits mean unlimited or disabled quotas; the
documentation lists zero remaining values for that case but does not describe
remaining values for an exhausted finite quota. Remaining values must be read
with their limits and the `under_*` flags. Zero usage can mean no usage or
disabled quotas. Local byte limits can lag updates; separate requests and
distributed account totals must not be compared as equivalent measurements.

On 2026-10-16, a read of the current official
[UAPI OpenAPI specification](https://api.docs.cpanel.net/_spec/specifications/cpanel.openapi.json)
returned SHA-256 `172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc`,
identical to the pinned source. Both Quota path objects were unchanged. The local
operation's description refers to `Quota::get_quota`, which is absent from the
map; the documented account-total operation is
[`Quota/get_quota_info`](https://api.docs.cpanel.net/specifications/cpanel.openapi/disk-quotas/quota-get_quota_info).
The [local operation](https://api.docs.cpanel.net/specifications/cpanel.openapi/disk-quotas/quota-get_local_quota_info)
requires cPanel 88+, while account totals require cPanel 56+.

The initial live run used committed revision
`2cb479bf6fb681815825531e7c16481bbabfc2ea` on cPanel `11.138.0.15`.
All 35 previous probes passed. All four Quota requests received HTTP 200 and
successful API outcomes but failed payload validation. The
[initial artifact](evidence/2026-10-16-quota-initial.json) retains only the four
Quota records from that run, with each record and its provenance unchanged:

- Account totals returned `inode_limit`, `inodes_remain`, `megabyte_limit` and
  `megabytes_remain` as numeric strings on both routes. The three `under_*` flags
  were numeric strings; the compatibility rule admits only documented string
  `0`/`1` flags. The overlay admits those representations only for the
  observed fields, normalizing them through the existing integer/number/flag
  validators; integer bounds and exact flag checks remain enforced.
- Local `byte_limit` and `inode_limit` were explicit `null` on both routes. They
  remain nullable separately from zero and omission, without inferring what null
  means. `bytes_used` was a numeric string directly and an integer through WHM;
  canonical decimal strings within PHP integer bounds normalize to integers.
- Total `inodes_used` and local `inodes_used` were integers, and total
  `megabytes_used` was a float. Their contracts were not broadened.
- Both account-total responses included unreviewed integer-valued fields,
  represented only by `$.*`. This does not establish their names or exact count.
  The client removes them under its existing unknown-field behavior; the evidence
  runner reported `unknown_field` and exited nonzero in the initial and
  corrected-contract Quota runs. PR20 adds exact acknowledgments while preserving
  these observations, as recorded in the discrepancy-policy section below. Follow-up
  `whm-api-client-8x0.4.3.1` tracks their review. No names or fields are guessed,
  and no discrepancy is silently suppressed.

Ten field corrections cite the initial same-response fingerprint. Independent
synthetic tests cover the documented and accepted wire forms, zero/fractional
values, omission/null distinctions, conversion failures, all three envelope
forms, routing/authentication, account isolation, metadata failures and unknown
key privacy. The fixtures are authored independently of generated specs and do
not contain live quota values. At that point, distributed accounts, quota
enforcement changes, finite-limit scenarios and other server versions had not
been live-tested. The later finite-limit scenario is recorded below.


[Corrected-contract evidence](evidence/2026-10-16-quota-inventory.json) records
revision `344136e95e8b8359bf50e1d5fee157d980add82e`. All 39 probes across 23
operations passed API outcome, payload validation and probe checks on
`11.138.0.15`. The four Quota probes retained the same field representations as
the initial run. Passing validation now establishes that the string flag values
matched the accepted `0`/`1` grammar, without persisting which flag value was
returned. The two local probes had no discrepancies. Each account-total route
still reported `unknown_field` at `$.*`; consequently `composer live:verify`
exited **1**, not a clean evidence pass.

The subsequent evidence/documentation commit corrects the initial observation's
flag wording in the overlay and regenerated PHPDoc. It does not change the
operation specs, response corrections, request recipes or executable behavior.
The artifact retains the actual earlier revision and overlay digest; its
applicability to the unchanged effective specs was checked explicitly rather
than replacing provenance or treating the later HEAD as live-observed.

This slice adds two generated methods (72 total in 12 groups), four probe recipes
and ten evidenced field corrections, without runtime/generator changes or
mutations. The unknown-field review remains tracked under
`whm-api-client-8x0.4.3.1` ([issue #18](https://github.com/hostingpal/whm-api-client/issues/18)).


## Contract review (2026-10-16, MySQL inventory)

The read-only database inventory slice generates
`uapi()->mysql()->listDatabases()` and `listUsers()` from
[`Mysql/list_databases`](https://api.docs.cpanel.net/specifications/cpanel.openapi/database-management/mysql-list_databases)
and [`Mysql/list_users`](https://api.docs.cpanel.net/specifications/cpanel.openapi/user-management/mysql-list_users).
Both are parameterless GET operations available in cPanel 82 and later. They
are disabled when the MySQL/MariaDB role is disabled and remote MySQL is not
configured. This slice covers two of the module's 25 documented operations.

Database records declare optional `database`, integer `disk_usage` in bytes and
`users` list fields. User records declare optional `user`, `shortuser` and
`databases` list fields. No row fields are required or nullable in the pinned
schemas. Empty lists, omitted fields and zero disk usage remain distinct. The
shared response-constraint policy remains unchanged; this slice does not add
general minimum/range validation or infer numeric-string compatibility.

On 2026-10-16, the current official
[UAPI OpenAPI source](https://api.docs.cpanel.net/_spec/specifications/cpanel.openapi.json)
had SHA-256 `172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc`,
identical to the pinned snapshot. Both MySQL operation objects were unchanged.
Independent synthetic fixtures exercise nonempty and empty records, nested
lists, omission, malformed values and account-bound request/error behavior.
These fixtures establish offline behavior, not live observations of row fields.

### Runtime observations

The [MySQL inventory evidence](evidence/2026-10-16-mysql-inventory.json) records
committed revision `1592922f10d23859f815bb626c0cf45c9e74ede7` on cPanel
`11.138.0.15`. All 43 probes across 25 operations received HTTP 200 and passed
API outcome, payload validation and probe checks. Both MySQL operations returned
empty lists on both the direct cPanel and WHM-bridged routes for the designated
test account. Their four records contain no discrepancies. This observes the
routes and empty-list responses only; database/user record fields, nested list
contents and `disk_usage` wire types remain unobserved. No MySQL response
corrections were added. Nonempty inventories, remote MySQL configurations,
disabled-role behavior and other server versions require separate evidence.

The full command exited **1** because the two known Quota account-total
`unknown_field` entries at `$.*` remain. An explicit comparison with
`2026-10-16-quota-inventory.json` found identical discrepancy tuples, including
operation, variant, route, HTTP method, server version, path, kind and masked
path type (`int`). No new discrepancy was present. The wildcard still does not
establish unknown key names or counts; this comparison does not claim that the
underlying unknown fields are identical. Detection and exit policy were unchanged
for that run; the subsequent PR20 policy is recorded below. Unknown field
contracts remain tracked in [issue #18](https://github.com/hostingpal/whm-api-client/issues/18).

All 43 effective-spec hashes match the current catalog. The artifact retains
the actual code revision and input hashes. It stores structural paths/types
only, without database/user names, disk-usage values, credentials, request
arguments or raw error text. This batch adds two generated methods (74 total
in 13 groups), four recipes and 253 independent offline test cases, with no
runtime/generator changes or live mutations.

### Runtime observations: 2026-10-16 discrepancy policy

Tracking: `whm-api-client-8x0.2.5`; proposal and review context:
[issue #18](https://github.com/hostingpal/whm-api-client/issues/18#issuecomment-6223158415).
The field-contract follow-up `whm-api-client-8x0.4.3.1` stays open.

At committed revision `ac37938050033c7e8a6d12f3347eb2bd6f6773a6`, the existing
43 read-only GET probes across 25 operations passed API outcomes, typed payload
validation and probe checks on cPanel `11.138.0.15`. No requests, generated
contracts, overlays or fingerprint rules changed. The
[saved schema-2 artifact](evidence/2026-10-16-discrepancy-policy.json) retains the
two Quota total `unknown_field` / `$.*` observations and all sanitized paths/types.
Its separate `discrepancy_review` reports **2 acknowledged, 0 unexpected, 0 stale**;
the command exited **0**.

The policy binds only those two historical observations to the immutable
[Quota artifact](evidence/2026-10-16-quota-inventory.json), SHA-256
`5f65d66acc36673ee37be1b4e024dad3ac725e5313d45c208abb489d5c9f6811`.
Matching requires operation, variant, route, actual method, server version,
pinned source and effective contract hashes, and the entire sanitized payload
fingerprint and discrepancy set. It never overrides a failed outcome. A missing,
changed or resolved acknowledged observation fails until reviewed; unrelated
clean observations need no acknowledgment.

This is a passing run **with acknowledged structural discrepancies**. Unknown
key names and multiplicity remain unobserved: different integer-valued fields can
collapse to the same `$.*` fingerprint. No key names or values were disclosed,
no unknown fields were added to the typed return shape, and no historical
artifact was rewritten. The MySQL inventories remain empty, so this run does not
resolve their row-field evidence gap (`8x0.2.4`).

Independent offline tests exercise policy scope, full-fingerprint changes,
stale/duplicate observations, failure precedence, source tampering, output-file
aliases, privacy and CLI exit behavior. These synthetic and saved-artifact tests
are distinct from the explicitly opted-in test-server run above. The policy is
development tooling; it adds no endpoint coverage or runtime dependency to the
core package.

### Runtime observations: 2026-10-17 populated MySQL inventory

Tracking: `whm-api-client-8x0.2.4`. The
[populated MySQL evidence](evidence/2026-10-17-mysql-populated.json) records
revision `294c59ca710bd9beee0b2ddfe900b85613120112` on cPanel `11.138.0.15`.
The explicitly opted-in fixture created two databases and two database users
on the designated `npr` account, linking one pair with `SELECT` privileges and
leaving the other pair unlinked. No tables or WordPress installation were needed
to exercise these inventory contracts.

Both direct and WHM-bridged GET routes observed `database`, integer `disk_usage`,
and `users` in database records, plus `databases`, `shortuser`, and `user` in user
records. Each nested list field had both populated and empty-list observations.
The same response supplied the generated result and sanitized fingerprint;
in-memory fixture assertions confirmed the exact created records and their
relationships without retaining resource names or values. No MySQL discrepancy
or compatibility correction was needed. This run does not establish positive
disk usage, other server versions, remote MySQL, or disabled-role behavior.

All 43 probes across 25 operations passed HTTP/API/payload/probe checks. The
unchanged discrepancy policy reports **2 acknowledged, 0 unexpected, 0 stale**;
the two unresolved Quota observations remain visible. The command exited **0**.
Generated coverage remains 74 operations in 13 groups. Setup and cleanup used
five generic form-POST functions from the pinned MySQL source; these calls do not
establish new typed coverage or change the GET probe catalog.

The separate `mysql_fixture` report records successful setup, capture,
expectations, and cleanup. All four resources have confirmed creation and
confirmed absence after cleanup. Exact guards require both live opt-ins and
bind the account, operations, names, and arguments; preexisting names prevent
setup. Offline tests cover setup/capture/deletion failures, uncertain creation
and deletion outcomes, continued cleanup, refused unrelated requests, and
output failure after cleanup. No mutation is retried. The live run verifies the
successful lifecycle; failure cleanup paths are independently tested offline.

Implementation effort before external review: ten manually authored tool/test/doc
files, 1,679 added and 21 removed lines in the fixture commit, including two
fixture classes and one test-only stateful server. The suite gained 118 test
cases (3,289 total, 23,667 assertions). Two internal review passes found no
remaining issues; the first full gate caught a test-helper file-layout error,
which was corrected before the passing full gates and this single live run.
Artifact and current-progress documentation are a separate commit. This records
the plumbing cost for the bounded reduction pilot rather than introducing a
general fixture framework.

External review of `abd9b1f49d7b3d8e1dbaa9bef84e767f6e0a7d2e` independently
passed the offline gates, recomputed the evidence hashes, and reran the default
read-only command. Its four MySQL lists were empty again, corroborating cleanup.
The review suggested manual recovery guidance for failed cleanup, now included
in the runner documentation. Positive `disk_usage` remains a separate nonblocking
evidence follow-up under `whm-api-client-8x0.2.7`. The user also deferred the real
HostingPal consumer exercise beyond this pilot; that decision adds no consumer
evidence and preserves the remaining correction-cycle gates.

### Runtime observations: 2026-10-17 finite-Quota preflight

Tracking: `whm-api-client-8x0.3.1`. The
[first finite-Quota attempt](evidence/2026-10-17-finite-quota-preflight.json)
records revision `c81036e62aa89d3c1bc4b9bb79e145f609ef8e22` on cPanel
`11.138.0.15`. All 43 baseline probes passed, with the unchanged assessment
reporting **2 acknowledged, 0 unexpected, 0 stale**. The separate finite scenario
stopped during preflight: neither account nor token creation was attempted,
cleanup was not required, and no finite-Quota observations were made. The command
exited **1**; this artifact is not finite-limit evidence.

Five subsequent guarded read-only diagnostic requests identified the recipe
problem without retaining identities, raw responses or upstream error messages.
An absent exact-user `listaccts` search returned an API error; an absent
exact-domain search succeeded with `data: null`. Full account inventory returned
a usable record list, while manual-domain and DNS-zone inventories also returned
usable lists with the designated target absent. The fixture therefore selects
exact identities locally from the full account inventory. API errors, null
payloads and malformed identity lists still fail closed. This changes only the
development recipe; it does not widen a generated contract or add a compatibility
correction. The original failed artifact remains unchanged.

### Runtime observations: 2026-10-17 finite-Quota token setup

The [second runner attempt](evidence/2026-10-17-finite-quota-token-setup.json)
records revision `15fba470a4eb38966d5dccb21202f40ebdd47e7d` on the same cPanel version. All 43
baseline probes again passed with two acknowledged Quota discrepancies. Finite
setup failed before capture; cleanup reconciled the created account and token,
revoked the token, removed the account, and confirmed account, domain and zone
absence. The command exited **1** and establishes no finite-limit observations.

A separate diagnostic invocation used the unchanged committed fixture and its
exact guards, with a reviewed lazy observer on the original token responses. It
confirmed successful token creation, a usable string token, integer `readonly`
equal to the requested flag, and a string `expires_at` exactly matching the
requested decimal timestamp. No credential, timestamp value, account marker or
upstream error text was retained. Token revocation and account/domain/zone
cleanup again succeeded. This diagnostic made no finite-Quota requests.

The pinned `Tokens/list` schema specifies an integer-or-null expiry. The fixture
accepts the exact requested integer or its exact decimal-string representation,
while preserving strict integer read-only flags and rejecting altered expiry
values, whitespace, leading zeros, exponents and floats. This is a documented
fixture compatibility decision, not generated Tokens coverage or a core schema
change. Independently authored regression expectations cover the boundary.

### Runtime observations: 2026-10-17 finite Quota limits

The [finite-Quota evidence](evidence/2026-10-17-finite-quota.json) records
revision `20cb951c94bb6c6fe9c5f3544e104c63557bd434` on cPanel `11.138.0.15`.
The guarded fixture created the explicitly designated disposable account with
64 MB configured disk quota, then used a temporary read-only account token for
direct UAPI and the WHM control credential for the account-bound bridge. Both
`Quota/get_quota_info` and `Quota/get_local_quota_info` passed HTTP, API, generated
payload and independent finite-expectation checks on both GET routes. The
separate scenario contains those four observations and its own WHM version probe.

Both total responses exposed integer `megabyte_limit`, floating-point usage and
remaining-space fields, numeric-string inode limit/remaining fields, integer
inode usage, and numeric-string `under_*` flags. Existing evidenced conversions
accepted these representations. In-memory assertions verified the configured
finite disk limit, nonnegative usage below the limit, positive remaining space
within the limit, and coherent under-limit flags. Local responses exposed integer
`byte_limit` on both routes, numeric-string direct `bytes_used` and integer bridged
`bytes_used`, integer inode usage, and null local inode limits. No generated Quota
contract or compatibility correction changed.

The fixed `unit_relationships` labels are `binary-mib` for both routes: each
observed local byte limit matched the configured quota multiplied by 1024 squared.
This records a relationship for this run, not a universal MB conversion. Usage
from separate requests was not compared; exact floating-point sum/rounding,
finite inode limits, exhausted quota, write rejection at a limit, distributed
allocation and other versions remain unobserved. Saved payload fingerprints
contain structural types only, without quota values or resource identities.

All 43 baseline probes also passed, and their unchanged policy reports
**2 acknowledged, 0 unexpected, 0 stale**. The finite scenario's separate policy
reports **0 acknowledged and 2 unacknowledged discrepancies**, one `unknown_field`
at `$.*` with masked integer type in each total response. Accordingly the full
command exited **1**, despite successful finite expectations and cleanup. No
baseline acknowledgment was reused for the disposable account. These observations
neither identify unknown keys/counts nor resolve their contracts; the existing
`whm-api-client-8x0.4.3.1` / [issue #18](https://github.com/hostingpal/whm-api-client/issues/18)
remains open. No discrepancy was removed, acknowledged or automatically corrected.

The fixture report confirms setup, capture, expectations and cleanup. Account and
token creation were confirmed, the exact token was revoked and independently
observed absent, and the owned account was removed with DNS retention disabled.
Separate account, manual-domain and zone inventories confirmed absence. A random
contact-email marker guarded ownership before token work and deletion; no
mutation was retried. Automatic service domains are outside the upstream manual
domain inventory. The live sequence comprised three normal runner invocations
and one diagnostic fixture invocation: the first created nothing, and all three
that created resources confirmed cleanup. Failed runner artifacts remain saved.

Implementation effort before external review: twelve manually authored tool,
test and runner-documentation files, with a net diff of 2,400 added and 12 removed
lines, plus this audit, current-progress documentation and the three captured
artifacts. The suite gained 167 cases (3,456 total, 24,836 assertions). The initial internal review resolved
manual-domain inventory, token capability/reporting and concurrent-run ownership
issues; two focused follow-up reviews checked the observed inventory and expiry
adaptations. The final independent focused run passed 249 cases / 2,325 assertions.
Full formatting, static analysis, tests and offline API-map checks passed. This
records the concrete recipe and review cost for the subsequent bounded plumbing
pilot; it introduces no general fixture framework or new generated operations.

### Reviewed slice: 2026-10-17 MySQL database and user mutations

The bounded expansion selects five functions already needed by the populated
fixture: `Mysql/create_database`, `create_user`, `set_privileges_on_database`,
`delete_user` and `delete_database`. Each pinned `#GET` variant is marked mutating;
the generated methods use form POST under the existing request policy. No JSON
or multipart body support is required. Generation produces 79 operations in the
same 13 groups without changing the runtime or generator.

The source declares nullable object results without properties or an
`additionalProperties` restriction. These are opaque object boundaries, not
empty-object contracts: preserve their fields without assigning undocumented
meanings. Existing empty-map compatibility also accepts an empty JSON list;
nonempty lists, scalars and an omitted `data` member remain mismatches. No new
omitted-payload correction or return-type narrowing is introduced. Optional
`prefix-size` and `privileges` remain omitted unless supplied. Privilege assignment
replaces existing privileges; the fixture explicitly sends `SELECT`.

The fixture reuses its four account-prefixed resources. Linked database/user
creation and deletion use direct UAPI, unlinked creation and deletion use the
WHM bridge, and the linked pair receives one deliberately planned `SELECT`
assignment through each route. The second assignment establishes execution and
response coverage, not an independently demonstrated privilege change. Ten
mutation responses are captured at their original execution, including deletion
during cleanup. A failed response validation is recorded before reconciliation;
no mutation is retried to obtain a successful response or complete evidence.
Baseline inventory observations and their Quota acknowledgments stay separate.

The test-mechanics pilot shares client construction, envelope construction and
low-level request assertions between the existing MySQL inventory suite and the
new mutation suite. Endpoint arguments, expected URIs/bodies and payloads remain
literal, independently authored expectations. The original inventory suite keeps
196 cases and 1,752 assertions. Its file shrinks from 373 to 334 lines; the shared
helper costs 67 lines. For comparison, a self-contained copy of the new mutation
suite with the original mechanics would require 294 lines, versus 258 using the
helper. This constructed duplication comparison totals 667 versus 659 lines,
an eight-line saving; it is not a claim that the new mutation suite existed before
the batch. Review now includes one helper and two suites, with no measured
elapsed review-time saving. Other module helpers are unchanged.

### Quota disposition: 2026-10-17 expected undocumented fields

A separately authorized read-only inspection of `Quota/get_quota_info` on `npr`
identified `backup_warning_threshold_pct` and `under_backup_threshold`, both
integers through direct UAPI and the WHM bridge on `11.138.0.15`. No field values
were printed or saved. The owner accepts these as expected extras with unknown
semantics and permits the correction cycle to continue. Their requiredness,
allowed values, meaning and version coverage remain unresolved under
`whm-api-client-8x0.4.3.1`.

Typed Quota returns remain documented-only. Evidence still masks unreviewed keys,
and the existing gate compares exact structural observations rather than these
names. This inspection does not establish the names or counts in historical
masked artifacts, including the finite-account scenario. No historical evidence,
acknowledgment or finite-scenario exit policy is changed.

### Runtime observations: 2026-10-17 generated MySQL mutations

The [mutation workflow artifact](evidence/2026-10-17-mysql-mutations.json), SHA-256
`7d9eb21a6d2abf085784e468016d6669f1c5ff54ab307f2e1ee28f15fb32c97c`,
records one opted-in fixture invocation at committed code
`487c30ec726b5548fc4a9b210427e1bd5e6c9d36` on cPanel `11.138.0.15`.
All ten mutation observations passed HTTP 200, API outcome, generated payload
validation and probe execution. Each of the five functions was observed through
direct UAPI and the WHM bridge using actual POST with pinned source variant
`#GET`. Every mutation returned explicit `null`; opaque object contents and
empty-map compatibility remain offline-tested only. No compatibility correction
was needed, and the separate mutation assessment has zero discrepancies.

All 43 baseline observations also passed, with **2 acknowledged, 0 unexpected,
0 stale** Quota findings. Both MySQL inventories again observed the populated
linked and unlinked records through both routes. The fixture confirmed all four
creations and independently confirmed all four resources absent during cleanup.
Setup, capture, inventory expectations and cleanup passed, and the command exited
**0**. No mutation was retried. Evidence contains structural paths/types and fixed
lifecycle labels, not resource names, credentials or response values other than
the reviewed server version.

This establishes the stated account, version and routes. Explicit `prefixSize`,
omitted/empty privilege arguments, other privilege sets, database contents,
positive disk usage, SQL permission enforcement, other server versions and
remote/disabled-role behavior remain unobserved. The second `SELECT` assignment
was deliberately idempotent, so its success does not independently demonstrate a
privilege change. Real HostingPal consumer validation remains deferred.

Before external review, the code commit touched 18 manually authored overlay,
implementation and test files (1,558 added / 196 removed lines), three generated
files (254 / 2), and four documentation files (124 / 10). The saved artifact and
this runtime addendum are separate. Unlike the earlier finite-Quota fixture's
12 manual tool/test/runner-documentation files and 2,400 / 12 lines, this batch
reuses a lifecycle and delivers five generated operations; these counts describe
different scopes and do not establish a general per-operation cost. The offline
suite gained 295 cases, reaching **3,751 tests / 28,216 assertions**. Required
Composer validation, formatting, static analysis, tests and offline API-map
checks passed. Two cross-component internal reviews and the primary review
covered capture/cleanup/guards and evidence assessment; source inspection
corrected the initial empty-object assumption, and review added the existing
empty-map compatibility case. Elapsed review time was not measured.

### MySQL provisioning reads: 2026-10-17

The owner approved the next bounded batch under `whm-api-client-8x0.4.6`:
`get_privileges_on_database`, `get_restrictions` and `get_server_information`.
All three pinned variants are read-only GETs. The first two are available from
cPanel 11.44; server information starts at cPanel 54. They retain the documented
MySQL-role/remote-configuration caveat. Privileges remain a string list, including
an empty list when none are assigned. Restrictions preserve optional integer
length fields and an optional nullable prefix. Server information preserves
optional string host/version fields and integer `is_remote` (`0|1`). No runtime,
generator, request encoding or compatibility policy changed.

The [provisioning artifact](evidence/2026-10-17-mysql-provisioning.json), SHA-256
`fb3581fb858647356d7d815e7560dd4b307a0c2558e373102c9ec8c3e1d28155`,
records one opted-in fixture run at code
`b1f83d003f444fac05b9b7bdc3ed6ac3e1ea6c4b` on cPanel `11.138.0.15`.
Its 49 read observations comprise the 47-probe catalog across 27 operations and
two fixture-bound privilege reads. All six new operation/route observations
passed HTTP 200, API outcome, generated validation and fixture expectations,
with no new discrepancies or wire corrections. Both restrictions responses
contained integer length fields and a string prefix. Both server-information
responses contained string host/version fields and an integer remote flag.
The privilege responses were nonempty string lists; the v2 fixture separately
required exactly the assigned `SELECT` value through each route. Only structural
types are saved, not those payload values or resource identities.

The fixture reused the same two databases and two users, with the same ten
generated mutation POST observations; all ten passed with explicit null payloads
and no mutation discrepancies. All four creations and all four cleanup absences
were confirmed. The read assessment retained **2 acknowledged, 0 unexpected,
0 stale** Quota findings, and the command exited **0**. Source/map/generator/
overlay/effective-contract hashes were rechecked, both assessments reproduced,
and all 1,270 structural paths were checked against static contract/envelope keys
and wildcard masking. No operation was replayed to recover evidence.

This verifies API-reported privilege assignment, not SQL permission enforcement.
Empty live privilege lists, other grants, disabled or omitted prefixes,
remote/disabled-role configurations, other cPanel versions and positive database
disk usage remain unobserved. General minimum/format enforcement remains outside
this batch under the existing runtime policy. HostingPal integration stays deferred.

The code commit changed 18 files: 11 manually authored overlay/implementation/test
files (475 added / 24 removed lines), three generated files (143 / 2), and four
documentation files (39 / 13). Manual implementation/overlay work accounts for
127 / 10 lines; seven test files account for 348 / 14. The existing test helper
and probe-pair helper were reused unchanged; no new shared helper, guard class,
assessment class or fixture resource was added. These three reads differ in scope
from PR25's five mutations, so these figures are not a per-operation speedup.
The suite gained 264 cases and reached **4,015 tests / 31,055 assertions**. Required
Composer and offline API-map gates passed. A separate local review of the code
commit found no issues and ran 400 focused tests / 5,253 assertions. External
review rounds and findings are tracked in Beads; human review time is not instrumented.

## Pinned-contract audit: 2026-10-30 complete Mysql section

The owner requested a larger dedicated PR, preferably covering an entire API
section, and delegated scope selection and development-server testing while away.
The selected batch completes all 25 functions in the pinned UAPI `Mysql` section:
15 new methods raise generated coverage from 82 to 97 operations across the same
13 groups. The source snapshot and map checksum remain unchanged. The complete
API still has 1,213 nondeprecated functions without generated methods.

The additions are `addHost()`, `addHostNote()`, `checkDatabase()`, `deleteHost()`,
`dumpDatabaseSchema()`, `getHostNotes()`, `listRoutines()`, `locateServer()`,
`renameDatabase()`, `renameUser()`, `repairDatabase()`,
`revokeAccessToDatabase()`, `setPassword()`, `setupDbAndUser()` and
`updatePrivileges()`. Every pinned mutation, including `check_database`, uses
form POST; the four new reads use GET. This section needs neither JSON nor
multipart execution support. Existing group generation and runtime validation
remain the contract path.

An independent audit compared all 15 additions with the decompressed pinned
cPanel OpenAPI source (`172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc`).
It confirmed parameter mappings and response shapes and found documentation
caveats dropped by the generator's prose extraction. Overlay notes now preserve
all version floors and role requirements, database-rename prefix/underscore limits
and restoration warnings, and database-username limits. The `add_host_note` role
condition is stricter than the other operations. `setup_db_and_user` starts at
cPanel 11.116. Parameter ordering puts the original name before the new name and
the database user before the password.

Mutation result contracts stay distinct: most accept nullable opaque objects;
`set_password` returns an object with optional `failures`, and
`setup_db_and_user` returns optional credential fields and a string port.
Per-host password failures remain payload data; the public method does not
invent a global envelope rule. Diagnostic table messages and rename warnings
also require caller inspection. Raw schema text and dynamically keyed host notes
are preserved as payload data.

The new credential-returning operation exposed an existing trace-privacy gap:
a successful response with a password and malformed `port` could retain that
password inside `OperationRunner::result()`'s response argument in `getTrace()`
when `zend.exception_ignore_args=0`. Independent direct and bridged regressions
failed before the response was marked `SensitiveParameter` and passed afterward.
The intentionally explicit, unvalidated `PayloadMismatchException::$payload`
remains available; the change protects implicit trace arguments. Both routes
still make exactly one request.

Independent literal endpoint cases cover all 15 additions on direct wrapped,
direct bare and bridged responses, including exact URI/body/auth, omission,
explicit empty values, null versus absent data, nested wrong types, integer flags,
opaque objects, API failures and metadata replacement. Password-input failures
join the existing sensitive-trace matrix. The generator/specs do not supply the
expected endpoint results. Generated coverage, offline validation and actual live
observations are separate claims.

The standalone section recipe is scoped to 21 operations on each UAPI route.
Four generated/offline-tested operations remain outside its live recipe:
remote-host add/note/delete lack an authoritative absence check in host notes,
and random setup needs safe recovery when generated identities are not returned.
Follow-up `whm-api-client-8x0.2.9` tracks that evidence work. Empty tables/routines,
API-reported grants and password results do not prove SQL enforcement, SQL login,
repair of damaged tables or stored-routine behavior. Consumer integration remains
deferred. The historical baseline and its Quota acknowledgment policy are unchanged.

### Runtime observations: 2026-10-30 Mysql section

One authorized run of the standalone section recipe at code revision
`b29cecebcd0b44a050017d574a02f3842f2ea430` completed with exit 0 on
`test.oblak.host`, cPanel `11.138.0.19`. The artifact is
[`2026-10-30-mysql-section.json`](evidence/2026-10-30-mysql-section.json), SHA-256
`df7ba98f8233e77583e5070113f95e1536928f917e9f4c748fd922c5fce875c2`.

All 57 observations passed: one WHM version call plus 28 lifecycle phases on each
UAPI route, covering 42 distinct MySQL operation/route pairs (21 functions).
The strict assessment found no missing, duplicate, out-of-order, unsuccessful,
provenance-mismatched or discrepant observations. It uses no acknowledgments.
The grant/read/revoke/read sequence observed exactly `SELECT` then an empty list
separately on both routes. Password results, linked inventories, database and
user rename readbacks, empty checks/repairs/routines and schema payload validation
passed. The fixture also invoked the account-wide privilege update on the
designated test account. Both routes' database and user resources are cleaned;
final inventories establish original and renamed names absent. No raw identities,
SQL, passwords or arbitrary upstream messages are saved.

Access is revoked before rename and both databases are empty. The rename readbacks
establish name changes and cleanup only; they do not observe grant or stored-code
recreation. This limitation also applies to the immutable artifact above. The
current recipe reports it explicitly; no operation was replayed to update the
historical evidence.

The artifact's 820 structural paths, all 57 effective-contract hashes, source/map/
generator/overlay provenance, version binding and complete assessment were audited
without replaying requests. No new compatibility correction was required. These
results cover the exact operations, routes, arguments and server version exercised;
the four excluded live operations and other stated limitations remain explicit.

Before capture, `composer validate`, `composer check` (4,493 tests / 33,833
assertions), PHPCS, maximum-level PHPStan and `composer api-map:check` passed.
A fresh whole-branch reviewer independently inspected the exact code revision,
including all 15 pinned contracts, guards, cleanup, sanitization, provenance and
credential traces; it reported no actionable findings and independently passed
521 focused tests / 2,985 assertions. This is an independent local review, not a
separate-account GitHub approval. Actual external PR approval remains a merge gate.

The code commit changed 22 files: 19 manual files with 2,497 additions / 17
removals, and three generated files with 718 additions / two removals. That includes
overlay, runtime trace protection, independent tests, standalone fixture tooling
and documentation. Evidence and this runtime addendum are separate. Two review
passes were used (contract audit, then whole-branch review); the first led to
source-caveat documentation and the returned-secret regression, and the second
found no remaining issue. No measured review-time saving is claimed.


### Complete FTP section: 2026-10-30

The owner requested another coherent API slice in an independent PR while
keeping the complete MySQL PR separate. Bead `whm-api-client-8x0.4.9` covers all
20 pinned `Ftp` functions from the unchanged 2026-10-13 cPanel OpenAPI snapshot:
`add_ftp`, `allows_anonymous_ftp`, `allows_anonymous_ftp_incoming`, `delete_ftp`,
`ftp_exists`, `get_ftp_daemon_info`, `get_port`, `get_quota`, `get_welcome_message`,
`kill_session`, `list_ftp`, `list_ftp_with_disk`, `list_sessions`, `passwd`,
`server_name`, `set_anonymous_ftp`, `set_anonymous_ftp_incoming`, `set_homedir`,
`set_quota` and `set_welcome_message`.

All nine mutations execute as form POST; the eleven read operations use GET.
`passwd` is exposed as `changePassword(user, password, domain)`; `pass` and
`pass_hash` map to sensitive `password` and `passHash` arguments. Optional
parameters preserve omission, explicit false, empty strings and zero. The API
forbids sending both password forms, leaves domains defaulted to the cPanel
primary domain, and defaults new usernames to dot removal. These are upstream
semantics rather than new local cross-parameter validation.

The overlay preserves FTP-role caveats and version floors: cPanel 54 for
`get_ftp_daemon_info` and `set_homedir`, 11.42 for the other operations. Inventory
row fields remain optional; disk amounts remain decimal strings in MB, with
`_diskquota` using `0.00` for unlimited and `diskquota` using `unlimited`.
`get_quota` is a string payload. `htmldir` may be explicitly null. Sessions keep
their documented optional fields and integer PID. `ftp_exists` is a nullable
object success or an API error, not a boolean result. Opaque nullable object
results retain explicit null and unknown object fields but reject an absent
payload. `add_ftp` has a literal `[null]` enum in the pinned schema: its generated
nullable-object shape follows the established general-enum policy, not exact
JSON Schema validation or a claim of documented non-null data.

The account-changing defaults are rendered in generated PHPDoc: `destroy=true`
also deletes the FTP home directory, omitted `add_ftp` homedir defaults to a
directory with the same name as the FTP account, `kill_session` without a login
targets all account sessions, and omitted quota settings can remove the quota. Both anonymous
access switches apply to the selected cPanel account. No generated source was
edited by hand and no shared runtime or generator policy was changed.

The separate section verifier is scoped to `npr` / `npr.rs`, with both live
opt-ins and committed source inputs. It exercises a run-owned FTP user through
each UAPI route. Home-directory arguments `.` and `./` refer to the existing
cPanel home; cleanup always supplies `destroy=false`. The directory call can
establish execution/readback, not a change in filesystem isolation. Failed or
uncertain creation never authorizes deleting a possibly unrelated user.
Account-wide anonymous settings, the welcome-message setter and session killing
are deliberately unobserved live in this recipe (follow-up `8x0.2.10`). FTP authentication, active
session rows, populated disk usage and another daemon/version remain separate
from API execution evidence. Runtime results are recorded below only after an
explicit capture; default PHP and API-map gates remain offline.


The first committed-code capture at
`dd82a734095977fae6f8a2f3a0b1fabf226f1004` on cPanel `11.138.0.19`
failed strictly on the finite-quota read through both routes: the original
payload was a JSON integer, while the pinned contract declares a string.
Unlimited quotas were strings. The [original artifact](evidence/2026-10-30-ftp-section.json),
SHA-256 `55b0184b8581fb288c0bb0016b4ce36ffce119b262f831edabe777c285a65da8`,
preserves both type mismatches and the failed assessment. It contains 39
observations; disk inventory phases were not reached. Both temporary FTP users
were independently confirmed absent after cleanup. All 540 structural paths,
provenance and assessment were audited without recording payload values or replaying requests.

A correction limited to `Ftp/get_quota` retains the documented string form and
observed integer form as `int|string`. An evidence-bound root union expresses
this in the existing type grammar; no generic validator or generator behavior
changes. Floats, booleans, null, lists and objects remain invalid. Independently
authored endpoint tests reproduced the integer mismatch on all three envelope
forms; the full fixture regression reproduced it before the correction. Fixture
readback requires the assigned finite quota as exactly integer `17` or string
`17`, separately from the initial `unlimited` value. The initial discrepancy is
not waived or rewritten, and new live evidence must bind the corrected revision.


The second capture at `cd7bdb6a57bc6fe40dc0442d9c49c956cdfabe90` is saved as
[the corrected-contract artifact](evidence/2026-10-30-ftp-section-corrected.json),
SHA-256 `5bf7067e69efb996a31fb6c4b0a30f5a63402f4f8c5c88c81b11db73f4f4e94a`.
All **41 observations** passed HTTP, API outcome, generated payload validation and
fixture expectations on `11.138.0.19`: 16 Ftp functions through both routes plus
WHM version. Both temporary users were independently confirmed absent; neither
run removed directory contents. The original-response finite quotas were integers,
and the populated disk inventories contained the documented row fields.

The command still exited **1**: each disk-inventory observation has an
`unknown_field` discrepancy at `$[].*` with string type. Those are two route
observations, not evidence of exactly two field names or any particular names.
The strict assessment remains **failed** with `unacknowledged_discrepancy`.
No acknowledgment or undocumented typed field was added; `8x0.2.11` tracks the
contract investigation. The typed client discards unknown fields by its existing
policy, so API/payload success and evidence-assessment failure remain separate.
The artifact's 634 structural paths, provenance, effective hashes and assessment
were independently audited; no credentials, live usernames or response values
were persisted. No third mutation run was performed merely to clear this finding.

Both saved captures restrict inventory to `include_acct_types=sub`; the
corrected artifact observes only `sub` rows. Main, anonymous and log-access rows
are outside its captured evidence. The fixture report declares `sub_rows_only`
for this recipe; the immutable artifacts are unchanged.

The [external review of head `fc96ecd12506fb489b8bb98bda8b39b065350363`](https://github.com/hostingpal/whm-api-client/pull/28)
reported a separate read-only, unfiltered diagnostic through both UAPI routes.
`listFtp()` and `listFtpWithDisk()` passed typed validation for main, log-access
and anonymous rows; no sub rows remained. That diagnostic is not bound to an
evidence artifact. Its undocumented disk-inventory keys were `type` and `user`,
both strings; this does not identify the masked unknown keys in the artifact's
sub rows. The strict artifact assessment stays failed, without an acknowledgment
or new typed field. The reviewer considers this finding nonblocking for merge;
`8x0.2.11` remains open. No live rerun was performed for these review fixes.

Before the first capture, the code commit changed 22 files: 18 manual files
(2,623 additions / 4 removals) and four generated files (1,037 / 1). The evidence,
quota correction and this addendum are additional work. A fresh independent
whole-branch review of `dd82a734` found no actionable issue and independently ran
767 tests / 6,142 assertions. The evidence-driven quota correction then passed
its observed RED-to-GREEN endpoint and fixture regressions, full **4,745 tests /
36,992 assertions**, formatting, maximum-level PHPStan and offline API-map checks.
No shared runtime or generator change was needed. External current-head review,
the unknown-field decision, and the four deliberately unobserved live functions
remain distinct from these implementation and execution results.


### Complete PostgreSQL section: 2026-10-30

The owner requested the next complete module under `whm-api-client-8x0.4.10`.
This slice adds all 14 pinned `Postgresql` functions. Integrating the approved
MySQL and FTP sections brings the combined branch to 131 generated operations
across 15 groups; the module contracts and immutable artifacts remain separate.
`get_restrictions`, `list_databases` and `list_users` are GETs. The eleven
mutations declare `x-readonly=false` and `x-rollback=none`; the client sends them
as form POST. Passwords on creation, rename and password change are sensitive.

The pinned source declares `create_user` data as a nullable integer, without
assigning it a meaning. Other mutations declare nullable objects without field
schemas. Explicit null and opaque fields are retained; absent data is not
accepted as null. Existing empty-map compatibility remains unchanged.
`list_users` returns a string list. Database rows have optional `database`,
integer byte `disk_usage` and `users` string-list fields. Restriction fields
are optional; `prefix` is a string and is not documented as nullable.
No MySQL response contract is reused by inference.

The overlay exposes `oldName`/`newName` for renames and places `user` before
`password` in `setPassword()`. It records the PostgreSQL-role caveat, version
requirements, prefix behavior, no-rollback policy, account-wide grant
synchronization and the password reset required after `rename_user_no_password`.
The pinned documentation recommends `rename_user`, which accepts a password.
Inventory, `delete_user` and `update_privileges` require cPanel 84+;
`delete_database` requires 54+, and other functions require 11.42+.
Source snapshots, shared validation policies and generator behavior are unchanged.
A malformed PostgreSQL `create_user` response echoing its input password exposed
the `ApiResponse` argument of `OperationRunner::result()` in exception traces.
A regression reproduced it on both routes before adding `SensitiveParameter`
to that response argument. Envelope failures also retained the raw PSR response
in `EnvelopeReader::read()` traces; that response argument is now sensitive too.
Regressions check the presence of response objects directly, including
resource-backed streams, for malformed status and failed inner/outer outcomes.
Payload access and validation behavior are unchanged.


Independent whole-branch review at `94e62b90e3f5e2b790de563a6990dd23926a4973`
found no blocking correctness or safety issue and passed 773 focused tests /
8,841 assertions with exception arguments enabled. Its minor documentation
finding restored the pinned account-prefix requirement for `createUser()` through
an overlay note and regeneration. Failed or short temporary-file writes remain
a resilience limitation; completed-report publication failures preserve a protected
recovery file. No live operation is replayed to recover evidence.


The [PostgreSQL preflight artifact](evidence/2026-10-30-postgresql-section.json),
SHA-256 `a3fbf7f299cf2a1811ed655524c2eadd076b3a217508997a07eb44d32d28211c`,
was captured once at revision
`6539db7103a198a609a0b24aa4c09514b5464f36` on cPanel `11.138.0.19`.
It records seven GET observations: WHM version passed, while PostgreSQL
`list_databases`, `list_users` and `get_restrictions` each returned HTTP 200
with an API failure on both direct and bridged UAPI. The recipe returned exit 1
with `preflight_failure` and all four resource states `not_started`. No mutation
was attempted and no resource was created; there is no cleanup claim.

The strict assessment remains **failed** because the reads failed and the
planned full-lifecycle sequence is incomplete. Payload validation and fixture expectations for the failed
reads were not checked. Zero recorded discrepancies does not establish successful
PostgreSQL behavior. At that capture, successful payload contracts and mutation
behavior remained offline-tested only. The failed artifact is preserved without
a waiver or replay to recover evidence; the later enabled-service run below
provides separate successful observations for `whm-api-client-8x0.2.14`.

The artifact's assessment, operation/route/method identities, pinned source hashes,
effective contracts, map/generator/overlay hashes and revision were independently
recomputed against the committed inputs. All 88 structural path entries contain
only static envelope/contract paths and type labels; no payload values, passwords,
resource names or upstream error text were persisted. The complete code passed
`composer validate`, `composer check` (**4,732 tests / 39,581 assertions**) and
`composer api-map:check`. The optional failed-temporary-write resilience follow-up
is `whm-api-client-8x0.2.13`; atomic completed-report publication failure recovery
is tested in this slice.


### Runtime observations: 2026-10-30 enabled PostgreSQL section

Before this capture, the reviewer performed an owner-requested server change:
PostgreSQL 13.23 was installed on CloudLinux 9.8 / cPanel `11.138.0.19`, and the
Postgres role and PostgreSQL service were enabled, running and monitored. The
reviewer confirmed the disabled role caused the earlier preflight failures.
That installation is background to this account-scoped recipe; it was not a
client change or an action performed by the capture.

Approved MySQL PR27 and FTP PR28 were merged into this branch through master,
without rebasing. Regeneration produces **131 operations across 15 groups**.
The integrated code at `61a9f3eb6dbc480625861bb41ef629a36d05129a` passed
`composer validate`, `composer check` (**5,961 tests / 48,438 assertions**) and
`composer api-map:check` before being committed/pushed and captured once.
The combined trace suite preserves the MySQL, FTP and PostgreSQL cases, including
all five reviewed outcome-echo paths; `EnvelopeReader::outcome()` marks both raw
outcome and metadata sensitive. Its explicit exception metadata and mismatch
payload APIs remain unchanged. The body-aware PSR search and unmarked-body
control are retained once, and all **162 tests / 878 assertions** pass.

The [enabled-service PostgreSQL artifact](evidence/2026-10-30-postgresql-section-enabled.json),
SHA-256 `a1de9a3458f7b721f2263dbd1f88ce619df61eca42c3d820d8b6844f16b4de45`,
contains **57 passing observations: 33 GET and 24 POST** on `11.138.0.19`.
All fourteen functions execute successfully on direct and WHM-bridged UAPI,
with HTTP 200, successful API/payload outcomes, independently checked fixture
expectations, zero discrepancies and a passing strict assessment. Both databases
and both users are `cleaned`, with original, renamed and final identities
confirmed absent by the final same-run inventories. The failed preflight artifact
and every historical MySQL/FTP artifact remain byte-identical.

The run observes empty and populated database/user inventories, integer byte
`disk_usage`, empty and populated linked-user lists, integer name-length limits
and a string prefix. Grant/revoke readbacks, database/user renames, password
updates and account-wide privilege synchronization pass. Every mutation returns
explicit null, including `create_user`; non-null integer/opaque mutation
payloads and unobserved optional-field variants remain offline-tested only.
The empty-inventory preflight and the inventory before `update_privileges()`
bind that account-wide operation to the run's owned resources. API execution and
readback establish neither SQL privilege enforcement nor SQL login, and no table
is created to establish new-table grant synchronization.

The audit independently recomputes generated-spec method/contract hashes and
source/map/generator/overlay/revision provenance, then rechecks all phase identities,
order and the strict assessment without changing original observations. All
820 structural path/type entries contain reviewed static paths or masked opaque
metadata paths. Configured credentials, response-derived account/domain/host
values, resource identities, password material and upstream error text are absent.
The fixed public recipe identifier retains its documented account label. The
new artifact was written with mode 0600, and no capture temporary files remain.
No operation was replayed or retried. Live follow-up `whm-api-client-8x0.2.14`
is complete; optional short/failed temporary-write recovery remains
`whm-api-client-8x0.2.13`.


## 2026-10-30 complete DomainInfo, Features and LangPHP contracts

This slice adds 13 generated methods: both remaining DomainInfo operations, four
Features operations and seven LangPHP operations. The three modules now contain
5, 5 and 11 methods respectively. With the reviewed MySQL, FTP and PostgreSQL
sections integrated, coverage is 144 operations across 15 groups.
Pinned snapshots are unchanged. Overlay evidence identifies the domain list/hash
container discrepancies, quoted domain flags, the PHP vhost list/single-record
alternatives and malformed phpversion_source schema. The INI content setter's
host/home conflict remains visible; no unsupported enum or empty-string flag
coercion is inferred.

Features/has_feature's pinned examples use UAPI status 0 for absent/disabled access,
so that operation declares unchecked UAPI outcome semantics and retains its
nullable object payload. The WHM bridge still checks its outer result. Independent
fixtures cover literal requests and populated, omitted, null, empty, false and zero
responses through wrapped direct, bare direct and bridged envelopes. Response-trace
regressions demonstrate that both raw HTTP responses and decoded payloads must be
sensitive even when a read returns a secret. The generated INI setter parameters
are also sensitive. The first stack commit adds evidence-required parameter
requiredness corrections for prose-documented alternatives used by subsequent
Email and SSL slices.

The reviewed read-only catalog adds 20 probes across the ten new read operations,
binding npr/npr.rs, domain hash output, home INI scope and fixed feature searches.
It now contains 67 probes across 37 operations. This is a runnable recipe count;
live outcomes are recorded separately. The three LangPHP mutations are offline
contract coverage until a dedicated reversible configuration fixture is observed.


### Initial runtime observations and compatibility corrections

The [initial 67-probe artifact](evidence/2026-10-30-domain-features-php.json)
records c79ed1d on cPanel 11.138.0.19, SHA-256
`d75a3575bf2ceec2e38c620eedaaf98b54253a42cb0f506b4cdbd74bb6749a0f`.
It contains 59 passed probes and eight payload failures, with all original structural
observations retained. No mutation ran. The CLI subsequently failed while printing
an absent static label, after the evidence file had already been saved; label
coverage now has an independent regression test.

Observed single-domain customlog/scriptalias lists extend their pinned object
forms without inventing item fields. Documented flags admit bounded quoted 0/1,
and integer ports admit lossless numeric strings. Feature metadata also returned
quoted flags; has_feature returned an integer rather than its documented nullable
object. That integer is preserved without guessing its meaning or turning it into
a boolean. The root PHP-version union failure did not identify its offending field:
its original fingerprint correctly masks all keys, so no correction was inferred.

A failed root union can now additionally record analyses of its checked alternatives,
identified by index and contract hash, from the same original response. These
supplemental structural diagnostics retain unknown-key masking and never alter the
primary fingerprint, runtime validation or discrepancy gate. They do not recover or
rewrite the first artifact. Unknown domain and INI fields remain unresolved under
8x0.2.17, as do the three PHP mutations' live behavior.


The [compatibility run](evidence/2026-10-30-domain-features-php-compatibility.json)
at a07f4f7 retained 67 GET observations on 11.138.0.19: 65 passed and the two PHP
vhost probes still failed. Its SHA-256 is
`ae83e8afa8f3296342c76c225d08b09d4e417e35fc2569f975a8f094b411eced`.
The new candidate analysis identifies only `$[].phpversion_source` as the list
alternative's type mismatch, with an observed object. A narrow correction now
preserves both the pinned list and that object form using the same documented
optional fields. No unknown key name or value is inferred. The strict evidence
assessment remains failed for original mismatches and unacknowledged extras;
this historical artifact is not rewritten after correcting the contract.


### Corrected read-only verification

The [corrected run](evidence/2026-10-30-domain-features-php-corrected.json) at
7b172f3 on 11.138.0.19 records all 67 API/payload probes passing, including all 20
new route-specific reads. Artifact SHA-256:
`50a569139cc932442be93960c322d1afb408164537ef9668a98402651156c2e4`.
The strict discrepancy assessment still fails: eight DomainInfo/INI observations
contain unknown fields, and two Quota observations no longer match their historical
11.138.0.15 acknowledgments. Both old Quota acknowledgments remain stale; no new
acknowledgment was introduced. The single-domain customlog list also exposes a
masked extra field. These successful payload calls are not discrepancy-free
contract evidence. All three artifacts and their original outcomes remain intact.

The first PR's final offline gates pass 4,310 tests and 33,655 assertions, plus
maximum PHPStan, PHPCS and offline API-map/source/generation verification. The
three PHP setters remain offline-only; no mutation ran in any of these captures.

### Review corrections and integrated read-only capture

The pinned `domains_data` hash and list examples include empty-string
`userdirprotect`. Independent copies now exercise both full examples through all
three envelope forms. The effective domain contracts preserve that exact empty
string, retain integer and quoted 0/1 flags, and align shared fields across both
operations and all record kinds. Empty strings carry no invented disabled meaning;
whitespace and other nonflag strings remain invalid. This is documentation-bound
compatibility, not a newly observed value on the test account.

Typed unchecked UAPI results now require integer status 0 or 1 before void, optional
or payload processing. Invalid status raises `InvalidResponseException` with the
captured current metadata; generic `call()` semantics and outer WHM checks remain
unchanged. `hasFeature()` callers must inspect errors alongside status: nonempty
errors describes a failed call, not a disabled feature. INI content `type` uses
plain string PHPDoc while the host/home discrepancy remains unresolved; content is
form-encoded once by the client and the server write/read round trip is unverified.

The narrow `countPops()` correction from `8533e95` is included here without the
expanded Email group. It preserves integer results and normalizes canonical
PHP-integer-bounded strings. The referenced immutable
[Email preflight artifact](evidence/2026-10-30-email-section-quota-corrected.json)
has SHA-256 `c3b795074558ee25cbeb001d09e002efe55942a32abfaa49bc8de03f97df6f31`;
both count routes failed their prior integer contract, all 17 requests were GET
and both resources remained `not_started`. No count value, main-account inclusion
or cause is inferred.

The three existing DomainInfo/Features/LangPHP artifacts remain unchanged. Their
contract hashes describe their original revisions. The reviewed MySQL, FTP and
PostgreSQL sections were integrated with a normal merge of `master`, preserving
all prior source/specs, trace safeguards and immutable evidence. Regeneration
produces **144 operations across 15 groups**.

At committed integrated code `27abfe6de3626e321a2a806d126d9b7f2f20fcc1`, one
[reviewed read-only capture](evidence/2026-10-30-domain-features-php-reviewed.json)
ran the exact 67-probe catalog once on `11.138.0.19`. Artifact SHA-256:
`f9ba559c0496b88c0c98082435f3e76235e2b8459ebe01d229127cfa2d3e50a8`.
All 67 requests were GET and HTTP 200; API outcome, payload validation and probe
outcome pass across 37 operations (7 WHM, 30 direct UAPI and 30 bridged UAPI
observations). Both count_pops payloads were numeric strings and normalize under
the reviewed correction. No PHP mutation or other write was executed.

The command correctly exits 1 because strict discrepancy review remains failed:
zero acknowledged, ten unexpected observations and two stale historical Quota
acknowledgments. Those observations contain twelve unknown-field entries: two
Quota entries, two domains_data entries, four single_domain_data entries, two
basic-directive entries and two INI-path entries. No discrepancy was waived or
removed and no operation was replayed to clear findings. The DomainInfo/INI
unknown fields and PHP mutation behavior remain tracked in `8x0.2.17`; the
existing Quota follow-up and exact acknowledgment policy remain unchanged.

An independent audit checked all 1,794 structural path/type entries, exact catalog
identities/order/routes/methods, all 67 effective-contract hashes and pinned
source/map/generator/overlay provenance. Credentials and designated account,
domain, host and IP values are absent. Every historical evidence JSON from both
parents remains byte-identical. The test account omitted userdirprotect on both
operations/routes, so the empty-string compatibility is documentation and offline
fixture coverage, not a newly observed live value. `has_feature` payloads were integers;
their meaning and the specific feature-state value are not persisted. Three PHP
setters, the INI write/read round trip and alternate server configurations remain
unobserved.

Before capture, Composer validation and the complete PHP gate passed **6,390 tests
and 51,705 assertions**, including PHPCS and maximum PHPStan. Offline API-map,
schema-copy/currentness checks, complete Go tests and Go vet also passed.
External current-head PR review remains the merge gate.


## 2026-10-30 complete Email contracts

The second stacked slice adds 92 generated operations, completing all 96 pinned
Email functions and bringing this branch to 187 typed operations in 13 groups.
Independent literal fixtures cover wrapped direct, bare direct and WHM-bridged
requests, optional arguments and payload omission/null/type boundaries. All
mutations and secret-bearing parameters travel in form POST bodies. The client
performs one request immediately and does not retry or roll back mutations.

Source-bound corrections permit add_pop password-hash alternatives, preserve
floating-point MB in list_pops_with_disk, and model manual MX results as dynamic
maps rather than a property literally named additionalProperties. For trace_delivery,
merging the finite formal destination fields avoids first-branch optional-object
validation dropping later documented fields. The recursive routed example remains
an explicit limitation. terminate_mailbox_sessions documents a WHM-style result
on its UAPI page; generated UAPI routing is preserved without inventing a second
route or claiming live envelope compatibility.

Email filter families require new one-based suffix encoding. Reviewed names ending
in * expand to 1, 2, and so on; they do not use the existing WHM hyphen convention.
The scalar variant permits integer values only for the documented numeric filter
comparisons. Independent wire tests reject malformed lists and collisions after
metadata reset and verify secret redaction. Shared generator and runtime checks
reject unsupported patterns before any network request.

The standalone mailbox fixture is a separate evidence scope: exact npr/npr.rs,
fresh owned addresses, welcome messages disabled, no delivery or external recipients,
strict cleanup, and original-response structural capture. Its actual outcomes and
unobserved operations are recorded separately from generated coverage.

### Email preflight and quota compatibility observations

The [initial Email artifact](evidence/2026-10-30-email-section.json), captured at
3ca852b on 11.138.0.19, has SHA-256
`ab1c60b5d7a5c1d330fe152ee36a5f26995ebfb080d939d118986093a8f467dc`.
It records 17 preflight GETs: WHM version and seven Email functions passed on both
routes, while list_mxs rejected quoted integer priority fields. No mailbox was
created; both resources remain not_started. A narrow correction accepts canonical
integer strings within PHP bounds at entries[].priority only.

The [next Email capture](evidence/2026-10-30-email-section-corrected.json), at
57c425d on the same server version, has SHA-256
`7afe0accaeda48e2c7f26a491c2937f0b0417981d8674f3cb7ab0ce90226f636`.
Its 29 original responses establish successful preflight, mailbox creation and
exact cleanup through both routes. Each lifecycle stopped at get_pop_quota because
its required integer arrived as a numeric string. Both owned mailboxes were
confirmed absent after cleanup. The structural observation establishes the wire
type, not the expected quota value. Failed phase and discrepancy assessments remain
unchanged; no artifact is rewritten after a contract correction.

The [quota-corrected preflight](evidence/2026-10-30-email-section-quota-corrected.json)
at 7300ef5 has SHA-256
`c3b795074558ee25cbeb001d09e002efe55942a32abfaa49bc8de03f97df6f31`.
Its 17 GETs stopped at count_pops quoted integers on both routes; no mutation ran.
That existing method now accepts canonical integer strings within PHP bounds,
without inferring whether the main account is counted.

The [count-corrected capture](evidence/2026-10-30-email-section-count-corrected.json)
at 8533e95 has SHA-256
`c10599b638300486019b137b20e40ae32bacef872d1d4044291d5feb76bc08a0`.
Its 29 responses again stop at numeric-string get_pop_quota payloads, despite the
canonical-integer correction. Both created mailboxes were confirmed cleaned up.
Structural numeric-string evidence alone cannot establish a lossless conversion;
a separate same-response diagnostic now records fixed format categories only.
It does not change the typed contract, original failure or strict assessment.


The [quota-format diagnostic](evidence/2026-10-30-email-section-quota-format.json)
at fa887d3 on 11.138.0.19 has SHA-256
`1e30c8b3f1472213d325670909acc666a8d7e8eee52f12a708be3d15ddd58f7f`.
Both original get_pop_quota responses contain integral-fixed-point strings: the
fractional digits are all zero. No numeric value or length is retained. Typed
validation still fails in this artifact; both created mailboxes were confirmed
absent after cleanup. Only this evidenced operation receives lossless acceptance
of zero-fraction fixed-point strings within PHP integer bounds. Fractional values,
exponents, noncanonical integer parts and overflow remain rejected.


The [final owned Email capture](evidence/2026-10-30-email-section-final.json) at
fd552b2 on 11.138.0.19 has SHA-256
`d1a8a5e97815a3eb7fe3524e7cea5227754320bc388293c9735f8a6df818cc9d`.
All 45 planned original responses pass API, typed-payload, fixture-expectation and
strict discrepancy checks, with zero discrepancies and no failure labels. Sixteen
Email functions were exercised through direct and bridged UAPI, plus WHM version.
Both fresh mailboxes were confirmed absent after creation, password verification,
quota update/readback, login suspension/unsuspension and cleanup. No welcome or
external delivery message was sent. This verifies API behavior on the recorded
revision/version, not IMAP/SMTP access or every generated Email method. Follow-up
8x0.2.18 retains unobserved functions and pinned-source contract gaps.


### Email review corrections and master integration: 2026-10-30

The [review of e461f175](https://github.com/hostingpal/whm-api-client/pull/31#pullrequestreview-5715145667)
independently observed exact `unlimited` MB quota results on both UAPI routes for
an unlimited mailbox, with integer `0` in byte units. This is reviewer evidence,
not an artifact-bound structural capture. `getPopQuota()` now has the precise
`int|'unlimited'` contract, retaining both existing lossless integer conversions.
An explicit string-only `literal` constraint represents the one accepted string;
it is never inferred from enums or applied to other integer results. Independent
fixtures reject other strings, null, absent payloads, fractions and overflow.

The same review observed list destination forms dropping the pinned `message`
field. Object and list destinations now retain identical pinned leaf fields,
including message, aliasfile, command, mailbox, mx, result and type. Independent
fixtures copy the pinned routed example and leaf examples without reading the
generated spec. Deeper recursive destinations remain outside this leaf contract.
The reviewer also observed normal status-1 UAPI envelopes for
`terminateMailboxSessions()` on both routes; that reviewer-only observation
resolves the runtime doubt without a runtime change or an artifact-bound claim.
The pinned metadata-only response remains a source discrepancy under `8x0.2.18`.

A normal master merge preserves the approved MySQL, FTP, PostgreSQL and
DomainInfo/Features/LangPHP changes, countPops compatibility, earlier immutable
artifacts and all trace safeguards. Generated coverage is **236 operations across
15 groups**. The v2 Email recipe keeps the same two owned mailboxes and finite
32/64 MB checks, then sets quota 0 and asserts exact `unlimited` MB and integer
zero bytes. Four GET-only traces use only run-bound local recipients. No delivery
operation is invoked. The revised capture is recorded separately below after
committed-code verification; no older capture is modified or waived.


### Revised Email capture after review

The single revised capture ran at committed integrated code
`205079c790ad2ed290c00d1656d4cffbe488d21c` on cPanel `11.138.0.19`, saved as
[2026-10-30-email-section-reviewed.json](evidence/2026-10-30-email-section-reviewed.json),
SHA-256 `13bc03e54292f382e3ec9062b8a7c148735aaa278e4aeecba1262f638363791b`.
It contains all **55 original responses**: 39 GET and 16 POST, covering 17 Email
functions through both UAPI routes plus WHM version. All 55 HTTP 200, API outcome,
typed-payload and probe checks pass, with **zero discrepancies**. There are
**54 passed, one failed and zero unchecked fixture expectations**. Both owned
mailboxes were confirmed cleaned up; each final inventory is an empty list.

All new cases pass on both routes. The `unlimited_quota` phase asserts the exact
literal `unlimited` in MB units, and `unlimited_bytes` asserts integer `0`; these
committed expectations provide value checks beyond the artifact's structural
string/int types. Each run-bound missing-recipient trace observes a list item
with string `message` and `type`, retained by the corrected typed contract. The
owned-recipient traces have empty destination lists and establish no item fields.
No message was sent and no recursive tree or actual delivery is established.

The command exits **1** and the strict assessment remains **failed**, solely for
`unsuccessful_phase`: direct UAPI `resumed_mailboxes` fails the exact predicate
`isset($rows[$address]) && ($rows[$address]['suspended_login'] ?? null) === 0`
after an API-successful `unsuspend_login`. The inventory is a nonempty list with
string login/email and integer suspension fields. Its structural payload and
LIST_POPS contract hash are identical to the earlier passing 45-response capture.
No missing-field/type discrepancy is observed; masked evidence cannot distinguish
a missing target identity from an incorrect integer flag value or establish a
cause. This artifact alone does not prove the mailbox remained suspended or imply
timing, caching or server behavior. Direct cleanup then succeeds, followed by the complete
bridged lifecycle and cleanup, so all 55 planned phases are present.

This semantic failure remains unwaived and tracked in **`8x0.2.18.1`**. The later
reviewer investigation below supplies a nonblocking decision without changing the
artifact or assessment. This fixture was not repeated to clear the failure. Independent
audit verifies all 914 structural path/type entries, exact phase order and methods,
55 effective-contract hashes, revision/source/map/generator/overlay provenance,
strict assessment and cleanup states. Credentials, account/domain/host and run
identity values are absent. All earlier immutable evidence files are unchanged.
Pre-capture offline gates passed 7,402 tests / 62,104 assertions, PHPCS, maximum
PHPStan, Composer validation, full Go tests/vet and the offline API-map check.


### Reviewer investigation of stale Email suspension flags

The [review of `851def1`](https://github.com/hostingpal/whm-api-client/pull/31#pullrequestreview-5715451604) separately reproduced stale `list_pops`
suspension flags on `npr`, while checking the real lock state in the server's
`shadow` file. Eight back-to-back API-only cycles produced three wrong flags in
both directions; the stale values persisted after 1.8 seconds and appeared on
both routes. Sixteen cycles with about a second between writes and server checks
had no wrong flags. A further sixteen back-to-back cycles caught one stale
suspended flag after unsuspend while the real lock was open. Server lock state
was correct at every point checked, and each throwaway mailbox was removed.

The reviewer concluded that the failed readback did not mean `unsuspendLogin()`
failed and was not a client mutation defect. Same-second `email_accounts.json`
and `shadow` mtimes support a cache-invalidation explanation, but do not prove
the mechanism. Reading `listPops()` just after a write can return the prior flag,
which can remain stale until a later write refreshes the cache. The reviewer
explicitly marked this finding **nonblocking for PR31**, without waiving it.
The failed artifact and open follow-up `8x0.2.18.1` remain unchanged in status.

These observations are attributed reviewer evidence, separate from this chat's
55-response structural capture. Observed overlay notes on `list_pops`,
`suspend_login` and `unsuspend_login` render the caveat in the generated API docs.
No runtime spec, recipe timing or captured response changed, and no new live run
was performed for this documentation correction.

## 2026-10-30 complete SSL contracts and multipart request support

The third stacked slice adds 43 typed SSL functions, covering all 49 nondeprecated
functions (check_shared_cert stays excluded). Together with the preceding Email
and DomainInfo/Features/LangPHP slices and the approved MySQL/FTP/PostgreSQL
master changes, this branch generates 279 operations in 15 groups. Pinned source snapshots remain unchanged. Independent request and
payload fixtures cover direct wrapped, direct bare and WHM-bridged execution.

Three source-declared multipart operations now use explicitly reviewed inline
string bodies: fetch_key_and_cabundle_for_certificate, upload_cert and upload_key.
The shared lifecycle retains exact routing, per-attempt metadata clearing, no
retries and sensitive frames. Boundary selection preserves arbitrary field bytes;
unsafe field names and non-string values fail locally. This adds no generic JSON,
file-handle or multipart escape hatch. Generator checks reject unreviewed multipart
bodies and incompatible query/body duplicate declarations. The upload_key page's
body crt versus query key contradiction is represented by an evidence-required
body-field correction; actual key acceptance is a separately recorded live claim.

The source's prose requires either ID or friendly name for three selectors despite
marking both required in its schema; evidence-bound requiredness corrections expose
both alternatives. install_ssl's optional domain_dcv_method object remains omitted
with a generated caveat because no authoritative form encoding is specified.
Follow-up8x0.2.16 owns this contract gap. Opaque/no-data operations preserve their
respective null/field/absence contracts instead of inventing mutation return types.

The SSL fixture uses independently generated, fresh in-memory PEM material for each
route. It never installs certificates or changes existing hosted services. Every
cleanup target must have confirmed creation and an unambiguous new identity;
uncertain uploads remain failed evidence and are never adopted from later reads.
Actual multipart results and resource cleanup are documented after execution.

### SSL inventory preflight observations

The [first SSL capture](evidence/2026-10-30-ssl-section.json) at 4a3c988 on
11.138.0.19 has SHA-256
`088e3c0e5045c2daf90f4dc8e705d863805614767db878b8226b5588cd8a30bd`.
Five GET responses were retained: WHM version passed, while list_keys/list_certs
failed typed validation on both routes. No upload or deletion ran; all resources
remain not_started. Eight evidenced corrections preserve quoted integer fields,
quoted self-signed flags and a nullable literal issuer.organizationName value.
The generator now supports escaped dots in correction paths so this literal wire
key is targeted without changing any nested field or replacing an entire object.

The [corrected inventory capture](evidence/2026-10-30-ssl-section-corrected.json)
at a43adae, SHA-256
`8b83217819c1275503d40c9d4547407794bc4e3a450170643e43fc1a93a63719`,
passes all five API/payload checks without discrepancies. Fixture preflight still
fails for existing certificate rows without a modulus; no mutation ran. The
inventory identity guard now permits omitted/null modulus only when the pinned
id-ecPublicKey algorithm, a documented curve and nonempty hexadecimal public point
establish non-RSA identity. Ambiguous RSA/unknown rows fail, including null modulus
previously admitted without algorithm checks. Independent tests cover both
inventories and preserve unrelated resources. Failed artifacts remain immutable.

The [owned-upload capture](evidence/2026-10-30-ssl-section-identity-corrected.json)
at 079d86b on 11.138.0.19 has SHA-256
`ee85cb903f02a686ccce6c0f317b4c36fb8f8715db48c86fd3ee4a6b8eea7e08`.
It records 19 original responses. Both routes accepted the multipart upload_key
field named key. Direct upload validation then rejected quoted integer fields;
the bridged key and certificate uploads passed typed validation, but an unknown
certificate field stopped the lifecycle before fetch. Delete responses also exposed
quoted integers/flags and an unknown key field. Every confirmed uploaded resource
was deleted and its absence verified; the direct certificate was never created.

Corrections target only the observed upload_key, delete_key and delete_cert fields.
A reviewed upload-only continuation rule permits a valid typed upload with unknown
extra fields to proceed after its original response proves ownership. Those extras
remain recorded and fail the strict final assessment. Preflight, inventory, fetch,
cleanup, uncertain-identity handling and the final zero-discrepancy gate are unchanged.

The [upload-corrected capture](evidence/2026-10-30-ssl-section-upload-corrected.json)
at 1370c7b, SHA-256
`1f38a174a8075059bcd55ccd865061c6be3cab98f052eaf93c2f43e5eb65b2ee`,
records 22 responses and confirms cleanup of all four uploaded resources. The
bridged multipart fetch now passes. Direct upload_cert exposes quoted versions of
five documented numeric/flag fields, so only those fields receive the same bounded
compatibility handling. Unknown upload_cert/delete_key fields remain failures;
follow-up 8x0.2.19 tracks their contracts and remaining SSL live coverage.


The [final owned SSL capture](evidence/2026-10-30-ssl-section-final.json) at
64d5266 on 11.138.0.19 has SHA-256
`a465a7763181c84cc917e793a3eb2f38f7b22f2f70e69ba39f7254bb4e53bce9`.
All 23 planned original responses pass API, typed-payload and fixture-expectation
checks, including all three multipart functions through direct and bridged UAPI.
Both fresh keys and both certificates were confirmed absent after cleanup.
The strict assessment still fails on four masked unknown-field observations:
upload_cert and delete_key on each route. The cleanup_request_failure labels
retain that strict discrepancy failure; successful deletion and final absence
are recorded separately. No discrepancy is acknowledged or removed. This proves
seven SSL functions on these routes/revision/version, not all 49 generated methods,
certificate installation, TLS serving, trust, AutoSSL issuance or renewal.


### SSL external-review corrections: 2026-10-30

Review of `7ff0090a75c7e87931fe4cdf933f0c8fc2f85142` independently reproduced
quoted `created`/`modulus_length` on direct `generate_key` after successful
creation, and `is_self_signed` failures on `fetch_cert_info` and
`fetch_certificates_for_fqdns`. The earlier evidence-bound corrections covered
only selected inventory/upload/delete operations. The reviewed record-family
policy now applies the same narrow rules to all **66 declared fields in 21 SSL
operations**: canonical PHP-integer-bounded strings for `created`,
`modulus_length`, `not_after`, `not_before`; exact quoted 0/1 for
`is_self_signed`; and explicit null for the literal `issuer.organizationName`
string. Existing optional fields and nullable modulus lengths are preserved.
This is family compatibility inferred from the matching pinned record contracts,
reviewed live reproductions and immutable original SSL artifacts, not a claim
that each extended operation/path was captured live. Unknown fields remain
masked and unacknowledged under `8x0.2.19`.

Independent endpoint fixtures cover native and quoted values, null versus omission,
invalid flags, fractional/exponent/noncanonical numeric strings, integer overflow
and wrong organization types across wrapped, bare and bridged UAPI. Before the
fix, 132 accepted-record cases failed; 16 installSsl certificate/CA-bundle trace
cases also reproduced the missing parameter marks. Certificate and CA-bundle
arguments now remain sensitive alongside the private key. `getCabundle()` sends
PEM in a form POST body, retaining its pinned GET source identity while avoiding
URL length limits and certificate contents in access logs. A Go regression first
proved that a multipart override accepted a binary file schema; generation now
rejects it because inline string fields do not provide filename upload semantics.
The executor and live cURL documentation describes both supported body encodings.

The expanded SSL storage recipe adds `generate_key`, `fetch_cert_info` and
`fetch_certificates_for_fqdns` through both routes. It plans **31 observations
(13 GET, 18 POST)** across ten SSL functions plus WHM version, retaining all three
multipart operations. Each route tracks a generated key separately from its
uploaded key and certificate, for six resource states. Generated ownership needs
a successful fully validated original response with a unique new ID, matching
PEM/public modulus, fresh route-bound label and absence from prior inventories
and other owned resources. Lost/malformed/unsuccessful generation remains uncertain;
later absence or identity sightings never authorize adoption, deletion or retry.
Independent failure modes cover each of those cases. Private PEM, resource IDs
and moduli stay in memory; final inventories check exact IDs and public moduli.
No account/server certificate is installed or replaced, and no TLS service,
AutoSSL, trust or renewal coverage is inferred. The optional install_ssl DCV map
remains omitted under `8x0.2.16`.

This stage runs offline only. Final master integration, regeneration to the
combined 279-operation/15-group surface and one capture from committed integrated
code follow the parent Email PR merge. Every historical SSL artifact remains
unchanged, including the 23-observation v1 run and its four strict unknown-field
findings. The expanded recipe does not waive those findings or promote them into
typed fields.


Offline validation for these review fixes passes `composer validate`,
`composer check` (**9,059 tests / 57,727 assertions**), `composer api-map:check`,
and the complete Go test/vet suites. The final independent record suite passes
1,914 tests / 5,154 assertions; installSsl PEM trace cases pass 16 / 80;
expanded fixture and request guards pass 72 / 602. A focused read-only review of
the ownership, guard and independently authored record tests found no actionable
issue and passed 1,986 tests / 5,756 assertions. The independent field matrix
confirms exactly 44 changed field specifications among the 66 reviewed fields,
with every other existing type/nullability key preserved. Historical evidence
files remain byte-identical. Integration and live capture remain separate pending
steps, and this offline result does not establish upstream behavior for the
expanded recipe.


### Integrated SSL v2 capture after PR31: 2026-10-30

The approved Email parent was integrated by normal merge at
`d159935d8da99062ef6527952ff1f6b1c5274dbd`, without rebasing. Regeneration produces
**279 operations across 15 groups**, retaining all reviewed Email/runtime/schema
corrections and all historical evidence. Pre-capture Composer validation/check
(**11,019 tests / 75,525 assertions**), API-map checks, full Go tests and vet pass.
The reviewed SSL field matrix remains identical after integration.

Exactly one [expanded capture](evidence/2026-10-30-ssl-section-reviewed.json)
ran at that committed code on `11.138.0.19`, SHA-256
`bd1e409a5608fff3ab4be889b84ce83743188548ec154f24e19601e9c7ad9aef`.
It retains **27 original responses (13 GET, 14 POST)**. All 27 HTTP 200/API
outcomes pass. Twenty-five payload/probe/expectation checks pass; both
`fetch_certificates_for_fqdns` calls fail typed validation, with expectations
`not_checked`. Their records are `probes[11]` (direct) and `probes[22]` (bridged).
Both original payloads report null|string structural types at `$[].cab`,
`$[].validation_type` and `$[].verify_error`; each path raises `type_mismatch`.
The pinned three fields are optional nonnullable strings, with no enum. The
validation_type prose nevertheless documents null, and sibling certificate
contracts already declare that field nullable. fetch_cert_info's cabundle also
permits null, while other cab endpoints and installed_host.verify_error retain
separate pinned semantics; no cross-operation widening is inferred.

Both `fetch_cert_info` phases succeed and the reviewed numeric/flag conversions
work where exercised. Each lifecycle stops at the FQDN-certificate mismatch
before key generation. The four uploaded keys/certificates are confirmed cleaned;
both generated-key states are `not_started`, so neither generated-key execution
nor six-resource cleanup is established. Four known masked unknown-field
observations in upload_cert/delete_key remain unwaived under `8x0.2.19`.
Strict assessment stays **failed**, including incomplete planned phases,
lifecycle failures and cleanup-request labels from delete_key discrepancies.
No mutation was retried and no second capture was run to relabel this evidence.

Independent audit checks all 27 effective contracts, request methods/source
variants, committed source/map/generator/overlay/revision hashes and the original
strict report. All **1,144 structural path/type entries** and **58 historical
parent-artifact comparisons** pass. Configured credentials, response-derived
account/domain/host values, PEM contents and resource identities are absent;
only the fixed public recipe identifier includes its account label. The artifact
was written with mode0600 and no capture temporary files remain. The three narrow
nullable-field corrections, external review and outstanding generation evidence
are tracked in open `whm-api-client-8x0.4.11.3.4`; this artifact remains immutable.
The optional install_ssl DCV map stays omitted under `8x0.2.16`.


### Narrow FQDN certificate nullability correction

Open follow-up `whm-api-client-8x0.4.11.3.4` adds `nullable:true` at exactly
`fetch_certificates_for_fqdns` result fields `cab`, `validation_type` and
`verify_error`. The validation_type correction follows the pinned prose's explicit
null case despite its missing schema flag. The cab/verify_error corrections bind
the original direct and bridged observations in the immutable 27-response artifact,
including its code revision, method, source variant, hash and null|string types.
No null meaning is assigned to cab/verify_error. All three fields remain optional
strings with explicit null separate from omission; every sibling operation keeps
its existing cab/verify_error semantics and every other SSL field is unchanged.

Independent fixtures cover strings, empty strings, null and omission on wrapped,
bare and bridged UAPI. The nine null cases first fail; all 75 focused acceptance
and rejection cases then pass, including wrong boolean/integer/float/array values.
An independent spec comparison verifies exactly three nullable flags and no other
operation, type, optionality or constraint changes. Historical artifacts remain
byte-identical and the failed capture is not reassessed as successful under a new
contract. No additional live request was made. Review, corrected FQDN evidence
and the unexecuted generated-key phases remain outstanding on the open follow-up.


The narrow correction passes Composer validation/check (**11,094 tests /
75,675 assertions**), offline API-map checks, full Go tests and vet. The focused
75-case nullability suite passes 150 assertions. A separate field-spec audit
confirms exactly the three nullable flags and all other SSL rules unchanged;
non-SSL generated groups/specs retain the approved master contracts. External
review and a separately authorized committed-code capture remain pending; no
additional live request follows this correction in this stage.


### Expanded final SSL capture after review clearance

The reviewer explicitly cleared exact code
`1f67a43398fabc46b35b5bd30d08c3d7ffe7467a` in
[comment 6528376774](https://github.com/hostingpal/whm-api-client/pull/32#issuecomment-6528376774)
for one committed-code capture after independently checking the three nullable
fields and both-route generated-key normalization/cleanup. This is separate
reviewer evidence; the new committed artifact comes from the cleared code's one
existing guarded fixture run. No code, request or compatibility rule changed
between that clearance and capture.

The [expanded final SSL artifact](evidence/2026-10-30-ssl-section-expanded-final.json)
has SHA-256 `1a42e7a3c920b70eb91ef2f26f7f40aed8209693b8e850f56e594a2620d382d5`
and records **all 31 original responses: 13 GET and 18 POST** on
`11.138.0.19`. Every HTTP 200/API outcome, typed payload, probe and independent
fixture expectation passes. Ten SSL functions execute on both direct and
WHM-bridged UAPI, plus WHM version. This includes all three multipart operations,
`fetch_cert_info`, `fetch_certificates_for_fqdns` and `generate_key`. The corrected
FQDN fields preserve null|string values, and generated-key numeric fields normalize
to the precise integer contract. Generated private keys remain in process memory;
the successful original response establishes unique fresh ID/modulus/PEM identity
before any generated-key cleanup is authorized.

Both uploaded keys, both certificates and both generated keys are `cleaned`.
Final same-run inventories confirm exact owned ID and public-modulus absence,
while unrelated resources are retained. No mutation is retried and no observation
is replayed to recover evidence. No certificate is installed or replaced, and the
recipe establishes no TLS serving, trust, service configuration, AutoSSL issuance
or renewal behavior, nor successful live execution of all 49 generated methods.
The install_ssl DCV map remains omitted under `8x0.2.16`.

The command exits **1** and strict status remains **failed**, solely for **six
masked unknown-field observations**. upload_cert, delete_key and the new
`delete_generated_key` phase each contribute one observation per route; the latter
still invokes SSL/delete_key. Every tuple is `$[].*` / `unknown_field` with string
structural types. All six match the earlier 27-response artifact at operation,
source variant, route, actual method, server version, pinned source and effective
contract hashes, full payload paths and discrepancy tuples. The added generated-key
cleanup supplies two more observations of that operation/structure family. This
is masked structural equivalence, not proof of unknown key identities or counts.
No original discrepancy is removed, acknowledged or promoted to a typed field.
The two cleanup_request_failure labels retain the strict discrepancy failures;
API deletion, passing expectations and final absence are recorded separately.
Follow-up `8x0.2.19` remains open for these contracts and other unobserved SSL work.

Independent audits verify every effective contract and committed revision/source/
map/generator/overlay hash, ordered phase identity, method and source variant.
All **1,266 structural path/type entries** and **94 historical parent-artifact
comparisons** pass, including the failed 27-response artifact. Recomputing the
strict report preserves every original record and its failed status. Configured
credentials, response-derived account/domain/host values, private/public PEM and
resource identities are absent; the fixed public recipe identifier retains its
account label. The new file was created with mode0600 and no capture temporary
files remain. Pre-capture code passed Composer validation/check (**11,094 tests /
75,675 assertions**), offline API-map checks and Go tests/vet. Only evidence and
documentation follow this capture; no additional live request was made.


## 2026-10-30 domain-management section

This independent slice adds 22 generated operations to master a91cb0d,
bringing its branch to 301 typed operations across 21 groups. Pinned source
snapshots remain unchanged. Independent literal fixtures exercise wrapped direct,
bare direct and WHM-bridged calls through the real generated contracts. The source
has field examples but no full response examples for these operations; assembled
fixtures are identified accordingly. No engine or generator behavior changed.

The domain-management groups cover all 22 pinned functions. Inventory and lookup
methods use GET; nine mutations use form POST. Most new domain endpoints require
cPanel 138; generated PHPDoc records the different floors and role restrictions.
Names and document roots are forwarded using their documented wire fields. Omitted
options retain server defaults, while explicit false is sent as 0. Rename and
conversion operations can change hosted configuration immediately and are not
retried or rolled back by the client.

`deleteAddonDomain()`, `park()->park()`, `park()->unpark()` and
`deleteSubdomain()` return void because their source expressly specifies no data.
`addSubdomain()` instead preserves a nullable opaque object, including unknown
object fields; an absent payload is a mismatch. WebVhosts preserves optional and
nullable proxy-subdomain lists. `listSslCapableDomains()` does not invent a domain
argument from inconsistent upstream prose.

The reviewed read-only catalog has 27 exact GET probes: WHM version and all
13 read operations through both UAPI routes on npr/npr.rs. Shared command
infrastructure reuses EvidenceRunner, its exact-URI GET guard and structural
sanitization without repeating the historical baseline. The strict assessment
requires the complete ordered catalog, matching provenance and zero discrepancies.
The read-only capture cannot establish mutation effects. Its outcomes and the
separate reviewer mutation observations are recorded below.

The [2026-10-30 domain-management capture](evidence/2026-10-30-domain-management.json)
at `9db0cad` on cPanel `11.138.0.19` records the reviewed catalog. All 27 GET observations passed HTTP, API, payload and probe checks. Strict assessment remains failed for two masked integer unknown-field observations at `$[].*` in `WebVhosts/list_ssl_capable_domains`, one per route. No acknowledgment or new typed field was added.
Addon, parked and subdomain inventories were empty. Their row fields, true redirect-status variants and nullable proxy-subdomain variants are not established by this read-only capture. Follow-up `8x0.2.20` owns the remaining live/contract work.

The immutable capture SHA-256 is `c53db4f8a2b1ef00b212b3f0a537c3b646c87590ffee2ac925254540b269b518`.
Review [5717731712](https://github.com/hostingpal/whm-api-client/pull/33#pullrequestreview-5717731712)
separately reports successful generated add/delete subdomain, change document root,
add/delete addon domain and park/unpark calls through both routes, followed by
resource cleanup and restoration of temporary account-limit changes. This reviewer
report does not extend the artifact's read-only observations. Rename and temporary
domain conversion remain unobserved. The two masked WebVhosts findings are
explicitly nonblocking in that review, remain strict failures and are unwaived.

Review corrections retain all generated operation specs and the original evidence.
All nine mutations now expose the pinned `x-rollback=none` caveat. Addon deletion
points callers to `responseMeta()->uapiMessages()` for a retained subdomain;
SubDomain regex matching is case-insensitive and invalid Perl patterns fail;
WebVhosts documents the temporary-domain error behavior without adding a selector.
The shared read-only assessor rejects malformed records and absent or malformed
structural maps; independent regressions cover the version and UAPI records.

## 2026-10-30 directory-access section

This independent slice adds 10 generated operations to master a91cb0d,
bringing its original branch to 289 typed operations across 18 groups. Pinned source
snapshots remain unchanged. Independent literal fixtures exercise wrapped direct,
bare direct and WHM-bridged calls through the real generated contracts. The source
has field examples but no full response examples for these operations; assembled
fixtures are identified accordingly. No engine or generator behavior changed.

The directory groups cover all ten pinned functions, available from cPanel 88.
Six reads use GET and four mutations use form POST. Directory paths are forwarded
unchanged. Only `DirectoryProtection/list_directories` documents paths relative to
the account home directory. `configureDirectoryProtection()` documents absolute
or relative paths without defining the relative base; `isDirectoryProtected()`
documents absolute paths. The [PR #34 reviewer](https://github.com/hostingpal/whm-api-client/pull/34#pullrequestreview-5717732052)
independently observed `public_html/...` relative paths working for both privacy
methods through direct UAPI and the WHM bridge.
Password-protection state belongs to DirectoryPrivacy; DirectoryProtection reports
leech protection. A successful unprotected-directory result has payload flag 0;
that does not make UAPI status 0 a success.

`configureDirectoryProtection(dir, enabled, authname)` can omit `authname` when
disabling protection, following the source's conditional requirement. Supply a
realm name when enabling it. `addUser()` keeps its password sensitive throughout
the request. User mutations preserve nullable opaque results and reject an absent
payload. Directory fields retain optional presence; only `auth_name` and
`auth_type` in the configure response additionally accept explicit null, based on
the reviewer's successful disable calls. Read-operation field types are unchanged.

The reviewed read-only catalog has 13 exact GET probes: WHM version and all
6 read operations through both UAPI routes on npr/npr.rs. Shared command
infrastructure reuses EvidenceRunner, its exact-URI GET guard and structural
sanitization without repeating the historical baseline. The strict assessment
requires the complete ordered catalog, matching provenance and zero discrepancies.
All 4 mutations were offline-only in the original slice; read observations cannot
establish their effects. The later reviewer run is recorded separately below.

The [2026-10-30 directory-access capture](evidence/2026-10-30-directory-access.json)
at `e3aa06b4bb693010ebd1df0709f4247b52022e72` on cPanel `11.138.0.19`
records the reviewed catalog. Its SHA-256 is
`3ae9c591fe57de8567195605985f435274da732e852561a0ca8776559d4e2685`. All 13 GET observations passed HTTP, API, payload and probe checks, with zero discrepancies and a passed strict assessment.
Directory records were present, but every captured `auth_name` and `passwd_file`
was an empty string, and the privacy-user list was empty. This capture made no
indexing, password-protection or leech-protection changes and establishes no
mutation or HTTP-enforcement coverage. Separate reviewer mutation observations
are recorded below; they are not part of this artifact. Follow-up `8x0.2.22` owns
the remaining live/contract work.

The [PR #34 review](https://github.com/hostingpal/whm-api-client/pull/34#pullrequestreview-5717732052) of
`c5326f75d504cf8760cb8d25e5abe2e84576dea2` separately exercised all four
mutations on `npr` through direct UAPI and the WHM bridge in throwaway directories,
reporting directory and `.htpasswds` cleanup. Successful disable responses carried
explicit null `auth_name` and `auth_type`, which the original string-only spec
rejected with `PayloadMismatchException` and `outcomeConfirmed=true`. The correction
makes exactly those two fields optional nullable strings on
`configure_directory_protection`. It preserves string and omitted forms and leaves
`passwd_file`, the integer flag, and every read-operation spec unchanged. The
review also observed populated string fields while protected, safe API rejection
when enabling without `authname`, and accepted relative paths as described above.
The review did not state its server version and supplies no saved mutation
artifact; it does not extend the read-only artifact's provenance or establish HTTP
authentication enforcement. No live calls or replacement capture were made for
this correction. The reviewer will recheck the mutation on the final integrated
head; `8x0.2.22` retains the remaining evidence and contract work.

The directory branch then normally merged the approved PR #33 master commit
`2b67612fa5feb64e028e077f868e5917be6651be`, bringing the combined generated surface
to 311 operations across 24 groups. Regeneration preserves every operation spec
from both parents, including the two configure-response nullable fields. The
shared read-only assessor and its regression tests come from that approved master
commit. Both original read-only artifacts remain unchanged; integration made no
live requests and does not extend their evidence.

## 2026-10-30 mime-section section

This independent slice adds 14 generated operations to master a91cb0d,
bringing its branch to 293 typed operations across 16 groups. Pinned source
snapshots remain unchanged. Independent literal fixtures exercise wrapped direct,
bare direct and WHM-bridged calls through the real generated contracts. The source
has field examples but no full response examples for these operations; assembled
fixtures are identified accordingly. No engine or generator behavior changed.

The Mime group covers all 14 pinned functions: six reads use GET and eight
mutations use form POST. No domain selector is documented for hotlink
configuration. MIME deletion targets the MIME type, while handler deletion
targets extensions. Redirect filtering, defaults and source/destination behavior
follow each method's pinned PHPDoc. These calls do not test HTTP redirect delivery.

Mutation results generally preserve nullable opaque objects. The pinned
`add_handler` and `add_mime` schemas additionally declare a null-only enum; the
current effective validator keeps their nullable object boundary without enforcing
that enum. Missing payloads still fail. `deleteHotlink()` has an unspecified data
schema, so its payload remains mixed rather than acquiring invented fields.

The reviewed read-only catalog has 13 exact GET probes: WHM version and all
6 read operations through both UAPI routes on npr/npr.rs. Shared command
infrastructure reuses EvidenceRunner, its exact-URI GET guard and structural
sanitization without repeating the historical baseline. The strict assessment
requires the complete ordered catalog, matching provenance and zero discrepancies.
The catalog does not execute the eight mutations; read observations cannot
establish their effects. Live capture outcomes and later independent reviewer
observations are recorded separately.

The initial [2026-10-30 Mime capture](evidence/2026-10-30-mime-section.json)
at `9262564` on `11.138.0.19` preserves 13 GET observations. All API outcomes
passed, but four payload checks failed: `get_redirect.url` and
`list_hotlinks.redirect_url` were explicitly null on both routes. The exact two
field corrections preserve string, empty string, omission and null separately;
other scalars/containers and sibling types remain rejected. Independently authored
regressions first reproduced all six failures across direct wrapped, direct bare
and bridged envelopes, then passed after regeneration. The original artifact is
unchanged (SHA-256 `bb55502de9a306159e7d7eeeeb913b60cf016c5574d0ce94737c3365b6c62a9a`).
The structural observations do not establish the upstream reason for null.

The [corrected-contract Mime capture](evidence/2026-10-30-mime-section-corrected.json)
at `fdbafb6` on `11.138.0.19` reruns the unchanged reviewed read catalog.
All 13 GET observations passed HTTP, API, payload and probe checks with zero
discrepancies and a passed strict assessment. Its SHA-256 is
`91526a8fe46c683cf16fc8fabe3bcfc45ff0de17f11d383e9ee97ff7434e1c81`.
The observed null URL fields are preserved. MIME and redirect lists were empty;
handler rows were populated. This capture includes no mutations and does not
establish HTTP redirect delivery or hotlink enforcement. The initial failed
artifact remains unchanged.

In a separate [review of `933e4dee80bfd7fa1c5543f3a54e454ae5b94b0c`](https://github.com/hostingpal/whm-api-client/pull/35#pullrequestreview-5717739276)
on 2026-10-30, Claude reports successful live checks on `npr` through both direct
and bridged UAPI routes for all eight mutations: `add_mime`, `delete_mime`,
`add_handler`, `delete_handler`, `add_redirect`, `delete_redirect`, `add_hotlink`
and `delete_hotlink`. Populated MIME, handler and redirect rows validated on both
routes. The reviewer observed a string `list_hotlinks.redirect_url` while hotlink
protection was enabled and null afterward; all mutations returned null, with one
message on `add_redirect`. The reviewer snapshotted `public_html/.htaccess`,
restored it and verified that its SHA-256 matched before and after. These are
attributed reviewer observations, not additional records in either committed
capture or evidence of HTTP redirect delivery/hotlink enforcement. No live calls
were made for these documentation corrections.

Follow-up `8x0.2.21` retains remaining response/argument variants and HTTP
behavior, plus the runner's operation/route duplicate-key limitation for distinct
argument variants. No current catalog hits that limitation. The pinned null-only
mutation enums remain within the documented `8x0.18` compatibility limitation.

The Mime branch then normally merged the approved PR #34 master commit
`b68e76053049d47db6a075461bb3edee5cd4eed9`, which includes PR #33, bringing the
combined generated surface to 325 operations across 25 groups. Regeneration
preserves every operation spec from both parents and the Mime client code. The
shared read-only assessor, its regression tests and the relocated domain probe
test come from that approved master. All original evidence artifacts and pinned
sources remain unchanged. Integration made no live requests or new captures and
does not extend the historical observations.

## 2026-10-30 mail-protection section

All 47 pinned operations across BoxTrapper, EmailAuth, SpamAssassin and cPGreyList
are generated from the unchanged 2026-10-13 source. The 19 reads use GET and 28
mutations or secret-returning operations use form POST. Independent literal
fixtures cover exact parameters, all declared result fields and the complete
SpamAssassin update example through wrapped direct, bare direct and WHM-routed
UAPI. Source descriptions, parameter-level examples, requiredness, role/version
caveats and partial-result semantics are reviewed separately from generated specs.

BoxTrapper blacklist_messages explicitly supports repeated queuefile fields in
its parameter-level example, even though the schema is scalar. Rule lists permit
repeated or numbered forms; the selected encoding is repeated. Some message and
Greylisting examples use contradictory field names; generated requests follow
the declared parameters and explicit repeated-key prose. set_forwarders has an
array schema with missing item type and an email example; repeated string values
follow the query array's default form/explode serialization. No server behavior
is inferred from that source interpretation.

SpamAssassin's update response nests an additionalProperties description under
properties and omits its array type, while prose and the complete example show
custom-named string lists. The example does not establish a general contract
for every custom preference. Both read and update return the complete preference
object, so their evidence-backed root replacements retain the same four known
optional fields and preserve opaque custom values as mixed. The new opt-in object values child preserves those keys without weakening
known fields; structural evidence masks dynamic keys. Existing closed objects and
all prior operation specs retain their contracts. Typed scalar preference values
do not yet implement the separately documented value-0/value-1 family, tracked
under `whm-api-client-8x0.1.20`.

The fixed read-only catalog has 19 observations: WHM version plus nine selected
operations through both routes, bound to npr/npr.rs. BoxTrapper mailbox-specific
reads/settings use a separate owned-mailbox fixture. Live outcomes and limitations
are recorded separately; no mail delivery or filtering-enforcement claim follows
from structural/API checks.

The first read-only capture at `4495789` on `11.138.0.19` records 19 HTTP-200
successful API outcomes and eight payload failures. Its immutable SHA-256 is
`801d8741c2f22fde88e3e00d3ff930e4d3e7dd6ea8547e23b1bdf763e0c78775`.
Both routes observe null DKIM error, DMARC error/record and PTR error fields;
only those four fields gain nullable compatibility. PTR ip_version opts into
the existing canonical, PHP-integer-bounded numeric-string conversion. SpamAssassin
symbolic scores preserve observed numeric strings alongside native integer/float
values, using the existing finite decimal grammar without floating-point coercion.
The structural artifact does not establish exact numeric spellings or values;
synthetic precision/bounds tests remain separate. Known fields and sibling
contracts stay strict. Two PTR nested null unknown-field observations remain
untyped and unacknowledged under `8x0.2.23`.

The corrected read-only capture at `a063db0` passes all 19 API/payload checks;
strict assessment remains failed for the two PTR unknown-field observations.
Its immutable SHA-256 is
`6cf42ae1f62f70835a5025174d352801b69b80e9900c85050dce15543e944701`.
At the same revision, the first BoxTrapper fixture captures 23 observations
(15 GET, 8 POST): all API outcomes succeed and both configuration reads fail only
on numeric-string `queue_days`. Both temporary mailboxes are confirmed cleaned.
The original artifact SHA-256 is
`5f931b1c478b3ab1c5e8dabf9fcc78381a57aa70a7204bffe0c428f99125ae29`.
Only `get_configuration.queue_days` gains the existing canonical integer-string
normalization; no boolean flags or sibling field contracts change. The first
capture does not establish rule round trips or later-phase behavior.

The next BoxTrapper capture at `da4dddc` contains 43 observations (29 GET,
14 POST), all API outcomes successful. All configuration/rule/template readbacks
pass through both routes. Direct `get_log.date` is a numeric string and fails
payload validation; its bridged counterpart is a native integer and passes.
The direct route aborts before its queue/final-status reads, while the bridge
completes. Both mailboxes are confirmed cleaned. Preserve this failed artifact
(SHA-256 `8c02bb75a38ab17bea72e39dd8c62f79726735bdb81a50ec8b3f29cc77072651`).
The date field alone gains canonical, PHP-integer-bounded string normalization,
with no exact date, string spelling or cause inferred from structural evidence.

The final disabled-mailbox capture at `3ea2166` passes all 45 observations
(31 GET, 14 POST), API/payload/expectation checks and strict assessment with zero
discrepancies on `11.138.0.19`. Both original-response creation identities and
inventory readbacks establish ownership; both mailbox deletions and final absence
checks pass. All fourteen selected BoxTrapper functions execute through both
routes, with protection disabled throughout and no delivery/queue mutation.
This establishes configuration and three rule-list API readbacks, template reads,
and empty logs/queues; it establishes no filtering or delivery enforcement.
Artifact SHA-256:
`c29304b444fe7fc57eb226ecd5b3a76ff5ccaf9b72440ac0548f5c0372c432ee`.

Initial handoff gates passed 15,437 tests / 108,555 assertions, PHPCS, maximum PHPStan,
strict Composer validation, Go tests/vet, pinned source/map checks and generation
reproducibility. Independent contract, integration, correction and structural
artifact reviews completed. Generated coverage is 372 operations in 29 groups;
22 of this slice's 47 functions have bounded live observations, with remaining
live work/PTR unknowns tracked in `8x0.2.23` and indexed preference inputs in
`8x0.1.20`. Prior artifacts and pinned source snapshots remain unchanged.

### PR36 current-head review corrections

[Claude review 5719192482](https://github.com/hostingpal/whm-api-client/pull/36#pullrequestreview-5719192482)
assessed `ed1e68c44acc8b97133892f0ce7d068d5db11fab` on cPanel `11.138.0.19`.
The reviewer observed `required_score` numeric strings in both preference reads
and updates through direct UAPI and the WHM bridge. The two item contracts now
use the existing finite numeric-string acceptance with preservation, alongside
native integers and floats. Independent tests cover exact formatting, precision,
invalid values and all supported envelopes. Custom preference values remain
opaque on both operations because updates return the complete preference object.
These reviewer observations are distinct from the committed structural artifacts.

The reviewer also found a preexisting local `_dmarc` record despite a null DMARC
validation record. Removal deleted it; the reviewer restored the zone snapshot
and verified the record separately. Both validation and removal now document
that null is not a safe absence check. This correction adds no new DMARC calls.

Pinned `x-rollback=clean` classifications are now represented correctly for
BoxTrapper `save_configuration`, `save_email_template`, `reset_email_template`,
`set_allowlist`, `set_blocklist`, `set_ignorelist` and `set_forwarders`.
The client still performs no automatic rollback or retry. Other operations'
rollback classifications are unchanged.

The largest remaining payload risk is unobserved nested DKIM/SPF
`records[].reason` nullability. Populated queued-message `time` values and eight
message actions also remain unobserved. These remain in `8x0.2.23`; no contract
is widened by speculation. The two masked PTR strict failures remain explicitly
nonblocking and unwaived under that same follow-up.

The Email and BoxTrapper entrypoints now share `SectionCommand`; their original
fixtures, guards and recipes remain byte-identical. The new required-score
fixture uses that command and restricts four nominal POSTs to one changed value
and one exact restoration through each route. Both routes confirm the full
original preference set. Original floats, overflowing JSON integers, unsafe
threshold shapes or disagreeing route snapshots fail before writes. The private
comparison preserves scalar types and list order and ignores only object-key
order. Lost-response uncertainty cannot be cleared by later matching reads.

Independent offline review checked request confinement, zero-POST preflight
failures, distinct restoration, unretried uncertain writes, evidence privacy and
separate preference/catalog assessments. The integrated correction passes
15,662 tests / 110,316 assertions, PHPCS, maximum PHPStan, strict Composer
validation, Go tests/vet and the offline source/map/generation gate. These checks
do not establish a live outcome for the new recipe; its final capture is separate.

### Required-score correction capture at 6f3e0cb

The single requested final capture at
`6f3e0cb530c04874dc504b9b291a4401ea80f187` on `11.138.0.19` is preserved as
[`2026-10-30-mail-protection-review-final.json`](evidence/2026-10-30-mail-protection-review-final.json),
SHA-256 `7c0c9b67e1bc79e3c3e39d0406013e846c0d715c0837d038dd304fcb5244014c`.
It contains 26 observations (24 GET, two POST), all HTTP 200 with successful API
outcomes and payload validation. Direct threshold set and readback expectations
pass with a numeric-string score. The original preference object had no
`required_score`; the omission-based restoration reply contains an empty
`required_score` list instead of omitting the field, so its exact-object
expectation fails. This is a response-versus-state distinction, not a payload
contract failure.

Both subsequent restoration reads and both final catalog preference reads match
the complete original object, with the field absent. The immutable artifact still
records direct restoration as `uncertain`, bridge mutation as `not_started`, and
failed preference assessment. No mutation was retried and no bridge write ran.
The final 19-GET catalog retains the two masked PTR unknown-field failures without
waiver. Their masked structures match the earlier capture; this does not identify
the underlying unknown key names. `8x0.2.23.1` tracks review of the removal reply
expectation and remaining bridge evidence before any further mutation.

Committed captures now observe 23 of the 47 new functions, adding direct
`update_user_preference` execution to the prior 22. That count does not establish
bridged preference mutation coverage, successful strict final assessment or mail
filtering enforcement. All earlier artifacts remain byte-identical.

The follow-up fixture correction accepts an empty-list removal reply only when
`required_score` was originally absent, and only for the restoration POST
response. All other preference fields must still match exactly; configured
original values stay exact. Both restoration getters and final catalog getters
still require the original object with the field absent. Lost-response uncertainty
and failed API/payload outcomes remain failures. Independent literal regressions
cover the observed response variant, strict getters, unrelated changes and
uncertain outcomes. The full offline gate passes 15,680 tests / 110,389 assertions.
No second live capture has run; the first artifact is neither rewritten nor
reclassified. [Claude review 5719375767](https://github.com/hostingpal/whm-api-client/pull/36#pullrequestreview-5719375767)
approved exact `d499ab6`, independently reproduced the removal reply and verified
numeric-string preference set/read/update with a custom preference through both
routes on `11.138.0.19`; `user_prefs` was restored byte-identical. This reviewer
report is separate from the committed artifact. No further capture is required.
PR36 merged as `52af23a` with green CI and the reviewed tree. Follow-up
`8x0.2.23.1` remains open for future committed both-route evidence.

## Fileman GET and form slice (2026-10-30)

The Fileman group implements 13 of 14 pinned functions: directory autocomplete,
file information, file content and directory listings use GET; content saving,
in-memory transcoding, copy, move, rename, delete, trash, restore and trash purging
use form POST. `upload_files` requires multipart and cannot use the WHM UAPI
bridge; it remains excluded under `8x0.1.19`. Generic form calls do not supply
multipart support.

`copyFile(source, destination)`, `moveFile(source, destination)` and
`renameFile(source, destination)` keep source first. Content arguments are
sensitive and form-encoded once. Paths and content are forwarded unchanged;
charset conversion is performed by the server. **Omitting `saveFileContent()`
content or passing `content: null` truncates an existing file to zero bytes.**
A null variable is not a no-op; supply intended content and verify the target.
`emptyTrash()` defaults to purging the account trash. Preserve each
method's version, File Storage role, charset and destructive-operation caveats.

`listFiles(types: 'file|dir')` accepts a pre-serialized pipe-delimited filter.
The existing scalar encoding exposes a native `string|int` boundary without
establishing integer filter semantics. No undeclared indexed `filepath-` family
is inferred from `limitToList`. Optional response keys remain absent, flags stay
integer `0|1`. File-information and flat-list sizes preserve the observed empty
string for zero-byte files, distinct from numeric zero and omission; canonical
integer strings still normalize losslessly. Their uid/gid fields also preserve
observed empty strings for root-owned entries. File-content charset fields
with unspecified schemas stay mixed. `emptyTrash()` preserves nullable opaque
objects and rejects a missing payload.

Observed file information preserves `mode` and `nicemode` as `int|string`,
including string spelling, and normalizes `size` only from a lossless canonical
integer string. String permission values do not establish a numeric format rule.
Every recorded listing is a flat list of rows: iterate it directly. The pinned
object alternative with `dirs`/`files` lists remains supported but unobserved.
Use `array_is_list($result)` to select flat iteration; otherwise read those named
lists when present. An empty array contains no rows under either interpretation.
Flat rows have the reviewed permission/size representation corrections; the
documented object alternative retains its original row types.

All 13 methods have offline contract tests. The bounded live catalog selects
three directory inventory reads through both routes, plus WHM version. It reads
no existing file content and sends no mutations. Content, filesystem lifecycle
and transcoding verification remain under `8x0.2.24`; exclusive fixture creation
must precede overwrite-capable saves.

The unchanged pinned UAPI source SHA-256 is
`172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc`.
The overlay preserves declared parameter requiredness. Effective response
contracts explicitly replace the list_files root with a union and widen only
the evidenced get_file_information and flat-row fields described above; they
do not claim to preserve every pinned response shape unchanged.
`list_files.types` has an array schema with `style: pipeDelimited` and
`explode: false`; caller-prepared scalar encoding follows that wire contract.
`limit_to_list` mentions indexed paths without declaring their request schema.
Neither detail justifies inventing a request family or delimiter conversion.

The independent module test uses literal wire and payload oracles across direct
wrapped/bare and bridged UAPI, omitted/empty/false options, response type failures,
metadata and sensitive-content traces. The seven-request catalog has a separate
literal URI oracle and rejects mutation methods before network dispatch. Existing
runtime, generator and live-runner behavior is unchanged.

The initial [Fileman inventory capture](evidence/2026-10-30-fileman-inventory.json)
at `5ea0d565bb2eda9d5e03a0df23b5d8d07d1487bf` on `11.138.0.19` records seven
GETs. All HTTP/API outcomes pass; three payload checks pass and four fail.
`get_file_information` returns integer `mode` and numeric-string `nicemode` on
both routes, plus numeric-string `size` on the direct route. `list_files`
returns a flat list on both routes instead of the pinned object with `dirs` and
`files` lists. The initial listing rows have wildcarded fields because the
container did not match; this artifact establishes no named flat-row field types.
The original failure evidence stays immutable, with SHA-256
`c4f5b8b3f8930adb168f657a9b679544ad3196286250a00cd9701fe736277491`.

The [second Fileman inventory capture](evidence/2026-10-30-fileman-inventory-corrected.json)
at `bc7662dd86bfbccf41e1640cc3b38f9d63e1a0bc` records seven successful
HTTP/API outcomes and five passing payload checks. Both listing payloads still
fail the union. The existing original-response `payload_alternatives` diagnostics
for flat-list candidate index 1 (SHA-256
`2da4c9dfe2a88ae19278cc4b771de12efa463396dd478a7ebca72e8fc8c4b7be`)
identify integer `mode` and numeric-string `nicemode` on both routes, plus
numeric-string `size` directly and integer `size` through the bridge. This is
candidate structural evidence, not a passing or selected alternative.

These observations bind the same representation corrections to flat rows only:
`mode` and `nicemode` preserve `int|string`; `size` accepts lossless canonical
integer strings. The pinned object alternative's `dirs`/`files` row contracts
remain unchanged. The second artifact stays immutable with SHA-256
`7bd1db300c24babef264c7bfc445dbc2c1430a82152bdf33665a553428a0726f`.

The [final Fileman inventory capture](evidence/2026-10-30-fileman-inventory-final.json)
at `ec442bdea2fcab033d95a8f10b84afc56df8ec42` on `11.138.0.19` passes all seven GET
observations: WHM version and all three inventory operations through both UAPI
routes. HTTP/API/payload/probe checks and strict assessment pass with zero
discrepancies. Directory information and flat listing rows are populated;
`isparent` is omitted in the observed listing rows. The documented object-format
listing alternative remains offline coverage. No file content, mutation,
filesystem permission enforcement or charset round trip is established. Follow-up
`8x0.2.24` remains open. Earlier failure captures stay unchanged. Final SHA-256:
`946ba0512be318c20b51616a4ede7346e069ae1150f97b44c43ee03ea217bf8f`.

### PR37 review corrections and master integration

Master `52af23a` (approved PR36) was merged normally into the Fileman branch as
`2ee036e`. Both slices and every existing artifact were preserved; combined
generation is 385 operations in 30 groups. Integration passed 17,738 tests /
124,501 assertions and the offline API-map gate before pushing.

[Claude review 5719361198](https://github.com/hostingpal/whm-api-client/pull/37#pullrequestreview-5719361198)
assessed `d484e4b` on `11.138.0.19` through both routes. It observed empty `size`
strings for zero-byte files and empty `uid`/`gid` strings for root-owned entries
in information and flat listing responses. Six narrow field unions now preserve
those exact empty strings alongside the existing integer contracts; canonical
integer-string size conversion remains lossless. Other integer fields and the
unobserved `dirs`/`files` object rows remain unchanged. Independent fixtures cover
empty versus zero/omission, mixed listing rows, malformed values and all envelopes.

The reviewer also observed omitted/null save content truncate an existing file
from eight bytes to zero with a successful response. Generated notes, README and
agent guidance now state that destructive behavior explicitly. Listing guidance
leads with the observed flat list and explains the named-list alternative without
claiming it was observed. Stale shape-preservation claims are corrected.

A separate private metadata inspection issued exactly two guarded GETs for the
existing `npr` mail directory. Both routes returned 13 visible entries and agreed
on one existing zero-byte file; no root-owned candidate row was present. No file
content was read and no resource was created or changed. The selected path stays
private and is not part of committed structural evidence. Root-owned compatibility
remains attributed to the reviewer; no additional empty fields are inferred.
The unobserved all-optional object branch and its strict rows stay in `8x0.2.24`.

[Claude comment 6535026613](https://github.com/hostingpal/whm-api-client/pull/37#issuecomment-6535026613)
subsequently compared root-owned files and directories with ordinary rows through
both routes on `11.138.0.19`. Only `uid` and `gid` changed from integers to empty
strings; other fields kept their normal types, and the reviewer removed the
throwaway directory. This independently confirms the narrow correction scope;
it is reviewer evidence, not part of our read-only capture.

The final corrected code passes 18,027 tests / 126,265 assertions, PHPCS, maximum
PHPStan, strict Composer validation, Go tests/vet and the offline source/map/
generation gate. Independent review of the seven-GET zero-byte catalog confirmed
exact URI/account confinement, metadata identity/zero assertions, privacy and
unchanged shared helpers. No new live outcome is inferred from these offline
checks; the committed-code final capture is recorded separately.

### Final integrated Fileman review capture

The immutable [review capture](evidence/2026-10-30-fileman-review-final.json) binds
`06001e0cab782c7bd46d7d0d1f659b0c3f7ebc7d` on `11.138.0.19`, SHA-256
`dfc4899b5e88df167ee361a081f5e682b54f20dd5005603023184ad98057993d`.
All seven GET observations pass HTTP 200, API outcomes, payload validation,
probe expectations and strict assessment with zero discrepancies. They cover WHM
version plus autocomplete, the existing selected zero-byte file's information
and its mail-directory listing through both routes. Information responses expose
`size` as an empty string through both routes. The recipe verifies exact file
identity and the zero marker in each information/list response without persisting
the selected path. No file content was read and no resource was created or changed.

This capture observes three of the thirteen generated Fileman functions through
both routes. It establishes no new filesystem mutation or permission-enforcement
coverage. Root-owned uid/gid forms remain supported by the reviewer's separate
observations, not inferred from ordinary owner fields here. Every earlier JSON
artifact from both merged parents is byte-identical, including failed captures.
The evidence commit changes only documentation/artifacts; executable code and
effective contracts remain those of the captured revision.

## Resource and web statistics reads (2026-10-31)

The resource and web statistics slice exposes eleven GET operations across
`bandwidth()`, `resourceUsage()`, `statsBar()`, `stats()` and `statsManager()`.
Bandwidth protocols, retention and grouped queries complement resource limits,
statistics-bar values, domain bandwidth, daily AwStats counters, error logs,
report-file inventory and analyzer configuration. `StatsManager/save_configuration`
requires a JSON request body and remains excluded under `8x0.1.18`; generic form
calls do not provide that transport.

Pipe-separated grouping, domain, protocol and display arguments are supplied as
strings and encoded once. Omitted options preserve server defaults; explicit
false flags are sent as zero. Keep bytes, timestamps, per-resource units and
formatted display strings distinct. Resource `maximum: null` means unlimited;
zero and omission remain separate. Dynamic domain/date/grouping keys remain keys
in returned arrays and are wildcarded in structural evidence. The pinned
Bandwidth grouping prose and response examples establish one to three dynamic
key levels ending in integer or fractional byte values. The overlay repairs the
formal schema's object-only leaves while retaining nullable second/third values
and empty maps; numeric strings and opaque fourth-level leaves are not inferred.
The result shape does not enforce correspondence with the supplied grouping.
Resource maximum/usage, StatsBar percent and bandwidth month-start values accept
lossless canonical integer strings only in their reviewed fields. A null
resource URL remains distinct from omission; fractional strings are not rounded
or silently coerced. StatsBar preserves observed null `count` values and string
or object `role` values whose dynamic values are strings or string lists. The
role map follows the final structural capture; it does not identify the map keys
or their meaning. Other percentage and flag rules stay unchanged.
`listSites()` normalizes only exact quoted `ssl` flags to integer 0/1, preserving
omission and the native flags. These field corrections follow the
[PR38 reviewer observations](https://github.com/hostingpal/whm-api-client/pull/38#pullrequestreview-5719939412)
on cPanel `11.138.0.19`; they do not establish every display or analyzer variant.
The live/error-log follow-up remains `8x0.2.25`.

Statistics require the documented features, roles and analyzer availability.
`getSiteErrors()` returns log text as payload data; report URLs are returned
without being fetched. Analyzer configuration metadata stays in `responseMeta()`.
The fixed live catalog targets only `npr`/`npr.rs`, bounds the bandwidth query to
October 2026 UTC, and limits the error-log read to one entry. No configuration,
statistics generation or log contents are changed by these reads.

On `test.oblak.host` with cPanel `11.138.0.19`, the reviewer found that
`getSiteErrors(log: 'error')` and the default failed because `npr` could not read
the root-owned mode-0600 `/var/cpanel/cpanel.config`. The same operation with
`log: 'suexec'` returned populated typed rows on both routes. This is an attributed
environment observation, not a general API restriction. The server file was left
unchanged and the earlier failed artifacts remain failed and unwaived.

All formal optional fields and dynamic maps are covered by independent literal
fixtures and exact-wire tests on wrapped/bare direct and bridged UAPI. The
source's grouping prose and time-only example disagree, and its minute-interval
name contains a typo; no guessed combination validation is added. StatsBar's
example-only `langkey` remains unmodeled. Daily-stat key prose and examples also
differ, so no date-key grammar is enforced. Pinned snapshots and existing
runtime, generator and evidence policies remain unchanged.

### Initial resource/statistics capture

The immutable [initial capture](evidence/2026-10-31-resource-statistics.json)
at `fb4f9c9eb03e094d89a41806e927c4cbf0c60432` on `11.138.0.19` records 23 GET
observations. All return HTTP 200; 21 API outcomes succeed, fourteen payload and
probe checks pass, seven payload checks fail, and two error-log requests fail
at the API layer. Both `Stats/get_site_errors` calls use the documented `log=error`
and `maxlines=1`; their failure does not establish a log-row contract. No
configuration or log content is changed. Follow-up `8x0.2.25` retains that gap.

The two-level Bandwidth query returns integer leaves at `$.*.*` on both routes.
ResourceUsage reports numeric strings for `maximum` and `usage`, and nullable
`url`, on both routes. StatsBar reports numeric-string `percent` on both routes.
Direct Stats bandwidth reports integer and numeric-string `month_start`; the
bridge reports native integers. These are structural classifications, not raw
values or proof of a particular numeric-string spelling. Preserve the original
failed artifact, SHA-256
`6114814a2c39e52080d34c90cf0790380c16b86d3dca87677cb00fea50a407f3`.

### Corrected resource/statistics capture

The immutable [corrected capture](evidence/2026-10-31-resource-statistics-corrected.json)
at `a439c3c7791a16207437911c4dda93ee60105ac0` on `11.138.0.19` records all 23 GETs
with HTTP 200. Twenty-one API, payload and probe checks pass with zero schema
discrepancies. Both `Stats/get_site_errors` API requests still fail before payload
validation, so the overall strict assessment remains failed; neither failure is
waived or reclassified as a skip. The selector and bounds follow the pinned
source, which supplies no diagnosed cause. Follow-up `8x0.2.25` remains open.

The successful checks establish canonical integer-string compatibility for the
selected resource rows, percentages and direct month-start timestamps, and
integer leaves for the selected two-level bandwidth query. They do not establish
fractional percentages or other grouping depths. Daily-stat maps, site lists,
report-file lists and analyzer lists are empty, so their populated schemas remain
offline-tested. Ten of the eleven new functions pass through both routes; error-log
payloads remain unobserved. No settings, analyzer execution or log mutation ran.
The original capture stays unchanged. Corrected artifact SHA-256:
`904c85e93c2774646f68ddbbf38685355164f6bec9c1f19bc8e3d72e96438521`.


### Requested varied resource/statistics capture

The immutable [review capture](evidence/2026-10-31-resource-statistics-review-final.json)
at `294b1cc432bd9a9929a49cade5b2425297c89802` on `11.138.0.19` contains exactly
27 GET observations. All HTTP statuses are 200 and all API outcomes pass;
25 payload/probe checks pass. The three Bandwidth query depths each pass on
both routes with integer leaves. Analog site inventory has populated rows,
with direct numeric-string and bridged integer SSL flags, and `suexec` returns
populated typed log rows on both routes. Log contents are not retained.

Both mixed StatsBar observations fail payload/probe validation at `role`: the
raw structure is `missing|object|string`, with map values `string|list<string>`.
The first review correction interpreted the review's PHP "array" description as
a JSON list. This artifact disproves that assumption. The narrow subsequent
correction replaces that nonempty-list alternative with a bounded map, retains
scalar strings and omission, and adds independent malformed-value and masking
regressions. Existing empty-map compatibility remains unchanged. This correction
is generated and offline-tested; it was not used by this capture.

Both StatsBar observations also retain a separate masked `$[].*` unknown-field
failure (direct string, bridged null/string). Masking establishes neither key
names nor counts; no fields or acknowledgments are inferred. The artifact stays
strictly failed and unwaived under `8x0.2.25`. Empty daily/report/analyzer results
do not establish populated variants. No server file, configuration, resource or
log content changed. Earlier captures remain byte-identical; this capture is
not replayed to clear its status. SHA-256:
`c3c7ac8302133d4d92212f4e47d6455b95e60710adcb04209cf485e5690aed90`.

The dedicated catalog uses fixed argument-variant labels for its three bandwidth
depths while retaining each pinned `#GET` variant and the exact URI guard. The
shared read-only assessor checks ordered labels and rejects missing, extra,
duplicate or mixed labeled/unlabeled identities. Unlabeled existing catalogs keep
their evidence format; no discrepancy acknowledgment policy changes.


### First capture of the reviewed role-map contract

[Claude's review of `023d2dd`](https://github.com/hostingpal/whm-api-client/pull/38#pullrequestreview-5720098872)
verified the corrected code, including all 46 StatsBar display keys (28 populated,
18 empty) through both routes, then requested one capture of the final contract.
The immutable [reviewed-contract capture](evidence/2026-10-31-resource-statistics-reviewed-contract.json)
at `023d2dd00f4aee6a6ab06523944c48586f6dc77f` on `11.138.0.19` contains 27 GETs:
one WHM version read and thirteen reads through each UAPI route. All 27 HTTP,
API, payload and probe checks pass, including the mixed StatsBar list and its
role map. No extra request, mutation or log-content persistence occurred.

Strict assessment remains failed solely for the two StatsBar `$[].*` unknown-field
observations. Their full masked payload fingerprints match the preceding failed
capture on their respective routes (direct string; bridge null/string); the prior
role type mismatch is absent under the corrected contract. That structural match
does not identify unknown names or counts. Both findings remain unmodeled and
unwaived under `8x0.2.25`; the earlier artifact retains its original failures.
Daily/report/analyzer collections remain empty. The capture covers the fixed
catalog, not every possible argument or display combination. SHA-256:
`9f3548970abc522615faa8822cac7aa8bd28a576cfd1047b4849be056ebba736`.

## DNSSEC and Dynamic DNS (2026-10-31)

The DNSSEC and Dynamic DNS groups cover all seventeen pinned functions.
`dnssec()` exposes key lifecycle, DNSKEY/private-key export, DS records and NSEC
settings; `dynamicDns()` exposes creation, inventory, ID replacement, description
updates and deletion. DNSKEY/DS reads and Dynamic DNS inventory use GET; the other
fourteen methods use form POST, including private-key export.

DNSSEC requires the DNS role and retains each method's version and registrar
requirements. Disabling DNSSEC destroys its keys and requires registrar record
removal; the client provides no retry or rollback. Dynamic domain/key maps retain
their returned keys, optional fields and per-domain results separately from the
API envelope. Single-domain calls use the documented `domain` argument; the
`domain-0`/`domain-1` family remains tracked under `8x0.1.21`.
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

The original pinned OpenAPI schemas misplace `additionalProperties` inside
`properties` for several dynamic domain/key maps. Explicit return-name prose and
response examples establish those maps; operation-specific overlay replacements
repair the malformed nodes without changing the pinned source or inferring a
literal field named `additionalProperties`. The fetch-DS example also places
`nsec_details` under keys while the formal schema declares it beside keys; the
formal sibling location remains the contract. Reviewed quoted-number examples
support only the individually annotated fields. All other field types retain
the source boundary.

### DNS inventory capture

The immutable [DNS security capture](evidence/2026-10-31-dns-security.json) at
`372df7834484b8ff696d677e764f3b114a1ed2ac` on `11.138.0.19` passes all five GET
observations: WHM version and both `DNSSEC/fetch_ds_records` and `DynamicDNS/list`
through direct UAPI and the WHM bridge. HTTP/API/payload/probe checks and strict
assessment pass with zero discrepancies. The DS result contains an empty object
for the selected domain, with `keys` and `nsec_details` absent; DDNS inventory is
an empty list. This establishes neither populated key/DDNS row schemas nor the
example-based quoted-number variants. No key, record, registrar or application
state changed, and no update URL was fetched. The other fifteen methods have no committed lifecycle capture; follow-up
`8x0.2.26` retains that evidence work. SHA-256:
`70811e4a21e6be51745f22738020410062ad98cc756539265ea8003c576c73e6`.


### DNSSEC and Dynamic DNS external review corrections

[Claude's review of `476d418`](https://github.com/hostingpal/whm-api-client/pull/39#pullrequestreview-5720009405)
independently exercised the full lifecycle on `npr.rs` through both routes. The
report establishes populated numeric-string variants for NSEC details, key
creation times and digest algorithms, export key IDs, and add/import key IDs.
The existing example-backed conversions for NSEC and creation times remain;
the correction adds digest/export conversions and returns integer add/import
IDs for strict typed chaining. Malformed, overflow, null and omission handling
has independent regression coverage. `nsec_details` remains beside `keys`, and
`nsec3_narrow` and DDNS deletion flags remain integers.

The reviewer reports restoring the zone to unsecured/no keys, verifying its
file hash against baseline and removing every DDNS test entry. These are
attributed reviewer observations, with no server version supplied in the review,
and are separate from the immutable five-GET artifact. They do not turn that
empty-zone capture into populated or mutation evidence. The final integration
capture retains the bounded five-GET recipe; DNSSEC enablement remains outside
that recipe, as permitted by the review. No lifecycle is replayed for evidence.


### Final integrated DNS inventory capture

The immutable [reviewed DNS capture](evidence/2026-10-31-dns-security-reviewed.json)
at `71e31f15379fd63a7ee19015421405cd2a93d1cc` follows normal integration of PR38.
On cPanel `11.138.0.19`, all five GET observations pass HTTP/API/payload/probe and
strict checks with zero discrepancies. DS results again contain an empty object
for the selected domain, with `keys` and `nsec_details` absent; both DDNS lists
are empty. This verifies the integrated read path, not the populated numeric
fields, private-key exports or lifecycle mutations. Those remain covered by
independent offline tests and the separately attributed reviewer report.

The bounded recipe intentionally performs no DNSSEC enablement, registrar, key,
record or DDNS change, as permitted by review 5720009405. No update URL was fetched.
The initial artifact and every earlier evidence file remain unchanged. Remaining
committed populated/mutation evidence stays under `8x0.2.26`; no further read-only
capture is outstanding from this review request. SHA-256:
`8be0fe648505873d0f36221e0ef750954b5268469399ac2de804ef8c1e5d3b56`.

## Repository deployment and Passenger applications (2026-10-31)

The repository, deployment and Passenger groups cover fourteen pinned functions.
`versionControl()` manages cPanel Git repositories, `versionControlDeployment()`
starts and inspects deployment tasks, and `passengerApps()` manages Application
Manager registrations. Passenger inventory uses GET; the other thirteen methods
use form POST, including repository and deployment retrievals whose source marks
them `x-readonly=false`.

Repository `sourceRepository` is caller-supplied JSON text, sent once as a form
parameter. The client does not JSON-encode a PHP object or decode/re-encode the
string. The existing scalar parameter boundary is `string|int|null`; supply the
documented serialized JSON string, or omit it. Clone URLs may contain credentials,
so this argument stays sensitive. The pinned source warns that repository deletion
permanently removes directory contents. The PR40 reviewer instead observed
registration deletion leaving the directory on the test server; retain the
source's destructive-operation warning rather than relying on that discrepancy.
Updating a branch can pull remote changes;
source URLs cannot be changed by the update method.

Deployment requires a clean working tree, branches and a checked-in `.cpanel.yml`.
Returned task IDs and event/log URLs do not prove completion and are not fetched
by the client. Reviewed integer deployment IDs are preserved, and `delete()`
accepts the returned integer or documented string identifier. A status-success
`create()` response with explicit null was observed for a non-deployable repository
with no deployment queued; absent data remains a mismatch. A payload error after
queueing does not undo the deployment and must not trigger a retry. Creation's
unspecified `last_update` schema includes a null-only
enum but remains mixed under the current effective validator; no stronger field
contract is invented.

Passenger registration requires an existing application; unregistering it does
not delete its files. Environment names and values use matching repeated fields;
supply one value per name. Editing environment variables replaces the current
set. Omitting `enabled` when editing uses the documented default of 1 and can
re-enable a disabled application; pass false explicitly to retain disabled state.
Values remain sensitive, and opaque returned environment objects preserve
their fields. Inventory `enabled` has an unspecified schema and remains mixed;
mutation flags retain their declared types. `ensureDeps()` starts asynchronous
installation. These methods manage Application Manager; the pinned source
recommends WebApp for applications deployed with that newer feature.

The bounded live catalog reads only WHM version and Passenger inventory on both
routes. Repository/deployment POST reads, mutations and populated/secret-bearing
variants remain tracked under `8x0.2.27`. No deployment, dependency installation,
repository deletion or application configuration change is part of that catalog.
The reviewer diagnosed its Passenger API failures: `npr` lacks the `passengerapps`
feature and the server has no Passenger packages. No server-wide installation was
made. All seven Passenger methods remain unobserved, including dependency-result
and mutation flag/environment variants. The failed capture remains unwaived.

Request encodings start from the pinned source; reviewed response corrections
are recorded separately below.
`source_repository` declares parameter-level JSON content, not a JSON body;
explicit scalar encoding preserves pre-serialized text. Passenger form/explode
array declarations establish repeated environment fields. The edit value field
omits `explode`, whose form-style default is true; registration states it
explicitly. Transport and evidence policies are unchanged. Optional opaque mutation objects preserve null/object/missing
distinctions; source-specific differences in nullable branches and deployment
fields are covered by independent literal fixtures.

### Application inventory capture

The immutable [application-management capture](evidence/2026-10-31-application-deployment.json)
at `344fffc64824bd35098d232dd92226cb222d2a15` on `11.138.0.19` records three GET
observations, all HTTP 200. WHM version passes. Both `PassengerApps/list_applications`
requests fail at the API layer with null payloads, so payload validation is not
performed and strict assessment remains failed. The sanitized evidence does not
diagnose the capability/configuration cause; neither failure is waived or treated
as a successful empty inventory. All fourteen new methods lack successful payload observations in this committed
capture; later reviewer lifecycle observations are recorded separately below.

No repository, deployment, application configuration or dependency installation
changed, and no returned URL was fetched. No contract was broadened from an API
failure, and no repeated capture was run merely to seek a passing status. SHA-256:
`48b5b71e8feb9107310383e50f311f39d99301a3fb57c4179a00d12c97e44a64`.


### Repository/deployment external review corrections

[Claude's review of `9aa3a6c`](https://github.com/hostingpal/whm-api-client/pull/40#pullrequestreview-5720064601)
used throwaway repositories with a committed `.cpanel.yml` containing only
`/bin/true`. The reviewer observed repository creation/update/deletion through
both routes and reports complete cleanup to zero repositories and deployments.
Full repository retrieval failed for nullable `last_deployment`, digit-string
`deployable` and numeric `last_update.date`. Queued deployment creation/retrieval
failed on integer identifiers/dates and fractional timestamp fields. A separate
non-deployable create returned status success and explicit null without queueing.
[The reviewer subsequently clarified](https://github.com/hostingpal/whm-api-client/pull/40#issuecomment-6536614693)
that every result in the original review and the corrected lifecycle at
`7b9f1f93ead92504052a31bffd452ade64f9a208` used cPanel `11.138.0.19` through both
direct UAPI and the WHM bridge. Corrected default and all-field repository reads,
updates, explicit-null no-queue creation, populated deployments, post-deployment
repository state and integer-ID deletion passed. All test repositories and
deployments were cleaned. These attributed reviewer observations do not expand
the initial GET-only artifact's coverage.

Corrections preserve nullable last-deployment state, narrow flag/integer-string
conversions, integer deployment identifiers and observed numeric timestamps while
retaining documented strings. Only the named queued/active/succeeded timestamps
receive numeric alternatives; canceled/failed variants remain unobserved. The
source's update-branches prose establishes a nullable list with non-null members,
repairing its misplaced item-level nullable flag. Repository creation's opaque
`last_update` schema remains mixed rather than inventing fields.

The generator supports an evidence-gated scalar string-to-`string|int` parameter
correction for deployment deletion and a parameter description replacement for
its identifier reference and Passenger's enabled default. Other parameter types,
wire encodings, optional omission and source snapshots remain unchanged. Literal
regressions cover response shapes, strict typed identifier chaining and malformed
inputs. The GET-only capture is not repeated: none of the corrected repository
or deployment contracts were exercised by it, and the reviewer explicitly requires
no new read-only capture. A future guarded POST-read recipe, remaining timestamp
variants and the directory-deletion discrepancy stay under `8x0.2.27`.

## Account environment and preferences (2026-10-31)

The account environment and preference slice exposes twenty compatible methods
across ContactInformation, Contactus, Locale, Themes, Notifications,
AccountEnhancements, EA4, ServerInformation and Variables. Fourteen reads use GET;
six secret-bearing or state-changing operations use form POST. The JSON-body
`ContactInformation/set_notification_preferences` operation remains excluded under
`8x0.1.18`, and the deprecated theme-base operation remains excluded.

Contact addresses use documented repeated form fields. The setter requires the
new addresses and password; omit `oldAddresses` only when the account has no
contacts. Existing contacts still require their complete old-address list to pass
the server race check. Unset keeps that list and password required. Passwords and
Pushbullet tokens are sensitive parameters. The token getter also uses POST and
returns sensitive payload data. Contact mutations with no documented data return
void; locale setting preserves its present unspecified payload, while theme update
preserves nullable opaque objects and rejects an absent payload.

Theme options preserve omission and explicit false. Enhancement availability data
is separate from UAPI status: a successful data value of zero is not an API error.
The enhancement-list source omits its container type and has inconsistent object
prose/items structure, so the result remains mixed rather than acquiring an
invented list contract. Notification preference rows retain the suspicious literal
`additionalProperties` source property without inferring a dynamic-field schema.

Variable reads preserve optional fields and nullable values. User/server name
selectors accept a string or repeated string list; the session selector stays
scalar. Source prose/examples establish dynamic feature-flag and domain/MX maps
where the formal schema misplaced `additionalProperties`; the dynamic-map repair
is limited to those field nodes. Server/session/user fields and opaque values are returned as data;
recommendations do not install packages or change configuration.

The 32 observed server flags accept exact quoted `0`/`1`; all eight server integer
quantities accept canonical lossless integer strings, with six value-observed and
two source-inferred. User integer/flag fields and feature flags have the reviewed
family compatibility described below; existing limit-string alternatives remain
unchanged. Contact email fields preserve explicit null, empty string and omission.
Session host additionally accepts null, observed through the bridge. Only outer EA4 recommendation URLs accept `bool|string`;
option URLs stay strings. Boolean URL values are payload data with no inferred
sentinel meaning. Unknown fields remain untyped, and failed EA4 calls do not
establish a successful response contract.

The live catalog selects twelve parameterless reads and the user-domain read
`getUserInformation(name: 'domain')` through both routes, plus WHM version (27 GETs).
The user selector requests only the documented domain field; the unfiltered user
read can include `pushbullet_access_token` and is excluded from this catalog.
Enhancement lookup by ID and token retrieval are also excluded. No contact, locale,
theme or preference settings change. Other user-field live coverage, successful
payload variants and mutations remain tracked under `8x0.2.28`; structural captures
never persist tokens, addresses or server/session/user values.

The initial [account-environment capture](evidence/2026-10-31-account-environment.json)
at `e7b14c3a2ec8b905f6f4fc0b6443538dea02d7e6` on `11.138.0.19` records 27 HTTP
200 GETs: twenty API/payload/probe passes, four payload mismatches, two API failures
and one bridge request failure. Its 83 discrepancies comprise 78 type mismatches
and five masked unknown-field entries. Thirty-two server flags and six server
integer quantities arrive as numeric strings on both routes; the bridge session
host is null; direct EA4 recommendation URLs include booleans alongside strings.
These observations support only those field corrections. The unknown preference,
recommendation and server fields remain untyped and unwaived. Both PHP-recommendation
reads fail at the API; the other EA4 bridge response has `data.uapi: null`, so no
UAPI payload is available. The capture establishes no cause for these failures.

The domain-selected user requests return only `domain`; other user fields, including
`pushbullet_access_token`, are absent. Locale, theme, preference and server-information
rows are populated; the enhancement list is empty. This read-only capture establishes
no contact, locale, theme, preference or token mutation behavior. Follow-up `8x0.2.28`
retains all failures, unknown fields and unobserved variants. Original SHA-256:
`e51005094c0ac52c469b0c8e6535291f0fea0d3a07c6fa782679a7d22d5225cd`.

The [corrected account-environment capture](evidence/2026-10-31-account-environment-corrected.json)
at `c85a39ef45f108bfef0264eb3a2e01c5167761a6` on `11.138.0.19` records 27 HTTP
200 GETs. Twenty-four API/payload/probe checks pass, with no known-field type
mismatches. Both PHP-recommendation API calls and the general recommendation
bridge call still fail. Five masked unknown-field observations remain: notification
preferences on both routes, direct general recommendations and server variables on
both routes. Strict status remains failed with no waivers or acknowledgments.
Eleven new functions pass on both routes; general recommendations pass directly.
The enhancement list is empty and the user read is domain-only. No mutation,
secret retrieval or broader user-field behavior is established. Follow-up `8x0.2.28`
remains open. Original evidence is unchanged. Corrected SHA-256:
`654442a1e360c28534c4b80c320ffdde2ca0d96ffdd87f8342fd1b6b47a3eb78`.

### Account-environment external review corrections

[Claude's review of `a361c45`](https://github.com/hostingpal/whm-api-client/pull/41#pullrequestreview-5720241923)
identified two failures outside the original domain-only user capture: unfiltered
user information rejected valid wire values, and the typed contact setter could
not supply an account's first contact address. The reviewer reports cPanel
`11.138.0.19` through direct and bridged routes, reads on `npr`, and contact/theme/
locale/token checks on disposable `wrvenv`, whose removal was verified after each
run. These are attributed reviewer observations, separate from committed captures.

For user information, the reviewer traced results through `%Cpanel::CPDATA` and
`%Cpanel::USERDATA` without numeric coercion. The same `gid` was an integer directly
and a digit string through the bridge. The reviewed compatibility family therefore
covers canonical lossless integer strings in every integer/flag field and feature
flag value for this operation. Native integers and omission remain unchanged;
flags still accept only zero and one. Unobserved siblings are source-based
compatibility inferences, not additional live observations. Existing `maximum_*`
string alternatives remain available; no new closed enum enforcement is inferred.
Contact email fields additionally preserve the null states observed after a
one-address set or complete unset, separately from empty strings and omission.

Server information's two remaining nullable integers follow the same documented
configuration-copy path as its six corrected siblings. Their numeric values were
not observed on this server, and no server-wide configuration was changed to
manufacture them. Repeated-name selection for user/server information follows
reviewed source `get_multiple('name')` and both-route selection observations;
session information keeps its scalar selector. The [full reviewer mapping clarification](https://github.com/hostingpal/whm-api-client/pull/41#issuecomment-6536895626)
records all eleven skip/disable source-key mappings, including `skipbwlimitcheck`
to `skip_bandwidth_limit_check`. Its pinned statement that 1 suspends HTTP service
also conflicts with the skip-key meaning. The overlay records each exact mapping
and preserves flags without inversion; only source mappings were inspected, not
runtime behavior or enforcement.

The first-contact setter now permits omitting `oldAddresses` only for an account
with no existing contact addresses. The reviewer observed successful explicit-null
results in that state; omission with existing addresses still fails the server's
race check. The unset method retains a required complete old-address list. Wrong
password/list failures and single-request/no-retry behavior remain intact.

The reviewer attributes both PHP-recommendation API failures to an absent
`/etc/cpanel/ea4/recommendations/custom_php_recommendation.json`; the API exception
is retained. The general-recommendations bridge failure remains a successful WHM
envelope containing null UAPI data, correctly rejected, with no established cause.
Unknown findings remain unwaived and explicitly nonblocking. Reviewer-observed
notification preference rows contain `onchangeparent`, `type`, `infotext`, and
`value`, with no literal `additionalProperties`; those unmodeled fields remain
follow-up `8x0.2.28`, not a new typed schema inferred from masked historical keys.

The new bounded review recipe selects 60 pinned user fields confirmed present on
`npr` by [review 5720313728](https://github.com/hostingpal/whm-api-client/pull/41#pullrequestreview-5720313728),
plus WHM version. Its fixed list excludes `pushbullet_access_token` and seven
pinned fields absent on this account: `lang`, `maximum_defer_fail_percentage`, `maximum_emails_per_hour`, `notify_bandwidth_limit`, `notify_ssl_expiry`, `notify_twofactorauth_change`, `notify_twofactorauth_change_notification_disabled`.
An absent scalar or list selector fails the entire request; the seven fields
remain client-supported, with no wire-form claim from this capture. It requests no token,
performs no mutation, and will capture only sanitized structure at the reviewed
corrected revision. This paragraph describes the recipe, not a completed run.
The original account-environment artifacts keep their original failures and hashes.


### Final account-environment review capture (2026-10-31)

The final [reviewed user-information capture](evidence/2026-10-31-account-environment-reviewed.json)
at `c4576669980a1ab22952f96b976a34bf0db7be58` on `11.138.0.19` passes all three
GET observations: WHM version and the fixed 60-field user selector on both routes.
All HTTP/API/payload/probe checks and strict assessment pass with zero discrepancies.
The token selector and seven pinned fields absent on `npr` are excluded; no wire
form is established for those seven. No mutation or token retrieval ran. Earlier
EA4 failures and masked findings remain immutable and unwaived under `8x0.2.28`.
Reviewer mutation/lifecycle reports remain separate from this committed read capture.

Artifact SHA-256: `e191517fda7b8ec3a4eb8bfd4df75d75d831cebc917d739e63a8f96a7744b5e4`.
The single run follows [Claude review 5720313728](https://github.com/hostingpal/whm-api-client/pull/41#pullrequestreview-5720313728)
after its three corrections. The allowlist is fixed in source, never derived from
live responses. All 60 prior JSON artifacts and 24 pinned-source files are unchanged.
The correction passed 31,127 offline tests / 233,646 assertions plus formatting,
maximum PHPStan, strict Composer, source/map/generation and full Go tests/vet.
No settings or resources were changed, so no mutation cleanup was needed.

## WHM backup and account restoration (2026-10-31)

The WHM backup and restoration groups expose 42 methods: 26 in `backups()` and
all sixteen in `accountRestoration()`. Fourteen explicit source reads use GET;
28 operations use form POST, including inventory-like backup methods whose pinned
`x-readonly` value is false. `backup_destination_set` remains excluded under
`8x0.1.22`: its composed provider-specific form schema is not supported by the
current generator, and its JSON examples conflict with the formal form content.
Generic compatible form access does not establish typed destination-update coverage.

Destination creation requires name, type and disabled; provider-specific inputs
are optional in the PHP signature only where pinned prose makes their requirement
conditional. Supply the fields required by the selected provider. Credentials,
private keys, passwords and OAuth secrets remain sensitive. Returned secrets and
OAuth URLs are payload data; the client does not fetch them. Archive and staging
paths refer to server-side files, not local uploads.

Omitted settings retain server defaults; false and zero are sent explicitly.
The backup setter's `keeplocal` prose contradicts the getter's retention meaning;
the client forwards the flag without inversion. `backupMonthlyDates` accepts
an integer or a comma-separated string, following the pinned prose and reviewed
upstream validator; the weekly day remains a single integer. Migration's
`no_convert` name conflicts with its positive conversion prose; the flag is
forwarded unchanged. `backup_set_list.backup_set` preserves a list of user/date
rows. A combined listing can have a null destination legend, and remote `where`
values may be transport IDs rather than the pinned `remote` label.

The two pkgacct session GETs were observed to create an empty server directory
before failing for an unknown session ID. Use only IDs returned by
`startBackgroundPkgacct()`; other input forms were not tested. These observations
come from [Claude review 5720277974](https://github.com/hostingpal/whm-api-client/pull/42#pullrequestreview-5720277974),
not the committed GET capture. Remaining coverage stays in `8x0.2.29`.

Backup configuration and strict retention can affect stored archives. Packaging
and restoration can overwrite account content; restoration may delete its source
archive when requested. Queue activation acts on queued accounts, while each
clear method documents the tasks it removes. Restricted restoration carries the
source's experimental limitations. Session identifiers do not establish completion.
The client does not retry or roll back these operations. Metadata-only operations,
including username conflict verification, return void and use API errors for
failure. Completed queue records preserve nullable counts/warnings, integer result
codes and string timestamps; omitted fields remain absent.

All 42 methods have offline contract coverage. The fixed live catalog selects WHM
version, four parameterless backup reads and six restoration status/module reads
(11 GETs). It excludes destination credentials, OAuth requests, session logs,
backup creation, account restoration, queue changes, migration and configuration
writes. Populated variants and all remaining live behavior stay in `8x0.2.29`.

The initial [backup/restoration capture](evidence/2026-10-31-backup-restoration.json)
at `88e01bd54f1939e4937fd3673f2b174a6353e7ab` on `11.138.0.19` records eleven
HTTP-200 and successful WHM API observations. Ten payload/probe checks pass;
`backup_config_get` has three known-field type mismatches and one masked unknown-field observation with integer values. The getter's `backup_weekly_day` is an integer; its original string
contract is retained alongside that observed variant. Only `check_min_free_space`
and `prebackup` gain exact quoted-flag acceptance. No other configuration field
is widened and the unknown field remains untyped and unwaived.

Backup metadata users/domains, archive inventory and every queue collection are
empty. Restoration module rows are populated. These reads establish no populated
backup/queue rows, destination behavior, backup creation, restoration, migration,
queue mutation or configuration-write semantics. All original findings remain in
`8x0.2.29`; source contradictions are unresolved. Initial SHA-256:
`4b788b00b69944d305473bd89004e6199914cf2b366ed90676b3313aac32daca`.

The [corrected backup/restoration capture](evidence/2026-10-31-backup-restoration-corrected.json)
at `a5b6e3a959185702d9e2146dc6323e0c69ec3cdf` on `11.138.0.19` records eleven
HTTP/API successes and ten payload/probe passes. The weekday integer and
`check_min_free_space` quoted flag now pass. `prebackup` still fails its strict
0/1 contract: the artifact classifies it only as numeric-string, which does not
establish its spelling or a valid flag value. The optional exact quoted-flag
acceptance does not make this response compatible. No broader numeric coercion,
new enum value or guessed sentinel is added. That capture also retains one masked unknown-field observation with integer
values; wildcard masking establishes neither its name nor field count. Strict status stays failed with no acknowledgments.

Both artifacts remain immutable. Empty inventories/queues establish no populated
row schemas; the module summary is populated. No mutation, backup creation,
restoration or configuration behavior is established. Follow-up `8x0.2.29` retains
the historical `prebackup` mismatch, masked unknown-field observation and
remaining source/live gaps. Corrected SHA-256:
`f910fcd3377c881e07d496fd727bec622016bd8df33552cfe381552752acde47`.


### Backup/restoration review corrections (2026-10-31)

[Claude review 5720277974](https://github.com/hostingpal/whm-api-client/pull/42#pullrequestreview-5720277974)
of `712e4b611d79ee065b96010acef56f262cd388fe` supplies new observations on
`11.138.0.19` and separately identified upstream source evidence. The getter
preserves the live exact `prebackup` string `-1` and the source-backed empty
string alongside documented flags; other strings and integer -1 remain invalid.
The actual optional integer flag `backupsuspendedaccts` is added while preserving
the documented `backupsuspendedaccounts` spelling independently. Neither correction
identifies a historical masked wildcard or changes an old artifact's assessment.

Source inspection and pinned row items establish the `backup_set_list` row list;
the two-row regression is offline evidence, not populated live coverage. Combined
listings preserve null destination legends. The monthly-date setter accepts
comma-delimited strings through a narrow evidence-gated scalar parameter override;
weekly day remains integer. Pinned examples supply optional `upload_system_backup`
and completed-task `status_info.status`/`statusmsg` fields. No server-wide setting
write establishes these corrections.

The reviewer observed pkgacct unknown-ID GETs creating empty directories before
API errors and removed those directories. Both methods now warn to use only IDs
returned by `startBackgroundPkgacct()`, and no such unknown-ID probes were added.
Unknown-user toggle success and idle skip-status API failure are documented as
bounded observations. Remote combined-list `where` values can be transport IDs.

The reviewer separately exercised disposable-account packaging and local restore,
then removed the account, archive and pkgacct directories and confirmed an empty
queue. Upstream transfer-session history under `/var/cpanel/transfer_sessions/`
and its `whmxfer.sqlite` row remain; this is not a claim of complete server cleanup.
No new live call ran during these corrections. Server-wide writes, populated
real-backup queue operations and unchecked transport/OAuth operations remain
unobserved. The original two GET artifacts stay failed, immutable and unwaived;
follow-up `8x0.2.29` retains remaining committed evidence and source/live gaps.

## Team and user management (2026-10-31)

The team and user-management section exposes 35 methods across `team()`,
`teamRoles()`, `userManager()`, `webDisk()`, `users()` and `passwdStrength()`.
Eight reads use GET; 27 state-changing or password operations use form POST.
Passwords remain sensitive parameters. Dotted service-field names are forwarded
literally, content is form-encoded once, and omitted options retain server defaults.
Explicit false, zero and empty values remain distinct from omission.

Team role operations can grant owner privileges. `setRoles()` replaces the whole
role set; omitting its role value removes all roles. Creation's `roles` field is
comma-separated, while role updates use singular `role`. Activation and password
reset requests can send email. Service changes can create, associate or remove
service subaccounts. Team creation without an expiry can return null; supplied
expiry results preserve integer timestamps with canonical numeric-string
compatibility. Other Team mutations preserve nullable opaque objects and reject
missing payloads. Each method retains its source version floor.

`listTeam()` preserves array and hash alternatives, including sensitive password
payload fields. The hash result uses dynamic username keys under `users`; source
inspection corrects the pinned literal `username`/`info` reading. Optional unset
date and last-login empty strings stay distinct from omitted fields. Team role
descriptions are a list of role records. These corrections are source-backed and
offline-tested; Team is unavailable on the reviewed server. Team counts and
nullable subaccount flags are payload values, separate from UAPI status.

User-manager operations preserve source service settings, merge candidates and
optional fields. The reviewed shared record family preserves null for unset
contact/profile fields and FTP quota, alongside pinned nullable parent-type,
subaccount-existence and synchronized-password fields. Operation-specific non-null
FTP quota types remain unchanged. Password-change success may return opaque text;
callers must not assume its content. Candidates preserve the known record shape,
with their own nested candidate lists kept as opaque lists. This finite boundary
retains deeper data without inventing recursive structure.
`deleteUser()` permits missing data only where the pinned prose expressly specifies
metadata-only completion; ordinary nullable-object mutations still reject missing
data. Required create-user passwords remain required. Web Disk deletion with
`destroy: true` can remove its home directory. The client does not retry or roll
back user changes, role grants, removals or password changes.

The fixed nine-GET live catalog reads WHM version, team role counts, role feature
descriptions, flat subaccount inventory and the `webdisk` password-strength policy;
each account read uses both routes. Team listings are excluded because their source
includes password fields. No GUID/service lookup identifiers are guessed, no account
is modified and no invitation, activation or reset email is sent. All remaining
methods and variants remain offline-tested under live follow-up `8x0.2.30`.

The initial [team/user-management capture](evidence/2026-10-31-team-user-management.json)
at `5cae92f5ef3dfdb4ff9e83b8ab06be6d17867677` on `11.138.0.19` records nine HTTP
200 observations. Version and both password-strength reads pass. Team counts and
role descriptions fail at the API on both routes, as does the bridged user list;
no cause is established. The direct user inventory succeeds at the API but has
seven known null mismatches: `alternate_email`, `avatar_url`, `domain`,
`invite_expiration`, `phone_number`, `real_name` and `services.ftp.quota`.
These observations support nullability only at those list-row paths. One masked
unknown-field observation with a null value remains untyped and unwaived. Strict assessment is failed.

The direct inventory is populated; it establishes no successful bridge payload or
team schema, and the team listing that includes password fields was not called.
No user, service, role or password changed and no message was sent. Follow-up
`8x0.2.30` retains API failures, the unknown field and unobserved operations/variants.
Initial immutable SHA-256:
`b0bbf85e9054d68f8a06eb984a63cdac76c81d9b9a2cf6cebb3e81c0d0c04116`.

The [corrected team/user-management capture](evidence/2026-10-31-team-user-management-corrected.json)
at `9922081314c6343fe65f9a6d978f354d0fde3c3c` on `11.138.0.19` records nine HTTP
200 observations. Four API/payload/probe checks pass: version, direct subaccount
inventory and both password-strength reads. The seven nullable field corrections
pass directly with no remaining known-field mismatches. Team counts and role
descriptions still fail on both routes, and the bridged user inventory still fails
at the API. One masked unknown-field observation with null values remains in the
direct inventory; masking establishes neither key identities nor counts.
Strict status remains failed, without waivers or acknowledgments.

This establishes populated direct inventory and password-policy reads only;
no team listing, password retrieval, user mutation or message sending occurs.
The original failed capture is unchanged. Follow-up `8x0.2.30` retains all API
failures, unknown-field findings and unobserved variants. Corrected SHA-256:
`3d16299d29f632b4874dd99c0e400f0301dd4a89d60a7a5ff60d49ab68d0a435`.


### Team/user review evidence boundaries (2026-10-31)

[Claude review 5720337990](https://github.com/hostingpal/whm-api-client/pull/43#pullrequestreview-5720337990)
reports direct reads on designated `npr` and disposable-account mutations on
`wrvenv`, with account removal confirmed after each pass on `11.138.0.19`.
These separate reviewer observations do not expand either committed GET artifact.
Successful create/edit/merge/password changes can take effect before payload
validation fails; callers must not retry them. The existing outcome-confirmed
exception and no-retry policy remain essential.

UserManager's bridge API error was attributed to absent `Cpanel/API/UserManager.pm`
while direct execution uses the compiled server. This is observed server/version
capability, not a universal direct-only API rule or a new client route restriction.
Unknown service-account lookup can raise an upstream Perl exception surfaced as
`ApiErrorException`; it is not a client payload-validation failure.

Team is unavailable on this server: `team_manager` is disabled and could not be
enabled as an account feature, while TeamRoles also checks the server `teams`
capability. Team list/hash, expiry and role-description corrections therefore
rest on attributed upstream source inspection and offline replay, not live success.
No Team feature installation or enablement is part of these corrections.

The reviewer separately identified `team_owner` null and, with WebDisk enabled,
`services.webdisk.enabledigest` integer as untyped fields. Those names do not
identify any historical masked wildcard; findings remain unwaived in `8x0.2.30`.
Populated conflict-account shapes and non-null FTP quota representation remain
unobserved/unresolved. The create-user string quota and other operation integer
quota contracts retain their separate documented non-null boundaries.
No further live capture has been performed or inferred by these corrections.


[Clarification 6537241960](https://github.com/hostingpal/whm-api-client/pull/43#issuecomment-6537241960)
provides a bounded candidate structure and attributes both serializers to
`Cpanel/UserManager/Record.pm:357-364`. One populated direct candidate was observed;
the printer capped objects at 24 keys, so that inventory is not exhaustive.
Dismissed candidates share the Record serializer by source inspection and were
not observed populated. Assignment call sites suggest empty deeper candidate
lists in practice but give no guarantee. The reviewed finite boundary therefore
types each candidate's known record fields and preserves its nested candidate
lists as `list<mixed>`, without claiming a recursive schema or discarding deeper
payload data. Optional service properties stay optional; `team_owner` remains
outside the typed contract and in the existing follow-up.

The same clarification supplies source-only Team types: version and subaccount
GUID are strings including empty strings; creation, last-login, expiry and
suspend timestamps allow canonical integer strings or integers and exact empty
strings. The reviewer did not trace AdminBin numeric serialization, so both
representations are compatibility, not live Team observations. No closed date
range or ten-digit runtime constraint is inferred from one source validator.

## Security and access controls (2026-10-31)

The security and access section exposes 24 compatible methods across `blockIp()`,
`modSecurity()`, `clamScanner()`, `knownHosts()`, `externalAuthentication()`, `ssh()`
and `lastLogin()`. Thirteen reads use GET and eleven mutations use form POST.
ClamScanner JSON disinfection remains excluded under `8x0.1.18`; generic form
calls cannot provide that body contract.

IP block inputs retain documented address, hostname and range spellings. ModSecurity
domain filters are caller-supplied comma-separated strings, encoded once; dependency
lists identify other affected domains. Per-domain exception fields preserve both
explicit null and omission. A failed call can still change some domains: re-read
`listDomains()` before deciding how to proceed. API status remains checked and no
mutation is retried or rolled back automatically.

ClamScanner preserves source scan types, administrator availability, byte/megabyte
units, integer counters and lossless integer-or-string start timestamps. `getScanPaths()` preserves its pinned object and observed list-of-records
alternatives; `getScanStatus()` also accepts the observed explicit null. Disinfection log alternatives use the established generator
merge, preserving common required `type` and all optional branch fields; no
per-discriminator validation is inferred. Scanning is a POST operation and does
not establish successful disinfection. The JSON disinfection operation is absent.

KnownHosts mutations change the selected account's SSH trust file. The reviewer
observed `create()` obtaining key material without a supplied key; this observation
does not establish network contact by `verify()`, which remains outside the fixed
capture. Its payload status is separate from the API status. Provider URLs/icons and public
key/fingerprint strings remain payload data, without fetching or rendering.
External account unlinking is a mutation. Subject identifiers preserve integers or
strings end to end, including strings larger than PHP integers. `removeAuthnLink()`
sends the provider argument as `provider_id`; successful unlinking is unobserved. SSH access is a
boolean capability, not proof of login. SSH ports normalize only canonical integer
strings, and last-login addresses preserve scalar-string and string-list alternatives.

All 24 methods have offline contract coverage. The fixed live catalog performs
25 GET observations: WHM version plus twelve parameterless scanner, ModSecurity,
external-authentication, SSH and last-login reads through both account routes.
No scan, disinfection, security-setting change, block change, external unlink,
host-key verification, login or remote URL fetch occurs. Empty inventories do not
prove populated row contracts, and API success does not prove security enforcement.
Remaining behavior is tracked under `8x0.2.31`.

The initial [security/access capture](evidence/2026-10-31-security-access.json)
at `91fce03c2cc0e24893d9c269b51a2c7d02644f0b` on `11.138.0.19` records 25 HTTP
200 observations: ten API/payload/probe passes, eight successful-API payload
mismatches and seven API failures. Both scan-path calls return lists of objects;
the original root mismatch masks their field names. The list alternative uses
the documented per-path `id`/`message` record and the source's available-paths prose,
which is a source-based item interpretation, not proof of named live leaf fields.
The formal object alternative remains supported. Both scan-status responses are
explicit null, both SSH ports are numeric strings, and both last-login responses
are scalar strings. Only those contract alternatives gain compatibility.

Both ModSecurity methods fail on both routes, and all three external-authentication
reads fail through the bridge. The capture itself establishes no cause, and no
failure is waived. No scan,
disinfection, security-setting change, unlink, host-key action or login occurs.
Follow-up `8x0.2.31` retains these errors and every unobserved variant. The original
capture remains failed and immutable. SHA-256:
`4697e246b36b465efa814273d583d2c50c72d795352f3205eba0d983ac14ea77`.

The corrected [security/access capture](evidence/2026-10-31-security-access-corrected.json)
at `aee24c1081727cd66b8ef86aced5bd27d7e83375` on `11.138.0.19` records 25 HTTP
200 observations: eighteen API/payload/probe passes and the same seven API
failures. There are zero payload discrepancies. Scan-path lists now establish
string `id` and `message` leaves on both routes; scan status remains null, SSH
ports normalize canonical integer strings and last-login results remain scalar
strings. No populated infected-file rows or enforcement behavior is established.
Strict assessment remains failed for the API errors, without a waiver. Causes
were assessed separately in the reviewer report below. No mutation ran. Both artifacts remain immutable and follow-up
`8x0.2.31` retains the unobserved methods and variants. SHA-256:
`6c0d5ace2ea0795b778c68f552bd2d0852ab8848fb26bc213823ce4916bb63a0`.

### Security/access review corrections and evidence boundary

[Claude review 5720388941](https://github.com/hostingpal/whm-api-client/pull/44#pullrequestreview-5720388941)
reports cPanel `11.138.0.19` observations through direct and bridged routes. Reads
used `npr`; mutations used a disposable account created and removed by the
reviewer. The reviewer confirmed account cleanup, enabled ModSecurity only for
that account, used TEST-NET IP blocks and targeted this server for known-host calls.
These reports are separate from both immutable committed GET captures.

All four ModSecurity writes returned explicit null `exception` values after
successfully changing state. Their bounded correction keeps omission distinct.
The upstream implementation can set per-domain data before returning status zero;
`Flag` still raises `ApiErrorException`, and callers must re-read `listDomains()`
because some domains may have changed. The original read failures reflect the
account's missing `modsecurity` feature; enabled-account reads passed both routes.

ExternalAuthentication bridge calls fail because the module file is absent on
this installation, while direct calls use the compiled server. This version-specific
capability observation does not prohibit bridge routing on other installations.
The review's direct argument probes establish `provider_id` as the removal wire
name, but use invented identifiers and establish no successful unlink. The public
provider argument keeps its name while the generated spec uses the corrected wire
name. Subject identifiers preserve `int|string` without floating conversion on the
getter and remover: the pinned 21-digit example requires lossless string support.
Populated links and successful unlinking remain source/offline evidence only.

`getScanStatus()` returned integer `time_started` on both routes after an attempted
scan, including a failed attempt. The correction preserves both integer and string
values without coercion; the other status fields remain strict. `startScan()` failed
because ClamAV is not installed. Completed scanning and disinfection are unobserved.
The format-only `scan_type` input limitation is tracked under `8x0.1.26` for generator
correction before the first tagged release; no server-wide installation is implied.

KnownHosts `create()` obtained key material without a supplied key, so contact is
recorded as a reviewer observation for creation. No network-contact claim is made
for verification. The reviewer separately exercised the KnownHosts and BlockIP
lifecycles on both routes. `checkDisinfectionStatus()` fractional IDs remain
unobserved. These observations neither replace the historical artifacts nor waive
their API failures. Follow-up `8x0.2.31` remains open for committed mutation evidence,
capability-dependent successes, populated variants and enforcement behavior.

## Account backup and restoration (2026-10-31)

The account-backup section exposes 32 compatible methods across `backup()`,
`restore()`, `wordPressBackup()`, `wordPressRestore()`, `backupInfo()`,
`extractInfo()` and `websiteBackup()`. Eight explicit reads use GET; 24 operations
use form POST. Four binary multipart Backup restore endpoints remain excluded
under `8x0.1.19`, including their alternative server-path modes; generic form access
does not establish typed upload coverage.

Full backups preserve sensitive remote passwords and SSH key passphrases; `keyName`
is an existing key identifier, not private-key content. The pinned prerequisite
text names `SSL::generate_key` and API2 `SSH::importkey`; this UAPI slice does not
implement API2 key import. Omitting the completion-email
argument sends no notification. A PID, backup ID or restore ID does not establish
completion. Restore paths retain required slash conventions and are passed unchanged;
`overwrite` is required and can replace original content. Source role/version and
WordPress Manager plugin requirements remain in each generated method.

WordPress results preserve optional fields, null and opaque-object alternatives.
`WordPressRestore/any_running` preserves both the flat pinned examples and the
formal optional `items` wrapper, including `any_running`, `sites` and `last_outcome`.
The `sites` list keeps its known row shape; its formal object alternative is
unstructured and only documented as empty. No populated keyed-record shape is
inferred. Both `any_running` placements preserve the documented integer 0/1 values.
Cancellation and cleanup have their documented result meanings and do not imply
successful restoration or archive deletion. Returned SSE URLs are never fetched.

BackupInfo, ExtractInfo and WebsiteBackup omit `x-readonly`. Ten methods use the
conservative form POST fallback; WebsiteBackup creation, deletion, extraction and
restoration have explicit pinned POST/form contracts and known mutation effects.
Their POST responses are outside the committed read-only capture; separate reviewer
lifecycle observations are recorded in the source audit.
BackupInfo reset clears completion tracking without canceling a run or deleting
archives. ExtractInfo start records a baseline/marker and extracts no files;
finish clears that marker after either success or failure. Paths refer to server-side
account files. The documented estimate limitations remain visible.

WebsiteBackup uses repeated `database` and `ids` fields, preserving order and
values. Creation with omitted databases backs up files only; omitted email sends
no notification. Restore defaults differ: databases restore unless
`restoreDatabases: false` is supplied. Restore merges and overwrites matching files
by default; `clean: true` can remove later additions. These optional boolean
switches preserve omission and encode explicit false/true as 0/1. Archive deletion is destructive,
per-ID failures stay separate from API status, and source ID/domain association
limits remain explicit. Background operation IDs mean submitted work; inspect
operation status and errors/warnings for completion. Server database snapshots do
not imply an atomic site rollback. No client retry or rollback is introduced.

All 32 methods have offline contracts. The fixed nine-GET catalog selects WHM
version plus account backup inventory, restore-user inventory and both WordPress
`anyRunning()` reads through both routes. It guesses no site or path and excludes
all unknown-risk calls, writes, extraction, restoration, deletion and notifications.
Remaining source/live behavior stays under `8x0.2.32`.

WordPressBackup `cancel()` and `start()` return an optional string `site`,
preserving the pinned outer type across format-only alternatives. Their generated
input currently accepts `string|int`; supply a string site as documented. Integer
site semantics are not established. The generator limitation is tracked under
`8x0.1.23`; it does not weaken the response contract.

## Account backup and restoration capture

The immutable [account-backup capture](evidence/2026-10-31-account-backup-restoration.json)
at `e7b4a42` on `11.138.0.19` records nine HTTP 200 observations. WHM version,
Backup/list_backups and Restore/get_users on both routes pass API, payload and
probe checks. Both inventories are empty; populated rows remain unobserved.
WordPressBackup/any_running and WordPressRestore/any_running fail at the API layer
on both routes. The sanitized evidence does not establish the cause, and no
response contract is widened from those failures. There are zero payload
discrepancies, while strict assessment remains failed for the four API errors.

No backup or restore job, extraction, cleanup, cancellation, message or filesystem
change ran in this capture. No POST method is represented by the artifact;
reviewer lifecycle reports remain separate evidence. Follow-up `8x0.2.32` retains the unobserved operations,
plugin availability and lifecycle behavior. No repeat capture was run merely to
seek a passing status. SHA-256:
`c06b329f50f2ebd16ae43d52a7be4afb8b37d0267956d2d557287113209956f3`.

### Account backup review corrections and evidence boundary

[Claude review 5720426019](https://github.com/hostingpal/whm-api-client/pull/45#pullrequestreview-5720426019)
reports cPanel `11.138.0.19` lifecycle observations through both routes on a
disposable account that the reviewer created and removed, confirming cleanup.
These reports do not add records to the immutable nine-GET capture.

`BackupInfo::reset()` and `ExtractInfo::finish()` returned integer `cleared: 1`
after clearing state. The direct typed calls failed after success; generic calls
and inspected upstream source confirm the integer. The correction accepts only
integer 0/1 alongside the pinned boolean, without widening unrelated state fields.
`diskQuotaCheck()` returned `estimatedBytes` as canonical decimal text directly
and an integer through the bridge. The same bounded compatibility on `availableBytes`
is source-family inference, not a separately observed quoted value.

`WordPressRestore::anyRunning()` now preserves the flat InProgress/NotInProgress
examples and the formal `items` wrapper in a single optional-field object. This
avoids a first-matching optional-object union silently dropping the other shape.
[Clarification 6537452942](https://github.com/hostingpal/whm-api-client/pull/45#issuecomment-6537452942)
explicitly retains the formal opaque-object `sites` alternative alongside the
known list rows: no populated keyed-record map is inferred and the object branch
is not narrowed. The object is formally unstructured and documented only as empty.
These are pinned-source and offline corrections; WordPress success is unobserved.

`restoreBackup()` uses optional booleans for `clean` and `restoreDatabases`, with
omission distinct from explicit false/true encoded as 0/1. The reviewer observed
clean restoration deleting a file added after the backup; this destructive effect
is also explicit in the pinned contract. The method has no automatic retry or
rollback. Creation, deletion, extraction and restoration have pinned POST/form
contracts; the other ten methods without read-only declarations retain conservative
POST selection. Corrected notes retain the cPanel 72 floor for `Restore::getUsers()`
and the documented key-generation/import references for SCP backup by key.

WordPress calls failed on both routes because their API modules were absent on this
installation; the installed plugin supplied no usable modules. Disabled server
backups prevent `queryFileInfo()` and `restoreFile()`; `getUsers()` still returns an
empty list. `ExtractInfo::start()` with a missing archive raises `ApiErrorException`.
These diagnoses do not change any captured outcome or waive the four API failures.

The reviewer separately exercised WebsiteBackup create, status-to-completion,
populated inventory, extract, merge/clean restore and deletion on both routes,
including per-ID repeat-delete failures and unknown-operation/domain errors.
Full backup to the home directory and progress reads were also exercised. SCP
calls, successful extraction-state start with a real archive, file restoration
from a real backup and WordPress success remain unobserved. Empty operation lists
after completion and empty Backup inventory despite an existing archive were also
observed upstream; no new semantics are inferred from them. Follow-up `8x0.2.32`
retains these limits and future committed mutation evidence. The format-only site
input limitation remains `8x0.1.23` for correction before the first tagged release.

## WHM database administration (2026-10-31)

`whm()->databases()` exposes 26 compatible database-administration methods: nine
explicit reads use GET and seventeen operations use form POST. The JSON
`update_sql_config` endpoint remains excluded under `8x0.1.18`. Remote profile reads
marked non-readonly in the pinned source also use POST, and their returned password
fields remain sensitive payload data.

The profile-list source misplaces `additionalProperties`. Its dynamic-name prose
and multi-profile example establish a root profile map containing exactly the eight
documented optional fields. Profile-read ports accept bounded canonical integer
strings observed on the stock localhost profile. The two list flags accept exact
quoted 0/1 from the pinned example; live values were native integers. Typed
`cpcloud` create-result flags use source-family compatibility only; no profile
write was observed. Database/profile keys remain unchanged and wildcarded in
evidence. Unknown-user `mysql_databases: null` remains distinct from an existing
account's empty list; it is not a universal account-existence test. Missing and
null root object payloads remain invalid. Six explicitly metadata-only
rename/password operations return void. Both profile reads can expose plaintext
`mysql_pass` in `PayloadMismatchException::$payload`; do not log that raw payload.

Database passwords, SSH/root passwords and key passphrases remain sensitive.
Stored SSH key names are identifiers. Optional credentials retain documented
alternatives and conditional requirements without invented validation. Explicit
false/zero/empty values remain distinct from omission. Root-password updates retain
the pinned `update_config` and root client-configuration caveats.

Renames can copy data/grants and delete original resources. The pinned rename
warning describes possible partial failure, but its response location has not
been observed; no WHM warnings accessor or text-based detector is implied.
Check the API outcome and explicitly read affected inventory after a rename.
Profile activation can change credentials, network access and server configuration.
Password setters take user before password; rename methods take old name before
new name, with optional `cpuser` last. Passwords retain sensitive-parameter marking.

Pass discovered upgrade versions as exact strings, such as `version: '10.10'`.
The evidence-gated input union also preserves existing integer/float callers;
casting a string to float can change the requested version to `10.1`. Upgrades
have no rollback, and selecting the same version reinstalls the engine. Upgrade
checks/starters return initiation information and require separate status
inspection. Available versions do not establish upgrade compatibility or
completion. The client does not poll, retry or roll back these operations;
method-specific role/version/prefix caveats remain in generated documentation.

All 26 methods have offline contracts. The fixed nine-GET catalog reads WHM
version, current/latest/installable database versions, optimization information,
database/user inventory, `npr` database details and profile-activation status.
It retrieves no profile passwords and guesses no upgrade ID or remote host.
No upgrade/checker run, profile validation/activation, password change, rename or
configuration write occurs. Source/live gaps remain under `8x0.2.33`.

## WHM database administration capture

The initial [WHM database capture](evidence/2026-10-31-whm-databases.json) at
`e0ac45dfd43464abec2d87f7883aca2b1ab5fe6e` on `11.138.0.19` records nine HTTP
200 and successful-API observations: six payload/probe passes and three payload
failures. Database optimizations return an object map under `payload`; the
container mismatch masks its row field names. The compatible map alternative
uses the same optional `name`, `reason` and `value` string fields as the pinned
list schema. Those row names are source-based interpretation, not named live
proof from this artifact. The original list alternative remains supported.

The selected account's `mysql_config.use_db_prefix` is a numeric string;
compatibility accepts only exact string flags `0` and `1`. Profile activation
status returns explicit null for `job_in_progress` and `last_job_details`.
These narrow corrections preserve omission and every pinned field. No profile
credentials were requested, and no upgrade, checker, profile, password, database
or user change ran. The initial artifact stays failed and immutable. Follow-up
`8x0.2.33` retains remaining behavior and variants. SHA-256:
`263cf2cfb1d5ec0c0c8a3fe6884e9b7611bc636db38f9977512cb052dd7e3a47`.

The corrected [WHM database capture](evidence/2026-10-31-whm-databases-corrected.json) at
`00ba48d402f0a60cd975ec38cf5166545a8c0286` on `11.138.0.19` passes all nine
HTTP/API/payload/probe observations and strict assessment, with zero discrepancies.
Optimization map records now establish the named `name`, `reason` and `value`
string leaves. The selected prefix flag passes exact string-flag normalization;
activation state retains both explicit null fields. Installable version rows are
present, while database/user inventories are empty. Empty inventories do not
establish their populated row schemas.

The other eighteen methods were outside this committed catalog. This capture
read no profile credentials and ran no upgrade, checker, profile activation,
password, rename or configuration change. Successful inventory calls establish neither upgrade safety nor
completion, remote trust or service enforcement. The initial failed artifact is
unchanged; follow-up `8x0.2.33` owns the remaining variants. SHA-256:
`b97b7f1bbf91bccc8d048026a1d99c38ecaa049730c165507fde5fe53b111ba1`.

### Independent database review observations

[Claude review 5720420360](https://github.com/hostingpal/whm-api-client/pull/46#pullrequestreview-5720420360)
reports WHM checks on cPanel `11.138.0.19` with MySQL 8.4, separate from the
committed nine-GET capture. Both profile reads returned canonical digit-string
ports; their plaintext password values were not printed. Quoted list flags and
create-result `cpcloud` compatibility are pinned-example/source-family evidence,
not live mutation observations. Unknown-user database inventory returned success
with a null database list; an existing empty account returned an empty list.

The reviewer created and removed disposable account `wrvdb` and its database
resources while checking all six MySQL/PostgreSQL rename and password methods,
followed by populated inventory. Unknown rename sources, profile names and upgrade
IDs raised API errors; no directory was created for the unknown upgrade ID.
These reviewer reports do not expand the immutable capture or establish server-wide
write coverage. No upgrade, checker run, root-password change or remote-profile
create/update/delete/validate/activate ran. A running activation step's `end_time`
and the location of partial-rename warnings remain unobserved under `8x0.2.33`.
The corrected nine-GET artifact already passes; no new capture is needed merely
to clear discrepancies, and the original failed artifact is preserved.

## WHM ModSecurity administration

`whm()->modSecurity()` exposes all 38 pinned functions: eight reads use GET and
thirty mutations or validation helpers use form POST. Rule and configuration text,
report messages and setting states remain sensitive inputs. As safety guidance,
treat returned settings and audit log fragments as private payload data; this is
reviewer judgment, not a statement from the pinned operation text or a claim that
secret values were observed. Configuration paths are forwarded without rewriting.

Rule staging, deployment and discard are separate operations. Config activation,
vendor enablement and automatic vendor updates also have distinct effects. API
success does not establish that a policy has been deployed or blocks HTTP traffic.
Vendor preview/add/update may fetch remote content, and `reportRule(send: true)`
can transmit log information and contact details to a third party. The client adds
no retries, rollback, URL fetching or automatic deployment.

`batchSettings()` currently supports the pinned single-setting arguments. Its
indexed multi-setting families remain under `8x0.1.24`. `reportRule()` retains the
formal integer row-ID input; the contradictory prose's comma-separated multi-ID
form is also deferred there. The source restricts batch setting IDs to 0 through 9.
The twelve source-backed empty-result mutations accept an omitted or null payload
while retaining any returned mixed data; they are not weakened to void. Omitted
wire payloads on those writes remain unobserved.

`getLog()` preserves the inner `data` list wrapper, including an empty log.
Log/report `meta_offset` and `meta_msg` accept source-family nullability; populated
null rows remain unobserved. Get/set settings share the reviewed row shape,
including exact `string|int` state/default values. Batch defaults keep that same
union. The report result's array-of-objects prose repairs its omitted container
type while preserving every declared hit field. Integer rule IDs from getters
can be passed directly to `disableRule()` and `undisableRule()`.

Omitting `enabled` in `addVendor()` installs and enables the remote rule set by
default. `getRules()` defaults to excluding bare comments and other directives;
with neither config nor vendor selector it raises `ApiErrorException` on the
reviewed server. These defaults are retained without implicit client actions.

All 38 methods have independent offline contract tests. The bounded live recipe
selects only WHM version, configuration inventory, pending configuration names,
vendor inventory and installation status. It reads no raw rule/configuration text,
audit logs or secret-bearing setting states; no protection, vendor, report or
configuration action runs. Follow-up `8x0.2.34` retains the remaining live behavior
and source gaps.

## WHM ModSecurity inventory capture

The immutable [WHM ModSecurity capture](evidence/2026-10-31-whm-modsecurity.json) at
`cd205ab2452ee9a8f215d579f4f9d69baa2c3e9a` on `11.138.0.19` records five HTTP 200
observations. WHM version, configuration inventory, vendor inventory and
installation status pass API, payload and probe checks. Both inventories are
empty, so their populated rows remain unobserved. The pending-configuration read
fails at the API layer. The reviewer established that the ordinary idle state
uses result 0 when no rule changes are pending; it therefore raises the expected
`ApiErrorException` under `Flag`. Other failures also use result 0, so no error-text
matching or special success exception is introduced. There are zero payload
discrepancies, while strict assessment remains failed and unwaived for that API error.

No raw rule/configuration text, audit logs or secret-bearing settings were read.
No scan, policy, vendor, report, deployment or configuration change ran, and no
returned URL was fetched. The other thirty-four methods were outside this committed catalog.
Follow-up `8x0.2.34` retains the failed read and all unobserved behavior; this
capture does not establish request blocking or enforcement. No repeated capture
was run merely to obtain a passing result. SHA-256:
`673fd065ffdf05bdb8a1c1dd5af5bcb770a8280aa556cc4a5f2d9fbbd516ab9c`.

### Independent ModSecurity review observations

[Claude review 5720447657](https://github.com/hostingpal/whm-api-client/pull/47#pullrequestreview-5720447657)
reports read-only WHM checks on cPanel `11.138.0.19`. The empty log establishes
its inner `data` wrapper, and all ten populated settings rows had string states.
The shared set-result record, integer setting alternatives, nullable log/report
fields and twelve optional mutation payloads are source/offline corrections;
no server-wide write or populated log row was observed. The reviewer printed
only keys and type classes, not rule, log or setting values.

Configuration and vendor inventories were empty; installed status and settings
reads passed. Missing selectors for rules, unknown configuration text and the
normal idle pending-config state raise API errors. No populated rules/configuration
text or any of the thirty write/helper operations ran. These reports remain
separate from the immutable five-GET capture, whose failed idle observation stays
unwaived under `8x0.2.34`. All existing artifacts are preserved; no new capture was
run to clear the failure.

## WHM login and mail protection

`whm()->cpHulk()` implements sixteen pinned cPHulk functions, and
`whm()->mail()` implements fourteen Greylisting functions. Fifteen reads use GET;
fifteen mutations use form POST. The JSON batch-record operation remains under
`8x0.1.18`. The provider-configuration setter is excluded under `8x0.1.25` because
its `PROVIDERKEY` placeholder represents a dynamic parameter name, not a literal
wire field. The pre-existing Mail SNI exclusion stays intact.

cPHulk configuration can change login protection, firewall blocking and commands
run after attacks. Command and generic configuration-value inputs remain sensitive.
Enabling or disabling protection, editing trusted or blocked addresses, and flushing
login history take effect on the selected server; the client does not retry or
roll them back. Per-item results and service flags remain separate from API success.
Greylisting time settings retain their documented units and defaults, and trusted
provider changes affect future mail handling without proving delivery behavior.

Documented IP/provider lists use the reviewed base-name then `-1`, `-2` encoding.
Alternative prose using a `-0` first index remains unverified. In `saveConfig()`,
omitted cPHulk options preserve their current values, as established by the
reviewed partial-update source; explicit false and zero remain distinct. Other
operations retain their method-specific omission rules. Response maps preserve
dynamic keys and optional fields. The source-backed untrust correction retains
provider error strings in `providers_failed` and `ips_untrusted` records in
`providers_untrusted`; provider IP entries are lists of the four known fields.
Trusted-host creation preserves per-IP error strings instead of silently dropping
those dynamic failures. Save-config enabled flags use the same exact quoted 0/1
compatibility as load, based on the shared source result; no save was observed.

All four history reads accept bounded canonical integer-string `timeleft` values.
The excessive-history result also retains its original native integer/float form;
fractional strings, noncanonical strings and overflow are not newly accepted.
Only the failed-login string values were observed; the other histories use shared
source inference. Native fractional values and stored-row null comments remain
unobserved under `8x0.2.35`.

On the reviewed server Greylisting is disabled. Its reads raise `ApiErrorException`
while disabled, except status and numeric configuration, which still work. This
is a version-specific reviewer observation, separate from the committed capture.
The client does not enable the service or waive API outcomes to populate evidence.

All thirty methods have independent offline contract coverage. The fixed eight-GET
catalog selects WHM version, cPHulk status and country inventory, and Greylisting
status, server netblock trust, common-provider configuration, numeric configuration
and trusted-host inventory. It reads no authentication logs, deferred-message
records or configured command text. It changes no protection, trust, history or
mail state and sends no messages. Follow-up `8x0.2.35` retains all unobserved
variants and enforcement behavior.

## WHM login and mail protection capture

The initial [login/mail-protection capture](evidence/2026-10-31-whm-login-mail-protection.json)
at `b99dd1f35aa591deb7803dc7f298296f1338a33b` on `11.138.0.19` records eight HTTP 200
observations: four API/payload/probe passes, three API failures and one successful
API response with a payload mismatch. Only `load_cpgreylist_config`'s
`cpgreylist_config.is_enabled` is a numeric string; compatibility is narrowed to
exact string flags `0` and `1` at that leaf, leaving sibling flags unchanged.

Server netblock trust, common-provider configuration and trusted-host inventory
fail at the API layer. The sanitized evidence does not establish their cause;
these errors remain unwaived. No login logs, deferred messages or command text
were read, and no security, trust, history, configuration or mail state changed.
The initial capture remains failed and immutable. Remaining variants and behavior
stay under `8x0.2.35`. SHA-256:
`60326d0c8bc7435124aca7c178c99a3dea11a1ab06476f9afff59e9411ac6f9d`.

The corrected [login/mail-protection capture](evidence/2026-10-31-whm-login-mail-protection-corrected.json)
at `8f1a4b91a556ca73f5be9db5ad6170d2e481bd0c` on `11.138.0.19` records eight HTTP 200
observations: five API/payload/probe passes, the same three API failures and zero
payload discrepancies. The reviewed Greylisting flag now passes exact string-flag
normalization; numeric configuration limits and sibling flags retain their types.
Country inventory is populated. Failed Greylisting inventories establish no row
schemas; the reviewer separately diagnosed disabled Greylisting as their cause.
Strict assessment remains failed without a waiver.

No authentication logs, deferred messages, command text, mutation or notification
was involved. Both artifacts remain immutable. API status does not establish
login blocking, mail delivery or enforcement. Follow-up `8x0.2.35` owns the
remaining failures, methods and variants. SHA-256:
`27fd32d3b51e8195a3f027b4890f55ad68a440bb706aabf3862b84f6582e6a27`.

### Independent login and mail protection review

[Claude review 5720507218](https://github.com/hostingpal/whm-api-client/pull/48#pullrequestreview-5720507218)
reports WHM GET checks on cPanel `11.138.0.19`, printing only key names and type
classes. All 110 failed-login rows carried whole digit-string `timeleft` values.
The other three histories were empty; their compatibility follows the shared
source routine. Fractional native values remain supported by the excessive-history
number schema, but were not observed. The canonical string correction does not
admit fractional strings or overflow-to-float.

The untrust maps, provider IP list, save-config quoted flag and trusted-host error
map corrections are source/offline evidence. All fifteen writes remain unobserved
because they are server-wide and some can cause lockout. Source returns an empty
string for an absent creation comment; stored-row null comments remain unobserved.
Existing numbered encoding is unchanged.

The reviewer established disabled Greylisting as the cause of all three captured
API failures. Status and configuration still work while it is disabled. No service
enablement, mutation or repeat capture ran. Both immutable artifacts retain their
failed/unwaived status under `8x0.2.35`, and the reviewer reports do not expand the
committed eight-GET catalog or establish login/mail enforcement.

## WHM reseller administration

`whm()->resellers()` covers all 21 pinned functions: seven reads use GET and
fourteen mutations use form POST. The group manages reseller privileges, ACL
lists, resource/package limits, delegated IPs, nameservers, account enhancements
and lifecycle operations. Each method retains its pinned version and role notes.

`setupReseller()` grants reseller privileges; ownership changes follow its explicit
`makeowner` option. Suspension and restoration retain the `reseller-only` option,
while termination can remove the reseller and owned accounts. Read the individual
method's destructive/default caveats before calling it. The client performs one
request without retry or rollback; API success and account-level outcomes remain
separate. Suspension reasons are sensitive inputs.

ACL save and assignment have different semantics; `acl-all` grants root-level
privileges. `setAcls()` accepts only `true` or omission for each assignment
switch: upstream grants any present `acl-*` key regardless of its value. Native
`?true` parameters reject false, zero, numeric one and strings before dispatch,
including calls from files without strict types. True sends `1`; null omits the
key. The overriding `user` alias is excluded, so `reseller` is the sole typed
target. `saveAclList()` retains boolean flags: false sends `0` and removes that
privilege. Both operations replace the complete set; omitted inline assignment
privileges are removed, and omitted save flags default to zero. Read the current
set before constructing a replacement. Use either a named ACL list or inline
switches, never both; with neither, the inline assignment clears all privileges.

Prefer named arguments. `suspendReseller(user, reason, ...)`,
`setResellerMainIp(user, ip)` and `terminateReseller(user, terminatereseller)`
put resource identifiers first. The pinned `acl-disallow-shell` description
contradicts its wire name; no interpretation beyond that contradiction is
asserted. `setEnhancementLimit(limited: true)` sends `limited=1`, and false sends
zero. `setResellerIps()` defaults omitted `delegate` to one and returns the
`getResellerIps()` shape according to the reviewed source; these writes remain
unobserved. Other omission behavior is documented only where the source supports
it. IP/nameserver lists use their documented serialization; the client does not
infer or validate a new network configuration.

All 21 functions have independent offline fixtures, including every documented
ACL input flag and privilege response field. The bounded live catalog reads WHM
version, default account counts, public contact details, reseller IPs without a user,
ACL lists and reseller names. It supplies no guessed reseller identifier, changes
no accounts, privileges, limits, contact details, IPs or nameservers, and does not
fetch returned contact URLs. Empty inventories do not establish populated row
contracts. Follow-up `8x0.2.36` retains the remaining live behavior and variants.

## WHM reseller inventory capture

The initial [reseller capture](evidence/2026-10-31-whm-resellers.json) at
`8acce7c186a2815c5f81275acfa053a6f52d3e3c` on `11.138.0.19` records six HTTP 200
observations: four API/payload/probe passes, one successful API response with a
payload mismatch and one API failure. `acctcounts` returns an exact empty string
at `reseller.limit`. Compatibility preserves that literal alongside the pinned
integer/null alternatives; its meaning is not inferred and it is not converted
to zero or null. Sibling counters keep their original contracts.

The `getresellerips` call without a user fails at the API layer. The sanitized
artifact alone does not establish the cause; the separate reviewer diagnosis
below identifies the omitted user. No failure is waived. ACL and reseller
inventories are empty; public contact name and
URL are empty strings. No account, ACL, privilege, limit, contact, IP or nameserver
changed, and no returned URL was fetched. The artifact remains failed and
immutable; follow-up `8x0.2.36` retains remaining variants and lifecycle behavior.
SHA-256: `76e0be8605fc048ab03c9bfd82c0fb98fefbf93d170c0fb619cde5902339c753`.

The [corrected reseller capture](evidence/2026-10-31-whm-resellers-corrected.json)
at `e8751c3ff59e7be77a1726669dc2b80181a7fca2` on `11.138.0.19` records six
HTTP-200 observations, five API/payload/probe passes and one unchanged API failure
from `getresellerips`. The account-count limit now preserves its observed empty
string alongside the documented integer/null alternatives. No meaning is assigned
to that empty string, and sibling fields retain their original contracts. There
are zero discrepancies; strict status remains failed because of the API failure.

ACL and reseller inventories remain empty, and public contact strings are empty.
No account, ACL, limit, contact, IP or nameserver setting changed. The initial failed
artifact remains unchanged; follow-up `8x0.2.36` retains the API failure and all
unobserved operations/variants. Corrected SHA-256:
`7ae3954f188d2128dceab3fd1e61d5bc6133761105778ad30788996c161817eb`.

### Independent reseller review and remaining mutation gap

[Claude review 5720544221](https://github.com/hostingpal/whm-api-client/pull/49#pullrequestreview-5720544221)
reports WHM GET checks on cPanel `11.138.0.19`. `getResellerIps()` without a
user as root failed because the source reads `user` without a default; a named
non-reseller also raised an API error. This diagnoses the retained capture failure
without changing its strict failed status or rerunning it. ACL/reseller inventories
were empty, so populated row contracts remain unobserved.

Presence-only ACL assignment, full-set replacement, target-alias removal, signature
ordering and delegated-IP defaults are source-backed and tested offline. The
reviewer's attempted disposable reseller lifecycle was denied before execution,
with no specific reason beyond the permission classifier judging it dangerous.
[Clarification 6537927871](https://github.com/hostingpal/whm-api-client/pull/49#issuecomment-6537927871)
records the exact attempted command scope and confirms no test account, home
directory or ACL list remained; no mutation ran and no alternate-form retry was
attempted. The rejection is not attributed to a particular operation. All fourteen
write methods remain unobserved under `8x0.2.36`; no new capture, privilege change
or lifecycle ran for these corrections. Reviewer reports remain separate from
the immutable six-GET artifacts.

## WHM IP address administration

`whm()->ipAddresses()` exposes all sixteen pinned IPv4, IPv6 and NAT functions.
Six reads use GET and ten mutations or NAT-check helpers use form POST. Each
method retains its source availability floor and parameter order. Addresses,
netmasks, ranges and notes are forwarded without rewriting or new validation.

`getSharedIp()` preserves the literal `~` value when no shared address exists.
IPv6 inventory retains the `SHARED` range name, optional rows and lists, and
integer status flags. Only the observed IPv6 range `enabled` field additionally
accepts exact quoted 0/1 flags, and its `mostrecent` and `note` fields accept null.
Other fields retain their reviewed contracts. Explicit false becomes zero,
while omitted options retain server defaults.

`disableIpv6Account()` returns null for absent/null data on clean success,
following reviewed upstream source. Present failure fields remain validated,
including mixed `reason` values; inspect them even after envelope success. This
write response is source-backed and offline-tested, not live-observed.
`addIps()` returns void; pinned per-address messages remain available at
`responseMeta()->whm['output']['messages']`.

On `11.138.0.19`, the reviewer observed `getIpv6RangeUsage()` returning the upstream
constants `used=20`, `free=1000000` and `forbidden=65536`. These placeholders do
not measure actual address capacity or utilization on that version.

`deleteIp(skipIfShutdown: true)` prevents removal when the interface is already
down; false or omission permits removal. The switch does not shut it down. NAT
and site-IP changes can alter connectivity and hosted configuration. Account IPv6
enablement/disablement and range removal are separate operations. Read each
method's selector and destructive/default caveats before calling it. The client
adds no retries, rollback, NAT probing or network reconfiguration beyond the
single requested operation. API success does not prove reachability, DNS, mail
routing, HTTP delivery or TLS behavior.

All sixteen methods have independent offline contracts. The fixed five-GET live
catalog selects WHM version, the default shared IP, IPv6 ranges and IPv4/IPv6
inventories. It guesses no address or range, performs no NAT check and changes
no account, address, interface, mapping or network state. Empty inventories do
not establish populated row schemas. Follow-up `8x0.2.37` retains all unobserved
variants and lifecycle behavior.

The initial [WHM IP-address capture](evidence/2026-10-31-whm-ip-addresses.json) at
`adb1dfdca8c5308315ef1cad23d902675798d579` on `11.138.0.19` records five successful
HTTP/API observations and four payload/probe passes. `ipv6_range_list` has three
known-field differences: a numeric-string `enabled`, and null `mostrecent` and
`note`. Those findings support exact quoted-flag compatibility only for that
operation's `enabled`, and nullable strings only for its other two fields. The
structural artifact does not establish both quoted flag values were observed.
Sibling fields and all other operations retain their existing contracts.

IPv4 addresses, IPv6 addresses and range rows are populated; shared-IP lookup
returns a string. Empty range-user lists establish no populated user elements.
No network, address, range or NAT setting changed, and no address/range identifier
was guessed for a probe. Strict status remains failed in this original artifact,
which is immutable. Follow-up `8x0.2.37` retains unobserved variants and mutations.
Initial SHA-256:
`439b50dbddb6d3577889ac9013a154ffde1151a8b4a53b8292c674e5ff1b3900`.

The [corrected IP-address capture](evidence/2026-10-31-whm-ip-addresses-corrected.json)
at `fde160d4c41a1651405d9a2c985941c71a6d57da` on `11.138.0.19` passes all five
HTTP/API/payload/probe checks and strict assessment with zero discrepancies.
The three operation-specific IPv6 range corrections now pass. IPv4, IPv6 and
range inventories are populated; the range-user lists remain empty. This verifies
only the four inventory operations plus version, not address/range lifecycle,
NAT behavior, exclusion semantics or network configuration changes. No mutation
ran. The initial failed artifact stays unchanged and `8x0.2.37` remains open.
Corrected SHA-256:
`185dd1b1d6728a6aad76ce258f60f1177f08e9f368c85935667e713889a9a552`.

### PR50 reviewed corrections and evidence limits

[Claude review 5720554070](https://github.com/hostingpal/whm-api-client/pull/50#pullrequestreview-5720554070)
reports read-only WHM checks on `11.138.0.19`, including range usage, public/shared
IP reads and unknown-range error handling. The observed usage placeholders are
separate from the committed inventory captures. The clean IPv6-disable response
comes from reviewed `Whostmgr/API/1/IPv6.pm` source; regression tests accept absent
or null successful data while retaining strict validation of present data.
Pinned `/delip/get` values establish the corrected shutdown-skip description,
and `/addips/get` supplies the metadata message contract. No new live request or
capture ran for these corrections. All ten form POST methods, including the NAT
check helper, remain unobserved under `8x0.2.37`. Both historical artifacts remain
immutable; no schema finding was waived or replayed.

## Expanded WHM server administration

`whm()->server()` now exposes 76 of the 79 pinned Server Administration functions,
including 72 additions for services, profiles, updates, configuration, linked nodes,
notifications, licensing and inventory. The new calls use 23 GET requests and 49
form POST requests. The four existing server methods retain their contracts.
Missing read-only declarations remain conservative POST calls, including the
three namespaced EcosystemFeatures functions.

JSON connected-application saving remains under `8x0.1.18`, binary configuration
upload under `8x0.1.19`, and `system_needs_reboot` under `8x0.1.27`. The latter's
result fields are envelope siblings outside `data`; neither the typed runner nor
the generic call currently preserves that result. No misleading void method is
provided. Connected-application reads preserve declared typed fields and opaque
additional properties through the existing object-values mechanism. Treat their
results and service/configuration values as private payload data.

Profile activation accepts documented serialized JSON text in its optional role
argument, with one form-encoding pass. Its native `string|int` boundary does not
establish integer semantics or automatic object encoding; supply a JSON string.
The typing limitation remains under `8x0.1.23` before release. Available-tier
maps preserve the five pinned named fields alongside dynamic values. Both
service-configuration reads document `dovecot`; undocumented services are outside
the contract, and null data from such a service can fail validation. Configuration
values can contain credential material, including the observed key name
`hulk_auth_passwd`; neither the review nor committed evidence records its value.
Treat returned values and raw payloads attached to exceptions as sensitive.
The full configuration and individual-key reads retain the same string, integer
or nested map values with opaque contents.

On `11.138.0.19`, the reviewer observed quoted role flags and digit strings
in both application-importance reads. Compatibility uses the established bounded
canonical-integer normalization policy; the observation does not establish every
accepted spelling or boundary. Strict flag values and integer bounds remain. The documented empty
node-linkage object is source-backed and offline-tested; populated linked-node
behavior remains unobserved. Per-method availability, role requirements,
omission, null and explicit false/zero behavior remain in generated PHPDoc.

For update preferences, `RPMUP` or `SARULESUP` set to `never` requires the
administrator to run `scripts/update-packages`; it does not make updates
unnecessary. Feature-management inputs retain the pinned single feature-ID
description without an unsupported comma-separated batch claim.

EULA acceptance, license purchase, reboot/restart, updates, profile activation,
node linking, configuration changes and notification tests are explicit operations.
They may have legal, financial, connectivity or service effects; the client adds
no automatic invocation, retry, rollback or completion polling. Credential, token,
configuration and notification inputs remain sensitive. API success or a job ID
does not establish completion, service health or remote connectivity.

Independent offline contracts cover all 72 additions and preserve the original
four. The bounded eight-GET catalog reads version, contact-event importance,
available/current profiles, password-strength policy, installed software versions,
service status and dashboard hostname. Optional selectors are omitted. It reads
no credentials, linked-node secrets, arbitrary configuration values or sockets,
and sends no notification, URL probe or mutation. Follow-up `8x0.2.38` retains
unobserved variants and operational behavior.

The initial [expanded server capture](evidence/2026-10-31-whm-server-administration.json)
at `4eecbe249c754341f454db573a1a475731dc8b9c` on `11.138.0.19` records eight
HTTP/API successes and six payload/probe passes. Contact-event `importance` and
nine minimum-password-strength fields are numeric strings rather than pinned
integers. Only those ten leaves gain lossless canonical integer-string
normalization; other fields and operations retain their contracts. Structural
numeric-string evidence alone does not establish every spelling accepted by the
normalization policy; noncanonical strings, fractions and overflow remain invalid.

Installed versions retain one masked unknown-field observation with string values;
service rows retain one masked unknown object observation with integer/string
children. Masking identifies neither names nor counts. Both stay untyped and
unwaived under `8x0.2.38`. Profile, contact-importance and service records are
populated. No configuration, credential, service, profile, license, notification
or node operation changed anything, and no secret-bearing read was selected.
The initial capture remains failed and immutable. SHA-256:
`69d1296be7b3170ce6c6e8468aee28b03a3c6633b4b9c0b374f3ffbd5844e296`.

The [corrected expanded server capture](evidence/2026-10-31-whm-server-administration-corrected.json)
at `742eaa8419eca4c9021ff0cc87ff6504a08e477e` on `11.138.0.19` passes all eight
HTTP/API/payload/probe checks. The ten integer-leaf corrections now pass. Strict
assessment remains failed for the same two masked unknown-field observations in
installed versions and service rows, with zero acknowledgments and no new typed
unknown fields. Masking identifies neither underlying names nor field counts.

All original observations and artifacts remain unchanged. These reads establish
only the selected inventory and policy responses, not service health, completed
updates, profile activation, licensing, node connectivity or notification delivery.
No mutation, credential read or URL request ran. Follow-up `8x0.2.38` retains the
unknown findings and unobserved variants. Corrected SHA-256:
`db24e53879fff4754369b984969640b51ea483713ad71c735d6d1bb1be88f6b8`.

### PR51 reviewed corrections and evidence limits

[Claude review 5720579254](https://github.com/hostingpal/whm-api-client/pull/51#pullrequestreview-5720579254)
reports type-only WHM reads on `11.138.0.19` covering all 27 GET methods. It
observes quoted role flags, digit strings for both application
importance methods, and object-valued Dovecot configuration keys. These reports
remain separate from the eight-GET structural captures. The empty node-linkage
object and named tier fields follow the pinned source, not populated node or
tier observations. Unknown service configuration nulls remain outside the
documented Dovecot contract; no broad null compatibility is inferred.

All 49 POST methods remain unobserved. Successful linked-server lookup and
populated linked inventories require linked servers; tweak-setting null/integer
variants also remain unobserved under `8x0.2.38`. Profile-activation input typing
remains under `8x0.1.23` before release. No new live call or capture ran for these
corrections. Both historical captures remain immutable, and their two masked
unknown-field observations stay strict failures without acknowledgment or waiver.

## WHM system package management

`whm()->systemPackages()` exposes all fourteen pinned functions for server RPM
metadata, package inventory and planning, plugin processes, background actions,
cache repair and upgrades. Seven methods use GET and seven use form POST.

The source requires either `namespaces` or `packages` for `getPackageInfo()`;
its individually optional parameters do not enforce that combination locally.
Supply the intended selector. `resolveActions()` requires both packages and a
namespace: the reviewer observed a missing-namespace API error despite the
pinned optional declaration. The formal namespace enum names `ea`; source prose
also permits configured additional prefixes. Repeated inputs use repeated wire
keys without inferring which additional namespaces this server supports.

`submitActions()` has no local nonempty-action combination guard. Omitting every
action sends an empty form body, and an empty scalar sends an empty field;
explicit empty lists reject before dispatch. Supply the intended nonempty
install, uninstall or upgrade actions before calling. Neither server rejection
nor a harmless no-op is guaranteed for an empty call. Background acceptance
and a returned build ID do not establish completion. The client does not poll,
retry, roll back or execute scripts automatically.

Package dependency requirements retain package-name strings and nested string
lists; a nested list means any one of its members, following the reviewed
PackMan source and the separate six-package GET report. Conflicts remain a
flat string list. Optional `vendor` is a string; the reviewed dnf backend uses
a source-only `NYI` placeholder rather than identifying an actual vendor.
`version_installed` remains a non-nullable string. The reviewer observed an
exact empty string on four non-installed packages and nonempty strings on two
installed packages, then withdrew the nullable recommendation. Null remains
unobserved and rejected; no empty-to-null conversion is added. These reviewer
reads and source findings are separate from the committed capture artifacts.

RPM metadata maps accept string or string-list leaves, including nested section
maps. The reviewer observed scalar values in `target_settings` and list values
in `rpm_groups`; arbitrary section names remain strings. For RPM edits/deletions,
`metadata.result=1` can accompany `data.status=0`. The returned zero means the
RPM change failed; callers must inspect it because envelope success alone does
not cause an exception. These metadata changes are distinct from installing or
uninstalling packages. Upstream recommends a later package-check script; the
client does not run it. Cache-repair success documents literal 1, while the
existing integer contract does not enforce a singleton enum.

Log offsets are bytes. Build IDs come from submitted actions and are not guessed;
log text is private payload data. Installation, removal, upgrade and cache repair
can change the server immediately. All seven POST methods remain unobserved.

Both historical three-GET package captures are complete and immutable. The
corrected `b8ebf87` capture on `11.138.0.19` passes HTTP/API/payload/probe checks,
with populated installed-package rows and metadata-only `listRpms()` success.
That method accepts absent successful data; its inherited explicit-null boundary
is unobserved. Present payloads remain validated, and absence/null does not mean
zero missing dependencies. The original strict `missing_required` finding is
an evidence-tooling mismatch: the old fingerprint path dropped `spec.optional`.
The correction carries optional-payload semantics into new fingerprint analysis;
it does not rewrite or waive either historical artifact. The corrected artifact
remains strict failed under `8x0.2.46`, SHA-256
`870dcf28decf6b60de972f9876115506ca2ee129a0b7b3c64e683e73016af1d4`.

The expanded fixed GET catalog includes package information, both RPM map
sections and action resolution. A separate review recipe selects only version
and those newly requested reads, without replaying the old inventory probes.
No package mutation, cache repair or build-log read is part of either recipe.

## WHM authentication

`whm()->authentication()` exposes 30 of the 32 pinned Authentication operations
for SSH keys, authentication providers, external-account links and two-factor
configuration/policy. Seven use GET and 23 use form POST. Deprecated `accesshash`
remains excluded; JSON `twofactorauth_set_tfa_config_for_user` remains under
`8x0.1.18`. The login-URL method uses POST as a conservative client privacy
policy, despite the pinned GET-only declaration. The pinned redirect example has
a `state` value; it does not establish that redirects always contain tokens.
Live POST compatibility remains unverified. Reviewed upstream `Market.pm` code
routes through a provider and may make an outbound call; the reviewer did not
execute it. Generated notes distinguish client policy from documented facts and
observed behavior.

Key material, passphrases, provider configurations, redirect URLs and TFA secrets
and tokens remain sensitive. Returned keys, provider URLs/configuration and TFA
configuration are private payload data. The client does not follow returned URLs;
upstream provider calls may have their own outbound behavior as described above.
Remote SSH connection checking is explicit and performs no automatic follow-up.
Key generation/import/authorization/deletion, provider/link changes and TFA changes
can alter access immediately. Preserve abort/overwrite, keep-file and authorization
defaults and inspect per-item results separately from API metadata. No retry or
rollback is added. Source versions and conditional input caveats remain in PHPDoc.

`authorizeSshKey()` accepts file-only authorization by omitting `text`; if both
are supplied, the pinned contract gives text precedence. The file argument remains
required and names a public key under `/root/.ssh`. Successful remote SSH checks
permit null `comment`, `protocol_versions` and `server_software` as documented.
These POST paths are source-backed and offline-tested. External-account link and
unlink methods take the local username first. Unset provider values retain null;
client ID/secret nulls and absent/null TFA `primary_account` are source-family
compatibility, not observations from executing those POST methods.

Provider display configurations accept caller-serialized JSON containing the
pinned `color`, `display_name`, `icon`, `icon_type`, `label` and `textcolor` keys,
as a form field encoded once. The native `string|int` boundary has no documented
integer semantics or automatic object encoding. Before release, `8x0.1.23` must
also cover this content-based query schema, separately from JSON-body transport.
TFA removal currently
supports scalar `user`; the separately documented `user-1`/`user-2` family remains
under `8x0.1.21`, without assuming equivalence to another numbered encoding.
A user listed as modified may have had no TFA settings; it does not prove removal
changed a configured account.

All 30 methods have independent offline contracts. The fixed three-GET catalog
reads WHM version, issuer names and policy status only. It retrieves no keys, TFA
secrets, provider configuration or login URLs, opens no remote SSH connection,
and changes no account, authentication policy or provider. Follow-up `8x0.2.40`
retains remaining source/live coverage.

The [WHM authentication metadata capture](evidence/2026-10-31-whm-authentication.json)
at `e88583ed1e3a5d2e255f11389a90afad6ba740b2` on `11.138.0.19` passes all three
HTTP/API/payload/probe checks and strict assessment with zero discrepancies. It
observes only WHM version, issuer-name strings and the integer TFA policy flag.
No provider, credential, key, login-URL, TFA-secret or linked-account data was read.
No authentication state changed and no remote connection or URL request occurred.

This establishes no successful login, SSH access, TFA challenge, provider/link
operation or mutation compatibility, including the effective POST login-URL method.
All remaining operations and variants stay under `8x0.2.40`. Immutable SHA-256:
`79245a336ed4daacbc6ac02c1bc48a3c72f64dee68f42987b5ab1dafb6366c81`.

### PR52 reviewed corrections and evidence limits

[Claude review 5720627530](https://github.com/hostingpal/whm-api-client/pull/52#pullrequestreview-5720627530)
reports seven WHM GET methods on `11.138.0.19`, including an unset provider field
with null value and an explicit `npr` user filter. Those observations remain
separate from the committed three-GET capture. Provider field values were already
optional/nullable in the generated contract; the correction records provenance.
Client ID/secret nulls and TFA primary-account nullability remain source-family
compatibility; primary-account omission was already supported. SSH success nulls,
file-only authorization and username-first link inputs follow the pinned text and
offline regressions. No POST operation was exercised for these corrections.

The generator's additional Policy note label identifies client transport,
sensitivity and encoding decisions without presenting them as upstream facts or
live observations. Existing observed-note live-evidence requirements remain.
The login-URL POST is still unverified; the reviewed provider call is source-only.
All 23 POST methods and remaining successful-access variants remain unobserved
under `8x0.2.40`. No credential, key, private URL or TFA secret was read, no state
changed and no new live capture ran. The original three-GET artifact is complete
and immutable.

## WHM SSL certificate management

`whm()->ssl()` exposes 32 of the 34 pinned SSL Certificates operations: ten GET
reads and 22 form POST calls, including source-classified POST fetch operations.
Provider reset/selection remains excluded under `8x0.1.28` because `x_*` denotes
dynamic input names, not a literal parameter. Provider inventories preserve strict
named fields and opaque additional values; treat returned provider data as private.

PEM, private-key, CA-bundle and passphrase inputs remain sensitive. Domain exclusion
arrays use documented numbered names; deferred installation uses repeated fields
whose five lists callers must align by position, including each certificate and
private key. Neither the client nor its type checks validate equal lengths or
pairing. An empty bundle requests server-side bundle download. The metadata setter accepts caller-serialized JSON text encoded once as
a form field; its current native `string|int` boundary does not establish integer
semantics or automatic object encoding (`8x0.1.23`). This is not a JSON request body.

Generation stores key/CSR material even when certificate generation is skipped.
Service certificate installation requires a separate service restart; the client
does not perform it. Exclusion replacement, disable/reset/install and background
checks can alter hosting behavior; queued work or a PID does not establish
issuance, renewal, installation or TLS delivery. No retry, rollback or polling is
added. `installSsl()` returns its own `status` and `warning_domains` alongside
the checked WHM envelope outcome. Callers must inspect those fields: a successful
`metadata.result` does not imply nested `status: 1`, and nested failure is not
converted into an exception. `listCertificates()` without `user` includes all
accounts' certificate metadata; specify the intended account when isolation is
required. Virtual-host deletion has no rollback and must not be retried simply
because response validation failed after a confirmed outcome.

Certificate fields preserve omission, native values and literal dotted keys.
Separate WHM reviewer observations support string terms-of-service acceptance
(despite the conflicting pinned flag), canonical integer strings in best-service
expiry and FQDN certificate dates/length, exact quoted flags, and null FQDN
verification-error/issuer-organization values. Vhost self-signed flags preserve
the exact empty string without assigning it new meaning; deletion flags accept
exact quoted 0/1. The five shared reset/install service-certificate leaves use
source-family compatibility only; those writes were not observed.
`fetchServiceSslComponents()` keeps its native integer contracts, and unconfirmed
vhost `subject.commonName` variants stay unsupported.

`rebuildInstalledSslDatabase()` is a documented no-op. It and deferred enqueue
accept absent/null successful data as reviewed source-only compatibility, while
present payloads keep their existing opaque-data validation. No missing/null
response from either method was observed.

All 32 methods have independent offline contracts. The bounded nine-GET catalog
reads version, schedule, log catalog metadata, metadata flags, problems/exclusions
for designated `npr`/`npr.rs`, the best domain for the pinned `whostmgr` service
example, and the account's certificate inventory. It reads no log contents,
private-key/component PEM or unspecified provider values and makes no changes.
Follow-up `8x0.2.39` retains remaining live behavior.

The initial [WHM SSL capture](evidence/2026-10-31-whm-ssl.json) at
`df3a6341a08d85eb9c011e617db7edbcc2c967cc` on `11.138.0.19` records nine
HTTP/API successes and seven payload/probe passes. The best-service-domain
expiration is null. Certificate inventory has null owner-list items and literal
`issuer.organizationName` values, plus numeric-string modulus lengths and expiry
timestamps. Five operation-specific field rules preserve these observed variants;
other fields and WHM/UAPI operations remain unchanged. Owner-list null items do
not make the list itself nullable, and no meaning is assigned to null values.

Integer-string compatibility stays canonical and lossless, rejecting noncanonical
strings, fractional values and overflow. Structural evidence does not establish
every accepted canonical spelling. No provider data, private key, component PEM
or log contents were read and no mutation or URL request ran. The original strict
assessment stays failed and immutable; `8x0.2.39` retains remaining live behavior.
Initial SHA-256:
`f1bded7e795124bb9d053a568a002b17db6b03766a4ec19cd9a416363142ea29`.

The [corrected WHM SSL capture](evidence/2026-10-31-whm-ssl-corrected.json) at
`4317e1af1a4065134fae5e8addbd6c9cf083e985` on `11.138.0.19` passes all nine
HTTP/API/payload/probe checks and strict assessment with zero discrepancies.
The five operation-specific corrections pass, with populated certificate inventory.
No installation, service restart, TLS serving/trust, AutoSSL issuance/renewal,
provider configuration or key lifecycle behavior is established.

No certificate, exclusion, configuration or scheduling setting changed. No private
key, component PEM, provider payload or log content was read, and no URL was fetched.
The original failed artifact stays unchanged; `8x0.2.39` retains unobserved behavior.
Corrected SHA-256:
`1e3117fc43dc790b0891b3571313b44f60953ce4b6e0d20305c0bc32ccacb498`.

### PR53 independent review and correction boundaries

[Claude review 5720639651](https://github.com/hostingpal/whm-api-client/pull/53#pullrequestreview-5720639651)
reports WHM observations on `11.138.0.19`, with type-only output and a disposable
`wrvssl` account created and removed by the reviewer. The account-scoped
exclusion cycle and install/delete operations used a locally generated
self-signed certificate. Those reports remain separate from the committed
nine-GET captures and do not expand their scope.

The reported normal-data failures identify the exact terms-of-service string,
best-service expiry, FQDN certificate fields, empty vhost self-signed flag and
quoted deletion flag. The corresponding service reset/install family corrections
are source/offline evidence; no server-wide write was run. The dismissed vhost
`subject.commonName` remains unchanged. Nested install status and warning domains
must be inspected independently of the checked metadata outcome.

Only rebuild and deferred enqueue permit an omitted/null successful payload under
the reviewed zero-property/opaque-object source contract. These response variants
remain unobserved; present values are still validated. The client performs no
automatic retry, rollback or alignment check for the five enqueue lists.

No new live request, capture, credential/private-key read, server-wide write or
URL fetch ran for these corrections. Both prior artifacts are immutable.
Remaining operations, variants, TLS serving/trust, issuance and service-certificate
changes stay open under `8x0.2.39`; input gaps `8x0.1.28` and `8x0.1.23` remain.

## Expanded WHM DNS management

`whm()->dns()` exposes 40 of the 44 pinned DNS functions: sixteen GET reads and
24 form POST methods for zone inventory, DNSSEC keys, domains, MX records,
resolvers, nameservers and maintenance. Deprecated `dumpzone` remains excluded.
Three record-write operations remain under `8x0.1.30`: `addzonerecord` and
`editzonerecord` require composed form schemas; `mass_edit_dns_zone` combines
repeated serialized JSON records and integer removal indices.

DNSSEC enable/disable uses repeated domain fields; other selectors retain their
own scalar contracts. `addZoneKey()` and each `enableDnssecForDomains()` row return
integer `new_key_id` values, accepting bounded canonical integer strings so the
IDs can be passed to the key-selector methods. `exportZoneDnskey()` normalizes its
`key_id` by the same rule. `importZoneKey()` preserves both the pinned
`import_key_id` integer and the source-backed optional `new_key_id`, with canonical
integer-string compatibility on the latter. Omitted fields remain absent.

`exportZoneKey()` preserves optional string `key_content` alongside key tag/type.
That field comes from separately reviewed upstream source, not a new raw export
observation. It is private key material: protect returned values and raw
`PayloadMismatchException::$payload` rather than logging them. Import input stays
sensitive; no export/import replay, automatic retry or rollback is performed.

`removeZoneRecord()` takes a one-based zone-file line. When selecting from
`parseDnsZone()`, pass `line_index + 1`, never the zero-based index itself. This
mapping is source-backed and has not been verified by a live record removal.
Use a current zone read and the applicable serial number; a stale or incorrect
line can remove the wrong record. `deleteDomain()` can succeed with `type: null`
when no domain exists, so a successful envelope does not establish deletion.
Domain deletion has no rollback and does not delete an addon's associated
subdomain. `resetZone()` requires `domain` or `zone`; it preserves valid TXT
records but resets other records to defaults, including subdomain records.

The WHM pinned schema lists `nsec3_iterations: 7` and no salt default. These are
pinned WHM declarations, not a promise about every server version's behavior;
omitted values are sent as omissions for the server to resolve. DNSSEC and
resolver/nameserver changes can immediately affect live hosting. Check per-domain
results independently of WHM metadata. No automatic polling or inference of DNS
propagation, authority, trust or DNSSEC validity is added.

All methods have offline contracts. The separate WHM reviewer report on
`11.138.0.19` exercised selected DNSSEC and disposable-account domain/zone calls,
with account, home directory and all three throwaway zones removed. No new
committed capture accompanies these corrections. The focused raw-export/import/
remove-record pass was rejected before execution with `[Modify Shared Resources]`
and was not retried. Those corrections remain source/offline evidence. Server-wide
writes and other remaining variants stay under `8x0.2.42`; existing artifacts are
unchanged.

### PR54 reviewed evidence and source corrections

[Claude review 5720693698](https://github.com/hostingpal/whm-api-client/pull/54#pullrequestreview-5720693698)
reports WHM calls on cPanel `11.138.0.19` using a disposable `wrvdns` account.
DNSSEC enable/disable, add/activate/deactivate/remove key, NSEC3 set/unset,
MX save, selected zone reset, subdomain/parked-domain create/delete, zone add/delete
and a cluster-trust check were exercised. The account, home and three zones were
confirmed absent afterward. Exported DNSKEY `key_id` and add/enable `new_key_id`
were digit strings; these are distinct from earlier UAPI observations.

The reviewer also inspected `Whostmgr/API/1/DNS.pm`, `Whostmgr/DNS.pm`,
`Cpanel/ZoneFile/Parse.pm` and `Cpanel/ZoneFile.pm` on that version. That report
supports optional export `key_content`, import `new_key_id`, and the zero-based
parse index to one-based deletion line mapping. The focused raw-export/import/
remove-record pass was denied before execution with `[Modify Shared Resources]`;
no call ran or was retried. Do not treat the typed export's retained tag/type as
an observation of raw private key content. The pinned `import_key_id` remains
alongside optional `new_key_id`, with strict validation on existing siblings.

Pinned WHM source SHA-256:
`6edb13c51d53f3c2be1fa83cc93e31d3a81f4f8506b3b45284fa00b986041594`.
The pinned WHM NSEC3 values are documented as source declarations, without
assuming the UAPI version-dependent defaults establish WHM runtime behavior.
The secret regression examines library frame arguments recursively; formatted
trace strings can truncate secret markers and are not its proof of redaction.

No live call or new capture ran for this correction. Reviewer reports remain
separate from committed evidence; all historical artifacts are preserved.
Server-wide resolver/nameserver/maintenance writes, the denied operations and
remaining variants stay open under `8x0.2.42`. No DNS propagation, trust or
registrar integration is established by API success.

## Expanded WHM account management

`whm()->accounts()` covers 45 of the 49 pinned functions. The expanded surface
preserves the ten existing methods and adds 23 GET and twelve form POST methods
for inventories, enhancements, conversion status, quota/bandwidth controls,
password policy, Digest Authentication and document roots. Password-strength
calculation uses POST because its password input is sensitive.

JSON personalization calls remain under `8x0.1.18`. Conversion initiation and
bulk modification remain excluded under `8x0.1.29`: their keyed database-copy and
MX fields need explicit support. Omitting conversion copy fields copies no MySQL
databases; the client does not silently drop them. Conversion status supports
one integer job ID; contradictory indexed examples remain in `8x0.1.21`.

`getMaximumUsers()` preserves the pinned optional `users` integer and the
separately observed optional `maximum_users`, normalizing bounded canonical
integer strings in the latter. Missing fields are not evidence of a zero or
unlimited license limit. `showBandwidth()` normalizes bounded canonical integer
strings for `totalused`, nested `bwusage[].usage` and per-account `limit`. The
source-backed limit literals `unlimited` and `N/A` stay strings, distinct from
numeric zero; their spellings were not established by a live capture. `N/A`
deleted-account rows remain unobserved. `domainUserData()`
normalizes its observed port strings and exact quoted `hascgi` and
`phpopenbasedirprotect` flags. Other numeric fields stay strict. Reported extras
`bwlimited` and `phpversion` remain untyped pending field-contract evidence.

`listAccountEnhancements()` returns an `enhancements` list of records with the
pinned optional `id` and `name` fields. This repairs the source's missing
container type; a populated list remains source/offline evidence. An observed
empty list cannot establish item contracts. Privilege results preserve all 61
named fields and opaque third-party ACL values. Omission and explicit null stay
distinct, with each method's units, versions and defaults in generated PHPDoc.

Key arguments come first in quota, document-root and Digest Authentication
setters. Digest configuration exposes only `enabledigest`; its password remains
sensitive. `untrackAcctId()` requires callers to supply `uid`, `gid` or both;
the signature preserves optional selectors and does not add a combination guard.
`forcePasswordChange()` takes serialized JSON object-map text such as
`{"user1":1,"user2":1}`, not a JSON list. The client form-encodes it once and
does not parse or validate the JSON structure.

Enhancement, quota, password/digest and document-root writes can affect accounts
immediately. API success and conversion status do not establish completed
migration or hosting behavior. The client adds no retry, rollback, polling,
purchase or notification. A separate reviewer lifecycle on `11.138.0.19`
exercised six account-scoped POST methods and removed its disposable account and
home directory. Enhancement writes, `untrackAcctId()` and the login-link read
remain unobserved; these reports do not expand the committed GET captures.

`setPrimaryDomainDocroot()` requires an existing target directory according to
the pinned contract, but the reviewed server accepted a missing directory and
returned success. Ensure the target exists before changing the root; API success
is not an existence check and does not establish that the site can serve files.

The immutable nine-GET catalog reads version, user counts, disk usage, home roots,
enhancement/user inventories, privileges and quota availability. The corrected
capture at `af701b1` on `11.138.0.19` passes HTTP/API/payload/probe checks but stays
strictly failed for a masked numeric-string unknown-field observation in
`get_maximum_users`, with pinned `users` absent. The wildcard identifies neither
a key name nor a field count. The separate reviewer observation of
`maximum_users` supports its current contract; it does not identify or rewrite
the historical masked finding. Both original artifacts remain unchanged and
unwaived. No new live call or capture was run by the correction agent; the
separate reviewer lifecycle is described in the source audit. Remaining live
behavior is tracked in `8x0.2.41`; empty-container evidence ambiguity remains
`8x0.2.50`.

### Separate PR55 review evidence

[Claude review 5720788777](https://github.com/hostingpal/whm-api-client/pull/55#pullrequestreview-5720788777)
reports WHM GET observations on cPanel `11.138.0.19` with type-only output.
The independently named `maximum_users` string, bandwidth total/nested usage
strings and domain userdata port/quoted flags support the narrow compatibility
corrections. This report is separate from the masked committed capture and does
not establish historical wildcard identity or count. No new capture accompanied
those initial corrections.

The pinned WHM OpenAPI snapshot (decompressed SHA-256
`6edb13c51d53f3c2be1fa83cc93e31d3a81f4f8506b3b45284fa00b986041594`)
contains the enhancement item fields beneath a node with omitted type; its
list-oriented description and the review support a list of those records.
Populated enhancements remain unobserved. Pinned Digest Authentication alias,
UID/GID requirements and the JSON object-map example support the request/docs
corrections. The ten existing account contracts and source snapshots are retained.

That first review skipped all twelve added POST methods and `getUsersLinks()`.
The subsequent [review 5721383444](https://github.com/hostingpal/whm-api-client/pull/55#pullrequestreview-5721383444)
of integrated `2e52204e68b02974b9afc03a976fb2ea1e6297e6` reports a separate WHM
lifecycle on `11.138.0.19` using disposable account `wrvacct`. Quota changes,
bandwidth limits, Digest Authentication toggles/readback, primary docroot changes,
forced password changes with a JSON object map, and password-strength calls
passed. A wrong Digest password and a JSON list instead of the password-change
object map raised API errors. The reviewer confirmed account and home cleanup.
These are reviewer reports, not records in the historical GET-only artifacts.

The lifecycle exposed digit-string and nonnumeric-string `acct[].limit` values
in `showBandwidth()`; one row with either form failed the previous strict integer
contract. Canonical integer strings normalize within PHP integer bounds without
changing sibling contracts. Nonnumeric values must not be mapped to numeric zero.
The original report retained only the nonnumeric value's type; it did not itself
confirm a literal spelling. [Clarification 6539473950](https://github.com/hostingpal/whm-api-client/pull/55#issuecomment-6539473950)
then cites upstream source on `11.138.0.19`: `Whostmgr/Bandwidth.pm` stores
`unlimited` for a zero limit and assigns `N/A` in its deleted-users branch;
`Whostmgr/Math.pm` preserves those strings and returns native zero for a falsy
stored limit. The contract accepts these exact two strings alongside bounded
canonical integer strings, preserving all forms distinctly. The literal spellings
are source-based, not inferred from the type-only lifecycle report. The reviewer
found no deleted-account rows in a later read, so `N/A` remains source/offline
compatibility. No deletion-state constraint is invented for the limit field, and
untyped `bwlimited` is unchanged. No new mutation was requested or run for the
clarification.

The same reviewer observed `setPrimaryDomainDocroot()` returning success and
`documentroot` for `public_html/nope` even though the directory did not exist.
The pinned existing-directory requirement is a caller prerequisite; this server
did not enforce it. No local filesystem check, automatic creation, retry or
rollback is added by the client.

The five enhancement writes and `untrackAcctId()` remain unobserved, as does the
login-link read. Untyped `bwlimited` and `phpversion` require independent contract
evidence. Remaining operations/variants stay under `8x0.2.41`; the shared
empty-list/object evidence ambiguity remains `8x0.2.50`. No live call or capture
was run by the correction agent, and every earlier artifact is unchanged.

## Expanded WHM hosting plans

`whm()->hostingPlans()` exposes twenty of 24 pinned functions, including package
creation, editing, deletion, extension management, matching, application inventory
and feature overrides. The original eight methods and two deprecated exclusions
are retained, with a narrow team-limit response correction in `listPackages()`.
Dynamic feature-list creation/update fields remain excluded under `8x0.1.31`. The twelve additions use four GET and eight form POST
methods.

Package options preserve exact wire casing, omission, false/zero and literal
`unlimited` values. Quotas use the source's MB units. `editPackage()` changes
accounts using the plan: an omitted `featurelist` assigns the default feature
list to those accounts, and omitted `_PACKAGE_EXTENSIONS` means no extensions.
Omitting an option does not promise to preserve its current value. Read
`getPackageInfo()` first and send the full intended settings. Mailbox-quota edits
do not change existing mailboxes. A package in use cannot be deleted;
`deletePackage()` returns no value. The client performs no retry or rollback.

Feature overrides accept caller-serialized JSON text in form fields, encoded
once, rather than a JSON body or automatically serialized PHP arrays. The scalar
native `string|int` input limitation remains under `8x0.1.23`; integer JSON
semantics are not established. Extension names and optional variables are
case-sensitive. `deletePackageExtension()` removes the named extensions and
associated variables; the typed input does not invent arbitrary variable fields.

`getPackageInfo()` retains named fields and source-documented opaque extension
variables, which may contain private configuration. Its observed nullable
`MAXPARK` remains distinct from omission. The twelve resource-limit integer
alternatives use the established canonical-string compatibility family. A later
independent populated-package review observed numeric-string variants and
`MAX_TEAM_USERS` in the three package reads. That team field accepts bounded
canonical integer strings in `listPackages()` and `getPackageInfo()` while
preserving their existing null and omission behavior; matching retains it using
the same family, with null support source-inferred for that read. Other fields
keep their existing contracts.

`matchPackages()` returns a list of records with optional `name`. Its `CGI`
result preserves strings such as the observed `n`, alongside the pinned integer
0/1 alternative; it does not inherit `getPackageInfo()` flag conversion. With
two packages present, a separate reviewer call without selectors returned one
of two; `MAXSQL=5` found the matching package, while `QUOTA=100` (its real quota)
and `QUOTA=99999` both returned none. The cause is unknown. Matching may return
partial inventory or miss a package with the requested value.

`getAvailableApplications()` supports the reviewed omissions in group and item
records, including optional string group descriptions. Null descriptions were
not observed and remain unsupported by the current contract. Present fields still
validate, and native integer order fields remain strict. Unspecified extension
templates may return metadata only; HTML and URLs
remain payload data and are never executed or fetched.

The original two-GET capture at `a5c08af` on `11.138.0.19` strictly passes with
zero discrepancies. Its empty `matchPackages()` result passed the former
empty-object boundary and establishes no populated shape. That immutable
artifact is not rewritten or repeated. Separate reviewer GET reports support the
contract corrections. A later reviewer separately observed package creation,
quota editing and deletion with disposable account/package cleanup; extension,
feature-list and override writes remain unobserved. Remaining behavior and the
matching contradiction stay under
`8x0.2.43`; shared empty-container evidence ambiguity remains `8x0.2.50`.

The requested three-GET capture at `1336c4d` on `11.138.0.19` records successful
HTTP/API/payload/probe outcomes for version, stock-default package information
and the application catalog. Two masked unknown-field observations in application
item/subitem records keep its strict assessment failed and unwaived under
`8x0.2.43`. Wildcards establish neither field identity nor underlying field count.
The capture is complete and must not be repeated merely to clear findings. No
mutation, arbitrary file read, template execution or URL fetch ran.

### PR56 contract review

[Review 5720810852](https://github.com/hostingpal/whm-api-client/pull/56#pullrequestreview-5720810852)
reports WHM GET reads on `11.138.0.19`. The stock default plan returned null
`MAXPARK`; application group and item records omitted fields that the initial
contract required. Integer package flags and group/item order fields passed and
remain unchanged. The package-info unknown-name path raised `ApiErrorException`.
The reviewer ran no package or feature writes.

The pinned WHM snapshot and existing package-inventory family support canonical
integer strings in the existing package-limit integer alternatives. The reviewed
stock plan had null limits, so populated numeric-limit compatibility is tested
offline and remains separate from that live report. The pinned matching response
calls `pkg` a list; its optional `name` uses the independently documented package
inventory string field. Populated matching rows were unobserved in that initial
review; the later populated-package lifecycle is recorded below.

The existing capture remains byte-identical, including its original effective
contract and empty-container assessment. Artifact SHA-256:
`16cafa35a705d064dabd8e3885b326bcbf6c0defe5b53853fcdc4e21f333bcbb`.
The requested package-info/application probes ran once through the separate
guarded recipe at `1336c4df3c0d48d41362938af4556991d9b249bd`. The new immutable
artifact is `2026-10-31-whm-hosting-plans-reviewed.json`, SHA-256
`fa47311ac73ed16812b86cd1a6367b921f2b2484dd4ffb0f3c083cc6db3497ab`.
Its strict failure retains the two masked unknown-field observations at
`$.groups[].items[].*` and `$.groups[].items[].subitems[].*`. No known-field
mismatch or unsuccessful API call occurred. Stock package limits were null;
canonical numeric-limit strings and populated matching rows were not observed
by this capture. The later reviewer lifecycle below separately observes them.
Application groups/items/subitems were populated, and the optional group
description appeared as string or absent. This structural capture does not
establish row counts or raw values. All 85 earlier artifacts and 24 pinned
source files remain byte-identical. No resource was created or changed.

[Clarification 6539624881](https://github.com/hostingpal/whm-api-client/pull/56#issuecomment-6539624881)
reports the same type counts with and without `raw`: one of 29 group rows had a
string `description`, and 28 omitted it; 204 item rows had strings and two omitted
it. No null or empty-string description was observed. The group field is typed
as optional string from this live report, not the pinned `Group` schema, which
has no such property. The cited source's truthy rewrite does not prove that null
can never occur; null remains an unobserved variant under `8x0.2.43`. No mutation
ran for that clarification, and no historical capture is reinterpreted.

### PR56 populated-package follow-up

[Review 5721514363](https://github.com/hostingpal/whm-api-client/pull/56#pullrequestreview-5721514363)
reports a separate WHM lifecycle on `11.138.0.19` at `1336c4df` (the same
executable contracts as `8a3c6b1a`). `listPackages()` and `getPackageInfo()`
failed on digit-string `MAX_TEAM_USERS`, including a created package with no
explicit team limit. Matching returned the exact CGI string `n` and a team
limit that the previous contract dropped. Three response specs are corrected:
canonical bounded team strings on all three reads, a retained matching team
field, and string CGI alongside its pinned integer 0/1 alternative. Matching
team null remains source-family compatibility from the two documented reads,
not an observed matching value. No other original method contract is widened.

The reviewer observed populated matching and numeric-string variants separately
from the committed stock-package captures. The type-only report does not name
every numeric-string resource field, so the twelve existing integer-arm
corrections retain their family attribution instead of claiming each was newly
observed. Selector-free matching returned one of two plans; MAXSQL=5 matched,
while QUOTA=100 (the real quota) and QUOTA=99999 did not. No cause is inferred.

`addPackage()` and `editPackage()` returned string `pkg` results. A quota-only
edit preserved previously set omitted limits. The feature list was already
default, so the pinned feature-list reset warning remains source-based; this
run also establishes no extension reset behavior. Deleting an in-use package
raised `ApiErrorException` and retained the package; deletion succeeded after
the account was gone. The first reviewer cleanup check failed on typed
`listPackages()`, leaving the disposable package temporarily. The reviewer
subsequently removed it through `killpkg` and verified only the original package
remained, with disposable account and home absent. These separate reviewer runs
are not committed mutation captures, and this correction chat made no live call.

The reviewer explicitly finds the two masked application observations
nonblocking, while requiring the strict report to remain failed and unwaived.
Separate named observations include item `leika` (array) and `cpanel_os_check`
(string), and subitem `role`/`description` (strings) and `multiuser_required`
(integer). Those independently observed keys do not identify historical masked
entries or their counts. No new fields are inferred or typed from the wildcard
artifact. Optional subitem expansion is not required for this correction.
Follow-up `8x0.2.43` retains the findings, matching discrepancy, remaining
variants and unobserved extension/feature-list/override writes. Both captures
and every earlier artifact remain immutable; no new capture is required merely
to obtain a green strict result.

## WHM API tokens, sessions and introspection

`whm()->apiTools()` exposes twelve functions from the seventeen-function API
Development Tools group: token lifecycle, session/login-link creation, application
inventory and legacy API usage. Five reads use GET and seven calls use form POST.
The remaining five execution wrappers are separate work under `8x0.4.17`.

`updateToken()` requires the token name, ACL, IP restriction and expiration as
explicit arguments. These replace the complete current settings. The pinned
upstream contract says omitted ACL grants all caller privileges, omitted IP
restrictions allow any IP, and omitted expiration means never expire. A rename
must therefore include the intended settings too; omission is not preservation.
Use `whitelistIp: 'any'` or `expiresAt: 0` only to deliberately choose those
unrestricted/non-expiring states. `createDashboardToken()` puts `tokenName`
before `acl`, matching token creation's key-first order.

ACL, whitelist and revoke-name inputs currently represent one scalar field.
The typed create/update methods cannot create or preserve multiple ACLs or IP
restrictions. Do not rename a multi-value restricted token through this scalar
update interface or substitute `all` to work around it. Source ACL prose/examples
disagree about starting indices, and whitelist examples disagree about spelling.
The existing numbered encoder does not establish these forms. Their explicit
encoding and validation remain priority-one work under `8x0.1.21` before release.

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

### PR57 contract review

[Review 5720856204](https://github.com/hostingpal/whm-api-client/pull/57#pullrequestreview-5720856204)
reports five WHM GET methods on `11.138.0.19` with type-only output. It identifies
`get_api_pages` returning an empty named `result` list and cites
`Whostmgr/API/1/Sys.pm` using the same data-loading path as `get_api_calls`.
The pinned call-statistics schema independently supplies the row shape; populated
page rows remain source/offline evidence. The historical masked observation
cannot establish that key's identity or count. Pinned flat page fields remain
available alongside the optional list.

The same review observes array-valued `whostmgr[].url`; list string elements use
the pinned scalar URL family and are not claimed as independently counted live
variants. `cpanel[].demo` has no reported type and stays untyped under `8x0.2.44`.
The committed catalog covers four typed API-tools reads plus version; the
AppConfig observation is separate. Invalid statistics type raised
`ApiErrorException`; token inventory and application-name reads passed. The
reviewer ran none of the seven POST methods.

The required update inputs and reset warnings follow the pinned
`api_token_update` descriptions. Multi-value ACL/IP support remains priority-one
`8x0.1.21` work before release: the existing unsuffixed-first numbered encoder
cannot be substituted for contradictory source zero-/one-based examples.
No credential change was attempted to resolve the documentation conflict.

[Clarification 6538502288](https://github.com/hostingpal/whm-api-client/pull/57#issuecomment-6538502288)
withdraws the requested empty-object-to-null mapping. Pinned recognized-present
versus unrecognized-absent data is retained; present empty objects remain
unobserved and do not establish recognition. No lookup was requested or run.
Token-creation mismatch recovery and no-retry guidance are client policy based
on accepted-outcome validation, not new live token-creation evidence. The
original artifact and all earlier evidence remain unchanged.

## Expanded WHM web-server configuration

`whm()->webServer()` exposes 28 of 30 pinned functions: eighteen GET reads and
ten form POST operations for EasyApache profiles, PHP/FPM configuration, INI
content/directives and PHP versions. The two FPM JSON-body operations remain
excluded under `8x0.1.18`.

Package, tag and directive lists use repeated wire fields; virtual hosts use the
reviewed numbered family. `ea4SaveProfile()` omits an explicitly empty optional
tag list, matching the pinned empty-list default. Required package lists still
reject an empty list. Other repeated inputs keep their existing behavior; this
does not establish an empty-list clearing convention. Handler names remain open
strings so values returned by `phpGetHandlers()` can be supplied to
`phpSetHandler()` without a closed PHPDoc enum.

INI content/directives and pool configuration inputs stay sensitive and are
encoded once. Pool parameters accept caller-serialized JSON text; they do not
introduce JSON bodies or automatic PHP-array serialization. The native
string|int limitation remains under `8x0.1.23`.

Impacted-domain selectors are individually optional according to explicit
either-or-both source prose, but callers must supply a domain or a true
system-default selector. A no-selector request raised `ApiErrorException` in the
reviewer run. False alone is insufficient, and parked domains are excluded.
`phpGetVhostsByVersion()` returned an empty list for an unknown version. These
are server behaviors; the client adds no error-text matching or selector guard.

According to the reviewed upstream PHP implementation, `phpSetVhostVersions()`
loads existing version/FPM state and modifies supplied values, so omitting
`phpFpm` preserves current FPM state. Explicit false is still encoded as `0`.
This source-based behavior is not a live write observation. Version floors,
role restrictions, PHP-FPM prerequisites and profile overwrite behavior remain
in each method's PHPDoc. Configuration changes can affect hosted sites
immediately; the client adds no retry, conversion polling, rollback or reload.

Profile version fields preserve strings and native integers/floats. Numeric
cPanel rows were captured; custom and vendor numeric versions are source-family
compatibility, while the reviewer observed CloudLinux strings. Other profile
fields remain strict. Vhost `phpversion_source` accepts the pinned list of
optional-field records or an object with optional `domain: string` and
`system_default: int`. Those field identities come from the pinned source,
not masked capture keys. No string-to-integer conversion is added there.

The INI-content, INI-directive and default-accounts-to-FPM writes accept omitted
or null successful data based on the reported upstream empty-result family;
these variants remain source/offline only. Present payloads still undergo their
declared validation. INI reads and mismatch payloads may contain private
configuration. EA4 metadata retains pinned object values plus string and
string-list variants, without inferring other scalar/null types.

The fixed nine-GET catalog covers version, additional package prefixes,
installed packages, profiles, EA4 metadata/recommendations, EOL PHP counts,
FPM capacity and vhost versions. It reads no INI content or arbitrary file,
changes no configuration and fetches no returned URL. Capacity estimates do
not prove safe conversion or serving behavior. The corrected capture at
`b535c57` on `11.138.0.19` has nine HTTP/API/payload/probe passes, with SHA-256
`9bff10d45c4bebe06189c8b0039efe5e625e2f08c6653cdefc40c0785af53d66`.

Two masked unknown-field observations remain strict failed and unwaived:
profile `$.cpanel[].*` strings and recommendation `$.*[].*` string lists.
Masking establishes neither identities nor underlying field counts. Both
historical artifacts are immutable. The separate reviewer report records
22 successful GET calls, including INI reads with type-only output, and finds
the masked observations nonblocking. All ten POST methods remain unobserved;
no new call or capture ran for these corrections. Follow-up `8x0.2.45` retains
the remaining variants and evidence limits.


### PR58 review evidence and source boundaries

[Review 5720977511](https://github.com/hostingpal/whm-api-client/pull/58#pullrequestreview-5720977511)
reports cPanel `11.138.0.19` through WHM, GET calls only. Its 22 successful
reads include INI content/directives with type-only output; they are separate
from the committed nine-GET catalog. The no-selector impacted-domain error
and unknown-version empty vhost list are also reviewer observations.

The review cites `Whostmgr/API/1/Lang/PHP.pm` for the three empty-result write
contracts and for modifying only supplied PHP/FPM settings. No source hash or
line was supplied, and no write observation is inferred. Optional payloads
retain strict validation for present data. The pinned vhost-source fields
independently justify the typed object alternative; the historical masked
object-string observation establishes neither those names nor integer values.
Custom/vendor numeric profile versions use the shared source format, rather
than an invented populated live observation.

The `ea4_save_profile` tag schema independently supplies an optional repeated
string list with empty-list default. The explicit `omit_empty` parameter
setting is client policy: only empty tags opt in to omission, with existing
required package and malformed-list checks retained. Shared generic calls and
other list inputs retain their behavior. Neither source nor offline tests
establish a new server-side empty-list clearing operation.

Original capture `3d43621` SHA-256
`1522b42c63825127dc0f925e953c545f93ae89ad4c9df2c9d8cc99307a35b31b`
retains its three known-field mismatches and two masked observations. Corrected
capture `b535c57` keeps both masked strict findings. Both are complete and
unchanged; no capture is repeated to clear them. All ten POST methods, serving
effects and conversion behavior remain unobserved under `8x0.2.45`.

## WHM account transfers

`whm()->transfers()` exposes fourteen functions: six GET reads and eight form
POST operations for transfer sessions, controls, metadata, analysis, logs and
archive deletion. The module-dependent form operation `enqueue_transfer_item`
remains outside the typed interface under `8x0.1.22`. After creating a root
transfer session, enqueue the intended items with `whm()->call()` and their
source-documented module parameters before calling `startTransferSession()`.
A returned process ID does not prove that any items are queued or completed.

`createRemoteRootTransferSession()` puts `host` and `user` first; use named
arguments for its flags and authentication alternatives. The non-root creation
method has no username parameter. Its required `unrestrictedRestore: true`
argument sends the pinned literal `1`, skipping Restricted Restore; other values
are rejected before a request. Passwords and key passphrases stay sensitive.
The source requires unrestricted restore to be disabled in the root flow when
using the enqueue operation's `force` option. Remote checks and session creation
can contact another host, and transfer controls can change account state.

Module names are open strings. On reviewed WHM `11.138.0.19`,
`FeatureListRemoteRoot` works while the pinned `FeaturesListRemoteRoot` spelling
fails. Module priority values remain strings. The analysis flag
`skip_reseller_privs=1` means reseller privileges transfer by default, despite
its name. `valid_for_transfer` means suitable for a transferred account but not
a new account; it is not a general username-validity flag. Analysis
`remote.conflicts` remains opaque because its pinned object type conflicts with
its error-message description; populated analysis is unobserved.

Logs and analysis may contain private paths or configuration. Unknown-session
reads exposed internal paths in the separate reviewer report. Treat upstream
errors and payloads as sensitive. The client does not retry, poll, roll back,
execute returned text or follow URLs. Archive deletion can remove backup files.
The original three-GET metadata capture is complete and immutable; all eight
POST methods and populated sessions/logs/analysis remain unobserved under
`8x0.2.47`. See the [coverage report](coverage.md)
for evidence boundaries and input exclusion `8x0.1.22`.

### Review corrections and provenance

Review [5720977719](https://github.com/hostingpal/whm-api-client/pull/59#pullrequestreview-5720977719)
compared the client with the pinned WHM OpenAPI source (decoded SHA-256
`6edb13c51d53f3c2be1fa83cc93e31d3a81f4f8506b3b45284fa00b986041594`)
and GET observations on `11.138.0.19`. The source's required integer enum `[1]`
for `unrestricted_restore` is enforced through an explicitly selected native
`true` guard and existing boolean encoding. This extends the reviewed singleton
correction to required inputs while preserving source and final requiredness,
nullable/composition exclusions and all existing optional ACL behavior. Other
singleton enums do not acquire this behavior automatically. Native argument
rejection occurs before the runner; it does not claim a runner metadata reset.

The module parameter's PHPDoc is an open string, and source/observed notes retain
the spelling contradiction. The pinned six-name list is not a closed inventory.
Only `remote.conflicts` changes its response type to `mixed`; sibling contracts
remain strict. Its flexibility is source-based, not a populated live observation.
The source-defined inverted flags are documented without adding cross-field
validation. Session ID precedes logfile, and root creation host/user precede
flags. Secret annotations, request methods, outcomes, response unwrapping and
the typed enqueue exclusion are preserved. Synthetic module priorities now
satisfy the positive-integer-string source pattern.

The separate reviewer report observed module-schema reads for `AccountLocal`
and `FeatureListRemoteRoot`, an API error for the typo and unknown module,
username validation for existing/free names, and API errors for unknown-session
state/log/analysis reads. Those reads created nothing on disk. They establish no
populated sessions, logs, remote analysis or remote-host contact. No POST was run.

The original bounded capture at `a5badae` records exactly three WHM GETs: version,
available modules and designated `npr` username validation. All HTTP, API,
payload, probe and strict checks pass with zero discrepancies. It observes
populated module priorities as strings and the four integer validation flags.
Artifact `2026-10-31-whm-transfers.json` SHA-256:
`8a8d004b5b765c7f79862d8a177d93855757617eada816812a6983b4116a0a1e`.
It is unchanged. The expanded catalog adds the safe module-schema read; a separate
reviewed recipe selects only version and `FeatureListRemoteRoot` schema for the
new evidence request, without replaying the completed catalog. Module schema
strings stay inert payload data. Remaining live behavior stays under `8x0.2.47`.

### Reviewed transfer module schema capture

The new [reviewed transfer capture](evidence/2026-10-31-whm-transfers-reviewed.json)
at `d81e43a8456dc7a9370d5950ac196b226691ae79` on WHM `11.138.0.19`
contains exactly two GETs: version and `transferModuleSchema(module:
'FeatureListRemoteRoot')`. Both HTTP/API/payload/probe checks and the strict
assessment pass, with zero discrepancies. The module schema has populated
wildcarded key records with string `def` fields and string `primary`/`required`
lists. Only sanitized paths and types were saved; no key names, definitions or
list values are retained. Schema text was not executed. This completes the
requested additional probe and does not replay or replace the original capture.
No remote host, remote credentials, session ID, log or archive target was supplied;
no transfer, resource mutation, cleanup operation or URL fetch occurred. All
eight POST methods and populated session/log/analysis data remain unobserved.
SHA-256: `ecd22c05ac50d5b2dcfc2ad3c90c0ff6c26b21f059268726b31f163de506bd7e`.
