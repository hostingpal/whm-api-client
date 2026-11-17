# WHM 360 Monitoring

> Agent contract notes for `whm()->monitoring()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM 360 Monitoring (2026-11-01)

`whm()->monitoring()` exposes the complete seven-function pinned Monitoring group
from cPanel & WHM 11.132: `registerServerWith360Monitoring()`,
`set360AgentPolling()`, `webProsAuthCallback()`, `webProsAuthClear()`,
`webProsAuthStart()`, `webProsAuthStatus()` and `webProsAuthToken()`.
Canonical identities already include `360Monitoring/`; no wire correction or
new router is needed. All seven use one immediate form POST with api.version=1
only in the URL and normal WHM outcome. Callback source formally requires
body-only POST. The other six source GET declarations lack x-readonly and use
existing unknown-risk POST policy, without live acceptance claims.

Registration can create an external account and configure the local agent;
credentials remain server-side. Already-registered no-op/idempotency is source
prose, not observed behavior. Polling retains source-order optional arguments
`(globalSections, interval, maxDataSpan, pluginSections)`. Both section filters
accept literal comma strings or nonempty sequential strict-string lists through
explicit shared delimited descriptors. Strings preserve every byte without
parsing/rejoining; arrays join once to the original field with a comma, preserving
item bytes, order, duplicates, spaces and empty strings. Reject commas inside list
items, empty lists, nonsequential lists and nonstring items before I/O after
metadata reset. Null omits; [''] sends empty and ['', 'cpu', ''] sends ,cpu,.
Collection arguments receive SensitiveParameter for library trace protection;
section lists do not alter transport sensitivity or the original POST policy.
Do not infer defaults or introduce indexed lists. Empty scalar strings and zero
remain supplied. Interval or maxDataSpan is required server-side; positive timing,
documented section choices and filter/interval relationships remain server
validation without local enum/range/cross-field guards. Interval without filters
updates all core/default plugin sections, and maxDataSpan controls batched
metrics. Source matching-values no-restart behavior is unverified; other changes
may restart agent360. The four FTP inventory filters also adopt the shared
delimiter convention; other delimited adopters remain under 8x0.28.8.

Callback is a required sensitive raw string in one form `json` field, encoded
once, never URL query data or callJson. Preserve JSON bytes/whitespace, unknown
keys and invalid/scalar JSON for server validation; no invented nested schema,
automatic serialization or companion. README explicitly json_encodes synthetic
code/state/session strings with JSON_THROW_ON_ERROR. Structured callback
foundation/adoption remains under 8x0.1.22. Code/state are required by source
prose; optional session_id applies without a request session, whose session takes
precedence. Legacy verifier is ignored with later removal documented. Server PKCE
verifier stays server-side. Validate state before exchange per source; matching
state is consumed on success or failure, unknown/other-session state consumes
nothing. Never retry implicitly. token_unusable may follow a stored unusable
token, so promise no rollback or all-or-nothing behavior.

Auth start's sensitive optional sessionId preserves leading zeros and empty
strings without local digits validation. Token/Basic callers need the intended
existing WHM session; the library has no browser-session transport or implicit
session creation. State is a ten-minute single-use secret bound to that session.
Authorization URLs/state remain private inert payload. Status checks identity
and expiry without tokens; token returns only bearer access_token, never refresh
bundle, with expires_in=0 meaning unknown remaining lifetime. Clear signs the
server out by removing the stored bundle; no-token success is unverified source
idempotency, and removal_failed leaves the token in place. Add no implicit read,
registration, redirect, OAuth exchange, token storage, polling, retry or rollback.

Required nonnull known-object roots retain every optional field, nonnullable
except callback token_data.email and status email, which are nullable because the
handler copies an optional ID-token claim (review 5726281163). user_id remains strict string
despite numeric example 12345. Poll status remains strict int: enum[1] documents
only success 1, and the existing validator does not enforce singleton membership
or establish other integer semantics. success/valid are strict native integer
0|1 flags, without quoted/bool/float coercion. Reason strings stay open. Preserve
missing fields versus present empty objects/strings; null/missing roots mismatch.
Known-object filtering and inherited empty-array object compatibility remain.
WHM metadata.result=0 precedes payload validation; payload success/valid=0 under
result1 requires caller inspection. Preserve raw WHM warnings/metadata and
mismatch snapshots. Selectors perform no I/O/reset; invocation resets normally,
while strict native argument failure occurs before entry/reset.

Tokens, URLs/state/session/account/error contents and mismatch payloads may be
private. json and sessionId have SensitiveParameter; library response frames are
protected without promising caller/third-party trace secrecy. Never log returned
tokens/state or fetch authorization URLs automatically. Synthetic offline tests
establish no external account/authentication, session/replay/expiry, registration,
agent/settings, persistence, sign-out or POST acceptance behavior. Live follow-up
8x0.2.79 remains OPEN; delivery 8x0.4.37 adds seven canonical operations once.
At Monitoring delivery, totals were 1,228 operations (633 UAPI, 595 WHM), 117 groups and 237 outputs
(236 PHP plus coverage). Original sources/evidence and previous endpoints remain
unchanged. The original Monitoring delivery changed no shared runtime or
generator policy. This subsequent filter adoption uses the accepted explicit
shared delimited descriptor convention; it adds no further shared runtime,
generator, dependency or source-schema correction.

Inventory and discovery selectors also accept nonempty sequential string lists:
Bandwidth `domains`/`protocols`, Branding `nvarglist` and required StatsBar `display`
join with `|`; Branding `appKeys` on both methods, VersionControl `fields` and
WHM HostingPlans `applicationsList` join with `,`. Fileman `onlyTheseFiles` stays a
raw string: review 5726574983 found that the 11.138.0.19 handler dereferences it as
a Perl array reference, so no HTTP form works (ignored without `limitToList`, an
API error with it). With `applicationsList` set, the WHM result is only
`{applications: [...]}` (opaque rows); `groups` and `raw` are absent.
Raw strings remain byte-preserved and unparsed. Lists preserve order, duplicates,
empty items, whitespace, Unicode, binary and URL/percent bytes; an embedded selected
delimiter rejects, while the other delimiter stays data. No escaping, trimming,
splitting, deduplication, enum validation or default insertion occurs. Optional
null omits, `[]` rejects, `['']` supplies empty and `['', 'x', '']` supplies `|x|`
or `,x,`. Malformed lists fail before I/O after metadata reset. StatsBar `display`
remains required `string|array`; native omission/null errors precede method entry
and do not reset prior metadata. Other selectors are `string|array|null = null`.

```php
// Synthetic request inputs; these calls use the selected account.
$account->bandwidth()->query(grouping: 'domain', domains: ['example.test', 'UNKNOWN']);
$account->branding()->getApplications(appKeys: ['email_accounts', 'boxtrapper']);
$account->statsBar()->getStats(display: ['diskusage', 'bandwidthusage']);
```

GET routes stay GET. Branding `getInformationForApplications()` retains form POST
and its required sensitive `docroot`; VersionControl `retrieve()` retains form POST
and no retry. Collection trace attributes do not classify selectors as secret
inputs or change transport. Omission retains each source default: all domains,
all dynamicui features/sitemap keys/files/WHM content, no group sorting, or the
VersionControl `*` field default. Explicit `*` or `['*']` remains supplied.
Bandwidth `grouping` and Fileman `types` keep their existing scalar boundaries
under separate composed-source gate `8x0.28.8.6`. Returned payloads and all companion
false/zero options are unchanged. This is offline request coverage; historical
scalar captures do not establish PHP-list execution or server filtering/empty
semantics. Live follow-up `whm-api-client-8x0.2.81` remains OPEN.
