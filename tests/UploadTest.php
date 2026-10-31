<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests;

use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadTest extends TestCase
{
    public function testFactoriesDescribeSourcesWithoutAccessingThem(): void
    {
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        foreach (
            ['read', 'getContents', '__toString', 'getSize', 'tell', 'isReadable', 'getMetadata',
            'seek', 'rewind', 'close', 'detach'] as $method
        ) {
            $stream->expects(self::never())->method($method);
        }

        $path = Upload::fromPath('/missing/private-path', 'path.bin');
        $bytes = Upload::fromString('bytes.bin', "\0\xff");
        $borrowed = Upload::fromStream('stream.bin', $stream);

        self::assertSame('path', $path->__debugInfo()['kind']);
        self::assertSame('string', $bytes->__debugInfo()['kind']);
        self::assertSame('stream', $borrowed->__debugInfo()['kind']);
    }

    public function testAnchorsRelativePathsAtFactoryTimeWithoutResolvingThem(): void
    {
        $cwd = getcwd();
        self::assertIsString($cwd);
        $upload = Upload::fromPath('missing/../private.bin');

        self::assertSame($cwd . '/missing/../private.bin', $upload->descriptor()['source']);
        self::assertSame('private.bin', $upload->descriptor()['filename']);
        self::assertSame('application/octet-stream', $upload->descriptor()['contentType']);
    }

    public function testPreservesExplicitFilenameMimeAndAllStringBytes(): void
    {
        $upload = Upload::fromString('café file<>;.bin', "\0\xff\r\n", 'application/vnd.test+binary');
        self::assertSame('café file<>;.bin', $upload->descriptor()['filename']);
        self::assertSame("\0\xff\r\n", $upload->descriptor()['source']);
        self::assertSame('application/vnd.test+binary', $upload->descriptor()['contentType']);
        self::assertSame('', Upload::fromString('empty', '')->descriptor()['source']);
    }

    #[DataProvider('invalidFilenames')]
    public function testRejectsUnsafeFilenameInEveryFactory(string $filename): void
    {
        foreach (['path', 'string', 'stream'] as $kind) {
            try {
                match ($kind) {
                    'path' => Upload::fromPath('/missing/file', $filename),
                    'string' => Upload::fromString($filename, ''),
                    'stream' => Upload::fromStream($filename, Stream::create('')),
                };
                self::fail('Unsafe filename accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertNull($exception->getPrevious());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFilenames(): iterable
    {
        foreach (['', '.', '..', 'a/b', 'a\\b', 'a"b', "a\0b", "a\x1fb", "a\x7fb", "a\xff"] as $index => $name) {
            yield (string) $index => [$name];
        }
    }

    #[DataProvider('invalidContentTypes')]
    public function testRejectsInvalidContentType(string $contentType): void
    {
        $this->expectException(InvalidArgumentException::class);
        Upload::fromString('file', '', $contentType);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidContentTypes(): iterable
    {
        foreach (
            ['', 'text', 'text/', '/plain', 'text/plain; charset=utf-8', 'text/ plain',
            "text/plain\r\nX: value", 'téxt/plain', 'text/plain/more'] as $index => $type
        ) {
            yield (string) $index => [$type];
        }
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsNonlocalPathsWithoutOpening(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        Upload::fromPath($path, 'file');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        foreach (
            ['', "local\0name", 'https://host/file', 'php://memory', 'file:///tmp/file',
            'data:text/plain,bytes', '//host/share/file',
            'scheme:filename', 'C:\\private\\file', '\\\\host\\share\\file'] as $index => $path
        ) {
            yield (string) $index => [$path];
        }
    }

    public function testRejectsNegativeSizeWithoutInspectingBorrowedStream(): void
    {
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->expects(self::never())->method('getSize');
        $stream->expects(self::never())->method('tell');
        $this->expectException(InvalidArgumentException::class);
        Upload::fromStream('file', $stream, size: -1);
    }

    public function testDebugAndExportHideEverySourceIncludingStreamInternals(): void
    {
        $secret = 'unique-private-upload-marker';
        $uploads = [
            Upload::fromPath('/' . $secret . '/file', $secret),
            Upload::fromString($secret, $secret),
            Upload::fromStream($secret, Stream::create($secret)),
        ];
        foreach ($uploads as $upload) {
            ob_start();
            var_dump($upload);
            $dump = ob_get_clean();
            self::assertIsString($dump);
            foreach ([$dump, print_r($upload, true), var_export($upload, true)] as $export) {
                self::assertStringNotContainsString($secret, $export);
                self::assertStringNotContainsString('Nyholm', $export);
            }
        }
    }

    public function testSerializationRefusalDoesNotConsumeOrInvalidateDescriptor(): void
    {
        $stream = Stream::create('private-bytes');
        $upload = Upload::fromStream('private-name', $stream);
        try {
            serialize($upload);
            self::fail('Upload serialized.');
        } catch (LogicException $exception) {
            self::assertStringNotContainsString('private-', $exception->getMessage());
        }
        self::assertSame(0, $stream->tell());
        self::assertSame($stream, $upload->descriptor()['source']);
    }

    public function testLocalValidationFramesRedactAllSuppliedMetadata(): void
    {
        $before = ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                Upload::fromString('private-name-secret', 'private-bytes-secret', "private-mime-secret\r\n");
                self::fail('Invalid MIME accepted.');
            } catch (InvalidArgumentException $exception) {
                foreach (['private-name-secret', 'private-bytes-secret', 'private-mime-secret'] as $secret) {
                    self::assertStringNotContainsString(
                        $secret,
                        (string) $exception . print_r(array_slice($exception->getTrace(), 0, 2), true),
                    );
                }
                self::assertNull($exception->getPrevious());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $before === false ? '1' : $before);
        }
    }

    public function testRefusesCraftedUnserialization(): void
    {
        $this->expectException(LogicException::class);
        unserialize('O:' . strlen(Upload::class) . ':"' . Upload::class . '":0:{}');
    }
}
