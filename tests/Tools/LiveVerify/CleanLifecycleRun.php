<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\CaptureClient;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccountLifecycle;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * One clean lifecycle run on the offline server double, for tests of recipes built on the lifecycle.
 *
 * A recipe's own clean run must report every field this one does with the same value, so a field the lifecycle
 * gains can never be left out of a report unnoticed.
 *
 * @phpstan-import-type ReportFields from DisposableAccountLifecycle
 */
final class CleanLifecycleRun
{
    /**
     * Report fields of one clean run; the opt-in environment is swapped in for the run and restored afterwards.
     *
     * @return ReportFields
     */
    public static function reportFields(): array
    {
        $saved = [];
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
                'WHM_API_LIVE_ACCOUNT' => DisposableAccountServer::USER,
                'WHM_API_LIVE_DOMAIN' => DisposableAccountServer::DOMAIN] as $key => $value
        ) {
            $saved[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            $capture = new CaptureClient(new DisposableAccountServer());
            $factory = new Psr17Factory();
            $root = new Client([
                'host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm', 'username' => 'root',
                'token' => 'WHMTOKEN',
            ], new HttpTransport($capture, $factory, $factory));
            $account = DisposableAccount::fresh(
                DisposableAccountServer::USER,
                DisposableAccountServer::DOMAIN,
                DisposableAccountServer::TOKEN_NAME,
                900,
                false,
            );
            $outcome = (new DisposableAccountLifecycle($account, $capture, $root))->run(
                static function (string $token): void {
                },
            );

            return DisposableAccountLifecycle::reportFields($outcome);
        } finally {
            foreach ($saved as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }
}
