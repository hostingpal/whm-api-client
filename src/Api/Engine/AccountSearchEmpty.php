<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Engine;

use HostingPal\WhmApiClient\Api\OutcomePolicy;
use HostingPal\WhmApiClient\Api\RequestBodyFormat;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use LogicException;
use SensitiveParameter;

/**
 * Evidence-bound absence forms for the generated WHM account search only.
 *
 * The handle temporarily defers Flag interpretation until the complete response
 * is available. This policy restores strict outcomes before payload handling;
 * generic calls continue to use the ordinary envelope Flag policy.
 *
 * @internal
 */
final class AccountSearchEmpty
{
    /** @param array<mixed> $spec */
    public static function check(array $spec): void
    {
        if (!array_key_exists('account_search_empty', $spec)) {
            return;
        }
        $returns = $spec['returns'] ?? null;
        $fields = is_array($returns) ? ($returns['fields'] ?? null) : null;
        $list = is_array($fields) ? ($fields['acct'] ?? null) : null;
        $items = is_array($list) ? ($list['items'] ?? null) : null;
        if (
            $spec['account_search_empty'] !== true
            || array_key_exists('module', $spec)
            || ($spec['function'] ?? null) !== 'listaccts'
            || ($spec['method'] ?? null) !== 'GET'
            || OperationSpec::body($spec) !== RequestBodyFormat::Form
            || OperationSpec::outcome($spec) !== OutcomePolicy::Flag
            || ($spec['unwrap'] ?? null) !== 'acct'
            || array_key_exists('optional', $spec)
            || array_key_exists('rows', $spec)
            || array_key_exists('empty_without_data', $spec)
            || !is_array($returns) || ($returns['type'] ?? null) !== 'object'
            || ($returns['nullable'] ?? false) === true
            || !is_array($list) || ($list['type'] ?? null) !== 'list'
            || ($list['nullable'] ?? false) === true
            || !is_array($items) || ($items['type'] ?? null) !== 'object'
            || ($items['nullable'] ?? false) === true
        ) {
            throw new LogicException('Invalid account_search_empty result policy.');
        }
    }

    /**
     * Interpret strict WHM outcomes and identify only the evidenced absence forms.
     *
     * Result 0 requires the exact diagnostic and an omitted data member. A present
     * payload always remains an API failure. Result 1 permits omission or explicit
     * null; every nonnull payload still passes through the original shape validator.
     */
    public static function isEmpty(#[SensitiveParameter] ApiResponse $response): bool
    {
        $result = $response->metadata->whmResult();
        if ($result === 0) {
            if (!$response->hasData && ($response->metadata->whm['reason'] ?? null) === 'No accounts found.') {
                return true;
            }
            throw new ApiErrorException(
                'WHM operation failed; inspect errors() or response metadata.',
                $response->metadata,
            );
        }
        if ($result !== 1) {
            throw new InvalidResponseException('Missing or invalid WHM outcome.', $response->metadata);
        }
        return !$response->hasData || $response->data === null;
    }
}
