# WHM API Client

`hostingpal/whm-api-client` is a framework-independent PHP library intended to
cover the WHM API and cPanel UAPI.

The 99 generated typed groups cover 1,135 documented operations (see
[`docs/api/coverage.md`](docs/api/coverage.md)): WHM server identity, load, disk
usage, domain inventory, PHP configuration, feature inventory, DNS zone and DNSSEC
management, and the cPanel account lifecycle, plus cPanel domains, DNS, PHP and
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
| `uapi()->email()` | All 96 pinned functions, including mailbox lifecycle, quotas and suspension, forwarders, filters, MX routing, autoresponders, spam settings and delivery diagnostics; see [generated methods](src/Api/Uapi/Email.php). |
| `uapi()->ssl()` | All 49 nondeprecated functions, including certificate/key/CSR inventory and lifecycle, installation, AutoSSL, SNI and redirects; see [generated methods](src/Api/Uapi/Ssl.php). |
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

### WHM login and mail protection

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

### WHM authentication

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

### WHM SSL certificate management

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

### WHM DNS management

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

### Expanded WHM account management

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

### Expanded WHM hosting plans

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
Existing StatsManager coverage and its deferred JSON save operation are unchanged.

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

## License

Project-authored code and documentation are licensed under the
[MIT License](LICENSE). Copyright (c) 2026 HostingPal contributors.

The bundled cPanel documentation snapshots retain their upstream terms and are
not covered by the project's MIT license. See the
[third-party documentation notice](docs/api/README.md#licensing).
