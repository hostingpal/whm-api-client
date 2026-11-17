# WHM web-server configuration

> Agent contract notes for `whm()->webServer()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM web-server configuration

`whm()->webServer()` exposes all 30 pinned functions: eighteen GET reads, ten
form POST operations and two typed JSON POST methods for EasyApache profiles,
PHP/FPM configuration, INI content/directives and PHP versions.
`phpFpmConfigGet()` and `phpFpmConfigSet()` use the required JSON body contracts
in WHM JSON configuration below.

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
`?string` argument omits null and preserves text without JSON syntax validation.

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
