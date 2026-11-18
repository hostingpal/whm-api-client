# WHM root-access restrictions

> Agent contract notes for `whm()->security()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM root-access restrictions

`whm()->security()` exposes both pinned Security functions from cPanel 108.
`restrictWhmRootAccess()` replaces the root password login allowlist: only listed
sources may log in as root with a password. Include your own source and every
existing pattern that must remain. Existing root sessions are not terminated and
root resellers are not restricted. The API manages
`/var/cpanel/authorized_whm_root_ips`; upstream warns that direct file modification
with improper formatting can remove all cPanel and WHM root password access.

Client policy accepts one CIDR string or a nonempty sequential string list and
sends repeated `cidr` fields in a form POST body, encoded once without changing
values or locally validating CIDRs. The pinned CLI/URL examples establish repeated
keys only; the external reviewer reports repeated-form support in
`Cpanel::Form::Param`.

Source-based findings from the reviewer's inspection of
`Whostmgr/API/1/RootIPAccess.pm` and `Utils.pm` on `11.138.0.19`: missing input
raises `MissingParameter`; blank values, including list members, raise `Empty`.
Each CIDR passes `Net::CIDR::cidrvalidate` before the file is written, so one invalid
value leaves existing restrictions unchanged. Successful responses contain a
reduced CIDR list; `Net::CIDR::cidradd` can merge ranges. These findings are
reviewer-inspected implementation evidence, not locally pinned Perl or live
verification. They do not specify exact serialized server error messages.

The pinned/effective restriction payload remains an object with an optional
`cidr` string list; absent/null data fails. Offline schema-compatibility fixtures
for omitted fields and empty containers do not represent source-success examples.
`allowAllWhmRootAccess()` removes the restrictions and returns void. Both methods
send one request, with no automatic retry or rollback; a payload error after API
success does not undo the change. No SSH/firewall behavior is inferred.

No live root-access, allowlist, connectivity or session change ran. Both methods
remain unobserved under `8x0.2.59`.
