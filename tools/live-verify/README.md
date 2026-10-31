# Live contract evidence

This development-only PHP command exercises the generated client on the approved
test server. Its default read-only mode performs 67 GET requests across 37 operations, with exact route
and argument guards, verified TLS, redirects
disabled, and no retries. The core package has no dependency on this tool.

| Route | Reviewed operations and arguments |
| --- | --- |
| WHM | `version`, `systemloadavg`, `listaccts(search: 'npr', searchtype: 'user', searchmethod: 'exact')`, `accountsummary(user: 'npr')`, `get_domain_info`, `php_get_installed_versions`, `php_get_system_default_version` |
| Direct and WHM-bridged UAPI, account `npr` | `DomainInfo/list_domains`, `DomainInfo/primary_domain`, `LangPHP/php_get_installed_versions`, `LangPHP/php_get_domain_handler(type: 'home')`, `Email/list_pops`, `Email/list_mail_domains`, `Email/list_domain_forwarders`, `Email/count_pops` |
| Direct and WHM-bridged UAPI, account `npr` | `SSL/installed_hosts`, `SSL/get_autossl_excluded_domains`, `SSL/get_autossl_problems`, `SSL/get_autossl_renewal_status(domain: 'npr.rs')`, `SSL/is_autossl_check_in_progress`, `SSL/can_ssl_redirect` |
| Direct and WHM-bridged UAPI, account `npr` | `Quota/get_quota_info`, `Quota/get_local_quota_info` |
| Direct and WHM-bridged UAPI, account `npr` | `Mysql/list_databases`, `Mysql/list_users`, `Mysql/get_restrictions`, `Mysql/get_server_information` |

The completed DomainInfo, Features and LangPHP modules add both routes for
`domains_data(format: hash, hide_temporary_domains: 0, return_https_redirects_status: 0)`,
`single_domain_data(domain: npr.rs, hide_temporary_domains: 0, return_https_redirect_status: 0)`,
`get_feature_metadata`, `has_feature(name: autossl)`, `has_features_like(pattern: mail, is_regex: 0)`,
`list_features_like(pattern: mail, is_regex: 0)`, `php_get_vhost_versions`,
`php_ini_get_user_basic_directives(type: home)`, `php_ini_get_user_content(type: home)`,
and `php_ini_get_user_paths`. INI text remains in memory and is captured only as its structural type.

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

### Mail protection reads and disabled-mailbox fixture

The separate `mail-protection.php` catalog captures 19 GET observations: WHM
version plus nine reads through direct and bridged UAPI. It covers BoxTrapper
email-template names, four EmailAuth validators for `npr.rs`, two SpamAssassin
reads and two cPGreyList reads. It does not alter the default catalog.

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/mail-protection.php --output=docs/api/evidence/mail-protection-new.json
```

The separate BoxTrapper recipe creates one fresh mailbox per route, with welcome
email disabled and an explicit finite quota. Both routes must first confirm the
mail domain and absence of both candidates. Ownership requires the original
validated creation response to identify the exact login and a successful inventory
to confirm it. An uncertain creation is never adopted for cleanup.

```sh
WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 \
WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/boxtrapper-section.php --output=docs/api/evidence/boxtrapper-section-new.json
```

The nominal recipe captures 45 ordered observations across 14 BoxTrapper
functions, four Email functions and WHM version. It confirms BoxTrapper starts
disabled, explicitly keeps it disabled, writes conservative configuration and
allow/block/ignore rules, and reads them back. It also reads the verify template,
template names, an empty log and empty queue, then checks disabled status again.
It never enables protection, forwards mail, changes templates, processes queued
messages or clears Spam Boxes. It establishes no mail-delivery or filtering effect.

A finally block deletes only confirmed owned mailboxes once and checks that both
candidate addresses are absent. A lost creation or deletion response remains
uncertain even if a later inventory is empty. The protected structural artifact
retains every original failure; strict assessment requires all phases, successful
outcomes and expectations, consistent provenance, verified cleanup and zero
discrepancies. No discrepancy acknowledgments apply. Actual identities, passwords,
rule content, templates and upstream error text are not saved.

Both commands require committed effective inputs, both tokens, exact `npr` /
`npr.rs` selectors and a new output filename. If publication fails after writing a
complete report, the command retains the protected temporary artifact and reports
its path. Uncertain resource recovery requires private inspection and confirmation
of this run's exact mailbox identity; a `wbt` prefix alone proves no ownership.
The broader generated API surface and indexed SpamAssassin preference-input gap
(`whm-api-client-8x0.1.20`) remain separate from these bounded observations.

The [corrected 19-GET capture](../../docs/api/evidence/2026-10-30-mail-protection-corrected.json)
at `a063db0` passes API/payload checks and retains two strict PTR unknown-field
findings. The [final BoxTrapper capture](../../docs/api/evidence/2026-10-30-boxtrapper-section-final.json)
at `3ea2166` passes all 45 observations and strict assessment with zero
discrepancies and both mailboxes cleaned. Initial failed captures remain intact.
Those initial captures cover 22 of the 47 new functions; other operations and variants
remain under `whm-api-client-8x0.2.23`.

### Required-score restoration fixture

`spam-preference-section.php` verifies the reviewed `required_score` correction
on the designated `npr` account. The shared `SectionCommand` handles authorization,
committed-input checks and protected, new-only publication for this fixture and
the Email and BoxTrapper entrypoints; each fixture retains its own request guard,
recipe and cleanup logic.

```sh
WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 \
WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/spam-preference-section.php --output=docs/api/evidence/spam-preference-new.json
```

Both routes must first return the same complete preference object. The original
threshold must be absent or a single numeric string that can be restored exactly.
Preflight also refuses floating-point values anywhere in the preference tree,
including oversized JSON integers decoded as floats, because their exact original
value cannot be established after PHP decoding. This is a fixture boundary; the
generated API contract is unchanged.
The fixture sets one different threshold and reads it back through each route,
then restores the exact original value (or removes only `required_score` when it
was originally absent). Both routes confirm the complete original preference set
after restoration, including all unrelated settings. Only the restoration POST
reply may retain an empty `required_score` list for original absence; getters
must still show the field absent and every unrelated setting unchanged. Original values and custom
key names stay in memory; the artifact contains sanitized structure only.

A dispatched mutation is never repeated. Cleanup sends one distinct restoration
request even after an accepted-outcome payload failure or transport uncertainty;
uncertainty remains a failure even if later reads match. Any phase failure halts
further threshold changes. The nominal capture has 31 observations: two preference
preflights, ten set/read/restore/confirmation observations, and one final 19-GET
mail-protection catalog. The threshold phase and restoration are assessed
separately from strict catalog discrepancies. Known PTR findings remain failures
and receive no waiver. This recipe does not replay the BoxTrapper mailbox fixture
or establish filtering or delivery enforcement.

The [correction capture](../../docs/api/evidence/2026-10-30-mail-protection-review-final.json)
at `6f3e0cb` stopped after 26 observations (24 GET, two POST), all successful at
HTTP/API/payload level. Removal returned an empty `required_score` list, failing
the exact reply expectation against original absence. Both restoration reads and
both final catalog reads matched the complete original state. The artifact keeps
its failed assessment and direct `uncertain` / bridge `not_started` states; the
bridge write did not run. `8x0.2.23.1` tracks reviewer assessment before any further
mutation. The two PTR strict findings remain unwaived. This adds direct preference
mutation execution to committed coverage (23/47), without claiming full fixture
completion or bridged mutation evidence. Do not rerun to clear these findings.

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
the 67 catalog probes plus two `get_privileges_on_database` GETs bound to the
linked pair, for 69 read observations across 38 operations. It then removes only its own exact resources
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

The 67 baseline catalog probes use their existing discrepancy policy.
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

`ProbeCatalog::readOnlyUapiPair()` binds one explicit generated-method callback
to direct UAPI and the fixed `npr` WHM bridge, in that order. The original pilot
covered two MySQL inventory operations; the helper also binds reviewed arguments
for later read slices. Module/function names and exact argument values remain
code-owned, and both probes pass through the strict recipe checks. Payload
expectations stay in independently authored tests.

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


## Complete PostgreSQL section

The separate `postgresql-section.php` command exercises the generated PostgreSQL
module on the designated `npr` / `npr.rs` test account. It requires committed
inputs, both tokens, PHP cURL, and both live opt-ins:

```bash
WHM_API_LIVE=1 WHM_API_LIVE_MUTATIONS=1 \
WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/postgresql-section.php --output=docs/api/evidence/postgresql-section.json
```

The command writes only a new output file, atomically with mode 0600. If atomic
publication fails after capture, it preserves the complete protected temporary
report and prints its JSON-escaped recovery path; do not repeat operations to
recover evidence. It captures
WHM version and PostgreSQL database inventory, user inventory and restrictions
through both UAPI routes before any mutation. Failed preflight or nonempty
inventories stop the lifecycle and produce failed evidence; a preflight refusal
never counts as successful mutation or cleanup coverage.

Each route then owns a fresh database and user after confirmed API creation.
The recipe exercises grants, renames, password changes, account-wide privilege
synchronization and revocation before deleting its resources and checking all
original and renamed identities for absence. An inventory check immediately
before synchronization refuses unrelated databases or users. Renaming without
a password is followed immediately by setting a new password. Uncertain creation
is never adopted; ambiguous rename identities are not deleted. An absent inventory
cannot resolve an uncertain operation that could still complete later; the state
remains uncertain and subsequent lifecycle work stops. No mutation is
retried. The transport verifies TLS, disables redirects and has bounded timeouts.

Observations retain exact phase, route, actual method, server version, revision
and contract hashes. The original response supplies only structural types and
masked paths; names, passwords, raw payloads and upstream error text are not
persisted. The strict assessment accepts no discrepancies or missing phases and
does not inherit baseline acknowledgments. Offline fixture tests use independently
authored server responses. API readback does not establish SQL authentication,
SQL privilege enforcement or privileges on newly created tables.


The [2026-10-30 PostgreSQL capture](../../docs/api/evidence/2026-10-30-postgresql-section.json)
is **failed preflight evidence**: the WHM version check passed, but all six
PostgreSQL reads returned API failures. The lifecycle stopped before writes, and
all resources are `not_started`. It establishes no successful PostgreSQL payload
or mutation coverage and stays unchanged.

After the reviewer's owner-requested PostgreSQL 13.23 installation, the separate
[enabled-service lifecycle](../../docs/api/evidence/2026-10-30-postgresql-section-enabled.json)
was captured once at committed integrated code `61a9f3e` on cPanel `11.138.0.19`.
All 57 observations (33 GET, 24 POST) pass across all 14 functions on both routes,
with zero discrepancies and a passing strict assessment. Both databases and both
users are cleaned, and original/renamed identities are confirmed absent. All
mutation payloads are explicit null; non-null payload alternatives remain covered
by offline fixtures. This run closes `whm-api-client-8x0.2.14`. It establishes
API execution and readback, with the SQL authentication/enforcement and new-table
grant-synchronization limits above. The installation was an earlier owner-requested
server change, not part of this lifecycle recipe.

A short or failed initial temporary-file write has no complete recovery file;
that separate resilience improvement is tracked in `whm-api-client-8x0.2.13`.

When a present payload fails every alternative of its effective root union, the
record also includes optional `payload_alternatives`. Each entry identifies the
zero-based alternative `index`, its `sha256` (SHA-256 of the JSON-encoded checked
type spec), and a separate structural `paths`/`discrepancies` analysis. These
candidate analyses use the same original response, expose only that checked
alternative's static field names, and wildcard unknown descendants. They do not
select an alternative, accept a payload, or change the primary opaque union
fingerprint, probe outcome, or discrepancy gate. They cannot recover field names
from older masked artifacts; further diagnosis requires a new reviewed run.

## Reviewed DomainInfo, Features and LangPHP read-only capture

The [reviewed integrated capture](../../docs/api/evidence/2026-10-30-domain-features-php-reviewed.json)
ran the exact 67-probe GET-only catalog once at committed code `27abfe6` after
normal integration of the reviewed MySQL, FTP and PostgreSQL sections. All 67
HTTP/API/payload observations pass on `11.138.0.19`, including both mailbox counts.
The command exits 1 for strict review: 12 unknown-field entries across 10
unexpected observations, zero acknowledgments and two stale Quota acknowledgments.
Historical artifacts remain unchanged; findings are not waived or retried away.
This run includes no PHP mutation. INI write/read behavior and the omitted
empty-string userdirprotect variant retain their offline/documentation limits.

## Owned Email mailbox recipe

`php tools/live-verify/email-section.php --output=PATH` requires committed inputs,
PHP cURL, both test tokens, `WHM_API_LIVE=1`, `WHM_API_LIVE_MUTATIONS=1`,
`WHM_API_LIVE_ACCOUNT=npr`, and `WHM_API_LIVE_DOMAIN=npr.rs`. The output must be
new; complete reports survive a publication failure in a protected recovery file.

The recipe performs WHM version and both routes' mailbox/domain/forwarder/MX/quota
preflight reads, then uses a fresh address for each route. Its v2 recipe has 55 planned responses
covering 17 Email functions: list_pops, count_pops, list_mail_domains,
list_domain_forwarders, list_forwarders, list_mxs, get_default_email_quota,
get_max_email_quota, add_pop, get_pop_quota, passwd_pop, verify_password,
edit_pop_quota, trace_delivery, suspend_login, unsuspend_login and delete_pop. It creates a 32 MB
mailbox with welcome mail disabled, checks its returned identity and inventory,
changes/verifies the password, changes quota to 64 MB with readback, then sets
quota 0 and requires exact MB `unlimited` and byte integer `0` through both routes.
It traces only that run-owned mailbox and a run-bound missing address on npr.rs,
without sending mail, verifies login suspension/unsuspension, deletes each owned
mailbox once and confirms exact absence. The same two resources cover finite and
unlimited quota behavior; no additional mailbox is created.

No welcome message, client-configuration email, forwarding or delivery test is
sent. Existing mailboxes and account-wide settings are not changed. An uncertain
creation is never adopted or removed; successful original creation establishes
ownership even if its payload later fails validation. Every confirmed owned
mailbox is cleaned on failure, with one deletion attempt and final absence checks.
The original outcome, typed validation and fixture expectation remain separate.
The strict report accepts no discrepancy acknowledgments and persists only
structural response evidence and fixed lifecycle labels, never passwords, email
addresses or upstream error messages. It does not establish IMAP/SMTP behavior or
live coverage of all 96 generated Email functions.

For get_pop_quota only, an optional scalar_encoding diagnostic classifies the
same captured response into five fixed categories: canonical-integer,
integral-fixed-point, fractional-fixed-point, other-numeric or other. Fixed-point
labels describe syntax, not bounds or accepted compatibility. No value, length or
unknown key is retained, and the diagnostic never changes validation or the gate.


The [reviewed Email v2 capture](../../docs/api/evidence/2026-10-30-email-section-reviewed.json)
contains all 55 phases on `11.138.0.19` at `205079c`, with all HTTP/API/payload
checks passing, zero discrepancies and both mailboxes cleaned. The exact unlimited
MB/byte expectations and both local trace targets pass through both routes.
Its command exits 1: direct `resumed_mailboxes` fails the combined target-identity /
zero-suspension predicate, leaving 54 passed, one failed and zero unchecked
expectations. The masked structure matches the earlier successful capture and
cannot distinguish the failing value or establish a cause. `8x0.2.18.1` remains
open and the fixture was not rerun. The later [reviewer investigation](https://github.com/hostingpal/whm-api-client/pull/31#pullrequestreview-5715451604)
confirmed stale API suspension flags despite correct server lock state and judged
the finding nonblocking, without waiving it or changing the failed artifact.

## Owned SSL storage recipe

`php tools/live-verify/ssl-section.php --output=PATH` requires committed inputs,
PHP cURL and OpenSSL, both test tokens, `WHM_API_LIVE=1`,
`WHM_API_LIVE_MUTATIONS=1`, `WHM_API_LIVE_ACCOUNT=npr`, and
`WHM_API_LIVE_DOMAIN=npr.rs`. The output must be a new path. Complete structural
reports are retained in a protected recovery file if new-only publication fails.

The recipe generates independent RSA-2048 private keys and self-signed npr.rs
certificates for the two routes, retaining PEM contents only in process memory.
It captures WHM version and key/certificate inventories through both routes before
any upload. Comparable IDs, public moduli and friendly names must establish that
neither fresh resource exists. An absent or null RSA modulus is accepted only
with the pinned non-RSA identity: `key_algorithm` is `id-ecPublicKey`, the curve
is `prime256v1` or `secp384r1`, and `ecdsa_public` is nonempty hexadecimal text.
Ambiguous RSA or unknown identities stop preflight before uploads. This check
classifies unrelated ECDSA inventory; it does not validate the elliptic-curve
point cryptographically or adopt that resource. The recipe uploads each new key and certificate, fetches
the matching key and CA bundle, reads the owned certificate by exact ID and
retrieves certificates for the designated npr.rs domain. It then generates one
additional RSA-2048 key per route and deletes only exact IDs confirmed by
successful original creation responses. Matching public moduli bind returned private keys
without persisting private material. Payload validation failures still permit
cleanup when the successful original response establishes an unambiguous owned ID;
failed or ambiguous creation never adopts an ID from a later inventory.
For generated keys, ownership additionally requires a fully validated successful
original response with a unique new ID and matching returned PEM/public modulus.
The ID and modulus must be absent from prior inventories and all other owned
resources. The label is fresh and route-bound. Lost, unsuccessful or malformed
generation stays uncertain even when a later inventory appears to identify it;
no generated key is adopted or deleted by inference. Generated-key state is
separate from the uploaded key/certificate states.

After an upload proves exact ownership and passes API and typed payload checks,
unknown response fields alone allow the owned-resource lifecycle to continue to
fetch and cleanup. The original unknown-field discrepancies remain in evidence,
and the final strict assessment still fails on every discrepancy. This narrow
continuation rule does not apply to preflight, other inventories, fetch or cleanup;
all other upload discrepancies and unsuccessful API/payload checks stop continuation.

The one-shot guard checks exact opt-ins, account, credentials, routing, fields,
headers, form bytes and multipart bytes. No request is retried. Each confirmed owned ID gets
one cleanup attempt, followed by inventory checks for ID and public-modulus
absence. Uncertain creations remain unresolved and stop later route lifecycles.
Existing unrelated resources are retained. No certificates are installed and no
TLS service certificates, AutoSSL exclusions, SSL defaults, redirects or mail SNI
configuration are changed. Successful storage calls do not establish HTTPS or
trust behavior.

The expanded v2 recipe plans 31 original observations: version, four initial
inventories, and thirteen lifecycle/cleanup observations on each route (13 GET,
18 POST overall). It covers ten SSL functions through both routes and six
separately owned resources. The first
[integrated v2 capture](../../docs/api/evidence/2026-10-30-ssl-section-reviewed.json)
at `d159935` retains 27 responses (13 GET, 14 POST). Both `fetch_cert_info` phases
pass; both `fetch_certificates_for_fqdns` phases fail typed validation for null
`cab`, `validation_type` and `verify_error`, so neither generated-key phase starts.
The four uploaded resources are cleaned, while generated-key states remain
`not_started`. The failed result is preserved under `8x0.4.11.3.4`; it does not
establish generated-key execution or six-resource cleanup. The three narrow
nullable-field corrections preserve the failed capture's original pre-correction
contract. After explicit review clearance, the separate
[expanded final capture](../../docs/api/evidence/2026-10-30-ssl-section-expanded-final.json)
at `1f67a43` on `11.138.0.19` records all 31 responses with passing HTTP/API,
typed-payload, probe and fixture expectations. All six uploaded/generated resources
are cleaned, including generated-key ID and public-modulus absence checks.
Strict status remains failed for six unknown-field observations: upload_cert and
both key-delete phases on each route. The two added generated-key delete phases
match the prior delete_key masked structures/contract; key names/counts remain
unknown and no observation is waived. Follow-up `8x0.2.19` retains that work.
The historical v1 artifact retains its original 23 observations unchanged. Reports keep
structural paths and provenance from `EvidenceRunner`, separate semantic
expectations and resource states, and no private key, certificate, resource ID or
arbitrary upstream error text. Any failed observation, expectation, missing stage,
unknown-field discrepancy or unconfirmed cleanup makes the report fail. A failed
preflight remains failed evidence, not a successful skip. The pinned `upload_key` body-field contradiction remains documented. The final
2026-10-30 fixture at 64d5266 observed successful use of the key field through
both routes on 11.138.0.19; this does not amend the pinned source.

## domain-management read-only section

The standalone `domain-management.php` entrypoint executes its fixed reviewed catalog:
WHM version followed by both UAPI routes for all 13 selected read operations
(27 GET observations). It requires committed source, tools, overlays and pinned
inputs, both test tokens and exact account/domain opt-ins:

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/domain-management.php \
  --output=docs/api/evidence/domain-management-new.json
```

The shared command binds `test.oblak.host`, direct cPanel user `npr` and the WHM
bridge target `npr`. The existing exact-URI GET guard rejects other requests before
HTTP dispatch. It captures each original response once and stores structural
paths/types only. Strict assessment requires every ordered observation to pass
HTTP, API, payload and provenance checks with zero discrepancies; it inherits no
acknowledgments from historical baseline evidence. This read-only recipe does not
exercise the nine mutations. Empty read results establish no populated row-field behavior.

Use a new output path. The command reserves a protected 0600 temporary file before
network I/O and publishes the completed report with a new-only atomic link. If a
destination race or unsupported link prevents publication, the complete protected
report remains at the JSON-encoded recovery path printed to stderr. Partial or
failed initial writes remain covered by follow-up `8x0.2.13`.

Recorded capture: [2026-10-30 domain-management](../../docs/api/evidence/2026-10-30-domain-management.json).
All 27 GET observations passed HTTP, API, payload and probe checks. Strict assessment remains failed for two masked integer unknown-field observations at `$[].*` in `WebVhosts/list_ssl_capable_domains`, one per route. No acknowledgment or new typed field was added. Addon, parked and subdomain inventories were empty. Their row fields, true redirect-status variants and nullable proxy-subdomain variants are not established by this capture. Separate reviewer evidence for seven mutation methods is recorded in the source audit; rename and temporary-domain conversion remain unobserved. See `8x0.2.20`.

## directory-access read-only section

The standalone `directory-access.php` entrypoint executes its fixed reviewed catalog:
WHM version followed by both UAPI routes for all 6 selected read operations
(13 GET observations). It requires committed source, tools, overlays and pinned
inputs, both test tokens and exact account/domain opt-ins:

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/directory-access.php \
  --output=docs/api/evidence/directory-access-new.json
```

The shared command binds `test.oblak.host`, direct cPanel user `npr` and the WHM
bridge target `npr`. The existing exact-URI GET guard rejects other requests before
HTTP dispatch. It captures each original response once and stores structural
paths/types only. Strict assessment requires every ordered observation to pass
HTTP, API, payload and provenance checks with zero discrepancies; it inherits no
acknowledgments from historical baseline evidence. This read-only recipe does not
exercise the four mutations. Empty read results establish no populated row-field behavior.

Use a new output path. The command reserves a protected 0600 temporary file before
network I/O and publishes the completed report with a new-only atomic link. If a
destination race or unsupported link prevents publication, the complete protected
report remains at the JSON-encoded recovery path printed to stderr. Partial or
failed initial writes remain covered by follow-up `8x0.2.13`.

Recorded capture: [2026-10-30 directory-access](../../docs/api/evidence/2026-10-30-directory-access.json).
All 13 GET observations passed HTTP, API, payload and probe checks, with zero discrepancies and a passed strict assessment. Directory records were present, but every captured auth_name and passwd_file was an empty string, and the privacy-user list was empty. The capture establishes no mutation or HTTP-enforcement coverage. Separate reviewer mutation observations are attributed in the source audit. See `8x0.2.22`.

## mime-section read-only section

The standalone `mime-section.php` entrypoint executes its fixed reviewed catalog:
WHM version followed by both UAPI routes for all 6 selected read operations
(13 GET observations). It requires committed source, tools, overlays and pinned
inputs, both test tokens and exact account/domain opt-ins:

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/mime-section.php \
  --output=docs/api/evidence/mime-section-new.json
```

The shared command binds `test.oblak.host`, direct cPanel user `npr` and the WHM
bridge target `npr`. The existing exact-URI GET guard rejects other requests before
HTTP dispatch. It captures each original response once and stores structural
paths/types only. Strict assessment requires every ordered observation to pass
HTTP, API, payload and provenance checks with zero discrepancies; it inherits no
acknowledgments from historical baseline evidence. This read-only recipe does not
exercise the eight mutations. Empty read results establish no populated row-field behavior.

Use a new output path. The command reserves a protected 0600 temporary file before
network I/O and publishes the completed report with a new-only atomic link. If a
destination race or unsupported link prevents publication, the complete protected
report remains at the JSON-encoded recovery path printed to stderr. Partial or
failed initial writes remain covered by follow-up `8x0.2.13`.

Recorded captures: [initial](../../docs/api/evidence/2026-10-30-mime-section.json)
and [corrected](../../docs/api/evidence/2026-10-30-mime-section-corrected.json).
The initial four payload mismatches establish exactly two nullable URL fields;
the corrected capture preserves their null values. All 13 GET observations passed HTTP, API, payload and probe checks with zero discrepancies and a passed strict assessment. The observed null URL fields are preserved. MIME and redirect lists were empty; handler rows were populated. This read-only capture establishes no mutation or HTTP redirect/hotlink enforcement coverage. Separate reviewer mutation and populated-row observations are attributed in the source audit. See `8x0.2.21`.

## Fileman directory inventory

`fileman-section.php` binds the `fileman-inventory-v1` recipe to seven GETs:
WHM version, then `autocompletedir`, `get_file_information` and `list_files`
through both routes. Autocomplete uses the `public` prefix with all three flags
explicitly false. Information and listings use `public_html`, request MIME and
permission fields and leaf-directory checks, and omit hidden entries. Listings
use `types=dir|file`. The account is exactly `npr` on `test.oblak.host`.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/fileman-section.php \
  --output=docs/api/evidence/fileman-inventory-new.json
```

Both tokens and committed inputs are required. The unchanged shared command,
exact-URI GET guard and original-response sanitizer retain structural paths/types
only. Strict assessment accepts no discrepancies or historical acknowledgments.
Use a new output path; publication and protected recovery follow the other
read-only sections. No existing file content is read, and no mutation is sent.
File content, filesystem lifecycle and transcoding remain under `8x0.2.24`.
Multipart upload remains under `8x0.1.19` and is not exposed by this catalog.

The [final Fileman inventory capture](../../docs/api/evidence/2026-10-30-fileman-inventory-final.json)
at `ec442bdea2fcab033d95a8f10b84afc56df8ec42` on `11.138.0.19` passes all seven GET
observations: WHM version and all three inventory operations through both UAPI
routes. HTTP/API/payload/probe checks and strict assessment pass with zero
discrepancies. Directory information and flat listing rows are populated;
`isparent` is omitted in the observed listing rows. The documented object-format
listing alternative remains offline coverage. No file content, mutation,
filesystem permission enforcement or charset round trip is established. Follow-up
`8x0.2.24` remains open. Earlier failure captures stay unchanged. Final SHA-256:
`946ba0512be318c20b51616a4ede7346e069ae1150f97b44c43ee03ea217bf8f`.

### Fileman zero-byte review capture

`fileman-review.php` runs seven GET probes at committed code: WHM version,
autocomplete through both routes, information for one selected existing zero-byte
file through both routes, and its `mail` directory listing through both routes.
Select the same existing file through private read-only metadata inspection first.
No fixture file is created, no content is read, and no mutation is authorized.

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
WHM_API_LIVE_ZERO_FILE='mail/<selected-basename>' \
php tools/live-verify/fileman-review.php --output=docs/api/evidence/fileman-review-new.json
```

The runtime path must be one non-hidden ASCII basename directly under `mail/`.
Exact GET URI guards retain the selected path and account binding; path values
and file contents never enter the artifact. Both information and listing require
the selected basename, exact `/home/npr/mail/` identity, file type, existence flag
and size equal to integer zero or the preserved empty-string marker. Listing
requires exactly one matching row. Callback expectation failures use the existing
conservative `probe_failure` outcome: raw structural evidence and HTTP status
remain available, while successful API/payload outcomes are not asserted after a
failed callback. Such a probe always fails strict assessment. Default inventory
probes and shared capture/publication helpers remain unchanged.

Use a new output path and preserve every prior artifact, including failures. The
read-only capture establishes no content, permission-enforcement or filesystem
mutation coverage, and cannot establish root-owned metadata variants unless such
a row is actually observed.

The [committed review capture](../../docs/api/evidence/2026-10-30-fileman-review-final.json)
at `06001e0` on `11.138.0.19` passes all seven GETs, both-route identity/zero checks
and strict assessment with zero discrepancies. Both information responses contain
the preserved empty-string size. No content was read or resource changed. Earlier
captures remain unchanged; do not repeat the run just for handoff.

## Application-management inventory

The `application-deployment-v1` catalog contains three GET observations: WHM
version and `PassengerApps/list_applications` through direct UAPI and the WHM
bridge for `npr`. Repository/deployment retrieval methods use form POST because their source
declares `x-readonly=false`, and are outside this GET catalog. No registration, enablement,
deployment, repository removal or dependency installation runs.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/application-deployment.php \
  --output=docs/api/evidence/application-deployment-new.json
```

Both tokens and committed inputs are required. Exact URI/method/body guards apply;
structural evidence retains no environment values, application-name keys, paths,
clone URLs, commit messages or log text. Returned URLs are never fetched. Strict
assessment requires every original observation to pass without discrepancies or
acknowledgments. Follow-up `8x0.2.27` owns all unobserved operations and variants.
