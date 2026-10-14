<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tests\Support\LiveCurlClient;
use HostingPal\WhmApiClient\Tests\Support\LiveTestEnvironment;
use HostingPal\WhmApiClient\WhmClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** Exercise the reviewed server operations on the explicitly approved test server. */
#[Group('live')]
final class WhmServerLiveTest extends TestCase
{
    private WhmClient $whm;

    protected function setUp(): void
    {
        try {
            $tokens = LiveTestEnvironment::fromEnvironment();
        } catch (RuntimeException $exception) {
            self::fail($exception->getMessage());
        }

        $factory = new Psr17Factory();
        $client = self::executeSafely(static fn (): Client => new Client([
            'host' => 'test.oblak.host',
            'port' => 2087,
            'authType' => 'whm',
            'username' => 'root',
            'token' => $tokens['whm'],
        ], new HttpTransport(new LiveCurlClient(), $factory, $factory)));
        $this->whm = $client->whm();
    }

    public function testHostnameMatchesTheApprovedServerAndRecordsWhmMetadata(): void
    {
        $hostname = self::executeSafely(fn (): string => $this->whm->server()->getHostname());

        self::assertTrue($hostname === 'test.oblak.host', 'The server hostname must match the approved target.');
        $this->assertWhmMetadata('gethostname');
    }

    public function testLoadAverageContainsThreeRealMeasurementsAndRecordsWhmMetadata(): void
    {
        $load = self::executeSafely(fn (): array => $this->whm->server()->getLoadAverage());

        self::assertLoadMeasurement($load['one'] ?? null, 'one');
        self::assertLoadMeasurement($load['five'] ?? null, 'five');
        self::assertLoadMeasurement($load['fifteen'] ?? null, 'fifteen');
        $this->assertWhmMetadata('systemloadavg');
    }

    public function testDiskUsageContainsACompletePartitionAndRecordsWhmMetadata(): void
    {
        $usage = self::executeSafely(fn (): array => $this->whm->server()->getDiskUsage());

        $partitions = $usage['partition'] ?? null;
        self::assertTrue($partitions !== null, 'The server must supply its partition list.');
        self::assertGreaterThan(0, count($partitions), 'The approved server must report at least one partition.');
        $completeInodeSampleFound = false;
        foreach ($partitions as $partition) {
            $hasCompleteInodes = self::assertPartitionSample($partition);
            $completeInodeSampleFound = $completeInodeSampleFound || $hasCompleteInodes;
        }

        self::assertTrue(
            $completeInodeSampleFound,
            'At least one partition must supply all four numeric inode measurements.',
        );
        $this->assertWhmMetadata('getdiskusage');
    }

    /**
     * Check all observed fields and report whether this record has usable inode counters.
     *
     * @param array<string, int|string|null> $sample
     */
    private static function assertPartitionSample(array $sample): bool
    {
        $fields = [
            'device', 'disk', 'filesystem', 'mount', 'available', 'total', 'used', 'percentage',
            'inodes_available', 'inodes_total', 'inodes_used', 'inodes_ipercentage',
        ];
        foreach ($fields as $field) {
            self::assertTrue(
                array_key_exists($field, $sample),
                'Each partition must retain the supplied ' . $field . ' field, including explicit null.',
            );
        }

        foreach (['device', 'disk', 'filesystem', 'mount'] as $field) {
            $value = $sample[$field] ?? null;
            self::assertTrue(
                is_string($value) && $value !== '',
                'The partition sample must supply ' . $field . '.',
            );
        }
        foreach (['available', 'total', 'used', 'percentage'] as $field) {
            self::assertTrue(
                is_int($sample[$field] ?? null),
                'The partition sample must supply numeric ' . $field . '.',
            );
        }

        return is_int($sample['inodes_available'] ?? null)
            && is_int($sample['inodes_total'] ?? null)
            && is_int($sample['inodes_used'] ?? null)
            && is_int($sample['inodes_ipercentage'] ?? null);
    }

    /** Assert only predicates so unexpected response values never appear in failure output. */
    private function assertWhmMetadata(string $command): void
    {
        $metadata = $this->whm->responseMeta();

        self::assertTrue($metadata !== null, 'A successful WHM response must retain metadata.');
        self::assertTrue($metadata->httpStatus === 200, 'The WHM HTTP status must be 200.');
        self::assertTrue(($metadata->whm['result'] ?? null) === 1, 'The WHM result must be successful.');
        self::assertTrue(($metadata->whm['command'] ?? null) === $command, 'Metadata must identify the operation.');
        self::assertTrue($metadata->uapi === null, 'A WHM operation must not acquire UAPI metadata.');
    }

    private static function assertLoadMeasurement(int|float|string|null $value, string $field): void
    {
        self::assertTrue(
            is_numeric($value) && is_finite((float) $value) && (float) $value >= 0,
            'The server must supply a finite, nonnegative ' . $field . '-minute load measurement.',
        );
    }

    /**
     * Discard transport/API exception messages and chains before PHPUnit formats failures.
     *
     * @template TResult
     * @param Closure(): TResult $operation
     * @return TResult
     */
    private static function executeSafely(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            $metadata = $exception instanceof ApiException ? $exception->metadata : null;
            $result = $metadata?->whm['result'] ?? null;
            self::fail(sprintf(
                'Live WHM operation failed (%s; HTTP %s; WHM result %s); response details suppressed.',
                $exception::class,
                $metadata === null ? 'unavailable' : (string) $metadata->httpStatus,
                is_int($result) ? (string) $result : 'unavailable',
            ));
        }
    }
}
