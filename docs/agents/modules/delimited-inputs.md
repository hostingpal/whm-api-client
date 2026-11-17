# Delimited list inputs

> Agent contract notes for pipe- and comma-joined list arguments across SSL, mail, account and WHM administration methods. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### SSL and domain-security domain lists

These existing `domains` arguments accept a compatible scalar string or a
nonempty sequential string list. The generated client joins lists into one
original form field using the pinned convention:

| Group | Methods | List delimiter |
| --- | --- | --- |
| `uapi()->modSecurity()` | `disableDomains`, `enableDomains` | comma |
| `uapi()->ssl()` | `addAutosslExcludedDomains`, `fetchCertificatesForFqdns`, `generateCert`, `generateCsr`, `setAutosslExcludedDomains`, `toggleSslRedirectForDomains` | comma |
| `uapi()->ssl()` | `disableMailSni`, `enableMailSni` | pipe |
| `whm()->ssl()` | `fetchSslCertificatesForFqdns`, `generateSsl` | comma |

```php
// Synthetic inputs. Calling this method immediately requests a redirect change.
$account->ssl()->toggleSslRedirectForDomains(
    domains: ['addon.example.test', 'addon.main.example.test'],
    state: false,
);

// Certificate inventory also accepts ordinary lists.
$certificates = $client->whm()->ssl()->fetchSslCertificatesForFqdns(
    ['one.example.test', 'two.example.test'],
);
```

Lists preserve order, duplicates, empty items and exact bytes. `['']` supplies an
empty field; `['', 'a', '']` retains both delimiters. Empty, sparse, keyed and
nonstring lists, nested/object items and items containing the selected delimiter
fail locally before dispatch. The other delimiter remains data. Scalars retain
raw delimiters, literal `%2C`/`%7C` and empty strings without parsing. Supply raw
values: the whole joined field is form-encoded once. No trimming, domain
validation, sorting or deduplication occurs.

Only `setAutosslExcludedDomains()` has optional `domains = null`: omission or null
removes every exclusion and enables AutoSSL for every account domain. It replaces
the complete list; `addAutosslExcludedDomains()` is additive. `[]` is rejected,
and an empty scalar or `['']` remains a supplied field without an invented reset
or no-op meaning. Every other listed domains argument is required. Certificate
and CSR generation keep `countryName` before `domains` and the existing `keyId`;
the client never generates a prerequisite key implicitly. WHM `generateSsl()`
stores a key and CSR even with `skipCertificate: true`.

All twelve remain one form POST: UAPI uses the direct or WHM bridge route, WHM
uses its native route. Role/version restrictions remain in generated PHPDoc.
ModSecurity requires account-owned domains and the WebServer role; an API failure
can follow partial changes. Mail SNI is documented as always enabled: enabling
succeeds with a warning, disabling fails without changes, and rebuild guidance
remains caller-driven. Webmail uses the cPanel service certificate. Redirect
changes retain the documented ownership/certificate conditions and require both
an addon domain and its associated subdomain. No implicit read, merge, rebuild,
restore, retry, rollback or contact with supplied/returned domains occurs.

All twelve collection arguments have `SensitiveParameter` trace protection
without a semantic secret flag. Returned PEM/private material, response metadata
and `PayloadMismatchException::$payload` can be sensitive; third-party transport
traces remain their own responsibility. This adoption adds no operations or
response changes: coverage stays 1,234 operations, 119 groups and 241 generated
outputs. These are offline synthetic tests; historical scalar captures do not
establish PHP-list live acceptance. Live follow-up `8x0.2.84` and the wider
adopter audit `8x0.28.8` remain open.


### Mail and account comma-separated inputs

Eight existing arguments accept raw strings or nonempty sequential string lists.
The client joins each list once with a comma into its original field:

| Group and method | PHP argument → wire field | Required |
| --- | --- | --- |
| `uapi()->boxTrapper()->saveConfiguration()` | `fromAddresses` → `from_addresses` | yes |
| `uapi()->email()->addForwarder()` | `forwardEmail` → `fwdemail` | no |
| `uapi()->email()->addMailmanDelegates()` | `delegates` → `delegates` | yes |
| `uapi()->mime()->addHotlink()` | `extensions` → `extensions` | yes |
| `uapi()->mysql()->setPrivilegesOnDatabase()` | `privileges` → `privileges` | no |
| `uapi()->team()->addTeamUser()` | `roles` → `roles` | no |
| `whm()->mail()->generateMobileconfig()` | `selectedAccountServices` → `selected_account_services` | no |
| `whm()->mail()->setUserEmailForwardDestination()` | `forwardTo` → `forward_to` | yes |

```php
// Synthetic inputs: these explicit calls change forwarding and database privileges.
$account->email()->addForwarder(
    domain: 'example.test',
    email: 'alias@example.test',
    forwardOption: 'fwd',
    forwardEmail: ['first@example.test', 'second@example.test'],
);
$account->mysql()->setPrivilegesOnDatabase(
    database: 'fixture_db',
    user: 'fixture_user',
    privileges: ['SELECT', 'CREATE TEMPORARY TABLES'],
);
// Explicit profile read; retain the private returned payload without decoding it.
$profile = $client->whm()->mail()->generateMobileconfig(
    account: 'fixture-account',
    useSsl: true,
    selectedAccountServices: ['email', 'caldav'],
);
```

Lists retain order, duplicates, empty items, whitespace, Unicode, binary, pipes
and URL/percent bytes. `['']` supplies an empty field and `['', 'a', '']` supplies
`,a,`. Empty, sparse, keyed, nested and nonstring lists and items containing a
comma reject locally before I/O after metadata reset; captured metadata snapshots
remain intact. A quoted email address containing a comma also rejects as a list
item; no quoting or escaping grammar is added. Scalar commas, literal `%2C` and
empty strings remain unparsed. Supply raw bytes: ordinary serialization encodes
the complete field once. No trimming, splitting, sorting, deduplication, coercion,
email/domain/role/privilege enum validation or implicit defaults are added.
The four optional arguments retain `null = null` omission; `[]` never becomes
omission. The four required arguments remain required; native wrong-type errors
precede method entry and retain prior metadata. Argument names and order stay
unchanged, including `forwardEmail` and system forwarding's user-first order.

The six UAPI methods retain direct and WHM bridge form POST; native WHM system
forwarding retains form POST. Mobile profile generation retains GET, including
its supplied service field in the query. All eight collection arguments have
`SensitiveParameter` trace protection without a semantic secret flag or transport
override. This covers library argument frames; caller/third-party traces, memory,
returned profile/configuration, response metadata and mismatch payloads can still
contain private data. Inspect warnings and raw metadata; API failure still wins
over a valid-looking payload and no request is retried.

BoxTrapper (cPanel 82, Receive Mail) changes message handling. Webmail overrides
the mailbox selector, while direct/bridge calls retain it. `queueDays` minimum one
is a server constraint; omitted `spamScore` retains current configuration, and
false/zero flags, float scores and empty `fromName` remain supplied. Email
forwarding and delegates (11.42, MailReceive/Receive Mail) retain their no-rollback
caveats. `forwardEmail` remains optional in the interface although required by the
server for `fwd`; fail/system/pipe alternatives and FileStorage requirements stay
unchanged, as do `failureMessage`/`pipeForward` trace protections. Delegates gain
mailing-list administrative privileges. The client does not insert the source
example's literal `delegates=` prefix into values.

Mime (11.42, WebServer) widens only `extensions`: `urls` stays one raw
newline-separated string and `redirectUrl` stays scalar. `allowNull: false` still
sends 0 and referrer behavior remains server-controlled. MySQL (11.44) privilege
assignment replaces existing privileges; there is no read/merge. Names with spaces
and `ALL PRIVILEGES` stay open strings. No default or clear/no-op/grant meaning is
inferred for omitted/null/empty privileges. Account prefixes and the disabled
MySQL/MariaDB-without-remote-MySQL caveat remain.

Team (104, selected account) uses plural `roles`; no roles means no features, and
creation can grant owner privileges. Password or activation email is required by
the server, without a client cross-field guard. Password protection, literal dotted
service fields, expiry/default/false/zero semantics and the self-referential FTP
homedir source condition remain. Its reviewed nullable canonical integer-string
timestamp result is unchanged; no live Team support is established. WHM profile
generation (11.52, Receive Mail) omits services to retain `email,caldav,carddav`;
unsupported services remain server-handled. Its private payload stays exact without
format validation, decoding, installation or sending. WHM system forwarding (11.52)
changes system-account destinations; root notices and nobody CGI bounce-mail
caveats remain. The client contacts no destination or supplied URL and performs
no implicit mail message, read, merge, settings change, retry or rollback.

This adds no endpoints or response changes: 1,234 canonical operations, 119 groups
and 241 generated outputs remain. Verification uses offline synthetic fixtures:
no mailbox/list/team/settings/database/server change, activation/welcome/configuration
message or mail delivery ran. Historical scalar captures cannot establish live
PHP-list acceptance. Live follow-up `8x0.2.85`, the wider `8x0.28.8` adopter audit
and existing module live/source follow-ups remain OPEN.

## WHM administration delimited inputs (2026-11-01)

Eleven generated positions use the accepted comma descriptor without a semantic
`sensitive:true` flag. Exact public → wire mappings are:

| Group/method | Public → wire | Position | Required |
| --- | --- | --- | --- |
| IpAddresses/addIps | `excludes` → `excludes` | 2 | no |
| Authentication/authorizeSshKey | `options` → `options` | 3 | no |
| Backups/backupConfigSet | `backupdays` → `backupdays` | 10 | no |
| Server/configureService | `eximAltportnum` → `exim-altportnum` | 2 | no |
| IpAddresses/disableIpv6Account | `user` → `user` | 0 | yes |
| IpAddresses/enableIpv6Account | `user` → `user` | 1 | yes |
| ModSecurity/getRules | `config` → `config` | 0 | no |
| ModSecurity/getRules | `vendorId` → `vendor_id` | 3 | no |
| Mail/saveSpamdConfig | `allowedIps` → `allowedips` | 0 | no |
| Resellers/setResellerIps | `ips` → `ips` | 2 | no |
| Resellers/setResellerNameservers | `nameservers` → `nameservers` | 1 | no |

```php
// Synthetic inputs; these explicit calls change server settings or account assignments.
$client->whm()->backups()->backupConfigSet(backupdays: ['0', '2', '6']);
$client->whm()->ipAddresses()->enableIpv6Account('SHARED', ['fixture-one', 'fixture-two']);
```

Required users remain `string|array`; nine optional positions remain
`string|array|null = null`. Nonempty sequential strict-string lists join once into
one original field, preserving order, duplicates, empty items and every other
byte. Reject embedded commas, empty/sparse/keyed lists and null/int/bool/float/
nested/object items after metadata reset before I/O. Raw commas, quoted commas,
percent escapes, pipes and empty scalars remain unparsed. Null omits; `['']`
supplies empty, `['', 'a', '']` supplies `,a,`, and `[]` never omits. Old captured
metadata remains intact. Native wrong types precede entry/reset. Add no coercion,
trim, sorting, parsing, enum/network/port/SSH syntax validation or cross-field guard.
Quoted SSH commas require scalar input; quotes cannot escape a list-item comma.
Days and ports remain strict strings in lists. Keep `backupdays` spelling,
`backupMonthlyDates` string|int|null, `backupWeeklyDay` integer and all aliases/order.

Omission excludes no IPv4 addresses, adds no SSH options, retains backup days
0,2,4,6 and Exim alternate port 26. ModSecurity selectors remain optional despite
source config-or-vendor prose; omission retains exclusion flag defaults 1 while
false sends 0. Omitted spamd allowedIps allows any IP; supplied loopback inclusion
is server validation without client insertion. Reseller ips omission clears the
list; nameservers omission resets defaults. Explicit empty stays supplied, and
review 5727013591 read its 11.138.0.19 effect: spamd allowedIps [''] equals
omission (any IP may connect), reseller ips [''] clears the delegated list and
nameservers [''] resets defaults. disableIpv6Account discards per-account
failures, so a null result does not prove every account changed; read it back. Preserve root SSH file/text precedence, required file
and sensitive text; IPv4 network effects and metadata.output.messages; IPv6
role/listening/range prerequisites, address loss/reassignment and per-account
failures; backup persistence, false/zero/timeouts/strict-prune/keeplocal caveats;
service clustering-ACL named-only and never-disable-cpsrvd caveats; spamd role,
zero-timeout/systemd PIDFile caveats; reseller delegate default 1/false 0, existing
all:int/ip:list<string> and separate main/shared IP assignment. Existing version
floors and all returned optional/null/mixed/strict fields remain in the overlays.

ModSecurity stays GET with both selected fields; the other nine methods stay form
POST. Generated SensitiveParameter covers all 11 collection positions and existing
SSH text without changing transport policy. Library frames conceal arguments;
caller/transport frames, memory and response/mismatch payloads remain outside that
guarantee. All 276 generated return specs and method bodies in these seven groups
and 266 nontarget contracts remain unchanged. No endpoint-count change: 1,234
canonical operations, 119 groups, 241 outputs. No implicit read/merge/retry/rollback,
local key/path access, DNS/network contact or restart is added. This convenience
has offline evidence only; historical scalar captures cannot prove PHP-list
acceptance. Live 2.86, wider 28.8, monthly-date gate 28.8.6 and existing module gaps
remain OPEN. Original source/checksum/evidence and 55 inherited diagnostics remain
unchanged. Exact-HEAD PHP 8.3 CI and external acceptance are separate gates.
