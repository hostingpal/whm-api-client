# Live contract evidence

This development-only PHP command exercises the generated client on the approved
test server. It performs 43 GET requests across 25 operations, with exact route
and argument guards, verified TLS, redirects
disabled, and no retries. The core package has no dependency on this tool.

| Route | Reviewed operations and arguments |
| --- | --- |
| WHM | `version`, `systemloadavg`, `listaccts(search: 'npr', searchtype: 'user', searchmethod: 'exact')`, `accountsummary(user: 'npr')`, `get_domain_info`, `php_get_installed_versions`, `php_get_system_default_version` |
| Direct and WHM-bridged UAPI, account `npr` | `DomainInfo/list_domains`, `DomainInfo/primary_domain`, `LangPHP/php_get_installed_versions`, `LangPHP/php_get_domain_handler(type: 'home')`, `Email/list_pops`, `Email/list_mail_domains`, `Email/list_domain_forwarders`, `Email/count_pops` |
| Direct and WHM-bridged UAPI, account `npr` | `SSL/installed_hosts`, `SSL/get_autossl_excluded_domains`, `SSL/get_autossl_problems`, `SSL/get_autossl_renewal_status(domain: 'npr.rs')`, `SSL/is_autossl_check_in_progress`, `SSL/can_ssl_redirect` |
| Direct and WHM-bridged UAPI, account `npr` | `Quota/get_quota_info`, `Quota/get_local_quota_info` |
| Direct and WHM-bridged UAPI, account `npr` | `Mysql/list_databases`, `Mysql/list_users` |

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

Quota recipes take no arguments and read account totals in MB and local usage in
bytes. They do not compare values from separate requests: usage may change and
distributed account totals can differ. Only field paths and types are retained,
not quota values. Quota total responses currently include unreviewed fields
reported as `$.*` discrepancies. Two exact structural observations are acknowledged
by the development evidence policy described below. Their field contracts remain
unresolved in `whm-api-client-8x0.4.3.1`; acknowledgment does not add them to typed
results or remove their discrepancy records.

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
network calls. Mutation fixtures, broad discovery and automated overlay edits are
outside this implementation.
