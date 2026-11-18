<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\DnsZoneGuard;
use Http\Mock\Client;
use LogicException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class DnsZoneGuardTest extends TestCase
{
    private const WHM = 'https://test.oblak.host:2087/json-api/';
    private const DIRECT = 'https://test.oblak.host:2083/execute/';
    private const ZONE = 'dns-recipe.example.test';
    private const SETTINGS = ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
        'WHM_API_LIVE_ACCOUNT' => 'wldnsrecipe', 'WHM_API_LIVE_DOMAIN' => self::ZONE];

    /** @var array<string, string|false> */
    private array $old = [];
    private Client $http;
    private DnsZoneGuard $guard;
    private DisposableAccount $account;

    protected function setUp(): void
    {
        foreach (self::SETTINGS as $key => $value) {
            $this->old[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        $this->account = DisposableAccount::fresh('wldnsrecipe', self::ZONE, 'dns-zone-recipe', 900, false);
        $this->http = new Client();
        $this->guard = new DnsZoneGuard($this->http, 'whm-secret', $this->account);
    }

    protected function tearDown(): void
    {
        foreach ($this->old as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testReadsAndCleanupRemainAvailableWithAnUnusedPhase(): void
    {
        $this->guard->arm('limits');
        $this->guard->sendRequest(self::request('GET', self::WHM . 'version?api.version=1'));
        $remove = self::request('POST', self::WHM . 'removeacct?api.version=1', 'username=wldnsrecipe&keepdns=0');
        $this->guard->sendRequest($remove);
        $this->refuse($remove, 2);
    }

    public function testAllExactOrderedDnsRequestsAndEachSetupMutationPassOnce(): void
    {
        $this->setupZone();
        $count = 2;
        foreach (['direct', 'bridged'] as $route) {
            $prefix = $route . '-mass-';
            $this->guard->arm($prefix . 'before');
            $this->guard->sendRequest(self::parse($route));
            foreach (['add' => 'added', 'edit' => 'edited', 'remove' => 'removed'] as $verb => $after) {
                $serial = 2026111800 + $count;
                $idx = $verb === 'add' ? null : 11;
                $this->guard->arm(
                    $prefix . $verb,
                    $serial,
                    $verb === 'edit' ? $idx : null,
                    $verb === 'remove' ? $idx : null,
                );
                $this->guard->sendRequest(self::mass($route, $verb, $serial));
                $this->guard->arm($prefix . $after);
                $this->guard->sendRequest(self::parse($route));
                $count += 2;
            }
            ++$count;
        }
        foreach (['main' => self::ZONE, 'alias' => 'dns-alias.example.test'] as $kind => $zone) {
            $this->guard->arm('direct-seed-' . $kind . '-before');
            $this->guard->sendRequest(self::parse('direct', $zone));
            $this->guard->arm('direct-seed-' . $kind, 45, 3);
            $record = static fn (string $name, string $ip): array =>
                ['dname' => $name . '.', 'ttl' => 300, 'record_type' => 'A', 'data' => [$ip]];
            $body = 'serial=45&zone=' . $zone . '&add='
                . rawurlencode(json_encode($record('source-one.' . $zone, '192.0.2.61'), JSON_THROW_ON_ERROR))
                . '&add=' . rawurlencode(json_encode($record('source-two.' . $zone, '192.0.2.62'), JSON_THROW_ON_ERROR))
                . '&edit=' . rawurlencode(json_encode(
                    ['line_index' => 3] + $record($zone, $kind === 'alias' ? '192.0.2.62' : '192.0.2.61'),
                    JSON_THROW_ON_ERROR,
                ));
            $this->guard->sendRequest(self::request('POST', self::dns('direct', 'mass_edit_zone'), $body));
            $this->guard->arm('direct-seed-' . $kind . '-after');
            $this->guard->sendRequest(self::parse('direct', $zone));
            $count += 3;
        }
        foreach (['bridged', 'direct'] as $route) {
            foreach (['before', 'after'] as $when) {
                if ($when === 'after') {
                    $this->guard->arm($route . '-swap');
                    $this->guard->sendRequest(self::request(
                        'POST',
                        self::dns($route, 'swap_ip_in_zones'),
                        'domain=' . self::ZONE . '&domain=dns-alias.example.test&dest_ip=192.0.2.'
                        . ($route === 'direct' ? '55' : '54'),
                    ));
                    ++$count;
                }
                foreach (['main', 'alias'] as $kind) {
                    $this->guard->arm($route . '-swap-' . $kind . '-' . $when);
                    $zone = $kind === 'main' ? self::ZONE : 'dns-alias.example.test';
                    $this->guard->sendRequest(self::parse($route, $zone));
                    ++$count;
                }
            }
        }
        self::assertCount($count, $this->http->getRequests());
        $this->refuse(self::mass('direct', 'add', 2026111802), $count);
    }

    /** @return iterable<string, array{string}> */
    public static function deviations(): iterable
    {
        $changes = ['serial', 'record', 'zone', 'extra', 'method', 'route', 'host', 'target', 'token', 'cookie',
            'type',
        ];
        foreach ($changes as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('deviations')]
    public function testArmedWriteRefusesChangedRequestsAndSpendsTheAttempt(string $change): void
    {
        $this->setupZone();
        $this->guard->arm('direct-mass-before');
        $this->guard->sendRequest(self::parse('direct'));
        $this->guard->arm('direct-mass-add', 42);
        $valid = self::mass('direct', 'add', 42);
        $body = (string) $valid->getBody();
        $uri = self::dns('direct', 'mass_edit_zone');
        $wrong = match ($change) {
            'serial' => self::mass('direct', 'add', 41),
            'record' => self::request('POST', $uri, str_replace('192.0.2.51', '192.0.2.53', $body)),
            'zone' => self::request('POST', $uri, str_replace(self::ZONE, 'other.test', $body)),
            'extra' => self::request('POST', $uri, $body . '&remove=0'),
            'method' => $valid->withMethod('GET'),
            'route' => self::mass('bridged', 'add', 42),
            'host' => $valid->withHeader('Host', 'other.test:2083'),
            'target' => $valid->withRequestTarget('/execute/DNS/other'),
            'token' => $valid->withHeader('Authorization', 'whm root:whm-secret'),
            'cookie' => $valid->withHeader('Cookie', 'secret-cookie'),
            default => $valid->withHeader('Content-Type', 'application/json'),
        };
        $this->refuse($wrong, 3);
        $this->refuse($valid, 3);
    }

    #[DataProvider('environmentKeys')]
    public function testEveryControlReadRequiresAllOptIns(string $key): void
    {
        putenv($key . '=other');
        $this->refuse(self::request('GET', self::WHM . 'listzones?api.version=1'), 0);
    }

    /** @return iterable<string, array{string}> */
    public static function environmentKeys(): iterable
    {
        foreach (array_keys(self::SETTINGS) as $key) {
            yield $key => [$key];
        }
    }

    public function testCannotArmAnUnrelatedOrOutOfOrderPhase(): void
    {
        $this->expectException(LogicException::class);
        $this->guard->arm('bridged-mass-remove', 42, removeIndex: 7);
    }

    public function testCannotRearmAnUnconsumedPhase(): void
    {
        $this->guard->arm('limits');
        $this->expectException(LogicException::class);
        $this->guard->arm('limits');
    }

    public function testNoExtraParametersCanBeBoundToSetup(): void
    {
        $this->expectException(LogicException::class);
        $this->guard->arm('limits', 42, 1, 0);
    }

    public function testForeignAccountBindingAndReadOnlyTokensAreRefused(): void
    {
        foreach (
            [
            ['wldnsrecipe', self::ZONE, 'other', false], ['npr', self::ZONE, 'dns-zone-recipe', false],
            ['wldnsrecipe', 'other.test', 'dns-zone-recipe', false],
            ['wldnsrecipe', self::ZONE, 'dns-zone-recipe', true],
            ] as [$user, $zone, $token, $readOnly]
        ) {
            try {
                $account = DisposableAccount::fresh($user, $zone, $token, 900, $readOnly);
                new DnsZoneGuard($this->http, 'whm-secret', $account);
                self::fail('Foreign binding accepted.');
            } catch (LogicException $exception) {
                self::assertStringNotContainsString('whm-secret', $exception->getMessage());
            }
        }
    }

    public function testIncorrectIndexCannotReplaceTheOneArmedForTheEdit(): void
    {
        $this->setupZone();
        $this->guard->arm('direct-mass-before');
        $this->guard->sendRequest(self::parse('direct'));
        $this->guard->arm('direct-mass-add', 42);
        $this->guard->sendRequest(self::mass('direct', 'add', 42));
        $this->guard->arm('direct-mass-added');
        $this->guard->sendRequest(self::parse('direct'));
        $this->guard->arm('direct-mass-edit', 43, 12);
        $this->refuse(self::mass('direct', 'edit', 43), 5);
    }

    public function testControlWritesRequireExactBodiesAndAreSingleUseEvenAfterHeaderRejection(): void
    {
        foreach ($this->account->controlPosts() as $uri => $body) {
            $valid = self::request('POST', $uri, $body);
            $this->refuse(self::request('POST', $uri, $body . '&extra=1'), 0);
            $this->refuse($valid->withHeader('Authorization', 'whm root:other-secret'), 0);
            $this->refuse($valid, 0);
        }
    }

    public function testDirectPostRequiresTheNewAccountTokenEvenWhenItsUriAndBodyMatch(): void
    {
        $this->guard->arm('limits');
        $uri = self::WHM . 'modifyacct?api.version=1';
        $this->guard->sendRequest(self::request('POST', $uri, 'user=wldnsrecipe&MAXPARK=1'));
        $this->guard->arm('park_alias');
        $this->refuse(self::request('POST', self::DIRECT . 'Park/park', 'domain=dns-alias.example.test'), 1);
    }

    public function testTokenCannotBeReboundOrContainHeaderControlCharacters(): void
    {
        foreach (['', 'two words', "token\t", "token\n"] as $invalid) {
            try {
                $this->guard->bindToken($invalid);
                self::fail('An unusable token was bound.');
            } catch (LogicException $exception) {
                self::assertSame('Invalid DNS fixture token.', $exception->getMessage());
            }
        }
        $this->guard->bindToken('cpanel-secret');
        $this->expectException(LogicException::class);
        $this->guard->bindToken('another-secret');
    }

    public function testGuardNeverDumpsOrPersistsItsCredentials(): void
    {
        $this->guard->bindToken('cpanel-secret');
        self::assertSame([], $this->guard->__debugInfo());
        $this->expectException(LogicException::class);
        serialize($this->guard);
    }

    private function setupZone(): void
    {
        $this->guard->bindToken('cpanel-secret');
        $this->guard->arm('limits');
        $limits = self::request('POST', self::WHM . 'modifyacct?api.version=1', 'user=wldnsrecipe&MAXPARK=1');
        $this->guard->sendRequest($limits);
        $this->guard->arm('park_alias');
        $this->guard->sendRequest(self::request('POST', self::DIRECT . 'Park/park', 'domain=dns-alias.example.test'));
    }

    private function refuse(RequestInterface $request, int $alreadySent): void
    {
        try {
            $this->guard->sendRequest($request);
            self::fail('An unreviewed request reached the transport.');
        } catch (LogicException $exception) {
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
        self::assertCount($alreadySent, $this->http->getRequests());
    }

    private static function parse(string $route, string $zone = self::ZONE): Request
    {
        $uri = self::dns($route, 'parse_zone') . ($route === 'direct' ? '?' : '&') . 'zone=' . $zone;
        return self::request('GET', $uri);
    }

    private static function mass(string $route, string $verb, int $serial): Request
    {
        $record = '{"dname":"exercise.dns-recipe.example.test.","ttl":300,"record_type":"A","data":["192.0.2.51"]}';
        $value = match ($verb) {
            'add' => rawurlencode($record),
            'edit' => rawurlencode('{"line_index":11,' . str_replace('192.0.2.51', '192.0.2.52', substr($record, 1))),
            default => '11',
        };
        $body = "serial=$serial&zone=" . self::ZONE . "&$verb=$value";
        return self::request('POST', self::dns($route, 'mass_edit_zone'), $body);
    }

    private static function dns(string $route, string $function): string
    {
        return $route === 'direct' ? self::DIRECT . 'DNS/' . $function
            : self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=wldnsrecipe&cpanel.module=DNS&cpanel.function='
                . $function;
    }

    private static function request(string $method, string $uri, string $body = ''): Request
    {
        $headers = ['Authorization' => str_starts_with($uri, self::DIRECT)
            ? 'cpanel wldnsrecipe:cpanel-secret' : 'whm root:whm-secret', 'Accept' => 'application/json'];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        return new Request($method, $uri, $headers, $body);
    }
}
