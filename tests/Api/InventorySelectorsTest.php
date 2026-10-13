<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class InventorySelectorsTest extends TestCase
{
    public function testInventoryGroupsRemainStableAndAccountBoundWithoutIo(): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $npr = $client->uapi('npr');
        $other = $client->uapi('other');
        self::assertSame($whm->php(), $whm->php());
        self::assertSame($whm->features(), $whm->features());
        self::assertSame($npr->dns(), $npr->dns());
        self::assertSame($npr->features(), $npr->features());
        self::assertNotSame($npr->dns(), $other->dns());
        self::assertNotSame($npr->features(), $other->features());
        self::assertSame([], $http->getRequests());
        self::assertNull($whm->responseMeta());
        self::assertNull($npr->responseMeta());
        self::assertNull($other->responseMeta());
    }

    public function testSelectingNewAndCachedGroupsPreservesExistingHandleMetadata(): void
    {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $npr = $client->uapi('npr');
        $other = $client->uapi('other');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.15"}}'));
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},'
            . '"data":{"uapi":{"status":1,"data":{"main_domain":"npr.test"}}}}'));
        $http->addResponse(new Response(200, [], '{"metadata":{"result":1},'
            . '"data":{"uapi":{"status":1,"data":{"versions":["ea-php84"]}}}}'));
        $whm->server()->version();
        $npr->domains()->list();
        $other->php()->listVersions();
        $whmMetadata = $whm->responseMeta();
        $nprMetadata = $npr->responseMeta();
        $otherMetadata = $other->responseMeta();
        self::assertNotNull($whmMetadata);
        self::assertNotNull($nprMetadata);
        self::assertNotNull($otherMetadata);

        $whmGroups = [$whm->server(), $whm->domains(), $whm->features(), $whm->php()];
        self::assertSame($whmGroups, [$whm->server(), $whm->domains(), $whm->features(), $whm->php()]);
        foreach ([$npr, $other] as $uapi) {
            $groups = [$uapi->domains(), $uapi->php(), $uapi->dns(), $uapi->features()];
            self::assertSame($groups, [$uapi->domains(), $uapi->php(), $uapi->dns(), $uapi->features()]);
        }

        self::assertSame($whmMetadata, $whm->responseMeta());
        self::assertSame($nprMetadata, $npr->responseMeta());
        self::assertSame($otherMetadata, $other->responseMeta());
        self::assertCount(3, $http->getRequests());
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);

        return [new Client([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'username' => 'root',
            'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
