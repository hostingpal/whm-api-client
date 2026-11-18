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
- **`readonly` presence.** Pinned as present only for read-only tokens; it was present
  on every row as 0 or 1.

## Behaviour the pinned text does not mention

Read in the handler and its validation and admin classes on `11.138.0.24`:

- Creation sends an `APIToken::Created` notification to the account's contact address.
  `npr` has none, so no delivery was observed.
- All four functions need the account's `apitokens` feature and refuse team users.
- A nonzero `expires_at` in the past is rejected; `0` passes validation and its stored
  meaning was not observed.
- A duplicate name on create, a missing source on rename and a rename to the same name
  are API errors (all three observed).
- `rename` sets no result data; the observed payload is null. The typed result keeps the
  pinned nullable object.

## Secrets

The created token is a credential returned once. `listTokens()` never returns it. If
`createFullAccess()` raises `PayloadMismatchException` after an accepted outcome, the
token may exist and the exception's unvalidated `$payload` may hold its only copy: do
not log it, do not retry, and revoke by name if the token is not wanted. Revoking the
token a direct client authenticates with ends that client's access.

## Live recipe and evidence

`tools/live-verify/tokens-section.php` (recipe `tokens-lifecycle-v1`) runs the whole
lifecycle through the generated client on the designated `npr` account. Each route owns
one throwaway token named `whmapiclient-live-<suffix>-<route>`, created read-only with a
one-hour expiry: preflight list, create, list, rename, list, revoke (expects 1), revoke
again (expects 0), list. The guard admits only those names, so the account's existing
token is out of reach. After any failure it revokes both names of that route once,
confirms absence and stops before the next route; a run that needed cleanup cannot pass.
The created secret is checked in place, never used and never recorded.

The [2026-11-18 capture](../../api/evidence/2026-11-18-tokens-section.json) at `c7f2ec78`
on `11.138.0.24` passes all 17 observations (9 GET, 8 POST) with zero discrepancies and
both tokens confirmed absent. It does not cover the API-error cases above, which come
from a separate sanitized probe on the same day that is not a committed capture.

Open follow-up `whm-api-client-8x0.2.98`: a populated `whitelist_ips`, notification
delivery, expiry and read-only enforcement, and versions other than `11.138.0.24`.
