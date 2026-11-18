# UAPI GPG and two-factor authentication

> Agent contract notes for `gpg()`, `twoFactorAuth()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## UAPI GPG and two-factor authentication

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


The GPG/two-factor capture at f213ab8 on 11.138.0.19 passes five GET checks and
strict assessment with zero discrepancies. Both public-key lists are empty;
two-factor status has integer is_enabled with issuer omitted. No key content,
secret inventory, private/team configuration, key/authentication mutation or
login enforcement was tested. Remaining live work stays under 8x0.2.54.
