# Live-verify recipes and evidence state

> Recipe-specific state for `tools/live-verify/`. Moved verbatim from the `Generated-client delivery`
> section of `AGENTS.md` on 2026-11-17. The general evidence rules stay in
> [AGENTS.md](../../AGENTS.md#generated-client-delivery); live policy is in
> [docs/live-testing.md](../live-testing.md).

- Follow the [current delivery design](../superpowers/specs/2026-10-14-generated-client-design.md#first-evidence-slice-and-next-batch).
  The development-only runner in `tools/live-verify/` captures 67 reviewed
  read-only route-specific probes across 37 operations through the generated
  client; run it explicitly
  with `WHM_API_LIVE=1 composer live:verify -- --output=docs/api/evidence/read-only.json`.
  It requires committed code and persists sanitized structure only. Catalog entries
  bind exact arguments, including the `npr` account filters and `type: 'home'` PHP
  handler, plus the `npr.rs` domain for AutoSSL renewal. Account/domain/PHP evidence
  (`8x0.2.3`), expanded Email inventory (`8x0.4.1`), the SSL slice (`8x0.4.2`),
  Quota (`8x0.4.3`) and MySQL inventory (`8x0.4.4`) build on the initial slices;
  consumer feedback (`8x0.10.3`) is deferred by the user as of 2026-10-17.
  The correction cycle and bounded pilot completed through PR25, followed by the
  provisioning reads in PR26. Consumer integration remains deferred. On 2026-10-30
  the owner authorized the full Mysql section as one larger dedicated PR.
- The standalone `tools/live-verify/ftp-section.php` recipe requires both live
  opt-ins, exact `WHM_API_LIVE_ACCOUNT=npr` / `WHM_API_LIVE_DOMAIN=npr.rs`, both
  tokens and committed inputs. It targets 41 ordered observations across 16 Ftp
  functions on both UAPI routes plus WHM version, with no discrepancy acknowledgments.
  Each route owns a fresh FTP user only after confirmed API creation. Cleanup
  always sends destroy=false and confirms absence. Home arguments . and ./ refer
  to the existing account home; no directory contents are deleted. Settings and
  session killing remain offline-only; readback does not prove FTP authentication,
  transfer-quota enforcement or a change in directory isolation.
  The corrected-contract 2026-10-30 artifact at cd7bdb6 on 11.138.0.19 passes
  all 41 API/payload/expectation observations and cleanup, but strict assessment
  fails on two list_ftp_with_disk unknown-field observations ($[].*, string).
  Keep their discrepancies; no acknowledgment or typed unknown field was added.
  The contract investigation is 8x0.2.11; deferred live settings/session work is 8x0.2.10.
- The standalone `tools/live-verify/postgresql-section.php` recipe covers the full
  PostgreSQL module on exact `npr` / `npr.rs`, with both live opt-ins, both tokens
  and committed inputs. All six inventory/restriction preflight reads across both
  routes must pass, and database/user inventories must be empty before creation.
  Each route owns fresh resources only after confirmed creation; account-wide
  privilege synchronization requires another inventory bound to those resources.
  Rename-without-password is immediately followed by a password update. Never adopt
  uncertain creations or delete ambiguous rename identities. Capture original
  responses structurally and verify every original/renamed identity absent after
  cleanup. No retries, payload logging, discrepancy acknowledgments or SQL-auth /
  privilege-enforcement claims. Failed preflight remains failed evidence, not a skip.
  The 2026-10-30 artifact at `6539db7` on `11.138.0.19` records seven GETs:
  WHM version passed; all six PostgreSQL reads returned HTTP 200 with API failures.
  No mutation ran and all resources remain not_started in that immutable artifact.
  After the reviewer's owner-requested PostgreSQL 13.23 installation and role/service
  enablement, the separate `2026-10-30-postgresql-section-enabled.json` capture at
  `61a9f3e` on the same cPanel version passes all 57 observations (33 GET, 24 POST)
  for all 14 functions through both routes. Strict assessment passes with zero
  discrepancies; both databases and both users are cleaned, with every original
  and renamed identity confirmed absent. Mutation payloads are explicit null;
  non-null alternatives remain offline-only. This closes live follow-up `8x0.2.14`.
  Keep SQL enforcement/login and new-table grant synchronization unclaimed.
  Optional short/failed temporary-write recovery remains tracked in `8x0.2.13`.
- The optional `--mysql-fixture` runner mode additionally requires
  `WHM_API_LIVE_MUTATIONS=1`. It creates two databases and two database users on
  the designated `npr` account, checks linked/unlinked inventory through both
  routes, and verifies exact-resource cleanup before writing evidence. The
  [populated MySQL artifact](../api/evidence/2026-10-17-mysql-populated.json)
  observes all documented row fields and integer `disk_usage` on `11.138.0.15`.
  That historical run used generic POSTs and establishes no generated mutation
  coverage. The current fixture captures ten generated mutation calls separately
  under `mysql_mutations`: linked resource creation/deletion direct, unlinked
  creation/deletion bridged, and an explicit SELECT assignment through each route.
  The second assignment establishes execution/response coverage, not an independent
  privilege change. Its strict assessment accepts no discrepancies or missing calls.
  The [generated mutation artifact](../api/evidence/2026-10-17-mysql-mutations.json)
  records all ten POSTs passing with explicit null payloads on `11.138.0.15`,
  zero mutation discrepancies, and all four resources confirmed absent afterward.
  The current v2 fixture recipe additionally appends two exact run-bound
  `get_privileges_on_database` GETs to the read observations and requires exactly
  the assigned `SELECT` result through both routes. It reuses the same resources
  and cleanup; API readback is not evidence of SQL permission enforcement.
- The standalone `tools/live-verify/mysql-section.php` recipe requires both live
  opt-ins, `WHM_API_LIVE_ACCOUNT=npr`, both tokens and committed inputs. Its
  [2026-10-30 evidence](../api/evidence/2026-10-30-mysql-section.json) contains
  57 passing phase observations across 21 Mysql functions on both UAPI routes
  plus WHM version on `11.138.0.19`, with zero discrepancies and all original/new
  resource identities absent after cleanup. Guards are phase-bound and one-shot;
  failed/uncertain creation or rename never establishes ownership. Four generated
  operations remain offline-only: remote-host add/note/delete and random setup,
  tracked under `8x0.2.9`. The recipe uses empty databases and checks API-reported
  privileges/password outcomes; it does not establish SQL enforcement or login.
- The optional `--finite-quota-fixture` mode requires both live opt-ins and exact
  `WHM_API_LIVE_ACCOUNT=wqfinite` / `WHM_API_LIVE_DOMAIN=wqfinite.example.com`.
  It captures five separate observations on a disposable finite-quota account,
  then verifies token, account, domain and zone cleanup. Its `finite_quota`
  assessment accepts no discrepancies and never inherits baseline acknowledgments.
  The [finite-Quota artifact](../api/evidence/2026-10-17-finite-quota.json) passes
  finite expectations and cleanup but exits 1 for two unacknowledged unknown-field
  observations. This establishes no exhausted-quota or finite-inode behavior.
