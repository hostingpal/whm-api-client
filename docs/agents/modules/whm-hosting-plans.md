# WHM hosting plans

> Agent contract notes for `whm()->hostingPlans()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### Expanded WHM hosting plans

`whm()->hostingPlans()` exposes 22 of 24 pinned functions, including package
creation, editing, deletion, extension management, matching, application inventory
and feature overrides. The original eight methods and two deprecated exclusions
are retained, with a narrow team-limit response correction in `listPackages()`.
`createFeaturelist()` and `updateFeaturelist()` require explicit `features` bool
maps with literal keys. Empty update rejects locally; omitted update keys preserve
state in the reviewer observation on 11.138.0.19. Empty create still sends the
fixed request and created an all-disabled list in that version-scoped fixture.
Create requires reseller Add/Remove Package permission; overwrite can replace a
list. Updated flags accept native/quoted 0|1 and normalize to integers;
`invalid_features` is a string list to inspect, without an atomicity guarantee.
Both methods use form POST. Source contradictions and reviewer provenance are
recorded separately in the source audit.

Package options preserve exact wire casing, omission, false/zero and literal
`unlimited` values. Quotas use the source's MB units. `editPackage()` changes
accounts using the plan: an omitted `featurelist` assigns the default feature
list to those accounts, and omitted `_PACKAGE_EXTENSIONS` means no extensions.
Omitting an option does not promise to preserve its current value. Read
`getPackageInfo()` first and send the full intended settings. Mailbox-quota edits
do not change existing mailboxes. A package in use cannot be deleted;
`deletePackage()` returns no value. The client performs no retry or rollback.

Feature overrides accept caller-serialized JSON text in form fields, encoded
once, rather than a JSON body or automatically serialized PHP arrays. Their native
arguments are strings; JSON syntax is not validated. Extension names and optional variables are
case-sensitive. `deletePackageExtension()` removes the named extensions and
associated variables; the typed input does not invent arbitrary variable fields.

`getPackageInfo()` retains named fields and source-documented opaque extension
variables, which may contain private configuration. Its observed nullable
`MAXPARK` remains distinct from omission. The twelve resource-limit integer
alternatives use the established canonical-string compatibility family. A later
independent populated-package review observed numeric-string variants and
`MAX_TEAM_USERS` in the three package reads. That team field accepts bounded
canonical integer strings in `listPackages()` and `getPackageInfo()` while
preserving their existing null and omission behavior; matching retains it using
the same family, with null support source-inferred for that read. Other fields
keep their existing contracts.

`matchPackages()` returns a list of records with optional `name`. Its `CGI`
result preserves strings such as the observed `n`, alongside the pinned integer
0/1 alternative; it does not inherit `getPackageInfo()` flag conversion. With
two packages present, a separate reviewer call without selectors returned one
of two; `MAXSQL=5` found the matching package, while `QUOTA=100` (its real quota)
and `QUOTA=99999` both returned none. The cause is unknown. Matching may return
partial inventory or miss a package with the requested value.

`getAvailableApplications()` supports the reviewed omissions in group and item
records, including optional string group descriptions. Null descriptions were
not observed and remain unsupported by the current contract. Present fields still
validate, and native integer order fields remain strict. Unspecified extension
templates may return metadata only; HTML and URLs
remain payload data and are never executed or fetched.

The original two-GET capture at `a5c08af` on `11.138.0.19` strictly passes with
zero discrepancies. Its empty `matchPackages()` result passed the former
empty-object boundary and establishes no populated shape. That immutable
artifact is not rewritten or repeated. Separate reviewer GET reports support the
contract corrections. A later reviewer separately observed package creation,
quota editing and deletion with disposable account/package cleanup; extension,
feature-list and override writes remain unobserved. Remaining behavior and the
matching contradiction stay under
`8x0.2.43`; shared empty-container evidence ambiguity remains `8x0.2.50`.

The requested three-GET capture at `1336c4d` on `11.138.0.19` records successful
HTTP/API/payload/probe outcomes for version, stock-default package information
and the application catalog. Two masked unknown-field observations in application
item/subitem records keep its strict assessment failed and unwaived under
`8x0.2.43`. Wildcards establish neither field identity nor underlying field count.
The capture is complete and must not be repeated merely to clear findings. No
mutation, arbitrary file read, template execution or URL fetch ran.
