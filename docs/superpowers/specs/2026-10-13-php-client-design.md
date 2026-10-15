# PHP client interface and UAPI routing

Status: public interface direction agreed on 2026-10-13. Base configuration and
WHM/UAPI account selection are implemented under `whm-api-client-8x0.1.3`.
The [first read-only slice](2026-10-13-first-api-slice-design.md), tracked under
`whm-api-client-8x0.1.4`, implements WHM version and UAPI domain listing with
authenticated execution, response parsing, and metadata. The domain/PHP inventory
slice (`whm-api-client-8x0.1.5`) adds WHM domain records and UAPI installed PHP
versions. The server identity/health slice (`whm-api-client-8x0.1.6`) adds WHM
hostname, load averages, and disk partition usage. The account lifecycle slice
(`whm-api-client-8x0.1.16`) adds form-encoded POST execution, a per-request outcome
policy, generic `call()` on both handles, and the WHM `accounts()` group
(`createacct`, `suspendacct`, `unsuspendacct`, `removeacct`, `passwd`,
`changepackage`, `modifyacct`, `listaccts`, `accountsummary`, `listpkgs`); see the
[scaling decision](#scaling-decision-typed-core-plus-generic-calls). Other endpoint
groups remain open.

Decision record: `whm-api-client-8x0.1.2`. Runtime execution and authentication:
`whm-api-client-8x0.1`. Concrete payload returns and response metadata:
`whm-api-client-8x0.1.1`.

## Public interface

Expose WHM operations through `whm()` and cPanel UAPI operations through
`uapi(?string $user = null)`. The configured credential type determines whether
UAPI calls go directly to cPanel or through WHM. The bridge is internal; callers
do not need a separate public `bridge()` method.

> **Superseded:** the operation table below describes the original hand-written
> slice. The typed groups are now generated from the pinned map plus
> `docs/api/overlay/`; the current operation surface is listed in
> [`docs/api/coverage.md`](../../api/coverage.md) and designed in the
> [generated-client design](2026-10-14-generated-client-design.md). Method names and
> plain-array results there replace the names and DTOs here.

```php
// Client configured with WHM credentials, such as a root token.
$version = $client->whm()->server()->version();
$accountDomains = $client->uapi('npr')->domains()->list();

// Client configured with the cPanel account npr and its cPanel token.
$accountDomains = $client->uapi()->domains()->list();
```

Use declared classes and methods with plural resource groups where appropriate
and camelCase operation names. These provide autocomplete and concrete parameter
and return types for PHPStan. Keep an explicit mapping from each PHP operation
to its documented upstream operation; friendly names do not change wire names.

| PHP call | Documented upstream operation | Status |
| --- | --- | --- |
| `whm()->server()->version()` | WHM `version` | Implemented; returns `string` |
| `whm()->server()->hostname()` | WHM `gethostname` | Implemented; returns `string` |
| `whm()->server()->loadAverage()` | WHM `systemloadavg` | Implemented; returns `ServerLoadAverage` |
| `whm()->server()->diskUsage()` | WHM `getdiskusage` | Implemented; returns `ServerDiskUsage` containing `DiskPartition` records |
| `uapi()->domains()->list()` | UAPI `DomainInfo/list_domains` | Implemented; returns `AccountDomains` |
| `whm()->domains()->list()` | WHM `get_domain_info` | Implemented; returns `ServerDomains` containing `DomainInfo` records |
| `uapi()->php()->listVersions()` | UAPI `LangPHP/php_get_installed_versions` | Implemented; returns `list<string>` |

The same UAPI mappings apply when a username selects access through WHM.
WHM domain information and UAPI account domains have different scopes and
payloads; similarly named PHP methods retain their own return contracts.

API selectors and resource group accessors perform no I/O. The terminal operation
executes the request immediately and returns the documented payload as a DTO,
collection, scalar, or `void`, as appropriate. There is no trailing `execute()`
step and no queue of pending commands implied by chaining.

The implemented domain signature is
`list(?bool $hideTemporaryDomains = null): AccountDomains`. Null omits the query
parameter, retaining the documented default `0`; false sends `0`, and true sends
`1`. True hides temporary domains from the main domain and the addon, parked, and
subdomain lists. `AccountDomains` retains optional `main_domain`, `addon_domains`,
`parked_domains`, `sub_domains`, and `is_temporary` fields. Its corresponding
camelCase properties use null for omitted fields, while `toArray()` preserves
presence and distinguishes omitted main domain from explicit null. Domain lists
are `list<string>`; temporary flags are `array<string, 0|1>`. Empty strings, lists,
and flags remain unchanged; an empty temporary-domain object becomes an empty
array map. Unknown extensions are outside this reviewed DTO contract.

The domain operation can return blank values without an API error when its
userdata file has incorrect permissions. Successful status alone does not
establish completeness. WHM version returns the supplied version string without
semantic-version conversion; a missing or invalid version raises
`InvalidResponseException`.

The server identity/health methods use the same explicit, immediate WHM execution
path without parameters or a cPanel account target. `hostname()` requires a string
and returns it unchanged. `ServerLoadAverage` preserves optional `one`, `five`,
and `fifteen` windows as `int|float|numeric-string|null` properties. Both properties
and `toArray()` retain the finite decimal strings observed live, including trailing
zeros and precision; strings allow an optional sign and decimal fraction, without
whitespace or exponents. No unit conversion is inferred from upstream prose.

`ServerDiskUsage` is an immutable iterable/countable collection with
`?list<DiskPartition> $partitions`. Its export retains the original `partition`
key, distinguishing omitted from empty. All 12 partition fields are optional;
disk sizes are integer kilobytes, and inode values are integer counts/percentage.
Runtime compatibility accepts disk `percentage: 0` and explicit null for all
four inode fields. Other pinned minima remain enforced. Properties use null for
omitted fields and unavailable inodes, while exports retain explicit null presence.
Endpoint and DTO offline tests cover these representations; the opted-in live
suite verifies all three methods. Evidence is recorded separately in
[source-audit.md](../../api/source-audit.md#runtime-observations-2026-10-13), without
altering pinned schemas or declaring revision-bound live parity coverage.

WHM `get_domain_info` returns immutable `ServerDomains`, an iterable/countable
collection of immutable `DomainInfo` records. Its optional `domains` property is
null when omitted; iteration/count treat omission as an empty collection, while
`toArray()` retains omitted versus empty-list presence. The 14 reviewed record
fields are optional; properties use null for omission and `toArray()` preserves
explicit nullable `ipv6`, blank strings, port wire values and 0/1 flags. Domain types
remain unrestricted upstream strings. The upstream inventory excludes service
subdomains and automatically created domains. Unknown extensions are ignored.
Ports are integers in the pinned schema; the test server returns decimal strings.
Canonical nonnegative decimal strings within PHP integer range are also accepted.
Properties expose integers while `toArray()` retains the integer or numeric-string
wire representation. This runtime compatibility does not alter the pinned sources.

UAPI `LangPHP/php_get_installed_versions` returns the `versions` list unchanged,
including custom PHP package names and empty lists, in the selected account
context. A missing or malformed list cannot satisfy `list<string>` and raises
`InvalidResponseException` with the response snapshot. EasyApache 4 and the
WebServer role are upstream prerequisites. Both direct and bridged routes share
this public method, and each checks its documented success outcome. Offline contract
tests and the separately opted-in PHPUnit `live` group cover the implemented routes.
Actual server observations remain separate from pinned contracts and saved,
revision-bound parity evidence.

## Credentials and account selection

The authentication username identifies the owner of the configured token. The
UAPI target username identifies the cPanel account whose data the operation uses.
For direct UAPI these are the same account. With WHM credentials they can differ.

```php
public function uapi(?string $user = null): UapiClient;
```

| Configured credentials | Requested target | Behavior |
| --- | --- | --- |
| cPanel token for `npr` | Omitted or `null` | Direct UAPI as `npr` |
| cPanel token for `npr` | `npr` | Direct UAPI as `npr` |
| cPanel token for `npr` | Another account | Reject the account mismatch before making a request |
| WHM root or reseller token | Explicit cPanel account | UAPI through WHM for that account, subject to permissions |
| WHM root or reseller token | Omitted or `null`, with a configured default cPanel account | UAPI through WHM for the configured default account |
| WHM root or reseller token | Omitted or `null`, without a default cPanel account | Report a missing-target error before making a request |

`root` may be the WHM authentication username. It is not an implicit UAPI target
or a shorthand for all cPanel accounts. A cPanel token does not gain impersonation
capability when another username is passed to `uapi()`. Credential type is explicit
in client configuration; the library does not guess it from the token string.
WHM operations require WHM credentials.

The default account setting belongs to client configuration. The client
accepts an RNIDS-style options array or immutable `ClientConfig` through
`Client::__construct`, plus a required PSR-backed `HttpTransport`. See the
[base client configuration](../../../README.md#base-client) for the implemented
options and validation behavior. Handles do not expose the configuration or its
credentials: an account-scoped UAPI handle built from WHM root credentials must
not hand out the root token. Credentials refuse serialization.

## Return conventions

Decided 2026-10-13 (`whm-api-client-8x0.6`, review of the first slices): a public
value never exposes wire-type variants. Records whose fields vary in wire type
(pinned integers plus observed strings or booleans) are immutable DTOs. Their
camelCase properties are normalized (flags to `0|1`, numeric-string ports to
`int`) and use null for omission, and `toArray()` returns the reviewed wire shape
with original keys, presence, and values. Lists of such records are
iterable/countable containers whose list property is null when the upstream member
is omitted. Precise PHPStan array shapes remain for payloads whose fields each have
a single type, and scalars or `list<string>` are returned directly. `DomainInfo`
ports and the WHM feature records (`FeatureCatalog`, `FeatureListData`,
`UserFeatureSettings`) follow this rule. Load averages are the one deliberate exception: `ServerLoadAverage` keeps
`int|float|numeric-string` because no lossless normalization preserves their
precision and trailing zeros.

Amended 2026-10-14 (`whm-api-client-8x0.7`): new typed groups return precise array
shapes rather than wire-preserving DTOs. Where a field's observed wire type differs
from the pinned type, the shape normalizes it when the conversion is lossless (the
account record's decimal-string `uid` becomes `int`); the raw payload stays
available through `call()`. Existing DTOs keep their contracts.

## Shared UAPI implementation

Direct and bridged access expose the same `UapiClient` interface, operation groups,
and payload types. Shared UAPI operation implementations delegate execution to
the selected route. That route handles authentication, request addressing, and
response envelope parsing while preserving the documented operation contract.

| Route | Normal HTTPS address | Authentication and account context |
| --- | --- | --- |
| WHM | `https://host:2087/json-api/<function>` | WHM username and token |
| Direct UAPI | `https://host:2083/execute/<Module>/<function>` | cPanel account username and token |
| UAPI through WHM | `https://host:2087/json-api/uapi_cpanel` | WHM credentials plus the target cPanel account |

The bridge supplies `api.version=1`, `cpanel.user`, `cpanel.module`, and
`cpanel.function` according to the reviewed contract. It preserves both outer
WHM and inner UAPI outcomes; outer success alone does not establish UAPI success.
HTTP methods, parameter encoding, and payload schemas remain source-driven.
Bridge coverage does not establish direct cPanel-token authentication coverage.

Operation groups receive one synchronous request callback with an operation name
and query parameters. Each group builds its endpoint-specific parameters and
parses its payload; family handles own route construction. All UAPI groups use
one account-bound route helper, with the module bound when selecting the group.
Reserved bridge fields take precedence over endpoint parameters. Selecting or
caching a group does not execute the callback or reset metadata.

Request execution resets metadata before query validation and I/O, then records
a usable envelope before API or payload errors. Groups never validate request
parameters before calling the request callback: multi-value input is passed
through as `list<string>` (repeated keys) or `NumberedList` (WHM `name`, `name-1`,
... convention), and the shared encoder validates it after the reset. Payloads are
validated through the internal `PayloadReader`, which attaches the response
snapshot to every `InvalidResponseException`.

The core remains framework-independent, using PSR-18 and PSR-17 interfaces.
Laravel integration remains a separate Composer package.

The PSR-18 client is explicit and must verify TLS, disable redirects, and set
timeouts for authenticated requests. `HttpTransport::discover()` is the explicit
opt-in to a discovered client whose defaults the library cannot verify; PSR-17
factories may still be discovered. The API layer rejects HTTP 3xx responses and
does not retry requests. Query values use RFC 3986 encoding, preserving dotted names.

## Account isolation and response metadata

Each selected UAPI client retains its account and credential context. Selecting
another account must not change an existing client's context:

```php
$npr = $client->uapi('npr');
$other = $client->uapi('anotheruser');

$domains = $npr->domains()->list(); // Still operates on npr.
$meta = $npr->responseMeta();
```

Repeated `whm()` and default `uapi()` access returns stable configured client
handles. Retaining the selected client, as above, makes metadata ownership
explicit for account-specific calls. Account selection must never rebind a
shared mutable client or expose another account's response metadata.

`responseMeta(): ?ResponseMetadata` belongs to the selected API client and
describes its latest attempted request. It performs no I/O and is initially
`null`. Clear previous metadata at the start of each request attempt so a failed
attempt cannot expose stale metadata. Populate an immutable snapshot when a
usable response envelope is available, including before an API-level exception;
the exception retains the same snapshot. Previously retained snapshots remain
unchanged after later calls.

Preserve WHM and UAPI metadata separately for bridged calls. Apply each documented
operation's status semantics rather than assuming every status field represents
generic success or failure. Concrete payload returns do not require a universal
response wrapper. Last-request metadata initially assumes synchronous use; future
concurrent execution requires per-request snapshots.

The implemented `ResponseMetadata::$whm` map contains the WHM envelope's raw
`metadata`; `$uapi` contains the UAPI result envelope without `data`, preserving
available status, errors, messages, warnings, and metadata. Nested JSON metadata
objects become arrays. Either map may be null when unavailable. Typed accessors
(`whmResult()`, `whmReason()`, `uapiStatus()`, `uapiErrors()`, `uapiWarnings()`,
`uapiMessages()`) read the documented envelope fields and return null or an empty
list for absent or non-conforming values, skipping non-string list entries. `errors()` combines the WHM reason when
the WHM result is `0` with the UAPI errors.

`ApiException` exposes public readonly `?ResponseMetadata $metadata`, matching
the selected client's snapshot for that attempt. Its subclasses distinguish
`ApiErrorException` (WHM or UAPI outcome failure), `HttpException` (HTTP outside
200–299), `InvalidResponseException` (unusable envelope, outcome, or payload; its
`PayloadMismatchException` subclass marks a payload mismatch after an accepted
envelope and outcome, per the generated-client design), and
`TransportException` (PSR transport failure). `ApiException::errors()` exposes
the snapshot's upstream error text. Messages exclude raw bodies, credentials, and
upstream error text. Transport failures omit the original exception chain because
it can retain authenticated requests; they record the PSR failure kind
(`network`, `request`, `other`), the cause class, and the cause message with the
configured token redacted, outside `getMessage()`. Metadata preserves upstream
diagnostic values.

## Scaling decision: typed core plus generic calls

> **Superseded:** the hand-written lean-pattern group decision below is replaced by
> the [generated-client design](2026-10-14-generated-client-design.md) (bead
> `whm-api-client-8x0.10`). The foundation and generic-call tiers remain in force.

Decided 2026-10-14 (`whm-api-client-8x0.7`). The pinned map holds 1,323 functions
and 1,325 variants. The first 29 hand-written operations cost about 90 source and
150 test lines each, which extrapolates to well over 100,000 lines, and the runtime
discrepancies found among them (bare direct UAPI envelope, string ports, string and
boolean flags, null inodes, decimal `uid`) show that strictly generated DTOs for
every operation would fail on real servers. The library therefore has three tiers:

1. **Foundation** (done): transport, credentials, routes, envelope reading with a
   per-request `OutcomePolicy`, GET and form-encoded POST execution, metadata and
   typed exceptions.
2. **Generic calls**: `whm()->call()` and `uapi()->call()` reach every documented
   function through that foundation and return `CallResult` (payload converted to
   arrays, presence flag, metadata) without a schema. They are infrastructure in
   the coverage registry, never endpoint coverage.
3. **Typed groups on demand**: reviewed operations that consumers rely on
   (inventory, server health, PHP, features, and the account lifecycle that
   HostingPal's provisioning needs), hand-written in the lean pattern below with
   offline contract tests, registry entries and, for read-only operations, live
   verification.

Generating thin signatures from the map remains possible later, once the typed
groups show which shapes consumers need; it is not pursued now.

### Lean pattern for new typed groups

- Precise `@phpstan-type` array shapes are the default for inputs and records;
  DTO classes are reserved for records that need behaviour or iteration.
- Present fields are validated against their pinned types with the shared
  `PayloadReader`; unknown fields are ignored; documented enumerations are not
  enforced at runtime. A field whose observed wire type differs from the pinned
  one is normalized when lossless and the observation is recorded in the source
  audit; wire preservation (`toArray()`) is not extended to new groups.
- Docblocks carry a one-sentence summary, a short description of scope,
  parameters, return and failure semantics, the pinned API-map identity and the
  official `@see` link; lifecycle narration (metadata reset, no retries, envelope
  retention) lives once on the group class and the base client.
- Mutations use POST; secret-bearing parameters never use GET.
- Registry entries claim only the contract units a method actually sends and
  declare `partial` when alias or alternative parameters are left to `call()`.

## Implementation verification

Runtime implementation must exercise both UAPI routes with offline fixtures,
including matching payload types, distinct envelope parsing, and independent
coverage evidence. Verify omitted and explicit targets, defaults, permission
failures, cPanel account mismatches, and account isolation. Verify that selectors
perform no I/O and terminal operations execute immediately without implicit retries.

Metadata tests cover initial state, successful calls, API errors over HTTP 200,
HTTP errors with decodable envelopes, transport failures, malformed responses,
consecutive requests, retained immutable snapshots, and per-client isolation.
Record endpoint mappings and contract evidence in the existing coverage registry.
Runtime implementation and endpoint coverage remain tracked in Beads.

Direct UAPI supports the pinned `result` wrapper and a bare result object observed
at runtime. A present `result` field takes precedence even if malformed; only its
absence allows a top-level `status` field to identify the bare form. Outcome and
payload validation still apply. The WHM bridge retains its `data.uapi` wrapper.

An authorized direct `DomainInfo/list_domains` request on 2026-10-13 authenticated
with the `npr` account's cPanel token on `test.oblak.host:2083` and returned HTTP
200 with integer status `1` in the bare envelope. The library recheck with the
updated parser returned the expected main domain and HTTP 200/UAPI status `1`
metadata, with null WHM metadata and `isBridged() === false`. This verifies direct
library execution for that operation, account, server, and date. See the separately
recorded
[runtime observation](../../api/source-audit.md#runtime-observations-2026-10-13).
The internal bridge used for domain listing does not establish complete generic
`uapi_cpanel` coverage, and runtime observations do not alter the pinned contracts.

## Contract sources

The [pinned source audit](../../api/source-audit.md) records authentication,
family boundaries, bridge parameters, and response discrepancies. The
[source manifest](../../api/sources/2026-10-13/manifest.json) identifies the exact
official snapshots used for the operation mappings above. Ordinary checks remain
offline and do not refresh those snapshots.

Official references: [cPanel API tokens](https://api.docs.cpanel.net/cpanel/tokens),
[WHM bridge guide](https://api.docs.cpanel.net/whm/use-whm-api-to-call-cpanel-api-and-uapi),
and [WHM UAPI execution](https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel).

Client organization follows the configuration and service boundaries in
[RNIDS Client](https://github.com/oblakhost/rnids-rsreg-client-php/blob/master/src/Client.php)
and its [configuration factory](https://github.com/oblakhost/rnids-rsreg-client-php/blob/master/src/Config/ClientConfigFactory.php).
RNIDS uses EPP and supplies no WHM authentication or HTTP contract. The
[PHP GitHub client HTTP builder](https://github.com/KnpLabs/php-github-api/blob/master/lib/Github/HttpClient/Builder.php)
provides a reference for PSR injection and discovery. These are architectural
references; their source code is not copied into this package.
