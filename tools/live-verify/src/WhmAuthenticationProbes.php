<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Whm\Spec\AuthenticationSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;

/** Fixed issuer/policy metadata only; no credentials, private URLs or authentication changes. */
final class WhmAuthenticationProbes
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
                'whm1:twofactorauth_get_issuer',
                'whm',
                AuthenticationSpec::TWOFACTORAUTH_GET_ISSUER,
                'https://test.oblak.host:2087/json-api/twofactorauth_get_issuer?api.version=1',
                static fn (Client $client): mixed => $client->whm()->authentication()->twoFactorAuthGetIssuer(),
            ),
            new Probe(
                'whm1:twofactorauth_policy_status',
                'whm',
                AuthenticationSpec::TWOFACTORAUTH_POLICY_STATUS,
                'https://test.oblak.host:2087/json-api/twofactorauth_policy_status?api.version=1',
                static fn (Client $client): mixed => $client->whm()->authentication()->twoFactorAuthPolicyStatus(),
            ),
        ];
    }
}
