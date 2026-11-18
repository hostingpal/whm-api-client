# UAPI PostgreSQL

> Agent contract notes for `uapi()->postgresql()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`uapi()->postgresql()` exposes all 14 pinned PostgreSQL functions for the selected
account: three inventory/restriction GETs and eleven form POST mutations.
`createUser()` returns a nullable integer with no pinned meaning; other mutations
return nullable opaque objects. Preserve explicit null, opaque fields and absent
payload distinctions. `listUsers()` is a string list; database row fields are
optional and `disk_usage` is integer bytes. Restrictions have an optional string
prefix, not a pinned nullable prefix. Password arguments remain sensitive.
Rename methods use oldName/newName, with a new password for `renameUser()`;
`renameUserNoPassword()` requires a subsequent password change per upstream.
`updatePrivileges()` synchronizes the whole selected account. Preserve no-rollback,
prefix, PostgreSQL-role and version caveats in the overlay, then regenerate.
