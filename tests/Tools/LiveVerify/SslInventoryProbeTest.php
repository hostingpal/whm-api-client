<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\EvidenceRunner;
use HostingPal\WhmApiClient\Tools\LiveVerify\Probe;
use HostingPal\WhmApiClient\Tools\LiveVerify\ProbeCatalog;
use Http\Mock\Client;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Bind the reviewed SSL recipes to real generated calls and sanitized same-response evidence.
 *
 * @phpstan-import-type Record from EvidenceRunner
 */
final class SslInventoryProbeTest extends TestCase
{
    /** @param array<string, list<string>> $paths */
    #[DataProvider('probes')]
    public function testSslProbeUsesReviewedRouteAndPreservesRawStructuralEvidence(
        string $operation,
        string $route,
        string $payload,
        array $paths,
    ): void {
        $http = new Client();
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
        $records = self::capture($http, self::probe($operation, $route));

        self::assertRequest($http, $operation, $route);
        self::assertSame('success', $records[0]['api_outcome']);
        self::assertSame('passed', $records[0]['payload_validation']);
        self::assertSame('passed', $records[0]['probe_outcome']);
        self::assertSame([], $records[0]['discrepancies']);
        foreach ($paths as $path => $types) {
            self::assertSame($types, $records[0]['payload_paths'][$path] ?? null, $path);
        }
        if ($payload === '[]') {
            self::assertSame(['$' => ['empty-list']], $records[0]['payload_paths']);
        }
        self::assertPrivateValuesAbsent($records);
    }

    /** @return iterable<string, array{string, string, string, array<string, list<string>>}> */
    public static function probes(): iterable
    {
        $cases = [
            ['installed_hosts', '[{"certificate":{"id":"123456789","domains":["private.example.test"],'
                . '"not_after":1800000000,"not_before":1700000000,"is_autossl":1,"is_self_signed":0,'
                . '"issuer.commonName":"private issuer","issuer.organizationName":"private organization",'
                . '"subject.commonName":"private.example.test","ecdsa_curve_name":null,"ecdsa_public":null,'
                . '"modulus":"private modulus","modulus_length":2048,"validation_type":null},'
                . '"certificate_text":"-----BEGIN CERTIFICATE-----private pem-----END CERTIFICATE-----",'
                . '"docroot":"/home/private-account/public_html","domains":["private.example.test"],'
                . '"fqdns":[],"ip":"192.0.2.7","is_primary_on_ip":0,"mail_sni_status":1,"needs_sni":1,'
                . '"servername":"private.example.test"}]', [
                    '$' => ['list'], '$[]' => ['object'], '$[].certificate' => ['object'],
                    '$[].certificate.id' => ['numeric-string'], '$[].certificate.not_after' => ['int'],
                    '$[].certificate.not_before' => ['int'], '$[].certificate.is_autossl' => ['int'],
                    '$[].certificate.is_self_signed' => ['int'], '$[].certificate.ecdsa_curve_name' => ['null'],
                    '$[].certificate.ecdsa_public' => ['null'], '$[].certificate.validation_type' => ['null'],
                    '$[].certificate.issuer.commonName' => ['string'],
                    '$[].certificate.issuer.organizationName' => ['string'],
                    '$[].certificate.subject.commonName' => ['string'],
                    '$[].certificate.auto_ssl_provider' => ['missing'],
                    '$[].certificate_text' => ['string'], '$[].docroot' => ['string'],
                    '$[].fqdns' => ['empty-list'], '$[].is_primary_on_ip' => ['int'],
                ]],
            ['get_autossl_excluded_domains', '[{"excluded_domain":"private.example.test"}]', [
                '$' => ['list'], '$[]' => ['object'], '$[].excluded_domain' => ['string'],
            ]],
            ['get_autossl_problems', '[{"domain":"private.example.test","problem":"private DCV problem",'
                . '"time":"2026-10-16T00:00:00Z"}]', [
                    '$' => ['list'], '$[]' => ['object'], '$[].domain' => ['string'],
                    '$[].problem' => ['string'], '$[].time' => ['string'],
                ]],
            ['get_autossl_renewal_status', '{"is_active":1,"is_excluded":0,"has_problems":0,"will_renew":1}', [
                '$' => ['object'], '$.has_problems' => ['int'], '$.is_active' => ['int'],
                '$.is_excluded' => ['int'], '$.will_renew' => ['int'],
            ]],
            ['is_autossl_check_in_progress', '0', ['$' => ['int']]],
            ['is_autossl_check_in_progress', '1', ['$' => ['int']]],
            ['can_ssl_redirect', '0', ['$' => ['int']]],
            ['can_ssl_redirect', '1', ['$' => ['int']]],
            ['installed_hosts', '[]', ['$' => ['empty-list']]],
            ['get_autossl_excluded_domains', '[]', ['$' => ['empty-list']]],
            ['get_autossl_problems', '[]', ['$' => ['empty-list']]],
        ];
        foreach (['direct-uapi', 'bridged-uapi'] as $route) {
            foreach ($cases as $index => [$operation, $payload, $paths]) {
                yield $route . ' ' . $operation . ' ' . $index => [$operation, $route, $payload, $paths];
            }
        }
    }

    #[DataProvider('routes')]
    public function testUnknownCertificateKeysAreWildcardedBeforeGeneratedFieldRemoval(string $route): void
    {
        $http = new Client();
        $payload = '[{"certificate":{"private.example.test":{"private-account":{"subject.commonName":'
            . '"private nested value"}}},"private-domain-key":{"private path":"private pem"}}]';
        $http->addResponse(new Response(200, [], self::envelope($route, $payload)));

        $records = self::capture($http, self::probe('installed_hosts', $route));

        self::assertRequest($http, 'installed_hosts', $route);
        self::assertSame('passed', $records[0]['payload_validation']);
        self::assertSame([
            ['path' => '$[].*', 'kind' => 'unknown_field'],
            ['path' => '$[].certificate.*', 'kind' => 'unknown_field'],
        ], $records[0]['discrepancies']);
        self::assertSame(['string'], $records[0]['payload_paths']['$[].certificate.*.*.*']);
        self::assertSame(['string'], $records[0]['payload_paths']['$[].*.*']);
        self::assertPrivateValuesAbsent($records);
    }

    #[DataProvider('routes')]
    public function testApiFailureKeepsStructureWithoutPrivateErrorTextOrSuccessDiscrepancies(string $route): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], self::envelope($route, 'null', 0)));

        $records = self::capture($http, self::probe('installed_hosts', $route));

        self::assertRequest($http, 'installed_hosts', $route);
        self::assertSame('failure', $records[0]['api_outcome']);
        self::assertSame('not_checked', $records[0]['payload_validation']);
        self::assertSame('failed', $records[0]['probe_outcome']);
        self::assertSame('api_failure', $records[0]['failure_kind']);
        self::assertSame([], $records[0]['discrepancies']);
        self::assertSame(['$' => ['null']], $records[0]['payload_paths']);
        $prefix = $route === 'direct-uapi' ? '$' : '$.data.uapi';
        self::assertSame(['string'], $records[0]['response_paths'][$prefix . '.errors[]']);
        self::assertPrivateValuesAbsent($records);
    }

    #[DataProvider('routes')]
    public function testAcceptedOutcomeWithMalformedCertificateRetainsRawMismatchEvidence(string $route): void
    {
        $http = new Client();
        $http->addResponse(new Response(200, [], self::envelope(
            $route,
            '[{"certificate":{"not_after":"private invalid expiry"}}]',
        )));

        $records = self::capture($http, self::probe('installed_hosts', $route));

        self::assertRequest($http, 'installed_hosts', $route);
        self::assertSame('success', $records[0]['api_outcome']);
        self::assertSame('failed', $records[0]['payload_validation']);
        self::assertSame('failed', $records[0]['probe_outcome']);
        self::assertSame('payload_mismatch', $records[0]['failure_kind']);
        self::assertSame([
            ['path' => '$[].certificate.not_after', 'kind' => 'type_mismatch'],
        ], $records[0]['discrepancies']);
        self::assertSame(['string'], $records[0]['payload_paths']['$[].certificate.not_after']);
        self::assertPrivateValuesAbsent($records);
    }

    /** @return iterable<string, array{string}> */
    public static function routes(): iterable
    {
        yield 'direct' => ['direct-uapi'];
        yield 'bridge' => ['bridged-uapi'];
    }

    private static function probe(string $operation, string $route): Probe
    {
        $probes = array_values(array_filter(
            ProbeCatalog::load(dirname(__DIR__, 3)),
            static fn (Probe $probe): bool =>
                $probe->operation === 'uapi:SSL/' . $operation && $probe->route === $route,
        ));
        self::assertCount(1, $probes, 'Exactly one reviewed SSL probe must bind this operation and route.');

        return $probes[0];
    }

    /** @return list<Record> */
    private static function capture(Client $http, Probe $probe): array
    {
        return (new EvidenceRunner($http, [
            'revision' => str_repeat('a', 40),
            'source_hashes' => ['whm1' => str_repeat('b', 64), 'uapi' => str_repeat('c', 64)],
            'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40),
            'overlay_sha256' => str_repeat('f', 64),
        ]))->run([$probe], '1', ['whm' => 'private-whm-marker', 'cpanel' => 'private-cpanel-marker']);
    }

    private static function assertRequest(Client $http, string $operation, string $route): void
    {
        self::assertCount(1, $http->getRequests());
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $uri = $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/SSL/' . $operation
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=SSL&cpanel.function=' . $operation;
        if ($operation === 'get_autossl_renewal_status') {
            $uri .= $route === 'direct-uapi' ? '?domain=npr.rs' : '&domain=npr.rs';
        }
        self::assertSame('GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(
            $route === 'direct-uapi' ? 'cpanel npr:private-cpanel-marker' : 'whm root:private-whm-marker',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function envelope(string $route, string $payload, int $status = 1): string
    {
        $result = '{"status":' . $status . ',"data":' . $payload
            . ',"errors":' . ($status === 1 ? 'null' : '["private error text"]')
            . ',"warnings":["private warning text"],"metadata":{"private@example.test":"private metadata"}}';

        return $route === 'direct-uapi' ? $result : '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}';
    }

    /** @param list<Record> $records */
    private static function assertPrivateValuesAbsent(array $records): void
    {
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        $privateValues = [
            'private-whm-marker', 'private-cpanel-marker', 'private.example.test', 'private issuer',
            'private organization', 'private modulus', 'private pem', 'BEGIN CERTIFICATE', 'private-account',
            'private-domain-key', 'private path', 'private nested value', '/home/', '192.0.2.7', '123456789',
            'private DCV problem', 'private error text', 'private warning text', 'private@example.test',
            'private metadata', 'private invalid expiry',
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }
}
