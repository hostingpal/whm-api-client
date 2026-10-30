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
also deletes the FTP home directory, `kill_session` without a login targets all
account sessions, and omitted quota settings can remove the quota. Both anonymous
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
