<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\ObservedStream;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class ObservedStreamTest extends TestCase
{
    public function testNonseekablePartiallyConsumedStreamKeepsOriginalStringBehavior(): void
    {
        $pipe = popen('printf abcdef', 'r');
        self::assertIsResource($pipe);
        $original = Stream::create($pipe);
        self::assertFalse($original->isSeekable());
        self::assertSame('ab', $original->read(2));
        $captured = null;
        $observed = new ObservedStream($original, static function (string $body) use (&$captured): void {
            $captured = $body;
        });

        self::assertNull($captured);
        self::assertSame('cdef', (string) $observed);
        self::assertSame('cdef', $captured);
        self::assertTrue($observed->eof());
        self::assertSame('', $observed->getContents());
        $observed->close();
    }

    public function testUsesOriginalStringConversionAndIgnoresObserverFailure(): void
    {
        $original = $this->createMock(StreamInterface::class);
        $original->expects(self::once())->method('__toString')->willReturn('original result');
        $original->expects(self::never())->method('rewind');
        $original->expects(self::never())->method('getContents');
        $observed = new ObservedStream($original, static function (string $body): void {
            throw new RuntimeException('Observer failed.');
        });

        self::assertSame('original result', (string) $observed);
    }

    public function testPreservesOriginalStringException(): void
    {
        $exception = new RuntimeException('Original stream failure.');
        $original = $this->createMock(StreamInterface::class);
        $original->expects(self::once())->method('__toString')->willThrowException($exception);
        $observed = new ObservedStream($original, static function (string $body): void {
            self::fail('Observer must not run after failed conversion.');
        });
        $this->expectExceptionObject($exception);

        (string) $observed;
    }

    public function testDelegatesStreamOperationsWithoutObservingThem(): void
    {
        $original = Stream::create('abcdef');
        $observed = new ObservedStream($original, static function (string $body): void {
            self::fail('Only string conversion may capture.');
        });

        self::assertSame(6, $observed->getSize());
        self::assertTrue($observed->isSeekable());
        self::assertTrue($observed->isReadable());
        self::assertTrue($observed->isWritable());
        self::assertSame('ab', $observed->read(2));
        self::assertSame(2, $observed->tell());
        $observed->seek(4);
        self::assertSame('ef', $observed->getContents());
        $observed->rewind();
        self::assertSame(2, $observed->write('xy'));
        self::assertSame($original->getMetadata(), $observed->getMetadata());
        self::assertSame($original->getMetadata('seekable'), $observed->getMetadata('seekable'));
        $resource = $observed->detach();
        self::assertIsResource($resource);
        self::assertNull($original->detach());
        fclose($resource);
    }
}
