# UAPI security and access

> Agent contract notes for `blockIp()`, `modSecurity()`, `clamScanner()`, `knownHosts()`, `externalAuthentication()`, `ssh()`, `lastLogin()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The security and access section exposes 24 compatible methods across `blockIp()`,
`modSecurity()`, `clamScanner()`, `knownHosts()`, `externalAuthentication()`, `ssh()`
and `lastLogin()`. Thirteen reads use GET and eleven mutations use form POST.
Typed ClamScanner JSON disinfection remains excluded under `8x0.1.18`; generic form
calls cannot provide that body contract.

IP block inputs retain documented address, hostname and range spellings. ModSecurity
domain filters accept comma-separated strings or nonempty sequential string lists, encoded once; dependency
lists identify other affected domains. Per-domain exception fields preserve both
explicit null and omission. A failed call can still change some domains: re-read
`listDomains()` before deciding how to proceed. API status remains checked and no
mutation is retried or rolled back automatically.

ClamScanner preserves source scan types, administrator availability, byte/megabyte
units, integer counters and lossless integer-or-string start timestamps. `getScanPaths()` preserves its pinned object and observed list-of-records
alternatives; `getScanStatus()` also accepts the observed explicit null. Disinfection log alternatives use the established generator
merge, preserving common required `type` and all optional branch fields; no
per-discriminator validation is inferred. Scanning is a POST operation and does
not establish successful disinfection. The JSON disinfection operation is absent.

KnownHosts mutations change the selected account's SSH trust file. The reviewer
observed `create()` obtaining key material without a supplied key; this observation
does not establish network contact by `verify()`, which remains outside the fixed
capture. Its payload status is separate from the API status. Provider URLs/icons and public
key/fingerprint strings remain payload data, without fetching or rendering.
External account unlinking is a mutation. Subject identifiers preserve integers or
strings end to end, including strings larger than PHP integers. `removeAuthnLink()`
sends the provider argument as `provider_id`; successful unlinking is unobserved. SSH access is a
boolean capability, not proof of login. SSH ports normalize only canonical integer
strings, and last-login addresses preserve scalar-string and string-list alternatives.

All 24 methods have offline contract coverage. The fixed live catalog performs
25 GET observations: WHM version plus twelve parameterless scanner, ModSecurity,
external-authentication, SSH and last-login reads through both account routes.
No scan, disinfection, security-setting change, block change, external unlink,
host-key verification, login or remote URL fetch occurs. Empty inventories do not
prove populated row contracts, and API success does not prove security enforcement.
Remaining behavior is tracked under `8x0.2.31`.

On reviewed cPanel `11.138.0.19`, missing account ModSecurity access explains its
read failures; enabled-account reads passed. ExternalAuthentication failed over
the bridge because its module file was absent, while direct calls worked. These
are installation-specific observations, not client route restrictions. ClamAV
was absent, so completed scans remain unobserved. Reviewer lifecycle reports are
separate from immutable capture evidence; see the source audit. The enum-only
`scan_type` input now generates `startScan(string $scanType): ?array`, with PHPDoc
`home|mail|public_html|public_ftp`. The pinned schema has neither type nor format;
its string enum and directory prose support this evidence-bound correction.
This pre-release narrowing rejects integers from strict PHP callers before a
request or metadata reset. Weak PHP callers retain normal scalar coercion.
Unknown strings still forward unchanged: the PHPDoc enum adds no runtime
membership validator. POST encoding, nullable opaque results and coverage are unchanged.


The corrected [security/access capture](../../api/evidence/2026-10-31-security-access-corrected.json)
at `aee24c1081727cd66b8ef86aced5bd27d7e83375` on `11.138.0.19` records 25 HTTP
200 observations: eighteen API/payload/probe passes and the same seven API
failures. There are zero payload discrepancies. Scan-path lists now establish
string `id` and `message` leaves on both routes; scan status remains null, SSH
ports normalize canonical integer strings and last-login results remain scalar
strings. No populated infected-file rows or enforcement behavior is established.
Strict assessment remains failed for the API errors, without a waiver. Causes
were assessed separately in the reviewer report in `docs/api/source-audit.md`. No mutation ran. Both artifacts remain immutable and follow-up
`8x0.2.31` retains the unobserved methods and variants. SHA-256:
`6c0d5ace2ea0795b778c68f552bd2d0852ab8848fb26bc213823ce4916bb63a0`.
