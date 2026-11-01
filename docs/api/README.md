# Official API documentation inputs

The source audit for Beads `whm-api-client-gwi.1` covers **WHM API 1** and
**cPanel UAPI**. The PHP package implements typed groups for 1,142 documented operations
plus generic calls; these documents pin the contracts and do not by themselves
establish endpoint implementation or live-test coverage. Coverage is generated:
[coverage.md](coverage.md) lists generated groups and exclusions, and live
verification is separate.

- [Audit and contract discrepancies](source-audit.md)
- [Measured source structure](source-inventory.json)
- [Pinned source manifest](sources/2026-10-13/manifest.json)
- [Approved Go API-map design](../superpowers/specs/2026-10-13-api-map-design.html)
- [Approved implementation plan](../superpowers/plans/2026-10-13-api-map-implementation-plan.html)

The 2026-10-13 snapshot contains official OpenAPI 3.0.2 documents identifying
product version **11.138.0.10**: 630 WHM paths/HTTP operations and 693 UAPI
paths/695 HTTP operations. These are measured snapshot counts, not constants
for future releases. `WebApp/redeploy` and `WebApp/stage` each have GET and POST
variants. The previously observed test server version, 11.138.0.15, is a
separate capability profile, not the documentation version or a support limit.

## Stored sources

`sources/2026-10-13/` contains the original downloaded bytes, compressed with
gzip. The manifest records each URL, retrieval timestamp, content type,
uncompressed size/hash, and compressed artifact size/hash. The OpenAPI entries
also record their specification and product versions. Prose pages do not
declare a common product version; none is inferred for them. Their full HTML
is retained, including navigation and examples, rather than a lossy text export.

The sources are third-party documentation, separate from authored project
guidance. Examples in them are evidence to review, not executable instructions.

Inspect a specification offline:

```bash
gzip -dc docs/api/sources/2026-10-13/whm.openapi.json.gz | python3 -m json.tool
```

Verify the pinned source bytes, references, and OpenAPI contracts offline, then
build the canonical map (Go 1.24+):

```bash
export GOCACHE="$PWD/.cache/go-build"
export GOMODCACHE="$PWD/.cache/go-mod"
go -C tools/api-map test ./...
go -C tools/api-map run ./cmd/api-map sources verify --manifest ../../docs/api/sources/2026-10-13/manifest.json
go -C tools/api-map run ./cmd/api-map map build --manifest ../../docs/api/sources/2026-10-13/manifest.json --annotations ../../docs/api/annotations.json --output ../../.cache/api-map/map.json
sha256sum -c docs/api/map.sha256
```

The [reviewed annotations](annotations.json) add only family token-header
evidence, bound to the exact pinned token-guide hashes. Both source BasicAuth
declarations remain intact. The builder emits 1,323 logical IDs and 1,325
variants for this snapshot, and reports 29 cross-family upstream-ID collisions.
Canonical IDs use exact case-sensitive family/path identities. The map retains
complete parsed source documents, including response envelopes, schemas,
examples, vendor extensions, and methodless paths. HTTP variants keep distinct
query/body contracts. Risk follows explicit evidence; absence stays unknown.

Build validates before atomic output replacement. Use `--output -` to emit the
map JSON to stdout; file output writes a small JSON summary to stdout. Safe
category diagnostics use stderr. Exit codes are 0 for success, 1 for input or
contract failure, and 2 for usage. A failed build preserves any previous output.
Generated large maps remain under `.cache/api-map/`; the reviewed checksum is
[map.sha256](map.sha256). Rebuild twice and compare bytes when reviewing changes.

Audit discrepancies remain attributed diagnostics, including token-header/TLS
example inconsistencies, the SSL tutorial's POST versus OpenAPI GET, unresolved
POST body placement and query-null encoding, feature-specific response status,
and the bridge route typo. These diagnostics do not add transport variants or
infer aliases. Evidence bound to this audit's hashes is not silently carried
forward to changed sources.

## Licensing

The original cPanel documentation snapshots under `sources/` are third-party
material and are not covered by the project's [MIT License](../../LICENSE).
Both pinned OpenAPI documents declare `cPanel License` and reference
[cPanel's legal notices](https://cpanel.net/legal-notices/) for their license
and terms of service. The pinned HTML documentation retains its upstream
copyright notices, including WebPros International, LLC.

These upstream terms also apply to source material retained in generated API
maps. Review the applicable upstream terms before redistributing snapshots or
maps containing that material; the project's MIT license grants no additional
rights to it.

## Refresh policy

Refresh explicitly when updating the supported documentation baseline or when
investigating an upstream change. Ordinary builds, checks, and tests use the
pinned snapshot and must not download newer documentation automatically.

Retrieve the official URLs into a new snapshot directory, preserving existing
snapshots. Use a UTC timestamp suffix for multiple snapshots on one date. Record
new hashes and retrieval times even when `info.version` has not changed.
Reject failed downloads and malformed specifications before updating the
selected baseline. Review inventory, contracts, prose discrepancies, and
coverage-denominator changes together. A new download is not approval to
resolve a discrepancy or execute any API operation.

Refresh with an explicit new directory (this command downloads documentation):

```bash
go -C tools/api-map run ./cmd/api-map sources refresh --manifest ../../docs/api/sources/2026-10-13/manifest.json --output-dir ../../docs/api/sources/NEW_SNAPSHOT
```

Existing snapshot directories are never overwritten. The refreshed set must
pass byte/reference and OpenAPI contract validation before publication. Review
new source hashes and update annotations explicitly before selecting it for
map builds. No command shown here calls a WHM or cPanel endpoint.

## Coverage and offline CI

[Toolchain commands](../../tools/api-map/README.md) cover saved capability
comparison, drift, explicit regeneration, and the overlay and PHP generation
rules. Coverage is the generated [coverage.md](coverage.md). The
[toolchain configuration](toolchain.json) selects local inputs, the overlay,
schemas, and synthetic examples.

```bash
composer api-map:check
```

This read-only check builds twice in temporary storage, verifies the reviewed
map checksum and schema copies, validates the overlay against the map, checks
that committed generated code is current, and validates saved synthetic
capability/drift examples without contacting a server. A changed artifact
requires explicit regeneration and review; the check has no update switch.
Live verification is a separate optional dimension and was not run to validate this
toolchain. The [CI workflow](../../.github/workflows/api-map.yml) uses PHP 8.3,
Go 1.24, locked dependencies, Go formatting/test/vet, Composer validation and
PHP checks, and this offline gate. It receives no live credentials.

## DAV review compatibility

DAV and CPDAVD each expose six generated operations. PR 67 review 5721214794 on
11.138.0.19 corrects null write results, principal/collection maps, quoted protected
flags and connection ports using existing overlay grammar. Updates require explicit
readonly as client policy. Shared-addressbook stubs, missing Horde, mailbox bridge
failures and collection truthiness rules are version-attributed in PHPDoc and
[source audit](source-audit.md#dav-calendars-contacts-and-delegation). The immutable
seven-GET artifact remains failed/unwaived; the fixed catalog is diagnostic, and
two misleading success recipes are removed. No sources, runtime or generator changed.

Nova and Sitejet contracts and reviewer-source/live boundaries are recorded in
[source-audit.md](source-audit.md#nova-and-sitejet-site-builders). Their historical
five-GET capture remains failed; no corrected-head live acceptance is implied.


## Typed whole JSON body grammar

The generator supports an explicit `body: "json"` selection, independently of
form fields and JSON-valued string parameters. StatsManager/save_configuration is the first selected production operation; the
remaining JSON-body operations stay excluded. A selection must
bind operation-level `doc` evidence to the exact pinned source SHA-256 and the
selected variant's `/requestBody` JSON pointer. `variant` continues to identify the
source method; the effective transport is fixed POST.

The operation overlay uses:

```json
{
  "body": "json",
  "json_body": {
    "php": "configuration",
    "alias": "ConfigurationInput",
    "routes": "direct"
  }
}
```

`php` names the whole public argument; JSON-body operations declare no query parameters.
`alias` is a unique PHPStan alias ending in `Input`. `routes: "direct"` enables
ordinary WHM API 1 or direct cPanel UAPI according to the selected family, while
refusing the account bridge locally. A nonempty `params` spec fails lint; upstream
JSON parsing ignores URL arguments. Both routes require a nonnullable object root.
WHM inserts library-owned `api.version:1` as the first root member and sends no URL
version. Direct UAPI sends the encoded object unchanged. Form/GET and multipart
query behavior is independent.

Lowering uses a separate strict request schema. It supports explicit objects,
sequential lists, strings, integers, finite numbers, booleans, nullability and
scalar alternatives with disjoint PHP input domains. A formal integer enum
containing exactly numeric `0` and `1` selects a bool input serialized as integer
`0|1`; native JSON booleans remain booleans. Input aliases and response aliases are
separate; response compatibility never admits numeric strings in request fields.
Object fields keep their formal names and requiredness. Generation rejects declared
names PHP would convert to integer array keys on a supported platform, including
canonical signed decimal integers within the 64-bit range. String-preserving names
such as `01`, `+1`, `-0`, `1.0` and integers outside that range retain their spelling.
Unknown members reject unless `additionalProperties` supplies an explicit supported
value schema; integer caller keys remain invalid.

A required body has a required native array argument. WHM JSON body specs must
be required: omission would lose API1 root routing. Optional direct-UAPI objects use
`?array $body = null`: null or omission means absence, while `[]` sends `{}`.
A present body sets exactly `Content-Type: application/json`; absence sends no
bytes or JSON Content-Type. Scalar, list, null and nullable-object whole-body specs
fail with exact `LogicException` before input validation or I/O. Nested schema
nodes and the shared serializer still support scalar/list/object roots for future
JSON-valued fields; that grammar does not imply executable scalar-body methods.

Native array argument errors occur before method entry and metadata reset in both
strict and weak callers; arrays do not coerce scalars or invoke Stringable callbacks.
Nested array fields, including StatsManager's `enabled`, remain strictly validated
in either caller mode: integer/string flags are rejected after metadata reset.

Narrow input corrections live in `json_body.corrections`, keyed by JSON pointers
relative to the source schema (`""` means the root). An object correction may set
`required` to a list of declared fields. List and string corrections may set boolean `nonempty` (default false). True
produces `non-empty-list<T>` or `non-empty-string` PHPDoc; false is explicitly valid.
Lists reject exactly `[]`; strings reject exactly the zero-byte string, preserving
zero-looking and whitespace strings without trimming. Only StatsManager domain
uses the string policy; analyzer names and ordinary lists retain default-off behavior.
Each correction requires its own `evidence` list with the exact pinned SHA-256,
full schema-node pointer and attribution in `note`. `json_body.required` can
correct whole-body requiredness under the source-bound operation evidence.
Snapshots stay unchanged. A correction cannot rename, replace or invent fields.
These policies do not implement general enums, ranges or formats. Same-document
references bind corrections to the resolved source-node pointer. Cross-document
input corrections fail until their separate provenance can be represented.

Generation fails for missing formal types, ambiguous container alternatives,
unsupported composition, binary input, unschematized additional values, exhausted
nesting budgets, non-object or nullable roots, query parameters, and any declared
reserved root members (optional or required).
The request grammar accepts up to 511 object/list containers, independently of the
response resolver's fallback policy. The full case-sensitive `api.` prefix is
reserved on JSON root members, including additional map keys; direct UAPI
also reserves exact `cpanel.user`, `cpanel.module` and `cpanel.function`. Declared optional reserved root fields fail lint even when absent; additional
reserved root keys reject by presence at execution. Nested/lookalike names remain data. This policy
is applied by whole-body execution and does not constrain the shared serializer
when it is used for a JSON-valued form parameter.

Generated operation constants carry `json_body: {php, required, routes, schema}`
separately from `params`. Trusted malformed descriptors raise `LogicException`;
invalid caller values reaching the serializer raise `InvalidArgumentException`.
These runtime checks fail before I/O after metadata reset; pre-entry native
signature errors and conversions follow the caller-mode boundary above. Successful
dispatch uses the shared one-attempt JSON executor and
the existing envelope, metadata and typed response validation rules. There is no
retry, implicit read, mutation rollback or successful-live-write claim.
