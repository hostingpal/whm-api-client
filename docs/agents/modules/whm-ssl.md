# WHM SSL certificate management

> Agent contract notes for `whm()->ssl()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM SSL certificate management

`whm()->ssl()` exposes all 34 pinned SSL Certificates operations: ten GET
reads and 24 form POST calls, including source-classified POST fetch operations.
`resetAutoSslProvider()` and `setAutoSslProvider()` accept optional sensitive
`parameters` string maps with a literal `x_` prefix added to caller suffix keys.
Null or [] sends no extra provider fields. Terms URLs require deliberate caller
acceptance; the client does not fetch URLs or automatically consent. Reset affects
remote registration; use `disableAutoSsl()` to disable AutoSSL. Provider inventories preserve strict
named fields and opaque additional values; treat returned provider data as private.

PEM, private-key, CA-bundle and passphrase inputs remain sensitive. Domain exclusion
arrays use documented numbered names; deferred installation uses repeated fields
whose five lists callers must align by position, including each certificate and
private key. Neither the client nor its type checks validate equal lengths or
pairing. An empty bundle requests server-side bundle download. The metadata setter accepts caller-serialized JSON text encoded once as
a form field; its native `string` argument preserves supplied text without JSON
syntax validation or automatic object encoding. This is not a JSON request body.

Generation stores key/CSR material even when certificate generation is skipped.
Service certificate installation requires a separate service restart; the client
does not perform it. Exclusion replacement, disable/reset/install and background
checks can alter hosting behavior; queued work or a PID does not establish
issuance, renewal, installation or TLS delivery. No retry, rollback or polling is
added. `installSsl()` returns its own `status` and `warning_domains` alongside
the checked WHM envelope outcome. Callers must inspect those fields: a successful
`metadata.result` does not imply nested `status: 1`, and nested failure is not
converted into an exception. `listCertificates()` without `user` includes all
accounts' certificate metadata; specify the intended account when isolation is
required. Virtual-host deletion has no rollback and must not be retried simply
because response validation failed after a confirmed outcome.

Certificate fields preserve omission, native values and literal dotted keys.
Separate WHM reviewer observations support string terms-of-service acceptance
(despite the conflicting pinned flag), canonical integer strings in best-service
expiry and FQDN certificate dates/length, exact quoted flags, and null FQDN
verification-error/issuer-organization values. Vhost self-signed flags preserve
the exact empty string without assigning it new meaning; deletion flags accept
exact quoted 0/1. The five shared reset/install service-certificate leaves use
source-family compatibility only; those writes were not observed.
`fetchServiceSslComponents()` keeps its native integer contracts, and unconfirmed
vhost `subject.commonName` variants stay unsupported.

`rebuildInstalledSslDatabase()` is a documented no-op. It and deferred enqueue
accept absent/null successful data as reviewed source-only compatibility, while
present payloads keep their existing opaque-data validation. No missing/null
response from either method was observed.

All 32 methods have independent offline contracts. The bounded nine-GET catalog
reads version, schedule, log catalog metadata, metadata flags, problems/exclusions
for designated `npr`/`npr.rs`, the best domain for the pinned `whostmgr` service
example, and the account's certificate inventory. It reads no log contents,
private-key/component PEM or unspecified provider values and makes no changes.
Follow-up `8x0.2.39` retains remaining live behavior.

The corrected WHM SSL capture at `4317e1a` on `11.138.0.19` passes all nine GET
observations and strict assessment with zero discrepancies. Five evidenced leaves
preserve best-domain null expiry, nullable certificate owner items and issuer
organization, and canonical integer-string modulus/expiry values. No sibling or
UAPI contract changed. Certificate inventory is populated; no mutation or private
material read ran. The initial artifact is immutable and `8x0.2.39` remains open.
