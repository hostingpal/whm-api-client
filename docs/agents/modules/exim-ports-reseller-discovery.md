# Exim ports and reseller account discovery

> Agent contract notes for UAPI Chkservd and Resellers modules. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## UAPI Exim ports and reseller account discovery (2026-11-01)

Delivery `whm-api-client-8x0.4.38` generates complete Chkservd (two) and Resellers
(one) modules from the pinned source. Cached/account-bound/lazy accessors expose
`chkservd()->getEximPorts()`, `chkservd()->getEximPortsSsl()` and
`resellers()->listAccounts()`. All are zero-argument native-array methods, use
one GET on direct and bridged UAPI, and retain ordinary Flag outcome.

Chkservd (cPanel 11.42) returns required nonnull objects with optional
`ports:list<int>`. Preserve omitted versus empty, native integers and order;
getEximPorts also normalizes canonical digit strings (review 5726617674:
the server splits a comma string); SSL ports stay native integers; reject
floats/bools/null. Source SMTP constraints
are 1..65535 and uniqueItems:true; SSL has 0..65535 with no uniqueness declaration.
Existing policy validates types, not general bounds/uniqueness. Resellers (cPanel
11) returns a required nonnull list of optional `domain`, `select`, `user` strings.
Preserve quoted select markers `'1'`/`''`, omission, exact identity bytes and row
order; ordinary-string enums remain unenforced. Numeric/bool markers mismatch.

Missing/null roots mismatch; existing empty-array object compatibility and unknown
field filtering stay unchanged. API/outer-WHM failures precede payload validation;
metadata resets each attempt and records warnings/status before API/payload errors.
Treat results/account/infrastructure data and mismatch payloads as private;
library SensitiveParameter response frames do not promise third-party trace or
payload-field redaction. No implicit login, account switching, probe, URL fetch,
file access, retry, listening/reachability/TLS/email guarantee or permission check
is added. These are offline synthetic contracts; live `8x0.2.82` stays OPEN.

Current totals are 1,234 operations (636 UAPI, 598 WHM), 119 groups and 241 outputs
including coverage. All 55 inherited source diagnostics remain visible/unwaived;
runtime/generator/schema/dependencies/source and historical evidence are unchanged.
