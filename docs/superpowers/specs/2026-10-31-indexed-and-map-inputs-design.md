# Indexed lists, named maps and related-field batch inputs

Date: 2026-10-31 (Europe/Belgrade). Status: public input design approved in the
project conversation; this document records that agreement and the engineering
choices below. Publication does not implement an encoder or establish live API
compatibility. Delivery epic: `whm-api-client-8x0.28`.

## Intent and approved approach

Callers describe values using ordinary PHP arrays. The generated client handles
upstream numbering, prefixes and related-field alignment. A list represents
ordered values, a map represents named identities, and a list of records represents
several items with related fields. The operation contract determines which kind of
array is expected; the runtime must not guess from an array's current contents.

The user approved hiding wire numbering, using maps for multiple settings within
one named feature list, and using records instead of parallel arrays for related
batch fields. Several named feature lists still require separate calls unless a
separately reviewed upstream operation provides that batch behavior.

This extends the [generated-client design](2026-10-14-generated-client-design.md).
Methods remain generated; the package stays framework-independent; existing named
arguments, result contracts, routing, metadata and single-attempt behavior remain.
The shared runtime interprets explicit operation specs backed by pinned evidence.

### Approaches considered

1. **Expose wire names or caller-selected encoding wrappers.** Small runtime change,
   but every consumer must know index bases, separators and alignment rules. This
   does not meet the approved goal. Existing generic-call markers remain available;
   new generated inputs do not require callers to construct them.
2. **Use separate parallel arrays for related fields.** Simple to transpose but
   easy to misalign, especially with omitted optional values. Rejected in favor of
   a record containing all fields for each item.
3. **Use ordinary arrays with generated, explicit encoding metadata.** Selected.
   It requires shared runtime and generator work, but keeps the upstream quirks in
   one reviewed contract and supports precise PHPStan input shapes.

No new public collection classes are required. Internal representation and class
names are implementer choices, subject to the observable contracts in this spec.

## Scope and boundaries

In scope: explicit indexed lists, typed repeated lists, dynamic named maps,
related-field record batches, collision-safe expansion, generated input shapes,
and a generated additional method for an existing canonical operation.

Out of scope: JSON request bodies or automatic JSON-text serialization; binary
multipart; general composed provider/record schemas; alternate response locations;
client-side batching loops; general runtime enum/range enforcement; inferred
server defaults, transactionality, rollback or retry. A string containing JSON
remains a string in this work. The separate request-format and schema-fidelity
beads remain authoritative for those capabilities.

Do not reactivate deprecated operations or change old scalar behavior merely to
make the new grammar uniform. An unresolved upstream naming convention remains a
blocked endpoint variant even after the general encoder exists.

## Public collection contract

| Input | PHP shape | Identity and ordering |
| --- | --- | --- |
| Indexed values | `non-empty-list<T>`; existing scalar `T` retained where documented | PHP position selects the wire index; order and duplicates are preserved |
| Repeated values | `non-empty-list<T>`; scalar retained only when the operation already supports it | Repeat the same field in input order; duplicates remain |
| Named values | `array<string, T>` with operation-specific empty policy | Keys are identities; preserve key bytes and insertion order |
| Related items | `non-empty-list<array{...}>` | Every row gets one index shared by all its present fields |

`T` is explicitly declared per parameter: string, integer, a reviewed scalar
union, or boolean flag. A string family does not start accepting integers merely
because another family needs them. Boolean flags take native PHP booleans and
encode as `1`/`0`; integer fields do not accept booleans. Numeric strings do not
become integers on request input. Float support is outside the initial families.

Top-level omission and `null` keep the existing scalar method's behavior when an
optional collection argument extends it. In particular, changing SpamAssassin's
optional scalar to `string|array|null` must preserve its omission/deletion behavior.
A null entry *inside* a collection is invalid in this delivery; no selected source
establishes a nullable entry wire representation. Empty strings, zero and false
remain values when allowed by the declared item type. Do not use truthy filtering.

## Indexed and repeated lists

The effective operation spec records a literal base/prefix, separator, starting
index, item type, scalar policy and empty policy. These are independent facts.
The encoder does not infer them from an operation name or a sibling endpoint.

| Family | Two-item wire expansion | First index |
| --- | --- | --- |
| Hyphenated, zero-based | `domain-0=a`, `domain-1=b` | 0 |
| Hyphenated, one-based | `token_name-1=a`, `token_name-2=b` | 1 |
| Suffix, one-based | `setting_id1=1`, `setting_id2=2` | 1 |
| Existing first-unsuffixed numbering | `name=a`, `name-1=b` | Special legacy rule |
| Repeated | `remove=3`, `remove=8` | No wire index |

For an extended scalar method, a scalar uses its established unsuffixed field.
A one-item array uses the declared list pattern, even when it has only one value.
Do not collapse it to a scalar. Existing `NumberedList`, `RepeatedList` and
`SuffixNumberedList` outputs remain byte-compatible for currently valid inputs.

A list must satisfy `array_is_list()`. Reject sparse, negative-indexed or associative
arrays; do not silently call `array_values()`, sort, deduplicate or renumber them.
List indices in PHP always start at zero. A one-based wire family does not require
one-based PHP array keys.

Empty lists are rejected for the initial indexed/repeated families. Do not turn
`[]` into omission: missing ACLs on token **creation** can grant all available
privileges, and a missing SpamAssassin value can delete a preference. For token
update, the reviewer-inspected implementation on `11.138.0.19` instead preserves
omitted fields and drops empty lists; accepting `[]` would silently leave the
existing value unchanged. Reject it for that reason, not an inferred privilege
reset. The source/pinned contradiction is recorded below. Any future empty-list
exception needs operation-specific evidence, an explicit encoding and its own tests.

The typed repeat extension must support `list<int>` for DNS removal indices while
preserving current string-repeat behavior. It must not widen every repeated field
or infer acceptance of negative line indices from the encoder's integer type;
existing source-bound validation policy and server constraints remain documented.

## Named maps

The operation declares the public argument name, fixed wire prefix (possibly
empty), value type, sensitivity, requiredness and empty policy. Callers provide
logical keys without a client-added prefix. There is no wildcard or placeholder
field on the wire.

| Logical map entry | Prefix | Resulting field |
| --- | --- | --- |
| `['changemx' => true]` | Empty | `changemx=1` |
| `['google' => false]` | Empty | `google=0` |
| `['old_database' => 'new_database']` | `copymysqldb-` | `copymysqldb-old_database=new_database` |
| `['terms_of_service_accepted' => $url]` | `x_` | `x_terms_of_service_accepted=<encoded URL>` |

A map must contain nonempty string keys. Reject integer PHP keys, including
numeric strings PHP has already converted to integers; their original spelling
cannot be recovered. Do not cast them back. PHP has already overwritten exact
duplicate keys before receiving an array, so the client cannot detect those lost
entries. It can and must detect collisions introduced during wire expansion.

Preserve key case, dots, hyphens and value bytes. Do not trim, normalize domains,
strip an apparent prefix, decode percent escapes or convert identifiers. Prefix
concatenation is literal; the client never strips an apparent prefix. The two
AutoSSL provider descriptors explicitly reject logical keys already beginning
`x_` before I/O, preventing accidental `x_x_` fields without rewriting input.
This is scoped client transport policy, not a blanket named-map rule: database
names may legitimately begin with `copymysqldb-` and keep those literal bytes.
PHPDoc examples must make the logical-key convention clear.

As an engineering transport rule, reject invalid UTF-8 keys and control bytes
(U+0000–U+001F and U+007F). Do not invent a universal feature/provider/domain-name
regex. Names and values are RFC 3986 encoded once after validation; `&`, `=`, `%`
and similar bytes are data, not manually assembled separators. The encoder does
not promise the server accepts every syntactically representable identifier.

Map value types are operation-specific. Feature/Greylisting maps use booleans;
database targets and AutoSSL provider parameters use strings. Credential-bearing
maps are sensitive in their entirety. Accepting a terms-of-service URL does not
fetch it or imply that the client accepted terms on
the user's behalf: the caller deliberately supplies the documented value.

### Empty maps, omission and replacement behavior

Allowed empty maps expand to zero dynamic fields, but the method still sends its
one request with the fixed fields. Never short-circuit the call as a no-op.

- `update_featurelist`: the new generated
  `updateFeaturelist(string $featurelist, array $features)` requires a **nonempty**
  boolean map; reject `features: []` before I/O to avoid a successful no-op. The
  pinned `feature name` description says no supplied features disables all features.
  That conflicts with the reviewer's source inspection and live observations on
  `11.138.0.19`: no features changes nothing, and omitted keys in a nonempty update
  preserve their existing state. Unknown names appear in `invalid_features`; the
  reviewed source dies for unknown names only when a custom features file exists.
  Callers must inspect `invalid_features`; do not invent an always-failing
  unknown-name rule or an atomicity guarantee.
- `create_featurelist`: keep a required boolean `features` map, with `[]` explicitly
  allowed. It sends no dynamic feature fields and, in the reviewer's corrected
  live report on `11.138.0.19`, creates an all-disabled list (120 of 120 features
  disabled in that fixture). This is version-scoped reviewer evidence, not a new
  local observation or a promise for every server version. Preserve `overwrite`
  as a separate optional argument with its existing source meaning. The later
  correction supersedes the initial review's untested create caveat.
- Greylisting provider maps may be optional. Omitted/null and allowed `[]` send no
  provider-specific fields, leaving existing providers unchanged by those fields.
  Other arguments and upstream defaults, including autotrust, may still act.
- Optional database-copy maps preserve omitted behavior; the operation's source
  defines what absence means. Empty maps add no map fields, without suppressing the
  remaining operation. MXCHECK adoption is excluded as described below.
- AutoSSL provider maps can be empty at the encoding layer. Provider requirements
  remain server-specific; do not synthesize credentials or legal-acceptance fields.

The exact PHP argument names for newly introduced map-only operations are chosen
in their endpoint adoption bead, checked against source meaning and existing group
names. The `features` names above are fixed public choices for feature-list calls.
An already published method signature must not be changed by that later choice.
Feature behavior above comes from the [2026-10-31 review](https://github.com/hostingpal/whm-api-client/pull/72#pullrequestreview-5722427592)
and its superseding [2026-10-31 correction](https://github.com/hostingpal/whm-api-client/pull/72#issuecomment-6544681850).

## Related-field record batches

Use one nonempty sequential list of records, not independently indexed arrays.
The generator declares known row fields, each field's wire prefix and strict
scalar type, required fields and a shared index policy. Missing optional keys stay
absent. Null is not an omission substitute inside a record; unknown keys fail.
Row numbers come from the original row positions, independently of which fields
are present. Missing fields in row one must never move row two's fields to index one.

Initial ModSecurity record type:

```php
/** @phpstan-type ModSecuritySettingInput array{
 *     settingId: int,
 *     state: string|int,
 *     remove?: bool,
 * } */
```

Both `settingId` and `state` remain required, matching the pinned required flags.
Although upstream says state is ignored when remove is 1, that does not itself
relax requiredness. Any later conditional omission needs its own source evidence.
Do not derive a state enum or per-ID constraints from examples. State values can
contain configuration secrets, so the entire record collection is sensitive.

```php
// Future generated convenience method, not implemented by this design PR.
$client->whm()->modSecurity()->updateSettings(settings: [
    ['settingId' => 1, 'state' => 'On'],
    ['settingId' => 2, 'state' => 'Off', 'remove' => false],
]);
```

This illustrative input expands in row/declared-field order to:

```text
setting_id1=1&state1=On&setting_id2=2&state2=Off&remove2=0
```

No `remove1` is invented. Actual valid setting/state combinations remain governed
by the endpoint contract. Repeated setting IDs are preserved, not deduplicated;
this is not a claim about how the server resolves conflicting updates.

### Generated alternate method and coverage identity

Retain `batchSettings(int $settingId, string|int $state, ?bool $remove = null)`
unchanged. Add generated `updateSettings(array $settings): array`, documented with
`non-empty-list<ModSecuritySettingInput>` and the same result alias as
`batchSettings()`. Both execute the one canonical `whm1:modsec_batch_settings`
operation with its selected source variant and current form POST policy.

This requires explicit generator support for additional method entries linked to
a canonical operation. An additional method may change only the declared input
mapping; it shares route, method, outcome and response contract. Reject public
name collisions and inconsistent duplicated return contracts. Record both symbols
in coverage mapping while counting the canonical operation only once. The
additional method does not call the scalar method repeatedly or aggregate results.
One failed response cannot trigger a retry of either the whole batch or individual
rows. The reviewed `11.138.0.19` ModSecurity handler sets failure if any row fails;
success means every submitted row was accepted, not that staged changes were
deployed. Both methods prepare staged settings for an explicit
`deploySettingsChanges()` call. A failure can leave some staged settings changed.
The shared canonical Flag contract raises `ApiErrorException` without exposing
`data.failures`; callers must explicitly re-read `getSettings()` after **any**
failure to establish current staged state. No automatic read, retry or rollback
is added. Duplicate IDs retain emitted order, but the server traverses a hash:
there is no deterministic ordering or last-wins guarantee. Avoid conflicting
repeated IDs. These handler facts are source inspection on `11.138.0.19`, not a
live batch-write observation; no unpinned `commit` parameter is added.

## Shared expansion and request lifecycle

Use an ordered field representation rather than merging associative arrays that
can overwrite parameters. Each field carries its origin and, for an explicitly
repeated parameter, a repeat-family identity. Concrete internal classes are left
to the builder; loss of origin information before collision checks is prohibited.

1. The existing handle clears metadata before validation as it does today.
2. Validate the full operation spec and all arguments, including every row/map
   entry, before dispatch. PHP's own signature TypeErrors remain outside this
   lifecycle, as with existing methods.
3. Expand scalar, indexed, repeated, map and record groups in declared argument
   order; preserve collection order. Build all fields without overwriting any.
4. Check every expanded name against the other fields and route reservations.
5. Encode names and values once and dispatch the unchanged GET/form request route.
6. Preserve existing envelope, metadata, per-item payload and exception behavior.

Two distinct origins may not emit the same exact wire name, even if their values
match. Repeated identical names are permitted only within one explicitly declared
repeat family. Indexed groups, map groups, records and fixed parameters cannot
borrow that exception. Collision checks operate on the complete expanded names,
including the field prefix; no last-write-wins rule is allowed.

Reject collisions with reserved fields before the handles' existing filtering
can discard them. Every generated route (WHM, direct UAPI and bridged UAPI)
reserves the entire case-sensitive `api.` prefix, including `api.version`.
Bridged UAPI additionally reserves exactly `cpanel.user`, `cpanel.module` and
`cpanel.function`. The route-aware validator obtains the prefix and exact-name
reservations from the handle and checks every expanded field name. The
[2026-10-31 review](https://github.com/hostingpal/whm-api-client/pull/72#pullrequestreview-5722427592)
attributes this to `Whostmgr/API/1/Data/Args.pm:90` and `Cpanel/Args.pm:86` on
`11.138.0.19`: `api.*` fields are consumed as API controls rather than operation
arguments. For example, `api.filter.enable`, `api.sort.enable`, `api.chunk.enable`
and `api.columns.enable` could change filtering or output while silently removing
a map entry. `uapi_cpanel` consumes the three exact bridge names above. User maps
cannot change the target account, module, function, API version, HTTP method,
endpoint or these API controls.

Legacy generic `call()` filtering remains an explicit compatibility boundary:
this generated-only rule does not globally change its existing reserved-field
filtering. New generated expansion must fail on its own collisions, and old
behavior retains regression coverage. No new generic map/index helper is required
by this spec.

Malformed generated specs fail as `LogicException`; invalid collection contents
fail as `InvalidArgumentException`, with no network request and cleared metadata.
A partial local expansion is never sent. Diagnostics may identify a public
argument, row position and static field name, but never include a map's dynamic
key or a supplied value. All library frames receiving collections or expanded
fields use `SensitiveParameter`. The package cannot control a third-party HTTP
client's trace policy; preserve the existing documentation boundary.

No implicit sorting, chunking, extra inventory reads, retries, rollback, parallel
requests, redirects or alternate-route fallback are introduced.

## Generator and source evidence

Extend the overlay JSON schema, Go overlay/effective contracts, generated PHP
operation specs, `SpecChecker`, argument encoding and generated PHPDoc together.
Use explicit semantic descriptors for index/list/map/record behavior. The exact
JSON member spelling is an implementation detail; the fields and validation rules
above are mandatory. Reject unsupported descriptor combinations during generation,
including missing type/index rules and overlapping static mapping definitions.

New encoding/requiredness/input-mapping decisions need operation-scoped evidence
with the pinned source hash and parameter/prose pointer. Formal properties,
required flags and explanatory text must be inspected together. General support
for a pattern is not evidence that a particular endpoint uses it.

The pinned inputs are the 2026-10-13 snapshots under `docs/api/sources/2026-10-13/`
(OpenAPI version `11.138.0.10`):

- WHM decompressed SHA-256:
  `6edb13c51d53f3c2be1fa83cc93e31d3a81f4f8506b3b45284fa00b986041594`.
- UAPI decompressed SHA-256:
  `172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc`.

### Reviewer evidence and version-scoped contradictions

The pinned contracts above were read locally from immutable documentation
snapshots. Separately, the [2026-10-31 review of `a25aec7`](https://github.com/hostingpal/whm-api-client/pull/72#pullrequestreview-5722427592)
reports upstream implementation inspection on `11.138.0.19`; those Perl files
were not inspected locally for this design correction. That review reports a live
disposable feature-list fixture, while the [later 2026-10-31 correction](https://github.com/hostingpal/whm-api-client/pull/72#issuecomment-6544681850)
adds live create-feature-list and MXCHECK findings from disposable resources.
These reports are distinct from pinned contracts and committed capture artifacts;
this documentation correction performs no live requests.

For the following families, the reviewer's source inspection resolves the
numbering ambiguity on `11.138.0.19`. It does not establish live list behavior or
an already implemented public API:

| Family | Reviewer-inspected reader and accepted wire fields |
| --- | --- |
| Token ACL update | `_parse_and_validate_acls` accepts `acl` or `acl-N` for numeric `N`, including zero- or one-based suffixes |
| Token IP update | `get_length_arguments($args, 'whitelist_ip')` accepts `whitelist_ip` or `whitelist_ip-N`; the pinned `whitelist-ip` spelling is not supported by this reader |
| Conversion job IDs | `_parse_job_ids` accepts `job_id` or `job_id-N`, including zero- or one-based suffixes |
| SPF domain/record pairs | `validate_domain_value_pairs_or_die` through `map_length_required_multiple_to_key_values` pairs the `domain` and `record` families by sorted position; a count mismatch errors |

The review describes `Cpanel::Form::Param` as collecting bare and numerically
suffixed names in numeric order, with UAPI `get_multiple` using the same optional
numeric-suffix family. Implementations must still select one explicit pattern in
each effective operation spec and audit source, item types and empty/scalar policy
per operation. The conflicting pinned examples alone no longer block these
families. No multi-value list form in this table was observed live by that review.

Token update has a separate omission contradiction. Pinned
`/paths/api_token_update/get/parameters` text describes omitted ACLs, IPs and expiry
as resets. The reviewer-inspected `update_token` on `11.138.0.19` copies only supplied
fields, preserving omitted ACLs, IPs and expiry; `_build_create_or_update_args`
drops empty lists. The creation-only all-privileges default must not be attributed
to this update handler. The reviewer corrects their earlier PR57 interpretation
and reports source-based rename semantics: a generic
`whm()->call('api_token_update', ...)` with only `token_name` and `new_name` preserves
other fields on that version. No root-credential execution established this.
Keep the current stricter `updateToken()` signature and production documentation
unchanged in this design PR; `whm-api-client-y9y` and `8x0.1.21` own the separate
overlay/signature follow-up, including version compatibility and list support.

MXCHECK maps are excluded from typed adoption in this design. The pinned
`/paths/massmodifyacct/get` and `/paths/modifyacct/get` parameter schemas describe
an integer priority, but the later correction supersedes the review's initial
approval. The reviewer reports that calls with words and integers returned success
without changing the stored MXCHECK setting on `11.138.0.19`. Their source inspection
identifies the actual constants in `Whostmgr/DNS/Constants.pm` as `auto`, `local`,
`remote` and `secondary`; this does not establish a working word-map replacement
for those operations. Preserve the pinned discrepancy and defer this adoption
until operation-specific effective behavior is established. `8x0.1.29` retains its
database-copy adopter; the MXCHECK limitation is separate.

### Initial adoption and unresolved variants

| Source operation/parameter | Agreed shape / evidence gate | Existing bead |
| --- | --- | --- |
| UAPI DNSSEC enable/disable/fetch DS `domain` | Scalar or string list; list uses `domain-0`, `domain-1` | `8x0.1.21` |
| UAPI SpamAssassin update preference `value` | Preserve scalar/null; list uses `value-0`, `value-1` | `8x0.1.20` |
| WHM token create/dashboard ACL/IP, token revoke, TFA user removal | Apply each evidenced pattern separately; revoke/TFA start at 1 | `8x0.1.21` |
| WHM token ACL/IP update | Source-supported `acl`/`acl-N` and `whitelist_ip`/`whitelist_ip-N` on `11.138.0.19`; explicit pattern, type and policy audit still required | `8x0.1.21` |
| WHM account conversion status `job_id` | Source-supported `job_id`/`job_id-N` on `11.138.0.19`; retain integer type and choose explicit pattern | `8x0.1.21` |
| WHM SPF domain/record pairs | Source-supported sorted-position pairing on `11.138.0.19`; related records and explicit pattern/policy required, not independent parallel arrays | `8x0.1.21` |
| WHM ModSecurity batch settings | Related records; suffix 1, 2; scalar method preserved | `8x0.1.24` |
| WHM ModSecurity report row IDs | Comma-separated representation is separate from numbered encoding; source/type audit stays in its bead | `8x0.1.24` |
| WHM Greylisting provider flags | Identity-key boolean map; autotrust remains fixed | `8x0.1.25` |
| WHM AutoSSL provider settings/reset | String map with `x_` prefix, entire map sensitive | `8x0.1.28` |
| WHM addon conversion database copies | String map with `copymysqldb-` prefix | `8x0.1.29` |
| WHM feature-list create/update | Required `features` boolean map; reject empty update, allow empty create with version-scoped all-disabled evidence | `8x0.1.31` |
| WHM mass DNS edit removal indices | Typed repeated integer list; add/edit JSON strings unchanged | `8x0.1.30` |

These rows refer to the named parameter descriptions on the corresponding pinned
`/paths/<operation>/get` (or selected variant) nodes; they do not authorize copying
a family to unlisted endpoints. Endpoint beads preserve finer source citations.
`install_ssl.domain_dcv_method` (`8x0.2.16`) remains outside this mapping because its
actual wire serialization is not established. Generic map support cannot decide it.

## Acceptance evidence

Independent literal fixtures must establish exact URI, Content-Type, form bytes
and account routing, rather than building expected strings with the new encoder.
Each applicable family needs tests for zero/one/multiple values, singleton-list
versus scalar behavior, sparse lists, wrong types, null entries, false/zero/empty
strings, unchanged duplicates/order and late invalid entries producing zero I/O.

Map fixtures include integer-coerced keys, empty/control/invalid UTF-8 keys, dots,
hyphens and percent-encoded data, fixed/prefix/cross-family collisions in both
orders, and sensitive diagnostics. Reservation fixtures cover WHM, direct UAPI
and bridged UAPI: reject `api.version` and the full case-sensitive `api.` prefix
(including `api.filter.enable`, `api.sort.enable`, `api.chunk.enable` and
`api.columns.enable`) on all three routes, plus exactly `cpanel.user`,
`cpanel.module` and `cpanel.function` on the bridge. Include case distinctions and
unreserved lookalikes so the prefix/exact-name rules cannot become broader by
accident. Keep legacy generic filtering regression expectations separate.
Record fixtures include optional fields present only in later rows, missing
required keys, unknown keys, nulls, mixed state types and whole-batch rejection.
An allowed empty map must still issue the expected fixed-parameter request.
Feature fixtures must reject missing maps for both methods and empty updates
before I/O, accept explicit empty create maps with the fixed request, and preserve
false values. These are future implementation requirements; an offline fixture
does not prove server state changes or broaden the reviewer's version-scoped
all-disabled create observation.

Generation tests cover strict PHPStan shapes, scalar signature compatibility,
malformed metadata rejection, aliases sharing one canonical coverage count,
source evidence requirements and unchanged unsupported exclusions. Runtime tests
cover metadata reset, failure snapshots, existing result contracts and one request
per call, including API failure and payload mismatch after an accepted outcome.

Live tests are endpoint-specific and separately tracked. Offline support does not
establish server compatibility, batch atomicity or enforcement. Any future owned
fixture must preserve existing scope, cleanup and immutable structural-evidence
rules. No live requests are needed to publish this design.

## Delivery and Beads ownership

Beads is the durable implementation plan; this document is the design contract,
not a Markdown task checklist. No implementation is started by this PR.

| Bead | Deliverable / prerequisite |
| --- | --- |
| `8x0.28.1` | Publish this design and link the work graph |
| `8x0.28.2` | Shared ordered expansion, collision checks and lifecycle protection |
| `8x0.28.3` | Indexed/typed-repeat descriptors, generation and compatibility; depends on .2 |
| `8x0.28.4` | Named-map descriptors, generation and compatibility; depends on .2 |
| `8x0.28.5` | Related-record generation and canonical-operation method aliases; depends on .3 |
| `8x0.28.6` | Integrated public compatibility/adoption audit after representative endpoint adoption |

Existing endpoint issues stay open and retain their descriptions and source/live
findings. Indexed consumers depend on .3; maps on .4; ModSecurity records on .5;
DNS removal support on .3 without implying the composed DNS-write gap is solved.
The final audit waits for the first indexed, named-map and related-record adopters,
not every unresolved upstream contradiction. No currently merging branch is edited.

Implementation plans should be filed in the owning Bead with exact files, interfaces
and failing-test-first steps when the relevant work begins. Source-specific method
names not fixed above, internal helper layout and test organization are left to the
builder; changing these public semantics requires a reviewed design amendment.
