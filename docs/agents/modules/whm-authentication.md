# WHM authentication

> Agent contract notes for `whm()->authentication()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

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

Provider display retains the caller-serialized string method and adds
`configureProviderDisplay(providerId, serviceName, configurations)` with a required
structured array of six optional nonnullable string fields: `color`, `display_name`,
`icon`, `icon_type`, `label` and `textcolor`. One serialization in one form field
is separate from whole-body JSON; `[]` sends `{}`. On reviewed `11.138.0.19`, both
methods merge supplied keys and always persist the complete merged configuration,
including empty input, pinning current values/defaults for that provider/service.
The version-scoped source note covers upstream color and provider-key constraints;
do not add local enum/regex/provider validators. The legacy string method preserves
its supplied serialized bytes without object encoding or JSON syntax validation.
`twoFactorAuthRemoveUserConfig(user: 'alice')` retains scalar `user`;
`user: ['alice', 'bob']` sends `user-1`/`user-2`, including suffix 1 for a
singleton list. Lists must be nonempty, sequential and string-only; order,
duplicates and exact string bytes are preserved. Invalid collections fail before
I/O after metadata reset. This remains one form POST with no implicit prior-state
read, retry or rollback. Inspect the `failed` map separately from API success.
A user listed in `users_modified` may have had no TFA settings; it does not prove
an actual removal. Users must set up TFA again after removal. Batch atomicity,
persistence and live list compatibility are not established by offline tests.

All 30 methods have independent offline contracts. The fixed three-GET catalog
reads WHM version, issuer names and policy status only. It retrieves no keys, TFA
secrets, provider configuration or login URLs, opens no remote SSH connection,
and changes no account, authentication policy or provider. Follow-up `8x0.2.40`
retains remaining source/live coverage.

The WHM authentication capture at `e88583e` on `11.138.0.19` passes all three
GET observations and strict assessment with zero discrepancies. Only version,
issuer names and the integer policy flag were read. No secret, private URL, key,
configuration or link data was requested, and no state changed. It establishes
no login, TFA challenge, SSH access or mutation compatibility. `8x0.2.40` remains
open for all unobserved functions and variants.
