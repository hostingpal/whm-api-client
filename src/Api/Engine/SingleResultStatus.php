<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestBodyFormat;
use HostingPal\WhmApiClient\Exception\ZoneRebuildException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;
use LogicException;
use SensitiveParameter;
use stdClass;

/** Interpret the ZoneEdit rebuild reply independently of dispatch and ancillary MX data. @internal */
final class SingleResultStatus
{
    /** @param array<mixed> $spec */
    public static function check(array $spec): void
    {
        if (!array_key_exists('single_result_status', $spec)) {
            return;
        }
        $list = $spec['returns'] ?? null;
        $row = is_array($list) ? ($list['items'] ?? null) : null;
        $result = self::requiredField($row, 'result');
        $conflicts = ['optional', 'unwrap', 'rows', 'empty_without_data', 'account_search_empty'];
        if (
            $spec['single_result_status'] !== true
            || ($spec['module'] ?? null) !== 'ZoneEdit' || ($spec['function'] ?? null) !== 'resetzone'
            || ($spec['method'] ?? null) !== 'POST' || OperationSpec::body($spec) !== RequestBodyFormat::Form
            || OperationSpec::outcome($spec) !== OutcomePolicy::Flag
            || array_intersect($conflicts, array_keys($spec)) !== []
            || !is_array($list) || ($list['type'] ?? null) !== 'list' || ($list['nullable'] ?? false)
            || self::requiredField($result, 'status') !== ['type' => 'flag']
        ) {
            throw new LogicException('Invalid single_result_status result policy.');
        }
    }

    /** Select a required field of a non-nullable object from the checked return grammar. */
    private static function requiredField(mixed $object, string $name): mixed
    {
        if (
            !is_array($object) || ($object['type'] ?? null) !== 'object' || ($object['nullable'] ?? false)
            || !is_array($object['fields'] ?? null) || !is_array($object['required'] ?? null)
            || !in_array($name, $object['required'], true)
        ) {
            return null;
        }

        return $object['fields'][$name] ?? null;
    }

    /** Require one unambiguous raw outcome before validating optional message or MX fields. */
    public static function confirm(#[SensitiveParameter] ApiResponse $response, PayloadReader $read): void
    {
        $rows = $response->data;
        if (
            !$response->hasData || !is_array($rows) || array_keys($rows) !== [0]
            || !$rows[0] instanceof stdClass || !($rows[0]->result ?? null) instanceof stdClass
        ) {
            $read->fail();
        }
        $status = $rows[0]->result->status ?? null;
        if ($status === 0) {
            $reason = $rows[0]->result->statusmsg ?? null;
            throw new ZoneRebuildException($response->metadata, is_string($reason) ? $reason : null);
        }
        if ($status !== 1) {
            $read->fail();
        }
    }
}
