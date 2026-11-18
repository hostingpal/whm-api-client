<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DnsZoneFixture;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class DnsZoneFixtureTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string, int}> */
    public static function cases(): iterable
    {
        yield 'preexisting domain' => ['foreign_domain', 'alias_preflight', 'passed',
            'not_required', 'not_required', 0];
        yield 'preexisting zone' => ['foreign_zone', 'alias_preflight', 'passed', 'not_required', 'not_required', 0];
        yield 'foreign owner after park' => ['wrong_owner', 'confirm_zones', 'passed', 'confirmed_absent',
            'confirmed_absent', 1];
        yield 'shared zone file with residual alias zone' => ['residual', 'confirm_zones', 'failed', 'confirmed_absent',
            'present', 1];
        yield 'ownership changed' => ['changed_owner', 'limits', 'passed', 'not_required', 'not_required', 0];
        yield 'lost park response' => ['lost', 'park_alias', 'failed', 'unconfirmed', 'unconfirmed', 1];
    }

    #[DataProvider('cases')]
    public function testAliasSetupAndCleanupFailures(
        string $case,
        string $stopped,
        string $cleanup,
        string $domainCleanup,
        string $zoneCleanup,
        int $parkCount,
    ): void {
        $saved = [];
        foreach (
            [
            'WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => DnsZoneFixture::ACCOUNT, 'WHM_API_LIVE_DOMAIN' => DnsZoneFixture::DOMAIN,
            ] as $name => $value
        ) {
            $saved[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        try {
            $lifecycle = new DisposableAccountServer(
                DnsZoneFixture::ACCOUNT,
                DnsZoneFixture::DOMAIN,
                DnsZoneFixture::TOKEN_NAME,
            );
            if ($case === 'changed_owner') {
                $lifecycle->overrides['listaccts:4'] = json_encode(['acct' => [[
                    'user' => DnsZoneFixture::ACCOUNT, 'domain' => DnsZoneFixture::DOMAIN, 'email' => 'foreign@example',
                ]]], JSON_THROW_ON_ERROR);
            }
            $server = new class ($lifecycle, $case) implements ClientInterface {
                public int $parked = 0;

                public function __construct(private readonly DisposableAccountServer $server, private string $case)
                {
                }

                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    if ($request->getUri()->getPath() === '/execute/Park/park') {
                        ++$this->parked;

                        return $this->case === 'lost'
                            ? new Response(502, [], 'private gateway error')
                            : new Response(200, [], '{"status":1,"data":null,"errors":null}');
                    }
                    $function = basename($request->getUri()->getPath());
                    $next = ($this->server->counts[$function] ?? 0) + 1;
                    $data = match ($function) {
                        'version' => ['version' => '11.138.0.24'],
                        'get_domain_info' => ['domains' => $this->domains()],
                        'listzones' => ['zone' => $this->zones()],
                        default => null,
                    };
                    if ($data !== null) {
                        $this->server->overrides[$function . ':' . $next] = json_encode($data, JSON_THROW_ON_ERROR);
                    }

                    return $this->server->sendRequest($request);
                }

                /** @return list<array{domain: string, user: string}> */
                private function domains(): array
                {
                    $rows = [['domain' => 'other.example', 'user' => 'other']];
                    if ($this->case === 'foreign_domain' || ($this->parked === 1 && $this->server->account)) {
                        $rows[] = ['domain' => DnsZoneFixture::ALIAS,
                            'user' => $this->case === 'wrong_owner' ? 'other' : DnsZoneFixture::ACCOUNT];
                    }

                    return $rows;
                }

                /** @return list<array{domain: string, zonefile: string}> */
                private function zones(): array
                {
                    $rows = [['domain' => 'other.example', 'zonefile' => 'other.db']];
                    if ($this->server->account) {
                        $rows[] = ['domain' => DnsZoneFixture::DOMAIN, 'zonefile' => 'primary.db'];
                    }
                    if (
                        $this->case === 'foreign_zone'
                        || ($this->parked === 1 && ($this->server->account || $this->case === 'residual'))
                    ) {
                        $rows[] = ['domain' => DnsZoneFixture::ALIAS, 'zonefile' => 'primary.db'];
                    }

                    return $rows;
                }
            };
            $scenario = (new DnsZoneFixture($server, 'WHMTOKEN', DomainRowsEvidenceTest::context()))->run();
            $fixture = $scenario['fixture'];
            self::assertSame('failed', $fixture['status']);
            self::assertSame($stopped, $fixture['stopped_at']);
            self::assertSame('failed', $fixture['alias_setup']);
            self::assertSame($cleanup, $fixture['cleanup']);
            self::assertSame($domainCleanup, $fixture['resources']['alias_domain']['cleanup']);
            self::assertSame($zoneCleanup, $fixture['resources']['alias_zone']['cleanup']);
            self::assertSame('confirmed_absent', $fixture['resources']['account']['cleanup']);
            self::assertSame('confirmed_absent', $fixture['resources']['token']['cleanup']);
            self::assertSame([], $scenario['probes']);
            self::assertSame($parkCount, $server->parked);
            self::assertSame($parkCount, $lifecycle->counts['modifyacct'] ?? 0);
            self::assertFalse($lifecycle->account);
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}
