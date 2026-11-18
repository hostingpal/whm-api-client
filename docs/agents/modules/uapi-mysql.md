# UAPI Mysql

> Agent contract notes for `uapi()->mysql()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`uapi()->mysql()` exposes account database and database-user inventory through
`listDatabases()` and `listUsers()` (cPanel 82+). Database `disk_usage` is in bytes;
row fields remain optional and nested user/database lists preserve empty lists.
Both operations are disabled when the MySQL/MariaDB role is disabled and remote
MySQL is not configured.
`getPrivilegesOnDatabase(database, user)` returns a string list (empty when no
privileges are assigned); `getRestrictions()` returns optional name-length limits
and an optional nullable prefix; `getServerInformation()` returns optional host,
integer `is_remote` and version fields. These reads have the same role caveat.
Keep omitted fields distinct from a null prefix and do not infer a closed privilege enum.
The same group generates `createDatabase()`, `createUser()`,
`setPrivilegesOnDatabase()`, `deleteUser()` and `deleteDatabase()` as form POST.
Privilege assignment replaces existing privileges. Mutation results are nullable
objects with no pinned field schema: preserve opaque object fields, explicit null
and the distinction from an absent payload. Do not invent a field contract or
weaken validation to void. Keep password arguments sensitive.
The Mysql group covers all 25 pinned operations. Additional methods cover remote
hosts/notes, table check/repair, schema export, routine listing, host location,
database/user rename, privilege revocation, password update, random setup and
account-wide privilege updates. All pinned mutations use form POST, including
checkDatabase(). Password updates return an object with optional failures, whose
per-host error records contain error and host. The pinned text does not say whether
failures can accompany status 1, so inspect both. Error text and hostnames are payload
data; omitted failures stays omitted. setupDbAndUser() (11.116+)
returns optional credential fields and a string port; treat its result as sensitive.
Rename database warnings can report failure to delete the original database or
grants. Preserve per-method role/version caveats in overlay notes and regenerate.
