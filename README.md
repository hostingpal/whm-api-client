# WHM API Client

`hostingpal/whm-api-client` is a framework-independent PHP library intended to
cover the WHM API and cPanel UAPI.

The 113 generated typed groups cover 1,238 documented operations (see
[`docs/api/coverage.md`](docs/api/coverage.md)): WHM server identity, load, disk
usage, domain inventory, PHP configuration, feature inventory, DNS zone and DNSSEC
management, and the cPanel account lifecycle, plus cPanel domains, DNS, PHP and
features, domain management, directory access, the complete Email and SSL modules,
disk quotas, 14 Fileman operations and the complete pinned MySQL, FTP, PostgreSQL
and Mime sections, plus mail protection through BoxTrapper, EmailAuth, SpamAssassin
and cPGreyList.
Generic `call()` methods provide GET and form-encoded POST access without a typed
group, subject to the operation's parameter, route and envelope requirements.
The separate `callJson()` entrypoints send validated raw JSON bytes through WHM
or direct cPanel UAPI; WHM-bridged UAPI JSON is rejected locally. `call()` remains
GET/form-only. `statsManager()->saveConfiguration()` provides the first typed JSON
body, through direct cPanel UAPI only, with account-wide replacement semantics.
Generic `callMultipart()` streams ordered text/file parts through WHM or direct
cPanel UAPI; bridged generic multipart is rejected locally. Three typed SSL
methods retain their reviewed multipart string fields. The library provides token
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

### WHM root-access restrictions

`whm()->security()` exposes both pinned Security functions from cPanel 108.
`restrictWhmRootAccess()` replaces the root password login allowlist: only listed
sources may log in as root with a password. Include your own source and every
existing pattern that must remain. Existing root sessions are not terminated and
root resellers are not restricted. The API manages
`/var/cpanel/authorized_whm_root_ips`; upstream warns that direct file modification
with improper formatting can remove all cPanel and WHM root password access.

Client policy accepts one CIDR string or a nonempty sequential string list and
sends repeated `cidr` fields in a form POST body, encoded once without changing
values or locally validating CIDRs. The pinned CLI/URL examples establish repeated
keys only; the external reviewer reports repeated-form support in
`Cpanel::Form::Param`.

Source-based findings from the reviewer's inspection of
`Whostmgr/API/1/RootIPAccess.pm` and `Utils.pm` on `11.138.0.19`: missing input
raises `MissingParameter`; blank values, including list members, raise `Empty`.
Each CIDR passes `Net::CIDR::cidrvalidate` before the file is written, so one invalid
value leaves existing restrictions unchanged. Successful responses contain a
reduced CIDR list; `Net::CIDR::cidradd` can merge ranges. These findings are
reviewer-inspected implementation evidence, not locally pinned Perl or live
verification. They do not specify exact serialized server error messages.

The pinned/effective restriction payload remains an object with an optional
`cidr` string list; absent/null data fails. Offline schema-compatibility fixtures
for omitted fields and empty containers do not represent source-success examples.
`allowAllWhmRootAccess()` removes the restrictions and returns void. Both methods
send one request, with no automatic retry or rollback; a payload error after API
success does not undo the change. No SSH/firewall behavior is inferred.

No live root-access, allowlist, connectivity or session change ran. Both methods
remain unobserved under `8x0.2.59`.

### WHM API 1

| Handle and accessor | Methods |
| --- | --- |
| `whm()->server()` | `version()`, `getHostname()`, `getLoadAverage()`, `getDiskUsage()` |
| `whm()->accounts()` | Account lifecycle, inventory, enhancements, conversion status and account settings (45); see [generated methods](src/Api/Whm/Accounts.php). |
| `whm()->webServer()` | `phpGetInstalledVersions()`, `phpGetSystemDefaultVersion()`, `phpGetHandlers()`, `phpGetDefaultAccountsToFpm()`, `phpGetOldFpmFlag()`, `isConversionInProgress()`, `phpGetVhostsByVersion()` |
| `whm()->hostingPlans()` | `addOverrideFeaturesForUser()`, `addPackage()`, `addPackageExtension()`, `deleteFeaturelist()`, `deletePackage()`, `deletePackageExtension()`, `editPackage()`, `getAvailableApplications()`, `getFeatureMetadata()`, `getFeatureNames()`, `getFeaturelistData()`, `getFeaturelists()`, `getFeaturelistsByPackageTypes()`, `getPackageExtensionForm()`, `getPackageInfo()`, `getUsersFeaturesSettings()`, `listPackages()`, `matchPackages()`, `removeOverrideFeaturesForUser()`, `verifyUserHasFeature()` |
| `whm()->dns()` | DNS zone inventory, DNSSEC keys, domains and server DNS administration (40); see [generated methods](src/Api/Whm/Dns.php). |
| `whm()->security()` | Root password login allowlist replacement and removal (2); read the [root-access cautions](#whm-root-access-restrictions) before use. |
| `whm()->authentication()` | SSH keys, providers, external-account links and two-factor authentication (30); see [generated methods](src/Api/Whm/Authentication.php). |
| `whm()->ssl()` | WHM certificate, service SSL and AutoSSL operations (32); see [generated methods](src/Api/Whm/Ssl.php). |
| `whm()->logs()` | `listAccountsRetention()`; see [log administration](#log-administration). |
| `whm()->customizations()` | `retrieveCustomizations()`, `deleteCustomizations()`; see [interface data](#whm-interface-customization-and-scoped-data). |
| `whm()->userData()` | `getScopedUserdata()`, `setScopedUserdata()`; see [interface data](#whm-interface-customization-and-scoped-data). |
| `whm()->inProductSurvey()` | `getInProductSurveyUrl()`; see [interface data](#whm-interface-customization-and-scoped-data). |

### cPanel UAPI

| Handle and accessor | Methods |
| --- | --- |
| `uapi()->addonDomain()` | Complete addon-domain create, delete and inventory operations (3). |
| `uapi()->domain()` | Temporary-domain checks, conversion and rename operations (4). |
| `uapi()->domainLookup()` | Base-domain and document-root lookups (4). |
| `uapi()->park()` | Domain alias creation, removal and inventory (3). |
| `uapi()->subDomain()` | Subdomain creation, removal, document roots and validation metadata (6). |
| `uapi()->webVhosts()` | Account virtual-host and SSL-capability inventories (2). |
| `uapi()->gpg()` | All 7 pinned functions: key inventory, export, import, generation and deletion; see [generated methods](src/Api/Uapi/Gpg.php). |
| `uapi()->twoFactorAuth()` | All 5 pinned functions: account/team configuration, material generation, setup and removal; see [generated methods](src/Api/Uapi/TwoFactorAuth.php). |
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
| `uapi()->market()` | Seventeen of nineteen pinned functions: nine catalog/provider/certificate status GETs and eight session, cart, token and pending-queue form POST actions; see [generated methods](src/Api/Uapi/Market.php). |
| `uapi()->email()` | All 96 pinned functions, including mailbox lifecycle, quotas and suspension, forwarders, filters, MX routing, autoresponders, spam settings and delivery diagnostics; see [generated methods](src/Api/Uapi/Email.php). |
| `uapi()->ssl()` | All 49 nondeprecated functions, including certificate/key/CSR inventory and lifecycle, installation, AutoSSL, SNI and redirects; see [generated methods](src/Api/Uapi/Ssl.php). |
| `uapi()->imageManager()` | `convertFile()`, `createThumbnails()`, `getDimensions()`, `resizeImage()` (complete pinned module). |
| `uapi()->logManager()` | `deleteArchive()`, `getSettings()`, `listArchives()`, `listErrorLogs()`, `setSettings()`, `viewErrorLog()`; see [log administration](#log-administration). |
| `uapi()->nova()` | All 11 pinned functions; see [Nova and Sitejet site builders](#nova-and-sitejet-site-builders). |
| `uapi()->sitejet()` | All 12 nondeprecated pinned functions; see [Nova and Sitejet site builders](#nova-and-sitejet-site-builders). |
| `uapi()->quota()` | `getQuotaInfo()`, `getLocalQuotaInfo()` |
| `uapi()->ftp()` | `addFtp()`, `allowsAnonymousFtp()`, `allowsAnonymousFtpIncoming()`, `changePassword()`, `deleteFtp()`, `ftpExists()`, `getFtpDaemonInfo()`, `getPort()`, `getQuota()`, `getWelcomeMessage()`, `killSession()`, `listFtp()`, `listFtpWithDisk()`, `listSessions()`, `serverName()`, `setAnonymousFtp()`, `setAnonymousFtpIncoming()`, `setHomedir()`, `setQuota()`, `setWelcomeMessage()` |
| `uapi()->mysql()` | `listDatabases()`, `listUsers()`, `getPrivilegesOnDatabase()`, `getRestrictions()`, `getServerInformation()`, `createDatabase()`, `createUser()`, `setPrivilegesOnDatabase()`, `deleteUser()`, `deleteDatabase()`, `addHost()`, `addHostNote()`, `checkDatabase()`, `deleteHost()`, `dumpDatabaseSchema()`, `getHostNotes()`, `listRoutines()`, `locateServer()`, `renameDatabase()`, `renameUser()`, `repairDatabase()`, `revokeAccessToDatabase()`, `setPassword()`, `setupDbAndUser()`, `updatePrivileges()` |
| `uapi()->dav()` | `getCalendarContactsConfig()`, `isDavServiceEnabled()`, `isHordeEnabled()`, `hasSharedGlobalAddressbook()`, `enableSharedGlobalAddressbook()`, `disableSharedGlobalAddressbook()`; see version-specific limitations below. |
| `uapi()->cpDavd()` | `listUsers()`, `listDelegates()`, `addDelegate()`, `updateDelegate()`, `removeDelegate()`, `manageCollection()` |
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

The `uapi()->siteQuality()` group exposes ten of thirteen pinned SiteQuality
functions from cPanel 110: account creation/deletion, project creation/domain
update, token retrieval, association/enabled status, local configuration reset,
activation email and code validation. Three source gates remain open:
`get_monitored_domains` and `get_monitored_system_scores` locate their formal
results outside `result.data` (`8x0.1.34`); `get_all_scores` requires a nullable
`verbose` input without established explicit-null form encoding (`8x0.1.35`).
These methods are absent and uncounted. SiteQuality remains incomplete; live
verification is separately tracked in `8x0.2.71`.

```php
// Synthetic inputs; this explicit call creates a monitoring project on the server.
$project = $account->siteQuality()->createProject(
    name: 'Example project',
    url: 'https://example.test',
    standardAlerting: false,
);
```

Seven mutations/secret-bearing calls use one form POST on both routes; the three
status/token reads use GET. `updateDomain(oldDomain, newDomain)` sends `old_domain`
then `new_domain`; the server searches its owned projects without a client loop.
Optional `standardAlerting: false` sends 0, true sends 1 and null omits, retaining
the pinned no-standard-alerts default. Omitted `systemType` retains Website;
strings stay open. Null optional password omits and allows server generation;
empty password remains supplied. The source says the system does not save it.
Password, activation code and monitored URL inputs are sensitive. Returned URLs
are payload only and are never fetched; returned `app_token` values are long-lived
authentication credentials. Avoid logging tokens and mismatch exception payloads.
The pinned token text says losing a token requires a new account.

Create-user `enabled` remains optional `bool|null`: pinned booleans and null
are preserved, while native integer 0/1 normalize to false/true. Attributed
11.138.0.19 source review establishes the new-user default 0; accepting 1 is
bounded compatibility policy, not a provider observation. Strings, floats,
other integers and containers fail. The enabled-status read and
deleted/reset/activation/code status fields retain integer `0|1` flags. Payload
0 under UAPI status 1 is a result; UAPI status 0 fails regardless of payload.

`createProject()` returns required `project` and `component` keys. `project` is a
nullable opaque provider row; null on the 403 reuse path does not imply failed
component creation. `component` preserves the whole decoded provider JSON,
including its envelope, or integer 1 for an empty body. Inner keys and JSON values
are preserved without an invented schema; unknown wrapper siblings are filtered.
All ten roots reject missing data and explicit null. The project wrapper also
rejects missing keys and empty roots. Its nullable project map retains inherited
empty-array compatibility; nonempty lists and scalars fail. These corrections
come from attributed source excerpts, not successful provider wire observations.

Deletion initiates external-account deletion without promising immediacy. Source
review says it clears the local link even when remote deletion fails, so deleted
0 can accompany a retained external account. `resetConfig()` renames the active
config to `config.<time>` and retains a token-bearing backup; this is not secure
deletion and does not delete the external account. `updateDomain()` can rename a
project before system lookup fails; a no-match status 0 reason is in metadata
messages while `errors()` may be empty. These are source-review limitations.
An explicit `sendActivationEmail()` requests an activation email; the client never
initiates one implicitly or retries it. Inspect `responseMeta()` warnings,
messages and raw metadata.
The client adds no polling, implicit read, login, token exchange/storage, URL
fetch, retry, restore or rollback. Offline fixtures establish no live external
account, email-delivery, activation, monitoring or persistence behavior.

The complete `uapi()->serviceProxy()` group exposes all three pinned ServiceProxy
operations from cPanel 96: GET inventory and form POST set/unset calls for the
selected account. `getServiceProxyBackends()` returns a list with optional string
`backend` and optional nullable string `service_group`; null denotes the general
backend, while omission remains distinct. Treat returned addresses and configuration
as private infrastructure information.

[Review 5725574081](https://github.com/hostingpal/whm-api-client/pull/103#pullrequestreview-5725574081) of original
`4462b68f73730ede842dd617a926bba23565af04` reports all three operations and
`setBackends()` succeeding through direct UAPI on cPanel `11.138.0.19`, while the
WHM bridge raised `ApiErrorException` because it could not load ServiceProxy.
The reviewer attributes this to an absent on-disk module and different compiled-
binary/direct versus disk-loader paths from their server-source inspection; that
explanation was not independently inspected locally. This build-scoped observation
keeps routing available and adds no fallback; it does not establish every version.

`setServiceProxyBackends(?string $general = null, ?string $serviceGroup = null,
?string $serviceGroupBackend = null)` preserves the source optionality. Omitted
settings retain existing configuration; null omits and empty strings are sent.
Supplying a service group or its backend requires the counterpart on the server;
the canonical method adds no cross-field guard. General-only updates remain valid
client input. The pinned source names `Mail`; strings remain open in the interface,
without promising server acceptance of other groups or doing local DNS/IP checks.
The server requires routable backends and rejects its documented non-routable
address classes and obfuscated encodings. In that direct-UAPI review, tested
loopback, RFC 1918 and cloud-metadata literals failed with generic log-ID text and
no caller-visible cause. Use the error ID with server logs to investigate; this
does not explain failures for untested destinations.

The no-argument canonical setter and `general: ''` returned `null` without changing
stored values in that review. Omission/null and empty-string forwarding stay as
documented. A null API-success result alone does not prove a configuration change;
readback remains caller-controlled.

The additional generated `setBackends(array $backends, ?string $general = null)`
accepts a nonempty sequential list of complete string pairs. Both row fields are
required; partial pairs, nulls, unknown fields and wrong types fail before I/O.
For example, these synthetic inputs execute one request when called:

```php
$account->serviceProxy()->setBackends(backends: [
    ['serviceGroup' => 'Mail', 'backend' => 'mx1.example.test'],
    ['serviceGroup' => 'Mail', 'backend' => 'mx2.example.test'],
], general: 'proxy.example.test');
```

Rows emit `service_group`/`service_group_backend`, then `service_group-1`/
`service_group_backend-1`, retaining exact values, row order and duplicate groups;
optional `general` follows. The reviewed direct two-`Mail`-row call stored the
second backend, establishing acceptance of those bare/underscore `-1` names and
last-duplicate behavior for that tested case on `11.138.0.19`. This does not establish
other versions or atomicity. The formal names and naming prose use underscores,
while one pinned multiple-group example uses `service-group-1/-2`; that historical
source discrepancy remains recorded. Supplied backend/general addresses and the whole paired
collection are sensitive parameters. Avoid logging returned configuration or
`PayloadMismatchException::$payload`; library frames conceal sensitive inputs and
responses, but third-party transports control their own traces.

`unsetAllServiceProxyBackends()` removes all service proxy configuration for the
selected account. Both mutations preserve nullable opaque object results, including
unknown fields and explicit null; missing data is a mismatch. Existing empty-array
object-validator compatibility is retained. With the Web Server role active, writes
rebuild vhosts and restart the web server. A rebuild failure can still return API
success with a failure warning in metadata: inspect `responseMeta()` warnings and
raw metadata. Success does not guarantee application or persistence. The client
adds no implicit read, merge, save, restore, retry, rollback or backend contact.
Delivery `8x0.4.28` provides generated/offline-tested coverage. The runtime
observations above belong to the reviewer at the original commit; this integration
performs no live requests or capture. Live follow-up `8x0.2.70` remains open.

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
API envelope. `enableDnssec()`, `disableDnssec()` and `fetchDsRecords()` accept
`domain` as a string or a nonempty sequential string list. Scalars retain `domain`;
arrays always use zero-based `domain-0`/`domain-1`, including singleton arrays.
Other DNSSEC methods retain their existing arguments. Remaining indexed families
and source contradictions stay under `8x0.1.21`. The separate
[2026-10-31 singleton-list capture](docs/api/evidence/2026-10-31-dnssec-indexed-input.json)
at `5e0ea6b` on `11.138.0.19` passes three GETs and strict assessment with zero
discrepancies. It confirms DS singleton-list acceptance on both routes; domain
detail objects are empty, and this capture changed no DNS, key, registrar or
preference state. Separately, [review 5722987368](https://github.com/hostingpal/whm-api-client/pull/76#pullrequestreview-5722987368) of
`ec0796271da96827835bb26d8fb38091cb882e94` on `11.138.0.19` reports direct and
bridged multi-domain enable/fetch/disable, populated DS `keys` and `digests`,
scalar/singleton fetch, an empty re-read after disable, and an unknown-domain
`ApiErrorException` without a partial result. These are reviewer observations
under `8x0.2.26`, distinct from the immutable singleton capture; DNS propagation,
resolver validation and remaining variants are not established.
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
is the first generated typed JSON-body operation. Generic `call()` remains form-only.

`statsManager()->saveConfiguration($configuration)` replaces the account's analyzer
configuration. Explicitly call `getConfiguration()` first and prepare the complete
intended domain/analyzer set. Round-trip `enabled_by_user` (the user choice) into
input `enabled`, rather than the effective `enabled` returned by the read. Analyzers
absent from the read receive defaults. A partial-looking input can reset other
settings to defaults. The client makes no implicit read, merge, patch, retry, restore or rollback.
The [PR73 reviewer](https://github.com/hostingpal/whm-api-client/pull/73#pullrequestreview-5722705880)
reports that the 11.138.0.19 handler builds defaults for every domain/analyzer,
applies changes, then saves; its POD says unspecified configurations are disabled.
This is attributed upstream evidence, without a promise that every default is
universally disabled.

The required input is `array{changes: non-empty-list<array{domain: non-empty-string,
analyzers: list<array{name: string, enabled: bool}>}>}`. Every listed field is
required; only the zero-byte domain string is rejected. `'0'`, `'00'` and whitespace
are forwarded unchanged; this is not domain validation. `analyzers: []` and empty
analyzer names remain valid inputs. Native bools map to integer 0/1 wire flags.
Names remain strings without a closed analyzer enum. Unknown members, malformed
lists, nulls and wrong scalar types fail locally before I/O. Requiredness is an
operation-scoped correction to the formally optional pinned 11.138.0.10 input,
bound to the attributed 11.138.0.19 handler and raw server observations. Valid
structure does not establish completeness, ownership, availability or persistence.

This method sends one direct-cPanel UAPI POST with exactly `Content-Type:
application/json`. The WHM bridge rejects it locally after clearing metadata.
Available from cPanel 90 with the WebServer role; root/reseller control can prevent
user changes. Returned domain/analyzer fields remain optional, with native integer
0/1 flags. On 11.138.0.19 the source uses the same row builder as `getConfiguration()`:
`name`, `enabled`, `enabled_by_user`. `locked` and analyzer `available_for_user` belong
to response metadata: `locked=1` is reseller/root-only and `locked=0` is user-manageable.
Inspect `responseMeta()` warnings for skipped `not_owned`, `not_available` and
`unrecognized` entries even with status 1. An empty analyzer name produces an
`unrecognized` warning, while the handler silently skips an empty domain. The client
rejects the empty string locally; it does not establish validity of other domains.
No payload `raw_warning` field is invented;
success does not prove every requested setting applied. Check persistence separately.

The successful live-write gate in `8x0.1.18` remains **OPEN**. The reviewer's
well-formed raw direct-JSON requests bypassed this client and were blocked by
`_can_self_manage` on 11.138.0.19: “You may not modify the weblog analyzer
configuration”. Passing the gate requires a root/reseller server-wide statistics
setting change; this work authorizes no such change and ran no live calls.
Twelve other pinned JSON-body operations remain deferred; remaining
selection/source/route audit is tracked under `8x0.29.6`.

```php
$statsManager = $client->uapi()->statsManager(); // Direct cPanel credentials.
$currentConfiguration = $statsManager->getConfiguration();
// The caller now prepares the complete intended domain/analyzer set from this
// explicit read, accounting for server availability and converting each
// enabled_by_user === 1 into input enabled. Do not round-trip effective enabled.
/** @var StatsConfigurationInput $completeIntendedConfiguration */
$savedConfiguration = $statsManager->saveConfiguration($completeIntendedConfiguration);
$warnings = $client->uapi()->responseMeta()?->uapiWarnings();
// Inspect warnings and verify persistence; status 1 does not prove every item applied.
```

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
string. The native parameter is `?string`; supply the documented serialized JSON
string, or omit it. Explicit null omits the field. These scalar JSON-text inputs are not parsed, validated, re-encoded or repaired;
malformed text and empty strings retain their supplied bytes before form encoding.
Across the ten corrected string inputs, a strict caller's integer raises native
`TypeError` before the generated method starts, retaining that handle's prior
metadata and sending no request. Weak call sites retain normal PHP scalar coercion.
This closes `8x0.1.23` for these inputs only; other release blockers and live gaps
remain as documented. Clone URLs may contain credentials,
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

The Fileman group implements all 14 pinned functions. Directory autocomplete,
file information, file content and directory listings use GET; content saving,
in-memory transcoding, copy, move, rename, delete, trash, restore and trash purging
use form POST. `uploadFiles()` uses streamed binary multipart with direct cPanel
credentials only. A WHM-bridged handle rejects it locally before source I/O.
The existing typed SSL string multipart methods retain support for both routes.

`uploadFiles()` requires a sensitive `non-empty-list<Upload>` and emits `file-0`,
`file-1`, and so on in one request. Empty/sparse lists, non-Upload items and
filenames containing `<`, `>` or `;` fail before any source opens or reads.
General Upload filename rules also reject double quotes and header injection.
Duplicate filenames stay in order; the source describes server renaming without
promising a particular stored name. Files start at the borrowed stream's current
cursor and require a known exact remaining length; all sources stay stable during
the call. The shared transport sends exact Content-Length without chunking.

The optional `dir`, `getDiskInfo`, `overwrite` and `permissions` arguments are
query fields, encoded once. Every argument is sensitive in library trace frames,
including the query flags. Null omits; false sends `0`; `0644` and `644` remain
strings. Omission preserves server defaults, including overwrite=false. Source
prose resolves relative directories against the account home, defaults omission
to that home, creates a missing directory, and describes antivirus scanning and
permission warnings. These are source statements, not a client live guarantee.

Typed upload explicitly uses `OutcomePolicy::Unchecked`: both integer UAPI flags
`0` and `1` preserve valid per-file rows, including counts, status/reason/warnings
and nullable stored size. Inspect `responseMeta()->uapiStatus()` and every row;
either flag can include failed files. Status `0` with explicit null data or a
validated object containing `uploads: []` raises `ApiErrorException`, preserving
the same response snapshot and `errors()`. The upstream "You must specify at least
one file to upload." message can mean insufficient disk space: the form parser
may discard all files before the upload handler sees them. A row-bearing partial
or all-failed batch still returns normally. Missing/invalid status remains an
envelope error. Missing data, status-0 missing rows, list roots and malformed known
fields raise `PayloadMismatchException`, retaining the snapshot and unvalidated
payload with `outcomeConfirmed=false`. Status `1` retains the documented optional
fields; null data still mismatches. Decimal byte/infinity strings, int|string
inode counts and additional string display values remain exact. The data root
explicitly rejects empty JSON arrays; existing nested empty-object compatibility
is unchanged. Absent diskinfo is not zero usage. Any failure may follow stored
files; there is no retry, rollback, atomicity or inferred ownership/cleanup.

```php
use HostingPal\WhmApiClient\Upload;

// $directClient is configured with the target account's cPanel token.
$result = $directClient->uapi()->fileman()->uploadFiles(
    [Upload::fromPath('/local/report.bin'), Upload::fromString('empty.txt', '')],
    dir: 'public_html/incoming',
    overwrite: false,
    permissions: '0644',
);
foreach ($result['uploads'] ?? [] as $upload) {
    // Inspect status, reason and warnings, including when envelope status is 0.
}
```

This is offline typed coverage of one additional canonical UAPI operation, with
no new live upload claim. PR74's attributed raw server observations on
`11.138.0.19` informed the reviewed outcome/size/filename decisions and are
separate from this client. The four Backup binary restores and WHM configuration
upload are now typed. Their offline coverage does not establish live compatibility;
Backup server-path alternatives remain deferred under `8x0.30.9`.


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

All 14 methods have offline contract tests. The bounded live catalog selects
three directory inventory reads through both routes, plus WHM version. It reads
no existing file content and sends no mutations. Content, filesystem lifecycle
and transcoding verification remain under `8x0.2.24`; exclusive fixture creation
must precede overwrite-capable saves.

The [final Fileman inventory capture](docs/api/evidence/2026-10-30-fileman-inventory-final.json)
at `ec442bd` on `11.138.0.19` passes all seven GET observations
and strict assessment with zero discrepancies. It covers three inventory
operations through both routes; the other ten original methods remain offline-tested.
Typed binary upload has separate offline coverage.
No existing file content was read and no mutation ran. Follow-up `8x0.2.24`
owns their live verification; typed binary upload has offline verification only.
A separate owned client capture remains under `8x0.30.5`.

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
continues to support form bodies only. Binary multipart requires an explicit,
source-bound descriptor; Fileman uses a required upload list, and WHM configuration
restore uses required text plus one Upload. Separate generic
`callMultipart()` supports explicit Upload descriptors on WHM/direct UAPI without
changing these SSL contracts.

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
a string or nonempty sequential string list for `value`. Scalars retain `value`;
arrays use zero-based `value-0`/`value-1`, including singleton arrays. Omission or
null removes settings; `[]` is rejected before HTTP. Empty strings, string `0`,
order and duplicates remain exact. One preference is updated per call.
[review 5722987368](https://github.com/hostingpal/whm-api-client/pull/76#pullrequestreview-5722987368) of `ec0796271da96827835bb26d8fb38091cb882e94`
on `11.138.0.19` observed three ordered values including a duplicate, singleton
and scalar values, and deletion with `value` omitted through direct UAPI and the
WHM bridge. It also observed `[]`, sparse lists and integer items rejected before
dispatch. These cases are recorded under `8x0.2.23.1`; they do not waive the
historical failed `required_score` removal capture or establish mail filtering.
`clearSpamBox()` deletes all Spam Box contents across the selected account and
has no individual-mailbox selector.

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
- `listAccounts()` returns `[]` for the evidenced no-match forms: WHM success with
  omitted or explicit null data, or integer result 0 with the exact reason
  `No accounts found.` and omitted data. The original result and reason remain in
  `responseMeta()` (including result 0 and its diagnostic). Any other failure,
  present data on a failed response, or malformed nonnull success payload still throws.
  `accountSummary()` and account mutations fail for absent accounts/domains;
  `listPackages()` keeps its existing payload contract. Generic calls retain Flag errors.
  These WHM GET forms were observed on 11.138.0.19 (omission) and recorded on
  11.138.0.15 (successful exact-domain null; structural diagnostics only). See the
  [reconciliation](docs/api/source-audit.md#account-search-absence-reconciliation-2026-11-01).
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
Typed ClamScanner JSON disinfection remains excluded under `8x0.1.18`; generic form
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
separate from immutable capture evidence; see the source audit. The enum-only
`scan_type` input now generates `startScan(string $scanType): ?array`, with PHPDoc
`home|mail|public_html|public_ftp`. The pinned schema has neither type nor format;
its string enum and directory prose support this evidence-bound correction.
This pre-release narrowing rejects integers from strict PHP callers before a
request or metadata reset. Weak PHP callers retain normal scalar coercion.
Unknown strings still forward unchanged: the PHPDoc enum adds no runtime
membership validator. POST encoding, nullable opaque results and coverage are unchanged.

### Account backup and restoration

The account-backup section exposes 36 compatible methods across `backup()`,
`restore()`, `wordPressBackup()`, `wordPressRestore()`, `backupInfo()`,
`extractInfo()` and `websiteBackup()`. Eight explicit reads use GET; 24 operations
use form POST and four Backup restore operations use streaming binary multipart POST.
Their alternative server-path query modes remain omitted under `8x0.30.9`;
generic form access does not establish compatibility with those modes.

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
preserving the pinned outer type across format-only alternatives. Their native `site`
input is `string`, preserving the same outer source type without URL/path format
validation. The scalar input correction under `8x0.1.23` is complete for these
two parameters; the installation-specific live gap remains.

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

`batchSettings(int $settingId, string|int $state, ?bool $remove = null)` retains
its single-setting contract. `updateSettings(array $settings)` adds a nonempty
sequential list of `ModSecuritySettingInput` associative records: required integer
`settingId`, required `string|int` `state`, and optional boolean `remove`. State
remains required even with `remove: true`; explicit null and unknown row keys fail
locally. Missing optional fields retain the original one-based row index, and
input order and duplicate setting IDs are preserved. The entire collection is
sensitive. Both methods share one canonical response contract and issue one form
POST. Both prepare staged settings; explicitly call `deploySettingsChanges()` to
activate them. On failure, some staged settings may already have changed.
`ApiErrorException` does not expose `data.failures`; after **any** failure,
explicitly re-read `getSettings()` to establish the current staged state before
deciding what to do next. No automatic re-read, retry or rollback occurs.
In the `11.138.0.19` handler source, success means every submitted row was accepted;
it does not establish deployment. Duplicates retain emitted order, but server
hash traversal provides no deterministic ordering or last-wins guarantee. Avoid
conflicting repeated IDs.
The source restricts batch setting IDs to 0 through 9; this change adds no
client-side ID/range or state enum validation. Record input support is source-backed
and tested offline only.

`reportRule(string $email, string $message, string|int $rowIds, bool $send, string $type)`
accepts an integer single row ID or a comma-separated string. The pinned formal
integer schema contradicts its multi-ID prose and example; the source-backed
union preserves both. All IDs must correspond to the same ModSecurity rule.
Strings retain their exact bytes, including spaces, leading zeros and duplicates;
the client adds no parsing or ID/same-rule validation. `send: false` prepares the
report; `send: true` sends it to a third-party vendor. This input correction is
verified offline only; no report was submitted.

```php
$client->whm()->modSecurity()->updateSettings(settings: [
    ['settingId' => 1, 'state' => 'On'],
    ['settingId' => 2, 'state' => 'Off', 'remove' => false],
]);
```

This emits `setting_id1=1&state1=On&setting_id2=2&state2=Off&remove2=0`;
there is no `remove1`. Empty state strings, integer zero and string `'0'` remain
values. The alias adds one public method, while canonical coverage remains 1,142
operations (548 UAPI and 594 WHM), 99 groups and 200 generated PHP files.

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

### WHM login and mail protection

`whm()->cpHulk()` implements sixteen pinned cPHulk functions, and
`whm()->mail()` implements fifteen Greylisting functions. Fifteen reads use GET;
sixteen mutations use form POST. The JSON batch-record operation remains under
`8x0.1.18`. `cpgreylistSaveCommonMailProvidersConfig()` accepts optional `providers`
as a literal-key bool map. Null or [] sends no provider-specific fields; existing
provider settings remain unchanged, while omitted autotrust retains default 1.
The pre-existing Mail SNI exclusion stays intact.

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

### Expanded WHM mail management

`whm()->mail()` adds 51 methods while preserving all sixteen existing overlay
entries, including the fourteen typed greylisting methods and two exclusions.
The group covers 65 of 67 pinned functions. Twenty additions use GET and 31 use
form POST for mail authentication/DNS, incoming blocks, outgoing-mail controls,
mailbox state, diagnostics, SNI, spamd/Exim settings and message/session actions.

Private keys, Exim configuration and mailbox search expressions remain sensitive.
Syntax validation uses POST for private configuration text despite the pinned GET
declaration; its POST compatibility remains live-unverified. Source-marked mutating
checks such as DKIM validation and Exim configuration checking also use POST.
Message, queue, private-key and mobileconfig results remain private payloads.
No returned URL or configuration text is executed or fetched.

Repeated and numbered families follow each operation's explicit source spelling.
Paired DKIM key and manual-MX lists retain caller responsibility for positional
matching. `installSpfRecords()` accepts one scalar domain/record pair: the record
suffix family is not established and remains under `8x0.1.21`. Tracking filter,
sort and chunk controls require explicitly prepared generic parameters where
the typed source surface does not declare them. No dynamic field is invented.

Per-item domain/mailbox results remain separate from API success. DKIM and DMARC
validation payloads use source-described lists despite missing formal type nodes.
Other optional, nullable, opaque, absent and void contracts remain strict.
Each method preserves availability, role restrictions, defaults, units and effects.
Hold/release and suspend/unsuspend are distinct operations; expunge can permanently
remove messages. Enabling SNI can succeed with a warning that it is always enabled;
the deprecated disabling function stays excluded. The client adds no mail sending,
retry, rollback, automatic expunge or configuration restart.

All 51 additions have independent offline contracts. The fixed eight-GET catalog
reads version, default DMARC policy, SNI support/status, blocked country/domain
inventories, owned mailbox names and forwarding destinations for designated
`npr`/`npr.rs`. It reads no messages, queue contents, private keys or mobileconfig
and sends no messages or mutations. These reads establish no mail delivery,
filtering enforcement, TLS serving or key-installation behavior.
Remaining live work stays under `8x0.2.48`.

### PR61 reviewed corrections and boundaries

The PR61 reviewer reported canonical integer strings for mailbox `messages` and
`vsize`, null DMARC `record`/`error`, and quoted PTR `ip_version` with null `error`
on `11.138.0.19` through WHM. These are separate reviewer observations, not a new
committed capture. Only those integer leaves normalize canonical bounded values;
other fields retain strict types. Matching DKIM/SPF `error` and SPF `ip_version`
compatibility follows the shared source family; those variants have no independent
live observation here. Omission stays distinct from null.

The reviewed `Cpanel/Email/UserForward.pm` alias branch supports a scalar string,
while `.forward` supports a string list. `forward_to` accepts either, preserving
exact empty strings without interpreting their meaning. Nonempty strings are
source-derived support, not new live evidence. Both historical capture files stay
byte-identical; the original failed finding remains unwaived.

Typed `applyDmarc(policies, domains)` and `removeDmarc(domains)` require explicit
targets. Client policy rejects blank strings/list entries and trimmed `0` before
I/O, because falsey omission can select every server domain. This is scope safety,
not DNS validation. Native missing/null argument failures occur before metadata
reset; policy errors clear metadata before dispatch. To deliberately select all
domains, use the generic default-POST `whm()->call('remove_dmarc', [])` or
`whm()->call('apply_dmarc', ['policy' => $policy])`. Those explicit generic calls
bypass typed validation and return unvalidated payloads.

`unblockIncomingEmailFromCountry(countryCodes)` now requires an explicit country
selection as client policy. `setUserEmailForwardDestination(user, forwardTo)` is
user-first; recipient-count methods put `user` first where present and `startTime`
before `endTime`. The Exim check's absent/null success payload is accepted based
on the pinned error-only message description; healthy/repair POST behavior is
source-only and unobserved here. Present Exim payloads remain strictly validated.

`enableDkim()`, `applyDmarc()` and `installSpfRecords()` retain the Flag outcome
policy. On API result `0`, the exception retains metadata but loses per-domain
`data`; pinned docs do not define partial-failure result semantics. Re-read domain
state before further action and never retry automatically. No new live request,
mutation, capture, waiver or outcome handling was introduced by these corrections.

### WHM reseller administration

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

### WHM IP address administration

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

### Expanded WHM server administration

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
argument, with one form-encoding pass. Its native argument is `?string`;
explicit null omits the field. The scalar input correction under `8x0.1.23` is
complete for this parameter. Available-tier
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

### WHM authentication

`whm()->authentication()` exposes 30 of the 32 pinned Authentication operations
for SSH keys, authentication providers, external-account links and two-factor
configuration/policy. Seven use GET and 23 use form POST. Deprecated `accesshash`
remains excluded; typed JSON `twofactorauth_set_tfa_config_for_user` remains under
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

Provider display offers two input forms for the same operation. The existing
`setProviderDisplayConfigurations(string $configurations, string $providerId,
string $serviceName)` accepts caller-serialized text unchanged, including malformed
or empty JSON text. The companion accepts a required structured PHP array:

```php
$client->whm()->authentication()->configureProviderDisplay(
    providerId: 'cpanelid',
    serviceName: 'cpaneld',
    configurations: ['display_name' => 'Hosting login', 'label' => 'Sign in'],
);
```

The six keys `color`, `display_name`, `icon`, `icon_type`, `label` and `textcolor`
are optional nonnullable strings; unknown keys and other value types fail before
I/O. Missing members stay absent. The client adds no color, MIME or base64 checks,
fetches no icon URL and opens no icon file. The source `icon_type` default
`image/svg+xml` remains upstream. Each string stays a string, including JSON-looking
contents. Input order is preserved. The complete value is serialized once into
one `configurations` form field, then form-encoded once; `[]` sends
`configurations=%7B%7D` (`{}`). This is a form POST, with no JSON request header.
On cPanel 11.138.0.19, both methods merge supplied keys over the
current configuration; omitted keys retain their current values. Every call writes
the full merged configuration, including an empty object. This pins current values
and defaults for that provider/service, preventing later upstream default changes
from applying. It is not a reset or a write-free no-op. The server requires `color`
and `textcolor` to match `^[0-9a-fA-F]{3,6}$`, without a leading `#`. A key the
provider does not support, such as `icon` on a provider without an icon, can pass
local shape validation and still fail upstream. These upstream constraints are
not additional local validators. Configuration input is sensitive; one attempt has
no automatic retry or rollback. This source/offline companion adds no canonical operation and establishes no new live provider-write
compatibility.

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

### WHM SSL certificate management

`whm()->ssl()` exposes all 34 pinned SSL Certificates operations: ten GET
reads and 24 form POST calls, including source-classified POST fetch operations.
`resetAutoSslProvider()` and `setAutoSslProvider()` accept optional sensitive
`parameters` string maps with a literal `x_` prefix added to caller suffix keys.
Null or [] sends no extra provider fields. Terms URLs require deliberate caller
acceptance; the client does not fetch URLs or automatically consent. Reset affects
remote registration; use `disableAutoSsl()` to disable AutoSSL. Provider inventories preserve strict
named fields and opaque additional values; treat returned provider data as private.

PEM, private-key, CA-bundle and passphrase inputs remain sensitive. Domain exclusion
arrays use documented numbered names; deferred installation uses repeated fields
whose five lists callers must align by position, including each certificate and
private key. Neither the client nor its type checks validate equal lengths or
pairing. An empty bundle requests server-side bundle download. The metadata setter accepts caller-serialized JSON text encoded once as
a form field; its native argument is `string`. The scalar input correction under
`8x0.1.23` is complete for this parameter; JSON request bodies remain separate work.

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

### WHM DNS management

`whm()->dns()` exposes 41 of the 44 pinned DNS functions: sixteen GET reads and
25 form POST methods for zone inventory, DNSSEC keys, domains, MX records,
resolvers, nameservers and maintenance. Deprecated `dumpzone` remains excluded.
Two record-write operations remain under `8x0.1.30`: `addzonerecord` and
`editzonerecord` require composed form schemas.

`massEditDnsZone(int $serial, string $zone, ?array $add = null, ?array $edit = null,
?array $remove = null): array` sends repeated literal form fields in one POST
(cPanel 96+). **Both `remove` indices and `edit.line_index` are 0-based. Use
`line_index` from `parseDnsZone()`.** Reviewed `dumpzone` `Line` values are 1-based;
passing them directly can delete another record while the API reports success.
Prepare the current SOA serial and record indexes yourself:

```php
$result = $client->whm()->dns()->massEditDnsZone(
    serial: $currentSerial,
    zone: 'example.test',
    add: [json_encode([
        'dname' => 'example.test.', 'ttl' => 14400,
        'record_type' => 'TXT', 'data' => ['example text'],
    ], JSON_THROW_ON_ERROR)],
    remove: [$lineIndex],
);
```

`add`/`edit` require nonempty sequential string lists of caller-serialized JSON;
`remove` requires a nonempty sequential integer list. Order, duplicates, zero and
string bytes survive without parsing, joining or index conversion. Null or omitted
families send no fields; omitting all three still sends serial/zone once. On reviewed
cPanel `11.138.0.19`, upstream rejects that empty change set with `ApiErrorException`
and no zone change. The client adds no minimum-one-edit guard or local no-op. Empty
lists reject locally. Record fields and type-dependent data formats remain the caller's
responsibility; edit's `line_index` is zero-based. All three collection arguments
receive the generator's existing trace redaction; add/edit may contain private data.

Stale serials and malformed `add` JSON also raise `ApiErrorException` without a zone
change on reviewed `11.138.0.19`. Each surviving record retains its line count per
the pinned source; this does not promise unchanged offsets or safe index reuse.
Optional `new_serial` is returned as an integer: native integers and evidenced
canonical in-range decimal strings normalize losslessly; omission stays absent.
Callers must track lines per record for later edits. Temporary domains are
unsupported. No reread, serial refresh, retry, rollback, atomicity or DNS propagation
guarantee is added.

`Flag` raises on `result=0` before payload validation, so unvalidated failure
`data.by_line` details are not exposed. The reviewer identified the `InvalidZoneFile`
detail path in server source but did not exercise it. The pinned operation gives
`A` and `TXT` as examples without a complete record-type enum; reviewer inspection
of the `11.138.0.19` handler found a fixed set excluding `SOA`. These observations
are attributed in [the correction audit](docs/api/source-audit.md#whm-dns-mass-edit-review-correction-2026-11-01),
separately from committed captures. Composed `addzonerecord`/`editzonerecord` forms
remain deferred under `whm-api-client-8x0.1.30`.

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

### Expanded WHM account management

`whm()->accounts()` covers 47 of the 49 pinned functions. The expanded surface
preserves the ten existing methods and adds 23 GET and fourteen form POST methods
for inventories, enhancements, conversion status, quota/bandwidth controls,
password policy, Digest Authentication and document roots. Password-strength
calculation uses POST because its password input is sensitive.

Typed JSON personalization calls remain under `8x0.1.18`. Conversion initiation accepts
`copyMysqlDatabases` as an old-to-new string map with literal `copymysqldb-` prefix;
null or [] copies no databases. Purely numeric keys reject after PHP integer-key
coercion. `moveMysqlDatabases`/`moveMysqlUsers` preserve unsuffixed scalars and use
one-based suffixes for nonempty lists. `massModifyAccount()` retains 50 fields;
MXCHECK-* is excluded after the reviewer's scoped no-effect observation. Its
unchecked result policy returns validated partial/all-failed rows even when outer
result is 0; callers inspect every per-user result and metadata. No retry,
polling, completion, cleanup or rollback is added. Conversion remains unobserved.
Conversion status supports one integer job ID. The pinned examples disagree about
the first suffix, but [review 5722427592](https://github.com/hostingpal/whm-api-client/pull/72#pullrequestreview-5722427592)
reports `_parse_job_ids` accepting `job_id` or numeric `job_id-N`, including zero-
and one-based suffixes, on `11.138.0.19`. This source inspection lifts that
numbering ambiguity for the reviewed handler/version; list adoption still needs
an explicit pattern and integer input policy under `8x0.1.21`. It establishes
neither a general arbitrary-pattern contract nor live conversion-list behavior.

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

### Expanded WHM hosting plans

`whm()->hostingPlans()` exposes 22 of 24 pinned functions, including package
creation, editing, deletion, extension management, matching, application inventory
and feature overrides. The original eight methods and two deprecated exclusions
are retained, with a narrow team-limit response correction in `listPackages()`.
`createFeaturelist()` and `updateFeaturelist()` require explicit `features` bool
maps with literal keys. Empty update rejects locally; omitted update keys preserve
state in the reviewer observation on 11.138.0.19. Empty create still sends the
fixed request and created an all-disabled list in that version-scoped fixture.
Create requires reseller Add/Remove Package permission; overwrite can replace a
list. Updated flags accept native/quoted 0|1 and normalize to integers;
`invalid_features` is a string list to inspect, without an atomicity guarantee.
Both methods use form POST. Source contradictions and reviewer provenance are
recorded separately in the source audit.

Package options preserve exact wire casing, omission, false/zero and literal
`unlimited` values. Quotas use the source's MB units. `editPackage()` changes
accounts using the plan: an omitted `featurelist` assigns the default feature
list to those accounts, and omitted `_PACKAGE_EXTENSIONS` means no extensions.
Omitting an option does not promise to preserve its current value. Read
`getPackageInfo()` first and send the full intended settings. Mailbox-quota edits
do not change existing mailboxes. A package in use cannot be deleted;
`deletePackage()` returns no value. The client performs no retry or rollback.

Feature overrides accept caller-serialized JSON text in form fields, encoded
once, rather than a JSON body or automatically serialized PHP arrays. Both native `features` arguments are `string`; the scalar
input correction under `8x0.1.23` is complete for these two parameters. Extension names and optional variables are
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

### WHM integrations and hooks

`whm()->integrations()` exposes all twelve nondeprecated functions: three GET
reads and nine form POST operations for account integration groups/links, token
replacement and server-wide hook inventory/editing/deletion/order. The deprecated
remote-access-hash function remains excluded. `getIntegrationLinkUserConfig()` is
a read sent conservatively as POST because the source sets `x-readonly=false`;
that flag alone does not establish mutation or link creation.

Subscriber IDs, tokens, autologin/fallback URLs and executable hook/check/rollback
text are sensitive. Returned configuration, commands and URLs are payload data;
the client never executes them or fetches URLs. `createIntegrationLink()` accepts
custom group IDs as strings alongside the source's listed built-in groups.
Hook ordering takes caller-prepared CSV IDs, encoded once.

The pinned `editHook()` source does not specify partial-update semantics for
omitted options. Its `escalateprivs` default of 0 is a separate documented fact.
Read the current hook and supply intended option values that matter, including
escalation. The client makes no preservation, transaction or rollback guarantee.
Hook changes can affect later events and privilege escalation; requests are not
retried or automatically restored.

The three removal/token responses have unconstrained, nonrequired `data` in the
pinned source. Successful absent or null payloads return `null`; present JSON
values retain the existing mixed contract, including scalars and opaque fields.
API/envelope failures still raise exceptions with response metadata. The four
source data-free operations remain void.

All thirteen optional action fields are preserved by one source-backed `actions`
list correction instead of thirteen leaf overrides. Only `check`, `description`
and `rollback` permit null. Canonical in-range integer-string weights normalize
to integers; exact quoted 0/1 action flags normalize to integer flags. Strict
neighboring fields are unchanged. The global depth limit is unchanged and
`8x0.10.5` remains open.

The immutable two-GET capture at `7b6f5ce` on `11.138.0.19` passes strict
assessment with zero discrepancies: version and empty integration-group
inventory for `npr`. It establishes no populated item shapes. Separately,
[PR62 review 5721050295](https://github.com/hostingpal/whm-api-client/pull/62#pullrequestreview-5721050295)
reports `listHooks()` failing on digit-string action `weight` and `escalateprivs`,
and empty `listIntegrationGroups()`/`listIntegrationLinks()` for `npr`.
`listIntegrationLinks()` also returned empty for an unknown user, so an empty
list does not prove account existence. Other action flags and `reorderHooks()`
compatibility are reviewed source-family policy tested offline, not additional
live observations. No capture rerun, hook execution or URL fetch accompanied
these corrections.

Separately, [PR62 review 5722110065](https://github.com/hostingpal/whm-api-client/pull/62#pullrequestreview-5722110065)
at `160fc83` on WHM `11.138.0.19` reports a disposable `wrvint` lifecycle using
fake tokens: create group/link, get link user config, remove group/link and update
the link token (six POST methods). Removing a nonexistent group or link also
succeeded with null, so success does not prove an item was removed. Account and
home cleanup were verified; no URL was fetched. These reviewer observations do
not expand or replace the immutable two-GET capture. Server-hook writes
`editHook`, `deleteHook` and `reorderHooks` remain unobserved under `8x0.2.49`;
the depth-limit audit `8x0.10.5` stays open.

### WHM NGINX cache management

`whm()->nginxManager()` covers all six pinned functions: two configuration GETs
and four form POST writes. Typed writes require explicit nonnullable `users`, as
one string or a nonempty sequential string list. They reject trimmed blanks,
trimmed zero, empty/malformed lists and nonstring entries before dispatch. This
trimmed-zero rule is conservative client policy, not an observed NGINX behavior
or a username validator. Valid strings retain their original bytes.

The pinned upstream omission behavior affects every user on all four writes.
Use narrow typed targets for normal operations; deliberately omitting `user` in a
generic POST is the explicit server-wide form. Generic calls intentionally bypass
typed target validation. `setCacheConfig()` keeps `enabled` first; false sends 0
and true sends 1. The user-configuration read retains optional filtering,
including null for all-user inventory. `getUsersCacheConfig(users, merge)` accepts users first.
Both reads accept only `merge: true` (sends 1) or null/omission (omits merge);
false, integers and strings reject even in weak callers. Native argument errors
retain prior metadata; runner target validation clears it before rejection.

`default.enabled` remains an integer 0|1 flag; `system.enabled` and
`users[].config.enabled` remain strict booleans. Configuration objects preserve
dynamic opaque values. A merged integer enabled variant is an **unobserved risk**
under `8x0.2.51`, not evidence for widening those response contracts.
All four writes return void. The pinned version floor is 11; requests add no
automatic restart, retry or rollback beyond the selected API operation.

```php
$nginx = $client->whm()->nginxManager();
$nginx->getUsersCacheConfig('npr', merge: true);
$nginx->clearCache(users: 'npr');
$nginx->rebuildCacheConfig(users: ['npr']);
$nginx->resetUsersCacheConfig(users: 'npr');
$nginx->setCacheConfig(enabled: false, users: ['npr']);
```

Deliberate **server-wide** operations, each using generic POST with `user`
omitted (choose only the intended operation):

```php
$client->whm()->call('nginxmanager_clear_cache', []);
$client->whm()->call('nginxmanager_rebuild_cache_config', []);
$client->whm()->call('nginxmanager_reset_users_cache_config', []);
$client->whm()->call('nginxmanager_set_cache_config', ['enabled' => 0]); // 1 enables caching
```

The immutable three-GET capture at `2b36d29` on WHM `11.138.0.19` has a passing
version check and two API failures; its strict assessment remains failed and
unwaived. Separately, [PR63 review 5721097412](https://github.com/hostingpal/whm-api-client/pull/63#pullrequestreview-5721097412)
reports both reads failing because the required EA4 `ea-nginx` package is absent.
This reviewer diagnostic does not change the historical artifact or establish a
successful payload. No NGINX method has a successful live payload observation;
all four POSTs remain unobserved. No installation, replay, cache/service change or
new capture accompanied these corrections. Gaps remain under `8x0.2.51`.

### WHM Commerce and Market

`whm()->commerce()` exposes three nondeprecated Commerce Integration functions;
`whm()->market()` covers all nine cPanel Market functions. Three reads use GET:
Commerce configuration, commission configuration and provider inventory. Nine
operations use form POST, including the product reads whose source declares
`x-readonly=false`. The two deprecated ecommerce functions remain excluded.
Source version floors are Commerce 11.122, token validation 62 and Market 56.

Commerce configuration uses `Sitejet/get_commerce` and `Sitejet/set_commerce`,
as shown in pinned CLI and URL examples. Canonical map identities, formal paths
and documentation links stay unchanged. The no-argument getter uses GET by client
policy based on its read purpose and the reviewer's successful GET; the source
has no `x-readonly` marker. The setter remains POST and source-backed only.
`validate_login_token` stays bare: its path and both examples agree despite its
`Market-` operation ID. No token call has established live compatibility.

[PR64 review 5721097744](https://github.com/hostingpal/whm-api-client/pull/64#pullrequestreview-5721097744)
separately reports bare `get_commerce` failing and the Sitejet GET succeeding on
`11.138.0.19`, with string `is_enabled` and null `storeurl`. It also reports null
local/remote commission IDs. These reviewer observations are separate from the
immutable runner capture. The getter preserves original integer 0|1 flags and
exact strings, including empty strings; URLs and commission IDs remain optional
nullable strings. No blanket coercion or boolean widening is added.

Both product reads preserve known strict fields and additional product values.
The unadjusted read retains `base_name` and `license_term` as additional data;
its category accepts null by alignment with the adjusted contract. The actual
pinned twelve-row example has integer values for all three `x_price_per_domain`
fields and four null identity-verification lists. These are source/offline
regressions; prices preserve integer/string distinctions. Deep option strings,
other price fields, names and flags retain their existing contracts.
`setProviderCommissionId(provider, commissionId)` and
`setProductAttribute(provider, productId, attribute, value)` put target identifiers
first; prefer named arguments. Sensitive input annotations remain in place.

Tokens, redirect/storefront URLs and product-attribute values are treated as
sensitive by client policy. Returned URLs, icons, schemas and product content
are payload data. The client sends one request, never fetches returned URLs and
adds no retry or rollback. All twelve methods have offline contracts. The
completed two-GET runner catalog covers version and provider inventory only;
remaining live variants and all POST behavior stay under `8x0.2.52`.

### WHM support-ticket administration

`whm()->supportTickets()` covers all fourteen pinned functions: four GET reads
classified `x-readonly=true` and ten form POST operations. Required integer server
and ticket selectors remain required, including for `list()`. Grant/list SSH
usernames can be omitted; revoke requires one. Omission does not infer root.
Grant secure IDs, OAuth codes and redirect URIs remain sensitive.

The source auths/servers list repair follows explicit array prose and item schemas.
Support-agreement dates preserve optional null values; flags remain integer 0|1.
Reviewed source-family compatibility also permits null on optional support-contact
fields and on the conditional nested grant timestamp for `NOT_AUTHED` rows.
Those nulls are inferred from source prose, not explicit pinned nullable flags or
live payload observations. Omission, null and exact empty strings stay distinct.
Grant/revoke echoed server and ticket IDs use the existing bounded canonical
integer-string normalization, with native integers preserved and invalid strings
rejected. Other IDs, timestamps, strict siblings and input types are unchanged.

Agreement acceptance and OAuth validation have source-documented void results.
SSH-test startup returns null for successful absent/null data and preserves a
present opaque object, including unknown nested fields; malformed present values
still fail after API success. This compatibility remains source-inferred and
offline-tested. A payload error can occur after state changed: never retry a
mutation automatically. Required fields and API outcome checks remain enforced.

The PR65 reviewer reports `getSupportAgreement()`, `getSupportInfo()`,
`whitelistCheck()` and `list()` through WHM GET on `11.138.0.19` with this client's
stateless token authentication all raising `ApiErrorException`:
"The system failed to restore the saved session." These failed reads establish no
successful payload compatibility. The pinned URL samples use `/cpsess##########/`
and the OAuth source says the validated token is stored on the current session.
The [final reviewer source trace 5722449280](https://github.com/hostingpal/whm-api-client/pull/65#pullrequestreview-5722449280)
on `11.138.0.19` establishes that `Whostmgr::TicketSupport::Token->new` calls
`Cpanel::Session::Restore::restoreSession()` and fails with the recorded error
when no session exists. `Whostmgr/TicketSupport.pm:31` builds remote Customer
Portal calls through that object; `ticket_validate_oauth2_code` directly calls
`Token->new()->fetch($args)`. Thus `validateOauth2Code()` cannot succeed
statelessly. This is an attributed reviewer source trace, not code inspected in
the locally pinned documentation. It applies to paths reaching the Customer
Portal; no session requirement is established for local-only methods.

The client has no session or cookie handling. All fourteen methods have no
observed success path and all ten POST methods remain unobserved. This carries
forward the documentation correction `whm-api-client-m6p`; session compatibility,
all success payloads and remaining live variants stay under `8x0.2.53`.

Stub-ticket creation, access grants/revocations, firewall changes, remote SSH
checks, OAuth exchange/session state and agreement acceptance can have external
or server effects. Returned support text, tokens, links and commands are payload
data and never executed or fetched. API success does not establish remote SSH
access or security enforcement. Exact per-method version floors remain in PHPDoc.
All fourteen methods have independent offline contracts; generated coverage,
offline validation and the separate reviewer report remain distinct.


### UAPI GPG and two-factor authentication

`uapi()->gpg()` covers all seven pinned functions and `uapi()->twoFactorAuth()`
all five. Four reads use GET and eight mutations/secret-bearing operations use
form POST. GPG requires cPanel 82; two-factor operations require cPanel 54.
Private key data, passphrases, shared secrets and verification tokens remain
sensitive. Key identifiers select records and are distinct from key material.

`setUserConfiguration(secret, tfaToken)` accepts `string|int` as a client
correction: prefer a string such as `'012345'` to preserve leading zeroes.
Native integers and zero remain accepted. The pinned schema says integer with
`maxLength: 6`, while the CLI sample quotes `'528112'`; no padding, token
validator or TOTP computation is added. Both arguments remain sensitive.

The upstream `importKey()` parameter instruction, "You must URI-encode this
value", is retained. Its percent-encoded parameter example and double-encoded
URL sample (`%250A`) are misleading for the behavior observed by reviewer
5722555389 on `11.138.0.19`: upstream reads `key_data` without an extra decode.
Pass raw armored text; the client form-encodes it once. The reviewer reimported
an owned public key containing 34 `+` and 33 `/` characters after deleting it;
the same ID returned and re-export matched after whitespace/header normalization.
This establishes neither exact raw-byte/header preservation nor all-version
compatibility. No correction reran the operation.

The separate populated-key review `5722555389` at
`9b34fd5b791857625f3c7b5085f919a0a81bf124` on `11.138.0.19` observed digit-string
`bits` in both inventories and digit-string `created` and expiring `expires` in
secret-key rows, through both direct and bridged GET. Non-expiring secret-key
`expires` was exactly `''`. The former typed contracts raised
`PayloadMismatchException`. The correction normalizes only these four integer
leaves from bounded canonical integer strings, retaining native integers,
optional omission and explicit zero. Secret-key `expires` additionally preserves
`''` distinctly from 0. Null, noncanonical and overflowing numeric strings remain
invalid. Public-key timestamps stay exact strings, including empty non-expiry.

Both reviewer key creations returned `''` and raised `PayloadMismatchException`
with `outcomeConfirmed=true`, yet both keys existed afterward. `generateKey()`
now accepts exactly `''` alongside the pinned nullable opaque map. Missing data,
nonempty strings, other scalars and nonempty lists remain invalid. Retrying after
a confirmed outcome can create another key. These corrections pass independent
offline fixtures; successful typed acceptance on the corrected head has not been
live-observed. Preserve source optional fields, strict 0|1 flags and null/missing
distinctions. The `tfa_removed` singleton and duplicated-1 configured-state prose
discrepancy remain explicit without adding general enum enforcement.

Key generation depends on entropy and may time out; timeout does not establish
whether a key was created. Key deletion and two-factor setup/removal can alter
account security immediately. Calls add no retry, rollback, local cryptography
or authentication flow. API success does not prove signing, encryption or login
enforcement. Returned secrets/key contents are sensitive payload data.

All twelve methods have independent direct and bridged UAPI contracts. The fixed
five-GET catalog reads version, public-key inventory and two-factor status through
both routes. It exports no key, reads no secret-key inventory or private/team
configuration, and makes no key/authentication changes. Remaining live variants
and controlled lifecycle verification stay under `8x0.2.54`.

Historical [review 5721152661](https://github.com/hostingpal/whm-api-client/pull/66#pullrequestreview-5721152661)
reported six successful GETs on `11.138.0.19`: empty public/secret-key inventories
and two-factor status through both routes. Those empty lists supplied no item
fields. The later [review 5722555389](https://github.com/hostingpal/whm-api-client/pull/66#pullrequestreview-5722555389)
used disposable `wrvgpg`, generated expiring/non-expiring keys, revoked its temporary
token and confirmed account/home cleanup. It also confirms typed public/secret
export, deletion and raw-armored import; bad import/deletion inputs raised
`ApiErrorException`.

The reviewer generated secrets until an independently computed TOTP had a leading
zero, enrolled with that string (`tfa_configured`), observed enabled state by GET,
and removed enrollment (`tfa_removed`); a wrong code raised `ApiErrorException`.
This establishes API enrollment/removal, not actual login enforcement. Six POST
methods have explicit reviewer execution evidence: GPG generation (with payload
mismatch), secret export, import, deletion, TOTP enrollment and removal. Secret
generation for the fixture is reported, but `generateUserConfiguration()` and its
typed result are not separately established. `getTeamUserConfiguration()` also
remains unobserved. Only the list reads are explicitly attributed to both routes;
no route is inferred for the other reviewer operations. The committed five-GET
capture, separate reviewer execution and corrected offline acceptance remain
separate. Remaining routes, compatibility variants, committed mutation evidence,
team configuration, login and cryptographic behavior stay under `8x0.2.54`.

### DAV calendars, contacts and delegation

`uapi()->dav()` and `uapi()->cpDavd()` each cover all six pinned functions:
six reads use GET and six mutations use form POST. They operate on the selected
cPanel account; identifiers and supplied values retain their exact wire encoding.
The client sends one request per call, with no retry, rollback, DAV connection,
synchronization or URL fetch. API success does not establish DAV authentication,
access enforcement or synchronization.

Review [5721214794](https://github.com/hostingpal/whm-api-client/pull/67#pullrequestreview-5721214794)
inspected head `2db7f7e077d68a63f89fbb15647202f6da832dc7` on cPanel `11.138.0.19`.
Its server implementation references are reviewer-inspected `Cpanel/API/DAV.pm`
and `Cpanel/API/CPDAVD.pm`; this correction performed no new server inspection.
The reviewer read `npr` through both routes and used a disposable `wrvdav` account,
a mailbox and a temporary token for writes; account/home/token cleanup was confirmed.
This report is separate from the immutable seven-GET capture.

`addDelegate()`, `updateDelegate()`, `removeDelegate()` and `manageCollection()`
accept successful missing/null data as `null`. A present object still validates
the pinned optional strict integer `shared` flag; that field was not sent by the
reviewed handlers. Earlier `PayloadMismatchException(outcomeConfirmed=true)`
followed successful writes; callers must not retry or automatically undo them.
Observed null successes cover collection create/delete through both routes,
collection update bridged, and delegation add/update/remove direct. Populated
`listDelegates()` was checked direct; bridged remove was checked only on empty state.

`updateDelegate(calendar, delegatee, delegator, readonly)` requires an explicit
nonnullable fourth boolean as client policy: false sends 0 and true sends 1.
`addDelegate()` retains its optional boolean. The reviewer-inspected implementation
defaults omission to 1, and an omitted update removed write access from a read-write
share. Mailbox add/update worked direct but failed bridged with undefined
`Cpanel::Email::Accounts::manage_email_accounts_db`; no fallback or retry occurs.
This observation does not establish that every possible bridged form fails.

`listUsers()` returns a map keyed by principal, then collection ID, preserving all
five optional collection fields and exact local keys. `protected` accepts native
0/1 and exact quoted "0"/"1", normalized to integers. Evidence paths wildcard both
dynamic key levels; private names and content are not persisted. Configuration
`no_ssl.port` and `ssl.port` accept canonical bounded integer strings observed direct
and native integers observed bridged. `activesync.port` uses the same explicit
source-family/offline policy; ActiveSync was disabled and its port unobserved.
Other flags, sibling types, omission and null rules remain strict.

On the reviewed version, shared-addressbook enable/disable are no-op stubs returning
`shared=0`. `hasSharedGlobalAddressbook()` is a return-0 stub that raises
`ApiErrorException` with empty `errors()`, with/without name and after enable;
that failure is not a false/not-shared result. `isHordeEnabled()` fails on both
routes because the server cannot find function `is_horde_enabled` in module `DAV`.
The pinned Horde prose promises 0; the current integer-only validator also accepts
1/-1 offline and adds no singleton-enum validation. These observed limitations
remain prominent in generated PHPDoc; pinned sources are unchanged.

Collection create requires name; update/delete require path, and create omission
autogenerates `<collection_type>-<uuid>`. Update needs at least one of name,
description or calendar-color. The reviewer-inspected source filters them by
truthiness: empty strings and "0" alone error and are ignored beside another field,
so they cannot clear a description. The client preserves supplied values and exact
`calendar-color` encoding without adding cross-field validation or undoing deletion.

The fixed seven-GET catalog is diagnostic, with unchanged account binding, order,
URIs and guards; it is no availability guarantee. The two misleading overlay
success recipes for shared-addressbook/Horde reads are removed, while the diagnostic
catalog retains those known failures. Its original capture remains failed and
unwaived. No new capture, live request or mutation ran for this correction.
Remaining availability, route/action and enforcement gaps stay open in `8x0.2.56`.

The immutable [seven-GET capture](docs/api/evidence/2026-10-31-dav-calendar.json)
at `628c46eec9608e31a5bc32dedb1d374f25d6b1ac` on `11.138.0.19` has seven HTTP
200 observations: version and both DAV service reads passed, while shared-addressbook
and Horde failed through both routes with payload `not_checked`. Strict assessment
is failed despite zero schema discrepancies, with no waiver. SHA-256:
`e4cec00d478951bd22f0cc1e82609ebcdcac2041709111a08a8034007770b19a`.
It includes no private reads, writes, DAV access or synchronization proof.


### Log administration

`uapi()->logManager()` covers all six pinned functions for the selected account;
`whm()->logs()->listAccountsRetention()` is WHM account-retention inventory.
Five reads use GET and two mutations use form POST. LogManager requires cPanel
82+, with deleteArchive() at 136+ and the error-log methods at 138+; the WHM
retention inventory requires 136+. Reviewer 5721279104 inspected the server's
`Cpanel/API/LogManager.pm` on `11.138.0.19`: shared `%API` requirements are the
WebServer role and rawlog feature for all six UAPI operations. The pinned snapshots
declare neither requirement, and disabled-role/feature behavior was not tested.

The pinned settings text uses current `~/.cpanel-logs` flag values when present,
with `cpanel.config` defaults when absent. Client policy omits null arguments and
transmits explicit false as 0 and explicit zero unchanged. The reviewer stored
and read back false/zero flags; omitted flags kept their current values after
`setSettings(retentionDays: -1)`. Here -1 clears the per-user retention override,
while zero retains indefinitely. The pinned schema supplies a minimum of -1;
the reviewer observed a server bound of -1..999 and an API error for 1000.
Native optional bool/int inputs and server-side range/cross-field validation stay
unchanged, including rejection of a call without arguments. No local maximum
check is added. These observations do not establish rotation or pruning.

Integer 0|1 UAPI flags, boolean WHM using_default, optional fields and nullable
retention/error-log metadata retain their contracts. The existing four capture-bound
conversions remain scoped to WHM default_retention and UAPI get_settings
archive_logs, prune_archive and retention_days. Separately, the reviewer observed
empty direct viewErrorLog() returning `log_content: []` and `lines_read: "0"`;
nonempty direct and bridged counts were native integers. Only lines_read additionally
accepts canonical signed decimal strings within PHP integer bounds, normalizing
to int. Native integer/omitted counts and strict sibling mtime/size/flags remain.
Neither immutable log-administration capture contains viewErrorLog().

Log contents are private payload data; do not log them or explicitly accessible
PayloadMismatchException payloads. The client forwards paths/selectors unchanged,
performs no local filesystem access, and makes one request with no retry or undo.
Deletion/settings can permanently affect archives. Mutations retain unconstrained
present JSON payloads, including null, and reject absent data. All seven methods
have offline contracts, including both UAPI routes; the private-log mismatch test
uses the shared trace suite with argument capture and positive controls.
StatsManager/save_configuration is separately adopted as a direct typed JSON POST;
its successful-write gate remains open under `8x0.1.18`.

The fixed eight-GET catalog observes version, WHM retention and selected-account
settings/archive/error-log metadata. It reads no log contents and makes no writes.
The initial `e5476c1` artifact remains failed and immutable; the corrected
`dd5e626` artifact on `11.138.0.19` passes all eight checks and strict assessment
with zero discrepancies. Account/archive rows are populated; error-log lists are
empty. Separate reviewer observations at `984c5c1` cover all seven methods on a
disposable account, including reads, writes and API-error cases through both UAPI
routes and the single WHM route. The reviewer revoked its token and removed the
account/home. They establish no live verification of the current corrected head.
Unreadable/symlink error rows with null size/mtime, php_fpm_error_log and actual
rotation/pruning remain unobserved under `8x0.2.55`.

### Nova and Sitejet site builders

`uapi()->nova()` covers eleven pinned functions and `uapi()->sitejet()` twelve
nondeprecated functions: six GETs and seventeen conservative form POSTs. Missing
`x-readonly` is a source fact; choosing POST is client policy. The two deprecated
Sitejet template operations remain excluded. Pinned availability is cPanel 110+,
except Sitejet restore-point/restoration at 120+; Nova requires AI App Builder.
Only nine Nova descriptions say disabling that feature disables the operation;
`accountExists()` and `getTier()` retain the feature requirement alone.

[Reviewer 5721328890](https://github.com/hostingpal/whm-api-client/pull/69#pullrequestreview-5721328890)
on `11.138.0.19` reports all six Nova reads they called failing on both routes
because `Cpanel::API::Nova` could not load, and its file was absent. All eleven
methods are unavailable for verification there. Write failures are expected from
the missing module but were not executed; every Nova response, including overlay
repairs, remains unobserved.

`createWebsite()` preserves a native integer ID or the pinned object with optional
integer `websiteID`. The reviewer inspected `Cpanel/API/Sitejet.pm` and
`Cpanel/Sitejet/Connector.pm`: Connector returns `$ret->{websiteId}` and the handler
sets data to that bare ID. This is a source-based container correction; remote
creation and its scalar type were not observed. The bounded integer type comes
from pinned `websiteID` and inventory `websiteId`, without string, null or float
coercion. `websiteId` is the request parameter for `getPreviewUrl()` (which returns
a string) and an inventory metadata key. Contact details, tokens, signatures,
credential URLs and metadata remain sensitive. Metadata is caller-supplied JSON
text encoded once as a form field; the client does not decode it or fetch URLs.

Both publication `cleanup` arguments are optional nullable booleans: omit/null
omits the field, false sends 0 and true sends 1, deleting previous document-root
files. Nova pins default 0. Sitejet pins no default; the reviewer inspected
`get('cleanup') || 0`, making omission zero on that server version. Remote
publication was not executed. Strict PHP callers reject non-null, nonboolean cleanup before
I/O; this follows the existing API surface without stronger weak-call guarantees.

`createRestorePoint()` is destructive and is not an automatically reversible
backup. The reviewer inferred deletion from `_clean_up(document_root, exclusions)`
in server source; live deletion was not witnessed. They observed creation success
and `restoreDocumentRoot()` refusal on both routes for a never-published domain
without its `<domain>-files` tracking file. The archive remains at
`~/<domain>_<time>.gz`, requiring manual recovery. Successful restoration remains
unobserved. Calls make one request without automatic retry, polling or undo;
a confirmed write with an invalid payload still throws `PayloadMismatchException`
with its explicit payload and metadata. Do not retry site creation after that
confirmed outcome.

The reviewer separately reports `getAllUserSitejetInfo()` with and without domain
passing quota/status and empty metadata, plus `canCreateDomains()`,
`isPublishInProgress()` and `getPreviewUrl()` on both routes. Published metadata
remains unobserved. Local writes on disposable `wrvsite` returned null for
`createRestorePoint()`/`addApiToken()` and a string for `getApiToken()` after a valid
stored token; missing-token and foreign-domain cases raised API errors. They
revoked the temporary token and confirmed account/home removal. These attributed
observations establish no unreported HTTP method, route variant or private value.
`addApiToken()` malformed input exposed a generic failure/Error ID; inspected
source uses `/^[a-z0-9]{32}\z/` and logs specific Invalid token text server-side.
No local token validator or log access is added.

The historical five-GET capability capture remains failed and immutable: version
and both Sitejet probes pass, both Nova probes are API failures with payload
`not_checked`; zero schema discrepancies do not waive those failures. The catalog
and recipe are unchanged. No new capture or corrected-head live acceptance ran.
Remote `createAccount()`, `createWebsite()`, `getSsoLink()` and `startPublish()`,
successful restoration, Nova and published variants remain open under
`8x0.2.57`.


```php
$builder = $client->uapi('npr')->sitejet();
$canCreate = $builder->canCreateDomains(); // integer 0|1
$inventory = $builder->getAllUserSitejetInfo(); // Account metadata; handle privately.
$publishing = $builder->isPublishInProgress(domain: 'example.test');
// Destructive calls require a deliberate account/domain decision:
// $builder->startPublish(domain: 'example.test', cleanup: true);
```

### WHM interface customization and scoped data

`whm()->customizations()`, `whm()->userData()` and `whm()->inProductSurvey()`
provide five methods. `retrieveCustomizations()` uses GET; the other four use
form POST as client policy following pinned `x-readonly=false`. Customizations
requires cPanel 100 and Jupiter; scoped data and survey functions require 86.
Application and theme remain exact supplied strings.

`retrieveCustomizations()` preserves present data as opaque `mixed`, without
inventing a branding field schema. The pinned response omits a data contract.
[Review 5721307565](https://github.com/hostingpal/whm-api-client/pull/70#pullrequestreview-5721307565)
on `11.138.0.19` reports the raw retrieval map and the old typed void result;
this is reviewer runtime evidence, with no new local call or capture. Explicit
null and other JSON variants are compatibility at the opaque boundary, not
universal observations. Absent data still raises `PayloadMismatchException`.

`deleteCustomizations(application, theme, path)` requires an explicit nonnullable
selective dot path and still returns void. Reviewer-inspected
`Whostmgr/API/1/Customizations.pm` and `Whostmgr/Customizations.pm` show that omitted
path, `''` and `'0'` select the entire default customization section. The existing
client guard rejects trimmed blanks and trimmed zero before HTTP; rejecting
whitespace variants is additional conservative policy. Valid paths are forwarded
without trimming. Missing/null arguments fail before the group callback and
retain prior metadata; runner guard failures clear it. Deliberate whole-section
deletion remains available through generic form POST. Deletion has no rollback.

Scoped data belongs to root for any WHM caller, according to reviewer-inspected
`UserData.pm`. `getScopedUserdata()` creates scope directories under
`/var/cpanel/userdata/root/scope/` when absent, so it is not a pure read. Its
pinned string-valued map remains strict; the reviewer separately observed typed
and raw reads of existing `inproductsurvey`, including digit-string `start_time`.
`setScopedUserdata(scope, key, value, json)` returns an object map with opaque
values: inspected source assigns arbitrary JSON and returns the in-memory map
after saving. This is a source-based write correction, not observed execution.
Null/missing roots, scalars and nonempty root lists remain invalid; existing
empty-container compatibility remains.

Scope, key, value and pre-serialized JSON arguments stay sensitive strings. JSON
text is form-encoded once; no JSON request body or PHP object input is added.
The client forwards exact supplied strings without new cross-field validation.
Empty key, value or json, missing write input and combining json with key/value
raise upstream API errors. Zero is forwarded without inferring server acceptance.

Survey `display` and `new_user` retain exact native/quoted 0|1 normalization.
Only `link`, `max_dismiss` and `submit_event` additionally preserve null, omission
and exact strings. Reviewer-inspected `InProductSurvey.pm` and
`Cpanel/InProductSurvey.pm` plus undefined configuration support those nulls;
no survey call was made. The function saves `start_time` and a generated `uuid`
in root's `inproductsurvey` scope, and `last_survey_link` only when a link exists.
Other fields remain strict; URLs are private payload data and are never fetched.

`update_customizations` remains excluded: required `data` is application/json
content in a query parameter, not a JSON request body. Its serialization is
tracked under `8x0.1.22`, with field-JSON design under `8x0.29`.
All five methods have offline contracts and issue one request without retries,
rollback or interface-rendering guarantees. The three mutating methods remain
unobserved under `8x0.2.58`; reviewer reads do not establish corrected-head live
acceptance or other scope/application/theme variants.

```php
// Retrieve Jupiter branding without changing configuration or fetching its URLs.
$customizations = $client->whm()->customizations()
    ->retrieveCustomizations('cpanel', 'jupiter');
```

### Generic calls

Use `call()` for functions whose parameters fit the supported GET query or
form-encoded POST representation, through the same authentication, routing,
envelope handling, metadata and exceptions. The caller must check the operation's
documented method, body format, route and outcome semantics; generic access does
not establish typed coverage or verification of that operation.

The [pinned request-body inventory](docs/api/source-inventory.json) includes 13
JSON operations (8 WHM, 5 UAPI) and 9 multipart operations (1 WHM, 8 UAPI).
StatsManager/save_configuration is the first typed JSON-body adopter; the other
12 JSON-body operations stay excluded. All nine pinned multipart operations now
have typed methods, with operation-specific source and route limits and offline
coverage; this does not establish live compatibility. Typed JSON-body machinery is available; remaining selection/source/route audit is
tracked under `whm-api-client-8x0.29.6`.
Raw `callJson()` provides JSON transport without a typed contract or endpoint
compatibility claim; `call()` remains GET/form-only. Selected typed
operation support is tracked under `whm-api-client-8x0.1.18` (JSON). Fileman is
the first typed binary multipart adopter. WHM configuration upload is also typed;
the four Backup binary methods are typed, with server-path modes deferred under `8x0.30.9`. Complete execution-contract coverage remains
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

### Raw JSON calls

Use `whm()->callJson($operation, $json, $outcome = OutcomePolicy::Flag)`
or direct cPanel `uapi()->callJson($module, $operation, $json, $outcome = OutcomePolicy::Flag)`
for an already serialized JSON document. Both always POST with
`Content-Type: application/json`. Authentication, base URI and account come from the handle;
WHM-bridged UAPI rejects JSON locally after clearing metadata, before I/O.

```php
// Choose an operation documented to accept JSON and a standard API envelope.
$result = $client->whm()->callJson($operation, $json);
// $directClient has cPanel credentials for its selected account.
$result = $directClient->uapi()->callJson($module, $operation, $json);
```

The body must be a JSON object with strict UTF-8 and native
`json_validate(..., 512)` depth limits (at most 511 nested containers). Non-object,
empty, malformed, BOM-prefixed and over-depth documents fail locally. Direct UAPI
retains every caller byte. WHM inserts `"api.version":1` as the first root member,
adding a comma only for a populated object, and preserves every caller byte
otherwise: padding, key order, ordinary duplicates, escapes and numeric literals.
WHM sends no version in the URL. No endpoint request/response schema is applied.
Callers must choose compatible operation, route and envelope contracts; raw syntax
acceptance does not establish typed coverage or live support. WHM execution-wrapper
names do not establish account-bound UAPI JSON compatibility.

There is no separate query argument: cPanel 11.138.0.19 reads JSON arguments only
from the body. Its parser removes root members beginning with `file-`. Flat direct
UAPI arguments generally work; nested data requires the function's `requires_json`
declaration. Flat bridge forms already suffice and the bridge rejects nested data,
so this client deliberately refuses bridged JSON locally. The entrypoints reject
reserved routing names by presence in decoded root JSON members,
even when the value is null or the member name is escaped. Both routes reserve
the entire case-sensitive `api.` prefix, including bare `api.` and future controls;
direct UAPI also reserves exactly `cpanel.user`, `cpanel.module` and
`cpanel.function`. Nested member names remain endpoint data. Legacy `call()`
filtering is unchanged. `CallResult`, `OutcomePolicy`, metadata snapshots and the
standard transport/HTTP/envelope/API exceptions work as above; failures never retry
or change body encoding. Library value-bearing trace frames are sensitive; a
third-party HTTP client's own frames can still contain the authenticated request.

### WHM configuration upload

`whm()->server()->restoreConfigFromUpload('Main', $upload)` accepts a required
case-sensitive module string and a required `Upload` descriptor. Documented module
choices are `Basic` (Basic WebHost Manager Setup) and `Main` (Tweak Settings).
Available from cPanel 74, it sends literal module text followed by the binary
`file` part in one streaming multipart POST to WHM, with `api.version=1` only in
the URL. Both values are sensitive. Paths, strings and lists are not accepted in
place of `Upload`; use the explicit factories described below. Module choices
are PHPDoc guidance under the existing scalar policy, without a new enum checker.

This restores server-wide configuration. Reviewer source inspection on
`11.138.0.19` found that invalid settings keep their old values and their reject
details are discarded by the restore handler. Success does not mean every setting
was applied; re-read the configuration of interest after the call. Unchanged
backups do not rewrite the configuration file, but may surface as an error because
the handler sets success metadata only after modifications. The compiled wrapper's
no-change response remains unverified under `8x0.30.7.1`.

The same source inspection indicates the server may retain a root-only backup in
`/root/tmp/Cpanel_Form_file.upload.*`. Restore-specific retention is unverified;
a separate read-only WHM file-part check observed a mode-0600 copy. Account for
possible retention when uploading configuration secrets. The client adds no retry,
rollback, transactional or restart guarantee. Its `void` result follows the normal WHM outcome and `responseMeta()`;
it does not report a changed-files count. The source's multipart schema requires
both fields even though `requestBody.required` is absent. The empty JSON HTTP
sample and port 2083 do not override multipart or normal WHM routing.

This implementation adds exactly one canonical WHM operation: total coverage is
1,149 operations (554 UAPI, 595 WHM), 99 groups and 201 generated outputs. Tests
verify request bytes, routing, stream ownership, metadata and failures offline.
Four Backup uploads are now typed; server-path modes remain deferred.

### Raw multipart calls

Use `whm()->callMultipart($operation, $parts, $query = [], $outcome = OutcomePolicy::Flag)`
or direct cPanel `uapi()->callMultipart($module, $operation, $parts, $query = [], $outcome = OutcomePolicy::Flag)`.
Both always POST through the handle's configured host, authentication and account.
Generic UAPI multipart requires direct cPanel credentials; bridged handles reject
locally after clearing metadata, before source I/O. Existing typed SSL multipart
string calls retain their reviewed bridge support.

```php
use HostingPal\WhmApiClient\Upload;

// Choose an operation documented to accept these parts and a standard envelope.
$parts = [
    ['name' => 'label', 'value' => 'literal text &+%'],
    ['name' => 'attachment', 'value' => Upload::fromPath($path, filename: 'report.bin')],
    ['name' => 'label', 'value' => 'a repeated text field'],
    ['name' => 'attachment', 'value' => Upload::fromString('empty.bin', '')],
];
$result = $directClient->uapi()->callMultipart($module, $operation, $parts);
// For a compatible WHM operation: $client->whm()->callMultipart($operation, $parts).
```

Parts are a sequential list of exact `name`/`value` records. Names are nonempty
literal `[A-Za-z0-9_.-]+` strings; values are strings or `Upload` descriptors.
Strings always mean text, including path-looking strings. Repeated names and
text/file interleaving retain their order; no endpoint numbering is inferred.
Raw bytes, NULs, empty files and empty text are preserved without form encoding.
An empty part list sends only a closing delimiter. The library chooses the
boundary and sends an exact `Content-Length`; no chunked fallback is supplied.

Query uses the existing scalar/list-marker representation plus multipart-only
null omission. Names remain in the URL and are encoded once. Keep secrets in
parts because servers may log the query. Both routes reserve the case-sensitive
`api.` prefix, including bare `api.` and arbitrary future names; direct UAPI also
reserves exactly `cpanel.user`, `cpanel.module` and `cpanel.function`. Raw reserved
query keys reject even when null. Expanded query fields and parts also reject
reserved names. An emitted query field cannot share a part name; a nonreserved
null query value emits nothing and creates no collision. Token-valid `API.version`,
`apiVersion` and bare `api` remain literal data. Validation clears this handle's
metadata first and checks the complete declaration before any source open/read.

`Upload::fromPath()` defers opening; relative paths anchor at descriptor creation.
Initial path support is POSIX-style local regular files. Sizes rely on filesystem
metadata; virtual files can report sizes that do not describe their readable bytes.
Use stable ordinary files or supply explicit bytes/streams. `fromString()` retains
exact bytes. `Upload::fromStream($filename, $stream, size: $remainingBytes)` borrows a
PSR stream at its call-time cursor. Omit size only when `getSize() - tell()` gives
a known valid remainder; an explicit size must agree with a derivable remainder.
Missing, nonregular or unreadable sources and unknown, invalid or overflowing
lengths reject before reads/HTTP with a static, unchained `InvalidArgumentException`;
this attempt clears prior response metadata. Earlier opened owned files close,
and borrowed cursors remain unchanged. An assertion covers the entire remainder; the library does not probe beyond it to detect excess.
Do not advance or mutate sources during a call or alias one underlying resource
through multiple wrappers. The same borrowed stream object cannot appear twice.
Paths/strings can be reused; later borrowed calls use the then-current position.

Construction and incremental reads are bounded; an injected HTTP client may
buffer, and explicit PSR `getContents()`/string conversion can allocate the body.
Owned files close on success, partial consumption and failure. Borrowed streams
are never closed, detached or rewound. A read error or detected boundary collision
can follow partial delivery. Recorded body failures become a static, unchained
`ApiException` at the public API boundary, including when the HTTP client swallows
or wraps the stream error. They are not classified as an original PSR client
exception; healthy-body PSR classifications and unrelated vendor exceptions keep
their existing behavior. One call makes one attempt: no retry, rollback or
remote file ownership/cleanup is inferred from any outcome.

Standard `CallResult`, envelope errors and metadata handling apply without endpoint
schema validation. The default `Flag` policy raises `ApiErrorException` for an
all-failed status `0` without exposing its data/per-file reasons; metadata excludes
that payload too. Explicit `OutcomePolicy::Unchecked` retains raw data for caller
inspection while preserving HTTP and unusable-envelope errors. Library value-bearing
trace frames and local error messages omit query/part data; caller/vendor request
logging remains outside that boundary. Generic multipart adds no typed-operation
coverage or live endpoint acceptance guarantee. Typed Fileman adoption is described
above; its owned client capture remains separate under `8x0.30.5`.

### WHM API tokens, sessions and introspection

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

### WHM web-server configuration

`whm()->webServer()` exposes 28 of 30 pinned functions: eighteen GET reads and
ten form POST operations for EasyApache profiles, PHP/FPM configuration, INI
content/directives and PHP versions. The two typed FPM JSON-body operations remain
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
introduce JSON bodies or automatic PHP-array serialization. The native pool-parameter
argument is `?string`, with explicit null omitting the field. The scalar input
correction under `8x0.1.23` is complete for this parameter.

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

### WHM account transfers

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
The original three-GET metadata capture and separate two-GET reviewed module
schema capture are complete and immutable; both strictly pass. All eight
POST methods and populated sessions/logs/analysis remain unobserved under
`8x0.2.47`. See the [source audit](docs/api/source-audit.md#whm-account-transfers)
for evidence boundaries and input exclusion `8x0.1.22`.

### WHM system package management

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

The separately requested review capture is now complete at `dd72691` on
`11.138.0.19`: exactly five WHM GETs (version, `ea-apache24` information,
`target_settings`, `rpm_groups`, and action resolution for that package in
namespace `ea`). All HTTP/API/payload/probe and strict checks pass, with zero
discrepancies. It observes mixed string/nested-string-list requirements, string
conflicts and vendor, a string installed version, nested RPM maps with string
leaves and with string-list leaves, and populated unaffected/uninstall plan
lists. The plan was returned only; no package action was submitted. Dynamic
names and all payload values are absent from the artifact. The nested RPM map
observations now support the previously inferred section-map compatibility.
This capture does not establish empty installed-version values, literal vendor
values, zero dependencies or any POST behavior. The separate reviewer report
retains the empty-string observation and source placeholder attribution.

The new artifact is `2026-10-31-whm-system-packages-reviewed.json`, SHA-256
`44b3540940eeda781608957a57d12e5b9b5bdf10343c84f4f5a272e071d156ed`.
It does not rerun either historical inventory probe or alter their findings.
No mutation, cache repair, build-log read or URL fetch ran. All seven POSTs and
remaining compatibility stay under `8x0.2.46`; no repeat is needed for integration.

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

### Named-map WHM inputs

Seven additional source-backed WHM operations add feature-list create/update,
Greylisting provider configuration, AutoSSL provider reset/selection, addon
conversion initiation and bulk account modification. Maps preserve literal keys,
strict scalar values and a single form-encoding pass. Fixed/routing collisions,
integer PHP keys and invalid late values reject before dispatch and clear stale
metadata. Every collection argument is trace-redacted independently of secrecy.

```php
$client->whm()->hostingPlans()->updateFeaturelist('custom', ['email' => true, 'ftp' => false]);
$client->whm()->mail()->cpgreylistSaveCommonMailProvidersConfig(
    autotrustNewCommonMailProviders: false,
    providers: ['google' => false],
);
$client->whm()->ssl()->setAutoSslProvider('cPanel', parameters: []);
$result = $client->whm()->accounts()->massModifyAccount(user: ['alice', 'bob']);
// Inspect each returned per-user result and responseMeta(), including outer result 0.
```

Logical provider keys omit `x_`; supplied keys already beginning `x_` reject
before I/O without stripping or rewriting. This policy is specific to the two
AutoSSL provider maps. Copy-database keys omit client-added `copymysqldb-`, but
literal database names sharing that prefix remain data. Purely numeric names
reject because PHP coerces their keys to integers.

Feature maps are required with no [] default. Empty update rejects; omitted
update keys preserved state in the reviewer's 11.138.0.19 observation. Empty
create dispatches the fixed request and produced an all-disabled list in that
fixture. Inspect `invalid_features`; no universal atomicity is promised. Other
maps are optional/nullable and empty maps still dispatch. Bulk modification
retains 50 source fields, excluding ineffective MXCHECK-* input, and uses
Unchecked to return strict partial/all-failed rows with native integer 0/1 metadata.

[The PR77 reviewer](https://github.com/hostingpal/whm-api-client/pull/77#pullrequestreview-5723287220)
observed feature-list and bulk-account behavior on 11.138.0.19. Greylisting,
AutoSSL writes and conversion were not run. These attributed observations are
separate from immutable captures and do not establish corrected-head live
acceptance. The client adds no retries, polling, rollback or implicit consent.

### Backup binary restore uploads (2026-10-31)

`uapi()->backup()` generates `restoreDatabases(Upload|array $files, ?int $timeout = null,
?bool $verbose = null)`, `restoreEmailFilters(Upload|array $backups, ?int $timeout = null,
?bool $verbose = null)`, `restoreEmailForwarders(Upload|array $backups, ?int $timeout = null,
?bool $verbose = null)` and `restoreFiles(Upload|array $backups, ?string $directory = null,
?int $timeout = null, ?bool $verbose = null)`. Arrays are precisely non-empty-list<Upload>.
Every public value is sensitive. Scalars emit bare `file` for databases or `backup`
for the other methods; lists emit `file-1`/`backup-1`, then `-2`, even for a singleton.
Wire order and duplicate filenames survive one streaming POST; no path-string inference,
sparse renumbering or Fileman-specific filename filter applies.

These new binary methods initially require direct cPanel UAPI by client policy
pending route evidence, not an upstream bridge prohibition. Server-path query
`backup` alternatives are separately bound, explicitly omitted and deferred under
`8x0.30.9`; no mixed mode is accepted. Optional query values are encoded once;
null omits, false/zero survive. Database restore requires cPanel 84 and the MySQL
Client role; filters/forwarders require cPanel 86 and Receive Mail; file restore
requires cPanel 86 and File Storage. Omission retains timeout 7200 seconds for the
first three, 172800 for files; 0 disables timeout. The documented minimum 0 adds
no new runtime minimum validation. Files' omitted directory defaults to user home;
supplied bytes are forwarded without an inferred relative-path base. Verbose
selects extra information from each source's module-specific restore logs.

Each returns `array{log_id?:string,log_path?:string}` under
ordinary Flag outcome handling. An actual empty object is valid; missing, null,
list or malformed data raises PayloadMismatchException, retaining metadata and
outcomeConfirmed=true after status success. No message unwrapping, log fetching,
completion, atomicity or per-file-success inference is added. Restoration changes
server state with no rollback and no retry after API, payload or transport failure.
No live restore ran during this implementation or integration; offline checks establish request/envelope/lifecycle behavior.

[PR89 review5724752679](https://github.com/hostingpal/whm-api-client/pull/89#pullrequestreview-5724752679)
reports 19 successful direct-UAPI restores on cPanel 11.138.0.19 with log_id/log_path
in data and messages in the envelope. Read verbose/restore text through
`responseMeta()->uapi['messages']`. Flag raises API errors before validating data,
so unvalidated log_id/log_path are unavailable on failure; `errors()` retains text.
The reviewer also found overlapping-archive results disagreed with list order in
three of five permutations. Upstream application order is unreliable and overlapping
archives have unpredictable results; restore one archive per call when a specific
result is needed. This adds no completion, atomicity or rollback guarantee.

Exactly four canonical UAPI operations are added: 1,149 total (554 UAPI, 595 WHM),
99 groups and 201 generated outputs (200 PHP files and coverage). The new narrow
`multipart_upload_family` descriptor `{php,source,routes:direct}` is mutually
exclusive with Fileman's `multipart_uploads`, WHM's `multipart_upload` and JSON.
Source-bound lowering preserves absent body/schema requiredness and uses prose's
required upload alternative. Streaming, source preparation, transport, old generated
operations and SSL bridge behavior remain unchanged. Approved parent source/preparation and Fileman/WHM corrections are integrated.
Server-path modes (.30.9), owned Fileman evidence (.30.5) and native Windows
verification (.30.10) remain separate open work.


### UAPI ImageManager (2026-10-31)

`uapi()->imageManager()` exposes all four pinned functions, available from
cPanel 82 with the WebServer role. `getDimensions(imageFile)` uses GET and
returns optional integer pixel `height` and `width` fields.
`convertFile(imageFile, type)`, `createThumbnails(dir, heightPercentage,
widthPercentage)` and `resizeImage(height, imageFile, width, saveOriginalAs:
null)` use form POST. Paths are account-server paths sent unchanged and encoded
once; the client does not access local files or normalize paths. The pinned
conversion prose describes account `/home` relative paths; reviewer cPanel
11.138.0.19 calls also accepted absolute paths under that home. This is no
universal confinement guarantee. The reviewed conversion handler
character-checks `type` only: `nope` succeeded and wrote a `.nope` extension; no
format enum is added. Positive dimensions and thumbnail percentages 1 through 99
are documented requirements; integer forwarding adds no new range validation or
promise that zero/negative values work upstream.

```php
$images = $client->uapi('example')->imageManager();
$dimensions = $images->getDimensions(imageFile: 'images/photo.jpg');
$converted = $images->convertFile(imageFile: 'images/photo.jpg', type: 'png');
$thumbnails = $images->createThumbnails(dir: 'images', heightPercentage: 25, widthPercentage: 25);
$resizedPath = $images->resizeImage(height: 300, imageFile: 'images/photo.jpg', width: 200,
    saveOriginalAs: 'images/original.jpg');
```

Conversion returns optional `converted_file`; resize returns the absolute
resized image path as a string. Thumbnail rows retain optional integer `failed`
and optional string `file`, `reason` and `thumbnail_file` fields, including
partial failures under API status 1. The source documents `failed=1` only on
failure; the existing integer contract does not enforce that singleton enum.
Thumbnails go in a `thumbnails` subdirectory with `tn_` filenames. Inspect every
row; success does not prove every thumbnail exists. Optional result fields
preserve omission, and empty thumbnail lists stay empty. Dimensions normalize
evidenced canonical bounded integer strings to optional integers under the
existing signed integer compatibility policy. Thumbnail status 0 returns every
validated nonempty row, including all-failed reasons. Status 0 with an empty
list or explicit null raises `ApiErrorException`; empty-directory failures can
have `errors()` empty. Missing data, wrong roots and malformed known row fields
remain payload mismatches with `outcomeConfirmed=false`. Status 1 permits an
empty list and retains strict payload validation. Other operations retain Flag
outcomes.

Omitted/null `saveOriginalAs` sends no field and saves no original copy; an
explicit empty string stays present. `saveOriginalAs` must be a NEW FILE path.
The server rejects existing paths, including directories. The pinned parameter
prose says directory, but reviewed source and both-route cPanel 11.138.0.19
calls resolve it to a file: `img/orig.png` retained the original 10x5 image
while existing directory `img` failed. The client performs no local filesystem
existence check. The client does no implicit read, backup, restore, cleanup,
collision handling, retry or rollback, and promises no atomicity. Resize has a
pinned clean rollback label, without a defined client restore protocol. No
server calls or image mutations ran for this slice. Offline tests establish
requests and payload handling; live transformations and HTTP image behavior
remain open under `8x0.2.60`.

Exactly four canonical operations are added: 1,154 total (558 UAPI, 596 WHM),
100 groups and 203 generated outputs (202 PHP files plus coverage). The guarded
root-list `rows: "$"` extension is explicit and source-bound; other Unchecked,
generic and Fileman object-field policies keep their existing behavior. Pinned
sources and historical evidence remain unchanged.

Review corrections above are attributed to [PR93 review 5724933500](https://github.com/hostingpal/whm-api-client/pull/93#pullrequestreview-5724933500)
of revision `0898b8b23929b57c233aa3065be8c460aa2f8d23`, submitted
2026-10-31T21:07:45Z. The reviewer removed the disposable account/home and revoked
temporary tokens after both runs. This correction made no server call or capture.


## License

Project-authored code and documentation are licensed under the
[MIT License](LICENSE). Copyright (c) 2026 HostingPal contributors.

The bundled cPanel documentation snapshots retain their upstream terms and are
not covered by the project's MIT license. See the
[third-party documentation notice](docs/api/README.md#licensing).

### UAPI CCS calendar delegation (2026-10-31)

`uapi()->ccs()` covers all five pinned CCS functions, available from cPanel 90.
They require the installed **Calendar and Contacts Server** cPanel plugin. CCS is
separate from the native CPDAVD module; its observations and defaults do not
establish CCS behavior. The client does not install or migrate the plugin or
provision calendars.

```php
$ccs = $client->uapi('example')->ccs();
$users = $ccs->listUsers();
$delegations = $ccs->listDelegates();
$ccs->addDelegate(delegatee: 'guest@example.test', delegator: 'owner@example.test', readonly: true);
$ccs->updateDelegate(delegatee: 'guest@example.test', delegator: 'owner@example.test', readonly: false);
$ccs->removeDelegate(delegatee: 'guest@example.test', delegator: 'owner@example.test');
```

The two reads use GET; the three mutations use form POST and return void under
ordinary UAPI status handling because their pinned responses have no data member.
Addresses retain their exact bytes and existing scalar conventions, encoded once
in the body. Optional `readonly` on add and update is a native nullable boolean:
omission/null omits the field, false sends 0 and true sends 1. Both sources declare
default 0 (full access); this is not an observed server guarantee. Omission can
select source full access, so explicitly choose the intended permission when it
matters. The client injects no default and adds no implicit read, merge, retry or
rollback. Mutations declare no rollback; API success alone does not establish
applied calendar permissions or their enforcement.

`listDelegates()` preserves null separately from an empty list. Rows have optional
nonnullable string `delegatee`/`delegator` and integer `read_only` (0|1): zero is full
access, one is read-only access, independent of UAPI status. Missing data or wrong
kinds/types raise a payload mismatch. `listUsers()` returns a dynamic
username-to-UUID map with `array<array-key, mixed>` PHPDoc. The formal additional
value schema has UUID prose and string examples but no type, so opaque JSON values
retain their types and keys retain existing PHP numeric-key behavior. Callers
inspect values; there is no inferred UUID validator or string-only correction.
Empty objects and the existing empty-array map compatibility are preserved;
null/missing data, nonempty lists and scalar roots mismatch.

Exactly five canonical UAPI operations and one group are added over `0898b8b`:
1,122 operations (534 UAPI, 588 WHM), 93 groups and 189 generated outputs (188 PHP
files plus coverage). Existing generator/runtime machinery is unchanged. No live
calls, plugin installation, calendar/contact reads or delegation mutations ran.
Installed-plugin behavior, response compatibility and permission enforcement remain
open under `whm-api-client-8x0.2.61`.

Ordered integration with approved master `7d5099b` yields 1,159 canonical
operations (563 UAPI, 596 WHM), 101 groups and 205 generated outputs (204 PHP
files plus coverage). Earlier slice counts above remain historical. The original
CCS delivery is closed under `whm-api-client-8x0.4.19`; installed-plugin runtime
verification remains open under `whm-api-client-8x0.2.61`.

[PR94 review 5724966468](https://github.com/hostingpal/whm-api-client/pull/94#pullrequestreview-5724966468)
of `259c8d6` reported plugin-absent `ApiErrorException` responses for both reads
through direct UAPI and the WHM bridge on cPanel 11.138.0.19. It saw no CCS handler
source or installed-plugin successful payload. Populated `read_only` wire types
remain unobserved: strict native integer 0|1 stays pinned, without speculative
digit-string acceptance. The opaque `listUsers()` map remains unchanged. This
integration performs no live call, plugin installation or calendar operation.


### UAPI account cache management (2026-10-31)

`uapi()->cacheBuster()` exposes both pinned browser resource version operations,
available from cPanel 11.46. `read()` sends GET and `update()` sends form POST.
Both are parameterless and return `array{cache_id?: int}`. The source describes an
eight-digit random integer (and read declares 10000000–99999999); the client
preserves strict integers without string normalization or local range checks.
Missing fields remain omitted. It does not promise global uniqueness,
monotonicity or security properties for this cache version.

The application that updates a resource must explicitly call `update()` when it
changes the resource, then append the returned ID to the resource URL query
string as the source describes. For example:

```php
$account = $client->uapi('exampleuser');
$current = $account->cacheBuster()->read();
// After the application updates its resource:
$updated = $account->cacheBuster()->update();
if (isset($updated['cache_id'])) {
    $resourceUrl = 'https://example.test/app.css?' . $updated['cache_id'];
}
```

The client does not fetch resources or modify URLs. This browser cache version
mechanism is separate from account NGINX cache management.

`uapi()->nginxCaching()` exposes all four pinned operations from cPanel 100,
requiring the installed `ea-nginx` package. `clearCache()` deletes the selected
account's cache contents; `disableCache()` and `enableCache()` change caching;
`resetCacheConfig()` resets configuration with no documented resulting defaults.
These parameterless calls use form POST; they accept no user or all-user target.

All four NGINX data schemas are exactly `{"properties":{}}`, without a type.
The existing `mixed` return contract accepts any present JSON value, including
null, scalars, lists and opaque objects recursively converted to arrays. Numeric
object keys follow existing PHP array semantics. Missing data remains a payload
mismatch under normal status handling; callers must inspect unconstrained results.
No result field is inferred from WHM NginxManager or prior WHM observations.

These mutations have no documented rollback. The client adds no implicit read,
restore, retry or rollback and installs no package. NGINX operations do not promise
browser invalidation, nor does CacheBuster promise NGINX invalidation. Offline
coverage establishes routing and payload contracts, not installed-package
compatibility, cache effects, browser refetches, observed response fields or reset
defaults. Separate live follow-up `whm-api-client-8x0.2.62` remains open; no live
call or server change ran in this slice. Delivery is `whm-api-client-8x0.4.20`.

This adds exactly six canonical UAPI operations and two groups over `259c8d6`:
1,128 operations (540 UAPI, 588 WHM), 95 groups and 193 generated outputs (192 PHP
files plus coverage). Historical slice totals above remain historical.


### PR95 ordered approved-master integration (2026-11-01)

Normal integration of original PR95 `31ed8cff3294ffcea2f8993c47da567cc0e4b2b7`
with approved master `052662324e298a36adebf9b8f8d4b80f112c0b6d` preserves
all six original CacheBuster/NginxCaching contracts, overlays, generated methods
and independent tests. Current combined coverage is 1,165 canonical operations
(569 UAPI, 596 WHM), 103 groups and 209 generated outputs (208 PHP files plus
coverage). Earlier delivery and integration totals remain historical.

[PR95 review 5724999867](https://github.com/hostingpal/whm-api-client/pull/95#pullrequestreview-5724999867)
by `seebeen` covers only original `259c8d6..31ed8cf`, with no required findings;
it is not approval or live validation of the combined revision. On cPanel
`11.138.0.19`, the reviewer reports CacheBuster read/update/read returned native
JSON integer `cache_id` through direct UAPI and the WHM bridge on disposable
account `wrvchc`. The reviewer reports revoking its temporary token and confirming
the account and home directory gone; this integration performs no new cleanup
verification or live capture.

All four NginxCaching calls returned `ApiErrorException` through both routes
because `ea-nginx` was absent. Installed-plugin successful responses and cache
effects remain unobserved. The reviewer's handler-source inspection predicts
explicit null on success because handlers do not set data; that is source
inference, not successful runtime evidence. Historical MySQL explicit-null
observations do not establish NGINX behavior. The pinned NGINX schema remains
untyped `{"properties":{}}`: preserve present `mixed` values, including null,
without narrowing to null-only; absent data still mismatches. Callers inspect
unconstrained results. No automatic cross-invalidation, reset defaults, retry,
rollback, package installation or browser refetch guarantee is added.

Delivery `whm-api-client-8x0.4.20` is closed; separate installed-plugin/runtime
follow-up `whm-api-client-8x0.2.62` remains open. This integration is offline.
Required PHP 8.3 CI, fresh combined-revision reviews and inherited acceptance
work remain separate gates; historical source/evidence and review results are
preserved without replay or new runtime claims.


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


### UAPI Mailboxes (2026-10-31)

`uapi()->mailboxes()` covers all five pinned Mailboxes functions. The two inventory
reads, `getMailboxStatusList(account)` and `hasUtf8MailboxNames(user: null)`, use
GET. `expungeMailboxMessages(account, mailbox, query)`,
`expungeMessagesForMailboxGuid(account, mailboxGuid, query)` and
`setUtf8MailboxNames(enabled)` use form POST. Inventory and expunge require
cPanel 64; UTF-8 mailbox-name settings require cPanel 78.

The expunge operations mark selected messages as deleted and declare no rollback.
Mailbox wildcard characters such as `*` cannot be escaped; upstream recommends
GUID-based expunge. Obtain names and GUIDs separately with getMailboxStatusList.
Required queries are sensitive and forwarded unchanged; the client does not parse,
narrow or escape them, verify mailbox ownership, protect arbitrary mail from
deletion, retry or roll back. Account, mailbox and GUID strings retain their exact
bytes, without email, domain, path or UUID validation, trimming or name conversion.

Mailbox-name expunge preserves any present JSON data as mixed, including null;
missing data fails validation. GUID expunge preserves opaque object fields and
existing empty-array object compatibility, while null and missing data fail. No
message counts or per-message outcomes are invented. Status-list rows have optional
string guid/mailbox and integer messages/vsize; vsize is bytes, zero remains zero
and an empty list remains valid. Source minimum 0 is documentation under the
existing runtime, with no new range validation or numeric-string coercion.

Omitted or null user retains the current-user default; an explicit empty string
is supplied. hasUtf8MailboxNames returns an object with optional integer enabled
(0|1), rather than an unwrapped boolean. setUtf8MailboxNames requires a native bool
and sends 0/1. It changes Roundcube mailbox-name encoding for the selected cPanel
account's email accounts, not message-content charset. Inspect optional integer
success (0|1) independently of API status; a successful envelope does not prove
mail-client behavior. Known optional fields remain distinct from explicit null.

This generated slice adds five canonical UAPI operations and one group over
`3872d384a2e4f83a348758085808452230459d8c`: 1,150 operations (562 UAPI, 588 WHM),
97 groups and 197 generated outputs (196 PHP files plus coverage). Prior contracts,
pinned sources and the map checksum are preserved; no runtime or generator change
is needed. Delivery is `whm-api-client-8x0.4.23`; separate live follow-up
`whm-api-client-8x0.2.65` remains OPEN. Synthetic offline fixtures establish no live
expunge, encoding or mail-client compatibility. No live API call, mailbox creation,
mail delivery or settings change ran; later live work requires separately owned
fixtures and cleanup. Required PHP 8.3 CI and the 55 inherited map diagnostics
remain visible, external-owned and unwaived.


### UAPI Mailboxes compatibility correction (2026-11-01)

The dated 2026-10-31 Mailboxes section above records the original offline
implementation and its then-effective contract. This correction integrates
approved master `3b81970c2d13c6f8f336a592eb12a9e4510a1d49` with original
`9e47d959cbf562bf91bd1b32944360297acfbcaf`: 1,187 canonical operations
(591 UAPI, 596 WHM), 105 groups and 213 generated outputs (212 PHP plus coverage).
Original evidence records, including their corrected provenance notes, remain
historical; the pinned source has not changed.

[Review 5725223473](https://github.com/hostingpal/whm-api-client/pull/98#pullrequestreview-5725223473)
of original `3872d38..9e47d95` reports cPanel `11.138.0.19` results through direct
UAPI and the WHM bridge. The effective GUID expunge result now preserves explicit
null alongside opaque object fields and inherited empty-array compatibility;
missing data, scalars and nonempty lists still fail. The formal pin declares a
nonnullable object, so this is an evidenced correction rather than a source
refresh. Name-based expunge still accepts any present JSON value, including null,
and rejects missing data.

Only status-list `messages` and `vsize` additionally normalize canonical decimal
integer strings losslessly within the platform integer range. Native integers,
zero, omitted fields and empty lists retain their meaning; `vsize` remains bytes.
Malformed spellings, overflow, booleans, native floats and null reject. Existing
negative-value behavior and the descriptive minimum/range policy are unchanged.
Unknown row fields continue to be filtered; GUID/mailbox strings and native UTF-8
0|1 flags remain strict. `hasUtf8MailboxNames(user)` takes a cPanel username, not a
mailbox address; omitted/null user selects the current user and an explicit empty
string is forwarded unchanged.

Both expunge methods can report success while deleting nothing: the reviewer
observed a nonmatching query and a nonexistent GUID succeed. Separately verify
the intended inventory with `getMailboxStatusList(account)`. Required sensitive
queries and exact name/GUID bytes are still forwarded unchanged; wildcard escaping
limits, no deletion count, no ownership protection, one request, no retry and no
rollback remain. No automatic verification, concurrency, atomicity or recovery
guarantee follows.

The reviewer reports seeded-message counts of 4 to 3 for name expunge and 3 to 2
for GUID expunge, with the latter throwing on explicit null at the original head.
Quoted status integers, strict native UTF-8 flags/settings readback, invalid-query
and unknown-account API errors, account/home removal and token revocation are
reviewer-attributed observations, not checks by this integration. This integration
made no live request, mailbox/message/settings change, source fetch or new capture.
Synthetic local responses verify the bounded contract only. Runtime follow-up
`whm-api-client-8x0.2.65`, the 55 inherited diagnostics, platform/stress/metadata/
release/full-parity gaps and deferred consumer integration remain open and
unwaived. Historical PR95 provenance finding I1 / `whm-api-client-qrk` remains open.


### UAPI WebApp (2026-10-31)

`uapi()->webApp()` covers all thirteen pinned WebApp functions from cPanel 138.
`fetchLogs()`, `getAvailable()`, `hasFeature()` and `listApplications()` use GET;
the nine lifecycle/configuration mutations use form POST. `stage()` and
`redeploy()` select the documented POST variant, preserving optional sensitive
`sshKeyPass` as body-only `sshkeypass` on direct and WHM bridge routes.
`configureStructured()` is a companion of `configure()`, with the same operation,
route, outcome and response; it adds no operation to the coverage count.

```php
$app = $client->uapi('exampleuser')->webApp();
$current = $app->listApplications(); // Read the current environment separately.
// Prepare the COMPLETE intended environment; this replaces every old variable.
$configured = $app->configureStructured('my-app', env: [
    'NODE_ENV' => 'production',
    'FEATURE_ENABLED' => true,
]);
```

Only supplied configure options change, but `env` and `envText` both replace ALL
variables. Read the current set and submit the complete intended variables;
neither method merges or reads implicitly. The server rejects both inputs
together; the client forwards both if supplied. Structured `env: []`, raw `'{}'`
and text yielding no variables clear the environment. Optional null omits the
input. Nonempty settings require `.env` to be untracked and ignored; clearing is
allowed despite `env_not_editable`. Server limits are 16 KiB JSON, 64 variables,
names matching `[A-Za-z_][A-Za-z0-9_]*` up to 128 characters, values up to 4096
characters without control characters, and reserved `PORT`. These remain server
restrictions rather than new client validation. Text splits at the first `=`,
later duplicate names win, and quotes/spaces after it remain literal. Leading
whitespace is trimmed upstream; comments/blank lines are skipped and lines
without `=` are rejected. Raw JSON and text bytes are otherwise forwarded once.

Structured environment input accepts string keys with string/int/finite-float/
bool/null values. It sends JSON inside one form field, through the existing
serializer; it is not a whole JSON request body. Empty input serializes as `{}`;
null values remain JSON null in client encoding. The attributed server conversion
is described below; no universal conversion or persistence guarantee is made. Integer keys, nested containers, objects, resources, invalid UTF-8
and nonfinite floats fail locally before I/O, clearing metadata without exposing
values in exceptions. The raw string method stays available without auto-parsing.

Stage registers previously uploaded ZIP/tar paths relative to the account home,
or Git source URLs; it does not deploy, upload, read or fetch the source. Use
trusted sources because the server builds and executes them. Never embed Git
credentials: upstream stores that URL in plaintext. Prefer the selected account
SSH key for `git@` URLs. A staged-name collision overwrites the staged source;
a deployed-name collision assigns the next free name-N. Use the returned name
and domain for later calls. Protected-key passphrases stay body-only; omission
stays omitted. The source accepts modern encrypted OpenSSH keys and rejects
legacy encrypted PEM keys. The client never prompts for or stores passphrases.

Changing appdir reruns preflight/defaults; empty resets to the source root and
explicit options take precedence. Empty commands clear overrides; nonstatic apps
without both commands are undeployable. Empty domain regenerates the default.
`useExistingDomain: true` confirms takeover and hides the existing website;
omission does not confirm and false cannot revoke prior confirmation. Changing
domain releases the prior include; another application's domain stays rejected.

Deploy/redeploy return asynchronous IDs and an SSE URL as payload data. Envelope
success establishes neither completion nor health. Upstream documents in-flight
idempotence; the client never retries or polls. A protected-key redeploy pull runs
synchronously before queueing per source, so latency may increase; upstream says
its passphrase never persists in task arguments, without live verification here.
Inspect `listApplications()` last_deploy separately from immediate
`responseMeta()` metadata.error_category. Only list's last_deploy gains an optional
mixed error_category, explicitly placed there by deploy/redeploy prose although
omitted in its formal schema. No field type/enum or sibling correction is inferred.
Configure/stage do not borrow that field. Known optional and nullable fields,
0|1 state flags and dynamic string environment maps stay distinct. The three
optional state fields deployed/env_editable/staged on configure/list/stage accept
observed native integer 0|1; pinned booleans normalize to 0|1 as source compatibility.
configureStructured inherits configure; no sibling response is widened.

`delete(name, verify)` requires explicit confirmation with no default; false
sends 0 for upstream rejection. It stops/removes the container, moves the directory
to .bak and removes the registry entry; the domain is unaffected and there is no
client rollback. Start/stop/restart/setMode apply only to server-category apps
with a completed deploy. `restarted=0` can retain the mode without finishing a
restart; inspect it independently. Logs are a private, static oldest-first tail,
with deployId meaningful only for build logs. Runtime/tag map keys and empty
manager lists remain intact; runtime apps.used is required when apps is present.
`hasFeature()` retains normal UAPI failure checking and strict integer data flag.

Historical original slice: thirteen canonical operations and one group were added over
`9e47d959cbf562bf91bd1b32944360297acfbcaf`: 1,163 operations (575 UAPI, 588 WHM),
98 groups and 199 generated outputs (198 PHP files plus coverage). It adds only
an opt-in generator `json.scalar_map: true` path with source hash/prose/schema
binding, plus synchronized published/embedded overlay schema. Existing formal
content-JSON aliases and PHP runtime remain unchanged. Delivery is
`whm-api-client-8x0.4.24`; separate live `whm-api-client-8x0.2.66` remains OPEN.
The original author ran no live call, source upload/clone, build, deployment, domain
takeover, lifecycle mutation or URL fetch. This integration also performed none.
Offline fixtures establish no server execution,
persistence, passphrase storage, application health or destructive compatibility.
Required PHP 8.3 CI and the 55 inherited diagnostics remain external-owned and
unwaived; local PHP 8.4 does not replace that CI evidence.


Current integration (2026-11-01) combines the approved master with WebApp: 1,200
canonical operations (604 UAPI, 596 WHM), 106 groups and 215 generated outputs
(214 PHP plus coverage). Only nine state leaves in configure/list/stage are
corrected; deploy/redeploy have job-ID responses without these flags.

On cPanel 11.138.0.19, seebeen reported on both direct UAPI and WHM bridge at original
revision 4ab5b80587f8d524bbc0ccf5129f9901ef9ab8df that the server stores environment
values as TEXT: number 5 becomes "5", float 1.5 becomes "1.5", true becomes "1", false
becomes "0", and null becomes "". The nonempty string "0" can be truthy in application
code. This is server conversion: configureStructured still serializes the original
native JSON types and bytes in one form field without client text conversion. Its
parameter description that scalar null stays JSON null describes client encoding. Each
supplied environment replaces the full set; structured [] emits {} and clears it, while
null for the whole optional input omits the field. These are attributed version-scoped
observations, not a universal conversion or persistence guarantee.

The observations above are attributed to [seebeen review 5725334504](https://github.com/hostingpal/whm-api-client/pull/99#pullrequestreview-5725334504)
and its [correction 6550246600](https://github.com/hostingpal/whm-api-client/pull/99#issuecomment-6550246600).
They concern original `4ab5b80` on `11.138.0.19`, both routes and disposable `wrvapp`,
not the combined tree. Stage/configure succeeded before payload validation failed;
complete-set replacement and clear were reported. Cleanup/token revocation were
reported by the reviewer, not independently checked here. No deployment or running
container behavior was observed; `8x0.2.66` remains OPEN.


### UAPI WPX WordPress lifecycle (2026-10-31)

`uapi()->wpx()` covers all fourteen pinned WPX functions for the selected account,
available from cPanel 137. Every operation requires `wp-toolkit`. Ownership,
features, plan limits and other prerequisites are server checks. Seven reads use
GET and seven operations use form POST on both client routes. Six POSTs preserve
the formally required form-body fields; `getCredentials()` uses mutation POST
because its pinned description says each call mints a fresh token, despite its
formal GET variant. PR100 review 5725396130 at `a4d3145` on `11.138.0.19` reports
POST reaching the adminbin through direct UAPI and the WHM bridge, then failing
because the WP Toolkit binary was missing. Success/token payload remains
unobserved; this establishes no universal route acceptance or success guarantee.

`createWebsite(domain, title)` can queue an installation, requiring `redirects`,
`mysql`, disk headroom and plan website-count allowance. The server normalizes
the domain; the client forwards its bytes unchanged. Omitted title defaults to
the domain. A title that violates upstream length/control-character restrictions
can fail in the background after API success. A successful `collision` result
queues nothing and preserves `existing_installations`; the client never deletes
existing sites automatically. A `running` result is not completion or health.

`importWebsite(sourceUrl, sourceUser, sourcePassword, domain, overwrite,
passwordType)` requires `nova-wordpress`, `redirects`, `wp-toolkit`, `mysql`, an
HTTPS source resolving only to public addresses, and an owned destination domain
with its own document root. Source user/password are body-only. The client does
not resolve or fetch the source URL. Omitted overwrite retains the server default
0; explicit false sends 0, while true can replace an existing installation.
`passwordType` remains a supplied string with the inherited sensitive annotation.
The background import can fail after API success.

`createNovaForWordPressWebsite(domain, colorScheme, promptToken, returnUrl)` creates
a cPanel token and a Nova magic link. It neither installs WordPress nor queues an
install; a later import callback does that. Besides `wp-toolkit`, it needs
`nova-wordpress`, `mysql`, `redirects`, `apitokens` and disk headroom. The source
says it does not enforce the WordPress website-count limit and revokes the token
on Nova failure; these are documented claims, not live observations.

`promptToken` retains exact opaque caller-supplied base64 bytes. Upstream supports
standard/URL-safe forms with optional padding, at most 8192 sent characters and
5000 decoded bytes. Omission or a blank prompt may consume a session prompt;
supplied nonblank input leaves that fallback intact. The client does not decode
or re-encode. Upstream `returnUrl` and `panelReturnUrl` must be HTTPS on the same
cPanel host, without userinfo and at most 2048 characters; invalid values are
ignored for defaults. No local URL validator is invented.

`getCredentials(id, panelReturnUrl)` mints a new one-use token each time; first
login consumes it. It never returns a WordPress password. Its formally optional
login/password strings remain strings rather than closed empty literals, and an
empty login URL is meaningful. Never log or fetch returned login URLs or Nova
magic links. Password/login, import source URL/user/password, prompt and return
URL inputs are sensitive. `changeAdminPassword(id, password, login)` omits login
only for a sole administrator; otherwise choose an existing admin from
`getAdminAccount()`. The server accepts nonempty passwords without its own
strength rule. The source describes caller-private file handling without process
or Toolkit-log exposure; offline fixtures do not establish that behavior.

`getAdminAccount()` preserves null administrator ID, empty strings/list when no
administrator exists; read failure remains an API error. It returns neither the
stored password nor active language. `setLanguage(id, language)` may install
translations and uses a WordPress locale, not a cPanel locale. Unpublished locales
can fail upstream; no language readback is invented.

`getWebsite(id)` bypasses brief list eventual-consistency lag. Missing and
other-account IDs both return success with explicit null; absent data remains a
mismatch, while malformed/backend errors fail. `listWebsites()` returns only
installations under the selected account home. Its row roots stay nonnullable;
optional nullable `wptk_id`, strict native integer health flags, empty domains and
omitted domains/info retain their distinct shapes. Guard a null ID before reuse.

`getSiteActivity(id)` starts WordPress and can take 30 seconds. All-zero flags can
mean boot failure/timeout rather than absence of activity. `has_user_activity`
reflects authored content or uploaded media only; other flags are informational.
`hasReachedQuota()` includes installation headroom and may return 1 before actual
quota exhaustion; this is separate from WordPress website-count limits. Payload
zero remains a normal Flag success; envelope status 0 still fails.

`getTaskStatus(taskId)` answers queue membership only. `done` also covers removed,
never-existing and other-account tasks and does not establish success.
`getTaskProgress(installId)` reads retained progress/outcome even after completion.
SSE paths need an interactive session; API-token clients explicitly call progress.
The client never polls or fetches returned paths automatically.

`removeInstallation(id)` permanently removes files and database without a recycle
bin or retained copy. It cannot be undone and a second call fails. No automatic
retry, collision cleanup, restore or rollback occurs. All methods make one request.
Optional null arguments omit; false, zero and empty strings remain supplied and
form/query strings encode once. No local range, enum, locale, address-resolution,
URL or password-strength checks are added. Formal response fields stay optional;
strict types remain unchanged except that `getTaskProgress().percentage` accepts
existing lossless canonical bounded integer strings and returns int. No new
percentage range validator or general integer/flag coercion is introduced.

The integrated slice adds fourteen canonical operations and one group over approved
master `2356ddc6c9ef759ea090898d1bbed35cc4a81775`: 1,214 operations (618 UAPI,
596 WHM), 107 groups and 217 generated outputs (216 PHP files plus coverage). Existing
runtime/generator/dependencies, prior overlays/generated contracts, pinned sources,
historical audit and evidence remain unchanged. Delivery is tracked by `8x0.4.25`;
live follow-up `8x0.2.67` remains open. Independent offline fixtures do not establish
installation/import/login, source resolution, quota behavior, token consumption or
revocation, translation/health behavior, or deletion/cleanup on a live server.

PR100 review corrections (2026-11-01): all three creators can return a successful
envelope with `status=unsupported_php`, compatibility diagnostics, and no queued
work or installation task. Inspect status explicitly before treating a result as
running. Nova also queues no installation task on its normal magic-link path.
The eleven added fields are optional; `compatible` and `is_fallback` are strict
native integer 0|1, `required_version` is string (source constant `7.4`), and
`blocked_by` is a string list. Seven lookup strings preserve null separately from
omission: `effective_version`, `system_default_package`, `system_default_version`,
`domain_package`, `domain_version`, `domain_version_source`, `domain_version_vhost`.
Existing creator domain/status remain nonnullable strings. No new closed enum,
local PHP-version check or automatic repair is introduced. A `domain` blocker can
require changing the MultiPHP Manager row named by `domain_version_vhost`; a
`system_default` blocker means contact the provider.

These refusal fields/remedies and the additional `wp-toolkit-site-import` gate
for Nova/import plus the Nova entitlement's `ai-app-builder` extension are
source-only facts from `Cpanel/API/WPX.pm`, `Cpanel/WPX/PhpCompat.pm` and
`Cpanel/WPX/Constants.pm` on `11.138.0.19`, reviewed in 5725396130. The local
captured-output provenance is in the source-audit correction appendix. They were
not observed in an unsupported-PHP wire response. Separately, that reviewer seeded
the two documented progress-log formats (no real installation): direct GET
returned quoted 20/10/40, bridge GET integers; no-log 0 and complete 100 were
integers on both routes. This task performed only offline synthetic verification.


### UAPI DomainRecommendations (2026-11-01)

`uapi()->domainRecommendations()` covers all six pinned functions for the selected
account: `domainAvailability()`, `domainSuggestions()`, `getStoreConfig()`,
`isEnabled()`, `purchaseDomain()` and `supportedTlds()`. All require the
`domain_recommendation` feature. Availability, suggestions, store configuration and
enablement require cPanel 134; checkout and supported TLDs require cPanel 138.
Five methods use GET. `purchaseDomain(string $domain, int $period)` uses form POST
because its selected formal GET is marked `x-readonly=false`, `x-rollback=none`.
A server accepting only the documented GET can fail until route-specific evidence
resolves transport. Offline validation does not establish live POST acceptance.

Availability and checkout expect an FQDN including its TLD. Suggestions accept
free text, normally a base name without a TLD. Reviewed 11.138.0.19 source also
adds an available full-domain search on the first page. Optional `page` is one-based;
omitted `page` and `pageSize` use store defaults. Strings retain their bytes and
are encoded once; no local IDN, domain, TLD, range or enum normalization occurs.
Optional null inputs omit; false becomes 0, true becomes 1, and zero and empty
strings stay supplied. The server caches availability per domain, suggestions by
search/page/page-size/requested-TLD combination and supported TLDs for up to two
minutes. Omitted `useCache` retains server default 1; explicit false bypasses its
cache and may contact the configured store. The pinned suggestion parameters and
examples contain no TLD argument despite the cache-key prose. Reviewed handler
source reads `get_multiple('tld')` and its POD shows `tld-0=com&tld-1=net`.
That source-only finding adds no typed argument or hidden parameter.
The client adds no cache, fallback, pagination, store lookup, merge or retry.

Availability preserves explicit null without inferring a reason; missing data is
a mismatch. Its availability, premium and TLD flags are native booleans, while
suggestion `is_premium` additionally accepts native false/true and normalizes to
integer 0/1. Enablement `is_enabled` remains a strict integer 0|1 flag. Optional
`is_searched_term` is a strict native integer 0|1: 1 identifies an injected
own-search row; ordinary rows may omit it. No string, float or null flag
conversion is added. Availability flags remain strict native booleans.
Payload `is_enabled=0` can succeed under envelope status 1; account feature denial
still raises an API error. Inspect response metadata and warnings separately.
`getStoreConfig()` exposes only allowlisted `store_type`; credentials, URLs and
future fields are filtered. An empty object can mean an unconfigured store.
`WHMCS` is a string value, without a closed store-type enum.

Prices remain exact strings, including decimal precision, formatting, whitespace,
prefixes and suffixes. Null, empty price objects and omitted fields stay distinct;
no currency parsing, conversion or rounding occurs. Availability's premium object
is opaque. Suggestions preserve returned dynamic domain and registration-term
keys and empty maps; metadata permits unknown fields. Every suggestion row
requires `pricing`, integer `is_premium` and nullable `premium_cost_pricing`, even
though source prose calls premium details optional. Omission remains a visible
mismatch until evidence resolves that discrepancy; no flag-dependent fields are
inferred. Availability and price at actual checkout are not guaranteed.

Reviewed 11.138.0.19 source refuses an unavailable domain, creates a real upstream
cart, adds a domain item and requests checkout. It emits backend
`Domain-Purchase-Initiated` analytics with the domain by default, before the cart
call; analytics may occur despite later validation or checkout failure. Do not
assume atomicity or retry automatically. No `skip_backend_track` input is added.
These are source-only facts, not configured-store or transaction observations.

`purchaseDomain()` returns an optional private checkout URL for caller-controlled
use. The method does not fetch it, navigate, register a domain, make payment or
establish ownership. There is no implicit retry or rollback. Treat the returned
URL and response snapshots as private payload. Implementation and offline tests
make no commercial transaction or server-store setting change. Live/store
configuration/purchase behavior and source gaps remain open under
`whm-api-client-8x0.2.68`; delivery is `whm-api-client-8x0.4.26`.

Current combined generated coverage is 1,220 operations (624 UAPI, 596 WHM),
108 groups and 219 outputs (218 PHP files plus coverage), adding these six
operations to approved master `922a095b1a852b3537107945c2ecd8a75c96eefb`.

The source review and serializer experiment support only the bounded response
compatibility above; actual configured-store success remains unobserved. The
reviewer never called `purchaseDomain()`: its permission check refused the call,
and no alternate route or retry was attempted. POST/store/cart/analytics runtime
behavior remains open under `8x0.2.68`.

### UAPI Webmail credentials and session inventory (2026-11-01)

Five operations span three generated groups. `session()` implements three of the
four pinned Session functions: `createWebmailSessionForMailUser(domain, login,
locale: null, remoteAddress: null)`,
`createWebmailSessionForMailUserCheckPassword(domain, login, password,
locale: null, remoteAddress: null)` and
`createWebmailSessionForSelf(locale: null, remoteAddress: null)`. All require
cPanel 86 and send form POST on direct UAPI and WHM bridge: each selected formal
GET declares `x-readonly=false` and `x-rollback=none`. Reviewer evidence below
establishes form POST acceptance only through original `5b005fb` on the reviewed build. `Session/create_temp_user` requires an
authenticated session URL; it remains visible as unsupported under `8x0.1.33`.
This is Session 3/4 plus complete ActiveSessions and WebmailApps coverage.

The mail-user creators require the calling cPanel account to own the Webmail
account. The password-checking method also requires the correct password; wrong
passwords and suspended accounts fail. The method without password verification
is a distinct authorized-owner operation. The client never falls back to it after
a password failure. In the review's suspended-mailbox case on `11.138.0.19`, the
non-password mail-user method still issued credentials on both routes while
check-password refused. Returned-credential login/access was not tested; no general
availability, bypass or suspension-enforcement guarantee follows. Self targets the authenticated cPanel user, not an arbitrary
mailbox. Login, password and remoteAddress are sensitive method parameters.

Lowercase locale is a server requirement; omitted locale uses the account locale.
The pinned source describes remote_address as defaulting to the API caller IP and
required for command-line use; this does not establish bridge inference. On cPanel
`11.138.0.19`, reviewer seebeen observed self and non-password mail-user omission
succeeding direct and failing with an API error on the WHM bridge. Supply an explicit
`remoteAddress` for the bridge on that reviewed build. Password-variant omission
is source-derived from the reviewer-inspected shared `_create_session()` and its
`REMOTE_ADDR` fallback, not runtime-observed by the reviewer. These scoped findings
are not a universal route/authentication prohibition. Optional null still omits; empty strings stay supplied. Native strings retain case, punctuation, IP
formatting and exact once encoding. No local locale, hostname, IP, password or
name normalization or added enum/range validation occurs.

Each creator returns an object with optional `hostname: string|null`,
`session: string` and `token: string`. Omitted hostname stays omitted, null means
the server that answered the API, and empty strings and opaque credential bytes
stay exact. The source recipe requires a separate caller-controlled POST to the
Webmail host on port 2096 at `<token>/login`, with `session=<session>` in its body.
The library does not construct, fetch or log in to that destination. Treat
hostname/token/session combinations and returned Webmail URLs as private data.
Creating credentials establishes neither login, session activity nor mail access.
No lookup, login, redirect, retry, polling, cleanup or rollback is implicit.

`activeSessions()->listActiveSessions()` requires cPanel 138 and sends one GET.
It returns the current session first, then the most recently active, preserving
server order. Caller generic sorting can replace source order, but the typed
no-parameter method adds neither generic controls nor client sorting. Empty
results can mean no unexpired sessions or an unreadable session store. Session
addresses are private; demo/shared accounts cannot call this function.
`ip_address` may be empty. Optional strings, native integer `last_active` Unix
timestamps and integer 0|1 `is_current`, `is_possessed` and `tfa_verified` flags
retain their types. Zero flags under API status 1 are successful data; API status
0 still fails. Possessed means reseller/root acting on behalf of the user;
two-factor verification describes that returned session only. No current-token
authentication, session-revocation capability or identifier from IP/time is inferred.

`webmailApps()->listWebmailApps(theme: null)` requires cPanel 11.48 and sends one
GET. The Receive Mail role disables this function when disabled; this caveat is
not assigned to the other operations. Theme omission keeps the server default.
Returned rows have optional `displayname`, `icon`, `id` and `url` strings without
a closed client enum. Icon paths are documented relative to
`/usr/local/cpanel/base/frontend`; URLs may contain session tokens. Both retain
their bytes, with no path normalization or URL verification/fetching. Listing an
app establishes neither browser reachability nor successful login.

Inventory roots are nonnullable lists of objects; creator roots are nonnullable
objects. Missing data, null roots, wrong nonempty containers and invalid known
field types fail, with unknown siblings filtered by existing shape rules. The existing ShapeValidator accepts empty JSON arrays when no fields are
required; this slice preserves that compatibility behavior (8x0.2.50).
Cached accessors perform no I/O. Every method makes exactly one API request.

Current combined generated coverage is 1,225 operations (629 UAPI, 596 WHM),
111 groups and 225 outputs (224 PHP files plus coverage), adding five operations
and three groups to approved master `81c6a051700b77c10039bd28b57f6ab8288f4c21`.
The original slice added five operations to `99f3c83b99eaad926c6c9ac308e771c47c641430`.
Delivery is `whm-api-client-8x0.4.27`; live follow-up `8x0.2.69` remains OPEN.
Original implementation used synthetic offline fixtures. The later
[seebeen review5725475353](https://github.com/hostingpal/whm-api-client/pull/102#pullrequestreview-5725475353)
was submitted 2026-10-31T23:39:57Z against original `5b005fb` on `11.138.0.19`:
all three credential creators passed as form POST with an address on both routes,
and populated inventory responses matched their specs. This is reviewer evidence,
not root verification or integrated-HEAD wire coverage. No returned credentials
were used for login/access; non-null hostname and create_temp_user remain unobserved.
The reviewer reports four lingering session files manually removed after account
removal, not an account-deletion cleanup guarantee. Root's four GETs observed only
empty ActiveSessions and populated WebmailApps. This integration performs no live
call or server write and does not replay the completed owner 165-call pass.
