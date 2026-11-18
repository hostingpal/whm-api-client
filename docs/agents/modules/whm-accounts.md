# WHM account management

> Agent contract notes for `whm()->accounts()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### Expanded WHM account management

`whm()->accounts()` covers 47 of the 49 pinned functions. The expanded surface
preserves the ten existing methods and adds 23 GET and fourteen form POST methods
for inventories, enhancements, conversion status, quota/bandwidth controls,
password policy, Digest Authentication and document roots. Password-strength
calculation uses POST because its password input is sensitive.

Typed JSON personalization calls remain under `8x0.1.18`. Conversion initiation accepts
`copyMysqlDatabases` as an old-to-new string map with literal `copymysqldb-` prefix;
null or [] copies no databases. Purely numeric keys reject after PHP integer-key
coercion. `moveMysqlDatabases`/`moveMysqlUsers` preserve unsuffixed scalars and use
one-based suffixes for nonempty lists. `massModifyAccount()` retains 50 fields;
MXCHECK-* is excluded after the reviewer's scoped no-effect observation. Its
unchecked result policy returns validated partial/all-failed rows even when outer
result is 0; callers inspect every per-user result and metadata. No retry,
polling, completion, cleanup or rollback is added. Conversion remains unobserved.
`convertAddonGetConversionStatus(jobId: 12)` retains native integer `job_id`;
`jobId: [33, 10, 33]` sends `job_id-0=33&job_id-1=10&job_id-2=33`, including
suffix 0 for singleton lists. Lists must be nonempty, sequential and native
integer-only; numeric strings, floats, booleans and null items fail without
coercion before I/O after metadata reset. The pinned minimum is 1; no new local
range policy or acceptance guarantee for invalid IDs is added. Order and
duplicates remain intact in one GET with an empty body. The pinned prose starts
at 0 and its example at 1; [review 5722427592](https://github.com/hostingpal/whm-api-client/pull/72#pullrequestreview-5722427592)
reports `_parse_job_ids` accepting either numeric base on `11.138.0.19`.
That attributed source inspection supports the explicit zero-based choice;
no multi-job list was observed live. Dynamic job-map keys, string payload
`job_id` and per-job status remain unchanged and separate from API success.
No polling or conversion initiation is added. The cPanel 58+ and Web Server role
requirements remain in generated PHPDoc.

`getMaximumUsers()` preserves the pinned optional `users` integer and the
separately observed optional `maximum_users`, normalizing bounded canonical
integer strings in the latter. Missing fields are not evidence of a zero or
unlimited license limit. `showBandwidth()` normalizes bounded canonical integer
strings for `totalused`, nested `bwusage[].usage` and per-account `limit`. The
source-backed limit literals `unlimited` and `N/A` stay strings, distinct from
numeric zero; their spellings were not established by a live capture. `N/A`
deleted-account rows remain unobserved. `domainUserData()`
normalizes its observed port strings and exact quoted `hascgi` and
`phpopenbasedirprotect` flags. Other numeric fields stay strict. Reported extras
`bwlimited` and `phpversion` remain untyped pending field-contract evidence.

`listAccountEnhancements()` returns an `enhancements` list of records with the
pinned optional `id` and `name` fields. This repairs the source's missing
container type; a populated list remains source/offline evidence. An observed
empty list cannot establish item contracts. Privilege results preserve all 61
named fields and opaque third-party ACL values. Omission and explicit null stay
distinct, with each method's units, versions and defaults in generated PHPDoc.

Key arguments come first in quota, document-root and Digest Authentication
setters. Digest configuration exposes only `enabledigest`; its password remains
sensitive. `untrackAcctId()` requires callers to supply `uid`, `gid` or both;
the signature preserves optional selectors and does not add a combination guard.
`forcePasswordChange()` takes serialized JSON object-map text such as
`{"user1":1,"user2":1}`, not a JSON list. The client form-encodes it once and
does not parse or validate the JSON structure.

Enhancement, quota, password/digest and document-root writes can affect accounts
immediately. API success and conversion status do not establish completed
migration or hosting behavior. The client adds no retry, rollback, polling,
purchase or notification. A separate reviewer lifecycle on `11.138.0.19`
exercised six account-scoped POST methods and removed its disposable account and
home directory. Enhancement writes, `untrackAcctId()` and the login-link read
remain unobserved; these reports do not expand the committed GET captures.

`setPrimaryDomainDocroot()` requires an existing target directory according to
the pinned contract, but the reviewed server accepted a missing directory and
returned success. Ensure the target exists before changing the root; API success
is not an existence check and does not establish that the site can serve files.

The immutable nine-GET catalog reads version, user counts, disk usage, home roots,
enhancement/user inventories, privileges and quota availability. The corrected
capture at `af701b1` on `11.138.0.19` passes HTTP/API/payload/probe checks but stays
strictly failed for a masked numeric-string unknown-field observation in
`get_maximum_users`, with pinned `users` absent. The wildcard identifies neither
a key name nor a field count. The separate reviewer observation of
`maximum_users` supports its current contract; it does not identify or rewrite
the historical masked finding. Both original artifacts remain unchanged and
unwaived. No new live call or capture was run by the correction agent; the
separate reviewer lifecycle is described in the source audit. Remaining live
behavior is tracked in `8x0.2.41`; empty-container evidence ambiguity remains
`8x0.2.50`.
