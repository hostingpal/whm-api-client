<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\TransfersSpec;
use HostingPal\WhmApiClient\Client;

/**
 * Fixed review-requested module schema read, separate from the original transfer capture.
 *
 * FeatureListRemoteRoot is a WHM 11.138.0.19 reviewer-observed module name;
 * the pinned schema operation lists FeaturesListRemoteRoot instead. Returned
 * schema strings stay inert data and are persisted only as sanitized structure.
 *
 * @see https://github.com/hostingpal/whm-api-client/pull/59#pullrequestreview-5720977719
 */
final class WhmTransfersReviewProbes
{
    /** @return list<Probe> */
    public static function load(): array
    {
        return [
            new Probe(
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->version(),
            ),
            new Probe(
                'whm1:transfer_module_schema',
                'whm',
                TransfersSpec::TRANSFER_MODULE_SCHEMA,
                'https://test.oblak.host:2087/json-api/transfer_module_schema?api.version=1'
                . '&module=FeatureListRemoteRoot',
                static fn (Client $client): mixed => $client->whm()->transfers()->transferModuleSchema(
                    module: 'FeatureListRemoteRoot',
                ),
                argumentVariant: 'feature-list-remote-root',
            ),
        ];
    }
}
