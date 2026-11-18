# WHM NGINX cache management

> Agent contract notes for `whm()->nginxManager()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM NGINX cache management

`whm()->nginxManager()` covers all six pinned functions: two configuration GETs
and four form POST writes. Typed writes require explicit nonnullable `users`, as
one string or a nonempty sequential string list. They reject trimmed blanks,
trimmed zero, empty/malformed lists and nonstring entries before dispatch. This
trimmed-zero rule is conservative client policy, not an observed NGINX behavior
or a username validator. Valid strings retain their original bytes.

The pinned upstream omission behavior affects every user on all four writes.
Use narrow typed targets for normal operations; deliberately omitting `user` in a
generic POST is the explicit server-wide form. Generic calls intentionally bypass
typed target validation. `setCacheConfig()` keeps `enabled` first; false sends 0
and true sends 1. The user-configuration read retains optional filtering,
including null for all-user inventory. `getUsersCacheConfig(users, merge)` accepts users first.
Both reads accept only `merge: true` (sends 1) or null/omission (omits merge);
false, integers and strings reject even in weak callers. Native argument errors
retain prior metadata; runner target validation clears it before rejection.

`default.enabled` remains an integer 0|1 flag; `system.enabled` and
`users[].config.enabled` remain strict booleans. Configuration objects preserve
dynamic opaque values. A merged integer enabled variant is an **unobserved risk**
under `8x0.2.51`, not evidence for widening those response contracts.
All four writes return void. The pinned version floor is 11; requests add no
automatic restart, retry or rollback beyond the selected API operation.

The immutable three-GET capture at `2b36d29` on WHM `11.138.0.19` has a passing
version check and two API failures; its strict assessment remains failed and
unwaived. Separately, [PR63 review 5721097412](https://github.com/hostingpal/whm-api-client/pull/63#pullrequestreview-5721097412)
reports both reads failing because the required EA4 `ea-nginx` package is absent.
This reviewer diagnostic does not change the historical artifact or establish a
successful payload. No NGINX method has a successful live payload observation;
all four POSTs remain unobserved. No installation, replay, cache/service change or
new capture accompanied these corrections. Gaps remain under `8x0.2.51`.
