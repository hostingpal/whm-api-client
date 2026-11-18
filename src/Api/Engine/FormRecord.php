<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\RequestBodyFormat;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Expand one discriminated record into literal WHM POST fields.
 *
 * Each variant describes the complete set of its permitted fields. The discriminator
 * selects exactly one variant. When an edit omits it, all supplied keys and native
 * values must fit a single variant. Only caller-supplied fields reach the wire.
 *
 * @phpstan-type Fields array<string, array{type: 'string'|'int', required: bool}>
 * @internal
 */
final readonly class FormRecord
{
    /** @param array<string, Fields> $variants */
    private function __construct(
        private string $php,
        private string $discriminator,
        private bool $required,
        private bool $untagged,
        private array $variants,
    ) {
    }

    /**
     * Check every variant, including collisions with omitted scalar parameters.
     *
     * @param array<mixed> $spec
     */
    public static function from(array $spec): self
    {
        $form = $spec['form_record'] ?? null;
        $params = $spec['params'] ?? null;
        if (
            ($spec['method'] ?? null) !== 'POST' || isset($spec['module'])
            || OperationSpec::body($spec) !== RequestBodyFormat::Form || !is_array($params)
            || !is_array($form) || !self::identifier($form['php'] ?? null)
            || !self::identifier($form['discriminator'] ?? null) || array_key_exists($form['php'], $params)
            || !is_bool($form['required'] ?? null) || !is_array($form['variants'] ?? null) || $form['variants'] === []
            || (array_key_exists('untagged', $form) && $form['untagged'] !== true)
            || array_diff(array_keys($form), ['php', 'discriminator', 'required', 'variants', 'untagged']) !== []
        ) {
            throw new LogicException('Invalid form record descriptor.');
        }

        $reserved = [$form['discriminator'] => true];
        foreach ($params as $param) {
            if (
                !is_array($param) || ($param['encoding'] ?? null) !== 'scalar'
                || !is_string($param['name'] ?? null) || isset($reserved[$param['name']])
            ) {
                throw new LogicException('Form records require distinct scalar companion fields.');
            }
            $reserved[$param['name']] = true;
        }
        $untagged = $form['untagged'] ?? false;
        $variants = [];
        foreach ($form['variants'] as $tag => $fields) {
            if (!is_string($tag) || $tag === '' || !is_array($fields)) {
                throw new LogicException('Invalid form record variant.');
            }
            $checked = [];
            foreach ($fields as $name => $field) {
                if (
                    !self::identifier($name) || isset($reserved[$name]) || !is_array($field)
                    || !in_array($field['type'] ?? null, ['string', 'int'], true)
                    || !is_bool($field['required'] ?? null) || ($untagged && $field['required'])
                    || array_diff(array_keys($field), ['type', 'required']) !== []
                ) {
                    throw new LogicException('Invalid form record field descriptor.');
                }
                $checked[$name] = ['type' => $field['type'], 'required' => $field['required']];
            }
            $variants[$tag] = $checked;
        }

        return new self($form['php'], $form['discriminator'], $form['required'], $untagged, $variants);
    }

    /**
     * Encode the scalar companions first, then the discriminator and its fields in declaration order.
     *
     * @param array<mixed> $params
     * @param array<string, mixed> $arguments
     * @throws InvalidArgumentException If a required record is absent or no variant accepts the supplied record.
     */
    public function encode(array $params, #[SensitiveParameter] array $arguments): OrderedFields
    {
        $record = $arguments[$this->php] ?? null;
        unset($arguments[$this->php]);
        $encoded = ArgumentEncoder::encode($params, $arguments);
        if (!$this->required && $record === null) {
            return $encoded;
        }
        if (!is_array($record)) {
            throw new InvalidArgumentException('Form record requires a named array.');
        }

        $variants = $this->variants;
        if (array_key_exists($this->discriminator, $record)) {
            $tag = $record[$this->discriminator];
            if (!is_string($tag) || !array_key_exists($tag, $variants)) {
                throw new InvalidArgumentException('Unknown form record discriminator.');
            }
            $variants = [$tag => $variants[$tag]];
            unset($record[$this->discriminator]);
            $encoded->append($this->php, $this->discriminator, $tag, multipart: false);
        } elseif (!$this->untagged) {
            throw new InvalidArgumentException('Form record requires a discriminator.');
        }
        foreach ($variants as $fields) {
            $values = self::values($fields, $record);
            if ($values !== null) {
                foreach ($values as $name => $value) {
                    $encoded->append($this->php, $name, $value, multipart: false);
                }
                return $encoded;
            }
        }
        throw new InvalidArgumentException('Form record fields do not match a declared variant.');
    }

    /**
     * @param Fields $fields
     * @param array<mixed> $record
     * @return array<string, int|string>|null Null when this variant does not accept the complete input.
     */
    private static function values(array $fields, #[SensitiveParameter] array $record): ?array
    {
        if (array_diff_key($record, $fields) !== []) {
            return null;
        }
        $values = [];
        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $record) && !$field['required']) {
                continue;
            }
            $value = $record[$name] ?? null;
            if (($field['type'] === 'string' && is_string($value)) || ($field['type'] === 'int' && is_int($value))) {
                $values[$name] = $value;
            } else {
                return null;
            }
        }
        return $values;
    }

    /** @phpstan-assert-if-true string $name */
    private static function identifier(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1;
    }
}
