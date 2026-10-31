<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\HostingPlansSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/**
 * Fixed reads requested by the hosting-plans review on cPanel 11.138.0.19.
 *
 * The reviewer identified the stock default package; the pinned getpkginfo
 * contract establishes the pkg wire parameter, not that package's existence.
 * No fallback selector or custom application file is permitted. A missing
 * package fails the capture rather than creating or selecting another package.
 *
 * @see https://github.com/hostingpal/whm-api-client/pull/56#pullrequestreview-5720810852
 */
final class WhmHostingPlansReviewProbes
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
                'whm1:getpkginfo',
                'whm',
                HostingPlansSpec::GETPKGINFO,
                'https://test.oblak.host:2087/json-api/getpkginfo?api.version=1&pkg=default',
                static fn (Client $client): mixed => $client->whm()->hostingPlans()->getPackageInfo('default'),
                argumentVariant: 'stock-default-package',
            ),
            new Probe(
                'whm1:get_available_applications',
                'whm',
                HostingPlansSpec::GET_AVAILABLE_APPLICATIONS,
                'https://test.oblak.host:2087/json-api/get_available_applications?api.version=1',
                static fn (Client $client): mixed => $client->whm()->hostingPlans()->getAvailableApplications(),
                argumentVariant: 'default-applications-file',
            ),
        ];
    }
}
