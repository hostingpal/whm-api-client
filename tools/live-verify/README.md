# Live contract evidence

This development-only PHP command exercises the generated client on the approved
test server. It performs 39 GET requests across 23 operations, with exact route
and argument guards, verified TLS, redirects
disabled, and no retries. The core package has no dependency on this tool.

| Route | Reviewed operations and arguments |
| --- | --- |
| WHM | `version`, `systemloadavg`, `listaccts(search: 'npr', searchtype: 'user', searchmethod: 'exact')`, `accountsummary(user: 'npr')`, `get_domain_info`, `php_get_installed_versions`, `php_get_system_default_version` |
| Direct and WHM-bridged UAPI, account `npr` | `DomainInfo/list_domains`, `DomainInfo/primary_domain`, `LangPHP/php_get_installed_versions`, `LangPHP/php_get_domain_handler(type: 'home')`, `Email/list_pops`, `Email/list_mail_domains`, `Email/list_domain_forwarders`, `Email/count_pops` |
| Direct and WHM-bridged UAPI, account `npr` | `SSL/installed_hosts`, `SSL/get_autossl_excluded_domains`, `SSL/get_autossl_problems`, `SSL/get_autossl_renewal_status(domain: 'npr.rs')`, `SSL/is_autossl_check_in_progress`, `SSL/can_ssl_redirect` |
| Direct and WHM-bridged UAPI, account `npr` | `Quota/get_quota_info`, `Quota/get_local_quota_info` |

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

Quota recipes take no arguments and read account totals in MB and local usage in
bytes. They do not compare values from separate requests: usage may change and
distributed account totals can differ. Only field paths and types are retained,
not quota values. Quota total responses currently include unreviewed fields
reported as `$.*` discrepancies. The command keeps its nonzero exit for these
discrepancies even when API outcomes, typed payload validation and probes pass;
see `whm-api-client-8x0.4.3.1` and the source audit.

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

The command atomically writes sanitized JSON, then exits 0 only when every probe
passed and has no discrepancies. Exit 1 also covers skipped/unsupported probes,
request or payload failures, and discrepancies needing review. Saved evidence
still exists when probes ran but reported those outcomes. No correction is applied
automatically. Review differences, retain independent synthetic regression tests,
and use the overlay/generator workflow for any justified contract change.

## Adding a reviewed slice

Recipes live in their operation's overlay `probe` object. The runner accepts
only `phase: read-only`, the exact arguments reviewed for that operation,
`expected: success`, and the exact reviewed route list. The code catalog binds each recipe to its generated method
and exact permitted request. New operations require both a reviewed catalog entry
and offline request/response tests; editing an overlay cannot authorize arbitrary
network calls. Mutation fixtures, broad discovery and automated overlay edits are
outside this implementation.
