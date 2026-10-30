# Live contract evidence

This development-only PHP command exercises the generated client on the approved
test server. Its default read-only mode performs 47 GET requests across 27 operations, with exact route
and argument guards, verified TLS, redirects
disabled, and no retries. The core package has no dependency on this tool.

| Route | Reviewed operations and arguments |
| --- | --- |
| WHM | `version`, `systemloadavg`, `listaccts(search: 'npr', searchtype: 'user', searchmethod: 'exact')`, `accountsummary(user: 'npr')`, `get_domain_info`, `php_get_installed_versions`, `php_get_system_default_version` |
| Direct and WHM-bridged UAPI, account `npr` | `DomainInfo/list_domains`, `DomainInfo/primary_domain`, `LangPHP/php_get_installed_versions`, `LangPHP/php_get_domain_handler(type: 'home')`, `Email/list_pops`, `Email/list_mail_domains`, `Email/list_domain_forwarders`, `Email/count_pops` |
| Direct and WHM-bridged UAPI, account `npr` | `SSL/installed_hosts`, `SSL/get_autossl_excluded_domains`, `SSL/get_autossl_problems`, `SSL/get_autossl_renewal_status(domain: 'npr.rs')`, `SSL/is_autossl_check_in_progress`, `SSL/can_ssl_redirect` |
| Direct and WHM-bridged UAPI, account `npr` | `Quota/get_quota_info`, `Quota/get_local_quota_info` |
| Direct and WHM-bridged UAPI, account `npr` | `Mysql/list_databases`, `Mysql/list_users`, `Mysql/get_restrictions`, `Mysql/get_server_information` |

Unlisted optional arguments are omitted. The Email probes observe default
behavior; explicit flags, filters and selection behavior are covered separately
by independent offline tests. Empty lists establish no item-field observations.
The SSL recipes inspect account state; the renewal-status function requires cPanel
134 or later. Certificate PEM, domain/IP/path values, issuer/subject text and arbitrary
AutoSSL problem messages remain in memory and are never saved as evidence.

```sh
# Export both OBLAK_TEST_WHM_KEY and OBLAK_TEST_NPR_KEY without printing them.
WHM_API_LIVE=1 composer live:verify -- --output=docs/api/evidence/read-only.json
```

Run from a committed checkout after `composer check` and `composer api-map:check`.
Runtime code, tools, Composer files, pinned sources and overlays must match the
recorded commit, including untracked files. Evidence files themselves may be new.
Ordinary Composer checks remain offline; this command is never part of CI.

MySQL inventory recipes take no arguments and only list the selected account's
databases and database users. Evidence retains structural types, not database or
user names. Empty lists provide no observations of item fields.
The two provisioning metadata reads also take no arguments. Their optional fields
remain optional, and a null database prefix remains distinct from an omitted one.
Hostnames, database-server versions and prefixes are retained only as structural types.

### Complete FTP section fixture

`ftp-section.php` is a standalone opt-in recipe for the generated Ftp group. It
requires committed effective inputs, both tokens, and the designated account and
domain. It does not change the default 47-probe catalog or its Quota policy.

```sh
WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 \
WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/ftp-section.php --output=docs/api/evidence/ftp-section-new.json
```

Use a new output filename. The command records sanitized structure from each
original response and refuses to overwrite an existing artifact. The strict
assessment requires all 41 ordered phase observations: WHM version and 20 phases
per UAPI route, covering 16 Ftp functions. It accepts no discrepancy acknowledgments.

The [2026-10-30 corrected-contract capture](../../docs/api/evidence/2026-10-30-ftp-section-corrected.json)
passed all 41 API/payload/expectation observations and both cleanups, but exits 1
because both disk inventories have undocumented string fields at `$[].*`.
The strict assessment remains failed; `8x0.2.11` tracks that contract investigation.
The [initial capture](../../docs/api/evidence/2026-10-30-ftp-section.json) separately
preserves the finite-integer quota mismatch that justified `getQuota(): int|string`.

Each route uses an independent random FTP username. The recipe checks absence,
creates that user, checks its inventory/existence/unlimited quota, updates its
password and home-directory spelling, sets a 17 MB quota, reads back the quota
and disk inventory, deletes the user, and confirms absence. The `.` and `./`
home arguments refer to the existing cPanel home directory; cleanup always sends
`destroy=false`, so it never deletes directory contents. This proves the home
setter's execution and readback, not a change in filesystem isolation. Quota and
password results do not establish FTP authentication or transfer enforcement.

The guard permits only the exact phase, method, URI, credentials, headers and
body once. Failed or uncertain creation never establishes ownership, even if a
matching account later appears. A confirmed successful API creation can be
cleaned up after a payload mismatch. Cleanup never retries a mutation, and a
successful delete still requires an independent inventory confirming absence.

If publication fails after the complete report is written, the command keeps its
0600 temporary file and prints its path on stderr. Retain that file as the
sanitized failure artifact. When a resource is `uncertain`, inspect the
designated account privately and identify this run's
`wftp`-prefixed account before manual recovery; the prefix alone does not prove
ownership. An uncertain creation stays uncertain even if the final inventory is
empty, because the server may finish creating it afterward. Raw responses,
passwords and actual resource identities remain out of the artifact. Do not rerun the fixture to recover the original response or
reuse a previous run's account. API outages and failed cleanup require private
inspection; a failed assessment is not a successful verification.

`kill_session`, `set_anonymous_ftp`, `set_anonymous_ftp_incoming` and
`set_welcome_message` are generated and offline-tested but deliberately unobserved
in this recipe (follow-up `whm-api-client-8x0.2.10`). It neither opens FTP sessions
nor changes account-wide settings.
Inventory captures use `include_acct_types=sub`; the report declares
`sub_rows_only`, and main, anonymous and log-access rows are outside the saved
artifact evidence. Empty session responses provide no evidence about session
row fields. Disabled FTP roles, other daemons and other versions remain
separate evidence cases.

### Nonempty MySQL fixture

The separate `--mysql-fixture` mode temporarily creates two databases and two
database users on the designated `npr` account, with one pair linked by `SELECT`
privileges and one unlinked pair. The `mysql-npr-linked-unlinked-v2` recipe runs
the 47 catalog probes plus two `get_privileges_on_database` GETs bound to the
linked pair, for 49 read observations across 28 operations. It then removes only its own exact resources
and verifies their absence. It requires an additional mutation opt-in:

```sh
WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 composer live:verify -- \
  --mysql-fixture --output=docs/api/evidence/mysql-populated.json
```

The recipe uses the existing cPanel token for `npr`; it never creates or deletes
the cPanel account. Proposed resource names must be absent before any mutation.
Setup and cleanup use generated UAPI form POST calls for the pinned
`Mysql/create_database`, `create_user`, `set_privileges_on_database`,
`delete_user` and `delete_database` contracts. The linked database/user pair is
created and deleted directly; the unlinked pair uses the WHM bridge. The linked
pair receives explicit `SELECT` privileges once through each route. This second
assignment verifies bridge execution and response handling, not a second observed
privilege change. Passwords and resource names remain in memory. Exact
operation, account, name and argument guards reject unrelated requests.

The four MySQL probes must observe the created records, documented fields,
linked nested lists and unlinked empty lists in their original responses. Positive
disk usage is not required. Setup/cleanup inventory reads reconcile resource
ownership; they do not replace or replay a probe to recover its evidence.
The two privilege reads must each return exactly `["SELECT"]`; missing, additional
or duplicate observations fail the fixture. Each URI and generated callback binds
the run's exact database/user names through the existing GET-only request guard.
Original API, payload and discrepancy outcomes remain recorded when that fixture
expectation fails. This verifies API-reported privileges, not SQL enforcement.
An uncertain mutation outcome is reconciled without retrying the mutation.
Cleanup continues for other owned resources after a failure and requires confirmed
absence before reporting success. Preexisting resources are never removed.

Evidence is written after cleanup and includes a separate `mysql_fixture` report
with fixed lifecycle outcomes, without names, passwords or upstream error text.
The `mysql_mutations` section retains ten original-response observations: five
operations through both routes, including deletion during cleanup. Pinned source
variants remain `#GET`; the actual method is `POST`. Successful responses may be
null or opaque objects because the upstream schemas declare no object fields.
All object keys are masked recursively in evidence. A payload mismatch after an
accepted mutation remains recorded before cleanup; the mutation is never retried.
The version and shared provenance are checked against the same run's baseline.

Exit 0 requires the baseline assessment, mutation assessment and fixture report to
pass. Mutation assessment accepts no discrepancies and rejects missing, duplicate
or unsuccessful calls. A failed setup, missing fixture record or unconfirmed cleanup exits 1 and
remains visible in the report. The default read-only command is unchanged even
when mutation opt-in is present without `--mysql-fixture`.

If cleanup reports `present` or `unconfirmed`, inspect the `npr` account's MySQL
inventory privately in cPanel. Fixture candidates follow `npr_wv<6 lowercase hex>d`
and `e` for databases, and `npr_wv<6 lowercase hex>u` and `v` for users, sharing
one suffix per run. Confirm ownership before manually removing exact resources;
the pattern alone is not proof and must not be used for bulk deletion. A new
runner invocation uses new names and does not recover a previous run's leftovers.

Quota recipes take no arguments and read account totals in MB and local usage in
bytes. They do not compare values from separate requests: usage may change and
distributed account totals can differ. Only field paths and types are retained,
not quota values. Quota total responses currently include unreviewed fields
reported as `$.*` discrepancies. Two exact structural observations are acknowledged
by the development evidence policy described below. Their field contracts remain
unresolved in `whm-api-client-8x0.4.3.1`; acknowledgment does not add them to typed
results or remove their discrepancy records.
The owner accepts the freshly observed integer keys `backup_warning_threshold_pct`
and `under_backup_threshold` as expected extras with unknown semantics. This
decision allows the correction cycle to continue; saved evidence still masks
their names, and the gate still compares structural observations rather than
checking those exact keys. No historical artifact is relabeled.

### Finite Quota fixture

The separate `--finite-quota-fixture` mode uses only the disposable account
`wqfinite` and domain `wqfinite.example.com` on the approved test server. Both
must be explicitly selected, with both live opt-ins:

```sh
WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 \
  WHM_API_LIVE_ACCOUNT=wqfinite WHM_API_LIVE_DOMAIN=wqfinite.example.com \
  composer live:verify -- --finite-quota-fixture \
  --output=docs/api/evidence/finite-quota.json
```

The account, domain and DNS zone must be absent before creation. The recipe
creates an account with a finite 64 MB disk quota, no shell or dedicated IP,
and no plan override or forced DNS replacement. Through the WHM bridge it
creates a temporary read-only cPanel token with a one-hour expiration; token
expiration does not replace cleanup. Setup confirms integer `readonly: 1` and
the exact requested expiry, accepting its observed decimal-string representation
as well as the documented integer. The token and any raw response data remain
in memory. Exact guards restrict every request to this recipe and its targets.

The 47 baseline catalog probes use their existing discrepancy policy.
Five additional observations—one WHM version request for provenance, plus both
Quota operations on both routes—are stored under `finite_quota`, with the fixed
scenario `finite-quota-disposable-v1` and a separate assessment. Historical `npr`
acknowledgments never apply to that section. Its initial assessment accepts no
discrepancies: unresolved unknown fields remain visible and cause exit 1 even
when the lifecycle and finite-limit checks pass. No policy is widened
automatically. The two fixture modes cannot be combined in one invocation.

Finite checks retain the original generated results only in memory. They require
observed positive total and local limits, nonnegative usage, and coherent
remaining-space and under-limit flags within each response. They do not compare
usage across requests or invent exact floating-point rounding rules. Fixed
`unit_relationships` labels describe how observed local limits relate to the
configured quota; they establish no universal MB conversion. Null or zero local
limits do not count as finite evidence. A positive difference is recorded as
`other`; it cannot establish an exact configured-limit match or distinguish a
delayed update from distributed allocation. The runner does not retry or change
server quota settings to make a check pass. Finite inode limits and exhausted
quota behavior are outside this recipe.

Each run binds account creation to a random contact-email marker held only in
memory. Account reconciliation requires that marker before token work or account
deletion, so a concurrent run cannot adopt another run's account. Preflight and
cleanup select exact account and primary-domain identities from the full account
inventory, then check the manual-domain
inventory, and DNS zones. The upstream manual-domain inventory excludes automatic
service domains; these checks do not claim a complete service-domain inventory.

Cleanup reconciles uncertain creations before removing anything, revokes the
exact temporary token, and continues account cleanup if token cleanup fails.
It removes the run-created account with DNS retention disabled and independently
checks account, domain and DNS-zone absence. No mutation is retried. Evidence is
written only after cleanup; `finite_quota.fixture` reports each outcome using
fixed labels without credentials, names or raw values. Exit 0 requires the
baseline assessment, finite assessment and fixture lifecycle all to pass.
If cleanup remains unconfirmed, inspect the explicitly selected test account and
domain privately and confirm ownership before manual recovery. A rerun refuses
preexisting resources and is not a cleanup mechanism.

## Capture and privacy

The transport observes the same response as the client. Its stream decorator
delegates the original string conversion without eager reads, rewinds or a second
request. Observation therefore precedes JSON decoding, normalization, field
removal and unwrapping while preserving the original stream behavior.

Raw bytes and decoded values stay in memory. Saved fingerprints contain paths and
types, including numeric strings, empty strings, omission, null and empty
containers. Only schema-declared or reviewed static envelope keys survive;
dynamic and unreviewed keys become `*` recursively. Empty lists provide no evidence
about their items. Request URLs, headers, arguments, tokens, raw values and
upstream exception text are never written. Server version is the sole reviewed
response value retained, restricted to a dotted numeric version.

## Interpreting results

Each probe records its canonical operation and source variant, actual method,
route, timestamp, code commit, source hash, map hash, generator tree hash,
overlay hash and effective generated spec hash. `server_version` comes from the
WHM version probe on the same server; it is null if that probe cannot establish it.
The generated spec hash covers the exact JSON encoding of the loaded spec constant.
The overlay digest hashes sorted relative paths and file hashes; see
`RepositoryContext` for the framing. Evidence is historical when these inputs
change; a passing run does not establish other routes, versions or operations.

Three outcomes are separate:

- `api_outcome`: success, failure, or unavailable when no usable outcome was accepted.
- `payload_validation`: passed, failed, or not_checked.
- `probe_outcome`: passed, failed, skipped for an absent recipe, or unsupported
  for a recipe outside the reviewed catalog. Any unsupported recipe prevents all
  network requests in that run.

`response_paths` describes the raw envelope and payload; `payload_paths` describes
the raw data member before unwrapping. Discrepancies compare accepted wire types,
including explicit normalization allowances, rather than normalized PHP types.
Unknown fields, missing required fields and type mismatches are sorted and
deduplicated. Failed or unusable API envelopes retain fingerprints but do not
suggest changes to the success payload contract.

The command atomically writes sanitized JSON with `schema_version: 2`. Probe records
retain their observations; the separate `discrepancy_review` assessment identifies
its policy, tracking issue, immutable reference artifact and SHA-256, plus
acknowledged IDs, unexpected observations and stale acknowledgments. Historical
schema-1 artifacts remain unchanged. The CLI prints the assessment separately
from each probe outcome.

Exit 0 requires successful HTTP/API/payload/probe outcomes for every observation,
no duplicate probe identities, no unexpected discrepancies and no stale
acknowledgments. Empty runs, skipped/unsupported probes and request/payload
failures exit 1. Evidence still exists when probes ran but reported failures.
A passing assessment with acknowledgments is **not a discrepancy-free run**.

### Reviewed structural acknowledgments

`DiscrepancyPolicy` contains only the two reviewed Quota total observations from
`docs/api/evidence/2026-10-16-quota-inventory.json`. It verifies that file's fixed
SHA-256 before any network request and refuses it (including filesystem aliases)
as an output destination. There is no command-line option to create acknowledgments.
Policy changes require code review and independent tests.

Each acknowledgment requires exactly one matching operation, HTTP variant, route,
actual method, server version, pinned source hash and effective generated contract
hash. The **entire** sanitized payload fingerprint and discrepancy set must also
match. This deliberately conservative comparison fails even for otherwise
accepted representation or omission changes in documented fields. Removing an
unknown field also makes the acknowledgment stale; review and remove the policy
entry instead of silently retaining an unused exception. Changes to unrelated
overlays, map entries, timestamps or code revisions do not broaden an acknowledgment.
The new run still records all of its own provenance.

Unknown names remain masked. Different unknown keys, or additional keys with the
same masked paths and types, can produce an identical fingerprint. An acknowledgment
therefore covers a structural observation, **not unknown-key identity or count**.
This policy neither resolves those fields nor guarantees detection of every new
unknown key. It never overrides an unsuccessful API or payload outcome, changes
fingerprinting, or widens the generated contract.

No correction is applied automatically. Review differences, retain independent
synthetic regression tests, and use the overlay/generator workflow for any
justified contract change.

## Adding a reviewed slice

Recipes live in their operation's overlay `probe` object. The runner accepts
only `phase: read-only`, the exact arguments reviewed for that operation,
`expected: success`, and the exact reviewed route list. The code catalog binds each recipe to its generated method
and exact permitted request. New operations require both a reviewed catalog entry
and offline request/response tests; editing an overlay cannot authorize arbitrary
network calls. The MySQL and finite-Quota fixtures above are separately guarded; general
fixture orchestration, broad discovery and automated overlay edits remain outside
this implementation.

### MySQL probe plumbing pilot

`ProbeCatalog::parameterlessUapiPair()` binds one explicit generated-method callback
to direct UAPI and the fixed `npr` WHM bridge, in that order. Only the two MySQL
inventory operations use it. Module/function names remain code-owned, and both
probes pass through the existing strict recipe checks. It accepts no operation
arguments; payload expectations stay in the independently authored tests.

The pilot (`whm-api-client-8x0.10.4`, baseline `7247113`) measures:

| Manual catalog work for the two operations | Before | After |
| --- | ---: | ---: |
| Operation declarations / spec references / generated callbacks | 4 each | 2 each |
| Operation declaration lines | 34 | 14 |
| URI constructions | 4 at call sites | 2 in the shared helper |
| Catalog files to edit | 1 | 1 |

The helper costs 40 lines plus one separator and one import: the catalog grows
by 22 lines overall. Review now covers two operation bindings and one shared
routing implementation. The unchanged MySQL API, MySQL probe and catalog suites
retain 277 cases with 3,303 assertions, including literal request and payload
oracles. This reduces repeated per-operation edits, but does not demonstrate net
line savings or measured review-time savings. Wider adoption remains a separate
decision after this bounded pilot is reviewed.

The expansion's bounded test-helper pilot (`whm-api-client-8x0.4.5.1`) shares
client setup, envelopes and request assertions in `tests/Support/MysqlTestSupport`.
Only MySQL inventory and mutation endpoint tests use it; their expected payloads,
wire parameters and route URIs remain independently authored in each suite.
The inventory file shrinks from 373 to 334 lines with its 196 cases and 1,752
assertions unchanged. The new mutation suite is 258 lines; a constructed copy
with duplicated original mechanics is 294 lines. Including the 67-line helper,
the equivalent combined comparison is 667 versus 659 lines. This avoids three
repeated mechanics and saves eight lines in that comparison, while adding a
shared helper to review. No elapsed review-time saving or broader adoption is
claimed. Wrong authentication and prefix encoding still fail independent tests.

## Complete Mysql section recipe

The standalone section recipe keeps its phase evidence separate from the historical
`--mysql-fixture` and the default read-only catalog:

```bash
WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 WHM_API_LIVE_ACCOUNT=npr \
  php tools/live-verify/mysql-section.php --output=docs/api/evidence/mysql-section.json
```

Both `OBLAK_TEST_WHM_KEY` and `OBLAK_TEST_NPR_KEY` must be exported. Effective source,
overlay and runner code must be committed. The recipe binds `test.oblak.host` and
the designated `npr` account, requires all opt-ins on each request, and makes no
mutation retries. Run only one instance at a time. It captures 21 Mysql
operations through direct UAPI and the WHM bridge, using separate run-owned
resources for each route. Grant/revoke reads check API-reported privileges, not
SQL enforcement; password checks inspect the returned failure list, not SQL login.
Empty database checks, repairs and routine lists establish no populated table or
stored-routine behavior. `updatePrivileges()` affects the entire `npr` test account.

Generated coverage includes all 25 pinned Mysql functions. Four are deliberately
unobserved by this recipe: `add_host`, `add_host_note`, `delete_host` and
`setup_db_and_user`. Host notes do not prove the absence of an authorized host
without a note. Random setup needs an ownership-safe recovery path when the
response containing its generated identities is lost. Offline tests cover these
contracts; no successful live observation is inferred from them.

The report retains phase identities, sanitized original-response structures,
request methods, version and contract provenance. Unknown/dynamic keys are masked;
raw passwords, schema SQL, resource names and upstream error text are not persisted.
Fixture expectations and cleanup remain distinct from payload/discrepancy results.
A failed phase remains a failure even if cleanup succeeds. Historical discrepancy
acknowledgments do not apply to this recipe.

If a run reports uncertain ownership or cleanup, inspect the designated account's
inventory privately before deciding on recovery. The recipe never adopts a name
merely because it appeared after a failed creation. An unsuccessful rename retains
possible original and destination resources; even an accepted rename can leave an
original name behind, which makes cleanup fail. The `npr_ws` name prefix is only a
candidate filter, not ownership proof. Neither the report nor a later rerun is
authorization to delete those candidates. The report deliberately omits exact names;
resolve uncertainty using private server inspection. Successful final inventories
must confirm both original and renamed identities absent.

Access is revoked before rename and the databases are empty, so the recipe does
not observe grant or stored-code recreation. An absent final inventory never
resolves an uncertain creation or rename; those resources remain `uncertain` and
require manual review. If publishing a completed report fails, stderr names its
retained 0600 temporary file. Recover that file privately; do not replay mutations
to recover evidence.
