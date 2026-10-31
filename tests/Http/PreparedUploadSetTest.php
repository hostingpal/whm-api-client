<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Http;

use HostingPal\WhmApiClient\Http\PreparedUploadSet;
use HostingPal\WhmApiClient\Tests\Support\UploadProbeStream;
use HostingPal\WhmApiClient\Tests\Support\UploadReleaseProbe;
use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class PreparedUploadSetTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }

    public function testRepeatedStringsHaveIndependentCursorsAndBinaryBytes(): void
    {
        $upload = Upload::fromString('file.bin', "a\0\xffb");
        $set = PreparedUploadSet::prepare([$upload, $upload, Upload::fromString('empty', '')]);
        [$first, $second, $empty] = $set->sources();
        self::assertSame(4, $first->size());
        self::assertSame('file.bin', $first->filename());
        self::assertSame('application/octet-stream', $first->contentType());
        self::assertSame("a\0", $first->read(2));
        self::assertSame("a\0\xffb", $second->read(99));
        self::assertSame("\xffb", $first->read(99));
        self::assertSame('', $first->read(1));
        self::assertSame(4, $first->size());
        self::assertSame(0, $empty->size());
        self::assertSame('', $empty->read(1));
    }

    public function testRepeatedPathsUseIndependentOwnedHandlesAndReleaseIsIdempotent(): void
    {
        $path = $this->file('abcdef');
        $upload = Upload::fromPath($path);
        $before = $this->openHandles($path);
        $set = PreparedUploadSet::prepare([$upload, $upload]);
        [$first, $second] = $set->sources();
        $opened = array_diff_key($this->openHandles($path), $before);
        self::assertCount(2, $opened);
        foreach ($opened as $handle) {
            self::assertSame(0, ftell($handle));
        }
        self::assertSame('ab', $first->read(2));
        self::assertSame('abcdef', $second->read(100));
        $set->release();
        $set->release();
        foreach ($opened as $handle) {
            self::assertFalse(is_resource($handle));
        }
        self::assertSame('abcdef', file_get_contents($path));
    }

    public function testSetDestructionReleasesOwnedHandlesEvenWhenSourceIsRetained(): void
    {
        $path = $this->file('bytes');
        $set = PreparedUploadSet::prepare([Upload::fromPath($path)]);
        $source = $set->sources()[0];
        $handles = $this->openHandles($path);
        unset($set);
        foreach ($handles as $handle) {
            self::assertFalse(is_resource($handle));
        }
        $this->expectException(RuntimeException::class);
        $source->read(1);
    }

    public function testBorrowedSourceStartsAtCurrentPositionAndIsNeverReleasedByLibrary(): void
    {
        $stream = new UploadProbeStream('abcdef');
        $upload = Upload::fromStream('file', $stream);
        $stream->seek(2);
        $set = PreparedUploadSet::prepare([$upload]);
        $source = $set->sources()[0];
        self::assertSame(4, $source->size());
        self::assertSame(0, $stream->reads);
        self::assertSame(1, $stream->seeks);
        self::assertLessThanOrEqual(4, $stream->metadataCalls);
        self::assertSame('cde', $source->read(3));
        $set->release();
        unset($set, $source);
        self::assertSame(5, $stream->tell());
        self::assertSame(0, $stream->closes);
        self::assertSame(0, $stream->detaches);
        self::assertSame(1, $stream->seeks);
        $again = PreparedUploadSet::prepare([$upload]);
        self::assertSame(1, $again->sources()[0]->size());
        self::assertSame('f', $again->sources()[0]->read(10));
        $again->release();
        self::assertSame(0, PreparedUploadSet::prepare([$upload])->sources()[0]->size());
    }

    public function testNonseekableUnknownSizeUsesExactExplicitRemainderWithoutConsumption(): void
    {
        $stream = new UploadProbeStream("\0\xff");
        $stream->seekable = false;
        $stream->reportedSize = null;
        $stream->throwTell = true;
        $set = PreparedUploadSet::prepare([Upload::fromStream('file', $stream, size: 2)]);
        self::assertSame(2, $set->sources()[0]->size());
        self::assertSame(0, $stream->reads);
        self::assertSame(0, $stream->seeks);
        self::assertSame("\0\xff", $set->sources()[0]->read(20));
        $set->release();
        self::assertSame(0, $stream->closes);
    }

    public function testExplicitSizeStillChecksDerivableNonseekableRemainder(): void
    {
        $stream = new UploadProbeStream('abcdef');
        $stream->seek(2);
        $stream->seekable = false;
        $set = PreparedUploadSet::prepare([Upload::fromStream('file', $stream, size: 4)]);
        self::assertSame(4, $set->sources()[0]->size());
        self::assertSame(0, $stream->reads);
        self::assertSame('cdef', $set->sources()[0]->read(10));
    }

    #[DataProvider('invalidLengths')]
    public function testRejectsInvalidOrUnknownRemainderWithoutReading(
        ?int $total,
        ?int $position,
        ?int $asserted,
        bool $throwTell = false,
    ): void {
        $stream = new UploadProbeStream('abcdef');
        $stream->reportedSize = $total;
        $stream->reportedPosition = $position;
        $stream->throwTell = $throwTell;
        $exception = null;
        try {
            PreparedUploadSet::prepare([Upload::fromStream('file', $stream, size: $asserted)]);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        self::assertNotNull($exception);
        self::assertSame(RuntimeException::class, $exception::class);
        self::assertNull($exception->getPrevious());
        self::assertSame(0, $stream->reads);
        self::assertSame(0, $stream->seeks);
        self::assertSame(0, $stream->closes);
        self::assertSame(0, $stream->detaches);
    }

    /** @return iterable<string, array{0: int|null, 1: int|null, 2: int|null, 3?: bool}> */
    public static function invalidLengths(): iterable
    {
        yield 'unknown' => [null, 0, null];
        yield 'unknown EOF not zero' => [null, 6, null];
        yield 'unknown cursor' => [6, null, null, true];
        yield 'negative total' => [-1, 0, 0];
        yield 'negative cursor' => [6, -1, 7];
        yield 'beyond total asserted' => [6, 7, 0];
        yield 'beyond total derived' => [6, 7, null];
        yield 'prefix assertion' => [6, 2, 2];
        yield 'excess assertion' => [6, 2, 5];
    }

    public function testExplicitZeroAndUnavailableMetadataRemainDistinctFromUnknown(): void
    {
        $stream = new UploadProbeStream('');
        $stream->reportedSize = null;
        $stream->throwTell = true;
        $stream->throwSize = true;
        $set = PreparedUploadSet::prepare([Upload::fromStream('file', $stream, size: 0)]);
        self::assertSame(0, $set->sources()[0]->size());
        self::assertSame('', $set->sources()[0]->read(1));
        self::assertSame(0, $stream->reads);
    }

    public function testDuplicateBorrowedIdentityRejectsBeforeAnyFileOpenOrMetadataCall(): void
    {
        $path = $this->file('bytes');
        $stream = new UploadProbeStream('abcdef');
        try {
            PreparedUploadSet::prepare([
                Upload::fromPath($path . '-missing'),
                Upload::fromStream('one', $stream),
                Upload::fromStream('two', $stream),
            ]);
            self::fail('Duplicate borrowed source accepted.');
        } catch (InvalidArgumentException) {
            self::assertCount(0, $this->openHandles($path));
            self::assertSame(0, $stream->metadataCalls);
            self::assertSame(0, $stream->reads);
        }
    }

    public function testMalformedLateDescriptorRejectsBeforeOpeningAnEarlierMissingPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PreparedUploadSet::prepare([Upload::fromPath('/missing/upload-source-preflight'), new \stdClass()]);
    }

    public function testSparseDescriptorListRejectsBeforeAnySourceMetadata(): void
    {
        $stream = new UploadProbeStream('bytes');
        try {
            PreparedUploadSet::prepare([1 => Upload::fromStream('file', $stream)]);
            self::fail('Sparse list accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $stream->metadataCalls);
            self::assertSame(0, $stream->reads);
        }
    }

    public function testSameBorrowedDescriptorCannotOccurTwice(): void
    {
        $stream = new UploadProbeStream('bytes');
        $upload = Upload::fromStream('file', $stream);
        $this->expectException(InvalidArgumentException::class);
        PreparedUploadSet::prepare([$upload, $upload]);
    }

    public function testKnownNonseekableRemainderNeedsNoAssertion(): void
    {
        $stream = new UploadProbeStream('abcdef');
        $stream->seek(2);
        $stream->seekable = false;
        $set = PreparedUploadSet::prepare([Upload::fromStream('file', $stream)]);
        self::assertSame(4, $set->sources()[0]->size());
        self::assertSame(0, $stream->reads);
        self::assertSame(1, $stream->seeks);
    }

    public function testUnknownLengthAtActualEofStillRequiresAnAssertion(): void
    {
        $stream = new UploadProbeStream('');
        $stream->reportedSize = null;
        $stream->read(1);
        $stream->reads = 0;
        self::assertTrue($stream->eof());
        $exception = null;
        try {
            PreparedUploadSet::prepare([Upload::fromStream('file', $stream)]);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        self::assertNotNull($exception);
        self::assertSame(RuntimeException::class, $exception::class);
        self::assertNull($exception->getPrevious());
        self::assertSame(0, $stream->reads);
        self::assertSame(0, $stream->seeks);
    }

    public function testPreparedSourceExportsHideAllSourceState(): void
    {
        $secret = 'unique-prepared-private-marker';
        $set = PreparedUploadSet::prepare([
            Upload::fromString($secret, $secret),
            Upload::fromStream($secret, new UploadProbeStream($secret)),
        ]);
        ob_start();
        var_dump($set);
        $dump = ob_get_clean();
        self::assertIsString($dump);
        foreach ([$dump, print_r($set, true), var_export($set, true)] as $export) {
            self::assertStringNotContainsString($secret, $export);
            self::assertStringNotContainsString('UploadProbeStream', $export);
        }
    }

    public function testUnreadableLocalFileRejectsBeforeReading(): void
    {
        $path = $this->file('bytes');
        chmod($path, 0000);
        try {
            if (is_readable($path)) {
                self::markTestSkipped('Current user can read mode-0000 files.');
            }
            $this->expectException(RuntimeException::class);
            PreparedUploadSet::prepare([Upload::fromPath($path)]);
        } finally {
            chmod($path, 0600);
        }
    }

    public function testMissingPathFailureRedactsFullTraceArguments(): void
    {
        $before = ini_set('zend.exception_ignore_args', '0');
        try {
            $exception = null;
            try {
                PreparedUploadSet::prepare([Upload::fromPath('/private-path-marker/missing', 'private-name-marker')]);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }
            self::assertNotNull($exception);
            self::assertSame(RuntimeException::class, $exception::class);
            self::assertNull($exception->getPrevious());
            $trace = print_r(array_slice($exception->getTrace(), 0, 2), true);
            self::assertStringNotContainsString('private-path-marker', $trace);
            self::assertStringNotContainsString('private-name-marker', $trace);
        } finally {
            ini_set('zend.exception_ignore_args', $before === false ? '1' : $before);
        }
    }

    public function testLaterMissingFileClosesEarlierOwnedHandleAndPreservesBorrowedCursor(): void
    {
        $path = $this->file('bytes');
        $stream = new UploadProbeStream('abcdef');
        $stream->seek(2);
        $exception = null;
        try {
            PreparedUploadSet::prepare([
                Upload::fromPath($path),
                Upload::fromStream('borrowed', $stream),
                Upload::fromPath($path . '-missing'),
            ]);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        self::assertNotNull($exception);
        self::assertSame(RuntimeException::class, $exception::class);
        self::assertNull($exception->getPrevious());
        self::assertCount(0, $this->openHandles($path));
        self::assertSame(2, $stream->tell());
        self::assertSame(0, $stream->reads);
        self::assertSame(1, $stream->seeks);
        self::assertStringNotContainsString($path, (string) $exception);
    }

    public function testBorrowedLengthFailureClosesPreviouslyOpenedFile(): void
    {
        $path = $this->file('bytes');
        $stream = new UploadProbeStream('abcdef');
        $exception = null;
        try {
            PreparedUploadSet::prepare([Upload::fromPath($path), Upload::fromStream('file', $stream, size: 3)]);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        self::assertNotNull($exception);
        self::assertSame(RuntimeException::class, $exception::class);
        self::assertNull($exception->getPrevious());
        self::assertCount(0, $this->openHandles($path));
        self::assertSame(0, $stream->tell());
        self::assertSame(0, $stream->reads);
    }

    public function testUnreadableAndDetachedBorrowedSourcesRejectWithoutConsumption(): void
    {
        $unreadable = new UploadProbeStream('bytes');
        $unreadable->readable = false;
        $detached = Stream::create('bytes');
        $resource = $detached->detach();
        try {
            foreach ([$unreadable, $detached] as $stream) {
                $exception = null;
                try {
                    PreparedUploadSet::prepare([Upload::fromStream('file', $stream, size: 5)]);
                } catch (RuntimeException $caught) {
                    $exception = $caught;
                }
                self::assertNotNull($exception);
                self::assertSame(RuntimeException::class, $exception::class);
                self::assertNull($exception->getPrevious());
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        self::assertSame(0, $unreadable->reads);
    }

    public function testSymlinkToRegularFileIsAllowed(): void
    {
        $path = $this->file('linked bytes');
        $link = $path . '-link';
        self::assertTrue(symlink($path, $link));
        $this->paths[] = $link;
        $set = PreparedUploadSet::prepare([Upload::fromPath($link)]);
        self::assertSame(12, $set->sources()[0]->size());
        self::assertSame('linked bytes', $set->sources()[0]->read(100));
    }

    public function testDirectoryRejectsAsNonregular(): void
    {
        $this->expectException(RuntimeException::class);
        PreparedUploadSet::prepare([Upload::fromPath(sys_get_temp_dir(), 'directory')]);
    }

    public function testFifoRejectsWithoutBlockingOrReading(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('POSIX FIFO support unavailable.');
        }
        $path = $this->file('');
        unlink($path);
        self::assertTrue(posix_mkfifo($path, 0600));
        $program = <<<'PHP'
require $argv[1];
try {
    \HostingPal\WhmApiClient\Http\PreparedUploadSet::prepare([
        \HostingPal\WhmApiClient\Upload::fromPath($argv[2]),
    ]);
    exit(1);
} catch (\RuntimeException $exception) {
    exit($exception->getPrevious() === null ? 0 : 2);
}
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $program, dirname(__DIR__, 2) . '/vendor/autoload.php', $path],
            [],
            $pipes,
        );
        self::assertIsResource($process);
        $deadline = hrtime(true) + 2_000_000_000;
        $status = ['running' => true, 'exitcode' => -1];
        try {
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);
        } finally {
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
        self::assertFalse($status['running'], 'Nonregular source preparation blocked past its deadline.');
        self::assertSame(0, $status['exitcode']);
    }

    public function testSourceReadErrorsNeverChainPrivateLowerLayerExceptions(): void
    {
        $before = ini_set('zend.exception_ignore_args', '0');
        try {
            $stream = new UploadProbeStream('private-stream-content-secret');
            $stream->throwRead = true;
            $set = PreparedUploadSet::prepare([Upload::fromStream('private-filename-secret', $stream)]);
            $exception = null;
            try {
                $set->sources()[0]->read(1);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }
            self::assertNotNull($exception);
            self::assertSame(RuntimeException::class, $exception::class);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(
                'private-stream-content-secret',
                (string) $exception . print_r(array_slice($exception->getTrace(), 0, 2), true),
            );
            self::assertStringNotContainsString(
                'private-filename-secret',
                (string) $exception . print_r(array_slice($exception->getTrace(), 0, 2), true),
            );
            $set->release();
            self::assertSame(0, $stream->closes);
        } finally {
            ini_set('zend.exception_ignore_args', $before === false ? '1' : $before);
        }
    }

    public function testBorrowedOverReadRejectsWithoutExposingSourceBytes(): void
    {
        $stream = new UploadProbeStream('private-overread-bytes');
        $stream->overRead = true;
        $set = PreparedUploadSet::prepare([Upload::fromStream('private-overread-name', $stream)]);
        self::assertSame(0, $stream->reads);
        $exception = null;
        try {
            $set->sources()[0]->read(1);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        self::assertNotNull($exception);
        self::assertSame(RuntimeException::class, $exception::class);
        self::assertNull($exception->getPrevious());
        self::assertStringNotContainsString('private-overread-', (string) $exception);
        self::assertSame(1, $stream->reads);
        $set->release();
        self::assertSame(0, $stream->closes);
    }

    public function testGenuineEmptyRegularFilePreparesWithoutReading(): void
    {
        $path = $this->file('');
        $set = PreparedUploadSet::prepare([Upload::fromPath($path)]);
        self::assertSame(0, $set->sources()[0]->size());
        foreach ($this->openHandles($path) as $handle) {
            self::assertSame(0, ftell($handle));
            self::assertFalse(feof($handle));
        }
        self::assertSame('', $set->sources()[0]->read(1));
        $set->release();
    }

    public function testRelativeColonFilenameUsesExplicitDotSlash(): void
    {
        $path = $this->file('colon bytes');
        $colon = $path . ':2026.csv';
        rename($path, $colon);
        array_pop($this->paths);
        $this->paths[] = $colon;
        $cwd = getcwd();
        self::assertIsString($cwd);
        try {
            chdir(dirname($colon));
            $set = PreparedUploadSet::prepare([Upload::fromPath('./' . basename($colon))]);
        } finally {
            chdir($cwd);
        }
        self::assertSame(basename($colon), $set->sources()[0]->filename());
        self::assertSame('colon bytes', $set->sources()[0]->read(100));
    }

    public function testSetRefusesSerializationWithoutInvalidatingSources(): void
    {
        foreach ([[], [Upload::fromString('private-set-name', 'private-set-bytes')]] as $uploads) {
            $set = PreparedUploadSet::prepare($uploads);
            $exception = null;
            try {
                serialize($set);
            } catch (Throwable $caught) {
                $exception = $caught;
            }
            self::assertNotNull($exception);
            self::assertSame(LogicException::class, $exception::class);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('private-set-', (string) $exception);
            if ($uploads !== []) {
                self::assertSame('private-set-bytes', $set->sources()[0]->read(100));
            }
        }
    }

    public function testForgedSetUnserializationCannotReleaseForeignObjects(): void
    {
        UploadReleaseProbe::$releases = 0;
        $key = "\0" . PreparedUploadSet::class . "\0sources";
        foreach ([[$key => [new UploadReleaseProbe()]], []] as $data) {
            $payload = 'O:' . strlen(PreparedUploadSet::class) . ':"' . PreparedUploadSet::class
                . '":' . count($data) . ':{';
            foreach ($data as $name => $value) {
                $payload .= serialize($name) . serialize($value);
            }
            $payload .= '}';
            $exception = null;
            $restored = null;
            try {
                $restored = unserialize($payload);
            } catch (Throwable $caught) {
                $exception = $caught;
            }
            unset($restored);
            self::assertSame(0, UploadReleaseProbe::$releases);
            self::assertNotNull($exception);
            self::assertSame(LogicException::class, $exception::class);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('private-foreign', (string) $exception);
        }
    }

    public function testUninitializedSetCanBeReleasedAndDestroyedSafely(): void
    {
        $set = (new ReflectionClass(PreparedUploadSet::class))->newInstanceWithoutConstructor();
        $set->release();
        self::assertSame([], $set->sources());
        unset($set);
    }

    /** @return array<int, resource> */
    private function openHandles(string $path): array
    {
        $handles = [];
        foreach (get_resources('stream') as $id => $resource) {
            if ((stream_get_meta_data($resource)['uri'] ?? null) === $path) {
                $handles[$id] = $resource;
            }
        }
        return $handles;
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-source-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->paths[] = $path;
        return $path;
    }
}
