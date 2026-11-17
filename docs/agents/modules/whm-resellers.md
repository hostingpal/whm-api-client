# WHM reseller administration

> Agent contract notes for `whm()->resellers()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM reseller administration

`whm()->resellers()` covers all 21 pinned functions: seven reads use GET and
fourteen mutations use form POST. The group manages reseller privileges, ACL
lists, resource/package limits, delegated IPs, nameservers, account enhancements
and lifecycle operations. Each method retains its pinned version and role notes.

`setupReseller()` grants reseller privileges; ownership changes follow its explicit
`makeowner` option. Suspension and restoration retain the `reseller-only` option,
while termination can remove the reseller and owned accounts. Read the individual
method's destructive/default caveats before calling it. The client performs one
request without retry or rollback; API success and account-level outcomes remain
separate. Suspension reasons are sensitive inputs.

ACL save and assignment have different semantics; `acl-all` grants root-level
privileges. `setAcls()` accepts only `true` or omission for each assignment
switch: upstream grants any present `acl-*` key regardless of its value. Native
`?true` parameters reject false, zero, numeric one and strings before dispatch,
including calls from files without strict types. True sends `1`; null omits the
key. The overriding `user` alias is excluded, so `reseller` is the sole typed
target. `saveAclList()` retains boolean flags: false sends `0` and removes that
privilege. Both operations replace the complete set; omitted inline assignment
privileges are removed, and omitted save flags default to zero. Read the current
set before constructing a replacement. Use either a named ACL list or inline
switches, never both; with neither, the inline assignment clears all privileges.

Prefer named arguments. `suspendReseller(user, reason, ...)`,
`setResellerMainIp(user, ip)` and `terminateReseller(user, terminatereseller)`
put resource identifiers first. The pinned `acl-disallow-shell` description
contradicts its wire name; no interpretation beyond that contradiction is
asserted. `setEnhancementLimit(limited: true)` sends `limited=1`, and false sends
zero. `setResellerIps()` defaults omitted `delegate` to one and returns the
`getResellerIps()` shape according to the reviewed source; these writes remain
unobserved. Other omission behavior is documented only where the source supports
it. IP/nameserver lists use their documented serialization; the client does not
infer or validate a new network configuration.

All 21 functions have independent offline fixtures, including every documented
ACL input flag and privilege response field. The bounded live catalog reads WHM
version, default account counts, public contact details, reseller IPs without a user,
ACL lists and reseller names. It supplies no guessed reseller identifier, changes
no accounts, privileges, limits, contact details, IPs or nameservers, and does not
fetch returned contact URLs. Empty inventories do not establish populated row
contracts. Follow-up `8x0.2.36` retains the remaining live behavior and variants.

The corrected WHM reseller capture at `e8751c3` on `11.138.0.19` has six HTTP-200
observations, five API/payload/probe passes, one unchanged `getresellerips` API
failure and zero discrepancies. Strict status remains failed. Account-count
`reseller.limit` preserves `int|null|''` without assigning meaning to empty string;
siblings remain strict. ACL/reseller inventories are empty. No mutation ran,
initial evidence remains immutable, and `8x0.2.36` owns remaining coverage.
