<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Tools\LiveVerify\FilemanUploadRecipe;
use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilemanUploadRecipeTest extends TestCase
{
    private const ENTROPY = '0123456789abcdef0123456789abcdef';

    public function testOracleMatchesLiteralCodePointVectors(): void
    {
        self::assertSame('', FilemanUploadRecipe::latin1ToUtf8(''));
        self::assertSame("\x00", FilemanUploadRecipe::latin1ToUtf8("\x00"));
        self::assertSame("\x7f", FilemanUploadRecipe::latin1ToUtf8("\x7f"));
        self::assertSame("\xc2\x80", FilemanUploadRecipe::latin1ToUtf8("\x80"));
        self::assertSame("\xc2\xbf", FilemanUploadRecipe::latin1ToUtf8("\xbf"));
        self::assertSame("\xc3\x80", FilemanUploadRecipe::latin1ToUtf8("\xc0"));
        self::assertSame("\xc3\xbf", FilemanUploadRecipe::latin1ToUtf8("\xff"));
        self::assertSame("a\xc3\xa9\x00\xc2\xa0", FilemanUploadRecipe::latin1ToUtf8("a\xe9\x00\xa0"));
    }

    public function testOracleIsInjectiveAndAgreesWithAnUnrelatedConverterForEveryByte(): void
    {
        $seen = [];
        for ($byte = 0; $byte < 256; ++$byte) {
            $mapped = FilemanUploadRecipe::latin1ToUtf8(chr($byte));
            self::assertSame(mb_convert_encoding(chr($byte), 'UTF-8', 'ISO-8859-1'), $mapped);
            self::assertSame($byte, mb_ord($mapped, 'UTF-8'));
            self::assertArrayNotHasKey($mapped, $seen);
            $seen[$mapped] = true;
        }
        self::assertCount(256, $seen);
    }

    public function testBinaryFileHoldsEveryByteValueAndTheExtraNulAndCrlfBytes(): void
    {
        $bytes = FilemanUploadRecipe::contents('bytes');
        self::assertCount(256, count_chars($bytes, 1));
        self::assertSame(261, strlen($bytes));
        self::assertSame("\x00\x00\x01", substr($bytes, 0, 3));
        self::assertSame("\xff\x00\r\n\xff", substr($bytes, -5));
        self::assertSame(3, substr_count($bytes, "\x00"));
    }

    public function testSyntheticFilesAreExactNonUtf8EmptyAndLargerThanOneRead(): void
    {
        self::assertSame('', FilemanUploadRecipe::contents('empty'));
        self::assertSame(20000, strlen(FilemanUploadRecipe::contents('path')));
        self::assertGreaterThan(8192, strlen(FilemanUploadRecipe::contents('path')));
        foreach (['bytes', 'cursor', 'path', 'partial', 'raw'] as $label) {
            self::assertFalse(mb_check_encoding(FilemanUploadRecipe::contents($label), 'UTF-8'), $label);
        }
        self::assertCount(5, array_unique(array_map(FilemanUploadRecipe::contents(...), [
            'bytes', 'cursor', 'path', 'partial', 'raw',
        ])));
    }

    public function testIdentifiersAreDerivedFromTheRunValueOnly(): void
    {
        $recipe = new FilemanUploadRecipe(self::ENTROPY, 1790000000);
        self::assertSame('wlupload-fixture-' . self::ENTROPY, $recipe->directory);
        self::assertSame('upload-' . self::ENTROPY . '@wlupload.example.com', $recipe->contactEmail);
        self::assertSame('bytes-0123456789abcdef.bin', $recipe->names['bytes']);
        self::assertCount(6, array_unique($recipe->names));
        self::assertSame(
            '/home/wlupload/wlupload-fixture-' . self::ENTROPY . '/raw-0123456789abcdef.bin',
            $recipe->absolutePath('raw'),
        );
    }

    #[DataProvider('invalidEntropy')]
    public function testRefusesWeakRunValues(string $entropy, int $expiresAt): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FilemanUploadRecipe($entropy, $expiresAt);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidEntropy(): iterable
    {
        yield 'empty' => ['', 1];
        yield 'short' => ['0123456789abcdef', 1];
        yield 'uppercase' => [strtoupper(self::ENTROPY), 1];
        yield 'path traversal' => ['../../../../etc/passwd0000000000', 1];
        yield 'no expiration' => [self::ENTROPY, 0];
    }

    public function testExactUrisUseOverwriteDisabledAndExplicitCharsets(): void
    {
        $recipe = new FilemanUploadRecipe(self::ENTROPY, 1790000000);
        $base = 'https://test.oblak.host:2083/execute/Fileman/';
        $directory = 'wlupload-fixture-' . self::ENTROPY;
        self::assertSame(
            $base . 'upload_files?dir=' . $directory . '&get_disk_info=1&overwrite=0&permissions=0600',
            $recipe->uploadUri('upload_success'),
        );
        foreach (['upload_partial', 'upload_all_failed', 'upload_raw'] as $phase) {
            self::assertSame(
                $base . 'upload_files?dir=' . $directory . '&overwrite=0&permissions=0600',
                $recipe->uploadUri($phase),
            );
        }
        self::assertSame($base . 'list_files?dir=%2Fhome%2Fwlupload&types=dir%7Cfile', $recipe->homeListUri());
        self::assertSame(
            $base . 'list_files?dir=' . $directory . '&include_permissions=1&show_hidden=1&types=dir%7Cfile',
            $recipe->directoryListUri(),
        );
        self::assertSame(
            $base . 'get_file_content?dir=%2Fhome%2Fwlupload%2F' . $directory . '&file=empty-0123456789abcdef.bin'
                . '&from_charset=ISO-8859-1&to_charset=UTF-8&update_html_document_encoding=0',
            $recipe->readUri('empty'),
        );
    }

    /** @param 'upload_success'|'upload_partial'|'upload_all_failed'|'upload_raw' $phase */
    #[DataProvider('uploadPhases')]
    public function testHandAssembledBodyEqualsTheLibraryEncoderForEveryPhase(string $phase): void
    {
        $recipe = new FilemanUploadRecipe(self::ENTROPY, 1790000000);
        $parts = [];
        foreach ($recipe->parts($phase) as $part) {
            $parts[] = ['name' => $part['field'], 'value' => Upload::fromString($part['filename'], $part['bytes'])];
        }
        $boundary = 'whm-api-recipe-test-boundary-0001';
        $body = StreamingMultipartEncoder::encode($parts, static fn (): string => $boundary);
        self::assertSame($recipe->expectedBody($phase, $boundary), (string) $body);
    }

    /** @return iterable<string, array{'upload_success'|'upload_partial'|'upload_all_failed'|'upload_raw'}> */
    public static function uploadPhases(): iterable
    {
        foreach (['upload_success', 'upload_partial', 'upload_all_failed', 'upload_raw'] as $phase) {
            yield $phase => [$phase];
        }
    }

    public function testFailingPhasesReuseOwnedNamesWithConflictingBytes(): void
    {
        $recipe = new FilemanUploadRecipe(self::ENTROPY, 1790000000);
        self::assertSame($recipe->names['bytes'], $recipe->parts('upload_partial')[0]['filename']);
        self::assertSame(FilemanUploadRecipe::CONFLICT_BYTES, $recipe->parts('upload_partial')[0]['bytes']);
        self::assertSame($recipe->names['partial'], $recipe->parts('upload_partial')[1]['filename']);
        self::assertSame($recipe->names['cursor'], $recipe->parts('upload_all_failed')[0]['filename']);
        self::assertSame(FilemanUploadRecipe::CONFLICT_BYTES, $recipe->parts('upload_all_failed')[0]['bytes']);
    }
}
