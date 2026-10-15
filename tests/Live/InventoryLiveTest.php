<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Support\LiveCurlClient;
use HostingPal\WhmApiClient\Tests\Support\LiveTestEnvironment;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Opted-in, redacted verification of the 22 reviewed inventory operations. */
#[Group('live')]
final class InventoryLiveTest extends TestCase
{
    /** @param Closure(WhmClient): mixed $operation */
    #[DataProvider('whmOperations')]
    public function testWhmInventory(Closure $operation): void
    {
        $whm = $this->client(false)->whm();
        self::safely(static fn (): mixed => $operation($whm));
        $metadata = $whm->responseMeta();
        self::assertTrue($metadata?->httpStatus === 200, 'Expected HTTP 200.');
        self::assertTrue(($metadata?->whm['result'] ?? null) === 1, 'Expected WHM success.');
        self::assertTrue($metadata->uapi === null, 'WHM inventory must retain only WHM metadata.');
    }

    /** @return iterable<string, array{Closure(WhmClient): mixed}> */
    public static function whmOperations(): iterable
    {
        yield 'feature metadata' => [static fn (WhmClient $whm): array => $whm->hostingPlans()->getFeatureMetadata()];
        yield 'feature names' => [static fn (WhmClient $whm): array => $whm->hostingPlans()->getFeatureNames()];
        yield 'feature list data' => [
            static fn (WhmClient $whm): array => $whm->hostingPlans()->getFeaturelistData('default'),
        ];
        yield 'feature lists' => [static fn (WhmClient $whm): array => $whm->hostingPlans()->getFeaturelists()];
        yield 'feature package types' => [
            static fn (WhmClient $whm): array => $whm->hostingPlans()->getFeaturelistsByPackageTypes(),
        ];
        yield 'user feature settings' => [
            static fn (WhmClient $whm): array => $whm->hostingPlans()->getUsersFeaturesSettings('npr', 'autossl'),
        ];
        yield 'user feature access' => [
            static fn (WhmClient $whm): int => $whm->hostingPlans()->verifyUserHasFeature('npr', 'autossl'),
        ];
        yield 'PHP versions' => [static fn (WhmClient $whm): array => $whm->webServer()->phpGetInstalledVersions()];
        yield 'default PHP version' => [
            static fn (WhmClient $whm): string => $whm->webServer()->phpGetSystemDefaultVersion(),
        ];
        yield 'PHP handlers' => [static fn (WhmClient $whm): array => $whm->webServer()->phpGetHandlers()];
        yield 'default FPM' => [static fn (WhmClient $whm): int => $whm->webServer()->phpGetDefaultAccountsToFpm()];
        yield 'old FPM flag' => [static fn (WhmClient $whm): int => $whm->webServer()->phpGetOldFpmFlag()];
        yield 'FPM conversion' => [static fn (WhmClient $whm): int => $whm->webServer()->isConversionInProgress()];
        yield 'PHP vhosts' => [
            static fn (WhmClient $whm): array => $whm->webServer()->phpGetVhostsByVersion('ea-php82'),
        ];
    }

    /** @param Closure(UapiClient): mixed $operation */
    #[DataProvider('uapiOperations')]
    public function testUapiInventory(bool $direct, Closure $operation): void
    {
        $client = $this->client($direct);
        $account = $client->uapi('npr');
        self::safely(static fn (): mixed => $operation($account));
        $metadata = $account->responseMeta();
        self::assertTrue($metadata?->httpStatus === 200, 'Expected HTTP 200.');
        self::assertTrue(($metadata?->uapi['status'] ?? null) === 1, 'Expected UAPI success.');
        self::assertTrue($account->isBridged() === !$direct, 'Expected configured account route.');
        self::assertTrue($account->username() === 'npr', 'Expected fixed test account.');
        if ($direct) {
            self::assertTrue($metadata->whm === null, 'Direct UAPI must not retain WHM metadata.');
        } else {
            self::assertTrue(($metadata?->whm['result'] ?? null) === 1, 'Expected outer WHM success.');
            self::assertNull($client->whm()->responseMeta());
        }
    }

    /** @return iterable<string, array{bool, Closure(UapiClient): mixed}> */
    public static function uapiOperations(): iterable
    {
        $operations = [
            'primary domain' => static fn (UapiClient $api): ?string => $api->domainInfo()->primaryDomain(),
            'builtin aliases' => static fn (UapiClient $api): array
                => $api->domainInfo()->mainDomainBuiltinSubdomainAliases(),
            'default PHP' => static fn (UapiClient $api): ?string => $api->langPhp()->phpGetSystemDefaultVersion(),
            'PHP handler' => static fn (UapiClient $api): string => $api->langPhp()->phpGetDomainHandler('home'),
            'impacted domains' => static fn (UapiClient $api): array
                => $api->langPhp()->phpGetImpactedDomains('npr.rs'),
            'features' => static fn (UapiClient $api): array => $api->features()->listFeatures(),
            'DNS lookup' => static fn (UapiClient $api): ?array => $api->dns()->lookup('npr.rs'),
            'generated domains' => static fn (UapiClient $api): array
                => $api->dns()->fetchCpanelGeneratedDomains('npr.rs'),
        ];
        foreach ([true, false] as $direct) {
            foreach ($operations as $name => $operation) {
                yield ($direct ? 'direct ' : 'bridge ') . $name => [$direct, $operation];
            }
        }
    }

    private function client(bool $direct): Client
    {
        $tokens = LiveTestEnvironment::fromEnvironment();
        $factory = new Psr17Factory();

        return new Client([
            'host' => 'test.oblak.host',
            'port' => $direct ? 2083 : 2087,
            'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? 'npr' : 'root',
            'token' => $direct ? $tokens['cpanel'] : $tokens['whm'],
        ], new HttpTransport(new LiveCurlClient(), $factory, $factory));
    }

    /** Do not expose upstream exception messages, payloads or credentials. @param Closure(): mixed $operation */
    private static function safely(Closure $operation): void
    {
        try {
            $operation();
        } catch (Throwable $error) {
            $metadata = $error instanceof ApiException ? $error->metadata : null;
            $whm = $metadata?->whm['result'] ?? null;
            $uapi = $metadata?->uapi['status'] ?? null;
            self::fail(sprintf(
                'Live inventory failed (%s; HTTP %s; WHM %s; UAPI %s).',
                $error::class,
                $metadata === null ? 'unavailable' : (string) $metadata->httpStatus,
                is_int($whm) ? (string) $whm : 'unavailable',
                is_int($uapi) ? (string) $uapi : 'unavailable',
            ));
        }
    }
}
