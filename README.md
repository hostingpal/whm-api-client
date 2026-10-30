# WHM API Client

`hostingpal/whm-api-client` is a framework-independent PHP library intended to
cover the WHM API and cPanel UAPI.

The 13 generated typed groups cover 187 operations (see
[`docs/api/coverage.md`](docs/api/coverage.md)): WHM server identity, load, disk
usage, domain inventory, PHP configuration, feature inventory, read-only DNS zones
and resolvers, and the cPanel account lifecycle, plus cPanel domains, DNS, PHP and
features, the complete Email module, SSL/AutoSSL inventory, disk quotas and MySQL inventory,
database/user creation, privilege assignment and deletion.
Generic `call()` methods provide GET and form-encoded POST access without a typed
group, subject to the operation's parameter, route and envelope requirements.
JSON and multipart request bodies are not supported. The library provides token
authentication, direct and WHM-routed UAPI, typed array payloads, API exceptions,
and per-client response metadata over a PSR HTTP transport.

The [agreed PHP client interface](docs/superpowers/specs/2026-10-13-php-client-design.md)
uses `whm()` and `uapi(?string $user = null)`, with typed resource groups
on those handles. The selected UAPI route follows the configured credentials:
direct access with a cPanel token, or account impersonation through WHM with a
WHM token. Source contracts remain pinned in the
[official documentation audit](docs/api/README.md).

## Requirements

- PHP 8.3 or later within PHP 8.x.
- Composer 2.
- A PSR-18 HTTP client and PSR-17 request/stream factories for runtime use.

The core depends on PSR interfaces and
[`php-http/discovery`](https://docs.php-http.org/en/latest/discovery.html).
Concrete HTTP implementations are supplied by the consuming application.

## Base client

The constructor accepts an options array or a validated, immutable `ClientConfig`,
plus a required `HttpTransport` wrapping your configured PSR-18 client (see
[HTTP integration](#http-integration)). `HttpTransport::discover()` is the explicit
opt-in for a discovered client with defaults this library cannot verify.
Construction and account selection perform no network requests.

```php
use HostingPal\WhmApiClient\Client;

// $whmToken is supplied by the consuming application; $transport wraps its
// configured PSR-18 client (verified TLS, redirects disabled, timeouts set).
$client = new Client([
    'host' => 'whm.example.com',
    'authType' => 'whm',
    'token' => $whmToken,
    'defaultCpanelUser' => 'npr', // Optional; enables uapi() without an argument.
], $transport);

$whm = $client->whm();
$npr = $client->uapi('npr');
$sameAccount = $client->uapi();
$otherAccount = $client->uapi('otheruser');

$npr->username();     // npr; selecting otheruser does not change this handle.
$npr->isBridged();    // true
$npr->responseMeta(); // null before the first operation receives usable metadata.
```

Direct cPanel access uses its own token and requires an explicit username:

```php
$client = new Client([
    'host' => 'cpanel.example.com',
    'authType' => 'cpanel',
    'username' => 'npr',
    'token' => $cpanelToken,
], $transport);

$npr = $client->uapi(); // Direct route for npr.
```

| Option | Meaning |
| --- | --- |
| `host` | Required bare DNS hostname or IP address; no scheme, path, or port. IPv6 is supported. |
| `authType` | Required `whm` or `cpanel`; never inferred from the token. |
| `token` | Required nonempty token without whitespace or control characters. |
| `username` | Token owner; defaults to `root` only for WHM. Required for cPanel. |
| `port` | Optional integer from 1 to 65535; defaults to 2087 for WHM or 2083 for cPanel. |
| `defaultCpanelUser` | Optional target used by `uapi()` with WHM credentials. With cPanel credentials it must match the token owner. |

Configuration builds HTTPS base addresses. The injected PSR client must verify
TLS certificates, disable redirects, and set timeouts for authenticated requests;
`HttpTransport::discover()` keeps whatever defaults the discovered client ships
with. The API layer rejects HTTP 3xx responses and performs no retries. Transport
options are configured on that client, not in this options array; see the explicit
[HTTP integration example](#http-integration). Handles never expose the
configuration or credentials, and credentials refuse serialization so tokens
cannot reach caches, queues, or sessions; do not `var_export()` configuration.

`uapi(null)` follows the same default rules as `uapi()`. An explicit empty username
is invalid. WHM credentials without an explicit or default cPanel target raise
`InvalidArgumentException`; `root` is not an implicit UAPI target. A cPanel token
cannot select another account, and calling `whm()` with cPanel credentials raises
`LogicException`. Repeated selection of the same account returns the same handle;
different accounts and separately constructed facades retain separate handles.

## Available operations

Typed groups are generated from the pinned API map plus the reviewed overlay in
`docs/api/overlay/`; the classes under `src/Api/Whm/` and `src/Api/Uapi/` are never
edited by hand. [`docs/api/coverage.md`](docs/api/coverage.md) lists the generated
groups, their accessors, operation counts and the excluded (deprecated) operations.
Every method has generated PHPDoc: a summary, the upstream description, any observed
notes recorded in the overlay, the API-map identity, the official operation `@see`
link and a precise PHPStan array shape for its result.

Selectors and groups perform no network I/O. A group method executes one request
immediately, takes named arguments (optional parameters are `null` to omit; booleans
distinguish omission, `false` as `0` and `true` as `1`), and returns the documented
payload as a plain array, scalar or `null`:

```php
// Use the WHM-configured $client from the first example.
$version = $client->whm()->server()->version();       // string
$hostname = $client->whm()->server()->getHostname();  // string
$load = $client->whm()->server()->getLoadAverage();   // array{one?: ..., five?: ..., fifteen?: ...}
$oneMinuteLoad = $load['one'] ?? null;                // int|float|numeric-string, original form.

foreach ($client->whm()->server()->getDiskUsage()['partition'] ?? [] as $partition) {
    $usedPercent = $partition['percentage'] ?? null;
}

$account = $client->uapi('npr');
$domains = $account->domainInfo()->listDomains();
$mainDomain = $domains['main_domain'] ?? null;

// Optional filter: null omits it, false sends 0, true sends 1.
$withoutTemporary = $account->domainInfo()->listDomains(hideTemporaryDomains: true);

foreach ($client->whm()->accounts()->getDomainInfo()['domains'] ?? [] as $domain) {
    $username = $domain['user'] ?? null;
    $port = $domain['port'] ?? null; // int, even when the server sends a decimal string.
}

$phpVersions = $account->langPhp()->phpGetInstalledVersions(); // list<string>

// Email inventory; skipMain excludes the cPanel main account.
$mailboxes = $account->email()->listPops(skipMain: true);
$mailDomains = $account->email()->listMailDomains();
$domainForwarders = $account->email()->listDomainForwarders();
$mailboxCount = $account->email()->countPops();

// Account-scoped certificate inventory and AutoSSL status.
$ssl = $account->ssl();
$installedHosts = $ssl->installedHosts();
$renewal = $ssl->getAutosslRenewalStatus(domain: 'example.com'); // cPanel 134+
$willRenew = $renewal['will_renew'] ?? null; // 0|1, or absent.
$autosslProblems = $ssl->getAutosslProblems();
$checkInProgress = $ssl->isAutosslCheckInProgress(); // 0|1
$canRedirect = $ssl->canSslRedirect(); // Capability; does not enable redirects.

// Account quota totals and usage on the local server are distinct views.
$quota = $account->quota()->getQuotaInfo(); // Disk fields in MB.
$localQuota = $account->quota()->getLocalQuotaInfo(); // Disk fields in bytes.
$underQuota = $quota['under_quota_overall'] ?? null; // 0|1, or absent.

// MySQL inventory belongs to the selected cPanel account (cPanel 82+).
$databases = $account->mysql()->listDatabases(); // database, disk_usage (bytes), users
$databaseUsers = $account->mysql()->listUsers(); // user, shortuser, databases
$restrictions = $account->mysql()->getRestrictions(); // Length limits and optional nullable prefix.
$databaseServer = $account->mysql()->getServerInformation(); // host, is_remote (0|1), version
$privileges = $account->mysql()->getPrivilegesOnDatabase(database: 'npr_app', user: 'npr_appuser');
// Privileges are a list of strings; no assigned privileges produces an empty list.
```

A zero `inode_limit` or `megabyte_limit` means unlimited or disabled quotas.
The documentation lists zero remaining values for that case but does not describe
remaining values for an exhausted finite quota. Read remaining values together
with their limits and the `under_*` flags. Zero usage means no usage or disabled
quotas. The local `byte_limit` can lag changes, and distributed accounts can have
different local and account totals. Megabyte fields are `int|float`; zero checks
must recognize both `0` and `0.0`, separately from absent fields.
Observed numeric strings normalize to the documented numbers and flags. Local
`byte_limit` and `inode_limit` can also be explicit `null`; this remains distinct
from zero and omission. The account-total response has unreviewed integer-valued
fields that are omitted from typed results and retained as wildcard discrepancies
in [the evidence audit](docs/api/source-audit.md#runtime-observations-2026-10-16-quota-inventory).
`getQuotaInfo()` requires cPanel 56+, and `getLocalQuotaInfo()` requires cPanel 88+.

MySQL inventory returns lists of records with optional wire-key fields; empty
lists and zero disk usage are preserved. These operations are unavailable when
the MySQL/MariaDB role is disabled and remote MySQL is not configured.
The same group provides `createDatabase()`, `createUser()`,
`setPrivilegesOnDatabase()`, `deleteUser()` and `deleteDatabase()` for the selected
account. They use form POST; passwords stay in the request body. Privilege
assignment replaces the user's current database privileges, and deletion removes
the named resource. The pinned mutation responses are nullable objects with no
documented field schema: `null` stays `null`, while object fields are preserved
as an opaque array. An absent response payload remains a contract mismatch.

Results are arrays keyed by the upstream wire names (`$domains['main_domain'] ?? null`).
Omitted fields are absent keys (use `array_key_exists()` or `??`) and an explicit
upstream `null` stays `null`. Values that vary on real servers are normalized to the
documented type: for example ports and `uid` become `int`, feature flags become
`0|1`, and each correction is recorded in the overlay with its evidence. Load
averages are the deliberate exception and keep `int|float|numeric-string` so
precision and trailing zeros survive.

Response validation enforces the declared PHP types, required fields, null and
omission rules, and explicitly accepted wire conversions. General documented
minimum, range and enumeration constraints are not currently enforced; adding
them requires a reviewed compatibility decision for the affected fields
(`whm-api-client-8x0.18`). Existing integer bounds and 0/1 flag rules remain
enforced. Some alternative response shapes are combined to preserve documented
fields, so the client does not promise exact JSON Schema validation. Writes use
the same payload rules as reads. A payload that differs from its contract after
the envelope was accepted raises
`PayloadMismatchException` (an `InvalidResponseException`) carrying the response
metadata and the unvalidated payload; see [Errors](#errors) for retry guidance.
Method PHPDoc distinguishes documented requirements from observed compatibility.
Generated coverage is separate from offline test results and live observations;
an observation establishes only the route, server version and cases exercised.
An empty live list does not verify the types of records it never returned.

### WHM API 1

| Handle and accessor | Methods |
| --- | --- |
| `whm()->server()` | `version()`, `getHostname()`, `getLoadAverage()`, `getDiskUsage()` |
| `whm()->accounts()` | `listAccounts()`, `accountSummary()`, `getDomainInfo()`, `createAccount()`, `suspendAccount()`, `unsuspendAccount()`, `removeAccount()`, `changePassword()`, `changePackage()`, `modifyAccount()` |
| `whm()->webServer()` | `phpGetInstalledVersions()`, `phpGetSystemDefaultVersion()`, `phpGetHandlers()`, `phpGetDefaultAccountsToFpm()`, `phpGetOldFpmFlag()`, `isConversionInProgress()`, `phpGetVhostsByVersion()` |
| `whm()->hostingPlans()` | `listPackages()`, `getFeatureMetadata()`, `getFeatureNames()`, `getFeaturelistData()`, `getFeaturelists()`, `getFeaturelistsByPackageTypes()`, `getUsersFeaturesSettings()`, `verifyUserHasFeature()` |
| `whm()->dns()` | `listZones()`, `parseDnsZone()`, `getZoneRecord()`, `listMxRecords()`, `exportZoneFiles()`, `resolveDomainName()`, `hasLocalAuthority()`, `fetchDsRecordsForDomains()`, `getNameserverConfig()`, `lookupNameserverIp()`, `lookupNameserverIps()`, `isAliasAvailable()`, `isHttpsAvailable()`, `isSvcbAvailable()` |

### cPanel UAPI

| Handle and accessor | Methods |
| --- | --- |
| `uapi()->domainInfo()` | All 5 operations: `domainsData()`, `singleDomainData()`, `listDomains()`, `primaryDomain()`, `mainDomainBuiltinSubdomainAliases()` |
| `uapi()->langPhp()` | All 11 operations: installed/default/vhost PHP versions, domain handlers and impacted domains, INI paths/basic directives/content reads, and `phpIniSetUserBasicDirectives()`, `phpIniSetUserContent()`, `phpSetVhostVersions()` |
| `uapi()->features()` | All 5 operations: `getFeatureMetadata()`, `hasFeature()`, `hasFeaturesLike()`, `listFeatures()`, `listFeaturesLike()` |
| `uapi()->email()` | All 96 pinned functions, including mailbox lifecycle, quotas and suspension, forwarders, filters, MX routing, autoresponders, spam settings and delivery diagnostics; see [generated methods](src/Api/Uapi/Email.php). |
| `uapi()->quota()` | `getQuotaInfo()`, `getLocalQuotaInfo()` |
| `uapi()->mysql()` | `listDatabases()`, `listUsers()`, `getPrivilegesOnDatabase()`, `getRestrictions()`, `getServerInformation()`, `createDatabase()`, `createUser()`, `setPrivilegesOnDatabase()`, `deleteUser()`, `deleteDatabase()` |
| `uapi()->dns()` | `lookup()`, `fetchCpanelGeneratedDomains()`, `parseZone()`, `hasLocalAuthority()`, `ensureDomainsResideOnlyLocally()`, `ensureDomainsUseRecognizedNameservers()`, `isAliasAvailable()`, `isHttpsAvailable()`, `isSvcbAvailable()` |

UAPI groups operate on the selected cPanel account through either credential route.
DomainInfo, Features and LangPHP expose every function in their pinned modules.
`domainsData(format: 'hash')` returns grouped domain records; `format: 'list'`
returns a record list. Domain details retain optional fields and explicit nulls.
`phpGetVhostVersions()` returns a list, or a single record when `vhost` is supplied.
INI setters and PHP version changes use form POST. INI content and directives are
sensitive parameters; retain a configuration backup before overwriting content.
The content setter's pinned enum says `host|vhost` while its prose says `home|vhost`;
this contradiction is documented, with no invented validation or default.

`features()->hasFeature(name: 'autossl')` preserves its nullable object or observed
integer payload; the integer's meaning is unspecified.
Read `$account->responseMeta()?->uapiStatus()` immediately afterward for the access
result: 1 means enabled; 0 means absent or disabled. This method accepts UAPI status
0 because it carries the access result. Outer WHM bridge failures still throw.

`uapi()->dns()->fetchCpanelGeneratedDomains()` requires cPanel 120 and preserves
trailing dots; `lookup()` returns textual lines or explicit `null`.

The [completed-module read capture](docs/api/evidence/2026-10-30-domain-features-php-corrected.json)
observed all ten new reads through both routes on cPanel 11.138.0.19. All 67 catalog
API/payload checks passed, while strict discrepancy review remains failed for
unknown fields and stale historical Quota acknowledgments. PHP setters remain
offline-tested only; see the [source audit](docs/api/source-audit.md).


The Email module uses POST for mutations and secret-bearing requests. Passwords,
password hashes, filter content and other marked inputs remain sensitive throughout
the library's call stack. `addPop(email: 'demo', password: $password, domain: 'example.com',
sendWelcomeEmail: false)` suppresses the optional welcome message. Supply either
`password` or `passwordHash`, never both; account quota and role restrictions still
apply. Methods that send mail or change account-wide settings execute immediately.

`storeFilter()` and `reorderFilters()` accept nonempty ordered lists for numbered
filter parameters. The client emits `action1`, `action2`, or `filter1`, `filter2`;
`values` also accepts integer items for numeric comparisons. Other filter lists
accept strings. Empty/keyed lists and colliding wire names fail before dispatch.
Mailbox counts and quota results retain their documented zero/unlimited meanings.
`getPopQuota()` normalizes observed zero-fraction decimal strings to integers
without floating-point conversion; fractional values and overflow remain errors.
`listPopsWithDisk()` keeps floating-point MB usage, while opaque mutation payloads
retain fields and explicit nulls.

Two Email contracts remain limited by the pinned source: `traceDelivery()` preserves
the formal destination shapes, but deeper recursive routed examples are not a typed
recursive contract; `terminateMailboxSessions()` documents a WHM-like envelope in
its UAPI page, so actual UAPI-envelope compatibility remains unverified. Offline
fixtures cover all functions; live evidence is limited to explicitly recorded calls.

The DNS methods are read-only (GET):

```php
$zone = $client->whm()->dns()->parseDnsZone('example.com'); // array{payload?: list<...>}
foreach ($zone['payload'] ?? [] as $line) {
    if (($line['type'] ?? null) === 'record') {
        $owner = base64_decode($line['dname_b64'] ?? '', true); // Zone text stays base64-encoded.
    }
}

// line_index is 0-based; getZoneRecord() takes the 1-based line number (line_index + 1, observed below).
$record = $client->whm()->dns()->getZoneRecord('example.com', 3);

// One domain or a nonempty list, sent as repeated domain parameters; null items mean "only local".
$remote = $client->uapi('npr')->dns()->ensureDomainsResideOnlyLocally(['example.com', 'example.net']);
```

- `parseDnsZone()`/`parseZone()` and `exportZoneFiles()` return the zone's owner names,
  record data and text base64-encoded, as documented; decode with a binary-safe decoder.
- `exportZoneFiles()`, `fetchDsRecordsForDomains()`, `hasLocalAuthority()` (WHM and
  UAPI) and the two UAPI `ensure*()` checks accept one name or a nonempty list
  (repeated parameters). DS records may include DNSSEC private key material
  (`privatekey`); treat that result as secret.
- `getZoneRecord()` returns the base fields (`Line`, `class`, `name`, `ttl`, `type`)
  plus the documented fields of the record's type (for example `address`,
  `exchange`/`preference`, `txtdata`, or the SOA `serial` and timers), all optional.
  It was verified live only for `A`, `CNAME`, `MX`, `NS`, `SOA` and `TXT` lines; the
  fields of the other documented record types follow the pinned schema unverified.
- Observed on cPanel 11.138.0.15 on the test server on 2026-10-15
  ([runtime observations](docs/api/source-audit.md#runtime-observations-2026-10-15-dns-slice)):
  `hasLocalAuthority()` records carried `error` as an explicit `null` after a successful
  check, although it is documented only for failures; `isAliasAvailable()` reported
  `resolver` as `null` without a PowerDNS resolver setting; `listMxRecords()` and
  `getZoneRecord()` received `preference` (and the SOA `serial`, `refresh`, `retry`
  and `expire`) as decimal strings, which are normalized to `int`;
  `lookupNameserverIps()` returned `ipv6` as an empty string for a nameserver without
  an IPv6 address; and a `parseDnsZone()` `line_index` plus one addressed the same line
  in `getZoneRecord()`, consistent with a 0-based index and a 1-based line number.
- `ensureDomainsUseRecognizedNameservers()` fails as a whole when the check cannot be
  determined; treat that failure as "unknown" rather than as every domain failing.

Selected behaviors worth knowing:

- `getUsersFeaturesSettings($users, $features)` accepts a username or a nonempty list of
  usernames and an optional feature name or list; omitting the feature filter asks for
  all features. Empty or malformed lists fail locally and clear prior metadata before
  network I/O.
- `verifyUserHasFeature(user, feature)` returns `0|1` and is an access check: upstream
  also returns true for unknown features. The `disabled` feature list reverses the
  interpretation of a feature's value.
- `phpGetImpactedDomains()` needs a domain (or a nonempty list, sent as repeated
  `domain` parameters) or `systemDefault: true`, and excludes parked domains.
  `phpGetDomainHandler()` takes `type` `home` or `vhost`, with `vhost` required for the
  latter. PHP handler inventory accepts the observed `lsapi` handler.
- `listDomains()` can return blank values without an API error when the account's
  userdata file has incorrect permissions; success alone does not establish a complete
  domain inventory. `getDomainInfo()` returns manually created domains visible to the
  WHM credentials; service and automatically created domains are excluded upstream.
- PHP configuration operations may require EasyApache 4 and the WebServer role, as
  documented per method.

### Account lifecycle and mutations

Inventory methods use GET. Mutations use POST so passwords and other parameters stay
out of the URL, which the pinned WHM documentation says the server records in its
access log.

- `listAccounts(search, searchtype, searchmethod, want)` and `accountSummary()` return
  the `acct` record list; `accountSummary()` does not require exactly one record.
  `hostingPlans()->listPackages()` returns the `pkg` list.
- `createAccount()`, `changePassword()` and `modifyAccount()` return `null` when the
  server sends no data, and the documented array otherwise.
- `suspendAccount()`, `unsuspendAccount()`, `removeAccount()` and `changePackage()`
  return `void`.
- Leading arguments: `listAccounts(search, searchtype, searchmethod, want)`,
  `suspendAccount(user, reason, ...)`, `changePackage(user, package)`,
  `changePassword(user, password, ...)` and `verifyUserHasFeature(user, feature)`.
  Prefer named arguments everywhere.

```php
$accounts = $client->whm()->accounts();
$created = $accounts->createAccount(
    username: 'newuser',
    domain: 'new.example',
    password: $password, // Sent in the form body, never in the URL.
    plan: 'default',
);
$summary = $accounts->accountSummary(user: 'newuser')[0] ?? null; // acct record: user, domain, plan, ...
$accounts->suspendAccount(user: 'newuser', reason: 'Unpaid invoice');
$accounts->unsuspendAccount(user: 'newuser');
$accounts->changePackage(user: 'newuser', package: 'gold');
$accounts->modifyAccount(user: 'newuser', contactemail: 'owner@new.example');
$accounts->removeAccount(username: 'newuser', keepdns: false);
```

Parameters outside a generated method's signature (for example per-domain
`MXCHECK-<domain>` keys for `modifyacct`) stay reachable through `call()`. The
server's `uid` is accepted as the observed canonical decimal string and normalized
to the pinned integer; see the
[runtime observations](docs/api/source-audit.md#runtime-observations-2026-10-14).

### Generic calls

Use `call()` for functions whose parameters fit the supported GET query or
form-encoded POST representation, through the same authentication, routing,
envelope handling, metadata and exceptions. The caller must check the operation's
documented method, body format, route and outcome semantics; generic access does
not establish typed coverage or verification of that operation.

The [pinned request-body inventory](docs/api/source-inventory.json) includes 13
JSON operations (8 WHM, 5 UAPI) and 9 multipart operations (1 WHM, 8 UAPI).
These body formats remain unsupported by both typed and generic calls. Selected
operation support is tracked under `whm-api-client-8x0.1.18` (JSON) and
`whm-api-client-8x0.1.19` (multipart); complete execution-contract coverage remains
open under `whm-api-client-8x0.1`.

```php
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;

// POST (the default) keeps api.version in the URL and form-encodes the parameters in the body.
$result = $client->whm()->call('listaccts', ['searchtype' => 'user', 'search' => 'npr']);
$accounts = $result->data['acct'];   // Envelope data converted to arrays; no schema applied.
$reason = $result->metadata->whmReason();

$client->whm()->call('passwd', ['user' => 'npr', 'password' => $password]); // The password stays out of the URL.

// GET is explicit: every parameter goes into the query string, which the server logs.
$version = $client->whm()->call('version', [], RequestMethod::Get);

// UAPI: direct with a cPanel token or bridged through WHM; cpanel.* fields cannot be overridden.
$pops = $client->uapi('npr')->call('Email', 'list_pops');

// Status with payload semantics: record the envelope and interpret it yourself.
$feature = $client->uapi('npr')->call('Features', 'has_feature', ['name' => 'ssh'], outcome: OutcomePolicy::Unchecked);
$state = $feature->metadata->uapiStatus();
```

`CallResult::$data` is the payload with JSON objects converted to associative
arrays (numeric keys become integers); `$hasData` distinguishes an omitted `data`
member from an explicit null. Each call first clears `responseMeta()`, then validates
the names (`[A-Za-z0-9_]` segments; WHM allows `/`-separated namespaces) and the
parameter values (strings, integers or nonempty lists of strings; a failure names the
parameter, never its value) before I/O. The reserved `api.version` and bridge
`cpanel.*` fields are removed from caller parameters. `OutcomePolicy::Flag`
(default) applies the documented `metadata.result`/`status` 1/0 rule;
`OutcomePolicy::Unchecked` records the envelope without interpreting the operation's
outcome. On the WHM bridge it waives only the UAPI `status`: the outer WHM
`metadata.result` reports whether the UAPI call ran, so a failed bridge raises
`ApiErrorException` under both policies. Both still require a usable envelope and
report HTTP failures. POST, the default method, was verified live on all three
routes on 2026-10-14 with read-only functions.

## Response metadata and failures

`responseMeta(): ?ResponseMetadata` belongs to the selected WHM or UAPI client.
It is initially null and clears at the start of every attempt. A usable envelope
records an immutable snapshot before payload validation or an API exception;
transport failures and responses without usable metadata leave it null. Later
requests cannot change retained snapshots or another account's metadata.

`ResponseMetadata::$httpStatus` records the HTTP status. Its nullable `$whm` map
contains the WHM envelope's raw `metadata`; `$uapi` contains the UAPI result
envelope without `data`, including any supplied status, errors, messages,
warnings, and metadata. Nested JSON metadata objects are represented as arrays. Bridged calls
retain both maps and check both outcomes; outer WHM success alone is insufficient.
Most typed operations declare `OutcomePolicy::Flag`. The documented exception is
`features()->hasFeature()`, which uses unchecked UAPI status for its access result.
Generic calls may also choose `OutcomePolicy::Unchecked` (see above).

Typed accessors read the documented envelope fields without coercion:
`whmResult()`, `whmReason()`, `uapiStatus()`, `uapiErrors()`, `uapiWarnings()` and
`uapiMessages()` return null or an empty list for absent or non-conforming values and
skip non-string list entries.
`errors()` returns the WHM reason when the WHM result is `0`, followed by the UAPI
errors, so a successful outer bridge reason such as `OK` is never reported as an error.

API failures extend `HostingPal\WhmApiClient\Exception\ApiException`. Its public
readonly `$metadata` property contains the same snapshot as `responseMeta()`,
or null when no usable API metadata was received:

| Exception | Meaning |
| --- | --- |
| `ApiErrorException` | WHM or UAPI reports an operation failure, including over HTTP 200. |
| `HttpException` | HTTP status is outside 200–299, including redirects. |
| `InvalidResponseException` | The envelope, outcome, or endpoint payload cannot satisfy the reviewed contract. |
| `PayloadMismatchException` | An `InvalidResponseException`: the envelope and outcome were accepted, but the payload differs from the contract. |
| `TransportException` | The PSR HTTP transport reports a request failure. |

`PayloadMismatchException` means the response envelope was accepted. When
`$outcomeConfirmed` is true, the API also reported success, so the operation took
effect: an account was created or a password changed even though the response did not
match. Every generated operation checks its outcome today; `$outcomeConfirmed` is false
only for an operation whose outcome is not checked. Do not retry a mutating operation
on this exception; read `$payload`, the unvalidated decoded `data` member as plain
arrays (null when it was omitted or null), or confirm the server state instead. The
payload can contain secrets such as a newly created token, so `var_dump()` and
`print_r()` elide it; log it deliberately. Malformed JSON and unusable
envelopes raise a plain `InvalidResponseException`, where the outcome is unknown.

```php
try {
    $created = $client->whm()->accounts()->createAccount('example', domain: 'example.test');
} catch (PayloadMismatchException $exception) {
    // Created upstream; the response shape differed from the contract.
    $raw = $exception->payload;
}
```

`ApiException::errors()` returns the upstream error descriptions from its
snapshot (empty without metadata). Exception messages exclude raw bodies,
credentials, and upstream error text, which can name accounts or domains; read it
through `errors()` and log it deliberately. `TransportException` never retains the
original exception chain, which may contain an authenticated request. Instead,
`$failure` reports the PSR interface the client used: `network` (typically
connection, DNS, timeout), `request`, or `other`. Classification follows the
client (Guzzle, for example, can report certificate verification failures as `request`); `$causeClass` names the PSR exception; and `$causeMessage` keeps its
message with the configured token redacted. The cause message can include the
request URL, so it is excluded from `getMessage()`. Metadata preserves upstream
diagnostics and may include account data.

```php
try {
    $client->uapi('npr')->dns()->lookup('example.com');
} catch (ApiErrorException $exception) {
    $reasons = $exception->errors(); // e.g. ['The domain does not exist.']
} catch (TransportException $exception) {
    $kind = $exception->failure;          // As classified by the PSR client.
    $detail = $exception->causeMessage;   // Token redacted; may contain the URL.
}
```

Direct UAPI supports the pinned `result` wrapper and the bare result object
observed during an authorized live request on 2026-10-13. A present `result`
field takes precedence, even when malformed; the bare form requires a top-level
`status` field. WHM bridge parsing continues to use `data.uapi`.
The library was verified directly as `npr` with its cPanel account token on
`test.oblak.host:2083` on 2026-10-13: domain listing returned the expected main
domain, HTTP 200/UAPI status `1`, and no WHM metadata. This observation is specific
to `DomainInfo/list_domains` on that server and account; see the
[runtime evidence](docs/api/source-audit.md#runtime-observations-2026-10-13).

## Development

```bash
composer install --no-interaction
composer validate
composer check
```

`composer check` runs PHPCS with PSR-12 and selected Slevomat rules, PHPStan at
its maximum level, and PHPUnit. The individual commands are:

```bash
composer phpcs
composer phpcbf
composer phpstan
composer test
```

`composer phpcbf` modifies files to fix coding style. Editor settings are in
`.editorconfig`; tool settings are in `phpcs.xml.dist`, `phpstan.neon.dist`,
and `phpunit.xml.dist`. Tests use a mock client and real PSR-7 messages without
contacting a server.

Live verification is a separate PHPUnit `live` group for the implemented read-only
operations on `test.oblak.host`. With `OBLAK_TEST_WHM_KEY` and `OBLAK_TEST_NPR_KEY`
already exported in the environment, run:

```bash
WHM_API_LIVE=1 composer test:live
```

This requires PHP's cURL extension and exercises WHM version, hostname, load average,
disk usage, domain inventory, PHP configuration, feature inventory and the read-only
DNS operations for the `npr.rs` zone, plus account domain/DNS/PHP/feature discovery
through direct cPanel and WHM-routed UAPI for `npr`, all through the generated groups
(see [runtime observations](docs/api/source-audit.md)). It validates the known
`npr.rs` account, typed payloads and API metadata, with
verified TLS, disabled redirects and no retries. The test transport accepts only
the reviewed GET routes; the DNS test's zone line and nameserver host, derived from
earlier read-only responses, are approved only for those exact values. Missing
opt-in or prerequisites fail the live command before requests; failure messages
exclude raw credentials and account payloads.

Run just the server identity and health slice with
`WHM_API_LIVE=1 composer test:live -- --filter WhmServerLiveTest`.
Run the expanded inventory slice with
`WHM_API_LIVE=1 composer test:live -- --filter InventoryLiveTest`, or the DNS slice
with `WHM_API_LIVE=1 composer test:live -- --filter DnsLiveTest`.

The default PHPUnit suite, `composer test`, `composer check`, and CI remain offline,
even when live-test environment variables are set. Pair new read-only slices with
an explicit live run on the approved test server; add reviewed routes to the live
group as implementation grows. The owner has given standing authorization for
all live-test operations on explicitly designated test accounts on
`test.oblak.host`, including destructive operations on existing or newly created
test accounts. Runs within that scope need no repeated permission. Explicit live
opt-in, target guards and cleanup checks still apply; ordinary tests remain
offline. Live runs do not update pinned documentation or the generated coverage.

The PHPCS ruleset adds sorted and unused-import checks (including PHPDoc uses),
short arrays, multiline trailing commas, consistent member and type spacing,
strict comparisons and standard-library calls, noncapturing catches, and required
`strict_types=1`. The selection follows the framework-neutral parts of
[Oblak's Slevomat rules](https://github.com/oblakstudio/wordpress-coding-standards/blob/master/src/Oblak-Slevomat/ruleset.xml).
It preserves PSR-12 spacing, multiline arrow functions, and existing public names.
Slevomat is registered through `phpcs.xml.dist`; its Composer installer plugin
stays disabled, so no machine-wide PHPCS configuration is required.

Production APIs require useful PHPDoc. Hand-written API methods must include a
short summary, detailed behavior, an exact official cPanel operation link, and
precise array shapes when they return arrays. Generated methods take their PHPDoc
from the pinned documentation, and caveats it lacks are recorded as overlay notes.
The full documentation requirements are in [AGENTS.md](AGENTS.md#documentation-requirements).

The [live evidence runner](tools/live-verify/README.md) captures the same raw
response before normalization and field filtering for 67 reviewed route-specific
probes across 37 operations. It saves sanitized structural evidence with revision
and contract hashes, while independent regression expectations remain in the
offline suite. It covers WHM version/load and account/domain/PHP inventory, plus
UAPI domain/PHP/Email/SSL/MySQL inventory and quotas through both UAPI routes.
The MySQL catalog includes account naming restrictions and database-server information.
The optional MySQL fixture adds two account-bound privilege GETs after assigning
`SELECT`, checking the API-reported assignment through both routes before cleanup.
This does not test SQL permission enforcement.
Account-list and summary probes target `npr` explicitly;
the PHP handler probe uses the reviewed `type: 'home'` argument, and AutoSSL renewal
status targets `npr.rs`. The [saved populated MySQL run](docs/api/evidence/2026-10-17-mysql-populated.json)
passes with **2 acknowledged, 0 unexpected and 0 stale discrepancies**; it is not
discrepancy-free. Unknown Quota field contracts remain open under
`whm-api-client-8x0.4.3.1`. The opted-in MySQL fixture observes all documented
database/user row fields, integer `disk_usage`, and populated and empty nested
lists through both routes on cPanel `11.138.0.15`; cleanup confirmed all four
temporary resources absent. Other versions and remote/disabled-role MySQL
configurations remain unobserved. See the
[delivery design](docs/superpowers/specs/2026-10-14-generated-client-design.md#first-evidence-slice-and-next-batch).
The [generated mutation run](docs/api/evidence/2026-10-17-mysql-mutations.json)
captures five operations through both routes in a separate `mysql_mutations`
section. All ten POST observations returned explicit null and passed without
mutation discrepancies; cleanup confirmed all four resources absent. The earlier
populated-inventory artifact does not establish this mutation coverage. Opaque
object results and optional prefix/privilege variants remain offline-tested only.
The [provisioning readback run](docs/api/evidence/2026-10-17-mysql-provisioning.json)
adds restrictions, database-server information and exact `SELECT` readback through
both routes. All 49 reads and ten fixture mutations passed, and cleanup confirmed
all four resources absent. Empty privilege lists and disabled prefixes remain
offline-tested only; the same two Quota acknowledgments remain visible.
The separate [finite-Quota scenario](docs/api/evidence/2026-10-17-finite-quota.json)
adds five observations on a disposable account. Both Quota operations passed
finite-limit checks through both routes, and token/account/domain/zone cleanup
was confirmed. Its command exited **1** because two unknown-field observations
remain unacknowledged in that scenario; the baseline acknowledgments do not apply.
Exhausted-quota enforcement and finite inode limits remain unobserved.
Real HostingPal consumer integration testing is deferred by the user as of
2026-10-17; no consumer validation is claimed. It can resume against the current
client before full API coverage or a release. Any public prerelease is a separate decision with
an explicit coverage and compatibility statement.

The development lockfile resolves dependencies against PHP 8.3. Runtime
requirements for consumers come from `composer.json`, not this lockfile.

## HTTP integration

The transport requires a PSR-18 client, because that client owns the policy the
API layer relies on: verified TLS, disabled redirects, and timeouts. The PSR-17
request and stream factories carry no such policy; each omitted factory is resolved
through HTTP discovery. A fully discovered transport is an explicit opt-in:

```php
use HostingPal\WhmApiClient\Http\HttpTransport;

// Keeps the discovered client's own defaults, which are often missing a timeout
// and whose redirect policy depends on the implementation. Prefer injection.
$transport = HttpTransport::discover();
```

For example, a consuming application can install Guzzle:

```bash
composer require guzzlehttp/guzzle:^7.9 guzzlehttp/psr7:^2.7
```

It can also inject its configured client and factories explicitly:

```php
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use HostingPal\WhmApiClient\Http\HttpTransport;

$factory = new HttpFactory();
$transport = new HttpTransport(
    httpClient: new GuzzleClient([
        'timeout' => 30,
        'verify' => true,
        'allow_redirects' => false,
    ]),
    requestFactory: $factory,
    streamFactory: $factory,
);
```

`HttpTransport::send($method, $uri, $headers, $body)` builds a PSR-7 request and
returns the client's PSR-7 response. The URI can be a string or `UriInterface`;
header values can be strings or lists of strings; the optional body is a raw
string. HTTP 4xx/5xx responses pass through normally, and transport exceptions
propagate according to [PSR-18](https://www.php-fig.org/psr/psr-18/).

Configure timeouts, verified TLS, disabled redirects, proxies, and other transport
options on the injected client. Fully explicit injection works without discovery
or Composer plugins.
Missing implementations produce discovery's `NotFoundException`.

The discovery Composer plugin is disabled for development in this repository;
runtime discovery still works. Consuming applications control their own
plugin policy and must install concrete implementations if automatic
installation is disabled. The development mock client is not selected by
normal discovery.

## Package boundaries

A separate Composer package will provide Laravel integration and supply its
configured Guzzle client. Laravel dependencies belong in that package.

The Go tool parses pinned official API documentation, generates a canonical
API map, validates the overlay, generates the typed client and
`docs/api/coverage.md`, and checks that generated code is current.
Go was selected for the separate development tool; it is not a PHP runtime
dependency. The [source audit, approved design, and implementation plan](docs/api/README.md) preserve
documentation provenance and distinguish implementation from live coverage.

Run `composer api-map:check` with Go 1.24+ for the read-only offline toolchain
gate. It validates deterministic output, the overlay, and that committed generated
code is current. `composer check` remains PHP-only. See the
[tool commands and artifact review workflow](tools/api-map/README.md).

## License

Project-authored code and documentation are licensed under the
[MIT License](LICENSE). Copyright (c) 2026 HostingPal contributors.

The bundled cPanel documentation snapshots retain their upstream terms and are
not covered by the project's MIT license. See the
[third-party documentation notice](docs/api/README.md#licensing).
