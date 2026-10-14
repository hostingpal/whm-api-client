# WHM API Client

`hostingpal/whm-api-client` is a framework-independent PHP library intended to
cover the WHM API and cPanel UAPI.

The typed groups cover 39 reviewed operations: WHM server identity, load, disk
usage, domain inventory, PHP configuration, feature inventory and the cPanel
account lifecycle, plus cPanel domains, DNS, PHP and features. Generic `call()`
methods reach every other documented WHM API 1 and UAPI function. The library
provides token authentication, direct and WHM-routed UAPI, GET and form-encoded
POST execution, typed payloads, API exceptions, and per-client response metadata
over a PSR HTTP transport.

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

Selectors and resource groups perform no network I/O. Terminal methods execute
one request immediately:

```php
// Use the WHM-configured $client from the first example.
$version = $client->whm()->server()->version(); // string, unchanged from the server.
$hostname = $client->whm()->server()->hostname(); // string, unchanged from the server.
$load = $client->whm()->server()->loadAverage(); // Immutable ServerLoadAverage.
$oneMinuteLoad = $load->one; // int|float|numeric-string|null; original representation.

$diskUsage = $client->whm()->server()->diskUsage(); // Immutable ServerDiskUsage.
foreach ($diskUsage as $partition) { // Immutable DiskPartition records.
    $availableKilobytes = $partition->available;
    $usedPercent = $partition->percentage;
}

$account = $client->uapi('npr');
$domains = $account->domains()->list(); // Immutable AccountDomains.
$mainDomain = $domains->mainDomain;
$payload = $domains->toArray();

// Optional filter: null omits it, false sends 0, true sends 1.
$withoutTemporaryDomains = $account->domains()->list(hideTemporaryDomains: true);

$serverDomains = $client->whm()->domains()->list(); // Immutable ServerDomains.
foreach ($serverDomains as $domain) { // Immutable DomainInfo records.
    $username = $domain->user;
    $phpPackage = $domain->phpVersion;
}

$phpVersions = $account->php()->listVersions(); // list<string>, e.g. ['ea-php82'].
```

`whm()->server()->version(): string` maps to
[WHM version](https://api.docs.cpanel.net/openapi/whm/operation/version).
`whm()->server()->hostname(): string` maps to
[WHM gethostname](https://api.docs.cpanel.net/openapi/whm/operation/gethostname).
Both return the upstream string unchanged; missing or non-string values raise
`InvalidResponseException` with the response metadata.

`whm()->server()->loadAverage(): ServerLoadAverage` maps to
[WHM systemloadavg](https://api.docs.cpanel.net/openapi/whm/operation/systemloadavg).
Its `one`, `five`, and `fifteen` properties represent the corresponding minute
windows. Values retain the upstream representation: integers, finite floats,
or finite decimal strings such as `"1.20"`, as observed on the test server.
Properties and `toArray()` retain string precision and trailing zeros. Missing
fields become null properties and remain absent from `toArray()`; explicit null
is invalid. Decimal strings allow an optional sign, digits, and optional fractional
digits, but no whitespace or exponent notation. Values are never rescaled; the
upstream documentation describes them as a percentage of CPU processor capacity.

`whm()->server()->diskUsage(): ServerDiskUsage` maps to
[WHM getdiskusage](https://api.docs.cpanel.net/openapi/whm/operation/getdiskusage).
It supports `foreach` and `count()`, with a nullable `partitions` property and
the original `partition` key in `toArray()`. An omitted list counts/iterates as
empty but remains distinct from a present empty list in the export.

| `DiskPartition` property | Field in `toArray()` | Present value type |
| --- | --- | --- |
| `available` | `available` | Positive integer kilobytes |
| `device` | `device` | String |
| `disk` | `disk` | String |
| `filesystem` | `filesystem` | String |
| `inodesAvailable` | `inodes_available` | Positive integer or null |
| `inodesIpercentage` | `inodes_ipercentage` | Nonnegative integer or null |
| `inodesTotal` | `inodes_total` | Positive integer or null |
| `inodesUsed` | `inodes_used` | Positive integer or null |
| `mount` | `mount` | String |
| `percentage` | `percentage` | Nonnegative integer |
| `total` | `total` | Positive integer kilobytes |
| `used` | `used` | Positive integer kilobytes |

All partition fields are optional. Null properties represent omission or explicit
null for inode fields; `toArray()` preserves the distinction. Zero disk percentage
and nullable inode fields reflect verified runtime deviations from the pinned
schema; other minimum and type constraints remain enforced. Unknown extensions
are ignored. These three server operations take no parameters or cPanel target
account and perform one request each, retaining the owning WHM handle's metadata.

`uapi()->domains()->list(?bool $hideTemporaryDomains = null): AccountDomains`
maps to [UAPI DomainInfo/list_domains](https://api.docs.cpanel.net/openapi/cpanel/operation/list_domains).
Both direct cPanel and WHM-routed domain calls return the same DTO. Omitting the
filter leaves the documented default of `0`; `true` hides temporary domains from
the main domain and addon, parked, and subdomain lists.

| DTO property | Present field in `toArray()` | Field type |
| --- | --- | --- |
| `mainDomain` | `main_domain` | `string\|null` |
| `addonDomains` | `addon_domains` | `list<string>` |
| `parkedDomains` | `parked_domains` | `list<string>` |
| `subDomains` | `sub_domains` | `list<string>` |
| `isTemporary` | `is_temporary` | `array<array-key, 0\|1>` |

Every field is optional upstream. DTO properties are null for omitted fields;
`toArray()` preserves which fields were present, distinguishing an omitted main
domain from explicit null. Empty strings, empty lists, and integer `0`/`1` flags
remain unchanged. An empty temporary-domain object becomes an empty array map.
Unknown payload extensions are outside this reviewed DTO contract.
The upstream domain operation can return blank values without an API error when
the account's userdata file has incorrect permissions; success alone does not
establish a complete domain inventory.

`whm()->domains()->list(): ServerDomains` maps to
[WHM get_domain_info](https://api.docs.cpanel.net/openapi/whm/operation/get_domain_info).
It returns the server's manually created domains visible to the WHM credentials;
service/proxy subdomains and automatically created domains are excluded upstream.
The collection supports `foreach` and `count()`. Its `domains` property holds
`list<DomainInfo>`, or null when the upstream field is omitted. An omitted list
iterates as empty, while `toArray()` distinguishes `[]` from `['domains' => []]`.
Unknown extensions are ignored.

Each immutable `DomainInfo` exposes the following reviewed fields:

| DTO property | Present field in `toArray()` | Field type |
| --- | --- | --- |
| `docroot` | `docroot` | `string` |
| `domain` | `domain` | `string` |
| `domainType` | `domain_type` | `string` |
| `ipv4` | `ipv4` | `string` |
| `ipv4Ssl` | `ipv4_ssl` | `string` |
| `ipv6` | `ipv6` | `string\|null` |
| `ipv6IsDedicated` | `ipv6_is_dedicated` | `0\|1` |
| `modsecurityEnabled` | `modsecurity_enabled` | `0\|1` |
| `parentDomain` | `parent_domain` | `string` |
| `phpVersion` | `php_version` | `string` |
| `port` | `port` | `int\|numeric-string` |
| `portSsl` | `port_ssl` | `int\|numeric-string` |
| `user` | `user` | `string` |
| `userOwner` | `user_owner` | `string` |

All record fields are optional. Properties use null for omitted values;
`toArray()` retains presence, including explicit `ipv6: null`, empty strings,
and integer zeros. Domain types and PHP package names remain upstream strings.
The public `port`/`portSsl` properties are integers (or null when omitted).
The pinned schema declares integer ports, but the test server returns decimal
strings. Canonical nonnegative decimal strings that fit a PHP integer are accepted
and normalized in the properties; `toArray()` preserves their original wire type.

`uapi()->php()->listVersions(): array` maps to
[UAPI LangPHP/php_get_installed_versions](https://api.docs.cpanel.net/openapi/cpanel/operation/php_get_installed_versions).
It returns `list<string>` on both direct and WHM-routed account handles, preserving
custom package names and an empty list. A missing or malformed `versions` list
raises `InvalidResponseException` with the response metadata. The operation lists
the system's PHP versions in the selected account context; it requires EasyApache 4
and is disabled when the WebServer role is disabled. See the live testing workflow
below and the recorded [runtime observations](docs/api/source-audit.md#runtime-observations-2026-10-13).

### PHP, feature and account inventory

The following 22 operations extend the original seven. Each method executes one
read-only GET immediately. WHM methods use the authenticated server user; UAPI
methods retain the selected cPanel account on either credential route.

| PHP method | Upstream operation | Return |
| --- | --- | --- |
| `whm()->php()->listVersions()` | `php_get_installed_versions` | `list<string>` |
| `whm()->php()->systemDefaultVersion()` | `php_get_system_default_version` | `string` |
| `whm()->php()->handlers(?string $version = null)` | `php_get_handlers` | List of handler records |
| `whm()->php()->defaultAccountsToFpm()` | `php_get_default_accounts_to_fpm` | `bool` |
| `whm()->php()->oldFpmFlag()` | `php_get_old_fpm_flag` | `0\|1\|2` |
| `whm()->php()->isConversionInProgress()` | `is_conversion_in_progress` | `bool` |
| `whm()->php()->vhostsByVersion(string $version)` | `php_get_vhosts_by_version` | `list<string>` |
| `whm()->features()->metadata()` | `get_feature_metadata` | `FeatureCatalog` of `FeatureMetadata` |
| `whm()->features()->names()` | `get_feature_names` | Feature names shape |
| `whm()->features()->listData(string $featureList)` | `get_featurelist_data` | `FeatureListData` of `FeatureSetting` |
| `whm()->features()->lists()` | `get_featurelists` | Feature list names shape |
| `whm()->features()->listsByPackageType()` | `get_featurelists_by_package_types` | Feature lists by package type shape |
| `whm()->features()->userSettings($user, $feature = null)` | `get_users_features_settings` | `UserFeatureSettings` of `UserFeatureSetting` |
| `whm()->features()->userHasFeature(string $user, string $feature)` | `verify_user_has_feature` | `bool` |
| `uapi()->domains()->primary(?bool $hideTemporaryDomains = null)` | `DomainInfo/primary_domain` | `?string` |
| `uapi()->domains()->builtinAliases(?bool $hideTemporaryDomains = null)` | `DomainInfo/main_domain_builtin_subdomain_aliases` | `list<string>` |
| `uapi()->php()->systemDefaultVersion()` | `LangPHP/php_get_system_default_version` | `?string` |
| `uapi()->php()->domainHandler(string $type, ?string $vhost = null)` | `LangPHP/php_get_domain_handler` | `string` |
| `uapi()->php()->impactedDomains(string\|array\|null $domain = null, ?bool $systemDefault = null)` | `LangPHP/php_get_impacted_domains` | `list<string>` |
| `uapi()->features()->list()` | `Features/list_features` | `array<array-key, 0\|1>` |
| `uapi()->dns()->lookup(string $domain)` | `DNS/lookup` | `list<string>\|null` |
| `uapi()->dns()->generatedDomains(string $domain)` | `DNS/fetch_cpanel_generated_domains` | `list<array{domain?: string}>` |

Precise nested array shapes and exact official operation links are documented on
these methods. Return conventions: a public value never exposes wire-type variants.
Records whose flags vary on the wire are immutable DTOs (iterable/countable
containers where they hold lists) whose camelCase properties normalize flags to
`0|1` and use null for omission, while `toArray()` returns the reviewed wire shape
with original keys, presence and values, including the exact string `'0'`/`'1'` or
boolean forms observed in the runtime audit. Array returns are kept for payloads
whose fields each have a single type. Load averages are the one deliberate exception: `ServerLoadAverage` keeps `int|float|numeric-string` because no lossless normalization preserves their precision and trailing zeros.
Scalar/list convenience methods require their selected
field; malformed payloads raise `InvalidResponseException` carrying the current
metadata. Nullable UAPI scalar fields accept explicit null. DNS lookup preserves
explicit null but rejects an omitted data member. Existing DTO methods retain
their contracts.

```php
$whm = $client->whm();
$versions = $whm->php()->listVersions();
$handlers = $whm->php()->handlers();
$settings = $whm->features()->userSettings(['npr'], ['autossl', 'sslmanager']);
foreach ($settings as $setting) {
    $effective = $setting->cpuserSetting ?? $setting->featureListSetting; // 0|1|null
}
$wire = $settings->toArray(); // Original keys and wire values.

$account = $client->uapi('npr');
$primary = $account->domains()->primary();
$aliases = $account->domains()->builtinAliases(); // Labels such as www, not full names.
$handler = $account->php()->domainHandler('vhost', 'npr.rs');
$affected = $account->php()->impactedDomains('npr.rs');
$dnsLines = $account->dns()->lookup('npr.rs'); // Textual lines, not DNS record objects.
```

`userSettings()` accepts a username or a nonempty list of usernames and an optional
feature name or nonempty list. Omitting the feature filter asks for all features.
`userHasFeature()` is an access check: upstream also returns true for unknown
features. The `disabled` feature list reverses the interpretation of `value`;
flag properties are normalized to `0|1` and `toArray()` keeps the wire values.
A null `cpuserSetting` means there is no account override (or the field was
omitted; `toArray()` distinguishes the two).

UAPI feature-map names follow PHP array-key conversion, including integer keys
for canonical numeric JSON names. PHP package strings are open, including custom packages.
WHM handler inventory accepts the observed `lsapi` handler alongside the pinned enum. The old FPM flag preserves
all three states (absent preconfiguration, existing preconfiguration, dismissed
dialogue). PHP configuration operations may require EasyApache 4 and the WebServer
role, as documented per method. For UAPI handlers, `type` is `home` or `vhost`, with
`vhost` required for the latter. Impacted-domain lookup needs a domain or
`systemDefault: true` and excludes parked domains. A nonempty list of domain strings
is sent as repeated `domain` parameters; order and duplicates are preserved. Empty
or malformed lists fail locally and clear prior metadata before network I/O. Semantic request errors are
reported by the API. Optional booleans distinguish omission, false and true.
Generated DNS domains require cPanel 120 and preserve trailing dots.

### Account lifecycle, POST execution and generic calls

`whm()->accounts()` covers the cPanel account lifecycle. Inventory methods use
GET; mutations use POST so passwords and other parameters stay out of the URL,
which the pinned WHM documentation says the server records in its access log.
Records are precise array shapes (`AccountRecord`, `PackageRecord`, `CreatedAccount`,
`ModifiedAccount`) whose present fields are validated against their pinned types;
unknown fields are ignored and documented enumerations are not enforced.

| PHP method | Upstream operation | Method | Return |
| --- | --- | --- | --- |
| `accounts()->list($search, $searchType, $searchMethod, $want)` | `listaccts` | GET | `list<AccountRecord>` |
| `accounts()->summary(string $user)` | `accountsummary` | GET | `AccountRecord` |
| `accounts()->packages(?string $want = null)` | `listpkgs` | GET | `list<PackageRecord>` |
| `accounts()->create(string $username, array $options = [])` | `createacct` | POST | `CreatedAccount` |
| `accounts()->suspend($user, $reason, $onlyRootMayUnsuspend, $leaveFtpAccountsEnabled)` | `suspendacct` | POST | `void` |
| `accounts()->unsuspend($user, $retainServiceProxies)` | `unsuspendacct` | POST | `void` |
| `accounts()->remove($username, $keepDns)` | `removeacct` | POST | `void` |
| `accounts()->changePassword($user, $password, $updateDatabasePassword, $digestAuthentication)` | `passwd` | POST | `array{app?: list<string>}` |
| `accounts()->changePackage($user, $package)` | `changepackage` | POST | `void` |
| `accounts()->modify(string $user, array $changes)` | `modifyacct` | POST | `ModifiedAccount` |

```php
$accounts = $client->whm()->accounts();
$created = $accounts->create('newuser', [
    'domain' => 'new.example',
    'password' => $password, // Sent in the form body, never in the URL.
    'plan' => 'default',
]);
$summary = $accounts->summary('newuser'); // AccountRecord: user, domain, plan, suspended, ...
$accounts->suspend('newuser', 'Unpaid invoice');
$accounts->unsuspend('newuser');
$accounts->changePackage('newuser', 'gold');
$accounts->modify('newuser', ['contactemail' => 'owner@new.example']);
$accounts->remove('newuser', keepDns: false);
```

Optional booleans distinguish omission (upstream default), `false` (`0`) and `true`
(`1`). `summary()` requires exactly one record. `create()` and `modify()` forward
every documented option key unchanged (`username`/`user` come from the argument);
per-domain `MXCHECK-<domain>` keys for `modifyacct` are outside the typed shape and
go through `call()`. The server's `uid` is accepted as the observed canonical decimal
string and normalized to the pinned integer; see the
[runtime observations](docs/api/source-audit.md#runtime-observations-2026-10-14).
The alias parameters `removeacct` `user` and `passwd` `enabledigest`, and the
`accountsummary` lookups other than `user`, are not sent, so the coverage report
lists those variants as partial.

#### Generic calls

Every documented WHM API 1 and UAPI function is reachable without a typed group,
through the same authentication, routing, encoding, envelope handling, metadata
and exceptions:

```php
use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestMethod;

$result = $client->whm()->call('listaccts', ['searchtype' => 'user', 'search' => 'npr']);
$accounts = $result->data['acct'];   // Envelope data converted to arrays; no schema applied.
$reason = $result->metadata->whmReason();

// POST keeps api.version in the URL and form-encodes the parameters in the body.
$client->whm()->call('passwd', ['user' => 'npr', 'password' => $password], RequestMethod::Post);

// UAPI: direct with a cPanel token or bridged through WHM; cpanel.* fields cannot be overridden.
$pops = $client->uapi('npr')->call('Email', 'list_pops');

// Status with payload semantics: record the envelope and interpret it yourself.
$feature = $client->uapi('npr')->call('Features', 'has_feature', ['name' => 'ssh'], outcome: OutcomePolicy::Unchecked);
$state = $feature->metadata->uapiStatus();
```

`CallResult::$data` is the payload with JSON objects converted to associative
arrays (numeric keys become integers); `$hasData` distinguishes an omitted `data`
member from an explicit null. Names are validated before I/O (`[A-Za-z0-9_]`
segments; WHM allows `/`-separated namespaces), and the reserved `api.version` and
bridge `cpanel.*` fields are removed from caller parameters. `OutcomePolicy::Flag`
(default) applies the documented `metadata.result`/`status` 1/0 rule;
`OutcomePolicy::Unchecked` records the envelope without interpreting it. Both still
require a usable envelope and report HTTP failures. POST execution on all three
routes was verified live on 2026-10-14 with read-only functions.

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
These are the `OutcomePolicy::Flag` rules that every typed operation declares;
generic calls may choose `OutcomePolicy::Unchecked` (see above).

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
| `TransportException` | The PSR HTTP transport reports a request failure. |

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
disk usage, domain inventory, PHP configuration and feature inventory, plus
account domain/DNS/PHP/feature discovery through direct cPanel and WHM-routed UAPI
for `npr`. The 22-operation expansion adds 30 live cases (14 WHM and 16 UAPI route cases).
It validates the known `npr.rs` account, typed payloads and API metadata, with
verified TLS, disabled redirects and no retries. The test transport accepts only
the reviewed GET routes. Missing opt-in or prerequisites fail the live command
before requests; failure messages exclude raw credentials and account payloads.

Run just the server identity and health slice with
`WHM_API_LIVE=1 composer test:live -- --filter WhmServerLiveTest`.
Run the expanded inventory slice with
`WHM_API_LIVE=1 composer test:live -- --filter InventoryLiveTest`.

The default PHPUnit suite, `composer test`, `composer check`, and CI remain offline,
even when live-test environment variables are set. Pair new read-only slices with
an explicit live run on the approved test server; add reviewed routes to the live
group as implementation grows. Mutating/destructive tests require approval for
the specific operations and targets. Live runs do not update pinned documentation
or the generated coverage.

The PHPCS ruleset adds sorted and unused-import checks (including PHPDoc uses),
short arrays, multiline trailing commas, consistent member and type spacing,
strict comparisons and standard-library calls, noncapturing catches, and required
`strict_types=1`. The selection follows the framework-neutral parts of
[Oblak's Slevomat rules](https://github.com/oblakstudio/wordpress-coding-standards/blob/master/src/Oblak-Slevomat/ruleset.xml).
It preserves PSR-12 spacing, multiline arrow functions, and existing public names.
Slevomat is registered through `phpcs.xml.dist`; its Composer installer plugin
stays disabled, so no machine-wide PHPCS configuration is required.

Production APIs require useful PHPDoc. Endpoint methods must include a short
summary, detailed behavior, an exact official cPanel operation link, and precise
array shapes when they return arrays. DTO and collection return types remain
concrete. The full documentation requirements are in [AGENTS.md](AGENTS.md#documentation-requirements).

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
API map, and compares documented operations with declared PHP implementation coverage.
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
