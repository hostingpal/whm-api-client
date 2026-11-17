# UAPI SiteQuality

> Agent contract notes for `uapi()->siteQuality()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

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
