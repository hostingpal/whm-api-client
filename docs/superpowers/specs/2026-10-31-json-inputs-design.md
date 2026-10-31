# Structured JSON inputs and the callJson escape hatch

Date: 2026-10-31 (Europe/Belgrade). Status: the user approved structured PHP inputs
for typed methods and an explicit low-level `callJson()` escape hatch for raw JSON.
This document records that decision and the engineering rules needed to implement
it. Delivery epic: `whm-api-client-8x0.29`. This design PR changes no runtime code and
establishes no live endpoint or route compatibility.

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
| Typed JSON body | Schema-described PHP data | One JSON document with `Content-Type: application/json` |
| Raw JSON body | `string $json` through `callJson()` | The same validated JSON bytes, unchanged |
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
    #[SensitiveParameter] array $query = [],
    OutcomePolicy $outcome = OutcomePolicy::Flag,
): CallResult;

// UAPI handle
public function callJson(
    string $module,
    string $operation,
    #[SensitiveParameter] string $json,
    #[SensitiveParameter] array $query = [],
    OutcomePolicy $outcome = OutcomePolicy::Flag,
): CallResult;
```

`operation` is the documented wire function name, not a URL. Existing `call()`
argument names and behavior do not change. `$query` uses the existing documented
`Parameters` representation, including current list markers; it is explicit query
data, not extra JSON body fields. There is no method, arbitrary URL, header,
authentication or account override argument. The HTTP method is POST.

The raw method returns the same untyped `CallResult` contract as `call()` and uses
the same explicit outcome policies and envelope checks. It does not validate an
operation's request or response field schema and does not increase typed coverage.
The caller is responsible for choosing a JSON-compatible operation and supplying
its required data. Raw access is not a claim that every named operation accepts a
JSON body or uses the standard envelope.

### Syntax and byte preservation

PHP 8.3 is already the package minimum. Use `json_validate($json, 512)` or an
exactly equivalent strict syntax check. Reject invalid UTF-8, invalid JSON, empty
or whitespace-only documents, trailing non-whitespace text and nesting beyond the
512-depth limit. The limit is a client resource constraint, not a claim that deeper
JSON is syntactically invalid. Do not strip a BOM or repair malformed input.

A valid root object, list, string, number, boolean or null is accepted by the raw
syntax layer; endpoint acceptance is a separate concern. Preserve leading/trailing
JSON whitespace, object-key order, duplicate ordinary keys, escaped spelling,
large numeric literals and the exact bytes supplied. Never decode and re-encode
to form the outgoing body, wrap it, pretty-print it or add a trailing newline.
Valid duplicate keys are not an endorsement of any server's duplicate-key behavior.

Routing checks may inspect decoded root member names, with the same depth bound;
that inspection must not supply the serialized body or coerce numeric literals.
No endpoint-specific shape validation is added as a side effect of inspection.

### Query and routing protections

WHM names use the current allowed function-path grammar; UAPI module/function
names use the current segment grammar. WHM always controls `api.version=1` in the
query. Account selection, base URL and credentials come only from the handle.

For the new JSON entry points, reject collisions with reserved routing names in
explicit query fields, expanded query fields or root JSON object members. Test
member names after JSON escape decoding, including a reserved key whose value is
null; presence is what matters. Nested JSON member names remain ordinary endpoint
data. Reject collisions before the legacy handle filtering could discard them.
WHM reserves `api.version`. UAPI JSON entry points additionally protect
`cpanel.user`, `cpanel.module` and `cpanel.function`, even for a direct handle, so
body/query values cannot become an alternate routing interface later.

Apply normal percent encoding to the separate query exactly once; do not infer or
move caller query fields into JSON. Query values can be logged by the server even
when library exception frames are protected. PHPDoc must say to keep secrets in
the body unless a reviewed operation explicitly requires otherwise. This new
rejection policy does not silently change legacy `call()` filtering behavior.

## Supported routes and transport limits

Initial runtime targets are ordinary WHM API 1 and direct cPanel UAPI JSON POST.
Use the configured authentication and base URI with the existing PSR-17/PSR-18
transport. Preserve the configured verified-TLS/disabled-redirect requirements,
timeout ownership and single attempt. No new runtime framework dependency is added.

A UAPI handle using WHM credentials is a bridged handle. Initial `callJson()` and
typed JSON-body execution on that handle fail locally after metadata reset with
`InvalidArgumentException`: JSON bodies on that route are not enabled by this
spec. There is no form fallback, direct-account fallback, extra login, alternative
credential lookup or account-selector change. This is a deliberate initial support
boundary, not a statement that the server can never support bridged JSON.

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

Use the same maximum nesting budget of 512 for typed encoding. Validate and encode
the complete input before the first request; a failure near the end does not send
an earlier partial structure. Cyclic PHP arrays must fail within the bounded
traversal/encoding budget without unbounded recursion or a network request.

### Body presence and public method generation

The internal request representation distinguishes absent body, present JSON null,
present empty object and present empty list. Never represent all four as one PHP
null sentinel or infer body presence from truthiness.

For new body operations, the canonical generated input is a precisely shaped
whole-body argument. An optional, non-nullable object body uses `?array $body = null`:
null omits the HTTP body; `[]` sends `{}`. An optional, non-nullable list similarly
uses null for absence and `[]` for a present list. A required body has a required
argument. A required nullable body accepts explicit null as JSON null.

If an operation has an *optional nullable* body, introduce an explicit `Omitted`
enum default (`Omitted::Value`) for that argument: omitted/default means no body,
explicit null means a present JSON null. This is a presence marker, not a raw-JSON
wrapper. All thirteen currently pinned JSON-body schemas must be audited before
claiming that this case is needed or covered; unused marker support need not ship.
Nested object-member presence always uses `array_key_exists`, so absent and null
stay distinct without a marker inside ordinary object arrays.

Whole-body arguments avoid losing optional-member presence while flattening body
fields into PHP defaults. A generated convenience alias with named body fields may
be added only when it keeps every supported presence state explicit; it is not a
prerequisite for JSON support. The discussion's named `changes` example illustrates
such convenience, while the canonical body form below makes all source states
representable. Do not change an already published scalar/string method's signature
in order to retrofit this layout.

An omitted body means no body bytes and no JSON Content-Type. A present JSON null
means four bytes `null` and JSON Content-Type. Empty object/list bodies have their
own bytes and the JSON header. Transport code must preserve that distinction.

## First typed body adopter: StatsManager

Select `uapi:StatsManager/save_configuration#POST` for `8x0.1.18`. Its pinned body
is an optional object with optional `changes`, a list of domain/analyzer records.
The domain and analyzer fields also lack formal required declarations. Preserve
that source requiredness rather than deriving required fields from examples.

Generate `saveConfiguration(?array $configuration = null): array`, with the exact
source-derived input and result shapes determined from the pinned operation. The
body uses `changes`, `domain`, `analyzers`, `name` and `enabled` wire member names.
`enabled` is a reviewed input boolean mapped to the formal integer 0/1 schema.
The generated input shape is:

```php
/** @phpstan-type StatsConfigurationInput array{
 *     changes?: list<array{
 *         domain?: string,
 *         analyzers?: list<array{name?: string, enabled?: bool}>,
 *     }>,
 * } */
```

The pinned response is a list of domain records with optional `domain` and
`analyzers`; analyzer rows have optional `name` and integer 0/1 `available`,
`enabled`, `locked` fields. Keep these response flags integers, independently of
input booleans. Preserve the source's descriptions without inferring enforcement
from the returned flags.

```php
// Future generated API; direct cPanel handle.
$client->uapi()->statsManager()->saveConfiguration(configuration: [
    'changes' => [
        [
            'domain' => 'example.com',
            'analyzers' => [
                ['name' => 'awstats', 'enabled' => true],
                ['name' => 'analog', 'enabled' => false],
            ],
        ],
    ],
]);
```

The present body is one JSON object. `configuration: []` sends `{}`;
`configuration: ['changes' => []]` sends `{"changes":[]}`; omitted/null configuration
omits the body. These are representational guarantees, not a promise of equivalent
server behavior. No new local nonempty constraint or no-op interpretation is added.

The source's samples quote `enabled` values even though the formal schema declares
integers. Preserve that discrepancy and select formal integer wire values with
source evidence; live compatibility must be observed, not presumed. The WebServer
role and root/reseller-controlled analyzer caveats remain in generated PHPDoc.
Result schema, actual supported values and live mutation ownership are still
reviewed in the endpoint bead before implementation and capture.

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
made. Sensitive annotations for configuration data and payload-bearing frames are
preserved. Existing already-serialized input bytes must not be parsed/re-encoded.

This companion uses the operation-alias support tracked in `8x0.28.5`. It counts as
another PHP interface to the same operation, not another documented operation.
Other JSON-valued fields need their own source-backed selection. In particular,
`update_customizations` composed content and the disputed backup destination
form/JSON transport remain `8x0.1.22` evidence/schema work; serializer availability
does not resolve those contradictions.

## Errors, metadata and secrets

Clear the owning handle's metadata before name/route/query/JSON/schema checks, as
with existing calls. PHP signature TypeErrors remain outside the method lifecycle.
Invalid caller JSON or structured content raises `InvalidArgumentException`;
malformed generated specs raise `LogicException`. No request is sent for either.
The implementation may chain a JsonException only if its message/trace does not
expose payload content; never include supplied JSON fragments or dynamic keys.

Protect all library frames holding raw/structured/serialized data with
`SensitiveParameter`. Error text may identify the public argument or a static
schema location, but not user values or dynamic object-member names. The existing
third-party HTTP-client trace limitation remains documented. No raw payloads or
credentials enter generated fixtures or committed live artifacts.

After dispatch, reuse current transport/HTTP/envelope errors, `responseMeta()`,
CallResult handling and typed payload validation. In particular, a response-shape
mismatch after a confirmed mutation outcome must not retry, replay the raw body,
retry with form encoding or split a request into several calls.

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
This count is backlog inventory, not delivery acceptance or live coverage.

The initial body generation grammar supports reviewed explicit object/list/scalar
schemas, nullability and unambiguous alternatives. Missing formal type declarations
need explicit evidenced corrections. Composed/ambiguous schemas, dynamic content
with no schema, general format-only inheritance fixes and depth fallback are not
silently solved here; retain `8x0.1.22`, `8x0.1.23`, `8x0.10.5` as applicable.
No multipart, arbitrary PSR request, streaming JSON parser, automatic batching,
provider credentials, legal acceptance or remote execution orchestration is added.

## Acceptance and delivery

Independent request fixtures prove exact Content-Type, path, query, authentication,
account isolation and raw bytes; typed fixtures prove exact decoded JSON kinds and
values. They must not compute expected output with the production serializer.
Required cases include omitted/null/empty body differences; nested missing/null;
false/zero/empty strings; known object/list empty arrays; sparse lists; unknown
fields; malformed UTF-8; NAN/INF; precision/locale independence; cyclic/deep arrays;
raw whitespace, duplicate keys, large numbers and root scalars; invalid/empty raw
JSON; encoded reserved root keys; and failures in the last input entry causing no I/O.

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
| `8x0.1.18` | First selected typed body adopter, StatsManager; depends on .4 |
| `8x0.29.6` | Delivered-route/compatibility/coverage audit; depends on .5 and the first body adopter |

The foundation tasks depend on design publication. Existing schema/source issues
retain prior descriptions and findings; link related work without closing it.
Concrete helper class layout, test file organization and exact overlay descriptor
member names are left to the builder. Any change to the public contracts above
requires a reviewed amendment. Production implementation remains paused while the
current implementation PRs finish their separate review/integration process.
