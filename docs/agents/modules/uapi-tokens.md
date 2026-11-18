# UAPI API tokens

> Agent contract notes for `uapi()->tokens()`. Index: [docs/agents](../README.md).

`uapi()->tokens()` covers all four pinned `Tokens` functions (cPanel 80+):
`createFullAccess()`, `listTokens()`, `renameToken()` and `revokeToken()`. The list is a
GET; the three mutations are form POSTs with the token name in the body. The WHM-side
token methods are separate: see [whm-api-tools.md](whm-api-tools.md).

## Where the pinned source is wrong

Observed on cPanel `11.138.0.24` through direct UAPI and the WHM bridge, and read in
`Cpanel/API/Tokens.pm` on that server:

- **`revoke` result placement and meaning.** The pinned schema declares `result.data` as
  an integer 0/1, while its description says the value is returned in the function's
  metadata and that 1 also covers a token that does not exist. The server returns the
  value as `result.data` with an empty metadata: `1` when a token with that name was
  removed, `0` when none had that name. Both are status 1. `revokeToken()` returns that
  integer unchanged and never reads metadata; `0` raises nothing.
- **`list` `expires_at` type.** Pinned as a nullable integer; a set expiry arrives as a
  digit string (the handler stores the request value as received). The overlay accepts
  the digit string and returns an integer. `create_time` is a real integer.
- **Undocumented `whitelist_ips`.** Every list row and the create result carry it, null
  in every observation. The server's base token class documents it as the allowed IP
  addresses or CIDR ranges, or undefined. It is typed as an optional
  `list<string>|null`; the string item type follows that source description, not an
  observed populated value. The UAPI module has no input that sets it. The handler's
  comment says the create result should hold only `create_time` and `token`, so the
  field may disappear from that result in a later version; it is optional for that reason.

Two observations agree with the pin. `readonly` is pinned as present and 1 only for
read-only tokens and otherwise absent or 0; it was present on every row, 0 on writable
tokens. `rename` data is pinned as a nullable object; it was null on this build. The
typed result stays `?array`, so do not describe `renameToken()` as returning null in
general.

## Behaviour the pinned text does not mention

Read in the handler and its validation and admin classes on `11.138.0.24`:

- Creation sends an `APIToken::Created` notification to the account user. Its targets
  are the account's contact addresses and Pushbullet token. `npr` has none, so no
  delivery was observed.
- All four functions need the account's `apitokens` feature and refuse team users.
- A nonzero `expires_at` in the past is rejected; `0` passes validation and its stored
  meaning was not observed.
- A duplicate name on create, a missing source on rename and a rename to the same name
  are API errors (all three observed).
- `rename` sets no result data. It changes only the stored name in place, leaving the
  secret and creation time, and refuses a name already in use. The captures confirm only
  that the renamed row keeps its expiry and read-only flag; that the secret still
  authenticates after a rename was not exercised.

## Secrets

The created token is a credential returned once. `listTokens()` never returns it. If
`createFullAccess()` raises `PayloadMismatchException` after an accepted outcome, the
token may exist and the exception's unvalidated `$payload` may hold its only copy: do
not log it, do not retry, and revoke by name if the token is not wanted. Revoking the
token a direct client authenticates with ends that client's access.

## Live recipe and evidence

`tools/live-verify/tokens-section.php` (recipe `tokens-lifecycle-v4`) runs the
lifecycle through the generated client on the designated `npr` account, which is shared.
Each route owns one throwaway token named `whmapiclient-live-<suffix>-<route>`, created
read-only with a one-hour expiry: list, read notification targets, create, list, rename,
list, revoke (expects 1), list. The rules a change to the recipe must keep:

- **No create without checking notification targets.** Before each route's create the
  recipe reads `contact_email`, `contact_email_2` and `pushbullet_access_token` through
  `Variables/get_user_information` and stops unless all three are reported and empty.
  The report's `notification_targets` records what the run checked; it makes no claim
  about delivery.
- **A released name is someone else's.** The run holds a name after an accepted create
  or rename and releases the old one after an accepted rename or revoke. A refused
  mutation changes nothing; a refused create ends the route as `not_created` with no
  cleanup.
- **An unknown outcome is not ownership.** A create, rename or revoke whose reply the
  run never got makes every name it could have touched `unknown`. Expiry and the two
  flags are attributes any token can share, not an identity, so a row under an unknown
  name is never revoked, however well it matches.
- **Cleanup revokes only a confirmed held name with the run's settings.** A held name
  with other settings, a released name that is listed again, or any row under an unknown
  name may be another party's token: it is left untouched and the route stays
  `uncertain`. A name the run never had is ignored.
- **No mutation targets a freed name.** The lifecycle revokes each token once. v1 and
  v2 revoked the freed name a second time to observe the `0` result; on a shared account
  that would hit whoever reused the name. Repeating it belongs on a disposable account
  (`whm-api-client-8x0.2.98`).
- **Absence needs evidence.** A list proves absence only when every row has a name and
  none holds a secret. A create of unknown outcome is never settled: an empty list does
  not settle it, because the request can still take effect, and a listed row does not,
  because nothing shows the run created it. A lost revoke reply settles only when no row
  carries the name.
- The guard admits only the run's own names, each phase once. Cleanup requests are
  separate phases, never retries, and a run that needed them cannot pass.
- The created secret is checked in place, never used and never recorded.

v4 has no capture. Its passing path sends the same requests with the same expectations
as v3; only the handling of failures changed, and those paths are synthetic-only. A
cost of the v4 rule: after a lost reply the run's own token is left in place until it
expires within the hour, and the route is reported `uncertain`.

Captures, all on `11.138.0.24` with zero discrepancies and both tokens absent:

- [v3](../../api/evidence/2026-11-18-tokens-section-v3.json) at `1c05e6d6`: 17
  observations (11 GET, 6 POST), `notification_targets` `none_configured` on both routes.
  The latest capture. Its cleanup treated a name as held after a lost rename reply.
- [v2](../../api/evidence/2026-11-18-tokens-section-v2.json) at `bd654671`: 19
  observations (11 GET, 8 POST). Same checks as v3 plus the second revoke returning `0`;
  its cleanup kept a renamed-away or revoked name as the run's.
- [v1](../../api/evidence/2026-11-18-tokens-section.json) at `c7f2ec78`: 17
  observations (9 GET, 8 POST), including the second revoke. v1 did not check
  notification targets and its report states that the account has no contact address;
  that was checked by hand that day. v1's cleanup revoked both names after any failure.

No capture needed cleanup. The `0` result of revoking a missing token is recorded by v1
and v2 only.

The failure paths are covered by synthetic tests only (`TokensSectionFixtureTest`,
`TokensSectionRecipeTest`). The API-error cases above come from a separate sanitized
probe on 2026-11-18 that is not a committed capture.

Open follow-up `whm-api-client-8x0.2.98`: a populated `whitelist_ips`, notification
delivery, expiry and read-only enforcement, and versions other than `11.138.0.24`.
