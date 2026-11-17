<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestBodyFormat;
use LogicException;

/**
 * Check a whole generated operation spec before any request is sent.
 *
 * OperationRunner::run() calls check() before it encodes arguments, so a defective
 * spec raises LogicException without I/O, whichever arguments a call supplies and
 * whichever payload the server would return. The check covers the operation keys
 * described on OperationSpec, every parameter spec (including those of arguments a
 * call omits), and the complete ShapeValidator type grammar recursively: every list
 * item, map value, object field, optional object extra value and union branch, and the type of nullable specs,
 * which the validator never inspects for a null payload. It also rejects duplicate
 * wire names and statically overlapping numbered families across parameters,
 * declared api. controls and bridge controls on shared UAPI specs,
 * "unwrap" on a nullable object result (a null payload could never be unwrapped),
 * and "required" entries that are not strings naming declared fields (the validator
 * compares required names as strings). Keys this runtime does not read are ignored,
 * as the runner and validator ignore them.
 *
 * Specs are plain constant arrays and have no identity, so successful checks are
 * cached per process under a hash of the serialized spec: a repeated call costs one
 * serialize() and one xxh128 hash, the cache grows only with distinct sound specs
 * (one per generated operation), and a defective spec is rechecked and rejected on
 * every call.
 *
 * @phpstan-import-type CollectionSpec from CollectionEncoder
 * @phpstan-import-type RecordSpec from RecordEncoder
 * @internal
 */
final class SpecChecker
{
    private const ENCODINGS = [
        'scalar', 'bool', 'repeat', 'numbered', 'suffix-numbered', 'suffix-numbered-scalar', 'collection', 'records',
        'json',
    ];

    /** Accepted wire variants per type; types not listed accept none. */
    private const ACCEPT = [
        'int' => ['numeric-string', 'integral-fixed-point-string'],
        'number' => ['numeric-string'],
        'bool' => ['int-flag', 'string-flag'],
        'flag' => ['string-flag', 'bool', 'empty-string'],
    ];

    private const TYPES = ['string', 'int', 'number', 'bool', 'flag', 'mixed', 'list', 'map', 'object', 'union'];

    /** @var array<string, true> Hashes of specs that passed the check. */
    private static array $sound = [];

    /**
     * Reject a defective operation spec; sound specs are remembered.
     *
     * @param array<mixed> $spec
     * @throws LogicException If any part of the spec is defective.
     */
    public static function check(array $spec): void
    {
        $key = hash('xxh128', serialize($spec));
        if (isset(self::$sound[$key])) {
            return;
        }

        self::operation($spec);
        self::$sound[$key] = true;
    }

    /**
     * Check one type spec and everything nested in it.
     *
     * @throws LogicException If the type spec is defective.
     */
    private static function type(mixed $type): void
    {
        if (!is_array($type) || !in_array($type['type'] ?? null, self::TYPES, true)) {
            throw new LogicException('Unsupported type spec.');
        }

        $kind = $type['type'];
        if (array_key_exists('literal', $type) && ($kind !== 'string' || !is_string($type['literal']))) {
            throw new LogicException('Invalid string literal spec.');
        }

        self::flag($type, 'nullable');
        self::flag($type, 'accept_empty_array');
        if (array_key_exists('accept_empty_array', $type) && $kind !== 'object') {
            throw new LogicException('Empty-array compatibility applies only to object specs.');
        }
        $accept = $type['accept'] ?? [];
        if (!is_array($accept) || !array_is_list($accept)) {
            throw new LogicException('Invalid "accept" type spec.');
        }

        foreach ($accept as $variant) {
            if (!in_array($variant, self::ACCEPT[$kind] ?? [], true)) {
                throw new LogicException('Unsupported accepted variant for a ' . $kind . ' type spec.');
            }
        }

        if (isset($type['preserve']) && ($kind !== 'number' || !is_bool($type['preserve']))) {
            throw new LogicException('Invalid "preserve" type spec.');
        }

        match ($kind) {
            'list' => self::type($type['items'] ?? null),
            'map' => self::type($type['values'] ?? null),
            'object' => self::object($type),
            'union' => self::union($type),
            default => null,
        };
    }

    /**
     * Check the operation-level keys, the parameters and the return spec.
     *
     * @param array<mixed> $spec
     */
    private static function operation(array $spec): void
    {
        OperationSpec::text($spec, 'function');
        OperationSpec::method($spec);
        OperationSpec::outcome($spec);
        if (array_key_exists('module', $spec)) {
            OperationSpec::text($spec, 'module');
        }

        self::params($spec['params'] ?? null, OperationSpec::body($spec), isset($spec['module']));
        if (OperationSpec::body($spec) === RequestBodyFormat::Json) {
            self::jsonBody($spec);
        } elseif (array_key_exists('json_body', $spec)) {
            throw new LogicException('JSON body metadata requires JSON encoding.');
        }
        if (array_key_exists('multipart_upload_family', $spec)) {
            UploadFamily::descriptor($spec);
        }
        if (array_key_exists('multipart_upload', $spec)) {
            ScalarUpload::descriptor($spec);
        }
        if (array_key_exists('multipart_uploads', $spec)) {
            UploadList::descriptor($spec);
        }
        if (
            OperationSpec::body($spec) === RequestBodyFormat::Multipart
            && !isset($spec['multipart_uploads']) && !isset($spec['multipart_upload'])
            && !isset($spec['multipart_upload_family'])
        ) {
            if (($spec['method'] ?? null) !== 'POST' || !isset($spec['module'])) {
                throw new LogicException('Multipart requires a UAPI POST operation.');
            }
            $parameters = $spec['params'] ?? null;
            if (!is_array($parameters)) {
                throw new LogicException('Multipart requires parameter specs.');
            }
            foreach ($parameters as $parameter) {
                if (!is_array($parameter) || ($parameter['encoding'] ?? null) !== 'scalar') {
                    throw new LogicException('Multipart requires scalar string fields.');
                }
            }
        }

        if (!array_key_exists('returns', $spec)) {
            throw new LogicException('Operation spec is missing "returns".');
        }

        $returns = $spec['returns'];
        if ($returns !== null) {
            self::type($returns);
        }

        self::flag($spec, 'optional');
        if (($spec['optional'] ?? false) === true && $returns === null) {
            throw new LogicException('Invalid optional spec.');
        }

        self::rows($spec, $returns);
        self::emptyWithoutData($spec, $returns);
        AccountSearchEmpty::check($spec);

        $unwrap = $spec['unwrap'] ?? null;
        if ($unwrap === null) {
            return;
        }

        $fields = is_array($returns) ? ($returns['fields'] ?? null) : null;
        if (
            !is_string($unwrap)
            || !is_array($returns)
            || $returns['type'] !== 'object'
            || ($returns['nullable'] ?? false) === true
            || !is_array($fields)
            || !array_key_exists($unwrap, $fields)
        ) {
            throw new LogicException('Invalid unwrap spec.');
        }
    }

    /**
     * Limit no-row failure classification to explicit unchecked UAPI object or root-list batches.
     *
     * A list root uses rows '$'; object selectors remain literal field names.
     * The root, selected list and its object items must be nonnullable. Field requiredness remains
     * part of the payload schema; only status 0 additionally needs usable rows.
     *
     * @param array<mixed> $spec
     */
    private static function rows(array $spec, mixed $returns): void
    {
        if (!array_key_exists('rows', $spec)) {
            return;
        }

        $name = $spec['rows'];
        $fields = is_array($returns) ? ($returns['fields'] ?? null) : null;
        // '$' selects the root only for a list; object keys remain literal.
        $root = is_array($returns) && ($returns['type'] ?? null) === 'list' && $name === '$';
        $field = $root ? $returns : (is_array($fields) && is_string($name) ? ($fields[$name] ?? null) : null);
        $items = is_array($field) ? ($field['items'] ?? null) : null;
        if (
            !is_string($name)
            || $name === ''
            || !isset($spec['module'])
            || OperationSpec::outcome($spec) !== OutcomePolicy::Unchecked
            || array_key_exists('optional', $spec)
            || array_key_exists('unwrap', $spec)
            || !is_array($returns)
            || (!$root && ($returns['type'] ?? null) !== 'object')
            || ($returns['nullable'] ?? false) === true
            || !is_array($field)
            || ($field['type'] ?? null) !== 'list'
            || ($field['nullable'] ?? false) === true
            || !is_array($items)
            || ($items['type'] ?? null) !== 'object'
            || ($items['nullable'] ?? false) === true
        ) {
            throw new LogicException('Invalid rows result policy.');
        }
    }

    /**
     * Limit the empty result to a WHM flag outcome that unwraps a nonnullable list.
     *
     * Only the flag policy guarantees a confirmed success before payload handling,
     * so an unchecked failure without data can never read as an empty result.
     *
     * @param array<mixed> $spec
     */
    private static function emptyWithoutData(array $spec, mixed $returns): void
    {
        if (!array_key_exists('empty_without_data', $spec)) {
            return;
        }

        $fields = is_array($returns) ? ($returns['fields'] ?? null) : null;
        $unwrap = $spec['unwrap'] ?? null;
        $field = is_array($fields) && is_string($unwrap) ? ($fields[$unwrap] ?? null) : null;
        if (
            $spec['empty_without_data'] !== true
            || isset($spec['module'])
            || OperationSpec::outcome($spec) !== OutcomePolicy::Flag
            || array_key_exists('optional', $spec)
            || array_key_exists('rows', $spec)
            || !is_array($returns)
            || ($returns['type'] ?? null) !== 'object'
            || ($returns['nullable'] ?? false) === true
            || !is_array($field)
            || ($field['type'] ?? null) !== 'list'
            || ($field['nullable'] ?? false) === true
        ) {
            throw new LogicException('Invalid empty_without_data result policy.');
        }
    }

    /** @param array<mixed> $spec */
    private static function jsonBody(array $spec): void
    {
        $body = OperationSpec::jsonBody($spec);
        $schema = StructuredJsonSchema::from($body['schema']);
        if ($spec['params'] !== []) {
            throw new LogicException('JSON body operations cannot declare query parameters.');
        }
        if ($schema->type !== 'object' || $schema->nullable) {
            throw new LogicException('JSON body operations require a nonnullable object root.');
        }
        if (!isset($spec['module']) && !$body['required']) {
            throw new LogicException('WHM JSON bodies must be required to carry API version routing.');
        }
        $reserved = isset($spec['module']) ? ['cpanel.user', 'cpanel.module', 'cpanel.function'] : [];
        foreach (array_keys($schema->fields) as $name) {
            if (str_starts_with($name, 'api.') || in_array($name, $reserved, true)) {
                throw new LogicException('JSON schema declares a reserved root member.');
            }
        }
    }

    /** Check every parameter spec, whether or not a call supplies its argument. */
    private static function params(mixed $params, RequestBodyFormat $body, bool $uapi): void
    {
        if (!is_array($params)) {
            throw new LogicException('Operation spec is missing "params".');
        }

        $wires = [];
        foreach ($params as $name => $param) {
            $wire = is_array($param) ? ($param['name'] ?? null) : null;
            if (
                !is_string($name)
                || !is_string($wire)
                || $wire === ''
                || !in_array($param['encoding'] ?? null, self::ENCODINGS, true)
            ) {
                throw new LogicException('Invalid parameter spec for ' . $name . '.');
            }

            if ($param['encoding'] === 'json') {
                if ($body !== RequestBodyFormat::Form) {
                    throw new LogicException('JSON fields require ordinary form encoding.');
                }
                JsonFieldEncoder::check($param['json'] ?? null);
            } elseif (array_key_exists('json', $param)) {
                throw new LogicException('JSON field metadata requires JSON field encoding.');
            }
            $records = null;
            if ($param['encoding'] === 'records') {
                $records = RecordEncoder::check($param['records'] ?? null);
            } elseif (array_key_exists('records', $param)) {
                throw new LogicException('Record metadata requires records encoding.');
            }
            $map = false;
            $declared = ['name' => $wire, 'encoding' => $param['encoding']];
            if ($records !== null) {
                $declared['records'] = $records;
            }
            if ($param['encoding'] === 'collection') {
                $declared['collection'] = CollectionEncoder::check($param['collection'] ?? null);
                $map = $declared['collection']['kind'] === 'map';
            } elseif (array_key_exists('collection', $param)) {
                throw new LogicException('Collection metadata requires collection encoding.');
            }

            if (
                array_key_exists('omit_empty', $param)
                && ($param['omit_empty'] !== true || $param['encoding'] !== 'repeat')
            ) {
                throw new LogicException('Invalid "omit_empty" parameter spec.');
            }

            if (
                array_key_exists('reject_empty', $param)
                && ($param['reject_empty'] !== true
                    || !in_array($param['encoding'], ['scalar', 'repeat'], true)
                    || array_key_exists('omit_empty', $param))
            ) {
                throw new LogicException('Invalid "reject_empty" parameter spec.');
            }

            $suffix = in_array($param['encoding'], ['suffix-numbered', 'suffix-numbered-scalar'], true);
            if (
                ($suffix && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\*$/D', $wire) !== 1)
                || (!$suffix && !$map && str_contains($wire, '*'))
            ) {
                throw new LogicException('Invalid suffix-numbered parameter pattern.');
            }
            if (
                str_starts_with($wire, 'api.')
                || ($uapi && in_array($wire, ['cpanel.user', 'cpanel.module', 'cpanel.function'], true))
            ) {
                throw new LogicException('Reserved wire name in the parameter specs.');
            }
            foreach ($wires as $other => $otherParam) {
                if ($wire === (string) $other) {
                    throw new LogicException('Duplicate wire name in the parameter specs.');
                }
                if ($map || ($otherParam['collection']['kind'] ?? null) === 'map') {
                    continue;
                }
                if (
                    self::overlaps($wire, $param['encoding'], (string) $other, $otherParam['encoding'])
                    || self::overlaps((string) $other, $otherParam['encoding'], $wire, $param['encoding'])
                    || self::collectionOverlaps($declared, $otherParam)
                    || self::collectionOverlaps($otherParam, $declared)
                ) {
                    throw new LogicException('Overlapping wire names in the parameter specs.');
                }
            }

            $wires[$wire] = $declared;
        }
        self::firstUnsuffixedOverlaps($params);
    }

    /**
     * Check the opt-in record namespace even when no arguments are supplied.
     *
     * Old descriptors retain their existing checks. Unknown map keys are checked
     * by OrderedFields when their concrete values are prepared.
     *
     * @param array<string, array<mixed>> $params
     */
    private static function firstUnsuffixedOverlaps(array $params): void
    {
        $optedIn = false;
        foreach ($params as $param) {
            $record = $param['records'] ?? null;
            $optedIn = $optedIn || (($param['encoding'] ?? null) === 'records'
                && is_array($record) && ($record['first_unsuffixed'] ?? false) === true);
        }
        if (!$optedIn) {
            return;
        }
        $families = [];
        foreach ($params as $origin => $param) {
            $encoding = $param['encoding'] ?? null;
            $wire = $param['name'] ?? null;
            if (!is_string($encoding) || !is_string($wire)) {
                throw new LogicException('Invalid parameter spec.');
            }
            $record = $encoding === 'records' ? RecordEncoder::check($param['records'] ?? null) : null;
            $opted = ($record['first_unsuffixed'] ?? false) === true;
            $names = [];
            $tails = [];
            if ($record !== null) {
                foreach ($record['fields'] as $field) {
                    if ($opted) {
                        $names[] = $field['prefix'];
                    }
                    $tails[] = [$field['prefix'] . $record['separator'], $opted ? 1 : $record['start']];
                }
            } elseif ($encoding === 'collection') {
                $collection = CollectionEncoder::check($param['collection'] ?? null);
                if ($collection['kind'] === 'map') {
                    continue;
                }
                if ($collection['kind'] === 'repeat' || $collection['allow_scalar']) {
                    $names[] = $wire;
                }
                if ($collection['kind'] === 'indexed') {
                    $tails[] = [$collection['prefix'] . $collection['separator'], $collection['start']];
                }
            } elseif (in_array($encoding, ['suffix-numbered', 'suffix-numbered-scalar'], true)) {
                $tails[] = [substr($wire, 0, -1), 1];
            } else {
                $names[] = $wire;
                if ($encoding === 'numbered') {
                    $tails[] = [$wire . '-', 1];
                }
            }
            $families[] = ['origin' => $origin, 'opted' => $opted, 'names' => $names, 'tails' => $tails];
        }
        foreach ($families as $i => $family) {
            foreach (array_slice($families, $i + 1) as $other) {
                if (!$family['opted'] && !$other['opted']) {
                    continue;
                }
                $overlap = array_intersect($family['names'], $other['names']) !== [];
                foreach ([[$family, $other], [$other, $family]] as [$left, $right]) {
                    foreach ($left['tails'] as [$base, $start]) {
                        foreach ($right['names'] as $name) {
                            $overlap = $overlap || self::indexContains($base, $start, $name);
                        }
                        foreach ($right['tails'] as [$otherBase]) {
                            $overlap = $overlap || $base === $otherBase
                                || self::indexContains($base, 0, $otherBase . '1')
                                || self::indexContains($otherBase, 0, $base . '1');
                        }
                    }
                }
                if ($overlap) {
                    throw new LogicException('Overlapping wire names in first-unsuffixed record specs.');
                }
            }
        }
    }

    /**
     * Extend declared-family lint to collection descriptors without changing legacy rules.
     *
     * @param array{name: string, encoding: string, collection?: CollectionSpec, records?: RecordSpec} $param
     * @param array{name: string, encoding: string, collection?: CollectionSpec, records?: RecordSpec} $other
     */
    private static function collectionOverlaps(array $param, array $other): bool
    {
        if (
            !in_array($param['encoding'], ['collection', 'records'], true)
            && !in_array($other['encoding'], ['collection', 'records'], true)
        ) {
            return false;
        }
        foreach (self::indexedFamilies($param) as [$base, $start]) {
            if ($other['encoding'] !== 'records' && self::indexContains($base, $start, $other['name'])) {
                return true;
            }
            foreach (self::indexedFamilies($other) as [$otherBase]) {
                if (
                    $base === $otherBase || self::indexContains($base, 0, $otherBase . '1')
                    || self::indexContains($otherBase, 0, $base . '1')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array{name: string, encoding: string, collection?: CollectionSpec, records?: RecordSpec} $param
     * @return list<array{string, int}>
     */
    private static function indexedFamilies(array $param): array
    {
        if ($param['encoding'] === 'records' && isset($param['records'])) {
            $families = [];
            foreach ($param['records']['fields'] as $field) {
                $families[] = [
                    $field['prefix'] . $param['records']['separator'],
                    ($param['records']['first_unsuffixed'] ?? false) ? 1 : $param['records']['start'],
                ];
            }
            return $families;
        }
        if (
            $param['encoding'] === 'collection'
            && isset($param['collection'])
            && $param['collection']['kind'] === 'indexed'
        ) {
            return [[
                $param['collection']['prefix'] . $param['collection']['separator'], $param['collection']['start'],
            ]];
        }
        if ($param['encoding'] === 'numbered') {
            return [[$param['name'] . '-', 1]];
        }
        if (in_array($param['encoding'], ['suffix-numbered', 'suffix-numbered-scalar'], true)) {
            return [[substr($param['name'], 0, -1), 1]];
        }

        return [];
    }

    private static function indexContains(string $base, int $start, string $name): bool
    {
        if (!str_starts_with($name, $base)) {
            return false;
        }
        $suffix = substr($name, strlen($base));

        // Overflow saturates at PHP_INT_MAX, which is above every allowed start.
        return preg_match('/^(0|[1-9][0-9]*)$/D', $suffix) === 1 && (int) $suffix >= $start;
    }

    /** Check whether a declared family can emit another parameter's name or family prefix. */
    private static function overlaps(string $wire, string $encoding, string $other, string $otherEncoding): bool
    {
        if ($wire === $other) {
            return true;
        }
        $suffix = in_array($encoding, ['suffix-numbered', 'suffix-numbered-scalar'], true);
        $otherSuffix = in_array($otherEncoding, ['suffix-numbered', 'suffix-numbered-scalar'], true);
        $other = $otherSuffix ? substr($other, 0, -1) : $other;
        if ($suffix) {
            $base = substr($wire, 0, -1);

            return preg_match('/^' . preg_quote($base, '/') . '[1-9][0-9]*$/D', $other) === 1;
        }
        if ($encoding === 'numbered') {
            return preg_match('/^' . preg_quote($wire, '/') . '-[1-9][0-9]*$/D', $other) === 1;
        }

        return false;
    }

    /**
     * Check declared fields and that every required name is a declared field.
     *
     * @param array<mixed> $type
     */
    private static function object(array $type): void
    {
        $fields = $type['fields'] ?? [];
        $required = $type['required'] ?? [];
        if (!is_array($fields) || !is_array($required) || !array_is_list($required)) {
            throw new LogicException('Invalid object type spec.');
        }

        foreach ($fields as $field) {
            self::type($field);
        }
        if (array_key_exists('values', $type)) {
            self::type($type['values']);
        }

        foreach ($required as $name) {
            if (!is_string($name) || !array_key_exists($name, $fields)) {
                throw new LogicException('Required names must be strings naming declared fields.');
            }
        }
    }

    /**
     * Check that a union has at least one branch and that every branch is sound.
     *
     * @param array<mixed> $type
     */
    private static function union(array $type): void
    {
        $options = $type['anyOf'] ?? null;
        if (!is_array($options) || $options === [] || !array_is_list($options)) {
            throw new LogicException('Invalid union type spec.');
        }

        foreach ($options as $option) {
            self::type($option);
        }
    }

    /**
     * Require an optional boolean key to be a boolean when present; null means absent.
     *
     * @param array<mixed> $spec
     */
    private static function flag(array $spec, string $key): void
    {
        if (!is_bool($spec[$key] ?? false)) {
            throw new LogicException('Invalid "' . $key . '" spec.');
        }
    }
}
