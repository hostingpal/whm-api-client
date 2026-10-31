# WHM API Client

`hostingpal/whm-api-client` is a framework-independent PHP library intended to
cover the WHM API and cPanel UAPI.

The 73 generated typed groups cover 665 documented operations (see
[`docs/api/coverage.md`](docs/api/coverage.md)): WHM server identity, load, disk
usage, domain inventory, PHP configuration, feature inventory, read-only DNS zones
and resolvers, and the cPanel account lifecycle, plus cPanel domains, DNS, PHP and
features, domain management, directory access, the complete Email and SSL modules,
disk quotas, 13 Fileman operations and the complete pinned MySQL, FTP, PostgreSQL
and Mime sections, plus mail protection through BoxTrapper, EmailAuth, SpamAssassin
and cPGreyList.
Generic `call()` methods provide GET and form-encoded POST access without a typed
group, subject to the operation's parameter, route and envelope requirements.
Generic calls do not support JSON or multipart bodies. Three typed SSL methods
support reviewed multipart string fields. The library provides token
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

// FTP account inventory, daemon capabilities and quota values.
$ftp = $account->ftp();
$ftpAccounts = $ftp->listFtp(includeAcctTypes: 'sub');
$ftpDiskUsage = $ftp->listFtpWithDisk(includeAcctTypes: 'main|sub');
$ftpDaemon = $ftp->getFtpDaemonInfo(); // cPanel 54+; enabled and supports flags are 0|1.
$ftpQuota = $ftp->getQuota(account: 'deploy', domain: 'example.com'); // int|string

// MySQL inventory belongs to the selected cPanel account (cPanel 82+).
$databases = $account->mysql()->listDatabases(); // database, disk_usage (bytes), users
$databaseUsers = $account->mysql()->listUsers(); // user, shortuser, databases
$restrictions = $account->mysql()->getRestrictions(); // Length limits and optional nullable prefix.
$databaseServer = $account->mysql()->getServerInformation(); // host, is_remote (0|1), version
$privileges = $account->mysql()->getPrivilegesOnDatabase(database: 'npr_app', user: 'npr_appuser');
// Privileges are a list of strings; no assigned privileges produces an empty list.

// PostgreSQL belongs to the selected account and requires the PostgreSQL role.
$postgresql = $account->postgresql();
$pgDatabases = $postgresql->listDatabases(); // Optional database, disk_usage (bytes), users.
$pgUsers = $postgresql->listUsers(); // list<string>; cPanel 84+.
$pgRestrictions = $postgresql->getRestrictions(); // Optional length limits and string prefix.
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
The MySQL group covers all 25 operations in the pinned API section. Alongside
inventory and provisioning, it includes remote-host access and notes, schema
export, routine listing, server location, database/user renaming, table checking
and repair, privilege revocation, password updates and automatic database/user
setup. Mutations use form POST; passwords stay in the request body.

```php
$mysql = $account->mysql();
$mysql->revokeAccessToDatabase(database: 'npr_app', user: 'npr_appuser');
$passwordResult = $mysql->setPassword(user: 'npr_appuser', password: $newPassword);
$hostFailures = $passwordResult['failures'] ?? []; // Check alongside the envelope status.
$schema = $mysql->dumpDatabaseSchema(dbname: 'npr_app'); // SQL text; does not execute SQL.
$routines = $mysql->listRoutines(databaseUser: 'npr_appuser');
```

Privilege assignment replaces the user's current database privileges; revocation
removes access to that database. `updatePrivileges()` affects all databases and
users in the selected cPanel account. Remote-host wildcards and CIDR ranges can
broaden access. `addHostNote()` requires an authorized host and the MySQL role.
`renameDatabase(oldName, newName)` moves data, recreates grants and stored code,
and removes the old database. Back up first and inspect
`responseMeta()->uapiWarnings()`: a successful rename can leave the old database
or grants behind. `checkDatabase()` and `repairDatabase()` return per-table
messages that must be inspected alongside the envelope status.

`setupDbAndUser(prefix: 'app')` requires cPanel 11.116+ and creates randomly named
resources. Its result includes a generated password; treat the entire result as
sensitive. Keep the optional application prefix short (at most six alphanumeric
characters) to preserve name entropy. Most mutation responses are nullable opaque
objects: preserve `null` and arbitrary object fields. Password updates instead
return an object with optional per-host `failures` records (`error`, `host`).
The pinned text does not say whether failures can accompany status 1, so check
both the list and the status. Error text and hostnames are payload data; omitted
`failures` stays omitted. Automatic setup returns an object with optional database,
user, password, host and string-port fields. An absent payload remains a contract
mismatch. Method PHPDoc documents each version
floor, role requirement and result shape.

The FTP group covers all 20 pinned functions, including account creation/deletion,
password changes, home directories, quotas, sessions, anonymous-access settings and
the account welcome message. Mutation methods use form POST. `addFtp()` accepts
`password` or `passHash`, which map to `pass` and `pass_hash`; the server forbids
using both. Omitted options retain upstream defaults: new usernames lose dots by
default, quotas default to unlimited, the domain defaults to the cPanel
account's primary domain, and an omitted `homedir` defaults to a directory with
the same name as the FTP account. `getQuota()` preserves documented strings and
observed finite integers; inventory disk amounts are decimal strings in MB, and `_diskquota` uses `0.00` for unlimited while
`diskquota` uses `unlimited`. Optional fields and explicit `htmldir: null` stay distinct.

```php
$ftp->addFtp(user: 'deploy', password: $ftpPassword, domain: 'example.com',
    homedir: 'public_html', quota: 100, disallowdot: false);
$ftp->changePassword(user: 'deploy', password: $newFtpPassword, domain: 'example.com');
$ftp->setQuota(user: 'deploy', domain: 'example.com', quota: 250);
$ftp->deleteFtp(user: 'deploy', destroy: false, domain: 'example.com');
```

The [FTP live audit](docs/api/source-audit.md#complete-ftp-section-2026-10-30)
records 16 functions on both routes, successful API/payload checks and confirmed
cleanup. Its strict evidence assessment still flags undocumented string fields
in both disk-inventory observations; those fields stay outside typed results.
The saved artifact observes only `sub` inventory rows; main, anonymous and
log-access rows are outside its captured evidence. Four settings/session functions
remain live-unobserved.

`deleteFtp(destroy: true)` also deletes the FTP home directory; choose the target
carefully. `killSession()` defaults to stopping **all** FTP sessions for the
cPanel account when `login` is omitted. Anonymous access/transfer settings apply
to the selected cPanel account. `ftpExists()` returns a nullable opaque payload
on success and raises `ApiErrorException` when the account does not exist; it is
not a boolean check. Most FTP operations require the FTP role and cPanel 11.42+;
`getFtpDaemonInfo()` and `setHomedir()` require cPanel 54+. Nullable mutation
payloads preserve opaque object fields and explicit null, and an absent payload
remains a contract mismatch.

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
| `uapi()->addonDomain()` | Complete addon-domain create, delete and inventory operations (3). |
| `uapi()->domain()` | Temporary-domain checks, conversion and rename operations (4). |
| `uapi()->domainLookup()` | Base-domain and document-root lookups (4). |
| `uapi()->park()` | Domain alias creation, removal and inventory (3). |
| `uapi()->subDomain()` | Subdomain creation, removal, document roots and validation metadata (6). |
| `uapi()->webVhosts()` | Account virtual-host and SSL-capability inventories (2). |
| `uapi()->directoryIndexes()` | Directory indexing reads and configuration (3). |
| `uapi()->directoryPrivacy()` | Password-protection state and directory user management (6). |
| `uapi()->directoryProtection()` | Leech-protection directory inventory (1). |
| `uapi()->boxTrapper()` | Complete BoxTrapper configuration, templates and message queue operations (25). |
| `uapi()->emailAuth()` | Complete DKIM, SPF, DMARC and PTR management/validation (12). |
| `uapi()->spamAssassin()` | Complete preference, symbolic-test and Spam Box operations (4). |
| `uapi()->cpGreyList()` | Complete account/domain Greylisting controls and inventory (6). |
| `uapi()->mime()` | Complete MIME types, handlers, redirects and hotlink configuration (14). |
| `uapi()->domainInfo()` | All 5 operations: `domainsData()`, `singleDomainData()`, `listDomains()`, `primaryDomain()`, `mainDomainBuiltinSubdomainAliases()` |
| `uapi()->langPhp()` | All 11 operations: installed/default/vhost PHP versions, domain handlers and impacted domains, INI paths/basic directives/content reads, and `phpIniSetUserBasicDirectives()`, `phpIniSetUserContent()`, `phpSetVhostVersions()` |
| `uapi()->features()` | All 5 operations: `getFeatureMetadata()`, `hasFeature()`, `hasFeaturesLike()`, `listFeatures()`, `listFeaturesLike()` |
| `uapi()->email()` | All 96 pinned functions, including mailbox lifecycle, quotas and suspension, forwarders, filters, MX routing, autoresponders, spam settings and delivery diagnostics; see [generated methods](src/Api/Uapi/Email.php). |
| `uapi()->ssl()` | All 49 nondeprecated functions, including certificate/key/CSR inventory and lifecycle, installation, AutoSSL, SNI and redirects; see [generated methods](src/Api/Uapi/Ssl.php). |
| `uapi()->quota()` | `getQuotaInfo()`, `getLocalQuotaInfo()` |
| `uapi()->ftp()` | `addFtp()`, `allowsAnonymousFtp()`, `allowsAnonymousFtpIncoming()`, `changePassword()`, `deleteFtp()`, `ftpExists()`, `getFtpDaemonInfo()`, `getPort()`, `getQuota()`, `getWelcomeMessage()`, `killSession()`, `listFtp()`, `listFtpWithDisk()`, `listSessions()`, `serverName()`, `setAnonymousFtp()`, `setAnonymousFtpIncoming()`, `setHomedir()`, `setQuota()`, `setWelcomeMessage()` |
| `uapi()->mysql()` | `listDatabases()`, `listUsers()`, `getPrivilegesOnDatabase()`, `getRestrictions()`, `getServerInformation()`, `createDatabase()`, `createUser()`, `setPrivilegesOnDatabase()`, `deleteUser()`, `deleteDatabase()`, `addHost()`, `addHostNote()`, `checkDatabase()`, `deleteHost()`, `dumpDatabaseSchema()`, `getHostNotes()`, `listRoutines()`, `locateServer()`, `renameDatabase()`, `renameUser()`, `repairDatabase()`, `revokeAccessToDatabase()`, `setPassword()`, `setupDbAndUser()`, `updatePrivileges()` |
| `uapi()->postgresql()` | `createDatabase()`, `createUser()`, `deleteDatabase()`, `deleteUser()`, `getRestrictions()`, `grantAllPrivileges()`, `listDatabases()`, `listUsers()`, `renameDatabase()`, `renameUser()`, `renameUserNoPassword()`, `revokeAllPrivileges()`, `setPassword()`, `updatePrivileges()` |
| `uapi()->dns()` | `lookup()`, `fetchCpanelGeneratedDomains()`, `parseZone()`, `hasLocalAuthority()`, `ensureDomainsResideOnlyLocally()`, `ensureDomainsUseRecognizedNameservers()`, `isAliasAvailable()`, `isHttpsAvailable()`, `isSvcbAvailable()` |
| `uapi()->dnssec()` | All 12 pinned functions: key lifecycle, DNSKEY/private-key exports, DS records and NSEC settings. `fetchDsRecords()` and `exportZoneDnskey()` use GET; the other ten use form POST, including private-key export. |
| `uapi()->dynamicDns()` | All 5 pinned functions: `listDomains()` uses GET; `create()`, `recreate()`, `setDescription()` and `delete()` use form POST. |

UAPI groups operate on the selected cPanel account through either credential route.
DomainInfo, Features and LangPHP expose every function in their pinned modules.
`domainsData(format: 'hash')` returns grouped domain records; `format: 'list'`
returns a record list. Domain details retain optional fields and explicit nulls.
Shared domain flags accept integer and quoted 0/1; documented empty-string
`userdirprotect` stays `''`, distinct from 0.
`phpGetVhostVersions()` returns a list, or a single record when `vhost` is supplied.
INI setters and PHP version changes use form POST. INI content and directives are
sensitive parameters; retain a configuration backup before overwriting content.
The content setter's pinned enum says `host|vhost` while its prose says `home|vhost`;
this contradiction remains open, so its argument has plain `string` PHPDoc.
The client form-encodes supplied content once; the pinned example is percent-encoded
and the server write/read round trip remains unverified.

`features()->hasFeature(name: 'autossl')` preserves its nullable object or observed
integer payload; the integer's meaning is unspecified.
Read `$account->responseMeta()?->uapiStatus()` immediately afterward for the access
result: 1 means enabled; 0 with no errors means absent or disabled. Nonempty
`responseMeta()->uapiErrors()` means the call failed, not that the feature is disabled.
This typed method requires integer status 0 or 1 and accepts 0 as an access result.
Outer WHM bridge failures still throw.

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
invalidates the existing update URL; update DDNS clients to use its replacement.
Deleting a DDNS domain also invalidates its existing update URL.
`setDescription()` returns no payload. Deletion's source `number` enum `[0, 1]` becomes an integer `0|1` flag under
the existing generator; zero means the identifier did not exist for deletion.
Omitted timestamps and explicit null `last_update_time` remain distinct.

The live catalog reads only DS records for `npr.rs`, Dynamic DNS inventory for
`npr`, and WHM version. It makes no key, registrar, DNS-record or DDNS changes.
Unobserved live variants remain tracked under `8x0.2.26`; API responses alone do
not establish DNS propagation or DNSSEC validation by resolvers.
The final integrated capture at `71e31f1` passes all five GETs and strict checks
with zero discrepancies. Its DS objects and DDNS lists are empty; populated
numeric fields and mutations remain separate reviewer/offline evidence. The
original capture is unchanged; `docs/api/source-audit.md` records both hashes.

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
The subsequent 27-GET review capture passes every HTTP/API outcome and 25 payload
checks, including all three query depths, Analog sites and populated `suexec`
rows. Its two StatsBar role mismatches establish the bounded-map correction;
two additional masked unknown-field findings remain strict and unwaived. The
artifact stays failed. After Claude verified the corrected code, a separate
27-GET capture at `023d2dd` passed all HTTP/API/payload/probe checks on that
contract. It remains strictly failed only for the same two masked unknown-field
findings, which are unwaived. See `docs/api/source-audit.md` for both immutable
artifacts, exact revisions, hashes and coverage limits.

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

The [final Fileman inventory capture](docs/api/evidence/2026-10-30-fileman-inventory-final.json)
at `ec442bd` on `11.138.0.19` passes all seven GET observations
and strict assessment with zero discrepancies. It covers three inventory
operations through both routes; the other ten methods remain offline-tested.
No existing file content was read and no mutation ran. Follow-up `8x0.2.24`
owns their live verification; multipart upload remains under `8x0.1.19`.

The [PR37 review capture](docs/api/evidence/2026-10-30-fileman-review-final.json)
at `06001e0cab782c7bd46d7d0d1f659b0c3f7ebc7d` on `11.138.0.19` passes all seven
GET observations with zero discrepancies. Both routes return an empty-string
size for the selected existing zero-byte file; information and directory checks
confirm its identity and zero marker. No content was read and no resource was
created or changed. The root-owned uid/gid variants remain supported by Claude's
separate report. Earlier artifacts stay immutable.

The account environment and preference slice exposes twenty compatible methods
across ContactInformation, Contactus, Locale, Themes, Notifications,
AccountEnhancements, EA4, ServerInformation and Variables. Fourteen reads use GET;
six secret-bearing or state-changing operations use form POST. The JSON-body
`ContactInformation/set_notification_preferences` operation remains excluded under
`8x0.1.18`, and the deprecated theme-base operation remains excluded.

Contact addresses use documented repeated form fields. `setEmailAddresses()`
permits omitting `oldAddresses` when the account has no current contact addresses;
with existing addresses, omission still fails the server's old-address check.
`unsetEmailAddresses()` requires the complete old-address list. New-address and
password inputs retain their source requiredness; passwords and Pushbullet tokens
are sensitive parameters. The token getter also uses POST and returns sensitive
payload data. Contact mutations with no documented data return void; locale
setting preserves its present unspecified payload, while theme update preserves
nullable opaque objects and rejects an absent payload.

Theme options preserve omission and explicit false. Enhancement availability data
is separate from UAPI status: a successful data value of zero is not an API error.
The enhancement-list source omits its container type and has inconsistent object
prose/items structure, so the result remains mixed rather than acquiring an
invented list contract. Notification preference rows retain the suspicious literal
`additionalProperties` source property without inferring a dynamic-field schema.

Variable reads preserve optional fields and nullable values.
`variables()->getUserInformation(name: ...)` and `getServerInformation(name: ...)`
accept one string or a nonempty list encoded as repeated `name` keys. Omission
retains the server's all-field behavior; `getSessionInformation()` keeps a scalar
selector. Source prose/examples establish dynamic feature-flag and domain/MX maps
where the formal schema misplaced `additionalProperties`. Server/session/user
fields and opaque values are returned as data; recommendations do not install
packages or change configuration.

Every user-information integer field, including integer alternatives and feature
flag values, accepts canonical lossless integer strings under the reviewed family
rule; flags accept only exact quoted `0`/`1`. Native integers and omission remain
unchanged. Existing string alternatives remain available, including noncanonical
and out-of-range strings in `maximum_*` fields; no new closed enum is enforced.
This family combines reviewer observations with source-based inference, without
claiming that every field was observed. `contact_email` and `contact_email_2` also
preserve explicit null, separately from empty strings and omitted keys.

The 32 observed server flags accept exact quoted `0`/`1`; eight integer quantities
accept canonical lossless integer strings. Six quantities were observed with
numeric values. Compatibility for `file_upload_maximum_bytes` and
`minimum_password_strength_mysql` is inferred from the reviewer-inspected shared
configuration-copy path; those two fields were seen only as null. Optional fields
and null remain distinct. Session host additionally accepts null, observed through
the bridge. Only outer EA4 recommendation URLs accept `bool|string`; option URLs
stay strings. Boolean URL values are payload data with no inferred sentinel
meaning. Unknown fields remain untyped, and failed EA4 calls do not establish a
successful response contract.

The live catalog selects twelve parameterless reads and the user-domain read
`getUserInformation(name: 'domain')` through both routes, plus WHM version (27 GETs).
The user selector requests only the documented domain field; the unfiltered user
read can include `pushbullet_access_token` and is excluded from this catalog.
Enhancement lookup by ID and token retrieval are also excluded. No contact, locale,
theme or preference settings change. Other user-field live coverage, successful
payload variants and mutations remain tracked under `8x0.2.28`; structural captures
never persist tokens, addresses or server/session/user values.

The corrected account-environment capture at `c85a39e` on `11.138.0.19` passes
24 of 27 GET API/payload/probe checks, with zero known-field type mismatches.
Two EA4 API failures, one EA4 bridge failure and five masked unknown-field
observations keep strict status failed and remain unwaived under `8x0.2.28`.
Eleven functions pass on both routes and general EA4 recommendations pass directly.
No mutation or secret retrieval ran; user information remains domain-only.

The final [reviewed user-information capture](docs/api/evidence/2026-10-31-account-environment-reviewed.json)
at `c4576669980a1ab22952f96b976a34bf0db7be58` on `11.138.0.19` passes all three
GET observations: WHM version and the fixed 60-field user selector on both routes.
All HTTP/API/payload/probe checks and strict assessment pass with zero discrepancies.
The token selector and seven pinned fields absent on `npr` are excluded; no wire
form is established for those seven. No mutation or token retrieval ran. Earlier
EA4 failures and masked findings remain immutable and unwaived under `8x0.2.28`.
Reviewer mutation/lifecycle reports remain separate from this committed read capture.

Separate [Claude reviewer evidence](https://github.com/hostingpal/whm-api-client/pull/41#pullrequestreview-5720241923)
on cPanel `11.138.0.19` reproduces `getPhpRecommendations()` API failures through
direct UAPI and the WHM bridge. Inspection of `Cpanel/API/EA4.pm` ties the failures
to the absence of `/etc/cpanel/ea4/recommendations/custom_php_recommendation.json`.
`ApiErrorException` is the correct outcome for this observed server state; the
failed captures remain failed and unwaived.

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

`uapi()->dns()->fetchCpanelGeneratedDomains()` requires cPanel 120 and preserves
trailing dots; `lookup()` returns textual lines or explicit `null`.

PostgreSQL inventory and restrictions use GET; all eleven mutations use form POST.
`createUser(name, password)` returns the pinned nullable integer without assigning
meaning to its value. Other mutations return nullable opaque objects; explicit
null stays null and a missing payload is rejected. Password arguments are sensitive.
Database row fields are optional; an empty users list stays empty. The restrictions
prefix is an optional string, and omission remains distinct from an empty string.

Use `renameDatabase(oldName, newName)` and
`renameUser(oldName, newName, password)` with account-prefixed names when prefixing
is enabled. `renameUserNoPassword()` requires setting a new password afterward;
the upstream documentation recommends `renameUser()` instead.
`updatePrivileges()` synchronizes grants across the selected account after tables
are created; it is not scoped to one database or user. Deletion, rename and
privilege mutations declare no rollback. Check the target names before calling.
Inventory, user deletion and privilege synchronization require cPanel 84+;
database deletion requires cPanel 54+, and the other operations require 11.42+.
Disabling the PostgreSQL role disables all fourteen operations.

All fourteen PostgreSQL methods have offline contract coverage. The
[committed lifecycle capture](docs/api/evidence/2026-10-30-postgresql-section-enabled.json)
at `61a9f3e` on cPanel `11.138.0.19` passes 57 observations (33 GET, 24 POST),
covering all fourteen functions through direct and bridged UAPI. Its strict
assessment passes with zero discrepancies; both databases and both users are
confirmed absent after cleanup, including their original and renamed identities.
The run observes empty/populated inventories, restrictions, grants/revocation,
renames, password changes and account-wide privilege synchronization. All mutation
payloads are explicit null. Non-null mutation payloads remain offline-tested.
API readback does not establish SQL privilege enforcement, SQL login or grants
on newly created tables. The earlier
[failed service preflight](docs/api/evidence/2026-10-30-postgresql-section.json)
remains unchanged. Before the successful capture, the reviewer installed
PostgreSQL 13.23 at the owner's request and enabled its role and monitored service.

The [reviewed integrated capture](docs/api/evidence/2026-10-30-domain-features-php-reviewed.json)
at `27abfe6` observed all ten new reads through both routes on cPanel 11.138.0.19.
All 67 GET-only catalog API/payload checks pass, including both normalized mailbox
counts. Strict review remains failed: 12 unknown-field entries across 10 observations,
zero acknowledgments and two stale Quota acknowledgments. No mutation ran.
The empty-string `userdirprotect` variant remains documentation/offline coverage
because the live responses omit that field. PHP setters and the INI write/read
round trip remain offline-only; see the [source audit](docs/api/source-audit.md).


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
Mailbox counts and quota results retain their distinct zero/unlimited meanings.
`getPopQuota()` returns `int|'unlimited'`. An observed unlimited mailbox returns
the exact `unlimited` string in MB units and integer `0` with `asBytes: true`.
Canonical integer strings and `integral-fixed-point-string` wire values with
zero-only fractions normalize losslessly to integers, without floating-point
conversion; fractional values, other strings and overflow remain errors.
`listPopsWithDisk()` keeps floating-point MB usage, while opaque mutation payloads
retain fields and explicit nulls.

Two Email contracts remain limited by the pinned source: `traceDelivery()` preserves
the formal destination shapes, but deeper recursive routed examples are not a typed
recursive contract. The pinned `terminateMailboxSessions()` page shows a WHM-like
envelope; the reviewer observed normal UAPI envelopes on both routes, as recorded
in the source audit under `8x0.2.18`. Offline
fixtures cover all functions; live evidence is limited to explicitly recorded calls.
The earlier owned-mailbox capture passed 45 observations across 16 Email functions
through both routes, with zero discrepancies and both temporary mailboxes removed.
The revised recipe adds unlimited MB/byte readbacks and local delivery traces,
using the same two run-owned mailboxes and sending no mail.
The [reviewed v2 capture](docs/api/evidence/2026-10-30-email-section-reviewed.json)
passes all 55 HTTP/API/payload checks and the new quota/trace expectations, with
zero discrepancies and both mailboxes cleaned. Its strict assessment fails on
one direct-route resumption readback expectation, tracked in `8x0.2.18.1`; that
failure remains unwaived. The reviewer confirmed stale `listPops()` suspension
flags despite correct server lock state and judged the finding nonblocking.
Immediately after a write, the previous flag can persist until a later write
refreshes the cache. Password, quota and suspension checks establish API behavior,
not SMTP or IMAP access; see the [source audit](docs/api/source-audit.md).


The SSL module exposes 49 nondeprecated functions; `check_shared_cert` remains
excluded upstream. Private keys and PEM inputs are sensitive, and writes use POST.
`uploadKey(key: $pem)`, `uploadCert(crt: $pem)`, and
`fetchKeyAndCabundleForCertificate(certificate: $pem)` send inline string fields as
multipart bodies. Routing and authentication remain unchanged on both routes;
these methods do not accept filesystem paths or file handles. Generic `call()`
continues to support form bodies only. Binary multipart file fields are rejected
during generation because filename uploads are unsupported.

`getCabundle(cert: $pem)` sends the certificate as a form POST to avoid URL
length limits and certificate contents in access logs. `installSsl()` marks
certificate, CA bundle and private-key inputs sensitive. SSL certificate/key/CSR
records share reviewed compatibility rules: canonical integer strings normalize
to integer timestamp/bit-length fields, exact quoted self-signed flags become
`0|1`, and issuer organization preserves explicit null. These field rules do not
establish successful live execution of every method returning those records.

For `fetchCertInfo()`, `setCsrFriendlyName()` and `setKeyFriendlyName()`, provide
one documented selector (`id` or `friendlyName`); neither selector is forced by
the PHP signature. `installSsl()` exposes the documented scalar parameters and
repeated vhost names. Its optional `domain_dcv_method` map is omitted because the
pinned source does not specify its wire encoding (follow-up `8x0.2.16`). The
`upload_key` page disagrees about `key` versus `crt`; the generated request uses
`key` from its parameter contract, with live verification reported separately.
The [expanded owned SSL capture](docs/api/evidence/2026-10-30-ssl-section-expanded-final.json)
at `1f67a43` on cPanel `11.138.0.19` records all 31 planned responses (13 GET,
18 POST) across ten SSL functions through both routes plus WHM version. Every
HTTP/API, typed-payload and fixture-expectation check passes. Both uploaded keys,
both certificates and both generated keys are confirmed cleaned. This includes
all three multipart methods, `fetchCertInfo()`, `fetchCertificatesForFqdns()` and
`generateKey()`; nullable FQDN-certificate fields retain string|null precision.

Strict evidence remains **failed** for six masked unknown-field observations in
`upload_cert` and `delete_key`. Two are the added generated-key cleanup phases;
all six match earlier operation/route/contract and full masked payload structures,
which establishes neither unknown key identities nor counts. No finding is waived;
`8x0.2.19` retains the contract investigation. The
[earlier 27-response failure](docs/api/evidence/2026-10-30-ssl-section-reviewed.json)
and every historical capture stay unchanged. See the [source audit](docs/api/source-audit.md)
for provenance, limits and the separate original outcomes.

Certificate deletion/installation, account-wide AutoSSL and redirect changes take
effect immediately. Live verification is bounded to fresh owned fixture material,
without installing certificates or changing existing hosted services.

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

### Mail protection

The four mail-protection groups expose all 47 pinned functions. Nineteen reads
use GET; the 28 mutation or secret-returning operations use form POST. Inspect
both the envelope and per-item results: a successful request does not establish
that every queued message or domain action succeeded.

```php
$account = $client->uapi('npr');
$templates = $account->boxTrapper()->listEmailTemplates();
$dkim = $account->emailAuth()->validateCurrentDkims(domains: ['example.com']);
$preferences = $account->spamAssassin()->getUserPreferences();
$greylisting = $account->cpGreyList()->listDomains();
```

BoxTrapper requires the Receive Mail role. Its mailbox methods take an email
address unless their individual documentation also permits the cPanel username;
Webmail parameter defaults do not establish defaults for this client's cPanel
routes. Queue actions and rule setters accept the documented repeated arguments.
`blacklistMessages()` supports one filename or repeated `queuefiles`. Template
content and rule inputs are sensitive, as are returned message contents. Partial
failure records remain payload data; do not retry mutations automatically.

EmailAuth uses repeated `domains`, `keys` and `records` where documented. The
client encodes raw PEM/SPF text once; it does not pair or validate cardinality of
parallel lists. Three DMARC methods allow omitted domains; omission in apply/remove
can affect all domains on the account. Key installation does not itself update
DNS. Private-key inputs and returned PEM values are sensitive. DKIM validation
can update the validity cache despite its read-only classification. DMARC methods
require cPanel 124+, and the other EmailAuth methods require 78+.

Observed compatibility preserves nullable DKIM/PTR error fields and DMARC
error/record fields. A null DMARC validation record does not prove that the
local zone has no DMARC record; see the observed removal caveat in the method
documentation before deleting records. PTR IP versions normalize canonical integer strings to
integers. SpamAssassin symbolic scores preserve numeric strings without losing
precision. These corrections apply only to the evidenced fields; see the
[source audit](docs/api/source-audit.md) for their pinned and live distinctions.

SpamAssassin preferences keep their four known fields typed while preserving
custom keys. Custom values have no general pinned type and remain opaque in
both reads and updates, which return the complete preference object.
`required_score` lists accept integers, floats and observed numeric strings;
strings retain their exact precision and formatting. `updateUserPreference()` accepts
one scalar `value`; omission removes settings, while empty string and string `0`
remain explicit values. The documented `value-0`/`value-1` multi-value family is
not yet represented by the typed signature (`whm-api-client-8x0.1.20`). `clearSpamBox()` deletes all Spam Box
contents across the selected account and has no individual-mailbox selector.

Greylisting selected-domain methods send repeated plural `domains` keys, following
the declared parameter and prose despite singular examples. Status flags remain
integer `0|1`, optional fields remain absent, and dependencies identify affected
related domains. API readback does not prove mail filtering or delivery behavior.

The bounded live checks cover 22 of these 47 functions. The 19-GET catalog passes
all API/payload checks while retaining two PTR unknown-field observations as strict findings.
The disabled-mailbox BoxTrapper fixture passes all 45 observations with verified
cleanup. Remaining variants and live coverage stay tracked; these checks do not
establish filtering or delivery enforcement. See the [evidence index](docs/api/evidence/README.md).

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

### WHM backup and restoration

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

The immutable WHM backup/restoration capture at `a5b6e3a` on `11.138.0.19`
records 11 HTTP/API successes and ten payload/probe passes. Its `prebackup`
validation failure and one masked unknown-field observation with integer values
remain strict failures, unwaived under `8x0.2.29`. A wildcard identifies neither
the underlying name nor field count. Backup and queue inventories are empty,
module rows populated, and no mutation ran. Later reviewer observations and
contract corrections are separate from that historical capture.

### Team and user management

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

The corrected team/user-management capture at `9922081` on `11.138.0.19` passes
four of nine API/payload/probe checks: version, direct subaccount inventory and
password-strength through both routes. Seven direct inventory null corrections
pass; five API failures and one masked unknown-field observation with null values
keep strict status failed and unwaived under `8x0.2.30`. No team listing, password
retrieval, user/role/service mutation or message sending ran.

### Security and access controls

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

On reviewed cPanel `11.138.0.19`, missing account ModSecurity access explains its
read failures; enabled-account reads passed. ExternalAuthentication failed over
the bridge because its module file was absent, while direct calls worked. These
are installation-specific observations, not client route restrictions. ClamAV
was absent, so completed scans remain unobserved. Reviewer lifecycle reports are
separate from immutable capture evidence; see the source audit. Format-only
`scan_type` input correction remains tracked in `8x0.1.26` before release.

### Account backup and restoration

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

`BackupInfo::reset()` and `ExtractInfo::finish()` accept boolean and integer 0/1
cleared flags. Quota checks normalize canonical, bounded integer strings only at
`estimatedBytes` and `availableBytes`. On reviewed cPanel `11.138.0.19`, WordPress
backup/restore modules were unavailable on both routes. Disabled server backups
prevented `queryFileInfo()` and `restoreFile()` while `getUsers()` still returned
an empty list. Missing-archive `ExtractInfo::start()` raises `ApiErrorException`.
These installation-specific observations preserve normal error handling.

WordPressBackup `cancel()` and `start()` return an optional string `site`,
preserving the pinned outer type across format-only alternatives. Their generated
input currently accepts `string|int`; supply a string site as documented. Integer
site semantics are not established. The generator limitation is tracked under
`8x0.1.23`; it does not weaken the response contract.

### WHM database administration

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

### WHM ModSecurity administration

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
`features()->hasFeature()`, which accepts integer status 0 or 1 for its access result.
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

The [directory-access read-only capture](docs/api/evidence/2026-10-30-directory-access.json)
records reviewed GET checks on cPanel `11.138.0.19`. All 13 GET observations passed HTTP, API, payload and probe checks, with zero discrepancies and a passed strict assessment.
Directory records were present, but every captured `auth_name` and `passwd_file`
was an empty string, and the privacy-user list was empty. This capture made no
indexing, password-protection or leech-protection changes and establishes no
mutation or HTTP-enforcement coverage. Separate reviewer mutation observations
are recorded in the source audit; they are not part of this artifact.

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
all four resources absent. That artifact does not observe empty privilege lists
or disabled prefixes; the same two Quota acknowledgments remain visible.
The [complete-section run](docs/api/evidence/2026-10-30-mysql-section.json)
adds 57 phase-specific observations on cPanel `11.138.0.19`: one WHM version
probe plus 21 MySQL operations on each UAPI route, with repeated lifecycle reads.
All observations pass with zero discrepancies; original and renamed fixture
identities are confirmed absent afterward. It observes SELECT-to-empty revocation,
password-result failures checks, renames and empty-database maintenance. The four
remote-host/random-setup methods remain generated and offline-tested only, with
safe live ownership/recovery tracked separately. SQL enforcement/authentication,
populated tables/routines and other server configurations remain unobserved.
The databases are empty and access is revoked before rename; this run does not
observe grant or stored-code recreation.
See the [section recipe](tools/live-verify/README.md#complete-mysql-section-recipe).
The separate [finite-Quota scenario](docs/api/evidence/2026-10-17-finite-quota.json)
adds five observations on a disposable account. Both Quota operations passed
finite-limit checks through both routes, and token/account/domain/zone cleanup
was confirmed. Its command exited **1** because two unknown-field observations
remain unacknowledged in that scenario; the baseline acknowledgments do not apply.
Exhausted-quota enforcement and finite inode limits remain unobserved.

The [corrected Mime read-only capture](docs/api/evidence/2026-10-30-mime-section-corrected.json)
records 13 GET observations on cPanel `11.138.0.19`, all passing HTTP, API,
payload and probe checks with zero discrepancies and a passed strict assessment.
MIME and redirect lists were empty; handler rows were populated.
`getRedirect()['url']` and `listHotlinks()['redirect_url']` preserve observed null
separately from string and omission; their original failed capture is retained.
A separate [Claude review](https://github.com/hostingpal/whm-api-client/pull/35#pullrequestreview-5717739276)
of `933e4de` reports successful live checks of all eight mutations and populated
MIME, handler and redirect rows on `npr` through both routes, with `.htaccess`
restored to its original SHA-256. These reviewer observations are separate from
the committed read-only captures and do not establish HTTP redirect delivery or
hotlink enforcement.

Real HostingPal consumer integration testing is deferred by the user as of
2026-10-17; no consumer validation is claimed. It can resume against the current
client before full API coverage or a release. Any public prerelease is a separate decision with
an explicit coverage and compatibility statement.

The [domain-management read-only capture](docs/api/evidence/2026-10-30-domain-management.json)
records reviewed GET checks on cPanel `11.138.0.19`. All 27 GET observations passed HTTP, API, payload and probe checks. Strict assessment remains failed for two masked integer unknown-field observations at `$[].*` in `WebVhosts/list_ssl_capable_domains`, one per route. No acknowledgment or new typed field was added.
Addon, parked and subdomain inventories were empty. Their row fields, true redirect-status variants and nullable proxy-subdomain variants are not observed by this capture, which makes no mutation requests. A separate [reviewer report](https://github.com/hostingpal/whm-api-client/pull/33#pullrequestreview-5717731712) records seven mutation methods passing through both routes with cleanup; rename and temporary-domain conversion remain unobserved.

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


## Expanded WHM web-server configuration

`whm()->webServer()` adds 21 methods, preserving the original seven and covering
28 of 30 pinned functions. Eleven additions use GET and ten use form POST for
EasyApache profiles, PHP/FPM configuration, INI content/directives and PHP versions.
The two FPM JSON-body operations remain excluded under `8x0.1.18`.

Package, tag and directive lists use repeated wire fields; virtual hosts use the
reviewed numbered family. INI content/directives and pool configuration inputs stay
sensitive and are encoded once. Pool parameters accept caller-serialized JSON text;
they do not introduce JSON bodies or automatic PHP-array serialization. The native
scalar string|int limitation remains under `8x0.1.23`.

Impacted-domain selectors are individually optional according to explicit
either-or-both prose. Supply a domain or a true system-default selector; false
alone errors, and parked domains are excluded. Source defaults, version floors,
role restrictions, PHP-FPM prerequisites and profile overwrite behavior remain
in each method's PHPDoc. Configuration changes can affect hosted sites immediately;
the client adds no retry, conversion polling, rollback or server reload.

Profiles preserve strict cpanel/custom rows and source-described vendor lists.
EA4 metadata retains the pinned object values plus string and string-list variants
shown in its example. It does not infer additional scalar/null types. Void,
unspecified, nullable and absent result boundaries follow each operation's source.
INI reads and mismatch payloads may contain private configuration.

All additions have independent offline contracts. The fixed nine-GET catalog
reads version, additional package prefixes, installed packages, profiles, EA4
metadata/recommendations, EOL PHP counts, FPM capacity and vhost versions. It reads
no INI content or arbitrary file, changes no profile/PHP/FPM setting and fetches
no returned URL. Capacity estimates do not prove safe conversion or serving
behavior. Remaining live work stays under `8x0.2.45`.
