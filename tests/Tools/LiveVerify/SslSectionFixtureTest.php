<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\SslSectionFixture;
use HostingPal\WhmApiClient\Tools\LiveVerify\SslSectionMaterial;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class SslSectionFixtureTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (
            ['WHM_API_LIVE' => '1', 'WHM_API_LIVE_MUTATIONS' => '1',
            'WHM_API_LIVE_ACCOUNT' => 'npr', 'WHM_API_LIVE_DOMAIN' => 'npr.rs'] as $name => $value
        ) {
            $this->environment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testBothRoutesUploadFetchAndCleanOnlyOwnedResources(): void
    {
        self::assertTrue(class_exists(SslSectionFixture::class), 'Missing bounded SSL fixture.');
        $server = new SslSectionServer();
        $fixture = self::fixture($server);
        $report = $fixture->run();
        self::assertSame('passed', $report['status']);
        $multipart = 0;
        $deletions = 0;
        foreach ($report['probes'] as $probe) {
            $record = $probe['record'];
            if (
                in_array($record['operation'], [
                'uapi:SSL/upload_key', 'uapi:SSL/upload_cert', 'uapi:SSL/fetch_key_and_cabundle_for_certificate',
                ], true)
            ) {
                ++$multipart;
                self::assertSame($record['operation'] . '#POST', $record['variant']);
                self::assertSame('POST', $record['method']);
            }
            if (in_array($record['operation'], ['uapi:SSL/delete_key', 'uapi:SSL/delete_cert'], true)) {
                ++$deletions;
                self::assertSame($record['operation'] . '#GET', $record['variant']);
                self::assertSame('POST', $record['method']);
            }
        }
        self::assertSame(6, $multipart);
        self::assertSame(6, $deletions);
        self::assertSame([], $server->keys);
        self::assertSame([], $server->certificates);
        self::assertSame(2, $server->counts['upload_key'] ?? 0);
        self::assertSame(2, $server->counts['upload_cert'] ?? 0);
        self::assertSame(2, $server->counts['fetch_key_and_cabundle_for_certificate'] ?? 0);
        self::assertSame(4, $server->counts['delete_key'] ?? 0);
        self::assertSame(2, $server->counts['delete_cert'] ?? 0);
        self::assertSame(2, $server->counts['generate_key'] ?? 0);
        self::assertSame(2, $server->counts['fetch_cert_info'] ?? 0);
        self::assertSame(2, $server->counts['fetch_certificates_for_fqdns'] ?? 0);
        self::assertCount(31, $report['probes']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['BEGIN', 'PRIVATE', 'owned-key-', 'owned-cert-', 'fixture-token'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        $before = array_sum($server->counts);
        self::assertSame('failed', $fixture->run()['status']);
        self::assertSame($before, array_sum($server->counts));
    }

    #[DataProvider('falseAttributions')]
    public function testAssessmentRejectsFalseSourceVariantOrActualMethod(string $key, string $value): void
    {
        $fixture = self::fixture(new SslSectionServer());
        $report = $fixture->run();
        self::assertSame('passed', $report['status']);
        $records = $report['probes'];
        foreach ($records as &$record) {
            if ($record['record']['operation'] === 'uapi:SSL/upload_key') {
                $record['record'][$key] = $value;
                break;
            }
        }
        (new ReflectionProperty($fixture, 'records'))->setValue($fixture, $records);
        $assessment = (new ReflectionMethod($fixture, 'report'))->invoke($fixture);
        self::assertIsArray($assessment);
        self::assertSame('failed', $assessment['status']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function falseAttributions(): iterable
    {
        yield 'source variant' => ['variant', 'uapi:SSL/upload_key#GET'];
        yield 'actual method' => ['method', 'GET'];
        yield 'operation identity' => ['operation', 'uapi:SSL/unreviewed'];
    }

    public function testConfirmedUploadWithMalformedPayloadStillCleansExactOriginalIdentity(): void
    {
        $server = new SslSectionServer('key_payload');
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->keys);
        self::assertSame(2, $server->counts['delete_key'] ?? 0);
        self::assertSame(0, $server->counts['upload_cert'] ?? 0);
    }

    public function testFailedCreationNeverAdoptsReturnedIdentityOrStartsSecondRoute(): void
    {
        $server = new SslSectionServer('key_api');
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(1, $server->counts['upload_key'] ?? 0);
        self::assertSame(0, $server->counts['delete_key'] ?? 0);
        self::assertSame(0, $server->counts['upload_cert'] ?? 0);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['key']);
    }

    public function testFailedPreflightRetainsFailedEvidenceWithoutMutation(): void
    {
        $server = new SslSectionServer('preflight');
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(0, $server->counts['upload_key'] ?? 0);
        self::assertCount(5, $report['probes']);
    }

    #[DataProvider('uncertainCreations')]
    public function testAmbiguousOriginalUploadIdentityIsNeverAdopted(string $mode): void
    {
        $server = new SslSectionServer($mode);
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['key']);
        self::assertSame(1, $server->counts['upload_key'] ?? 0);
        self::assertSame(0, $server->counts['delete_key'] ?? 0);
    }

    /** @return iterable<string,array{string}> */
    public static function uncertainCreations(): iterable
    {
        yield 'missing ID' => ['key_missing_id'];
        yield 'duplicate identities' => ['key_duplicate'];
    }

    public function testFetchFailureStillCleansEveryConfirmedOwnedResource(): void
    {
        $server = new SslSectionServer('fetch');
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame([], $server->keys);
        self::assertSame([], $server->certificates);
        self::assertSame(2, $server->counts['delete_key'] ?? 0);
        self::assertSame(2, $server->counts['delete_cert'] ?? 0);
    }

    public function testCleanupFailureRetainsFailedEvidenceAndNeverRetriesDelete(): void
    {
        $server = new SslSectionServer('cert_cleanup');
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['cert']);
        self::assertSame([], $server->keys);
        self::assertCount(1, $server->certificates);
        self::assertSame(1, $server->counts['delete_cert'] ?? 0);
        self::assertSame(1, $server->counts['upload_key'] ?? 0);
    }

    public function testUnrelatedPreexistingStoredKeyIsRetained(): void
    {
        $server = new SslSectionServer();
        $server->keys['existing'] = ['id' => 'existing', 'modulus' => 'deadbeef', 'pem' => 'not read'];
        self::assertSame('passed', self::fixture($server)->run()['status']);
        self::assertSame(['existing'], array_keys($server->keys));
    }

    #[DataProvider('identifiedEcdsaInventories')]
    public function testUnrelatedEcdsaInventoryDoesNotRequireRsaModulus(
        string $function,
        string $curve,
        bool $nullModulus,
    ): void {
        $server = new SslSectionServer();
        $row = ['id' => 'preexisting-ecdsa', 'friendly_name' => 'existing unrelated ECDSA',
            'key_algorithm' => 'id-ecPublicKey', 'ecdsa_curve_name' => $curve,
            'ecdsa_public' => '02' . str_repeat('a1', $curve === 'prime256v1' ? 32 : 48)];
        if ($nullModulus) {
            $row['modulus'] = null;
        }
        $server->existingInventory[$function] = [$row];
        $report = self::fixture($server)->run();
        self::assertSame('passed', $report['status']);
        self::assertSame([$row], $server->existingInventory[$function]);
        self::assertSame(2, $server->counts['upload_key'] ?? 0);
        self::assertSame(2, $server->counts['upload_cert'] ?? 0);
        self::assertSame(4, $server->counts['delete_key'] ?? 0);
        self::assertSame(2, $server->counts['delete_cert'] ?? 0);
        self::assertSame([], $server->keys);
        self::assertSame([], $server->certificates);
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function identifiedEcdsaInventories(): iterable
    {
        foreach (['list_keys', 'list_certs'] as $function) {
            foreach (['prime256v1', 'secp384r1'] as $curve) {
                foreach ([false, true] as $nullModulus) {
                    yield "$function $curve " . ($nullModulus ? 'null' : 'omitted')
                        => [$function, $curve, $nullModulus];
                }
            }
        }
    }

    /** @param array<string,mixed> $identity */
    #[DataProvider('unidentifiedInventories')]
    public function testUnidentifiableInventoryStopsBeforeAllMutations(string $function, array $identity): void
    {
        $server = new SslSectionServer();
        $row = ['id' => 'ambiguous-existing', 'friendly_name' => 'existing unrelated identity', ...$identity];
        $server->existingInventory[$function] = [$row];
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertCount(5, $report['probes']);
        foreach (
            ['upload_key', 'upload_cert', 'fetch_key_and_cabundle_for_certificate', 'delete_key', 'delete_cert'] as $op
        ) {
            self::assertSame(0, $server->counts[$op] ?? 0);
        }
        foreach ($report['resources'] as $resources) {
            self::assertSame(
                ['key' => 'not_started', 'cert' => 'not_started', 'generated_key' => 'not_started'],
                $resources,
            );
        }
        self::assertSame([$row], $server->existingInventory[$function]);
    }

    /** @return iterable<string,array{string,array<string,mixed>}> */
    public static function unidentifiedInventories(): iterable
    {
        $ecdsa = ['key_algorithm' => 'id-ecPublicKey', 'ecdsa_curve_name' => 'prime256v1', 'ecdsa_public' => '02a1'];
        foreach (['list_keys', 'list_certs'] as $function) {
            foreach ([false, true] as $nullModulus) {
                foreach (
                    [
                    'unknown algorithm' => [...$ecdsa, 'key_algorithm' => 'unknown'],
                    'RSA without modulus' => [...$ecdsa, 'key_algorithm' => 'rsaEncryption'],
                    'missing algorithm' => ['ecdsa_curve_name' => 'prime256v1', 'ecdsa_public' => '02a1'],
                    'missing curve' => ['key_algorithm' => 'id-ecPublicKey', 'ecdsa_public' => '02a1'],
                    'unknown curve' => [...$ecdsa, 'ecdsa_curve_name' => 'unknown'],
                    'empty public point' => [...$ecdsa, 'ecdsa_public' => ''],
                    'missing public point' => ['key_algorithm' => 'id-ecPublicKey', 'ecdsa_curve_name' => 'prime256v1'],
                    'nonhex public point' => [...$ecdsa, 'ecdsa_public' => 'not-hex'],
                    ] as $name => $identity
                ) {
                    if ($nullModulus) {
                        $identity['modulus'] = null;
                    }
                    yield "$function $name " . ($nullModulus ? 'null' : 'omitted') => [$function, $identity];
                }
            }
        }
    }

    public function testKnownOwnedUploadsContinueWithUnknownFieldsButEvidenceStillFails(): void
    {
        $server = new SslSectionServer('upload_unknown');
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertCount(31, $report['probes']);
        self::assertSame(2, $server->counts['fetch_key_and_cabundle_for_certificate'] ?? 0);
        self::assertSame(2, $server->counts['upload_key'] ?? 0);
        self::assertSame(2, $server->counts['upload_cert'] ?? 0);
        self::assertSame(4, $server->counts['delete_key'] ?? 0);
        self::assertSame(2, $server->counts['delete_cert'] ?? 0);
        self::assertSame([], $server->keys);
        self::assertSame([], $server->certificates);
        $uploads = 0;
        foreach ($report['probes'] as $probe) {
            if (in_array($probe['phase'], ['upload_key', 'upload_cert'], true)) {
                ++$uploads;
                self::assertSame('passed', $probe['expectation']);
                self::assertSame('success', $probe['record']['api_outcome']);
                self::assertSame('passed', $probe['record']['payload_validation']);
                self::assertSame([['path' => '$[].*', 'kind' => 'unknown_field']], $probe['record']['discrepancies']);
            }
        }
        self::assertSame(4, $uploads);
        foreach ($report['resources'] as $resources) {
            self::assertSame(['key' => 'cleaned', 'cert' => 'cleaned', 'generated_key' => 'cleaned'], $resources);
        }
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('unreviewed_fixture_field', $encoded);
        self::assertStringNotContainsString('unpersisted fixture value', $encoded);
    }

    public function testPreflightUnknownFieldsStillPreventEveryMutation(): void
    {
        $server = new SslSectionServer('preflight_unknown');
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertCount(5, $report['probes']);
        self::assertSame(0, $server->counts['upload_key'] ?? 0);
        self::assertSame(0, $server->counts['upload_cert'] ?? 0);
        self::assertSame(0, $server->counts['fetch_key_and_cabundle_for_certificate'] ?? 0);
        foreach ($report['resources'] as $resources) {
            self::assertSame(
                ['key' => 'not_started', 'cert' => 'not_started', 'generated_key' => 'not_started'],
                $resources,
            );
        }
    }

    public function testUnknownFieldsNeverPermitContinuationAfterTypedPayloadFailure(): void
    {
        $server = new SslSectionServer('key_payload_unknown');
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame(2, $server->counts['upload_key'] ?? 0);
        self::assertSame(0, $server->counts['upload_cert'] ?? 0);
        self::assertSame(0, $server->counts['fetch_key_and_cabundle_for_certificate'] ?? 0);
        self::assertSame(2, $server->counts['delete_key'] ?? 0);
        self::assertSame([], $server->keys);
        foreach ($report['probes'] as $probe) {
            if ($probe['phase'] === 'upload_key') {
                self::assertSame('failed', $probe['record']['payload_validation']);
                self::assertSame([
                ['path' => '$[].*', 'kind' => 'unknown_field'],
                    ['path' => '$[].created', 'kind' => 'type_mismatch']], $probe['record']['discrepancies']);
            }
        }
    }

    #[DataProvider('uncertainGeneratedKeys')]
    public function testUnconfirmedGeneratedKeyNeverAdoptsLaterInventoryOrRetries(string $mode): void
    {
        $server = new SslSectionServer($mode);
        if ($mode === 'generated_existing') {
            $server->keys['existing'] = ['id' => 'existing', 'modulus' => 'cafe', 'pem' => 'not read'];
        }
        $report = self::fixture($server)->run();
        self::assertSame('failed', $report['status']);
        self::assertSame('uncertain', $report['resources']['direct-uapi']['generated_key']);
        self::assertSame(1, $server->counts['generate_key'] ?? 0);
        self::assertSame(1, $server->counts['delete_key'] ?? 0, 'Only the confirmed uploaded key is deleted.');
        self::assertArrayHasKey('owned-generated-key-direct', $server->keys);
        self::assertSame('not_started', $report['resources']['bridged-uapi']['key']);
        self::assertContains('unresolved_ownership', $report['failures']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE', $json);
        self::assertStringNotContainsString('owned-generated-key', $json);
    }

    /** @return iterable<string,array{string}> */
    public static function uncertainGeneratedKeys(): iterable
    {
        foreach (
            ['generated_lost', 'generated_api', 'generated_missing_id', 'generated_wrong_modulus',
            'generated_payload', 'generated_existing'] as $mode
        ) {
            yield $mode => [$mode];
        }
    }

    public function testMissingOptInPreventsAnyRequest(): void
    {
        putenv('WHM_API_LIVE_MUTATIONS=0');
        $server = new SslSectionServer();
        self::assertSame('failed', self::fixture($server)->run()['status']);
        self::assertSame([], $server->counts);
    }

    private static function fixture(SslSectionServer $server): SslSectionFixture
    {
        return new SslSectionFixture($server, ['whm' => 'fixture-token', 'cpanel' => 'fixture-token'], [
            'direct-uapi' => SslSectionMaterial::generate(),
            'bridged-uapi' => SslSectionMaterial::generate(),
        ], [
            'revision' => str_repeat('a', 40), 'source_hashes' => ['whm1' => str_repeat('b', 64),
                'uapi' => str_repeat('c', 64)], 'map_sha256' => str_repeat('d', 64),
            'generator_tree' => str_repeat('e', 40), 'overlay_sha256' => str_repeat('f', 64),
        ]);
    }
}
