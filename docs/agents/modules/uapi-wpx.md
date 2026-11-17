# UAPI WPX WordPress lifecycle

> Agent contract notes for `uapi()->wpx()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI WPX WordPress lifecycle (2026-10-31)

`uapi()->wpx()` covers all fourteen pinned WPX functions for the selected account,
available from cPanel 137. Every operation requires `wp-toolkit`. Ownership,
features, plan limits and other prerequisites are server checks. Seven reads use
GET and seven operations use form POST on both client routes. Six POSTs preserve
the formally required form-body fields; `getCredentials()` uses mutation POST
because its pinned description says each call mints a fresh token, despite its
formal GET variant. PR100 review 5725396130 at `a4d3145` on `11.138.0.19` reports
POST reaching the adminbin through direct UAPI and the WHM bridge, then failing
because the WP Toolkit binary was missing. Success/token payload remains
unobserved; this establishes no universal route acceptance or success guarantee.

`createWebsite(domain, title)` can queue an installation, requiring `redirects`,
`mysql`, disk headroom and plan website-count allowance. The server normalizes
the domain; the client forwards its bytes unchanged. Omitted title defaults to
the domain. A title that violates upstream length/control-character restrictions
can fail in the background after API success. A successful `collision` result
queues nothing and preserves `existing_installations`; the client never deletes
existing sites automatically. A `running` result is not completion or health.

`importWebsite(sourceUrl, sourceUser, sourcePassword, domain, overwrite,
passwordType)` requires `nova-wordpress`, `redirects`, `wp-toolkit`, `mysql`, an
HTTPS source resolving only to public addresses, and an owned destination domain
with its own document root. Source user/password are body-only. The client does
not resolve or fetch the source URL. Omitted overwrite retains the server default
0; explicit false sends 0, while true can replace an existing installation.
`passwordType` remains a supplied string with the inherited sensitive annotation.
The background import can fail after API success.

`createNovaForWordPressWebsite(domain, colorScheme, promptToken, returnUrl)` creates
a cPanel token and a Nova magic link. It neither installs WordPress nor queues an
install; a later import callback does that. Besides `wp-toolkit`, it needs
`nova-wordpress`, `mysql`, `redirects`, `apitokens` and disk headroom. The source
says it does not enforce the WordPress website-count limit and revokes the token
on Nova failure; these are documented claims, not live observations.

`promptToken` retains exact opaque caller-supplied base64 bytes. Upstream supports
standard/URL-safe forms with optional padding, at most 8192 sent characters and
5000 decoded bytes. Omission or a blank prompt may consume a session prompt;
supplied nonblank input leaves that fallback intact. The client does not decode
or re-encode. Upstream `returnUrl` and `panelReturnUrl` must be HTTPS on the same
cPanel host, without userinfo and at most 2048 characters; invalid values are
ignored for defaults. No local URL validator is invented.

`getCredentials(id, panelReturnUrl)` mints a new one-use token each time; first
login consumes it. It never returns a WordPress password. Its formally optional
login/password strings remain strings rather than closed empty literals, and an
empty login URL is meaningful. Never log or fetch returned login URLs or Nova
magic links. Password/login, import source URL/user/password, prompt and return
URL inputs are sensitive. `changeAdminPassword(id, password, login)` omits login
only for a sole administrator; otherwise choose an existing admin from
`getAdminAccount()`. The server accepts nonempty passwords without its own
strength rule. The source describes caller-private file handling without process
or Toolkit-log exposure; offline fixtures do not establish that behavior.

`getAdminAccount()` preserves null administrator ID, empty strings/list when no
administrator exists; read failure remains an API error. It returns neither the
stored password nor active language. `setLanguage(id, language)` may install
translations and uses a WordPress locale, not a cPanel locale. Unpublished locales
can fail upstream; no language readback is invented.

`getWebsite(id)` bypasses brief list eventual-consistency lag. Missing and
other-account IDs both return success with explicit null; absent data remains a
mismatch, while malformed/backend errors fail. `listWebsites()` returns only
installations under the selected account home. Its row roots stay nonnullable;
optional nullable `wptk_id`, strict native integer health flags, empty domains and
omitted domains/info retain their distinct shapes. Guard a null ID before reuse.

`getSiteActivity(id)` starts WordPress and can take 30 seconds. All-zero flags can
mean boot failure/timeout rather than absence of activity. `has_user_activity`
reflects authored content or uploaded media only; other flags are informational.
`hasReachedQuota()` includes installation headroom and may return 1 before actual
quota exhaustion; this is separate from WordPress website-count limits. Payload
zero remains a normal Flag success; envelope status 0 still fails.

`getTaskStatus(taskId)` answers queue membership only. `done` also covers removed,
never-existing and other-account tasks and does not establish success.
`getTaskProgress(installId)` reads retained progress/outcome even after completion.
SSE paths need an interactive session; API-token clients explicitly call progress.
The client never polls or fetches returned paths automatically.

`removeInstallation(id)` permanently removes files and database without a recycle
bin or retained copy. It cannot be undone and a second call fails. No automatic
retry, collision cleanup, restore or rollback occurs. All methods make one request.
Optional null arguments omit; false, zero and empty strings remain supplied and
form/query strings encode once. No local range, enum, locale, address-resolution,
URL or password-strength checks are added. Formal response fields stay optional;
strict types remain unchanged except that `getTaskProgress().percentage` accepts
existing lossless canonical bounded integer strings and returns int. No new
percentage range validator or general integer/flag coercion is introduced.

The integrated slice adds fourteen canonical operations and one group over approved
master `2356ddc6c9ef759ea090898d1bbed35cc4a81775`: 1,214 operations (618 UAPI,
596 WHM), 107 groups and 217 generated outputs (216 PHP files plus coverage). Existing
runtime/generator/dependencies, prior overlays/generated contracts, pinned sources,
historical audit and evidence remain unchanged. Delivery is tracked by `8x0.4.25`;
live follow-up `8x0.2.67` remains open. Independent offline fixtures do not establish
installation/import/login, source resolution, quota behavior, token consumption or
revocation, translation/health behavior, or deletion/cleanup on a live server.

PR100 review corrections (2026-11-01): all three creators can return a successful
envelope with `status=unsupported_php`, compatibility diagnostics, and no queued
work or installation task. Inspect status explicitly before treating a result as
running. Nova also queues no installation task on its normal magic-link path.
The eleven added fields are optional; `compatible` and `is_fallback` are strict
native integer 0|1, `required_version` is string (source constant `7.4`), and
`blocked_by` is a string list. Seven lookup strings preserve null separately from
omission: `effective_version`, `system_default_package`, `system_default_version`,
`domain_package`, `domain_version`, `domain_version_source`, `domain_version_vhost`.
Existing creator domain/status remain nonnullable strings. No new closed enum,
local PHP-version check or automatic repair is introduced. A `domain` blocker can
require changing the MultiPHP Manager row named by `domain_version_vhost`; a
`system_default` blocker means contact the provider.

These refusal fields/remedies and the additional `wp-toolkit-site-import` gate
for Nova/import plus the Nova entitlement's `ai-app-builder` extension are
source-only facts from `Cpanel/API/WPX.pm`, `Cpanel/WPX/PhpCompat.pm` and
`Cpanel/WPX/Constants.pm` on `11.138.0.19`, reviewed in 5725396130. The local
captured-output provenance is in the source-audit correction appendix. They were
not observed in an unsupported-PHP wire response. Separately, that reviewer seeded
the two documented progress-log formats (no real installation): direct GET
returned quoted 20/10/40, bridge GET integers; no-log 0 and complete 100 were
integers on both routes. This task performed only offline synthetic verification.
