# WHM login and mail protection

> Agent contract notes for `whm()->cpHulk()`, Greylisting in `whm()->mail()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM login and mail protection

`whm()->cpHulk()` implements sixteen pinned cPHulk functions, and
`whm()->mail()` implements fifteen Greylisting functions. Fifteen reads use GET;
sixteen mutations use form POST. The JSON batch-record operation remains under
`8x0.1.18`. `cpgreylistSaveCommonMailProvidersConfig()` accepts optional `providers`
as a literal-key bool map. Null or [] sends no provider-specific fields; existing
provider settings remain unchanged, while omitted autotrust retains default 1.
The pre-existing Mail SNI exclusion stays intact.

cPHulk configuration can change login protection, firewall blocking and commands
run after attacks. Command and generic configuration-value inputs remain sensitive.
Enabling or disabling protection, editing trusted or blocked addresses, and flushing
login history take effect on the selected server; the client does not retry or
roll them back. Per-item results and service flags remain separate from API success.
Greylisting time settings retain their documented units and defaults, and trusted
provider changes affect future mail handling without proving delivery behavior.

Documented IP/provider lists use the reviewed base-name then `-1`, `-2` encoding.
Alternative prose using a `-0` first index remains unverified. In `saveConfig()`,
omitted cPHulk options preserve their current values, as established by the
reviewed partial-update source; explicit false and zero remain distinct. Other
operations retain their method-specific omission rules. Response maps preserve
dynamic keys and optional fields. The source-backed untrust correction retains
provider error strings in `providers_failed` and `ips_untrusted` records in
`providers_untrusted`; provider IP entries are lists of the four known fields.
Trusted-host creation preserves per-IP error strings instead of silently dropping
those dynamic failures. Save-config enabled flags use the same exact quoted 0/1
compatibility as load, based on the shared source result; no save was observed.

All four history reads accept bounded canonical integer-string `timeleft` values.
The excessive-history result also retains its original native integer/float form;
fractional strings, noncanonical strings and overflow are not newly accepted.
Only the failed-login string values were observed; the other histories use shared
source inference. Native fractional values and stored-row null comments remain
unobserved under `8x0.2.35`.

On the reviewed server Greylisting is disabled. Its reads raise `ApiErrorException`
while disabled, except status and numeric configuration, which still work. This
is a version-specific reviewer observation, separate from the committed capture.
The client does not enable the service or waive API outcomes to populate evidence.

All thirty methods have independent offline contract coverage. The fixed eight-GET
catalog selects WHM version, cPHulk status and country inventory, and Greylisting
status, server netblock trust, common-provider configuration, numeric configuration
and trusted-host inventory. It reads no authentication logs, deferred-message
records or configured command text. It changes no protection, trust, history or
mail state and sends no messages. Follow-up `8x0.2.35` retains all unobserved
variants and enforcement behavior.

The corrected [login/mail-protection capture](../../api/evidence/2026-10-31-whm-login-mail-protection-corrected.json)
at `8f1a4b91a556ca73f5be9db5ad6170d2e481bd0c` on `11.138.0.19` records eight HTTP 200
observations: five API/payload/probe passes, the same three API failures and zero
payload discrepancies. The reviewed Greylisting flag now passes exact string-flag
normalization; numeric configuration limits and sibling flags retain their types.
Country inventory is populated. Failed Greylisting inventories establish no row
schemas; the reviewer separately diagnosed disabled Greylisting as their cause.
Strict assessment remains failed without a waiver.

No authentication logs, deferred messages, command text, mutation or notification
was involved. Both artifacts remain immutable. API status does not establish
login blocking, mail delivery or enforcement. Follow-up `8x0.2.35` owns the
remaining failures, methods and variants. SHA-256:
`27fd32d3b51e8195a3f027b4890f55ad68a440bb706aabf3862b84f6582e6a27`.
