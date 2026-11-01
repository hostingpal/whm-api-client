# Offline API map tool

This Go 1.24+ development tool uses pinned official WHM API 1 and cPanel UAPI
sources. It does not add a Go runtime dependency to the PHP library. Install
Composer dependencies from `composer.lock` and Go dependencies from this module's
`go.mod`/`go.sum` before running checks; package installation may use the network.
Run the following commands from the repository root.

```bash
export GOCACHE="$PWD/.cache/go-build"
export GOMODCACHE="$PWD/.cache/go-mod"
go -C tools/api-map mod download
go -C tools/api-map mod verify
go -C tools/api-map test ./...
go -C tools/api-map vet ./...
composer validate
composer check
composer api-map:check
```

`composer check` remains the PHP-only gate. `composer api-map:check` invokes
`go -C tools/api-map run ./cmd/api-map check --root ../..`. The binary resolves
[toolchain.json](../../docs/api/toolchain.json) paths from that explicit root.
Configuration is schema validated, contains only local paths and artifact
metadata, and cannot supply executables, shell snippets, tokens, or live hosts.

The check verifies source bytes/references, published/embedded schema equality,
two byte-identical map builds and the reviewed checksum, the overlay against
the map, that committed generated code is current (including stale generated
files and hand-written files at rendered paths), and synthetic capability/drift
examples. All generated check outputs are temporary and cleaned on success,
failure, and cancellation. It never refreshes sources, captures a server, or
rewrites repository files. Use `GOPROXY=off GOSUMDB=off` after dependency installation to prevent
Go dependency fetching during ordinary checks.

The binary exits 0 on success, 1 for invalid inputs/contracts or stale generated
artifacts, and 2 for usage errors. `go run`/Composer can wrap nonzero process
exits; build and run the binary directly when an automation needs the exact code.

## Explicit artifact regeneration

Regeneration is a review action, separate from `check`. Preserve pinned source
snapshots; inspect source/annotation changes and diagnostics before accepting a
new checksum. These commands replace their named outputs:

```bash
go -C tools/api-map generate ./internal/schema
go -C tools/api-map run ./cmd/api-map sources verify --manifest ../../docs/api/sources/2026-10-13/manifest.json
go -C tools/api-map run ./cmd/api-map map build --manifest ../../docs/api/sources/2026-10-13/manifest.json --annotations ../../docs/api/annotations.json --output ../../.cache/api-map/map.json
sha256sum .cache/api-map/map.json > docs/api/map.sha256
composer api-map:check
```

Generated PHP and `docs/api/coverage.md` are regenerated with
`composer api-map:generate`; see "Overlay and PHP generation" below.

## Overlay and PHP generation

The generated client (see `docs/superpowers/specs/2026-10-14-generated-client-design.md`)
is rendered from the canonical map plus `docs/api/overlay/`:

- `config.json` sets `scope` to `listed` (only operations named in group files) or
  `all` (every operation in the map). In both scopes an operation whose entry has
  `exclude` (`reason` plus evidence) is left out and listed in
  `docs/api/coverage.md`; an excluded entry takes no other settings.
- `uapi/<Module>.json` and `whm/<group>.json` hold one group each: `family`
  (`uapi` or `whm1`, matching the directory), `group` (the UAPI module or the
  documented WHM `x-tagGroups` name), optional `accessor`, `class` and
  `description`, and `operations` keyed by map ID (`uapi:Module/function`,
  `whm1:function`). Files are validated against
  `docs/api/schema/overlay-v1.schema.json`. A group may appear in one file only,
  and the group must be documented in the map (exclusion-only files are valid).
  WHM groups need an `accessor`; UAPI accessors and classes derive from the module.

Operation entries (all keys optional):

- `function`: evidence-gated WHM wire correction from a bare canonical function to
  exactly `Namespace/same_leaf`, using identifier namespace and leaf. UAPI, excluded
  operations, different leaves, paths, query strings and URLs are rejected. This
  changes the generated function spec and effective contract hash; canonical map
  IDs, variants, source hashes and documentation links remain unchanged.
- `name`: the PHP method name. Required when the function has no `_` separator,
  starts with `_`, or contains `/`; otherwise the camelCase function name is used.
- `variant` (`GET`/`POST`): the documented variant to generate, required for an
  operation the map documents with several variants (UAPI `WebApp/stage` and
  `WebApp/redeploy`); it selects that variant's parameters, request body and
  documentation link.
- `params`, keyed by wire name: `php` renames the argument (required when the
  derived name is invalid or `this`/`result`), `encoding` is `scalar`, `bool`,
  `repeat` (repeated keys), `numbered` (`name`, `name-1`, ...),
  `suffix-numbered` (`action*` becomes `action1`, `action2`, ...; string lists),
  or `suffix-numbered-scalar` (the same suffixes, string or integer items). `sensitive`
  overrides secret detection (names split at separators and case boundaries;
  a parameter the heuristic leaves non-sensitive needs an explicit `sensitive`
  decision when any word of its name is `key` or `signature`, or its pinned
  description mentions an authorization code or verifier), and `omit` drops the
  parameter from the signature (it stays reachable through `call()`); name
  patterns such as `MXCHECK-*` need an explicit named-map descriptor (or omission),
  and a required parameter cannot
  be omitted (exclude the operation instead). A union of scalar schemas becomes
  the union of their PHP types; an object, map or a union with a list, map or
  object branch needs an `encoding` (or `omit`). `required` overrides the pinned
  requiredness, preserving false separately from omission. `enum_doc: false`
  suppresses only the PHPDoc literal enum in favor of the existing native type;
  absent or true keeps the pinned enum. It changes neither encoding nor native
  parameter types and does not resolve disputed upstream values.
  `collection` instead declares ordinary strict list/map inputs without markers:
  `{"kind":"indexed","item_type":"string","allow_scalar":true,"empty":"reject",
  "prefix":"domain","separator":"-","start":0}`. Every fact is required;
  `kind: "repeat"` omits and forbids prefix/separator/start. Item types are `string`,
  `int`, `bool` (encoded as 1/0), or evidenced `string|int`; floats/null entries are
  unsupported. Indexed prefixes are literal simple ASCII identifiers, separators
  are `""` or `"-"`, and starts are integers from 0 through 2147483647. The runtime
  also rejects index overflow. A permitted scalar keeps the original unsuffixed
  wire name; even a singleton array uses the declared list pattern. Every list
  must be nonempty and sequential, preserving order, duplicates, empty strings,
  zero and false for the selected item type. Optional null still omits the whole
  argument. Native array/scalar unions and `non-empty-list<T>` PHPDoc are generated;
  source string/integer enum literals appear in both the scalar alternative and
  the list item type, including resolved item references. `enum_doc: false`
  retains its explicit documentation override; boolean flag conversion stays
  `bool`. An evidenced `string|int` widening retains source literals for the
  source kind and admits the other kind without inventing literals. Literal
  schemas that cannot be represented truthfully fail generation with a parameter
  diagnostic rather than silently widening. This adds no runtime enum validation.
  All collection arguments receive `SensitiveParameter` for trace protection,
  independently of secret-bearing transport classification. Source/overlay secret
  decisions still require POST; ordinary read-only collection inputs retain GET.
  `kind: "map"` requires `allow_scalar: false`, `empty: "allow"` or `"reject"`,
  and a mandatory literal `prefix` (empty or `[A-Za-z_][A-Za-z0-9_-]*`); it forbids
  separator/start. For example `{"kind":"map","item_type":"bool",
  "allow_scalar":false,"empty":"allow","prefix":""}` accepts a feature map,
  while prefix `copymysqldb-` appends caller keys literally. No prefix stripping or
  wildcard inference occurs. The native type is `array` (`?array` when optional),
  with `array<string,T>` or `non-empty-array<string,T>` PHPDoc. An allowed empty map
  emits no dynamic fields, while fixed fields and the request still dispatch.
  Optional `reject_prefixed_keys: true` requires a nonempty map prefix and
  rejects caller keys already beginning with that prefix without rewriting. Only
  the two AutoSSL provider descriptors declare it; other maps remain literal.
  Map values must match the declared strict scalar item type, backed by the
  source scalar schema and matching parameter evidence. Keys must be nonempty
  valid UTF-8 strings without U+0000–U+001F/U+007F; integer keys, including PHP's
  coercion of canonical numeric string keys, fail. Dots, hyphens, case, percent,
  ampersand, equals and retained string keys such as `"01"` are preserved. Each
  literal key/value is RFC3986 encoded once. Map source placeholders are never
  emitted; use `php` to give placeholder parameters a meaningful public name.
  Actual map collisions with fixed, other map/list and route fields fail before
  dispatch in either order without exposing keys or values in diagnostics.
  Collection descriptors cannot combine with `encoding`, `type`, `wire`, `omit`, `omit_empty` or
  `reject_empty`. Static indexed overlaps fail generation and whole-spec lint;
  expanded collisions fail before dispatch, retaining the ordered-field engine's origin checks.
  Each collection override needs operation-level `doc` evidence with
  `source_sha256` matching that source parameter's locator and `pointer` matching
  its parameter JSON pointer or that pointer plus `/description`. These structured
  fields are mandatory only for the new collection override; existing evidence
  entries keep their original contract. A fixture proves grammar support, not
  endpoint or live compatibility. Inspect the formal source and prose before
  choosing a pattern; the generator does not infer it from operation names.
  `wire` corrects one scalar string, integer or number parameter's transmitted
  name with operation-level evidence. Both names must be simple ASCII identifiers
  (`[A-Za-z_][A-Za-z0-9_]*`), excluding routing fields (`api.version`, `cpanel.*`),
  indexed names and wildcard families. Omitted, boolean, repeated and composite
  inputs cannot use it, and duplicate final wire names fail generation. Overlay
  keys and `order` still use the pinned source name; the public PHP name, type,
  requiredness and sensitivity retain their existing rules.
  `type: "string"` narrowly corrects a concrete enum-only scalar schema with no
  `type` or `format`, a nonempty all-string enum, and only optional `example`,
  `description` or `title` annotations. Nullable, composed, referenced, object,
  list, mixed/numeric/null enums and content-based JSON parameters are rejected.
  It requires `doc` evidence with the exact parameter `/schema` pointer and
  `source_sha256`, bound to the selected operation source and parameter pointer.
  A POST method override does not change that source-variant binding. Encoding
  must be absent or `scalar`; wire corrections and collections cannot combine.
  It preserves literal enum PHPDoc, requiredness, sensitivity and existing scalar
  form encoding; unannotated typeless/format-only sources retain their fallback.
  This is native PHP typing, not runtime enum-membership validation. Strict-caller
  integers fail before dispatch or metadata reset; normal weak-caller scalar
  coercion remains PHP behavior.
  `type: "string|int"` widens a documented scalar string or integer parameter
  with matching scalar encoding, for reviewed identifier variants or
  comma-delimited values. `type: "string|int|float"` applies only to a documented
  scalar number parameter whose native type is `int|float`; it adds exact string
  input while retaining both numeric types. This permits passing a version such
  as `"10.10"` from discovery without losing its trailing zero. Strings are
  forwarded unchanged; the override does not validate version syntax or repair
  precision already lost in a caller's float conversion. Unselected number
  parameters retain `int|float`. Neither override can target omitted,
  boolean/flag, repeated or composite inputs. `description` replaces the
  complete parameter text with a nonblank
  reviewed description, preserving later sentences. Both settings require
  operation-level evidence; requiredness, sensitivity and optional null omission
  retain their existing rules.
  `type: "true"` is a separate evidence-gated correction for a concrete,
  nonnullable scalar integer source with exactly `enum: [1]`. Source and final
  requiredness must match: optional inputs generate `?true` with a null default;
  required inputs generate `true` with no default. Encoding must be absent or
  `bool`, and a simultaneous wire-name correction is rejected. The existing
  boolean encoder sends true as `1`; null omits only optional inputs. PHP rejects
  false, integers (including `1`) and strings before the runner, in strict and
  weak callers. Required inputs also reject null and omission. Native parameter
  rejection does not clear prior response metadata. No singleton enum changes
  implicitly; ordinary zero/one flags still accept false and emit `0`.
  Composite, nullable and omitted inputs cannot use this correction, and it
  adds no response type. Optional presence-only privilege inputs still retain
  each operation's reviewed replacement semantics.
  `omit_empty: true` is an explicit evidence-gated exception for optional
  `repeat` parameters whose pinned source is a concrete, nonnullable array of
  nonnullable strings. It omits exactly `[]`, alongside the existing null
  omission, while strings and nonempty lists keep their existing encoding and
  validation. Source-required, effectively required, omitted, scalar, composed
  and other-encoding inputs are rejected; `true` is the only accepted present
  value. The setting flows into the effective contract and generated runtime
  spec. Only `ea4SaveProfile(tags: [])` selects it; required packages and other
  list operations retain empty-list rejection.
  `reject_empty: true` is an evidence-gated client target-scope policy. It requires
  a required, concrete, nonnullable string or repeat-encoded string/list source,
  without enums, composition, omission or simultaneous type correction. Only
  scalar/repeat encodings are supported. Runtime validation rejects missing/null,
  empty or keyed lists, nonstring entries, blank strings and trimmed `0`; the
  latter guards Perl-falsey all-domain fallback. Values are not trimmed or DNS
  validated. The policy currently selects only WHM apply/remove DMARC domains;
  unflagged parameters and generic calls retain existing behavior. Typed native
  missing/null failures occur before metadata reset; runner policy failures clear
  metadata without I/O. Deliberate all-domain calls use documented generic POSTs.
- `order`: wire names moved to the front of the signature in that order;
  required arguments always precede optional ones, so an order that lists an
  optional parameter before a required one, or an omitted parameter, is a problem.
- Typed complete JSON bodies use the source-bound `body: "json"` / `json_body`
  grammar documented in [the input guidance](../../docs/api/README.md#typed-whole-json-body-grammar).
  Whole-body execution requires a nonnullable object root, no query parameters,
  exact application/json and direct routes. WHM adds root api.version:1 first;
  direct UAPI retains the encoded object. WHM JSON specs must require the body to
  retain API1 routing. On direct UAPI only, optional array/null means absent body,
  while [] means a present empty object. Native array TypeErrors precede metadata
  reset in strict and weak callers; nested array fields stay strict in either mode.
  The shared input grammar still represents scalar/list/object values for field
  JSON. Explicit boolean nonempty defaults off on lists/strings; true means
  non-empty-list or non-empty-string, rejecting [] or the zero-byte string only.
  The narrow StatsManager domain correction does not trim or validate hostnames.

- `body` (`multipart`) selects reviewed inline string multipart encoding; `body_fields`
  maps explicitly corrected body property names to existing query parameter names.
  Both require operation-level evidence.
- `method` (`GET`/`POST`), `outcome` (`flag`, the default, or `unchecked`),
  `void` (no payload) and `unwrap` (return one documented object field; exclusive
  with `void`). Read-only operations default to GET; mutating, unknown-risk and
  secret-bearing ones default to POST, and GET is refused for secret-bearing ones.
- `rows`: a nonempty declared result-field name for an unchecked UAPI object
  batch, or `$` selecting a nonnullable root list of nonnullable objects, without
  void, optional_payload or unwrap. Root, list and object rows must
  be nonnullable. Exact source hash/pointer evidence binds the selected row field
  in the reviewed result/data response schema; other source layouts are unsupported.
  Status 0 plus explicit null or a fully validated empty row list raises API error;
  missing data/rows or malformed known fields remain payload mismatches. Status 1
  keeps pinned optional fields. Fileman/upload_files selects its uploads field; ImageManager/create_thumbnails
  selects the whole list with `$`.
  For root lists, evidence must bind the exact selected source SHA and
  `/responses/200/content/application~1json/schema/properties/result/properties/data`
  pointer. `$` on object roots remains a literal declared field name with its
  existing field evidence binding. Whole payload validation precedes empty-list
  classification; other Unchecked operations do not inherit this policy.

- `optional_payload`: an omitted or null `data` member is a valid response; the
  spec gains `'optional' => true`, the method's return type becomes nullable and
  it returns null in that case (other payloads are validated as usual). Needs a
  documented payload and is exclusive with `void`.
- `evidence`: operation-level evidence, required when the entry sets `variant`, `method`,
  `outcome`, `rows`, `void` or `optional_payload`, changes a parameter `required` setting,
  suppresses its enum documentation (`enum_doc: false`), sets a parameter `encoding` (it
  changes the wire request), corrects a parameter `wire`, `type` or `description`, or marks
  a parameter `"sensitive": false`.
- `notes`: docblock lines, each `{"kind", "text"}`. Kind `documented` restates
  the pinned documentation and renders as "Documented:"; kind `observed` records
  runtime behaviour, renders as "Observed:" and needs live operation-level
  evidence. Kind `policy` renders as "Policy:" for client transport, sensitivity,
  encoding or other client decisions, without claiming upstream or live evidence.
  A policy note does not waive live evidence required by a separate observed note.
  `probe` is stored, not read by the generator.
- `response`: corrections keyed by payload path. A path is dot-separated field
  names; `[]` steps into list items and `{}` into map values, as in `acct`,
  `acct[]`, `data{}` or `acct[].uid`; the empty path is invalid. A backslash escapes
  a literal dot in a field name: `[].issuer\.organizationName` addresses the
  literal `issuer.organizationName` key, while `[].issuer.organizationName`
  addresses nested fields. JSON writes that backslash as `\\`; trailing and
  other escape sequences are rejected. Keys: `type`
  (a scalar type; keeps nullability), `accept` (added to the variants the
  schema already implies: `numeric-string` for int and
  number, `integral-fixed-point-string` for int only (canonical bounded integer
  part followed by a dot and zero-only fraction, without float conversion), `int-flag`/`string-flag` for bool, `string-flag`/`bool`/`empty-string`
  for flag). `empty-string` preserves only the exact empty string as `''|0|1`;
  it does not coerce it to 0 or accept whitespace or other strings. Corrections
  also support `nullable`, `preserve` (number only), `required` (object fields only), `add`
  (a full type spec declaring an undocumented field) and `replace` (a full type
  spec replacing a documented node entirely, not combined with `type` or `add`).
  Every correction needs evidence. Type specs need their companion keys: list
  `items`, map `values`, union `anyOf`, object `fields`. String specs can declare
  an explicit `literal` string, matching that exact value (including an empty
  literal); generated PHPStan types retain it. This opt-in constraint is never
  inferred from upstream enums and is invalid on other types. Only `replace` may
  address the payload root, with the empty path.

A narrowly evidenced retrieval exception can restore an omitted payload contract:
only WHM GET operations with `x-readonly=true`, a resolved plain 200 JSON object
whose only property is `metadata`, and no payload issue or partial alternatives
qualify. The overlay must supply exactly one root response replacement with an
unadorned `mixed` spec and valid correction-level `live` evidence. `void`,
`optional_payload`, `unwrap`, exclusions, compositions and payload siblings do
not qualify. This preserves opaque observed data without inferring fields;
missing data still fails in the existing runtime. Ordinary corrections to a
documented payload remain unchanged. PR70 review 5721307565 supplies the
attributed retrieval observation; it is separate from source-only corrections.

The generator reads the pinned schemas as follows. `allOf` merges its parts and
its sibling `properties`/`required`, skipping parts that carry only annotations.
A `oneOf`/`anyOf` branch that admits only null makes the union nullable; two or
more object branches, and the schemas of a base schema's discriminator mapping,
merge into one object holding every branch's fields (required only when every
branch requires them), so the engine's first-match union rule cannot drop
fields that only later branches declare; list and map branches likewise merge
their elements. Inside an `allOf` a referenced base is read without expanding
its mapping. A 0/1 enum becomes a flag (documented
as strings, it also accepts `"0"`/`"1"`), and an enum with a null member is
nullable. A node the engine cannot represent (`properties` beside
`additionalProperties`) is a problem until a `replace` covers it. A 200 envelope
that cannot be resolved is a problem until the entry declares `void`, and data
documented in only some `oneOf`/`anyOf` envelope alternatives needs
`optional_payload`. The rules the runtime `SpecChecker` enforces before every
call are problems here too: every `required` name must be a declared field (also
after `replace` or `add`), `unwrap` needs a non-nullable object payload, and a
wire name may appear only once.

Additional public input mappings use operation-level `additional_methods`:

```json
"additional_methods": [{
  "name": "updateSettings",
  "inputs": [{
    "php": "settings",
    "records": {
      "alias": "ModSecuritySettingInput",
      "start": 1,
      "separator": "",
      "sensitive": true,
      "fields": [
        {"source": "setting_id", "php": "settingId", "prefix": "setting_id"},
        {"source": "state", "php": "state", "prefix": "state"},
        {"source": "remove", "php": "remove", "prefix": "remove"}
      ]
    }
  }]
}]
```

Each additional method declares a valid, unique PHP `name` and nonempty `inputs`.
Each input has `php` and exactly one of `source` or `records`. A scalar `source`
retains its canonical encoding/type/requiredness/sensitivity under a new PHP name;
it is the pinned parameter name, even when `params[source].wire` corrects its
transmitted name. Every canonical source parameter must be mapped exactly once
across scalar inputs and record fields; unknown, duplicate and missing mappings
fail generation. Required inputs must precede optional inputs. Public method,
spec constant and PHPStan alias collisions fail generation.

A `records` descriptor requires every shown member: a unique PHPStan class-style
`alias`, integer `start` from 0 through 2147483647, `separator` (`""` or `"-"`),
explicit boolean `sensitive`, and nonempty ordered `fields`. Every field requires
a unique valid PHP `php` name, a pinned `source` name and literal ASCII identifier
`prefix` (`[A-Za-z_][A-Za-z0-9_]*`). Field types and requiredness derive from the
canonical strict scalar source (`string`, `int`, `bool`, `string|int`); no field
type, result, route or outcome can be declared independently. Guarded/omitted or
collection source fields cannot become records. Complete selected-source field
prose is preserved with the established Markdown conversion and PHPDoc escaping,
including text beyond the first sentence; literal PHPStan shapes stay precise.

Every scalar/record source mapping needs operation-level `kind: "doc"` evidence
with `source_sha256` matching the selected parameter locator and `pointer` equal
to its parameter JSON pointer or that pointer plus `/description`. Inspect all
formal fields and prose before choosing prefixes/indexes; source-name lookup and
evidence binding stay separate from corrected wire names. Evidence for a sibling
operation, a schema pointer, or a different source hash cannot bind the mapping.

The required record argument is `non-empty-list<Alias>`: each row is an associative
array with only known fields, required keys present and strict values. Explicit
null is rejected even for optional fields; omission skips only that field and
never shifts later indexes. Expansion uses original row order and declared field
order, preserving duplicates, empty strings, zero and false within the declared
type. Server processing order or duplicate resolution is operation-specific.
Static record/index/legacy overlaps fail whole-spec lint as `LogicException` before
arguments or I/O; actual collisions with maps/routing fields remain invalid
arguments. All record arguments are trace protected. `sensitive: true` is a
separate secret classification requiring canonical POST; a false descriptor
cannot downgrade a sensitive source field. Records require canonical form bodies.

A source input can explicitly select `"json": {"alias": "ProviderDisplayInput"}`
to expose its pinned `content.application/json.schema` as a structured PHP value
in one ordinary form field. This requires a canonical form body and a query
parameter with exactly that content media entry and no sibling schema. The same
source hash/parameter-pointer evidence is required. The `Input` alias and strict
request grammar derive from the selected schema, including scalar, list and object
roots; they are independent of whole-body JSON's object-only/no-query policy.
Required allowed null emits JSON `null`; optional nullable roots are unsupported,
while optional nonnullable null omits the field. Unknown object keys, invalid types,
integer object keys, nonsequential lists and invalid UTF-8 fail before dispatch.
Empty object input `[]` becomes `{}`. Serialization occurs once before the existing
form encoding, preserving strings and input order without default insertion or
provider validation. The whole input is sensitive. The legacy canonical string
mapping remains unchanged; selecting this companion does not add an operation or
establish live compatibility.

An additional method changes only inputs. Generated alias specs inherit the
canonical route, method, selected variant, response shape/result alias,
optional/unwrap rules and outcome, replacing only `params`. No separate response,
`Unchecked` override or failure schema belongs in `additional_methods`. Both
symbols appear in coverage while the canonical operation counts once. The
ModSecurity alias is one extra public method across the current 1,154 canonical
operations, 100 groups and 202 generated PHP files (203 outputs with coverage.md).
It issues one request, without batching loops, automatic reads, retries or rollback.

Query parameters with `content` support exactly one `application/json` media entry
with a resolvable schema, no sibling `schema`, and absent or `scalar` encoding.
They become native `string` arguments (`?string` when optional), carrying caller-serialized
JSON text in the existing query/form field. Collection and type overrides, other or
multiple media, unresolved/missing schemas, content on body fields and sibling schemas
fail generation. This default mapping does not parse, validate or re-encode JSON
or provide JSON request bodies. The explicit additional-method JSON-field selection above is the
separate opt-in for structured PHP arguments. Null omission and one outer
form/query encoding pass retain their existing behavior. Strict callers get native
`TypeError` before the generated method or metadata reset; weak callers retain PHP coercion.
Unlisted/excluded operations remain ungenerated.

Format-only typeless `oneOf`/`anyOf` alternatives retain a concrete outer scalar type
without format validation or general schema intersection. This shared lowering also
applies to responses. A source refresh must review effective response-shape changes;
input-type review alone is insufficient. Explicitly typed branches, richer constraints
and competing compositions keep their existing lowering. Multipart mappings retain
the source-bound query/body schema equality check below; JSON content does not establish
an inline multipart string contract.

The fields of a documented `application/x-www-form-urlencoded` request body
become parameters after the query parameters (required only when the body and
its schema require them) and force POST, which form-encodes every operation
parameter in the body. Reviewed UAPI operations may select `body: "multipart"` with operation-level evidence.
Only inline, non-nullable string fields are supported. Query declarations may repeat
body fields only when their schemas match (documentation annotations are ignored);
the query declaration retains its requiredness. Every query field must have a matching
body field. An evidence-backed `body_fields` map explicitly corrects a contradictory
body property name to an existing query parameter, such as `{"crt": "key"}`; unknown
sources, unknown targets, duplicate targets and omitted multipart fields fail generation.
The runtime keeps routing fields in the query and sends binary-preserving string fields
with a collision-checked multipart boundary. This routing capability is not evidence of
live operation support. Generic `call()` stays form-only. JSON bodies without an
explicit reviewed selection, unreviewed multipart bodies, bodies without plain properties, and deprecated operations remain
unsupported by generation. Evidence is `kind` (`doc` or `live`), `version`, `date`
(`YYYY-MM-DD`), `note` and an optional `fingerprint`.

```bash
go -C tools/api-map run ./cmd/api-map overlay validate --map ../../.cache/api-map/map.json --overlay ../../docs/api/overlay
composer api-map:generate        # build the map, then write generated PHP and docs/api/coverage.md
composer api-map:generate:check  # fail when committed generated files are stale
# Opt-in full-surface stress render (scope "all") into a scratch directory, for
# php -l, PHPCS and PHPStan runs over every operation; never part of the checks.
API_MAP_STRESS_DIR="$PWD/.cache/stress" go -C tools/api-map test ./internal/cli -run TestStressRenderFullSurface
```

`overlay validate` and `generate php` exit 0 on success, 1 on overlay problems,
read or write errors, hand-written targets and (with `--check`) out-of-date or
stale files, and 2 on usage errors. The first docblock of every generated PHP
file starts with the marker line

    Generated by tools/api-map from the pinned API map and docs/api/overlay; do not edit.

and `docs/api/coverage.md` carries it after its heading; the generator recognises
its own files by that exact line within their first 40 lines. `generate php`
writes each file atomically (a run is atomic per file, not across files), removes
previously generated files that are no longer produced, and never overwrites or
removes a file without the marker. Symbolic links are refused inside the overlay
directory and at every generated path (folders and files beneath `--root`), so
neither reading nor writing can leave the repository. Generated files must not
be edited by hand: change the overlay or the generator and regenerate.

## Saved capability comparison and source drift

Reproduce the synthetic saved capability comparison without contacting a server:

```bash
go -C tools/api-map run ./cmd/api-map capabilities compare --map ../../docs/api/examples/capabilities/comparison-map.json --profile ../../docs/api/examples/capabilities/comparison-profile.json --explanations ../../docs/api/examples/capabilities/comparison-explanations.json --output ../../docs/api/examples/capabilities/comparison.json --markdown ../../docs/api/examples/capabilities/comparison.md
```

Reviewed explanations bind to exact source/profile hashes; unexplained
differences do not establish a cause. WHM bridge evidence does not establish
direct UAPI authentication, and UAPI runtime enumeration remains unavailable.
Compare two saved canonical maps to inspect upstream drift:

```bash
go -C tools/api-map run ./cmd/api-map drift report --before ../../.cache/api-map/before.json --after ../../.cache/api-map/map.json --output ../../.cache/api-map/drift.json --markdown ../../.cache/api-map/drift.md
```

Create `before.json` from the previously reviewed snapshot before rebuilding the
new map. Drift separates identity, contract, metadata, auth, availability, and
risk changes; it does not silently revise generated code.
Report/build `--output -` emits JSON to stdout; Markdown remains a file and
safe diagnostics use stderr.

Only these explicit commands use network services; neither belongs in CI:

```bash
# Fetch documentation into a new immutable directory.
go -C tools/api-map run ./cmd/api-map sources refresh --manifest ../../docs/api/sources/2026-10-13/manifest.json --output-dir ../../docs/api/sources/NEW_SNAPSHOT
# Separately authorized live capture: only WHM version and applist.
go -C tools/api-map run ./cmd/api-map capabilities capture --opt-in --host https://test.example:2087 --user root --token-env WHM_TEST_TOKEN --output ../../.cache/api-map/capability-profile.json
```

Supply the token through the process environment; do not commit credentials or
real capability profiles. CI receives no WHM/cPanel secrets and does not
capture, publish, commit, or push artifacts. See the
[source audit](../../docs/api/README.md) for provenance and upstream licensing.


The separate `multipart_upload` descriptor `{php, source, text}` selects the
reviewed WHM required scalar Upload body, currently `source: "file"` and
`text: "module"`. It is mutually exclusive with `multipart_uploads`, JSON,
body aliases, query params and additional methods. The generator requires exact
source-bound field and schema-requiredness evidence, a WHM POST with no endpoint
query parameters, the nonnullable binary file plus nonnullable Basic/Main text,
and absent requestBody.required. Both generated arguments are required and
sensitive. This explicit adoption does not change formal requiredness defaults,
ordinary scalar enum enforcement, Fileman list lowering or SSL string bodies.
The runtime emits text followed by one Upload through the shared streaming stack.


Backup's reviewed `multipart_upload_family: {php, source, routes: direct}` lowers
one required sensitive `Upload|array` argument (`Upload|non-empty-list<Upload>`).
Only literal binary `file`/`backup` sources with exact operation/body prose and
property evidence, absent body/schema requiredness and separately evidenced optional
server-path query omission are supported. Bare scalars and one-based list names
(including singleton lists) remain distinct. Ordinary optional query options stay
query fields, all sensitive. The descriptor is disjoint from Fileman's zero-based
list and WHM's literal-text/scalar descriptors; all are mutually exclusive in the
schema, lowering and runtime. No runtime enum/minimum enforcement is introduced.
