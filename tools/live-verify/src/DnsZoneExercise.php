<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Dns;
use HostingPal\WhmApiClient\Api\Uapi\Spec\DnsSpec;
use HostingPal\WhmApiClient\Client;
use RuntimeException;
use SensitiveParameter;
use stdClass;

/**
 * Exercise typed DNS writes on the run's two separately owned zones. Each probe captures one original response.
 * Live record values, comparison snapshots, indexes and exception messages stay in memory.
 *
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-import-type SwapIpInZonesResult from Dns
 * @phpstan-type Route 'direct'|'bridged'
 */
final class DnsZoneExercise
{
    private const ZONES = [DnsZoneFixture::DOMAIN, DnsZoneFixture::ALIAS];

    private const MARKERS = ['source-one' => '192.0.2.61', 'source-two' => '192.0.2.62'];

    /** @var list<Record> */
    private array $records = [];

    /** @var 'not_run'|'known_failure'|'success'|'unexpected' */
    private string $bridge = 'not_run';

    /** The caller provisions and proves ownership of both zones before constructing this exercise. */
    public function __construct(
        private readonly EvidenceRunner $runner,
        private readonly CaptureClient $capture,
        #[SensitiveParameter] private readonly Client $direct,
        #[SensitiveParameter] private readonly Client $whm,
        private readonly string $version,
        private readonly DnsZoneGuard $guard,
    ) {
    }

    /** All readbacks use fresh calls; no mutation is retried, including the expected bridge failure. */
    public function run(): void
    {
        $this->massCycle('direct');
        $this->massCycle('bridged');
        foreach (self::ZONES as $zone) {
            $this->seed($zone);
        }
        $this->swap('bridged', '192.0.2.54');
        $this->swap('direct', '192.0.2.55');
    }

    /** @return list<Record> */
    public function observations(): array
    {
        return $this->records;
    }

    /** @return 'not_run'|'known_failure'|'success'|'unexpected' */
    public function bridgeOutcome(): string
    {
        return $this->bridge;
    }

    /** @param Route $route */
    private function massCycle(string $route): void
    {
        $zone = DnsZoneFixture::DOMAIN;
        $name = 'exercise.' . $zone . '.';
        $before = $this->read($route, $route . '-mass-before', $zone);
        self::check($before->absent($name));
        $serial = $this->mass($route, $route . '-mass-add', $zone, $before->serial, [self::a($name, '192.0.2.51')]);
        $added = $this->read($route, $route . '-mass-added', $zone);
        self::check($added->serial === $serial);
        $index = $added->requiredA($name, '192.0.2.51', 300);
        $serial = $this->mass(
            $route,
            $route . '-mass-edit',
            $zone,
            $added->serial,
            edit: [self::a($name, '192.0.2.52', $index)],
            editIndex: $index,
        );
        $edited = $this->read($route, $route . '-mass-edited', $zone);
        self::check($edited->serial === $serial);
        $index = $edited->requiredA($name, '192.0.2.52', 300);
        $serial = $this->mass($route, $route . '-mass-remove', $zone, $edited->serial, removeIndex: $index);
        $removed = $this->read($route, $route . '-mass-removed', $zone);
        self::check($removed->serial === $serial && $removed->absent($name));
    }

    /** Place both candidate source addresses in both zones; their apices nominate different candidates. */
    private function seed(string $zone): void
    {
        $phase = 'direct-seed-' . self::role($zone);
        $before = $this->read('direct', $phase . '-before', $zone);
        $index = $before->requiredA($zone . '.', $before->apexAddress());
        $add = [];
        foreach (self::MARKERS as $label => $address) {
            $owner = $label . '.' . $zone . '.';
            self::check($before->absent($owner));
            $add[] = self::a($owner, $address);
        }
        $apex = self::MARKERS[$zone === DnsZoneFixture::DOMAIN ? 'source-one' : 'source-two'];
        $serial = $this->mass(
            'direct',
            $phase,
            $zone,
            $before->serial,
            $add,
            [self::a($zone . '.', $apex, $index)],
            $index,
        );
        $after = $this->read('direct', $phase . '-after', $zone);
        self::check($after->serial === $serial && $after->apexAddress() === $apex);
        foreach (self::MARKERS as $label => $address) {
            $after->requiredA($label . '.' . $zone . '.', $address, 300);
        }
    }

    /** @param Route $route */
    private function swap(string $route, string $destination): void
    {
        $before = $this->swapReads($route, 'before');
        $one = $before[DnsZoneFixture::DOMAIN]->apexAddress();
        $two = $before[DnsZoneFixture::ALIAS]->apexAddress();
        self::check($one !== $two && !in_array($destination, [$one, $two], true));
        $sources = ['source-one' => $one, 'source-two' => $two];
        foreach (self::ZONES as $zone) {
            foreach ($sources as $label => $address) {
                $before[$zone]->requiredA($label . '.' . $zone . '.', $address, 300);
            }
        }
        if ($route === 'bridged') {
            $this->bridge = 'unexpected';
        }
        $phase = $route . '-swap';
        $this->guard->arm($phase);
        $rows = $this->observe(
            $route,
            $phase,
            'swap_ip_in_zones',
            DnsSpec::SWAP_IP_IN_ZONES,
            static fn (Dns $dns): array => $dns->swapIpInZones(domain: self::ZONES, destIp: $destination),
        );
        $after = $this->swapReads($route, 'after');
        if ($rows === null) {
            foreach (self::ZONES as $zone) {
                self::check($before[$zone]->sameAs($after[$zone]));
            }
            $this->bridge = 'known_failure';

            return;
        }

        // The chosen apex becomes the destination, the other apex retains its address.
        // Neither the order of traversal nor the order of changed rows is assumed.
        $mainChanged = $after[DnsZoneFixture::DOMAIN]->apexAddress() === $destination;
        $chosen = $mainChanged ? $one : $two;
        $changedLabel = $mainChanged ? 'source-one' : 'source-two';
        foreach (self::ZONES as $zone) {
            $oldApex = $before[$zone]->apexAddress();
            self::check($after[$zone]->apexAddress() === ($oldApex === $chosen ? $destination : $oldApex));
            self::check($after[$zone]->serial > $before[$zone]->serial);
            foreach ($sources as $label => $address) {
                $expected = $label === $changedLabel ? $destination : $address;
                $after[$zone]->requiredA($label . '.' . $zone . '.', $expected);
            }
            self::check(self::hasChange($rows, $zone, $changedLabel, $chosen, $destination));
        }
        if ($route === 'bridged') {
            $this->bridge = 'success';
        }
    }

    /**
     * Match a returned change to the same owner, source and destination found in the two independent zone reads.
     *
     * @param SwapIpInZonesResult $rows
     */
    private static function hasChange(
        #[SensitiveParameter] array $rows,
        string $zone,
        string $label,
        string $source,
        string $destination,
    ): bool {
        $count = 0;
        foreach ($rows as $row) {
            if (
                rtrim($row['zone_name'] ?? '', '.') === $zone && ($row['record_type'] ?? null) === 'A'
                && in_array(rtrim($row['record_name'] ?? '', '.'), [$label, $label . '.' . $zone], true)
                && ($row['old_value'] ?? null) === $source && ($row['new_value'] ?? null) === $destination
            ) {
                $count++;
            }
        }

        return $count === 1;
    }

    /**
     * @param Route $route
     * @return array<string, DnsZoneSnapshot>
     */
    private function swapReads(string $route, string $when): array
    {
        $snapshots = [];
        foreach (self::ZONES as $zone) {
            $phase = $route . '-swap-' . self::role($zone) . '-' . $when;
            $snapshots[$zone] = $this->read($route, $phase, $zone);
        }

        return $snapshots;
    }

    /** @param Route $route */
    private function read(string $route, string $phase, string $zone): DnsZoneSnapshot
    {
        $this->guard->arm($phase);
        $rows = $this->observe(
            $route,
            $phase,
            'parse_zone',
            DnsSpec::PARSE_ZONE,
            static fn (Dns $dns): array => $dns->parseZone($zone),
            $zone,
        );

        return new DnsZoneSnapshot($zone, $rows ?? throw new RuntimeException('Zone read failed.'));
    }

    /**
     * @param Route $route
     * @param non-empty-list<string>|null $add
     * @param non-empty-list<string>|null $edit
     */
    private function mass(
        string $route,
        string $phase,
        string $zone,
        int $serial,
        #[SensitiveParameter] ?array $add = null,
        #[SensitiveParameter] ?array $edit = null,
        ?int $editIndex = null,
        ?int $removeIndex = null,
    ): int {
        $this->guard->arm($phase, $serial, $editIndex, $removeIndex);
        $result = $this->observe(
            $route,
            $phase,
            'mass_edit_zone',
            DnsSpec::MASS_EDIT_ZONE,
            static fn (Dns $dns): array => $dns->massEditZone(
                $serial,
                $zone,
                $add,
                $edit,
                $removeIndex === null ? null : [$removeIndex],
            ),
        );
        $next = $result['new_serial'] ?? null;
        if ($next === null || $next <= $serial) {
            throw new RuntimeException('Mass edit did not return an advanced serial.');
        }

        return $next;
    }

    private static function a(string $owner, string $address, ?int $line = null): string
    {
        $record = ['dname' => $owner, 'ttl' => 300, 'record_type' => 'A', 'data' => [$address]];
        if ($line !== null) {
            $record = ['line_index' => $line, ...$record];
        }

        return json_encode($record, JSON_THROW_ON_ERROR);
    }

    private static function role(string $zone): string
    {
        return $zone === DnsZoneFixture::DOMAIN ? 'main' : 'alias';
    }

    /**
     * Retain each capture before interpreting its result. The null return is reserved for the exact bridge defect.
     *
     * @template T of array<mixed>
     * @param Route $route
     * @param array<mixed> $spec
     * @param Closure(Dns): T $invoke
     * @return T|null
     */
    private function observe(
        string $route,
        string $phase,
        string $function,
        array $spec,
        #[SensitiveParameter] Closure $invoke,
        ?string $readZone = null,
    ): ?array {
        $bridged = $route === 'bridged';
        $uri = $bridged
            ? 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=' . DnsZoneFixture::ACCOUNT
                . '&cpanel.module=DNS&cpanel.function=' . $function
            : 'https://test.oblak.host:2083/execute/DNS/' . $function;
        if ($readZone !== null) {
            $uri .= ($bridged ? '&' : '?') . 'zone=' . $readZone;
        }
        $result = null;
        $probe = new Probe(
            'uapi:DNS/' . $function,
            $bridged ? 'bridged-uapi' : 'direct-uapi',
            $spec,
            $uri,
            static function (#[SensitiveParameter] Client $client) use ($bridged, $invoke, &$result): void {
                $result = $invoke($client->uapi($bridged ? DnsZoneFixture::ACCOUNT : null)->dns());
            },
            argumentVariant: $phase,
        );
        $record = $this->runner->capture($probe, $this->capture, $bridged ? $this->whm : $this->direct);
        $record['server_version'] = $this->version;
        $this->records[] = $record;
        if (
            $record['http_status'] !== 200 || $record['method'] !== ($readZone === null ? 'POST' : 'GET')
            || $record['discrepancies'] !== []
        ) {
            throw new RuntimeException('DNS probe transport or structural evidence failed.');
        }
        if ($record['probe_outcome'] === 'passed') {
            return $result ?? throw new RuntimeException('DNS probe lacked its typed result.');
        }
        if (
            $phase === 'bridged-swap' && $record['failure_kind'] === 'api_failure'
            && self::knownBridgeFailure($this->capture->rawBody())
        ) {
            return null;
        }

        throw new RuntimeException('DNS probe failed.');
    }

    /** Inspect only the same HTTP response that produced the typed API failure. */
    public static function knownBridgeFailure(#[SensitiveParameter] ?string $raw): bool
    {
        $outer = json_decode($raw ?? '');
        if (
            !$outer instanceof stdClass || !($outer->metadata ?? null) instanceof stdClass
            || ($outer->metadata->result ?? null) !== 1 || !($outer->data ?? null) instanceof stdClass
            || !($outer->data->uapi ?? null) instanceof stdClass
        ) {
            return false;
        }
        $inner = $outer->data->uapi;
        $errors = $inner->errors ?? null;
        if (
            ($inner->status ?? null) !== 0 || !is_array($errors) || count($errors) !== 1
            || !is_string($errors[0] ?? null)
        ) {
            return false;
        }

        return preg_match(
            '~\bUndefined subroutine &Cpanel::AdminBin::Call::call called at /usr/local/cpanel/Cpanel/API/DNS\.pm'
                . '(?: line [0-9]+)?\.~',
            $errors[0],
        ) === 1;
    }

    private static function check(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('DNS exercise expectation failed.');
        }
    }
}
