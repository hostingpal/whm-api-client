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
`[]` into omission: missing token ACLs can grant all available privileges, and a
missing SpamAssassin value can delete a preference. Any future empty-list exception
needs operation-specific evidence, an explicit encoding and its own tests.

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
| `['example.com' => 10]` | `MXCHECK-` | `MXCHECK-example.com=10` |
| `['old_database' => 'new_database']` | `copymysqldb-` | `copymysqldb-old_database=new_database` |
| `['terms_of_service_accepted' => $url]` | `x_` | `x_terms_of_service_accepted=<encoded URL>` |

A map must contain nonempty string keys. Reject integer PHP keys, including
numeric strings PHP has already converted to integers; their original spelling
cannot be recovered. Do not cast them back. PHP has already overwritten exact
duplicate keys before receiving an array, so the client cannot detect those lost
entries. It can and must detect collisions introduced during wire expansion.

Preserve key case, dots, hyphens and value bytes. Do not trim, normalize domains,
strip an apparent prefix, decode percent escapes or convert identifiers. Prefix
concatenation is literal: a caller who includes `x_` in a logical key will not have
it stripped automatically. PHPDoc examples must make this convention clear.

As an engineering transport rule, reject invalid UTF-8 keys and control bytes
(U+0000–U+001F and U+007F). Do not invent a universal feature/provider/domain-name
regex. Names and values are RFC 3986 encoded once after validation; `&`, `=`, `%`
and similar bytes are data, not manually assembled separators. The encoder does
not promise the server accepts every syntactically representable identifier.

Map value types are operation-specific. Feature/Greylisting maps use booleans;
MX priorities use integers; database targets and AutoSSL provider parameters use
strings. Credential-bearing maps are sensitive in their entirety. Accepting a
terms-of-service URL does not fetch it or imply that the client accepted terms on
the user's behalf: the caller deliberately supplies the documented value.

### Empty maps, omission and replacement behavior

Allowed empty maps expand to zero dynamic fields, but the method still sends its
one request with the fixed fields. Never short-circuit the call as a no-op.

- `update_featurelist`: no supplied features disables all features. The new
  generated `updateFeaturelist(string $featurelist, array $features)` therefore
  requires the map argument; `features: []` explicitly requests the documented
  clear behavior. Values and their 0/1 meaning stay source-derived. This spec does
  not invent how omitted individual features behave in a nonempty update.
- `create_featurelist`: use the same deliberate required `features` argument,
  including an explicit empty map when the caller wants no dynamic feature fields.
  Preserve `overwrite` as a separate optional argument with its existing source
  meaning. An empty create map does not acquire an invented clear interpretation.
- Greylisting provider maps may be optional. Omitted/null and allowed `[]` send no
  provider-specific fields, leaving existing providers unchanged by those fields.
  Other arguments and upstream defaults, including autotrust, may still act.
- Optional database-copy/MX maps preserve omitted behavior; the operation's source
  defines what absence means. Empty maps add no map fields, without suppressing the
  remaining operation.
- AutoSSL provider maps can be empty at the encoding layer. Provider requirements
  remain server-specific; do not synthesize credentials or legal-acceptance fields.

The exact PHP argument names for newly introduced map-only operations are chosen
in their endpoint adoption bead, checked against source meaning and existing group
names. The `features` names above are fixed public choices for feature-list calls.
An already published method signature must not be changed by that later choice.

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
rows, and one successful envelope does not imply atomic per-setting success.

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

Reject collisions with reserved routing fields before the handles' existing
reserved-field filtering can discard them. WHM reserves `api.version`; bridged
UAPI additionally reserves `cpanel.user`, `cpanel.module` and `cpanel.function`.
The route-aware validator obtains these names from the handle. User maps cannot
change the target account, module, function, API version, HTTP method or endpoint.
Do not globally change legacy generic-call reserved-field filtering in this work;
new generated expansion must fail on its own collisions, and old behavior has
regression coverage. No new generic map/index helper is required by this spec.

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

The pinned inputs are the 2026-10-13 snapshots under `docs/api/sources/2026-10-13/`:

- WHM decompressed SHA-256:
  `6edb13c51d53f3c2be1fa83cc93e31d3a81f4f8506b3b45284fa00b986041594`.
- UAPI decompressed SHA-256:
  `172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc`.

### Initial adoption and unresolved variants

| Source operation/parameter | Agreed shape / evidence gate | Existing bead |
| --- | --- | --- |
| UAPI DNSSEC enable/disable/fetch DS `domain` | Scalar or string list; list uses `domain-0`, `domain-1` | `8x0.1.21` |
| UAPI SpamAssassin update preference `value` | Preserve scalar/null; list uses `value-0`, `value-1` | `8x0.1.20` |
| WHM token create/dashboard ACL/IP, token revoke, TFA user removal | Apply each evidenced pattern separately; revoke/TFA start at 1 | `8x0.1.21` |
| WHM token ACL update | Prose and examples disagree on first index; remains gated until resolved | `8x0.1.21` |
| WHM account conversion status `job_id` | Integer family has conflicting index examples; remains gated | `8x0.1.21` |
| WHM SPF domain/record pairs | Domain numbering does not establish the record pattern; remains gated | `8x0.1.21` |
| WHM ModSecurity batch settings | Related records; suffix 1, 2; scalar method preserved | `8x0.1.24` |
| WHM ModSecurity report row IDs | Comma-separated representation is separate from numbered encoding; source/type audit stays in its bead | `8x0.1.24` |
| WHM Greylisting provider flags | Identity-key boolean map; autotrust remains fixed | `8x0.1.25` |
| WHM AutoSSL provider settings/reset | String map with `x_` prefix, entire map sensitive | `8x0.1.28` |
| WHM addon conversion database copies | String map with `copymysqldb-` prefix | `8x0.1.29` |
| WHM bulk account MX values | Integer map with `MXCHECK-` prefix | `8x0.1.29` |
| WHM feature-list create/update | Required `features` boolean map; identity keys | `8x0.1.31` |
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
orders, reserved names on both WHM and bridged UAPI, and sensitive diagnostics.
Record fixtures include optional fields present only in later rows, missing
required keys, unknown keys, nulls, mixed state types and whole-batch rejection.
An allowed empty map must still issue the expected fixed-parameter request.

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
