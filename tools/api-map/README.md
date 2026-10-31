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
  patterns such as `MXCHECK-*` must be omitted, and a required parameter cannot
  be omitted (exclude the operation instead). A union of scalar schemas becomes
  the union of their PHP types; an object, map or a union with a list, map or
  object branch needs an `encoding` (or `omit`). `required` overrides the pinned
  requiredness, preserving false separately from omission. `enum_doc: false`
  suppresses only the PHPDoc literal enum in favor of the existing native type;
  absent or true keeps the pinned enum. It changes neither encoding nor native
  parameter types and does not resolve disputed upstream values.
  `wire` corrects one scalar string, integer or number parameter's transmitted
  name with operation-level evidence. Both names must be simple ASCII identifiers
  (`[A-Za-z_][A-Za-z0-9_]*`), excluding routing fields (`api.version`, `cpanel.*`),
  indexed names and wildcard families. Omitted, boolean, repeated and composite
  inputs cannot use it, and duplicate final wire names fail generation. Overlay
  keys and `order` still use the pinned source name; the public PHP name, type,
  requiredness and sensitivity retain their existing rules.
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
  `type: "true"` is a separate evidence-gated presence-only correction, used
  where sending a key grants a privilege regardless of its value. It requires
  an optional concrete scalar integer source with exactly `enum: [1]`; the
  effective parameter must remain optional. Encoding must be absent or `bool`,
  and a simultaneous wire-name correction is rejected. It generates `?true`
  with a null default and the existing boolean encoder: true sends `1`, while
  null omits the key. PHP rejects false, integers (including `1`) and strings
  before dispatch, in both strict and weak callers. No singleton enum is
  changed implicitly; ordinary zero/one flags still accept false and emit `0`.
  Composite, nullable, required and omitted inputs cannot use this correction,
  and it adds no response type. This guard does not make omission a partial
  update: each operation's replacement semantics remain in its reviewed notes.
- `order`: wire names moved to the front of the signature in that order;
  required arguments always precede optional ones, so an order that lists an
  optional parameter before a required one, or an omitted parameter, is a problem.
- `body` (`multipart`) selects reviewed inline string multipart encoding; `body_fields`
  maps explicitly corrected body property names to existing query parameter names.
  Both require operation-level evidence.
- `method` (`GET`/`POST`), `outcome` (`flag`, the default, or `unchecked`),
  `void` (no payload) and `unwrap` (return one documented object field; exclusive
  with `void`). Read-only operations default to GET; mutating, unknown-risk and
  secret-bearing ones default to POST, and GET is refused for secret-bearing ones.
- `optional_payload`: an omitted or null `data` member is a valid response; the
  spec gains `'optional' => true`, the method's return type becomes nullable and
  it returns null in that case (other payloads are validated as usual). Needs a
  documented payload and is exclusive with `void`.
- `evidence`: operation-level evidence, required when the entry sets `variant`, `method`,
  `outcome`, `void` or `optional_payload`, changes a parameter `required` setting,
  suppresses its enum documentation (`enum_doc: false`), sets a parameter `encoding` (it
  changes the wire request), corrects a parameter `wire`, `type` or `description`, or marks
  a parameter `"sensitive": false`.
- `notes`: docblock lines, each `{"kind", "text"}`. Kind `documented` restates
  the pinned documentation and renders as "Documented:"; kind `observed` records
  runtime behaviour, renders as "Observed:" and needs live operation-level
  evidence. `probe` is stored, not read by the generator.
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
live operation support. Generic `call()` stays form-only. JSON bodies, unreviewed
multipart bodies, bodies without plain properties, and deprecated operations remain
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
