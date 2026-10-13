<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Live;

use Closure;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\DiskPartition;
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
        $hostname = self::executeSafely(fn (): string => $this->whm->server()->hostname());

        self::assertTrue($hostname === 'test.oblak.host', 'The server hostname must match the approved target.');
        $this->assertWhmMetadata('gethostname');
    }

    public function testLoadAverageContainsThreeRealMeasurementsAndRecordsWhmMetadata(): void
    {
        $load = self::executeSafely(fn () => $this->whm->server()->loadAverage());

        self::assertLoadMeasurement($load->one, 'one');
        self::assertLoadMeasurement($load->five, 'five');
        self::assertLoadMeasurement($load->fifteen, 'fifteen');
        $this->assertWhmMetadata('systemloadavg');
    }

    public function testDiskUsageContainsACompletePartitionAndRecordsWhmMetadata(): void
    {
        $usage = self::executeSafely(fn () => $this->whm->server()->diskUsage());

        self::assertTrue($usage->partitions !== null, 'The server must supply its partition list.');
        self::assertGreaterThan(0, count($usage), 'The approved server must report at least one partition.');
        $completeInodeSampleFound = false;
        foreach ($usage as $partition) {
            $hasCompleteInodes = self::assertPartitionSample($partition);
            $completeInodeSampleFound = $completeInodeSampleFound || $hasCompleteInodes;
        }

        self::assertTrue(
            $completeInodeSampleFound,
            'At least one partition must supply all four numeric inode measurements.',
        );
        $this->assertWhmMetadata('getdiskusage');
    }

    /** Check all observed fields and report whether this record has usable inode counters. */
    private static function assertPartitionSample(DiskPartition $sample): bool
    {
        $payload = $sample->toArray();
        $fields = [
            'device', 'disk', 'filesystem', 'mount', 'available', 'total', 'used', 'percentage',
            'inodes_available', 'inodes_total', 'inodes_used', 'inodes_ipercentage',
        ];
        foreach ($fields as $field) {
            self::assertTrue(
                array_key_exists($field, $payload),
                'Each partition must retain the supplied ' . $field . ' field, including explicit null.',
            );
        }

        $labels = [
            'device' => $sample->device,
            'disk' => $sample->disk,
            'filesystem' => $sample->filesystem,
            'mount' => $sample->mount,
        ];
        foreach ($labels as $field => $value) {
            self::assertTrue($value !== null && $value !== '', 'The partition sample must supply ' . $field . '.');
        }
        $measurements = [
            'available' => $sample->available,
            'total' => $sample->total,
            'used' => $sample->used,
            'percentage' => $sample->percentage,
        ];
        foreach ($measurements as $field => $value) {
            self::assertTrue(
                $value !== null,
                'The partition sample must supply numeric ' . $field . '.',
            );
        }

        return $sample->inodesAvailable !== null
            && $sample->inodesTotal !== null
            && $sample->inodesUsed !== null
            && $sample->inodesIpercentage !== null;
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
