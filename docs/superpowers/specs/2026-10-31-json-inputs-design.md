# Structured JSON inputs and the callJson escape hatch

Date: 2026-10-31 (Europe/Belgrade). Status: the user approved structured PHP inputs
for typed methods and an explicit low-level `callJson()` escape hatch for raw JSON.
This document records that decision and the engineering rules needed to implement
it. Delivery epic: `whm-api-client-8x0.29`. The original PR73 design changed no runtime code. The PR81 amendment below
corrects the execution contract; attributed reviewer observations establish only
the operations, routes and server version recorded under source and review evidence.

## Intent and approaches

Typed callers work with PHP data described by precise generated input shapes. The
client validates the declared structure and serializes it once. Callers who need
to control a complete JSON request body use `callJson()` with an already serialized
JSON string. The transport, account routing, metadata and failure policies remain
those of this framework-independent package.

Three approaches were considered:

1. **Only raw JSON strings.** Minimal transport work, but ordinary callers must
   know wire types and encoding and receive no endpoint input-shape guarantees.
2. **Structured and raw values accepted interchangeably by every typed method.**
   Flexible, but makes the validation promise unclear and confuses a JSON string
   value with a pre-serialized JSON document.
3. **Structured typed methods plus a separate raw complete-body method.** Approved.
   Typed inputs have an explicit schema; raw JSON has syntax and routing checks,
   without endpoint-specific input validation. Existing serialized-string methods
   remain compatible rather than being silently reinterpreted.

The [generated-client design](2026-10-14-generated-client-design.md) remains the
architecture. The [indexed/map design in PR72](https://github.com/hostingpal/whm-api-client/pull/72)
provides separately tracked canonical-operation method aliases; JSON objects are
not dynamic collections of HTTP parameter names and do not acquire that map
encoder's prefixes or numbered fields.

## Three distinct representations

| Representation | Public input | What goes on the wire |
| --- | --- | --- |
| Typed JSON body | Schema-described PHP data | One JSON document with exactly `Content-Type: application/json`, no parameters |
| Raw JSON body | `string $json` through `callJson()` | Validated object bytes; WHM inserts its owned root version, direct UAPI preserves bytes |
| JSON-valued parameter | Schema-described PHP value in a typed companion method | One JSON string within an ordinary query/form field |

JSON-valued parameters do not turn the whole request into JSON. A string which
happens to contain JSON is not parsed automatically. A JSON body is never passed
through the form encoder or wrapped in an invented `data` property. Existing form,
GET and reviewed multipart-string behavior stays unchanged.

## Raw public API

The new signatures are:

```php
// WHM handle
public function callJson(
    string $operation,
    #[SensitiveParameter] string $json,
    OutcomePolicy $outcome = OutcomePolicy::Flag,
): CallResult;

// UAPI handle
public function callJson(
    string $module,
    string $operation,
    #[SensitiveParameter] string $json,
    OutcomePolicy $outcome = OutcomePolicy::Flag,
): CallResult;
```

`operation` is the documented wire function name, not a URL. Existing `call()`
argument names and behavior do not change. There is no query argument: upstream
reads JSON arguments only from the body. There is no method, arbitrary URL, header,
authentication or account override argument. The HTTP method is POST.

The raw method returns the same untyped `CallResult` contract as `call()` and uses
the same explicit outcome policies and envelope checks. It does not validate an
operation's request or response field schema and does not increase typed coverage.
The caller is responsible for choosing a JSON-compatible operation and supplying
its required data. Raw access is not a claim that every named operation accepts a
JSON body or uses the standard envelope. Future `callJson()` PHPDoc must also
explain the [reviewed UAPI source limits](#source-and-review-evidence): flat JSON
arguments are accepted generally in the reviewer-inspected implementation, while
nested structures require the function's `requires_json` declaration. The corrected
count is five functions across four modules: ClamScanner (1), ContactInformation
(1), Personalization (2), StatsManager (1). This source generality does not establish
live compatibility for every function, nor relax local bridge refusal.

### Syntax and byte preservation

PHP 8.3 is already the package minimum. Use `json_validate($json, 512)` or an
exactly equivalent strict syntax check. Reject invalid UTF-8, invalid JSON, empty
or whitespace-only documents, trailing non-whitespace text and documents rejected
by that depth argument. The accepted boundary is **511 nested JSON containers**:
count every object or list, including the root and an empty deepest container;
scalar leaves add no container level. Raw and typed whole-body execution require a nonnullable object root; the
shared serializer grammar remains general for JSON-valued fields. This is a
client resource constraint, not a claim that deeper JSON is syntactically invalid.
Do not strip a BOM or repair malformed input.

Reject every non-object root locally, even when it is valid JSON: upstream
parsejson requires a hash and otherwise dies before the function is reached.
Direct UAPI preserves leading/trailing and inner JSON whitespace, object-key order,
duplicate ordinary keys, escaped spelling and numeric lexemes exactly. WHM inserts
`"api.version":1` immediately after the root opening brace as its first member,
plus a comma only when caller members are present; every original byte stays in
order. Empty or whitespace-only objects receive no comma. Never decode/re-encode
to form the outgoing body, wrap it, pretty-print it or add a trailing newline.
Valid duplicates do not endorse any server's duplicate-key behavior. Upstream
parsejson removes root keys beginning with `file-`; document that silent-drop
behavior without introducing an unsupported local rejection.

Routing checks may inspect decoded root member names, with the same depth bound;
that inspection must not supply the serialized body or coerce numeric literals.
No endpoint-specific shape validation is added as a side effect of inspection.

### Body routing protections

WHM names use the current allowed function-path grammar; UAPI module/function
names use the current segment grammar. WHM always controls root `"api.version":1`;
no JSON version or operation fields are sent in the URL. Share that insertion path
with future typed WHM dispatch: all eight pinned WHM JSON operations require an
object root, including any effective typed body contract. The generic structured
serializer still supports all schema-declared root kinds; this execution policy
does not narrow its grammar. Account selection, base URL and credentials come
only from the handle.

For all new raw and typed JSON-body entry points on WHM and direct UAPI, reserve
the entire case-sensitive `api.` prefix in caller root JSON object members. This includes `api.version`, `api.filter_*`,
`api.sort_*`, `api.paginate_*`, `api.columns_*` and any other name beginning `api.`;
it is not a closed list of known controls. Test every root member occurrence after
JSON escape decoding, including duplicate members and reserved keys whose values
are null; presence is what matters. Nested JSON member names remain ordinary
endpoint data. Reject collisions before legacy handle filtering could discard
them. UAPI JSON entry points additionally protect the exact names `cpanel.user`,
`cpanel.module` and `cpanel.function`, even for a direct handle, so body values
cannot become an alternate routing interface later.

This boundary follows the [reviewer-inspected source](#source-and-review-evidence):
WHM `extract_api_args` consumes `api.*`, and UAPI `Cpanel::Args` handles the filter,
sort, pagination and column controls. Root members reach
`Cpanel::API::_safety_check_args` through `get_raw_args_hr`. The reviewer's root
`api.columns` probes in the PR81 review on 11.138.0.19 reduced WHM listaccts
from 43 fields to one and direct Email/list_pops from four fields to one. This
supersedes the earlier source-only caveat; it does not establish universal
operation compatibility. No pinned JSON-body operation declares a query parameter.
Legacy GET/form routing, percent encoding, OrderedFields collision guards and
`call()` filtering stay unchanged.

## Supported routes and transport limits

Initial runtime targets are ordinary WHM API 1 and direct cPanel UAPI JSON POST.
Use the configured authentication and base URI with the existing PSR-17/PSR-18
transport. Preserve the configured verified-TLS/disabled-redirect requirements,
timeout ownership and single attempt. No new runtime framework dependency is added.

For every present raw or typed JSON body, the Content-Type value must be exactly
`application/json`, with **no parameters**, including no `charset`. The
[reviewer-inspected](#source-and-review-evidence) `Cpanel::JSONAPI::is_json_request`
compares `CONTENT_TYPE` exactly with `application/json`; a helper adding
`; charset=utf-8` does not select that JSON path. Assert the final PSR request
header exactly, rather than relying on a helper's default. JSON inside a form
field keeps the outer form Content-Type.

A UAPI handle using WHM credentials is a bridged handle. Initial `callJson()` and
typed JSON-body execution on that handle fail locally after metadata reset with
`InvalidArgumentException`: JSON bodies on that route are not enabled by this
spec. There is no form fallback, direct-account fallback, extra login, alternative
credential lookup or account-selector change. This is a deliberate initial support
boundary, not a statement that the server can never support bridged JSON. The
reviewer's earlier HTTP 400 bridge observations were caused by putting
`api.version` in the URL, which JSON parsing ignores; they never reached the bridge.
With root routing, flat DomainInfo/list_domains and single_domain_data requests
succeeded with outer result 1 and UAPI status 1 on 11.138.0.19. Nested
Personalization/get returned outer result 0 with “uapi_cpanel does not support
nested data structures”. Flat arguments already work as form data, while nested
structures motivate JSON, so deliberate local bridge refusal remains. These are
attributed reviewer observations, not a new local live run. Non-object direct
roots returned HTTP 500 and are now rejected locally alongside malformed JSON.

Generic WHM access still requires the caller to choose a compatible WHM operation;
using an execution-wrapper name through that low-level handle does not establish
support for an account-bound UAPI JSON route or typed wrapper behavior. Wrapper
contracts remain `8x0.4.17` work. No blanket wrapper/remote-execution compatibility
is inferred from the ability to send JSON bytes.

Later bridge support requires authoritative route/forwarding evidence, literal
routing/isolation tests and scoped live verification (or a precise recorded gap).
Only then may a reviewed route capability enable it. Do not assume direct UAPI
body structure is forwarded unchanged by the WHM bridge.

## Structured input and serialization contract

The operation spec, not PHP's automatic array heuristics, determines the JSON kind
at every node. Input schemas record object fields, requiredness, nullability, list
item types, reviewed scalar alternatives and explicit additional-value schemas.

| PHP input and declared schema | JSON result |
| --- | --- |
| Empty array declared as object | `{}` |
| Empty array declared as list | `[]` |
| Object key absent | Member omitted |
| Present null accepted by nullable schema | `null` |
| Native false for a boolean field | `false` |
| Native false for a reviewed integer 0/1 flag | `0` |
| Native integer zero | `0` |
| Native string `"0"` in a string field | `"0"` |
| Empty string in a string field | `""` |

Nonempty objects use string-key arrays and declared field names. Reject integer
PHP keys rather than reconstruct a numeric-string spelling PHP may already have
lost. Preserve key spelling and input insertion order. Lists must be sequential;
do not sort, renumber or deduplicate. Unknown object keys fail unless the source
explicitly declares a reviewed additional-value schema; validate those additional
values under that schema. Source-derived fields are not renamed implicitly.

Reject objects, resources and arbitrary `JsonSerializable` instances in the new
structured boundary. Accept ordinary arrays and declared scalars only. Internally,
construct the JSON object/list distinction from the schema, so an empty PHP array
can correctly become an empty object without asking callers to cast to stdClass.
For this delivery, genuinely ambiguous object/list unions are rejected during
generation, not resolved from an empty array or first matching branch. Their
explicit representation belongs with the composed-schema follow-up.

Validate strict types before serialization. No request-side numeric-string or
boolean/integer coercion is implied by response compatibility rules. Native boolean
to integer conversion applies only to explicitly declared 0/1 flag fields. Do not
invent universal range, format or enum enforcement; preserve the existing reviewed
validation policy and separately track broader enforcement under `8x0.18`.

Use throwing JSON encoding without partial output or invalid-UTF-8 substitution.
Preserve finite float values and their integral-float distinction (`1.0`), reject
NAN/INF, and test independence from PHP precision/locale configuration. Preserve
string contents without decoding embedded JSON, normalizing Unicode, trimming or
changing line endings. Exact escape spelling of *typed* output need not match a
caller's imagined serialization; decoded values and declared kinds must match.
Raw byte preservation is the separate `callJson()` contract.

Typed output must satisfy the same `json_validate($json, 512)` boundary as raw
input: at most 511 nested containers under the definition above. Check the entire
schema against that container budget, bound value traversal by the same budget,
and validate the complete encoded output before returning it. Object/list schema
nodes count as containers; scalar leaves and scalar `anyOf` descriptors add no
container level. Malformed or over-budget schema descriptors raise `LogicException`;
caller value mismatches raise `InvalidArgumentException`. No partial body is
returned or dispatched.

Native `json_encode($value, JSON_THROW_ON_ERROR, 512)` alone is insufficient: it
accepts one more container level than `json_validate(..., 512)`. Independent offline
probes for this correction used PHP **8.4.26**, with literal JSON and separately
constructed PHP lists, objects and mixed nesting, ending in either a scalar or an
empty deepest container:

| Nested containers | Native encoder with depth 512 | Native validator with depth 512 |
| --- | --- | --- |
| 511 | Accepts | Accepts |
| 512 | Accepts | Rejects |
| 513 | Throws | Rejects |

This reconciles the [incoming PR80 clarification](https://github.com/hostingpal/whm-api-client/pull/80)
without changing that implementation. The probe is local PHP 8.4 evidence, not a
claim that future PHP 8.3 CI has run. Future acceptance tests must check the boundary
on supported PHP versions. Validate and encode the complete input before the first
request; a failure near the end does not send an earlier partial structure. Cyclic
PHP arrays must fail within the bounded traversal/encoding budget without unbounded
recursion or a network request.

### Body presence and public method generation

Typed whole-body execution requires a nonnullable object schema and no query
parameters. Scalar, list, null and nullable-object root specs fail exact
LogicException before I/O. WHM JSON specs must require the body so API1 root
routing cannot disappear; optional WHM specs fail locally and during generation.
A supported direct-UAPI optional object body uses `?array $body = null`:
null or omission means no bytes and no Content-Type; `[]` sends `{}` with exact
application/json. A required body has a required native array argument. Nested
object members preserve absence independently from explicit null. The shared
StructuredJsonSchema/encoder still supports scalar/list/object roots for field
JSON, but those root kinds do not establish whole-body execution support.

Both strict and weak callers reject non-array arguments before method entry, so
native TypeErrors retain prior metadata. Native array binding does not coerce
scalars or invoke Stringable conversion. Nested fields remain strictly validated
after entry and metadata reset in both modes. Obsolete scalar whole-body fixtures
are replaced with supported generated object-body caller tests.

Whole-body arguments avoid losing optional-member presence while flattening body
fields into PHP defaults. A generated convenience alias with named body fields may
be added only when it keeps every supported presence state explicit; it is not a
prerequisite for JSON support. The discussion's named `changes` example illustrates
such convenience; the canonical body form preserves the reviewed effective
presence contract, including the StatsManager correction below. Do not change an
already published scalar/string method's signature in order to retrofit this layout.

An omitted object body means no bytes or JSON Content-Type. A present empty object
has its own bytes and the JSON header. WHM adds library-owned api.version:1 first
in that object; direct UAPI retains {}. Neither JSON route sends URL arguments.

## First typed body adopter: StatsManager

**This operation replaces the account's analyzer configuration.** Callers must
explicitly read `StatsManager/get_configuration` first, then supply the complete
intended set of domain/analyzer settings for the account. Round-trip
`enabled_by_user` (user choice) into input `enabled`, rather than effective
`enabled`. Analyzers absent from the read receive their defaults. The
[reviewer-inspected handler](#source-and-review-evidence) builds a default
configuration for every domain and analyzer, applies supplied changes, then saves.
Its POD says unspecified domain/analyzer configurations are disabled. Preserve
that warning without promising every default is universally disabled independently
of that source statement. A partial-looking change can reset other settings to
defaults. Future generated PHPDoc must lead with these replacement/default
semantics and the caller's prior-read and complete-set obligations, recorded as an
overlay note during implementation. The client must not perform an implicit read,
merge, retry, restore or rollback.

Keep `uapi:StatsManager/save_configuration#POST` selected for `8x0.1.18`, with its
successful live-write gate **OPEN and currently blocked** as described below.
Generate `saveConfiguration(array $configuration): array`: the argument is required
and has no nullable default. The pinned `11.138.0.10` body is formally optional,
with optional `changes` and optional row fields; that requiredness is contradicted
by the attributed `11.138.0.19` handler evidence and raw direct-UAPI observations.
Record this as an evidenced effective-input correction, leaving pinned sources
immutable. The body uses `changes`, `domain`, `analyzers`, `name` and `enabled` wire
member names. The generated input shape is:

```php
/** @phpstan-type StatsConfigurationInput array{
 *     changes: non-empty-list<array{
 *         domain: non-empty-string,
 *         analyzers: list<array{name: string, enabled: bool}>,
 *     }>,
 * } */
```

Require `changes` to be a non-empty list. Every change requires `domain` and
`analyzers`; domain rejects the zero-byte string. Explicit boolean `nonempty`
defaults to false on list/string descriptors; true rejects only [] or the empty
string. Zero-looking and whitespace strings remain unchanged. This is not hostname
validation: the attributed handler also skips the string `0`, so complete domain
selection from getConfiguration remains essential. Every analyzer requires `name` and `enabled`. Missing or null
configuration, `[]`, empty `changes`, empty change rows, and missing required
analyzer fields must fail locally without I/O (PHP signature errors remain outside
the lifecycle). Do not add a nonempty analyzer-list constraint, broad string-format
validation or a closed analyzer-name enum. Selecting valid domains/analyzers and
supplying the complete intended set remain the caller's obligations; required
structure alone does not prove ownership, availability or persistence.

The reviewed input-field policy maps native `enabled: bool` to integer `0|1` on
the wire; it implies no general scalar coercion. Pinned samples quote these flags,
while the formal schema declares integer `0|1`. The reviewer reports the handler
compares `enabled == 1`, supporting that explicit mapping, but no successful write
or end-to-end boolean mapping was observed. A source-evidenced 11.138.0.19 correction replaces the incorrect pinned save
analyzer row with getConfiguration's row: optional name, enabled and enabled_by_user,
with native integer 0|1 flags. Both handlers return the same _get_configuration
builder. locked and analyzer available_for_user belong in metadata; locked=1 means
reseller/root-only and locked=0 means user-manageable. No successful write or live
write response was observed. Empty analyzer names produce unrecognized warnings;
empty domains are silently skipped by the handler and reject locally. Status 1
can still contain warnings/skips. Input requiredness does not change response
requiredness. Attribution and pinned pointers live in the operation overlay.

```php
// Future generated API; direct cPanel handle.
// The caller has explicitly read StatsManager/get_configuration and prepared
// the complete intended domain/analyzer set for this account, with bool inputs.
/** @var StatsConfigurationInput $completeIntendedConfiguration */
$savedConfiguration = $client->uapi()->statsManager()->saveConfiguration(
    configuration: $completeIntendedConfiguration,
);
```

This is a complete-set-oriented example, not a ready-to-run configuration or a
one-domain patch recipe. The caller prepares the configuration and inspects the
response metadata/warnings afterward. The handler can skip malformed/invalid,
unowned, unavailable or unrecognized entries and still save. `not_owned`,
`not_available` and `unrecognized` produce `raw_warning` with status `1` and skipped
entries. A successful outcome does not mean every requested item was applied;
required shape validation cannot replace warning inspection or persistence checks.

The pinned WebServer-role and root/reseller-controlled analyzer caveats remain in
generated PHPDoc. On the reviewer's `11.138.0.19` server, all well-formed direct
JSON POST cases were blocked by `_can_self_manage()` with the permission error
“You may not modify the weblog analyzer configuration”, including `enabled` as
`1`, `0`, `"1"` or `true`. Successful-write verification would require changing a
root/reseller server-wide statistics setting. This task authorizes no such change.
Source/offline implementation may proceed with StatsManager selected, while
`8x0.1.18` retains its open success gate; do not waive it or substitute the untested
Personalization/contact candidates. Any account-scoped alternative needs its own
reviewed selection outside this correction.

## Structured JSON inside a parameter

Each JSON-valued parameter declares its schema, whether omission/null is legal,
and its effective query/form location. Serialize its structured value once, then
pass that JSON *string* through the existing outer parameter encoder once. Never
flatten object members into separate HTTP fields or change the whole request's
Content-Type to application/json.

Select WHM `set_provider_display_configurations.configurations` as the first
structured companion. Its pinned content schema is an object whose optional
fields `color`, `display_name`, `icon`, `icon_type`, `label`, `textcolor` are strings.
Unknown fields and null member values fail under that schema; absence is permitted.

Add generated `configureProviderDisplay(string $providerId, string $serviceName,
array $configurations): void`, with a precise configurations array shape. Share
the canonical operation, response contract, current outcome/POST policy and
coverage identity with `setProviderDisplayConfigurations()`. The source/current
method has a void result; the companion is also void and invents no array payload.
The legacy method
continues accepting its existing serialized-string boundary unchanged; the separate
scalar-fidelity repair in `8x0.1.23` must not silently change semantics under this PR.

```php
$client->whm()->authentication()->configureProviderDisplay(
    providerId: 'cpanelid',
    serviceName: 'cpaneld',
    configurations: [
        'display_name' => 'Hosting Center',
        'color' => '6677aa',
        'textcolor' => 'FFFFFF',
    ],
);
```

The resulting JSON text is one `configurations` form field under the current
mutation POST policy. The formal source says query content; retain that source
fact separately from the effective form policy. Do not switch the current method
to GET or a JSON body merely because its source parameter has `content: application/json`.
An empty configurations array serializes to `{}`; no semantic reset/no-op claim is
made. The [reviewer-inspected implementation](#source-and-review-evidence) calls
`Cpanel::JSON::Load($config_str)` on the one configurations string, supporting one
serialization inside one form field; this is source evidence, not a new live
provider mutation. Sensitive annotations for configuration data and payload-bearing
frames are preserved. Existing already-serialized input bytes must not be
parsed/re-encoded.

This companion uses the operation-alias support tracked in `8x0.28.5`. It counts as
another PHP interface to the same operation, not another documented operation.
Other JSON-valued fields need their own source-backed selection. In particular,
`update_customizations` composed content and the disputed backup destination
form/JSON transport remain `8x0.1.22` evidence/schema work; serializer availability
does not resolve those contradictions.

## Errors, metadata and secrets

Clear the owning handle's metadata before name/route/JSON/schema checks, as
with existing calls. PHP signature TypeErrors remain outside the method lifecycle.
Invalid caller JSON or structured content raises `InvalidArgumentException`;
malformed generated specs raise `LogicException`. No request is sent for either.
Raw validation errors are static and unchained; never include supplied JSON
fragments or dynamic keys in diagnostics.

Protect all library frames holding raw/structured/serialized data with
`SensitiveParameter`. Error text may identify the public argument or a static
schema location, but not user values or dynamic object-member names. The existing
third-party HTTP-client trace limitation remains documented. No raw payloads or
credentials enter generated fixtures or committed live artifacts.

After dispatch, reuse current transport/HTTP/envelope errors, `responseMeta()`,
CallResult handling and typed payload validation. In particular, a response-shape
mismatch after a confirmed mutation outcome must not retry, replay the raw body,
retry with form encoding or split a request into several calls.

## Source and review evidence

This correction distinguishes four evidence layers. The existing compressed
2026-10-13 OpenAPI snapshots identify **11.138.0.10**; their body/prose/result and
provider-parameter contracts were inspected locally, without modifying sources.
The [review of original head 4ee9fae, submitted 2026-10-31 at 12:27:37 UTC](https://github.com/hostingpal/whm-api-client/pull/73#pullrequestreview-5722705880)
reports upstream implementation inspection under `/usr/local/cpanel` on
**11.138.0.19**, including `Cpanel/StatManager.pm`,
`Cpanel/StatsManager/Configuration/Domain.pm::save_configuration`,
`Cpanel::JSONAPI::is_json_request`, `Cpanel::API::_safety_check_args` and
`Cpanel/Args.pm:86`. Those Perl implementation findings are attributed to the
reviewer; this correction does not claim local inspection of those files. The
[count correction, posted 2026-10-31 at 12:27:45 UTC](https://github.com/hostingpal/whm-api-client/pull/73#issuecomment-6543354915)
changes the `requires_json` statement to five functions across four modules.

The same review reports raw server requests deliberately bypassing this client,
on disposable account `wrvjson`, with a temporary token revoked and account/home
cleanup confirmed by the reviewer. These are server-only observations, not typed
client, runtime implementation or evidence-capture coverage. In particular, direct
UAPI rejected an absent body or `{}` for missing `changes`, `{"changes":[]}` for
having no changes, and `{"changes":{}}` for not being an array. The permission-blocked
well-formed StatsManager cases retain their limits above. The original bridge
HTTP 400 attribution and non-object acceptance were corrected by the later
PR81 review described below. No successful StatsManager write
was observed. The effective required-input contract, full `api.` reservation,
exact header, shared nesting budget and initial bridge refusal are client policy
rulings informed by those sources, rather than newly observed live successes.

## Pinned inventory and implementation boundaries

Inputs remain the 2026-10-13 snapshots; ordinary checks stay offline:

- WHM decompressed SHA-256:
  `6edb13c51d53f3c2be1fa83cc93e31d3a81f4f8506b3b45284fa00b986041594`.
- UAPI decompressed SHA-256:
  `172660ce0d8e0868756dabf8e3243ea41399aac7bff06082d806c73f71a094fc`.

There are thirteen pinned JSON-body operations: eight WHM (batch cPHulk records,
personalization get/set, PHP-FPM configuration get/set, connected application save,
TFA user configuration, SQL configuration update) and five UAPI (ClamScanner
disinfect, notification preferences, Personalization get/set, StatsManager save).
All thirteen are POST body operations; none has a formally top-level nullable
body. This count is backlog inventory, not delivery acceptance or live coverage.

The shared input grammar supports reviewed explicit object/list/scalar schemas,
nullability and unambiguous alternatives. Whole-body generation requires a
nonnullable object root and no query declarations; reserved root names are linted
whether optional or required. Missing formal type declarations
need explicit evidenced corrections. Composed/ambiguous schemas, dynamic content
with no schema, general format-only inheritance fixes and depth fallback are not
silently solved here; retain `8x0.1.22`, `8x0.1.23`, `8x0.10.5` as applicable.
No multipart, arbitrary PSR request, streaming JSON parser, automatic batching,
provider credentials, legal acceptance or remote execution orchestration is added.

## Acceptance and delivery

Independent request fixtures prove exact Content-Type, path, query, authentication,
account isolation and raw bytes; typed fixtures prove exact decoded JSON kinds and
values. They must not compute expected output with the production serializer.
Present typed and raw JSON bodies assert the final header value is exactly
`application/json`, with no charset or other parameters. Absent bodies assert no
JSON Content-Type; JSON-valued form parameters assert the outer form Content-Type.
Required cases include omitted/null/empty body differences where the effective
operation permits them; nested missing/null; false/zero/empty strings; known
object/list empty arrays; sparse lists; unknown fields; malformed UTF-8; NAN/INF;
precision/locale independence; cyclic/deep arrays; raw whitespace, duplicate keys,
large numbers and local rejection of every non-object root; invalid/empty raw JSON; and failures in the last
input entry causing no I/O.

Depth fixtures cover list, object and mixed nesting with scalar leaves and empty
deepest containers: 511 containers accepted, 512 and 513 rejected by both raw and
typed boundaries. Confirm typed output passes `json_validate(..., 512)`, despite
native encoder acceptance at 512, and schema validation checks every branch rather
than only the caller-selected alternative. Scalar leaves/alternatives add no
container level. Over-budget descriptors fail as specs, over-budget values as
caller input, with no partial output or I/O.

StatsManager fixtures reject omitted/null/empty configuration, missing or empty
`changes`, non-list `changes`, empty rows, and every missing required domain,
analyzers, name or enabled field. They retain `analyzers: []` as structurally valid,
prove bool-to-integer `0|1` mapping independently of integer response flags, and
cover status-1 warnings for skipped entries without claiming all entries applied.
These are future offline acceptance obligations, not a claim that new tests or a
RED/green cycle were run for this prose-only correction.

Reserved-name fixtures cover the entire case-sensitive `api.` prefix on WHM and
direct UAPI, in raw and future typed paths, for caller root members: version, filter, sort, paginate, columns, bare `api.` and arbitrary
future control names. Include JSON-escaped member names, null-valued presence and
a reserved occurrence hidden by a later duplicate. Compare `Api.version`,
`API.version`, `api_version` and `xapi.version` lookalikes: this reservation alone
does not reject them. Nested `api.*` members remain endpoint data, subject to the
typed schema. UAPI also rejects exact root `cpanel.user`, `cpanel.module`,
`cpanel.function` even direct, while bridge bodies remain locally refused. Legacy
generic `call()` filtering stays unchanged.

Route tests cover WHM and direct UAPI plus local rejection of bridged UAPI body JSON.
Field-JSON tests prove one JSON serialization and one outer encoding, with legacy
string calls unchanged. Metadata-reset, API failure, confirmed-outcome mismatch,
no-retry and sensitive-trace tests apply to both typed and raw paths. Generation
must reject malformed descriptors, preserve precise PHPStan shapes, keep canonical
alias counts correct and pass existing source/map/code reproducibility gates.

Endpoint live testing remains separately scoped and recorded. A syntax-valid raw
request, an offline fixture or an API success alone does not prove a whole module,
an alternate route, persistent setting behavior or permission enforcement.

Beads is the durable implementation plan; this document is not a Markdown TODO list.

| Bead | Deliverable / prerequisite |
| --- | --- |
| `8x0.29.1` | Publish this reviewed design and link existing work |
| `8x0.29.2` | Schema-guided serializer and input/body presence distinctions |
| `8x0.29.3` | Raw callJson and shared WHM/direct-UAPI JSON execution |
| `8x0.29.4` | Generated typed JSON body specs; depends on .2 and .3 |
| `8x0.29.5` | JSON-valued parameters and provider companion; depends on .2, alias support `8x0.28.5` and scalar-fidelity audit `8x0.1.23` |
| `8x0.1.18` | First selected typed body adopter, StatsManager; depends on .4; successful live-write gate OPEN, currently permission-blocked |
| `8x0.29.6` | Delivered-route/compatibility/coverage audit; depends on .5 and the first body adopter |

The foundation tasks depend on design publication. Existing schema/source issues
retain prior descriptions and findings; link related work without closing it.
Concrete helper class layout, test file organization and exact overlay descriptor
member names are left to the builder. Any change to the public contracts above
requires a reviewed amendment. The owner has resumed Astra's implementation work
in PR80 onward. PR81 amends this design alongside raw runtime corrections; downstream typed
adoption and its external review retain their separate processes and scoped live gates.


## PR81 execution-contract amendment provenance (2026-10-31)

[Review 5723716149 of PR81 head 69871030d0ff9001ebb389064c364ee17464bfda](https://github.com/hostingpal/whm-api-client/pull/81#pullrequestreview-5723716149)
and the [superseding PR73 correction 6545903345](https://github.com/hostingpal/whm-api-client/pull/73#issuecomment-6545903345)
replace the original URL-version, separate-query, all-root and old bridge-400
rationale. Historical PR73 approval and audit remain recorded; those portions
are superseded by this amendment.

The reviewer inspected cPanel 11.138.0.19 Cpanel::Form::Timed / parsejson source
and ran read-only calls through real callJson at that original PR81 head, plus raw
variants the client refused. WHM version and accountsummary succeeded with the
version in the JSON root and no query; URL-only versions returned HTTP 400 because
API 0 was selected. Query-only required arguments were ignored on WHM and direct
UAPI. Direct valid array, scalar and null roots and sampled WHM array/null roots
returned HTTP 500 before dispatch. Direct flat JSON, Personalization/get nested
JSON, padded whitespace, escaped names and duplicate ordinary members worked;
the server used the last duplicate. All JSON writes remained untested. These are
reviewer source/runtime observations scoped to that version/head, not local root
live verification or new committed capture coverage.

Local pinned-snapshot inspection at 11.138.0.10 confirms thirteen JSON-body POSTs
have no query parameters. Six of eight WHM code samples put api.version in the
body; update_sql_config instead uses a URL sample, and the TFA user-config sample
has neither. Those immutable source discrepancies remain visible. The shared
WHM insertion policy applies to all eight, including future typed dispatch.
