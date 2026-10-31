<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Http;

use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Tests\Support\UploadProbeStream;
use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StreamingMultipartBodyTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
    }

    public function testIndependentFullBinaryBodyPreservesRepeatedInterleavedPartsAndEmptyBytes(): void
    {
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'same', 'value' => 'literal /path &+%'],
            ['name' => 'same', 'value' => Upload::fromString('ž space.bin', "\0\xff0", 'image/png')],
            ['name' => 'same', 'value' => ''],
            ['name' => 'empty', 'value' => Upload::fromString('empty', '')],
        ], static fn (): string => 'test-boundary');
        $expected = "--test-boundary\r\nContent-Disposition: form-data; name=\"same\"\r\n\r\nliteral /path &+%\r\n"
            . "--test-boundary\r\nContent-Disposition: form-data; name=\"same\"; filename=\"ž space.bin\"\r\n"
            . "Content-Type: image/png\r\n\r\n\0\xff0\r\n"
            . "--test-boundary\r\nContent-Disposition: form-data; name=\"same\"\r\n\r\n\r\n"
            . "--test-boundary\r\nContent-Disposition: form-data; name=\"empty\"; filename=\"empty\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n\r\n--test-boundary--\r\n";
        self::assertSame('multipart/form-data; boundary=test-boundary', $body->contentType());
        self::assertSame(strlen($expected), $body->getSize());
        $actual = '';
        while (!$body->eof()) {
            $bytes = $body->read(3);
            self::assertLessThanOrEqual(3, strlen($bytes));
            $actual .= $bytes;
        }
        self::assertSame($expected, $actual);
        self::assertSame(strlen($expected), $body->tell());
        self::assertSame(strlen($expected), $body->getSize());
        self::assertSame('', $body->read(9));
    }

    public function testEmptyPartListIsAClosingDelimiterAndDefaultBoundaryHas192RandomBits(): void
    {
        $body = StreamingMultipartEncoder::encode([], static fn (): string => 'test-boundary');
        self::assertSame("--test-boundary--\r\n", $body->getContents());
        $random = StreamingMultipartEncoder::encode([]);
        self::assertMatchesRegularExpression('/boundary=whm-api-[a-f0-9]{48}$/', $random->contentType());
        self::assertNotSame($random->contentType(), StreamingMultipartEncoder::encode([])->contentType());
    }

    public function testTextCollisionRegeneratesWithoutReadingTheFile(): void
    {
        $stream = new UploadProbeStream('bytes');
        $calls = 0;
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'text', 'value' => 'first-boundary'],
            ['name' => 'file', 'value' => Upload::fromStream('file', $stream)],
        ], static function () use (&$calls): string {
            return ++$calls === 1 ? 'first-boundary' : 'second-boundary';
        });
        self::assertSame(2, $calls);
        self::assertSame(0, $stream->reads);
        self::assertSame('multipart/form-data; boundary=second-boundary', $body->contentType());
    }

    public function testSizeAndConstructionDoNotConsumeAndBorrowedCursorIsPreserved(): void
    {
        $stream = new UploadProbeStream('prefixREST');
        $stream->seek(6);
        $stream->seekable = false;
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromStream('f', $stream)],
        ], static fn (): string => 'test-boundary');
        $size = $body->getSize();
        self::assertSame(0, $stream->reads);
        self::assertSame(6, $stream->tell());
        self::assertStringContainsString("\r\n\r\nREST\r\n", $body->getContents());
        self::assertSame($size, $body->getSize());
        $body->close();
        self::assertSame(10, $stream->tell());
        self::assertSame(0, $stream->closes);
        self::assertSame(0, $stream->detaches);
        self::assertSame(1, $stream->seeks);
    }

    public function testAssertedNonseekableRemainderDoesNotProbeForExcess(): void
    {
        $stream = new UploadProbeStream('bytesEXCESS');
        $stream->reportedSize = null;
        $stream->throwTell = true;
        $stream->seekable = false;
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromStream('f', $stream, size: 5)],
        ]);
        self::assertSame(0, $stream->reads);
        self::assertStringContainsString("\r\n\r\nbytes\r\n", $body->getContents());
        self::assertSame([5], $stream->requestedReadSizes);
        $stream->throwTell = false;
        self::assertSame(5, $stream->tell());
    }

    public function testIncrementalReadsRequestOnlyBoundedSourceChunks(): void
    {
        $stream = new UploadProbeStream(str_repeat('x', 40000));
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromStream('f', $stream)],
        ]);
        while (!$body->eof()) {
            self::assertLessThanOrEqual(71, strlen($body->read(71)));
        }
        self::assertGreaterThan(1, $stream->reads);
        foreach ($stream->requestedReadSizes as $readSize) {
            self::assertLessThanOrEqual(8192, $readSize);
        }
        self::assertSame(40000, array_sum($stream->requestedReadSizes));
    }

    public function testHugeRequestedReadReturnsBoundedBytesAndPreservesTheCompleteBody(): void
    {
        $bytes = str_repeat("\0\xffb0", 10000);
        $stream = new UploadProbeStream($bytes);
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromStream('binary', $stream)],
        ], static fn (): string => 'test-boundary');
        $expected = "--test-boundary\r\nContent-Disposition: form-data; name=\"f\"; filename=\"binary\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . $bytes . "\r\n--test-boundary--\r\n";
        $size = strlen($expected);
        self::assertSame($size, $body->getSize());
        self::assertSame(0, $stream->reads);

        $actual = $body->read(PHP_INT_MAX);
        self::assertNotSame('', $actual);
        self::assertLessThanOrEqual(8192, strlen($actual));
        self::assertFalse($body->eof());
        self::assertLessThan(strlen($bytes), $stream->tell());
        self::assertSame(strlen($actual), $body->tell());
        self::assertSame($size, $body->getSize());

        while (!$body->eof()) {
            $chunk = $body->read(PHP_INT_MAX);
            self::assertNotSame('', $chunk);
            self::assertLessThanOrEqual(8192, strlen($chunk));
            $actual .= $chunk;
            self::assertSame(strlen($actual), $body->tell());
            self::assertSame($size, $body->getSize());
        }
        self::assertSame($expected, $actual);
        self::assertSame(strlen($bytes), $stream->tell());
        self::assertSame($size, $body->tell());
    }

    #[DataProvider('collisionSplits')]
    public function testCollisionAcrossEveryReadSplitNeverEmitsTheDelimiter(int $chunk, int $read): void
    {
        $stream = new UploadProbeStream('prefix-test-boundary-suffix');
        $stream->maxReadLength = $chunk;
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromStream('f', $stream)],
        ], static fn (): string => 'test-boundary');
        $header = "--test-boundary\r\nContent-Disposition: form-data; name=\"f\"; filename=\"f\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n";
        self::assertSame($header, $body->read(strlen($header)));
        $emitted = '';
        try {
            while (!$body->eof()) {
                $emitted .= $body->read($read);
            }
            self::fail('A streamed delimiter must fail.');
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (RuntimeException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('test-boundary', $emitted);
            self::assertStringNotContainsString('prefix', $error->getMessage());
        }
        self::assertSame(0, $stream->closes);
        $this->expectException(RuntimeException::class);
        $body->assertHealthy();
    }

    /** @return iterable<string, array{int, int}> */
    public static function collisionSplits(): iterable
    {
        for ($chunk = 1; $chunk <= 27; ++$chunk) {
            foreach ([1, 2, 7, 64] as $read) {
                yield $chunk . '-' . $read => [$chunk, $read];
            }
        }
    }

    #[DataProvider('badParts')]
    public function testCompleteInputValidationPrecedesAnySourcePreparation(mixed $late): void
    {
        $stream = new UploadProbeStream('data');
        try {
            StreamingMultipartEncoder::encode([
                ['name' => 'f', 'value' => Upload::fromStream('f', $stream)],
                $late,
            ]);
            self::fail('Invalid later metadata must fail.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $stream->metadataCalls);
            self::assertSame(0, $stream->reads);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function badParts(): iterable
    {
        yield 'nonrecord' => ['bad'];
        yield 'injection' => [['name' => "f\r\nInjected: bad", 'value' => 'x']];
        yield 'empty name' => [['name' => '', 'value' => 'x']];
        yield 'integer name' => [['name' => 0, 'value' => 'x']];
        yield 'number' => [['name' => 'f', 'value' => 1]];
        yield 'extra key' => [['name' => 'f', 'value' => 'x', 'extra' => 'x']];
        yield 'missing value' => [['name' => 'f']];
    }

    public function testDuplicateBorrowedIdentityFailsBeforeMetadataOrOpeningFiles(): void
    {
        $stream = new UploadProbeStream('data');
        $upload = Upload::fromStream('f', $stream);
        $path = $this->file('data');
        try {
            StreamingMultipartEncoder::encode([
                ['name' => 'f', 'value' => Upload::fromPath($path)],
                ['name' => 'f', 'value' => $upload],
                ['name' => 'f', 'value' => $upload],
            ]);
            self::fail('Repeated stream identity must fail.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->handles($path));
            self::assertSame(0, $stream->metadataCalls);
        }
    }

    public function testTotalOverflowClosesOwnedFilesWithoutReadingAnySource(): void
    {
        $stream = new UploadProbeStream('data');
        $stream->reportedSize = PHP_INT_MAX;
        $path = $this->file('data');
        try {
            StreamingMultipartEncoder::encode([
                ['name' => 'f', 'value' => Upload::fromPath($path)],
                ['name' => 'g', 'value' => Upload::fromStream('g', $stream)],
            ]);
            self::fail('Overflow must fail locally.');
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (RuntimeException) {
            self::assertSame([], $this->handles($path));
            self::assertSame(0, $stream->reads);
            self::assertSame(0, $stream->tell());
        }
    }

    public function testLaterSourcePreparationFailureReleasesEarlierFiles(): void
    {
        $path = $this->file('data');
        $stream = new UploadProbeStream('bytes');
        $stream->reportedSize = null;
        try {
            StreamingMultipartEncoder::encode([
                ['name' => 'f', 'value' => Upload::fromPath($path)],
                ['name' => 'g', 'value' => Upload::fromStream('g', $stream)],
            ]);
            self::fail('Unknown length must fail.');
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (RuntimeException) {
            self::assertSame([], $this->handles($path));
            self::assertSame(0, $stream->reads);
        }
    }

    #[DataProvider('readFailures')]
    public function testReadFailureIsSafeRecordedAndClosesOwnedFiles(bool $throws): void
    {
        $stream = new UploadProbeStream('short');
        $stream->reportedSize = 20;
        $stream->throwRead = $throws;
        $path = $this->file('owned');
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => Upload::fromPath($path)],
            ['name' => 'g', 'value' => Upload::fromStream('private-filename', $stream)],
        ]);
        self::assertCount(1, $this->handles($path));
        self::assertSame('', (string) $body);
        self::assertSame([], $this->handles($path));
        $body->close();
        try {
            $body->assertHealthy();
            self::fail('String conversion failure must remain recorded after close.');
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (RuntimeException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private', $error->getMessage());
        }
        self::assertSame(0, $stream->closes);
    }

    /** @return iterable<string, array{bool}> */
    public static function readFailures(): iterable
    {
        yield 'early EOF' => [false];
        yield 'source throws' => [true];
    }

    public function testCloseDetachAndDestructionReleaseOnlyOwnedSources(): void
    {
        foreach (['close', 'detach', 'destruct'] as $action) {
            $path = $this->file('owned');
            $stream = new UploadProbeStream('borrowed');
            $body = StreamingMultipartEncoder::encode([
                ['name' => 'f', 'value' => Upload::fromPath($path)],
                ['name' => 'g', 'value' => Upload::fromStream('g', $stream)],
            ]);
            self::assertCount(1, $this->handles($path));
            if ($action === 'close') {
                $body->close();
                $body->close();
            } elseif ($action === 'detach') {
                self::assertNull($body->detach());
            }
            unset($body);
            self::assertSame([], $this->handles($path));
            self::assertSame(0, $stream->closes);
            self::assertSame(0, $stream->detaches);
            self::assertSame(0, $stream->reads);
        }
    }

    public function testRepeatedPathAndStringSourcesRemainIndependent(): void
    {
        $path = $this->file('PATH');
        $upload = Upload::fromPath($path, 'f');
        $string = Upload::fromString('s', 'STRING');
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'f', 'value' => $upload], ['name' => 'f', 'value' => $string],
            ['name' => 'f', 'value' => $upload], ['name' => 'f', 'value' => $string],
        ]);
        self::assertCount(2, $this->handles($path));
        $bytes = $body->getContents();
        self::assertSame(2, substr_count($bytes, "\r\n\r\nPATH\r\n"));
        self::assertSame(2, substr_count($bytes, "\r\n\r\nSTRING\r\n"));
    }

    public function testReadOnlyPsrOperationsAndClosedState(): void
    {
        $body = StreamingMultipartEncoder::encode([]);
        self::assertFalse($body->isSeekable());
        self::assertFalse($body->isWritable());
        self::assertTrue($body->isReadable());
        self::assertSame('', $body->read(0));
        foreach (['write', 'seek', 'rewind', 'negative'] as $operation) {
            try {
                match ($operation) {
                    'write' => $body->write('private'),
                    'seek' => $body->seek(0),
                    'rewind' => $body->rewind(),
                    default => $body->read(-1),
                };
                self::fail('Unsupported stream operation must throw.');
            } catch (AssertionFailedError $failure) {
                throw $failure;
            } catch (RuntimeException $error) {
                self::assertNull($error->getPrevious());
            }
        }
        $size = $body->getSize();
        $body->close();
        self::assertFalse($body->isReadable());
        self::assertTrue($body->eof());
        self::assertSame($size, $body->getSize());
        self::assertSame('', (string) $body);
        $this->expectException(RuntimeException::class);
        $body->tell();
    }

    public function testDebugExportAndMetadataDoNotRevealSensitiveState(): void
    {
        $path = $this->file('secret-file-bytes');
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'private-name', 'value' => 'private-text'],
            ['name' => 'f', 'value' => Upload::fromPath($path, 'private-filename')],
        ]);
        ob_start();
        var_dump($body);
        $dump = ob_get_clean();
        self::assertIsString($dump);
        $diagnostics = $dump . print_r($body, true) . var_export($body, true);
        foreach ([$path, 'secret-file-bytes', 'private-name', 'private-text', 'private-filename'] as $secret) {
            self::assertStringNotContainsString($secret, $diagnostics);
        }
        self::assertSame([], $body->getMetadata());
        self::assertNull($body->getMetadata('uri'));
    }


    public function testSparseOuterListRejectsWithoutOpeningSources(): void
    {
        $stream = new UploadProbeStream('bytes');
        try {
            StreamingMultipartEncoder::encode([1 => ['name' => 'f', 'value' => Upload::fromStream('f', $stream)]]);
            self::fail('Sparse lists must fail.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $stream->metadataCalls);
        }
    }

    public function testKnownZeroBorrowedSourceIsNotRead(): void
    {
        $stream = new UploadProbeStream('');
        $body = StreamingMultipartEncoder::encode([['name' => 'f', 'value' => Upload::fromStream('empty', $stream)]]);
        self::assertStringContainsString("Content-Type: application/octet-stream\r\n\r\n\r\n", $body->getContents());
        self::assertSame(0, $stream->reads);
    }

    public function testUnexpectedEmptyNonEofReadAbortsWithoutLooping(): void
    {
        $stream = new UploadProbeStream('bytes');
        $stream->maxReadLength = 0;
        $body = StreamingMultipartEncoder::encode([['name' => 'f', 'value' => Upload::fromStream('f', $stream)]]);
        self::assertSame('', (string) $body);
        self::assertSame(1, $stream->reads);
        self::assertFalse($stream->eof());
        $this->expectException(RuntimeException::class);
        $body->assertHealthy();
    }

    public function testLaterFileOpenFailureReleasesTheEarlierOwnedFile(): void
    {
        $path = $this->file('owned');
        try {
            StreamingMultipartEncoder::encode([
                ['name' => 'f', 'value' => Upload::fromPath($path)],
                ['name' => 'g', 'value' => Upload::fromPath($path . '-absent')],
            ]);
            self::fail('Missing source must fail.');
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (RuntimeException) {
            self::assertSame([], $this->handles($path));
        }
    }

    public function testSerializationCannotExposeBodyState(): void
    {
        $body = StreamingMultipartEncoder::encode([['name' => 'private', 'value' => 'private-value']]);
        try {
            serialize($body);
            self::fail('Serialization must fail.');
        } catch (LogicException $error) {
            self::assertStringNotContainsString('private-value', $error->getMessage());
        }
        $class = $body::class;
        $this->expectException(LogicException::class);
        unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}');
    }

    public function testFailureTracesHideAllLibrarySourceAndFramingArguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $stream = new UploadProbeStream('private-source-bytes');
        $stream->throwRead = true;
        $body = StreamingMultipartEncoder::encode([
            ['name' => 'private-part-name', 'value' => Upload::fromStream('private-filename', $stream)],
        ]);
        try {
            try {
                $body->getContents();
                self::fail('Source exception must fail.');
            } catch (AssertionFailedError $failure) {
                throw $failure;
            } catch (RuntimeException $error) {
                self::assertNull($error->getPrevious());
                foreach ($error->getTrace() as $frame) {
                    if (!str_starts_with($frame['class'] ?? '', 'HostingPal\\WhmApiClient\\Http\\')) {
                        continue;
                    }
                    $arguments = print_r($frame['args'] ?? [], true);
                    foreach (['private-source-bytes', 'private-part-name', 'private-filename'] as $secret) {
                        self::assertStringNotContainsString($secret, $arguments);
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'multipart-body-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->paths[] = $path;
        return $path;
    }

    /** @return array<int, resource> */
    private function handles(string $path): array
    {
        return array_filter(get_resources('stream'), static fn ($resource): bool =>
            (stream_get_meta_data($resource)['uri'] ?? null) === $path);
    }
}
