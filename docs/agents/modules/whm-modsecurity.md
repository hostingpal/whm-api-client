# WHM ModSecurity administration

> Agent contract notes for `whm()->modSecurity()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM ModSecurity administration

`whm()->modSecurity()` exposes all 38 pinned functions: eight reads use GET and
thirty mutations or validation helpers use form POST. Rule and configuration text,
report messages and setting states remain sensitive inputs. As safety guidance,
treat returned settings and audit log fragments as private payload data; this is
reviewer judgment, not a statement from the pinned operation text or a claim that
secret values were observed. Configuration paths are forwarded without rewriting.

Rule staging, deployment and discard are separate operations. Config activation,
vendor enablement and automatic vendor updates also have distinct effects. API
success does not establish that a policy has been deployed or blocks HTTP traffic.
Vendor preview/add/update may fetch remote content, and `reportRule(send: true)`
can transmit log information and contact details to a third party. The client adds
no retries, rollback, URL fetching or automatic deployment.

`batchSettings(int $settingId, string|int $state, ?bool $remove = null)` retains
its single-setting contract. `updateSettings(array $settings)` adds a nonempty
sequential list of `ModSecuritySettingInput` associative records: required integer
`settingId`, required `string|int` `state`, and optional boolean `remove`. State
remains required even with `remove: true`; explicit null and unknown row keys fail
locally. Missing optional fields retain the original one-based row index, and
input order and duplicate setting IDs are preserved. The entire collection is
sensitive. Both methods share one canonical response contract and issue one form
POST. Both prepare staged settings for explicit deploySettingsChanges(). A failure
can be partial; ApiErrorException omits data.failures, so callers must explicitly
re-read getSettings() after any failure. Success in the inspected 11.138.0.19
handler means every row was accepted, not deployment. Server hash traversal gives
no deterministic ordering or last-wins guarantee for conflicting repeated IDs.
No automatic read, retry or rollback occurs.
The source restricts batch setting IDs to 0 through 9; this change adds no
client-side ID/range or state enum validation. Record input support is source-backed
and tested offline only.

`reportRule(string $email, string $message, string|int $rowIds, bool $send, string $type)`
accepts an integer single row ID or a comma-separated string. The pinned formal
integer schema contradicts its multi-ID prose and example; the source-backed
union preserves both. All IDs must correspond to the same ModSecurity rule.
Strings retain their exact bytes, including spaces, leading zeros and duplicates;
the client adds no parsing or ID/same-rule validation. `send: false` prepares the
report; `send: true` sends it to a third-party vendor. This input correction is
verified offline only; no report was submitted.
The twelve source-backed empty-result mutations accept an omitted or null payload
while retaining any returned mixed data; they are not weakened to void. Omitted
wire payloads on those writes remain unobserved.

`getLog()` preserves the inner `data` list wrapper, including an empty log.
Log/report `meta_offset` and `meta_msg` accept source-family nullability; populated
null rows remain unobserved. Get/set settings share the reviewed row shape,
including exact `string|int` state/default values. Batch defaults keep that same
union. The report result's array-of-objects prose repairs its omitted container
type while preserving every declared hit field. Integer rule IDs from getters
can be passed directly to `disableRule()` and `undisableRule()`.

Omitting `enabled` in `addVendor()` installs and enables the remote rule set by
default. `getRules()` defaults to excluding bare comments and other directives;
with neither config nor vendor selector it raises `ApiErrorException` on the
reviewed server. These defaults are retained without implicit client actions.

All 38 methods have independent offline contract tests. The bounded live recipe
selects only WHM version, configuration inventory, pending configuration names,
vendor inventory and installation status. It reads no raw rule/configuration text,
audit logs or secret-bearing setting states; no protection, vendor, report or
configuration action runs. Follow-up `8x0.2.34` retains the remaining live behavior
and source gaps.

### WHM ModSecurity inventory capture

The immutable [WHM ModSecurity capture](../../api/evidence/2026-10-31-whm-modsecurity.json) at
`cd205ab2452ee9a8f215d579f4f9d69baa2c3e9a` on `11.138.0.19` records five HTTP 200
observations. WHM version, configuration inventory, vendor inventory and
installation status pass API, payload and probe checks. Both inventories are
empty, so their populated rows remain unobserved. The pending-configuration read
fails at the API layer. The reviewer established that the ordinary idle state
uses result 0 when no rule changes are pending; it therefore raises the expected
`ApiErrorException` under `Flag`. Other failures also use result 0, so no error-text
matching or special success exception is introduced. There are zero payload
discrepancies, while strict assessment remains failed and unwaived for that API error.

No raw rule/configuration text, audit logs or secret-bearing settings were read.
No scan, policy, vendor, report, deployment or configuration change ran, and no
returned URL was fetched. The other thirty-four methods were outside this committed catalog.
Follow-up `8x0.2.34` retains the failed read and all unobserved behavior; this
capture does not establish request blocking or enforcement. No repeated capture
was run merely to obtain a passing result. SHA-256:
`673fd065ffdf05bdb8a1c1dd5af5bcb770a8280aa556cc4a5f2d9fbbd516ab9c`.

The generated `updateSettings()` alias maps a strict related-field record list to
`whm1:modsec_batch_settings`, retaining `batchSettings()` and its result alias.
Additional methods are input mappings on the canonical operation, never separate
operations. Alias specs inherit the canonical spec and replace only params.
Every mapped field needs matching operation-scoped source SHA256 and parameter
pointer evidence. Types and requiredness come from canonical source parameters;
record fields cannot declare a separate result, route, variant, outcome or method.
Record expansion appends directly to OrderedFields with original argument origin
and no repeat-family exception. Keep scalar/index/map/SSL/generic paths unchanged.
Record fields retain complete selected-source descriptions through the established
prose converter and PHPDoc escaping. Source names bind evidence independently of
corrected wire names. Whole-spec overlap lint includes record families and rejects
known static collisions as exact LogicException before argument validation/I/O.
Batch changes stay staged for explicit deploySettingsChanges(); after any failure,
callers must explicitly re-read getSettings(), because Flag exceptions omit failures
and changes can be partial. Success in the inspected 11.138.0.19 handler means every
row was accepted, not deployment. Duplicates have no server order/last-wins promise.
Coverage remains 1,142 operations, 99 groups, 200 generated PHP files. No live batch
write or report submission was performed; comma-delimited report IDs remain in .1.24.
