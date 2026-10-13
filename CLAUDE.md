# Project Instructions for AI Agents

This file provides instructions and context for AI coding agents working on this project.

<!-- BEGIN BEADS INTEGRATION v:1 profile:minimal hash:1105d646 -->
## Beads Issue Tracker

This project uses **bd (beads)** for issue tracking. Run `bd prime` to see full workflow context and commands.

### Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --claim  # Claim work
bd close <id>         # Complete work
```

### Rules

- Use `bd` for ALL task tracking — do NOT use TodoWrite, TaskCreate, or markdown TODO lists
- Run `bd prime` for detailed command reference and session close protocol
- Use `bd remember` for persistent knowledge — do NOT use MEMORY.md files

**Architecture in one line:** issues live in a local Dolt DB; sync uses `refs/dolt/data` on your git remote; `.beads/issues.jsonl` is a passive export. See https://github.com/gastownhall/beads/blob/main/docs/core-concepts/sync-concepts.md for details and anti-patterns.

## Agent Context Profiles

The managed Beads block is task-tracking guidance, not permission to override repository, user, or orchestrator instructions.

- **Conservative (default)**: Use `bd` for task tracking. Do not run git commits, git pushes, or Dolt remote sync unless explicitly asked. At handoff, report changed files, validation, and suggested next commands.
- **Minimal**: Keep tool instruction files as pointers to `bd prime`; use the same conservative git policy unless active instructions say otherwise.
- **Team-maintainer**: Only when the repository explicitly opts in, agents may close beads, run quality gates, commit, and push as part of session close. A current "do not commit" or "do not push" instruction still wins.

## Session Completion

This protocol applies when ending a Beads implementation workflow. It is subordinate to explicit user, repository, and orchestrator instructions.

1. **File issues for remaining work** - Create beads for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **Handle git/sync by active profile**:
   ```bash
   # Conservative/minimal/default: report status and proposed commands; wait for approval.
   git status

   # Team-maintainer opt-in only, unless current instructions forbid it:
   git pull --rebase
   git push
   git status
   ```
5. **Hand off** - Summarize changes, validation, issue status, and any blocked sync/commit/push step

**Critical rules:**
- Explicit user or orchestrator instructions override this Beads block.
- Do not commit or push without clear authority from the active profile or the current user request.
- If a required sync or push is blocked, stop and report the exact command and error.
<!-- END BEADS INTEGRATION -->
## Project Scope

`hostingpal/whm-api-client` is a standalone PHP Composer library in the
`HostingPal\WhmApiClient` namespace. Its planned scope is the complete WHM API
and cPanel UAPI surface. The read-only slices implement WHM `version`, `gethostname`, `systemloadavg`,
`getdiskusage`, and `get_domain_info`, plus UAPI `DomainInfo/list_domains` and
`LangPHP/php_get_installed_versions`, with authenticated PSR HTTP execution and
typed payloads. Other endpoints and broader execution features remain separate work.
Official API inputs and contract discrepancies are pinned under `docs/api/`. Do not invent
endpoints, authentication rules, request payloads, or response schemas to fill
gaps in those sources.

The core package must remain framework-independent. A future Laravel
integration belongs in a separate Composer package and will use Guzzle. The
Go documentation tool under `tools/api-map/` produces a canonical API map;
implementation parity and further commands are tracked under `gwi`. Go is a
development-tool choice, not a runtime dependency of the PHP package. Preserve pinned documentation snapshots and
refresh them explicitly; routine checks must remain offline. The tool design
is tracked under `whm-api-client-gwi`; Laravel integration is separate work.

## Architecture and Conventions

- Follow the [agreed PHP client interface](docs/superpowers/specs/2026-10-13-php-client-design.md):
  `whm()` and `uapi(?string $user = null)` expose typed operation groups; UAPI
  routes internally through direct cPanel access or WHM according to configured
  credentials. There is no separate public `bridge()` selector. WHM credentials
  require an explicit or configured default cPanel target; never assume `root`
  is that target. Preserve account isolation and per-client `responseMeta()`.
  `whm()->server()->version(): string` and
  `uapi()->domains()->list(?bool $hideTemporaryDomains = null): AccountDomains`
  execute immediately. Selectors and groups perform no I/O. The optional domain
  filter preserves omission (`null`), false (`0`), and true (`1`). DTO properties
  use null for omitted fields; `toArray()` retains optional field presence,
  explicit main-domain null, empty lists, and integer temporary flags.
- `whm()->domains()->list(): ServerDomains` returns an immutable iterable/countable
  collection of `DomainInfo` records. Preserve all 14 optional record fields,
  nullable IPv6, integer 0/1 flags, integer port properties, and open string
  domain types. Omitted `domains` remains null and distinct from an empty list in
  `toArray()`; iteration/count treat either as empty. Upstream excludes service
  and automatically created domains.
  `uapi()->php()->listVersions(): array` has a `list<string>` contract on both
  routes; retain custom PHP package names and empty lists, and reject missing or
  malformed lists with metadata. Requires EasyApache 4 and the WebServer role.
  WHM port fields accept pinned integers and observed canonical nonnegative decimal
  strings within PHP integer range; `toArray()` preserves the original wire type.
  Runtime observations remain separate from pinned contracts and saved parity evidence.
- `whm()->server()->hostname(): string`, `loadAverage(): ServerLoadAverage`, and
  `diskUsage(): ServerDiskUsage` map to WHM `gethostname`, `systemloadavg`, and
  `getdiskusage`. All are parameterless read-only server operations. Load windows
  `one`/`five`/`fifteen` preserve finite numbers or observed decimal numeric strings,
  including precision/trailing zeros, without rescaling. Null properties mean
  omission, retained by `toArray()`; explicit load null is invalid. Disk usage is
  iterable/countable with nullable `partitions`, exporting the original `partition`
  key and all 12 optional record fields. Runtime compatibility permits disk
  percentage 0 and explicit null for four inode fields; other pinned minima stay
  enforced. Preserve omission versus null versus empty lists. Three methods have
  offline regressions and passed opted-in live checks on `test.oblak.host`; record
  schema discrepancies separately from pinned sources and revision-bound evidence.
- The additional 22 read-only inventory methods cover WHM PHP versions/default/handlers,
  default FPM/old FPM/conversion/vhosts-by-version; WHM feature metadata/names/list
  configuration/list names/package types/account settings/access; and UAPI primary
  domain/builtin aliases/PHP default/handler/impacted domains/features/DNS lookup/
  generated domains. New `whm()->php()`, `whm()->features()`, `uapi()->features()`
  and `uapi()->dns()` selectors remain inert and account-bound. See README for the
  complete mapping. Follow the spec's return conventions: public values never
  expose wire-type variants. Records whose flags vary on the wire are DTOs with
  normalized `0|1` camelCase properties and wire-preserving `toArray()` (WHM feature
  metadata/list data/user settings return `FeatureCatalog`, `FeatureListData` and
  `UserFeatureSettings`); single-typed payloads keep precise PHPStan array shapes.
  Load averages are the deliberate exception (`int|float|numeric-string` keeps
  precision). Preserve optional fields, null and empty lists. Observed WHM feature
  compatibility is field-specific (exact string 0/1 or booleans); PHP handlers also
  accept observed lsapi. UAPI impactedDomains accepts one domain or a nonempty list
  encoded as repeated domain keys, with invalid lists clearing metadata before I/O.
  Scalar/list convenience
  methods require their selected fields. DNS lookup returns textual lines or
  explicit null; internal payload presence rejects an omitted data member.
  Feature access returns true for unknown feature IDs by upstream design, and
  disabled-list values have inverted meaning. No generic endpoint/status coverage
  is implied. Pinned contracts and observed compatibility remain separate.
- Operation groups receive one synchronous request callback accepting an
  operation name and query parameters. Groups own endpoint parameter construction;
  WHM and UAPI handles own route construction, with one account-bound UAPI helper
  for direct and bridged execution. Reserved bridge fields take precedence over
  endpoint parameters. Groups never validate request parameters before the
  callback: multi-value input passes through as `list<string>` (repeated keys) or
  `NumberedList` (WHM `name`, `name-1`, ...), and the shared encoder validates it
  after the metadata reset. Validate payloads with the internal `PayloadReader`,
  which attaches the response snapshot to every failure. Selectors only bind
  callbacks and cache groups; they perform no I/O or metadata reset.
- Each attempt clears old response metadata. A usable envelope records an
  immutable snapshot before API or payload errors. `ResponseMetadata::$whm`
  retains raw WHM metadata; `$uapi` retains the UAPI result envelope without `data`.
  `ApiException::$metadata` exposes the same snapshot, with distinct
  `ApiErrorException`, `HttpException`, `InvalidResponseException`, and
  `TransportException` failures. Both bridge outcomes are checked. Do not apply
  these operations' status semantics universally or claim generic bridge coverage.
  Typed metadata accessors and `errors()` read the documented envelope fields;
  `ApiException::errors()` exposes upstream error text outside `getMessage()`.
  `TransportException` records the PSR failure kind, cause class and token-redacted
  cause message without chaining the cause.
- Authenticated operations perform no retries. `Client` requires an explicit
  `HttpTransport`; its PSR-18 client must verify TLS, disable redirects and set
  timeouts, and `HttpTransport::discover()` is the explicit opt-in for unverified
  discovered defaults. The API layer rejects HTTP 3xx responses. Handles never
  expose `ClientConfig` or credentials, and credentials refuse serialization.
  Direct UAPI supports the pinned `result` wrapper and the observed bare result
  object. A present `result` takes precedence even if malformed; the bare form
  requires top-level `status`. Authorized direct library verification on
  2026-10-13 for `DomainInfo/list_domains` as `npr` on `test.oblak.host:2083`
  passed with a cPanel token, the expected main domain, and HTTP 200/status 1.
  Keep this runtime evidence separate from pinned source contracts. Infrastructure
  tests and WHM bridge evidence do not establish other endpoint or route coverage.
- Production code lives in `src/`; tests live in `tests/` with a matching
  directory structure.
- `src/Http/HttpTransport.php` is the HTTP infrastructure entry point. It requires
  an explicit PSR-18 client; PSR-17 factories may be supplied or resolved through
  `php-http/discovery`, and `HttpTransport::discover()` opts in to a discovered client.
- Keep public HTTP contracts based on PSR interfaces. Do not couple the core
  runtime to Laravel, Guzzle, or another concrete HTTP implementation.
- Use PSR-12 plus the selected Slevomat rules in `phpcs.xml.dist`, strict types
  in PHP files, and the repository's `.editorconfig`. The focused rule set covers
  imports, arrays, trailing commas, spacing, and strict comparisons/calls; keep
  multiline arrow functions and PSR-12 spacing. Keep code compatible with
  PHPStan's maximum level.
- Test HTTP behavior with local doubles; routine tests must not require live
  WHM or cPanel services or credentials.
- User-authorized read-only slices should also be verified on `test.oblak.host`
  using the separate PHPUnit `live` group. Existing WHM root and cPanel `npr`
  tokens are supplied through `OBLAK_TEST_WHM_KEY` and `OBLAK_TEST_NPR_KEY`;
  never print or store them. No repeated permission is needed for implemented,
  reviewed read-only operations on this test server. Mutating/destructive tests
  still require explicit approval for specific operations and targets; snapshot
  readiness is not confirmed. Do not automatically restore the server.

## Documentation Requirements

- Document production classes, public APIs, configuration options, and
  non-obvious internal behavior as part of the implementation, not as a later
  cleanup task. Keep PHPDoc, usage examples, and README guidance current.
- Every API endpoint method must have a short PHPDoc summary and a separate
  detailed description covering its purpose, account scope, meaningful
  parameter behavior, returned payload, and relevant failure or metadata
  semantics. Use the reviewed source contract; do not invent missing details.
- Include `@see` links to the exact official operation documentation on
  `https://api.docs.cpanel.net/` (the canonical cPanel developer documentation
  host). Add relevant guide links when authentication or bridge behavior needs
  context. Preserve the link between the PHP method and its pinned API-map
  identity; a link to the documentation homepage alone is not sufficient.
- Document array responses with precise PHPStan-compatible array shapes,
  including nested fields, optional keys, nullable values, and list element
  types. Use reusable `@phpstan-type` and `@phpstan-import-type` aliases where
  helpful. Apply the same precision to structured array inputs. Do not use bare
  `array` or `array<string, mixed>` as a substitute for a known endpoint schema.
- Keep concrete DTO and collection return types when those are the public
  contract; document their fields and mapping shapes rather than weakening
  their return types to arrays. For genuinely opaque upstream metadata, explain
  the boundary instead of guessing a shape. Document applicable `@throws`
  contracts and preserve omitted, null, empty, and zero semantics.

## Build and Validation

Run commands from the repository root:

```bash
composer install --no-interaction
composer validate
composer check
```

`composer check` runs the PSR-12/Slevomat formatting check, static analysis, and
tests. The individual commands are:

```bash
composer phpcs
composer phpcbf
composer phpstan
composer test
```

`composer phpcbf` applies formatting fixes. Tool configuration lives in
`phpcs.xml.dist` (PSR-12 and selected Slevomat rules), `phpstan.neon.dist` (maximum level), and
`phpunit.xml.dist`.

PHPCS resolves Slevomat through the ruleset's explicit `installed_paths` setting.
The Composer standards-installer plugin remains disabled; do not require a
global PHPCS configuration or introduce WordPress runtime dependencies.

Explicit live verification (PHP cURL extension and both test tokens exported):

```bash
WHM_API_LIVE=1 composer test:live
```

The live suite/group exercises only the reviewed GET routes for
implemented operations on `test.oblak.host`, with verified TLS, redirects disabled,
and no retries. Missing opt-in or credentials fail before network I/O. Default
PHPUnit, Composer checks and CI remain offline even when credentials are present.
Keep raw live payloads, credentials and profiles outside committed fixtures.

Go documentation tooling (Go 1.24+) uses a separate module and cache:

```bash
export GOCACHE="$PWD/.cache/go-build"
export GOMODCACHE="$PWD/.cache/go-mod"
go -C tools/api-map test ./...
go -C tools/api-map run ./cmd/api-map sources verify --manifest ../../docs/api/sources/2026-10-13/manifest.json
go -C tools/api-map run ./cmd/api-map map build --manifest ../../docs/api/sources/2026-10-13/manifest.json --annotations ../../docs/api/annotations.json --output ../../.cache/api-map/map.json
sha256sum -c docs/api/map.sha256
```

The map build validates before atomically replacing its output. Pinned sources,
reviewed hash-bound annotations, and ordinary checks remain offline. Only an
explicit `sources refresh --manifest PATH --output-dir NEW_DIR` command may
fetch documentation; it validates a new immutable snapshot. Review annotations
again when their source hashes change. Maps retain upstream response envelopes
and BasicAuth alongside token-guide evidence. Generated large maps live under
`.cache/api-map/`; `docs/api/map.sha256` records the reviewed deterministic map.
`composer check` continues to validate the PHP package offline.

Offline implementation parity uses explicit PHP symbol and registry declarations:

```bash
php tools/php-symbols/export.php --root . --output docs/api/coverage/symbols.json
go -C tools/api-map run ./cmd/api-map parity report --map ../../.cache/api-map/map.json --registry ../../docs/api/coverage/registry.json --symbols ../../docs/api/coverage/symbols.json --output ../../docs/api/coverage/report.json --markdown ../../docs/api/coverage/report.md
```

Optional `--test-evidence PATH` and `--live-evidence PATH` read saved artifacts;
`--revision REVISION` independently identifies the code revision being assessed.
Without a matching revision, saved outcomes remain stale/unverified. Declared
PHP tests do not prove passing outcomes, and infrastructure tests do not count
as endpoint coverage. Invalid registry mappings emit diagnostic reports and
exit 1. Contract hashes follow reachable schemas and discriminator mappings;
response schemas retain the complete upstream envelope.

Live capability capture is a separate, explicitly opted-in command, never a
routine check:

```bash
go -C tools/api-map run ./cmd/api-map capabilities capture --opt-in --host https://test.oblak.host:2087 --user root --token-env OBLAK_TEST_WHM_KEY --output ../../.cache/api-map/capability-profile.json
```

Capture only requests WHM `version` and `applist`. Keep real credentials and
profiles outside committed fixtures. UAPI runtime inventory remains unavailable;
WHM bridge evidence never establishes direct UAPI authentication.



Offline toolchain integration (Go 1.24+, Composer dependencies installed):

```bash
composer api-map:check
go -C tools/api-map vet ./...
go -C tools/api-map generate ./internal/schema
```

`api-map:check` runs `check --root ../..`, reading `docs/api/toolchain.json`.
It builds in temporary storage, checks exact schema copies/map reproducibility,
fresh PHP symbols, checked parity artifacts, synthetic capability/drift examples,
and exact operation/variant/PHP/contract/test baseline mappings. It is read-only
and offline; it has no baseline-update flag. Review baseline edits manually.
Invalid input/mappings exit 1; valid-input baseline regressions exit 3. Live
verification never affects the offline baseline. Keep `composer check` PHP-only.
See `tools/api-map/README.md` for explicit artifact regeneration and the saved
`capabilities compare` / `drift report` commands. The CI workflow uses locked
dependencies and receives no WHM/cPanel secrets.


## Maintaining Agent Guidance

`AGENTS.md` and `CLAUDE.md` are independent files. Update both whenever project
scope, architecture, conventions, or development commands change so their
project guidance stays aligned. Preserve generated Beads blocks and keep
project-specific additions outside those blocks. Follow the conservative
Beads profile: do not commit, push, or run Dolt remote sync without explicit
authorization.
