<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Two in-memory zone files behind the typed direct and bridged clients.
 * Parses the incoming form and serves subsequent reads from the changed rows, independently of the exercise phases.
 */
final class DnsZoneExerciseServer implements ClientInterface
{
    public const MAIN = 'dns-recipe.example.test';
    public const ALIAS = 'dns-alias.example.com';
    private const ERROR = "Undefined subroutine &Cpanel::AdminBin::Call::call called at "
        . "/usr/local/cpanel/Cpanel/API/DNS.pm line 176.\n";

    /** @var array<string, int> */
    private array $serials = [self::MAIN => 100, self::ALIAS => 200];

    /** @var array<string, array<int, array{name: string, address: string, ttl: int}>> */
    private array $records = [
        self::MAIN => [1 => ['name' => '@', 'address' => '198.51.100.1', 'ttl' => 600]],
        self::ALIAS => [1 => ['name' => '@', 'address' => '198.51.100.2', 'ttl' => 600]],
    ];

    public function __construct(private readonly string $behavior)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = basename($request->getUri()->getPath());
        $bridge = $path === 'uapi_cpanel';
        $query = self::fields($request->getUri()->getQuery());
        $function = $bridge ? $query['cpanel.function'][0] : $path;
        $form = self::fields((string) $request->getBody());
        $data = match ($function) {
            'modifyacct', 'park' => null,
            'parse_zone' => $this->read($query['zone'][0]),
            'mass_edit_zone' => $this->edit($form),
            'swap_ip_in_zones' => $this->swap($form['domain'], $form['dest_ip'][0], $bridge),
            default => throw new LogicException('Unsupported synthetic request.'),
        };
        $errors = $function === 'swap_ip_in_zones' && $data === null ? [self::ERROR] : null;
        $inner = ['status' => $errors === null ? 1 : 0, 'errors' => $errors, 'data' => $data];
        $document = match (true) {
            $bridge => ['metadata' => ['result' => 1], 'data' => ['uapi' => $inner]],
            $function === 'modifyacct' => ['metadata' => ['result' => 1]],
            default => $inner,
        };

        return new Response(200, [], json_encode($document, JSON_THROW_ON_ERROR));
    }

    /**
     * Parse repeated fields individually, including literal dots in bridge routing names.
     *
     * @return array<string, non-empty-list<string>>
     */
    private static function fields(string $wire): array
    {
        $fields = [];
        foreach (explode('&', $wire) as $part) {
            if ($part !== '') {
                [$name, $value] = explode('=', $part, 2);
                $fields[rawurldecode($name)][] = rawurldecode($value);
            }
        }

        return $fields;
    }

    /** @return list<array<string, mixed>> */
    private function read(string $zone): array
    {
        $rows = [[
            'type' => 'record', 'record_type' => 'SOA', 'line_index' => 0, 'dname_b64' => base64_encode('@'),
            'data_b64' => array_map(base64_encode(...), ['ns', 'hostmaster', (string) $this->serials[$zone]]),
        ]];
        foreach ($this->records[$zone] as $index => $record) {
            $rows[] = [
                'type' => 'record', 'record_type' => 'A', 'line_index' => $index, 'ttl' => $record['ttl'],
                'dname_b64' => base64_encode($record['name']), 'data_b64' => [base64_encode($record['address'])],
            ];
        }

        return $rows;
    }

    /**
     * Replace only the selected line and keep the other rows across all calls on both routes.
     *
     * @param array<string, list<string>> $form
     * @return array{new_serial: string}
     */
    private function edit(array $form): array
    {
        $zone = $form['zone'][0];
        if ((string) $this->serials[$zone] !== $form['serial'][0]) {
            throw new LogicException('Stale serial in synthetic edit.');
        }
        foreach ($form['remove'] ?? [] as $index) {
            unset($this->records[$zone][(int) $index]);
        }
        foreach ([...($form['add'] ?? []), ...($form['edit'] ?? [])] as $input) {
            $row = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
            if (
                !is_array($row) || !is_string($row['dname'] ?? null) || !is_int($row['ttl'] ?? null)
                || !is_array($row['data'] ?? null) || !is_string($row['data'][0] ?? null)
            ) {
                throw new LogicException('Malformed synthetic A record.');
            }
            $record = ['name' => $row['dname'], 'address' => $row['data'][0], 'ttl' => $row['ttl']];
            if (is_int($row['line_index'] ?? null)) {
                $this->records[$zone][$row['line_index']] = $record;
            } else {
                $this->records[$zone][] = $record;
            }
        }

        return ['new_serial' => (string) ++$this->serials[$zone]];
    }

    /**
     * Select an apex once per call unless simulating the documented per-zone algorithm. Swap traversal and report
     * order deliberately differ from request order. Faults can contradict the readback or change a refused zone.
     *
     * @param list<string> $zones
     * @return list<array<string, string>>|null
     */
    private function swap(array $zones, string $destination, bool $bridge): ?array
    {
        if ($bridge && $this->behavior !== 'bridge_success') {
            if ($this->behavior === 'failure_changes_zone') {
                $this->records[self::ALIAS][1]['ttl']++;
            }

            return null;
        }
        $sourceZone = $bridge ? self::MAIN : self::ALIAS;
        $source = $this->records[$sourceZone][1]['address'];
        $changes = [];
        foreach (array_reverse($zones) as $zone) {
            ++$this->serials[$zone];
            $selected = $this->behavior === 'per_zone' ? $this->records[$zone][1]['address'] : $source;
            foreach ($this->records[$zone] as $index => $row) {
                if ($row['address'] !== $selected) {
                    continue;
                }
                $this->records[$zone][$index]['address'] = $destination;
                $change = [
                    'zone_name' => $zone, 'record_type' => 'A', 'record_name' => $row['name'],
                    'old_value' => $selected, 'new_value' => $destination,
                ];
                $changes[] = $change;
                if ($zone === self::ALIAS && str_starts_with($row['name'], 'source-two.')) {
                    if ($this->behavior === 'conflicting_changed') {
                        $changes[] = array_replace($change, ['new_value' => '203.0.113.8']);
                    }
                    if ($this->behavior === 'reported_unchanged') {
                        $changes[] = array_replace($change, [
                            'record_name' => 'source-one', 'old_value' => '192.0.2.61',
                        ]);
                    }
                }
            }
            $changes[] = [
                'zone_name' => $zone, 'record_type' => 'TXT', 'record_name' => '@',
                'old_value' => 'v=spf1 -all', 'new_value' => 'v=spf1 -all',
            ];
        }

        return $changes;
    }
}
