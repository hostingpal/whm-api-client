# Official API documentation inputs

The source audit for Beads `whm-api-client-gwi.1` covers **WHM API 1** and
**cPanel UAPI**. The PHP package implements typed groups for 455 documented operations
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
