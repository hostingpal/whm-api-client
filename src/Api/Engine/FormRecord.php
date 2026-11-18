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
    /**
     * @param array<string, Fields> $variants
     * @param list<string> $changeParams
     */
    private function __construct(
        private string $php,
        private string $discriminator,
        private bool $required,
        private bool $untagged,
        private array $variants,
        private array $changeParams,
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
        $keys = ['php', 'discriminator', 'required', 'variants', 'untagged', 'change_params'];
        if (
            ($spec['method'] ?? null) !== 'POST' || isset($spec['module'])
            || OperationSpec::body($spec) !== RequestBodyFormat::Form || !is_array($params)
            || !is_array($form) || !RecordFields::identifier($form['php'] ?? null)
            || !RecordFields::identifier($form['discriminator'] ?? null) || array_key_exists($form['php'], $params)
            || !is_bool($form['required'] ?? null) || !is_array($form['variants'] ?? null) || $form['variants'] === []
            || (array_key_exists('untagged', $form) && $form['untagged'] !== true)
            || array_diff(array_keys($form), $keys) !== []
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
        $changes = [];
        if (array_key_exists('change_params', $form)) {
            if (
                !$untagged || $form['required'] || !is_array($form['change_params'])
                || !array_is_list($form['change_params']) || $form['change_params'] === []
            ) {
                throw new LogicException('Invalid form record change parameters.');
            }
            foreach ($form['change_params'] as $name) {
                if (!is_string($name) || !isset($params[$name]) || in_array($name, $changes, true)) {
                    throw new LogicException('Invalid form record change parameter.');
                }
                $changes[] = $name;
            }
        }
        $variants = [];
        foreach ($form['variants'] as $tag => $fields) {
            if (!is_string($tag) || $tag === '' || !is_array($fields)) {
                throw new LogicException('Invalid form record variant.');
            }
            $checked = [];
            foreach ($fields as $name => $field) {
                $descriptor = RecordFields::descriptor($field);
                if (
                    !RecordFields::identifier($name) || isset($reserved[$name])
                    || !in_array($descriptor['type'], ['string', 'int'], true) || ($untagged && $descriptor['required'])
                ) {
                    throw new LogicException('Invalid form record field descriptor.');
                }
                $checked[$name] = $descriptor;
            }
            $variants[$tag] = $checked;
        }

        return new self($form['php'], $form['discriminator'], $form['required'], $untagged, $variants, $changes);
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
        if (($record === null || $record === []) && $this->changeParams !== []) {
            foreach ($this->changeParams as $name) {
                if (isset($arguments[$name])) {
                    return $encoded;
                }
            }
            throw new InvalidArgumentException('Supply a record change or a mutable companion parameter.');
        }
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
            $values = RecordFields::values($fields, $record);
            if ($values !== null) {
                foreach ($values as $name => $value) {
                    $encoded->append($this->php, $name, $value, multipart: false);
                }
                return $encoded;
            }
        }
        throw new InvalidArgumentException('Form record fields do not match a declared variant.');
    }
}
