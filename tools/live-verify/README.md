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

## Resource and web statistics

`resource-statistics.php` binds `resource-statistics-v1` to 23 GET observations:
WHM version and eleven statistics reads through both UAPI routes. All account
requests target `npr`, and domain requests use `npr.rs`. The Bandwidth query uses
`domain|protocol`, HTTP only, daily samples, UTC, and fixed timestamps from
2026-10-01T00:00:00Z to 2026-10-31T00:00:00Z. StatsBar selects bandwidth/disk use
with both warning flags false; site errors request at most one Apache log entry.
Webalizer and Analog selectors follow the pinned source, without enabling services.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/resource-statistics.php \
  --output=docs/api/evidence/resource-statistics-new.json
```

Both tokens and committed inputs are required. The existing exact-URI GET guard,
original-response structural sanitizer and new-only protected publication apply.
Dynamic grouping/date/domain keys remain wildcarded; log text and all other
payload values are not persisted. Strict assessment accepts no discrepancies or
historical acknowledgments. No configuration write, report download or analyzer
execution is performed. Failed capability/schema observations remain failed
evidence instead of being skipped or waived.

The corrected [statistics capture](../../docs/api/evidence/2026-10-31-resource-statistics-corrected.json)
at `a439c3c` on `11.138.0.19` records 23 HTTP-200 GETs. Twenty-one API/payload/probe
checks pass with zero discrepancies; both `Stats/get_site_errors` API calls fail,
so strict status remains failed. Ten new functions pass on both routes. Daily
statistics, site/report lists and analyzer lists are empty; populated variants
remain offline coverage. No settings or log content changed. Follow-up `8x0.2.25`
retains the unresolved log errors and other unobserved variants.


## Resource/statistics varied review capture

`resource-statistics-review.php` binds a separate fixed review recipe with 27 GETs:
one WHM version observation and all eleven statistics functions through both UAPI
routes, with three bandwidth queries per route instead of one. Query grouping is
`domain`, `domain|protocol` and `domain|protocol|year`, using the same October 2026
UTC bounds, `npr.rs` domain and HTTP protocol. The mixed StatsBar selection includes
ordinary usage and the reviewed nullable-count/array-role rows. Site inventory
uses `analog`; the one-entry error-log read uses `suexec`. No analyzer, server
configuration, file permission, log content or account setting changes.

The command requires committed inputs, `WHM_API_LIVE=1`, exact account/domain
guards and both test tokens, like the original section command. It writes only to
a new path. Original statistics catalogs and artifacts remain unchanged.

Repeated operation/route pairs use fixed reviewed `argument_variant` labels,
separate from the pinned `variant` (which remains the operation plus `#GET`).
Labels are catalog constants, never request values or upstream data. The guarded
URI still binds each exact request. The strict section assessment requires the
expected label on every labeled record and rejects missing, wrong, reordered or
duplicate labels and mixed labeled/unlabeled repeats. Historical unlabeled
catalogs retain duplicate rejection and their original record shape. No failure
or discrepancy acknowledgment is introduced.

## DNSSEC and Dynamic DNS inventory

The fixed `dns-security-v1` catalog contains five GET observations: WHM version,
`DNSSEC/fetch_ds_records(domain: 'npr.rs')` and `DynamicDNS/list` through direct
UAPI and the WHM bridge for `npr`. It neither guesses key identifiers nor fetches
DDNS update URLs, and performs no DNSSEC, registrar, key or domain mutation.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/dns-security.php \
  --output=docs/api/evidence/dns-security-new.json
```

Both test tokens and committed inputs are required. The exact-URI GET guard
rejects method, body and target changes before dispatch. The report stores only
structural types/paths: private-key strings, DDNS IDs and domain-map keys are
never persisted as values. Strict assessment requires all five observations to
pass without discrepancies or acknowledgments. An unavailable API remains failed
evidence. Follow-up `8x0.2.26` owns the remaining operation and payload variants.

The [DNS security capture](../../docs/api/evidence/2026-10-31-dns-security.json) at
`372df78` on `11.138.0.19` passes all five GET observations and strict assessment
with zero discrepancies. DS detail objects and the DDNS inventory are empty;
populated fields and the other fifteen methods remain offline coverage. No DNS,
key, registrar or DDNS state changed. Follow-up `8x0.2.26` remains open.

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

The [application-management capture](../../docs/api/evidence/2026-10-31-application-deployment.json)
at `344fffc` on `11.138.0.19` records three HTTP-200 GETs. WHM version passes;
both Passenger inventory calls fail at the API layer before payload validation,
so strict assessment remains failed. This is no successful empty-inventory or
payload-schema evidence. All fourteen methods are offline-tested; separate
reviewer lifecycle observations and their limits are recorded in the source audit.
Committed lifecycle evidence remains under `8x0.2.27`. This capture changed no
repository or application state.

## Account environment and preferences

The `account-environment-v1` catalog contains 27 GET observations: WHM version,
twelve parameterless account reads and `getUserInformation(name: 'domain')`, each
account read through direct UAPI and the WHM bridge for `npr`. It covers contact
notification preferences, contact-us availability, locale attributes/inventory,
theme inventory, notification count, enhancement inventory, both EA4 recommendation
reads, server information, Variables server/session information and the user domain.
The documented `name=domain` selector prevents a broad user-configuration request,
which can include `pushbullet_access_token`; both exact URIs require that selector.
Other optional selectors remain omitted. Enhancement lookup by ID, token retrieval
and every state-changing method are excluded. Other user-field live coverage remains
under `8x0.2.28`; the full generated user-information contract stays available.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/account-environment.php \
  --output=docs/api/evidence/account-environment-new.json
```

Both test tokens and committed inputs are required. The exact URI/method/body
guard and structural-only evidence rules remain unchanged. No contact, token,
locale, theme or notification preference is modified; no messages are sent.
Dynamic keys are wildcarded, and server/session/user values are never persisted.
Strict assessment requires all original observations to pass with no discrepancies
or acknowledgments. Follow-up `8x0.2.28` owns unobserved variants and failures.

The corrected account-environment capture at `c85a39e` on `11.138.0.19` passes
24 of 27 GET API/payload/probe checks, with zero known-field type mismatches.
Two EA4 API failures, one EA4 bridge failure and five masked unknown-field
observations keep strict status failed and remain unwaived under `8x0.2.28`.
Eleven functions pass on both routes and general EA4 recommendations pass directly.
No mutation or secret retrieval ran; user information remains domain-only.

## Account-environment broad user review capture

`account-environment-review.php` binds three GET observations: WHM version and
`Variables/get_user_information` on both routes for designated account `npr`.
Its explicit 60-name allowlist selects the pinned user-information fields verified
present on `npr`, excluding `pushbullet_access_token` and the seven names absent
on this account: `lang`, `maximum_defer_fail_percentage`, `maximum_emails_per_hour`, `notify_bandwidth_limit`, `notify_ssl_expiry`, `notify_twofactorauth_change`, `notify_twofactorauth_change_notification_disabled`.
Those seven remain supported by the client; this capture does not establish their
wire forms. One absent selector fails the whole request, as documented in
[review 5720313728](https://github.com/hostingpal/whm-api-client/pull/41#pullrequestreview-5720313728). The list is fixed in the reviewed catalog;
it is never derived from live keys or automatically expanded with a future schema.
Repeated `name` query keys exercise the typed selector without an unfiltered read.

The recipe label is `account-environment-review-v1`; each user read records the
fixed argument variant `reviewed-present-fields-except-token`. The command requires
committed inputs, `WHM_API_LIVE=1`, exact `npr`/`npr.rs` guards and both test tokens.
The shared exact-URI guard rejects added token selectors, omitted selectors,
changed targets, request bodies and mutations before dispatch. Evidence retains
only masked structural types/paths from each original response. No settings,
contact addresses, locale, theme or token changes; no message sending or returned
URL fetch. Original account-environment catalogs and captures remain unchanged.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/account-environment-review.php \
  --output=docs/api/evidence/account-environment-reviewed-new.json
```

The recipe covers broad user-field wire compatibility, not every account state
or mutation. Every discrepancy and failed observation remains a strict failure;
no acknowledgment or retry is added. The requested capture follows review of the
corrected contracts and writes to a new immutable path.


The final [reviewed user-information capture](../../docs/api/evidence/2026-10-31-account-environment-reviewed.json)
at `c4576669980a1ab22952f96b976a34bf0db7be58` on `11.138.0.19` passes all three
GET observations: WHM version and the fixed 60-field user selector on both routes.
All HTTP/API/payload/probe checks and strict assessment pass with zero discrepancies.
The token selector and seven pinned fields absent on `npr` are excluded; no wire
form is established for those seven. No mutation or token retrieval ran. Earlier
EA4 failures and masked findings remain immutable and unwaived under `8x0.2.28`.
Reviewer mutation/lifecycle reports remain separate from this committed read capture.

## WHM backup and restoration inventory

`backup-restoration.php` binds `backup-restoration-v1` to eleven WHM GETs: version,
backup configuration, users/domains with backup metadata, users with backup metadata,
archive inventory, restoration module summary, queue-active status, active/completed/
pending queue lists and combined queue state. Every request is parameterless apart
from `api.version=1`, and uses the existing exact URI/method/body guard.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/backup-restoration.php \
  --output=docs/api/evidence/backup-restoration-new.json
```

The shared command requires committed inputs and both test tokens, although this
catalog sends only WHM requests. Structural-only captures never persist account,
domain, archive path, log or configuration values. Dynamic keys are wildcarded.
No destination/OAuth credential read, backup creation, restoration, queue mutation,
migration, archive deletion or configuration write occurs. Other readonly functions
requiring identifiers are excluded without guessing identifiers. Strict assessment
accepts no discrepancies or historical acknowledgments; failures remain failures.
Follow-up `8x0.2.29` retains all unobserved operations and variants.

The immutable WHM backup/restoration capture at `a5b6e3a` on `11.138.0.19`
records 11 HTTP/API successes and ten payload/probe passes. Its `prebackup`
validation failure and one masked unknown-field observation with integer values
remain strict failures, unwaived under `8x0.2.29`. A wildcard identifies neither
the underlying name nor field count. Backup and queue inventories are empty,
module rows populated, and no mutation ran. Later reviewer observations and
contract corrections are separate from that historical capture.

## Team and user management

`team-user-management.php` binds `team-user-management-v1` to nine exact GETs:
WHM version plus Team role counts, TeamRoles feature descriptions,
`UserManager/list_users?flat=1` and `PasswdStrength/get_required_strength?app=webdisk`
through direct UAPI and the WHM bridge for `npr`. The password-policy application
comes from the pinned enum/example; no user, GUID or service identifier is guessed.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/team-user-management.php \
  --output=docs/api/evidence/team-user-management-new.json
```

Team/list_team is excluded because both pinned alternatives include password
fields. Subaccount inventory has only password-capability/synchronization flags,
not password material; it stays structural-only in evidence. Existing exact
URI/method/body guards, committed-input checks, TLS verification, no retries and
new-only protected publication remain unchanged. Both tokens are required.
No user/service/role/password changes, invitation, activation or reset messages
occur. Strict assessment accepts no discrepancies or historical acknowledgments.
Follow-up `8x0.2.30` retains other operations and unobserved variants.

The corrected team/user-management capture at `9922081` on `11.138.0.19` passes
four of nine API/payload/probe checks: version, direct subaccount inventory and
password-strength through both routes. Seven direct inventory null corrections
pass; five API failures and one masked unknown-field observation with null values
keep strict status failed and unwaived under `8x0.2.30`. No team listing, password
retrieval, user/role/service mutation or message sending ran.

## Security and access controls

`security-access.php` binds `security-access-v1` to 25 exact GET observations:
WHM version and twelve parameterless account reads, each direct and bridged for
`npr`. It covers four ClamScanner status/path/infection reads, two ModSecurity reads,
three ExternalAuthentication reads, both SSH reads and LastLogin. No host, identity,
file or network target is guessed. KnownHosts/verify is excluded from this fixed
parameterless catalog; the recipe binds no verification target.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/security-access.php \
  --output=docs/api/evidence/security-access-new.json
```

Both tokens, committed inputs, verified TLS, exact URI/method/body guard, no retries
and new-only output publication are required. Evidence stores structural paths and
types only, wildcarding dynamic keys; file paths, identities, IPs, icons and URLs
are not persisted as values. No scan, disinfection, blocking, protection changes,
external-authentication unlink, host-key mutation/verification, login or URL fetch
runs. Strict assessment accepts no discrepancies or acknowledgments. Follow-up
`8x0.2.31` retains all unobserved methods, variants and enforcement behavior.

## Account backup and restoration

`account-backup-restoration.php` binds `account-backup-restoration-v1` to nine
exact GET observations: WHM version plus Backup/list_backups, Restore/get_users,
WordPressBackup/any_running and WordPressRestore/any_running through direct UAPI
and the WHM bridge for `npr`. All are explicitly source-readonly and parameterless;
no site, backup ID, archive or path is guessed.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/account-backup-restoration.php \
  --output=docs/api/evidence/account-backup-restoration-new.json
```

The existing exact URI/method/body guard, committed inputs, both tokens, TLS,
no retries and new-only output rules apply. Captures preserve structural paths
and types only, masking dynamic keys and never fetching returned SSE URLs or files.
No creation, remote transfer, extraction, restoration, cancel/cleanup, deletion,
tracking reset or email notification runs. Fourteen newer operations remain
outside this catalog: ten use conservative POST selection, while four WebsiteBackup
mutations have explicit pinned POST/form contracts. Strict assessment
accepts no discrepancies or acknowledgments; `8x0.2.32` retains all source/live gaps.

## WHM database administration

`whm-databases.php` binds `whm-databases-v1` to nine exact WHM GETs: version,
current/latest/installable MySQL versions, database optimization information,
database users, databases, MySQL database/user details for `npr`, and remote profile
activation status. Only the account-detail read supplies `user=npr`; all others
have only `api.version=1`. The source-declared status fields include no credentials.

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/whm-databases.php \
  --output=docs/api/evidence/whm-databases-new.json
```

The shared command requires both tokens and committed inputs even though this
catalog uses only WHM. Exact URI/method/body guards, verified TLS, no retries and
new-only output publication remain unchanged. Structural-only evidence wildcards
database/profile keys and never persists values. Profile credential reads and
background-upgrade status requiring an ID are excluded; no ID or network target
is guessed. No upgrade, checker run, profile creation/validation/activation,
password, rename or configuration mutation occurs. Strict assessment accepts no
discrepancies or acknowledgments; `8x0.2.33` retains remaining live behavior.


The corrected nine-GET artifact at `00ba48d` is already complete and strictly
passing; retain it and the initial failed artifact. Separate reviewer profile-read
and disposable-account lifecycle observations in review 5720420360 do not expand
this catalog. Profile responses and unvalidated exception payloads may contain
plaintext passwords; the catalog still excludes those credential reads.

## WHM ModSecurity inventory

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/whm-modsecurity.php \
  --output=docs/api/evidence/YYYY-MM-DD-whm-modsecurity.json
```

The fixed five-GET catalog reads WHM version, `modsec_get_configs`,
`modsec_get_configs_with_changes_pending`, `modsec_get_vendors` and
`modsec_is_installed` on the designated test server. It uses the existing exact
request guard, opt-in, committed-input checks, verified TLS and structural evidence
capture. It does not read raw configuration/rule text, audit logs or settings that
may contain an access key. It sends no report, mutation, vendor download or URL
fetch. Empty inventories establish no populated-row or enforcement behavior.
Keep failures immutable and remaining coverage under `8x0.2.34`.


On the reviewed `11.138.0.19` server, the pending-config read returns API result 0
when there are no pending changes. `Flag` therefore raises `ApiErrorException`
for this normal idle state as well as other failures; do not match error text or
waive the immutable capture. Separate reviewer log/settings reads do not expand
this catalog, and all thirty server-wide write/helper operations remain unobserved.

## WHM login and mail protection inventory

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/whm-login-mail-protection.php \
  --output=docs/api/evidence/YYYY-MM-DD-whm-login-mail-protection.json
```

The fixed eight GET requests read WHM version, cPHulk status/country inventory,
and Greylisting status, server netblock trust, common-provider configuration,
configuration limits and trusted hosts. Existing exact request guards, opt-in,
committed-input checks, verified TLS and structural evidence capture apply.
Authentication logs, deferred mail records and configured command text are
excluded. No protection, trust, history, configuration or mail state is changed,
and no message is sent. Source/runtime gaps remain under `8x0.2.35`.


The three retained API failures were independently diagnosed as disabled Greylisting
on `11.138.0.19`; status and configuration reads still work. Preserve both failed
artifacts and strict assessments without waivers. Do not enable Greylisting or
repeat the catalog merely to make it pass. Separate reviewer login-history reads
and source-backed write-result corrections do not expand this eight-GET capture;
all fifteen server-wide/lockout writes remain unobserved.

## WHM reseller inventory

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/whm-resellers.php \
  --output=docs/api/evidence/YYYY-MM-DD-whm-resellers.json
```

The fixed six GET requests read WHM version, account counts, public contact data,
reseller IPs, ACL lists and reseller names. Optional user arguments are omitted;
this does not establish an authenticated-user default. The reviewed root
`getresellerips` call fails because its source requires an actual user value,
while a named non-reseller also fails. Preserve this historical recipe and failed
artifact; do not substitute a guessed selector or rerun merely for green.
The existing exact request guard,
opt-in, committed-input checks, verified TLS and structural capture remain active.
No reseller is selected by a guessed identifier and no account, privilege, limit,
contact, IP or nameserver is changed. Returned URLs are not fetched. Keep remaining
variants and lifecycle verification under `8x0.2.36`.

The corrected reseller capture at `e8751c3` passes five of six API/payload/probe
checks with zero discrepancies. `getresellerips` still fails at the API; the
separate reviewer report attributes it to the omitted user. Strict status remains
failed and unwaived. The observed account limit
empty string is preserved without inferred semantics. ACL/reseller inventories
are empty. No settings changed; historical evidence and follow-up `8x0.2.36` remain.

## WHM IP address inventory

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/whm-ip-addresses.php \
  --output=docs/api/evidence/YYYY-MM-DD-whm-ip-addresses.json
```

Five fixed GETs read version, the default shared IP, IPv6 ranges, IPv4 addresses
and IPv6 addresses. Existing exact request guards, opt-in, committed-input checks,
verified TLS and structural evidence capture apply. No address/range is guessed;
no NAT check, address change, interface shutdown, account move or mapping update
runs. The evidence cannot establish routing, reachability or application delivery.
Keep remaining live variants under `8x0.2.37`.

The corrected WHM IP-address capture at `fde160d` on `11.138.0.19` passes all five
GET observations and strict assessment with zero discrepancies. IPv6 range
`enabled` accepts exact quoted flags; `mostrecent` and `note` accept null only
where observed. IPv4/IPv6/range rows are populated; range-user lists are empty.
No network or address/range change ran. The original failed capture is immutable
and follow-up `8x0.2.37` retains all remaining live coverage.

## WHM integration groups

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-integrations.php --output=docs/api/evidence/YYYY-MM-DD-whm-integrations.json`.

Two fixed GETs read version and list_integration_groups with user=npr. Existing
committed-input, exact request guard, opt-in, verified TLS and new-only structural
output rules apply. No hook commands, credential links or user configuration are
read; no hook/link/token mutation or URL fetch runs. `8x0.2.49` retains gaps.

The WHM integrations capture at `7b6f5ce` on `11.138.0.19` passes two GET checks and
strict assessment with zero discrepancies. Integration groups are empty; no
populated rows, hooks, credentials, user configurations or mutations are observed
in that capture. Separate PR62 reviewer lifecycle 5722110065 at `160fc83` on
`11.138.0.19` observes six POST methods using disposable `wrvint` and fake tokens,
with account/home cleanup verified and no URL fetch. Removing nonexistent links
and groups succeeds with null. Hook edit/delete/reorder remain unobserved under
`8x0.2.49`; `8x0.10.5` retains the depth-limit generator audit.

## Expanded WHM server inventory

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/whm-server-administration.php \
  --output=docs/api/evidence/YYYY-MM-DD-whm-server-administration.json
```

Eight fixed GETs read version, contact-event importances, available/current
profiles, minimum password-strength policy, installed versions, service status
and dashboard hostname. Optional filters/selectors remain omitted. Existing
exact request guards, opt-in, committed-input checks, verified TLS and structural
capture apply. No credential, linked-node secret, arbitrary config or socket
inventory is requested. No EULA, purchase, reboot/restart, update, activation,
node-link, configuration, notification or URL-verification operation runs.
Successful inventory does not establish service health, completion or remote
connectivity. Remaining source/live variants stay under `8x0.2.38`.

The corrected expanded WHM server capture at `742eaa8` on `11.138.0.19` passes
all eight HTTP/API/payload/probe checks. Ten evidenced integer leaves accept
canonical integer strings losslessly; siblings remain unchanged. Strict status
still fails for two masked unknown-field observations in installed versions and
service rows. They remain untyped and unwaived; no key identity/count is inferred.
No mutation or private configuration read ran. Historical artifacts remain
immutable and `8x0.2.38` retains remaining live coverage and unknown findings.

## WHM NGINX configuration

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-nginx.php --output=docs/api/evidence/YYYY-MM-DD-whm-nginx.json`.

Three fixed GETs read version and merged system/npr cache configurations. Existing
opt-in, committed-input, exact request guard, TLS and immutable structural-output
rules apply. No all-user inventory, cache clearing/rebuild/reset/toggle, service
restart or HTTP enforcement test runs. Keep gaps under `8x0.2.51`.

The NGINX capture at 2b36d29 on 11.138.0.19 records three HTTP-200 observations:
version passes, but both configuration reads fail at the API layer, with payloads
not checked. Strict status stays failed and no failure cause is inferred. No
cache or service change ran; all six methods remain offline-tested and live gaps
stay under 8x0.2.51. Preserve the artifact without retries or schema loosening.

Separate PR63 reviewer diagnostic 5721097412 on WHM `11.138.0.19` identifies
the missing required EA4 `ea-nginx` package. No successful NGINX payload or POST
was observed; the historical capture remains failed and unwaived. This correction
does not install the package or run another capture. The current catalog uses
`merge: true` and users-first typed arguments, retaining the same three GETs.

## WHM authentication metadata

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/whm-authentication.php \
  --output=docs/api/evidence/YYYY-MM-DD-whm-authentication.json
```

Three exact parameterless GETs read version, TFA issuer names and policy status.
The existing guard, opt-in, committed inputs, TLS verification, structural capture
and no-retry rules apply. Provider and login URLs, keys, credential configuration,
TFA secrets and linked-account data are omitted. No remote connection, URL request,
key operation, provider/link/policy change or notification runs. This establishes
no successful login, TFA challenge or SSH authorization; `8x0.2.40` retains other
operations and variants, and strict findings remain failed without waivers.

The WHM authentication capture at `e88583e` on `11.138.0.19` passes all three
GET observations and strict assessment with zero discrepancies. Only version,
issuer names and the integer policy flag were read. No secret, private URL, key,
configuration or link data was requested, and no state changed. It establishes
no login, TFA challenge, SSH access or mutation compatibility. `8x0.2.40` remains
open for all unobserved functions and variants.

## Site-builder domain capabilities

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/site-builders.php --output=docs/api/evidence/YYYY-MM-DD-site-builders.json`.

Five fixed GETs read version and Nova/Sitejet can_create_domains through both UAPI
routes. Existing opt-in, committed-input, exact request guard, TLS and immutable
structural-output rules apply. No external account/site creation, private token or
content retrieval, SSO/OAuth, publishing/restoration or URL fetch runs. Capability
results do not establish successful creation or rendering. Keep gaps under `8x0.2.57`.

The site-builder capture at fcd979d on 11.138.0.19 records five HTTP-200 reads:
version and both Sitejet capability reads pass; both Nova capability reads fail
at the API layer. Strict assessment remains failed with zero schema discrepancies,
without diagnosing the cause from that capture alone. No account/site/token/content/publish/restore/SSO
operation or URL fetch ran. Remaining live work stays under 8x0.2.57.

Separately, reviewer 5721328890 on `11.138.0.19` found `Cpanel::API::Nova`
missing and all six Nova reads they called failing on both routes. All eleven
Nova methods are unavailable for verification; writes were not executed and all
Nova response shapes remain unobserved. Their Sitejet reads and local disposable
account writes are attributed in the [source audit](../../docs/api/source-audit.md#nova-and-sitejet-site-builders); remote service calls,
successful restoration and published metadata remain unobserved. The historical
capture stays failed/not_checked without a waiver, and no corrected-head capture
ran. Remaining gaps stay open under `8x0.2.57`.

## Log metadata administration

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/log-administration.php --output=docs/api/evidence/YYYY-MM-DD-log-administration.json`.

Eight fixed GETs read version, WHM retention and selected-account settings/archive/
error-log metadata through both UAPI routes. Existing opt-in, committed-input,
exact request guard, TLS and immutable structural-output rules apply. No existing
log contents, archive deletion or settings mutation runs. Keep gaps under `8x0.2.55`.

The corrected log metadata capture at dd5e626 on 11.138.0.19 passes eight GET
checks and strict assessment with zero discrepancies. The original capture-bound rules normalize only WHM default_retention and
UAPI get_settings retention_days integer strings and exact quoted archive_logs/
prune_archive flags. The separate reviewer lines_read correction is described below. Account/archive rows are populated, error-log lists empty. No log
content, deletion or setting mutation ran. Initial e5476c1 evidence stays failed
and immutable; remaining live work stays under 8x0.2.55.

Separate review [5721279104](https://github.com/hostingpal/whm-api-client/pull/68#pullrequestreview-5721279104)
at `984c5c16e47c43741b50e817a6f87149eb2f86d5` on `11.138.0.19` exercised
all seven methods with synthetic logs/archives on a disposable account, both UAPI
routes and the single WHM route. Its token was revoked and account/home removed.
It observed empty direct `view_error_log` with `log_content: []` and string
`lines_read: "0"`; nonempty direct/bridged counts were integers. This separate
review observation motivates only that leaf's integer-string compatibility and
is absent from both committed captures. The review also observed stored false/zero
settings, unchanged omitted flags after clearing retention, server rejection of
1000 outside -1..999 and of an empty-argument settings call. Reviewer source
inspection found WebServer/rawlog requirements in shared `%API`; neither is
pinned or a disabled-role runtime test. Unreadable/symlink null metadata,
php_fpm_error_log and actual rotation/pruning remain open under `8x0.2.55`.
No corrected-head live run is claimed.

## DAV service and shared-addressbook status

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/dav-calendar.php --output=docs/api/evidence/YYYY-MM-DD-dav-calendar.json` only for an explicitly requested capture.

The fixed seven-GET catalog is diagnostic: version, then shared-addressbook,
DAV service and Horde status through both UAPI routes. Order, exact URIs, account
binding, opt-in, committed-input guard, TLS and immutable structural output remain
unchanged. It reads no private configurations, contacts, calendars or delegations
and changes no setting/collection. It is no availability guarantee.

The original `628c46e` capture on `11.138.0.19` has seven HTTP 200 observations:
version and two service-state passes, four shared/Horde API failures with payload
`not_checked`. Strict status remains failed despite zero schema discrepancies;
there is no waiver. Separate reviewer report 5721214794 attributes the shared read
to a return-0 stub and Horde to a missing handler on that version. Two misleading
overlay success recipes are removed; the fixed catalog retains both diagnostic
failures. The runner does not reinterpret errors as success, infer false, or
accept expected-error recipes. No new capture ran for this correction.

The reviewer separately used npr reads on both routes and disposable-account
writes; those are outside this artifact. Keep remaining live work in `8x0.2.56`.

## GPG public inventory and two-factor status

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/gpg-two-factor.php --output=docs/api/evidence/YYYY-MM-DD-gpg-two-factor.json`.

Five fixed GETs read version and selected-account public-key inventory/two-factor
status through both UAPI routes. Existing opt-in, committed-input, exact request
guard, TLS and immutable structural-output rules apply. No key export, secret-key
inventory, private/team configuration, key/authentication mutation or login test
runs. Keep remaining live coverage under `8x0.2.54`.

The GPG/two-factor capture at f213ab8 on 11.138.0.19 passes five GET checks and
strict assessment with zero discrepancies. Both public-key lists are empty;
two-factor status has integer is_enabled with issuer omitted. In that capture, no key content,
secret inventory, private/team configuration, key/authentication mutation or
login enforcement was tested. Remaining live work stays under 8x0.2.54.

Separate [review 5722555389](https://github.com/hostingpal/whm-api-client/pull/66#pullrequestreview-5722555389)
at `9b34fd5b791857625f3c7b5085f919a0a81bf124` on `11.138.0.19` used disposable
`wrvgpg` with token/account/home cleanup confirmed. It observed populated list
fields through both routes, empty-string generation payload mismatches after keys
were created, raw-armored import preserving the ID and normalized re-export,
and leading-zero TOTP API enrollment/removal. Other reviewer routes are unspecified.
Six POST methods have explicit reviewer execution evidence: GPG generation with
payload mismatch, secret export, import, deletion, TOTP enrollment and removal.
The reported fixture secret generation does not separately identify
`generateUserConfiguration()` or its typed result; team configuration also remains
unobserved. The narrow response corrections pass offline fixtures; no
successful typed corrected-head acceptance or login enforcement is claimed.
This reviewer report does not alter the immutable five-GET artifact or catalog.
Remaining routes/variants and committed mutation evidence stay under `8x0.2.54`.

## WHM SSL inventory

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/whm-ssl.php \
  --output=docs/api/evidence/YYYY-MM-DD-whm-ssl.json
```

Nine exact GET requests read version, AutoSSL schedule, log catalog metadata,
metadata flags, designated account/domain problems and exclusions, the best
SSL domain for the pinned `whostmgr` example and certificate metadata for `npr`.
The request guard, opt-in, committed inputs, verified TLS, structural-only capture
and no-retry behavior are unchanged. Provider payloads, private keys, component
PEM and log contents are omitted. No certificate generation, installation, reset,
removal, provider/configuration change, background check or notification occurs.
No returned URL is fetched. Strict findings remain failures; `8x0.2.39` retains
unobserved operations and variants.

The corrected WHM SSL capture at `4317e1a` on `11.138.0.19` passes all nine GET
observations and strict assessment with zero discrepancies. Five evidenced leaves
preserve best-domain null expiry, nullable certificate owner items and issuer
organization, and canonical integer-string modulus/expiry values. No sibling or
UAPI contract changed. Certificate inventory is populated; no mutation or private
material read ran. The initial artifact is immutable and `8x0.2.39` remains open.

## Expanded WHM account inventory

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-account-management.php --output=docs/api/evidence/YYYY-MM-DD-whm-account-management.json`.

Nine fixed GETs read version, user counts, disk usage, home roots, enhancement/user
inventories, privileges and quota availability. Optional selectors are omitted.
Existing exact request guards, committed inputs, opt-in, verified TLS and structural
capture apply. No passwords, private links or account/configuration mutations are
selected. No conversion, purchase or message runs. Strict discrepancies remain
failures; remaining behavior and variants stay under `8x0.2.41`.

## WHM hosting plans

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-hosting-plans.php --output=docs/api/evidence/YYYY-MM-DD-whm-hosting-plans.json`.

The original two fixed GETs read version and matchpkgs without selectors. A
reviewer received an empty match result while listPackages returned a plan on
11.138.0.19; the cause is unknown. Selector omission does not guarantee complete
inventory. Preserve the completed artifact; do not rerun it merely for green. Existing
exact request guards, committed inputs, opt-in, verified TLS and structural-only
capture apply. No package or feature mutation, arbitrary file read, template
execution or URL fetch runs. Findings stay unwaived; `8x0.2.43` retains live gaps.


### Reviewed hosting-plan contracts

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/whm-hosting-plans-review.php \
  --output=docs/api/evidence/YYYY-MM-DD-whm-hosting-plans-reviewed.json
```

The separate `whm-hosting-plans-review-v1` recipe adds the explicitly requested
`getPackageInfo('default')` and `getAvailableApplications()` reads, alongside WHM
version. The stock default package is grounded in the reviewer report, not an
inferred default for the required `name` parameter. Its exact request guards,
explicit live opt-in, committed-input checks and structural-only publication are
unchanged. No write, arbitrary file selector, template execution or URL fetch is
included. It writes a new immutable artifact; the original capture is preserved.

The requested 2026-10-31 run is complete in
`docs/api/evidence/2026-10-31-whm-hosting-plans-reviewed.json` at `1336c4d`.
All three HTTP/API/payload/probe outcomes pass; two masked application item and
subitem unknown-field observations retain a failed, unwaived strict assessment
under `8x0.2.43`. Do not rerun this completed capture merely to clear findings.

## WHM API tools

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-api-tools.php --output=docs/api/evidence/YYYY-MM-DD-whm-api-tools.json`.

Five parameterless GETs select version, applist, api_token_list, get_api_calls and
get_api_pages. Token-list schema contains metadata, not raw credentials. Usage
entries are function names or file paths. Only masked structural evidence is
persisted. Existing committed-input, exact request guard, opt-in, verified TLS
and new-only output requirements apply. No token/session change, secret lookup,
login-link creation or URL fetch runs. Follow-up `8x0.2.44` retains unobserved behavior.

The completed `465be19` capture has five GET observations covering four typed
API-tools reads plus version; it does not cover AppConfig. Its masked empty-list
unknown-field observation remains strict failed and unwaived. The separately
reviewed `result` wrapper correction does not alter the historical artifact or
establish its hidden key identity/count. No repeat is required merely for green
status, and no credential/session action is part of this recipe.

## Expanded WHM web-server inventory

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-webserver-configuration.php --output=docs/api/evidence/YYYY-MM-DD-whm-webserver-configuration.json`.

Nine fixed parameterless GETs read version and eight EasyApache/PHP inventories.
Only masked structural evidence is persisted. Existing committed-input, exact
request guard, opt-in, verified TLS and new-only output requirements apply.
No INI content, arbitrary file or private configuration selector is supplied;
no conversion/profile/PHP/FPM mutation or URL fetch runs. `8x0.2.45` retains gaps.

Both committed web-server captures are complete and immutable. Their two
masked unknown observations remain strict failed and unwaived, explicitly
nonblocking in review 5720977511. The separate reviewer INI reads and source
corrections do not expand this fixed catalog. No repeated capture or server
configuration write is required merely for integration or green status.

## WHM transfer metadata

The original `whm-transfers.php` recipe captured version, available modules and
`npr` username validation in `2026-10-31-whm-transfers.json` at `a5badae`.
That three-GET capture strictly passes and is complete; do not replay it just
for integration. The catalog now also includes the source/observed module name
`FeatureListRemoteRoot`, with exact local GET arguments and no remote contact.

The separate `whm-transfers-review.php` recipe selects only version and that
module-schema read for the requested new evidence. Use both the normal live
opt-in and designated `npr` account/domain guards; it records sanitized structure
from the same raw responses through committed generated code. Schema text stays
inert and no embedded URLs are followed. This recipe supplies no remote host,
credential, session ID, log or archive path. It performs no POST or resource
change. All eight writes and populated session/log/analysis variants remain
unobserved under `8x0.2.47`. Preserve every historical artifact and discrepancy.

The requested two-GET recipe is complete: `2026-10-31-whm-transfers-reviewed.json`
at `d81e43a` on `11.138.0.19` strictly passes with zero discrepancies, observing
populated schema key records and primary/required string lists. SHA-256
`ecd22c05ac50d5b2dcfc2ad3c90c0ff6c26b21f059268726b31f163de506bd7e`.
Do not repeat either transfer capture merely for integration or green status.

## WHM system-package inventory

The expanded `whm-system-packages-v2` catalog has seven fixed GETs: version,
missing required RPMs with target omitted, installed-package inventory,
`getPackageInfo(packages: 'ea-apache24')`, RPM `target_settings` and `rpm_groups`,
and `resolveActions(packages: 'ea-apache24', namespaces: 'ea')`. The typed plural
argument encodes the singular wire key `package`; namespace uses `ns`.
The three-GET historical captures used v1 and are complete; do not repeat them
merely for integration or to clear their findings.

For the separately requested new reads, run only:

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-system-packages-review.php --output=docs/api/evidence/YYYY-MM-DD-whm-system-packages-reviewed.json
```

This `whm-system-packages-review-v1` recipe selects version and those four new
reads. It does not replay `listRpms()` or `listPackages()`. Action resolution is
a planning read, not action submission. All requests are exact fixed WHM GETs;
there is no install/remove/upgrade action, cache repair, build-log read, arbitrary
package selector or URL fetch. Existing committed-input, live opt-in, exact
request guard, verified TLS and new-only structural output rules apply. Dynamic
RPM keys and all returned values remain masked in persisted evidence.

The optional-payload fingerprint correction affects newly analyzed responses
only. Absence remains structurally distinct from null, optional omission is
accepted, and malformed present payloads still fail. The historical package
`missing_required` finding is a tooling mismatch and remains strict failed and
unwaived in its original artifact. It is not a server-failure or zero-dependency
claim. All seven POST methods and remaining compatibility stay under `8x0.2.46`.

### Reviewed package contracts, 2026-10-31

The separately requested review capture is now complete at `dd72691` on
`11.138.0.19`: exactly five WHM GETs (version, `ea-apache24` information,
`target_settings`, `rpm_groups`, and action resolution for that package in
namespace `ea`). All HTTP/API/payload/probe and strict checks pass, with zero
discrepancies. It observes mixed string/nested-string-list requirements, string
conflicts and vendor, a string installed version, nested RPM maps with string
leaves and with string-list leaves, and populated unaffected/uninstall plan
lists. The plan was returned only; no package action was submitted. Dynamic
names and all payload values are absent from the artifact. The nested RPM map
observations now support the previously inferred section-map compatibility.
This capture does not establish empty installed-version values, literal vendor
values, zero dependencies or any POST behavior. The separate reviewer report
retains the empty-string observation and source placeholder attribution.

The new artifact is `2026-10-31-whm-system-packages-reviewed.json`, SHA-256
`44b3540940eeda781608957a57d12e5b9b5bdf10343c84f4f5a272e071d156ed`.
It does not rerun either historical inventory probe or alter their findings.
No mutation, cache repair, build-log read or URL fetch ran. All seven POSTs and
remaining compatibility stay under `8x0.2.46`; no repeat is needed for integration.

## Expanded WHM mail inventory

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-mail-management.php --output=docs/api/evidence/YYYY-MM-DD-whm-mail-management.json`.

Eight fixed GETs read version, default DMARC, SNI support/status, incoming-block
inventories, owned mailbox names and system forwarding destinations. Account/domain
selectors are fixed to npr/npr.rs. Existing committed-input, exact request guard,
opt-in, verified TLS and new-only structural output rules apply. No messages,
queue contents, keys or mobileconfig are read; no mail is delivered and no state
changes. Follow-up `8x0.2.48` retains unobserved behavior and variants.

The corrected WHM mail-management capture at 437c951 on 11.138.0.19 passes all
eight GET observations and strict assessment with zero discrepancies. Only
get_user_email_forward_destination.forward_to additionally preserves literal empty
string alongside list<string>, without assigning meaning. PR61 source review
separately supports arbitrary strings, with null still invalid. Inventories are empty and no mail mutation/delivery/key/queue inspection
ran. Initial e023749 evidence remains failed and immutable; 8x0.2.48 stays open.

## WHM Market provider inventory

Run `WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs php tools/live-verify/whm-market.php --output=docs/api/evidence/YYYY-MM-DD-whm-market.json`.

Two fixed GETs read version and provider inventory. Existing opt-in, exact request
guard, committed-input, TLS and immutable structural-output rules apply. No
commission IDs, private product information, token exchange, provider/commerce
changes, purchases or URL fetches run. Keep live gaps under `8x0.2.52`.

The Market provider capture at 38edb29 on 11.138.0.19 passes both GET checks and
strict assessment with zero discrepancies. Provider rows are populated; only this
inventory plus version is observed. No commerce route, commission ID, product,
private token, OAuth exchange, mutation or URL fetch ran. The separately reported
PR64 reviewer Commerce/commission reads do not change this completed capture or
catalog. All remaining live variants stay under 8x0.2.52.


## DNSSEC singleton indexed-input read

`dnssec-indexed-input-v1` is a separate opt-in catalog with exactly three GETs:
WHM version, then direct and bridged `fetchDsRecords(domain: ['npr.rs'])`.
Both DS observations carry `argument_variant: singleton-domain-list`. Exact
`domain-0=npr.rs` URLs, the `npr` account, GET method and empty request bodies are
guarded; the existing runner makes one attempt per probe and keeps only sanitized
structural evidence. The historical `dns-security-v1` catalog is unchanged.

After code review and explicit capture authorization, select a fresh output path:

```bash
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
  php tools/live-verify/dnssec-indexed-input.php \
  --output=docs/api/evidence/dnssec-indexed-input-new.json
```

The command requires both existing test tokens and committed repository context.
A successful result establishes singleton-list wire acceptance only. It establishes
no multi-domain batch, populated DS field, mutation, propagation or DNSSEC resolver
coverage. Existing artifacts and discrepancy policies are never changed by this
catalog.

## Webmail session and application inventory

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/webmail-inventory.php \
  --output=docs/api/evidence/YYYY-MM-DD-webmail-inventory.json
```

`webmail-inventory-v1` has five fixed GETs: WHM version, then
`ActiveSessions/list_active_sessions` and `WebmailApps/list_webmail_apps` through
direct UAPI and the `npr` bridge. Neither call supplies an argument, so the server's
default theme applies. Existing opt-in, committed-input, exact request guard, TLS
and immutable structural-output rules apply.

An empty session list proves nothing about session row fields, and upstream prose
says it can also mean an unreadable session store. Application rows establish
field types only: returned URLs may carry a session token, so they are neither
persisted nor fetched, and they prove neither reachability nor login. No `Session/*`
creator runs on any account: a reviewer's disposable-account test left four session
files behind after the account was removed, and cleaning them needs filesystem
access. The creators stay open under `8x0.2.69`.

The [2026-11-01 capture](../../docs/api/evidence/2026-11-01-webmail-inventory.json)
at `eba1711` on `11.138.0.19` passes all five GETs and strict assessment with zero
discrepancies. Session inventory is empty on both routes, so all six session row
fields stay unobserved. Application rows are populated. Do not repeat this capture
for a populated result; session rows need a separately reviewed recipe.
SHA-256: `37ee9760012a45c19100adc379ca59d7b87d46aebea8596a6c1f8e75dc201cd6`.

## UAPI Market reads

```sh
WHM_API_LIVE=1 WHM_API_LIVE_ACCOUNT=npr WHM_API_LIVE_DOMAIN=npr.rs \
php tools/live-verify/market-reads.php \
  --output=docs/api/evidence/YYYY-MM-DD-market-reads.json
```

`market-reads-v1` has eleven fixed GETs: WHM version, then five `x-readonly: true`
reads through direct UAPI and the `npr` bridge: `get_providers_list`,
`get_all_products`, `get_pending_ssl_certificates`,
`get_provider_specific_dcv_constraints` and `get_login_url`. Existing opt-in,
committed-input, exact request guard, TLS and immutable structural-output rules apply.

Selectors are not guessed. A private direct-route read of the provider list, catalog
and pending queue before this catalog was written showed `cPStore` as the only
provider, three catalog rows and no pending certificates. `url_after_login` selects
no server resource; the recipe sends the pinned parameter example, on a reserved
domain, and the returned URL is never followed.

Four of the nine reads in `8x0.2.63` are deliberately not run, and
`MarketReadsProbes::NOT_RUN` records each reason:

| Operation | Reason |
| --- | --- |
| `get_certificate_status_details` | No pending order, so no real `order_item_id` exists |
| `get_ssl_certificate_if_available` | No pending order, so no real `order_item_id` exists |
| `get_product_info` | No read enumerates a real `product_name` |
| `get_completion_url` | No read enumerates a real `product_name` |

The pinned `product_name` example, `cpanel-ssl`, is a store-page slug and appears
nowhere in this server's catalog. The catalog's `product` values are display labels
in a different form, and the pinned source states no mapping between them. Run
these two only with a selector known to be real on the server, for example after
private inspection of its store product files.

Payloads can hold security tokens, action URLs, PEM, CSR and identity data; only
structural paths and types are saved. No order, cart, checkout, certificate, queue
or token action runs, no POST is sent, and no returned URL or credential is used.
`get_license_info` and `get_build_cart_url` are not `x-readonly` and stay out of this
catalog. A successful read establishes no purchase, payment, issuance, installation,
trust or served TLS behavior.

The [2026-11-01 capture](../../docs/api/evidence/2026-11-01-market-reads.json) at
`eba1711` on `11.138.0.19` passes all eleven GETs and strict assessment with zero
discrepancies. Provider, catalog and DCV payloads are populated, the login URL is a
string, and the pending list is empty on both routes. Catalog prices, `max_users`
and a non-empty `base_name` stay unobserved, as do the object identity branch and
pending rows. Do not repeat this capture unchanged. The four unrun reads need a real
selector and a new recipe. See the source audit for the observed types.
SHA-256: `4a9ec4fffec130ee099b63cb2f86c24482b332a8101995828426745bdb70da51`.
