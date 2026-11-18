# UAPI Webmail credentials and session inventory

> Agent contract notes for `session()`, `activeSessions()`, `webmailApps()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### Create a temporary user in an authenticated session

`SessionUrlClient` binds one caller-owned cPanel or Webmail session to
`Session/create_temp_user` (cPanel 54.0.16+). Supply an independently trusted
HTTPS service origin and the complete endpoint URL for the active session,
including its `/cpsess<digits>/execute/Session/create_temp_user` path. Pass
the opaque cookie value as sent on the wire, without its name or attributes.
Configure a dedicated PSR-18 transport to verify TLS, disable redirects and
retries, and enforce bounded timeouts. Omit ambient authentication and cookies.

```php
use HostingPal\WhmApiClient\SessionUrlClient;

$session = new SessionUrlClient(
    trustedOrigin: 'https://panel.example.test:2083',
    endpointUrl: $authenticatedEndpointUrl,
    cookieValue: $existingSessionCookieValue,
    transport: $transport,
);
$result = $session->createTempUser();
$temporaryUser = $result['created'] === 1 ? ($result['session_temp_user'] ?? null) : null;
```

The return type requires a `created` flag and checks the identifier's string
type when present. Use the identifier only when the flag is one and the key
exists. A zero flag indicates that this invocation did not set up a temporary user.

Construction performs no I/O. Each call sends one empty form POST with
`cpsession` on port 2083 or `webmailsession` on port 2096. The caller owns
session establishment and logout. Keep the URL, cookie, returned identifier,
response metadata and raw failures out of logs and object exports.

On `11.138.0.24` cPanel returned `['created' => 1, 'session_temp_user' => <string>]`
when it created the temporary user and `['created' => 0]` on a second call in
the same session. Both carried UAPI status 1. The official operation also
names Webmail, but on this build its application allowlist refused the call
after authentication. The client returns that failure as an `ApiErrorException`
with status 0 in `responseMeta()`. A new attempt clears earlier metadata.
Inspect any confirmed payload mismatch before issuing another mutation.
[Official session operation](https://api.docs.cpanel.net/specifications/cpanel.openapi/webmail-sessions/session-create_temp_user).

### UAPI Webmail credentials and session inventory (2026-11-01)

Five operations span three generated groups. `session()` implements three of the
four pinned Session functions: `createWebmailSessionForMailUser(domain, login,
locale: null, remoteAddress: null)`,
`createWebmailSessionForMailUserCheckPassword(domain, login, password,
locale: null, remoteAddress: null)` and
`createWebmailSessionForSelf(locale: null, remoteAddress: null)`. All require
cPanel 86 and send form POST on direct UAPI and WHM bridge: each selected formal
GET declares `x-readonly=false` and `x-rollback=none`. Reviewer evidence below
establishes form POST acceptance through original `5b005fb` on the reviewed build.
The fourth Session operation uses the authenticated session URL described above.

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
were used for login/access. That review did not observe a non-null hostname or create_temp_user.
The reviewer reports four lingering session files manually removed after account
removal, not an account-deletion cleanup guarantee. Root's four GETs observed only
empty ActiveSessions and populated WebmailApps. This integration performs no live
call or server write and does not replay the completed owner 165-call pass.
