<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestBodyFormat;
use HostingPal\WhmApiClient\Api\RequestMethod;
use LogicException;

/**
 * Describe and read the generated per-operation spec format.
 *
 * tools/api-map generates one spec constant per operation in the group's Spec
 * class. Keys:
 *
 * - function: the wire operation name (required string).
 * - module: the UAPI module (UAPI operations only).
 * - method: 'GET' or 'POST'.
 * - outcome: 'flag' or 'unchecked'.
 * - body: optional 'form' (default), 'multipart' (UAPI POST only) or 'json' (POST).
 * - json_body: only for body=json; {php: argument name, required: bool,
 *   routes: 'direct', schema: strict StructuredJsonSchema descriptor}. The body
 *   argument is separate from params, is always sensitive, and is serialized once.
 *   Execution requires a nonnullable object root and empty params (no URL query).
 *   WHM body specs must be required so API version routing cannot disappear.
 *   Direct-UAPI optional bodies use null for absence; [] is a present empty object.
 *   WHM inserts library-owned api.version:1 first in the root; direct UAPI keeps
 *   the encoded object unchanged. Bridged UAPI fails locally without I/O.
 * - params: PHP argument name => array{name: wire name, encoding: 'scalar'|
 *   'bool'|'repeat'|'numbered'|'suffix-numbered'|'suffix-numbered-scalar', omit_empty?: true}, in
 *   signature order. Suffix encodings replace terminal * with one-based indices
 *   (action1, action2); the scalar form also permits integer list items.
 *   omit_empty is accepted only with repeat and skips exactly [] before wrapping
 *   it as a repeated list. Generation restricts this explicit option to optional
 *   string-list source parameters; absent settings retain empty-list rejection.
 *   New explicit encoding 'collection' requires a collection descriptor with
 *   kind ('indexed'|'repeat'|'map'), item_type ('string'|'int'|'bool'|'string|int'),
 *   allow_scalar (boolean), and empty ('reject' for lists). Indexed descriptors additionally
 *   require literal identifier prefix, separator (''|'-'), and nonnegative integer
 *   start (at most 2147483647). Repeat descriptors forbid these index fields.
 *   For list kinds, scalars use name; arrays use the pattern, including singletons.
 *   Bool items become integer 1/0. Null/float items are invalid; optional top-level
 *   null still omits. Collection metadata never combines with legacy encodings,
 *   omit_empty or reject_empty. Map descriptors require allow_scalar false,
 *   empty ('allow'|'reject'), and a literal prefix (empty or ASCII identifier with
 *   hyphens); index separator/start fields are forbidden. Each nonempty UTF-8
 *   string key without ASCII control bytes is appended literally to that prefix.
 *   Integer keys (including PHP-coerced numeric strings) and non-array inputs fail.
 *   Optional reject_prefixed_keys: true requires a nonempty map prefix and rejects
 *   caller keys beginning with that prefix without stripping or rewriting them.
 *   It is declared only for the two AutoSSL provider maps. Other maps remain literal.
 *   Empty allowed maps emit no dynamic fields; the request still dispatches.
 *   Map PHPDoc is array<string,T> or non-empty-array<string,T>; top-level null
 *   omits optional arguments. Generated collections receive SensitiveParameter
 *   for trace protection, independently of
 *   secret-bearing transport classification, which still requires POST.
 *   Encoding 'records' requires records: {start, separator, fields}. Fields map
 *   row keys to {prefix, type, required}; strict types are string/int/bool/string|int.
 *   A nonempty sequential list of associative records expands in row/declared-field
 *   order. Missing optional fields remain absent; explicit null/unknown keys fail.
 *   Every field uses the original row position; no independent-list compaction.
 *   Additional generated method constants inherit the canonical operation spec
 *   and replace only params, sharing route, method, outcome and return schema.
 *   Encoding 'json' requires json: {required: bool, schema: strict
 *   StructuredJsonSchema descriptor}; it stays in params under one original wire
 *   name. Validate and serialize the complete value once, then form-encode the
 *   resulting string once. This is distinct from body=json and json_body: no JSON
 *   content-type or root routing guard applies inside this field. Required null
 *   is present JSON null when allowed. Optional nonnullable null means absence;
 *   optional nullable roots are unsupported. Malformed descriptors fail before I/O.
 * - returns: required key; a type spec (see ShapeValidator for the grammar), or
 *   an explicit null for a void operation.
 * - unwrap: optional string naming a field declared in an object "returns";
 *   that field of the validated object is returned.
 * - optional: optional boolean (only with a non-null "returns"). When true, an
 *   omitted or null data member is a valid response and the operation returns
 *   null; any other payload is validated as usual.
 *
 * A malformed spec is a generator defect: SpecChecker rejects it with
 * LogicException before the request is dispatched, never as an API or payload
 * failure. Wire names are unique across params; "unwrap" requires a non-nullable
 * object "returns".
 *
 * @internal
 */
final class OperationSpec
{
    /**
     * Map the spec's HTTP method to the request method.
     *
     * The spec's method must be exactly GET or POST; any other value means the
     * generator emitted a spec this runtime does not support.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the method is not GET or POST.
     */
    public static function method(array $spec): RequestMethod
    {
        return RequestMethod::tryFrom(self::text($spec, 'method'))
            ?? throw new LogicException('Unsupported operation method.');
    }

    /**
     * Read a generated operation's body encoding; older specs keep form encoding.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the body format is unknown or not a string.
     */
    public static function body(array $spec): RequestBodyFormat
    {
        if (!array_key_exists('body', $spec)) {
            return RequestBodyFormat::Form;
        }
        return RequestBodyFormat::tryFrom(self::text($spec, 'body'))
            ?? throw new LogicException('Unsupported operation body format.');
    }

    /**
     * Read the separately validated whole-body argument descriptor.
     *
     * @param array<mixed> $spec
     * @return array{php: string, required: bool, routes: string, schema: array<mixed>}
     */
    public static function jsonBody(array $spec): array
    {
        $body = $spec['json_body'] ?? null;
        $params = $spec['params'] ?? null;
        if (
            ($spec['method'] ?? null) !== 'POST' || !is_array($body) || !is_array($params)
            || !is_string($body['php'] ?? null) || $body['php'] === ''
            || !is_bool($body['required'] ?? null) || ($body['routes'] ?? null) !== 'direct'
            || !is_array($body['schema'] ?? null) || array_key_exists($body['php'], $params)
            || array_diff(array_keys($body), ['php', 'required', 'routes', 'schema']) !== []
        ) {
            throw new LogicException('Invalid JSON body operation spec.');
        }
        return [
            'php' => $body['php'], 'required' => $body['required'],
            'routes' => $body['routes'], 'schema' => $body['schema'],
        ];
    }

    /**
     * Map the spec's outcome policy name to the policy.
     *
     * Only 'flag' and 'unchecked' are generated; both correspond to the
     * OutcomePolicy cases of the same meaning.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the policy is unknown.
     */
    public static function outcome(array $spec): OutcomePolicy
    {
        return match (self::text($spec, 'outcome')) {
            'flag' => OutcomePolicy::Flag,
            'unchecked' => OutcomePolicy::Unchecked,
            default => throw new LogicException('Unsupported outcome policy.'),
        };
    }

    /**
     * Read a required string field of the spec.
     *
     * Used for the wire names and policy keys that the generated spec must
     * declare as strings; a missing or non-string value is a generator defect.
     *
     * @param array<mixed> $spec
     * @throws LogicException If the field is missing or not a string.
     */
    public static function text(array $spec, string $key): string
    {
        $value = $spec[$key] ?? null;

        return is_string($value) ? $value : throw new LogicException('Operation spec is missing "' . $key . '".');
    }
}
