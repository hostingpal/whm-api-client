<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\SslSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Upload, inspect and remove only fresh account-owned SSL storage material through both routes.
 *
 * Inventories establish absence before creation. Only a successful original API
 * response with a unique new ID and the exact fresh RSA modulus establishes
 * ownership, including when another payload field fails validation. Ambiguous or
 * unsuccessful creation is never adopted from a later inventory. Each known ID
 * gets at most one cleanup request, followed by exact ID and modulus absence checks.
 * No certificate installation, server certificate replacement, redirects, SNI,
 * defaults, exclusions or account configuration changes are allowed.
 *
 * @phpstan-import-type Route from SslSectionGuard
 * @phpstan-import-type Context from EvidenceRunner
 * @phpstan-import-type Record from EvidenceRunner
 * @phpstan-type Observation array{phase:string, expectation:'passed'|'failed'|'not_checked', record:Record}
 * @phpstan-type Resources array{key:'not_started'|'owned'|'cleaned'|'uncertain',
 *     cert:'not_started'|'owned'|'cleaned'|'uncertain'}
 * @phpstan-type Report array{recipe:string,status:'passed'|'failed',probes:list<Observation>,
 *     resources:array<Route,Resources>,failures:list<string>,limitations:list<string>}
 */
final class SslSectionFixture
{
    /** Pinned source variant and expected actual request method, reviewed independently of generated method policy. */
    private const REQUEST_CONTRACTS = [
        'whm1:version' => ['whm1:version#GET', 'GET'],
        'uapi:SSL/list_keys' => ['uapi:SSL/list_keys#GET', 'GET'],
        'uapi:SSL/list_certs' => ['uapi:SSL/list_certs#GET', 'GET'],
        'uapi:SSL/upload_key' => ['uapi:SSL/upload_key#POST', 'POST'],
        'uapi:SSL/upload_cert' => ['uapi:SSL/upload_cert#POST', 'POST'],
        'uapi:SSL/fetch_key_and_cabundle_for_certificate'
            => ['uapi:SSL/fetch_key_and_cabundle_for_certificate#POST', 'POST'],
        'uapi:SSL/delete_key' => ['uapi:SSL/delete_key#GET', 'POST'],
        'uapi:SSL/delete_cert' => ['uapi:SSL/delete_cert#GET', 'POST'],
    ];

    private readonly SslSectionGuard $guard;
    private readonly CaptureClient $capture;
    private readonly EvidenceRunner $runner;
    /** @var array<'whm'|Route,Client> */
    private readonly array $clients;
    /** @var list<Observation> */
    private array $records = [];
    /** @var array<Route,Resources> */
    private array $resources = [
        'direct-uapi' => ['key' => 'not_started', 'cert' => 'not_started'],
        'bridged-uapi' => ['key' => 'not_started', 'cert' => 'not_started'],
    ];
    /** @var array<string,string> */
    private array $owned = [];
    /** @var list<string> */
    private array $initialIds = [];
    /** @var list<string> */
    private array $failures = [];
    private bool $used = false;

    /** @param array{whm:string,cpanel:string} $tokens
     * @param array<Route,SslSectionMaterial> $materials
     * @param Context $context
     */
    public function __construct(
        #[SensitiveParameter] ClientInterface $http,
        #[SensitiveParameter] array $tokens,
        #[SensitiveParameter] private readonly array $materials,
        array $context,
    ) {
        if ($materials['direct-uapi']->modulus === $materials['bridged-uapi']->modulus) {
            throw new RuntimeException('SSL fixture routes require independent fresh keys.');
        }
        $this->guard = new SslSectionGuard($http, $tokens, $materials);
        $this->capture = new CaptureClient($this->guard);
        $factory = new Psr17Factory();
        $transport = new HttpTransport($this->capture, $factory, $factory);
        $bridge = new Client(['host' => 'test.oblak.host', 'port' => 2087, 'authType' => 'whm',
            'username' => 'root', 'token' => $tokens['whm']], $transport);
        $direct = new Client(['host' => 'test.oblak.host', 'port' => 2083, 'authType' => 'cpanel',
            'username' => 'npr', 'token' => $tokens['cpanel']], $transport);
        $this->clients = ['whm' => $bridge, 'direct-uapi' => $direct, 'bridged-uapi' => $bridge];
        $this->runner = new EvidenceRunner($this->guard, $context);
    }

    /** Run at most once; every failure remains structural evidence, with cleanup for confirmed owned IDs.
     * @return Report
     */
    public function run(): array
    {
        if ($this->used || !SslSectionGuard::optedIn()) {
            $this->failures[] = 'configuration_failure';
            return $this->report();
        }
        $this->used = true;
        try {
            $this->call('version', 'whm', 'version');
            $this->successful();
        } catch (Throwable) {
            $this->failures[] = 'version_failure';
            return $this->report();
        }
        $ready = true;
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach (['key', 'cert'] as $kind) {
                try {
                    $rows = $this->inventory('initial_' . $kind, $route, $kind);
                    $this->absent($rows);
                    foreach ($rows as $row) {
                        $this->initialIds[] = $row['id'];
                    }
                } catch (Throwable) {
                    $ready = false;
                }
            }
        }
        if (!$ready) {
            $this->failures[] = 'preflight_failure';
            return $this->report();
        }
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            try {
                $this->lifecycle($route);
            } catch (Throwable) {
                $this->failures[] = $route . ':lifecycle_failure';
            } finally {
                $this->cleanup($route);
            }
            if (in_array('uncertain', $this->resources[$route], true)) {
                $this->failures[] = 'unresolved_ownership';
                break;
            }
        }
        return $this->report();
    }

    /** @param Route $route */
    private function lifecycle(string $route): void
    {
        foreach (['key', 'cert'] as $kind) {
            $rows = $this->inventory('ready_' . $kind, $route, $kind);
            $this->absent($rows);
            foreach ($rows as $row) {
                $this->initialIds[] = $row['id'];
            }
        }
        $material = $this->materials[$route];
        foreach (['key', 'cert'] as $kind) {
            $this->setState($route, $kind, 'uncertain');
            $this->call('upload_' . $kind, $route, 'upload_' . $kind, [
                $kind === 'key' ? 'key' : 'crt' => $kind === 'key' ? $material->key : $material->certificate,
                'friendly_name' => SslSectionGuard::name($material),
            ]);
            if ($this->last()['record']['api_outcome'] === 'success') {
                $id = $this->createdId($route);
                if ($id !== null) {
                    $this->owned[$route . ':' . $kind] = $id;
                    $this->guard->own($route, $kind, $id);
                    $this->setState($route, $kind, 'owned');
                }
            }
            $this->expect($this->resources[$route][$kind] === 'owned');
            $this->successful();
        }
        $result = $this->call(
            'fetch',
            $route,
            'fetch_key_and_cabundle_for_certificate',
            ['certificate' => $material->certificate],
        );
        $this->successful();
        $key = is_array($result) ? ($result['key'] ?? null) : null;
        $certificate = is_array($result) ? ($result['crt'] ?? null) : null;
        $this->expect(is_string($key) && SslSectionMaterial::keyModulus($key) === $material->modulus);
        $this->expect(is_array($result) && ($result['status'] ?? null) === 1);
        $this->expect(is_string($certificate)
            && @openssl_x509_fingerprint($certificate, 'sha256')
                === openssl_x509_fingerprint($material->certificate, 'sha256'));
    }

    /** Read the original creation response, never a later resource inventory.
     * @param Route $route
     */
    private function createdId(string $route): ?string
    {
        try {
            $raw = $this->capture->rawBody();
            $document = $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                return null;
            }
            $outer = $document['data'] ?? null;
            $result = $route === 'bridged-uapi' ? (is_array($outer) ? ($outer['uapi'] ?? null) : null)
                : (array_key_exists('result', $document) ? $document['result'] : $document);
            $rows = is_array($result) ? ($result['data'] ?? null) : null;
            if (!is_array($rows) || !array_is_list($rows)) {
                return null;
            }
            $ids = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['modulus'] ?? null)) {
                    continue;
                }
                if (SslSectionMaterial::normalize($row['modulus']) !== $this->materials[$route]->modulus) {
                    continue;
                }
                $id = $row['id'] ?? null;
                if (!is_string($id) || $id === '' || in_array($id, $this->initialIds, true)) {
                    return null;
                }
                $ids[] = $id;
            }
            return count($ids) === 1 ? $ids[0] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param Route $route */
    private function cleanup(string $route): void
    {
        foreach (['cert', 'key'] as $kind) {
            if ($this->resources[$route][$kind] !== 'owned') {
                continue;
            }
            try {
                $this->call('delete_' . $kind, $route, 'delete_' . $kind, ['id' => $this->owned[$route . ':' . $kind]]);
                $this->successful();
            } catch (Throwable) {
                $this->failures[] = $route . ':cleanup_request_failure';
            }
        }
        foreach (['cert', 'key'] as $kind) {
            try {
                $rows = $this->inventory('final_' . $kind, $route, $kind);
                $this->absent($rows);
                $id = $this->owned[$route . ':' . $kind] ?? null;
                foreach ($rows as $row) {
                    $this->expect($row['id'] !== $id);
                }
                if ($this->resources[$route][$kind] === 'owned') {
                    $this->setState($route, $kind, 'cleaned');
                }
            } catch (Throwable) {
                $this->failures[] = $route . ':cleanup_unconfirmed';
                if ($this->resources[$route][$kind] !== 'not_started') {
                    $this->setState($route, $kind, 'uncertain');
                }
            }
        }
    }

    /** @param Route $route
     * @param 'key'|'cert' $kind
     * @return list<array{id:string,modulus:?string,friendly_name:string}>
     */
    private function inventory(string $phase, string $route, string $kind): array
    {
        $rows = $this->call($phase, $route, $kind === 'key' ? 'list_keys' : 'list_certs');
        $this->successful();
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->expect(false);
            throw new RuntimeException('SSL inventory unavailable.');
        }
        $result = [];
        $ids = [];
        foreach ($rows as $row) {
            if (
                !is_array($row) || !is_string($row['id'] ?? null) || $row['id'] === ''
                || !array_key_exists('modulus', $row) || (!is_string($row['modulus']) && $row['modulus'] !== null)
                || !is_string($row['friendly_name'] ?? null) || in_array($row['id'], $ids, true)
            ) {
                $this->expect(false);
                throw new RuntimeException('SSL inventory identity unavailable.');
            }
            $ids[] = $row['id'];
            $result[] = ['id' => $row['id'], 'modulus' => $row['modulus'] === null
                ? null : SslSectionMaterial::normalize($row['modulus']), 'friendly_name' => $row['friendly_name']];
        }
        return $result;
    }

    /** @param list<array{id:string,modulus:?string,friendly_name:string}> $rows */
    private function absent(#[SensitiveParameter] array $rows): void
    {
        foreach ($rows as $row) {
            foreach ($this->materials as $material) {
                $this->expect($row['modulus'] !== $material->modulus
                    && $row['friendly_name'] !== SslSectionGuard::name($material));
            }
        }
    }

    /** @param 'whm'|Route $route
     * @param array<string,string> $fields
     */
    private function call(
        string $phase,
        string $route,
        string $function,
        #[SensitiveParameter] array $fields = [],
    ): mixed {
        $spec = match ($function) {
            'version' => ServerSpec::VERSION,
            'list_keys' => SslSpec::LIST_KEYS,
            'list_certs' => SslSpec::LIST_CERTS,
            'upload_key' => SslSpec::UPLOAD_KEY,
            'upload_cert' => SslSpec::UPLOAD_CERT,
            'fetch_key_and_cabundle_for_certificate' => SslSpec::FETCH_KEY_AND_CABUNDLE_FOR_CERTIFICATE,
            'delete_key' => SslSpec::DELETE_KEY,
            'delete_cert' => SslSpec::DELETE_CERT,
            default => throw new RuntimeException('Unknown SSL fixture function.'),
        };
        $uri = $this->guard->authorize($phase, $route, $function, $fields);
        $result = null;
        $operation = $route === 'whm' ? 'whm1:version' : 'uapi:SSL/' . $function;
        $contract = self::REQUEST_CONTRACTS[$operation]
            ?? throw new RuntimeException('Unknown SSL fixture request contract.');
        $probe = new Probe(
            $operation,
            $route,
            $spec,
            $uri,
            static function (#[SensitiveParameter] Client $client) use ($function, $fields, &$result): mixed {
                $ssl = $function === 'version' ? null : $client->uapi('npr')->ssl();
                return $result = match ($function) {
                    'version' => $client->whm()->server()->version(),
                    'list_keys' => $ssl?->listKeys(),
                    'list_certs' => $ssl?->listCerts(),
                    'upload_key' => $ssl?->uploadKey(key: $fields['key'], friendlyName: $fields['friendly_name']),
                    'upload_cert' => $ssl?->uploadCert(crt: $fields['crt'], friendlyName: $fields['friendly_name']),
                    'fetch_key_and_cabundle_for_certificate' => $ssl?->fetchKeyAndCabundleForCertificate(
                        $fields['certificate'],
                    ),
                    'delete_key' => $ssl?->deleteKey(id: $fields['id']),
                    'delete_cert' => $ssl?->deleteCert(id: $fields['id']),
                };
            },
            sourceVariant: $contract[0],
        );
        try {
            $record = $this->runner->capture($probe, $this->capture, $this->clients[$route]);
        } finally {
            $this->guard->clearPending();
        }
        $this->records[] = ['phase' => $phase, 'record' => $record,
            'expectation' => $record['probe_outcome'] === 'passed' ? 'passed' : 'not_checked'];
        return $result;
    }

    /** @param Route $route
     * @param 'key'|'cert' $kind
     * @param 'not_started'|'owned'|'cleaned'|'uncertain' $state
     */
    private function setState(string $route, string $kind, string $state): void
    {
        $current = $this->resources[$route];
        if ($kind === 'key') {
            $current['key'] = $state;
        } else {
            $current['cert'] = $state;
        }
        $this->resources[$route] = $current;
    }

    private function successful(): void
    {
        $record = $this->last()['record'];
        if ($record['probe_outcome'] !== 'passed' || $record['discrepancies'] !== []) {
            throw new RuntimeException('SSL observation failed.');
        }
    }

    private function expect(bool $condition): void
    {
        if (!$condition) {
            $last = array_key_last($this->records);
            if ($last !== null) {
                $this->records[$last]['expectation'] = 'failed';
            }
            throw new RuntimeException('SSL expectation failed.');
        }
    }

    /** @return Observation */
    private function last(): array
    {
        $last = array_key_last($this->records);
        if ($last === null) {
            throw new RuntimeException('SSL observation unavailable.');
        }
        return $this->records[$last];
    }

    /** @return Report */
    private function report(): array
    {
        $passed = $this->failures === [] && count($this->records) === 23;
        $version = $this->records[0]['record']['server_version'] ?? null;
        foreach ($this->records as &$record) {
            $record['record']['server_version'] = $version;
            $expected = self::REQUEST_CONTRACTS[$record['record']['operation']] ?? null;
            $passed = $passed && $expected !== null
                && $record['record']['variant'] === $expected[0] && $record['record']['method'] === $expected[1]
                && $version !== null && $record['expectation'] === 'passed'
                && $record['record']['probe_outcome'] === 'passed' && $record['record']['discrepancies'] === [];
        }
        foreach ($this->resources as $state) {
            $passed = $passed && $state === ['key' => 'cleaned', 'cert' => 'cleaned'];
        }
        return ['recipe' => 'ssl-owned-multipart-npr-v1', 'status' => $passed ? 'passed' : 'failed',
            'probes' => $this->records, 'resources' => $this->resources,
            'failures' => array_values(array_unique($this->failures)),
            'limitations' => ['storage_only_no_certificate_installation', 'no_tls_service_or_trust_claim',
                'uncertain_creations_are_not_adopted_or_deleted', 'empty_lists_do_not_observe_item_fields']];
    }
}
