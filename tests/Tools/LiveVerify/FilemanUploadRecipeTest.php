<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Http\StreamingMultipartEncoder;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
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
        $recipe = new FilemanUploadRecipe(self::ENTROPY, self::account());
        self::assertSame('wlupload-fixture-' . self::ENTROPY, $recipe->directory);
        self::assertSame('bytes-0123456789abcdef.bin', $recipe->names['bytes']);
        self::assertCount(6, array_unique($recipe->names));
        self::assertSame(
            '/home/wlupload/wlupload-fixture-' . self::ENTROPY . '/raw-0123456789abcdef.bin',
            $recipe->absolutePath('raw'),
        );
    }

    #[DataProvider('invalidEntropy')]
    public function testRefusesWeakRunValues(string $entropy): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FilemanUploadRecipe($entropy, self::account());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEntropy(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['0123456789abcdef'];
        yield 'uppercase' => [strtoupper(self::ENTROPY)];
        yield 'path traversal' => ['../../../../etc/passwd0000000000'];
    }

    /** The recipe's URIs, paths and guard name one account, so it refuses a binding for anything else. */
    #[DataProvider('foreignBindings')]
    public function testRefusesABindingThatIsNotThisRecipesAccount(
        string $user,
        string $domain,
        string $tokenName,
        bool $readOnlyToken,
    ): void {
        $account = DisposableAccount::fresh($user, $domain, $tokenName, 900, $readOnlyToken);
        $this->expectException(InvalidArgumentException::class);
        new FilemanUploadRecipe(self::ENTROPY, $account);
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function foreignBindings(): iterable
    {
        $user = FilemanUploadRecipe::ACCOUNT;
        $domain = FilemanUploadRecipe::DOMAIN;
        $token = FilemanUploadRecipe::TOKEN_NAME;
        yield 'another account' => ['other', $domain, $token, false];
        yield 'another domain' => [$user, 'other.example.com', $token, false];
        yield 'another token' => [$user, $domain, 'other-token', false];
        yield 'a read-only token cannot upload' => [$user, $domain, $token, true];
    }

    public function testExactUrisUseOverwriteDisabledAndExplicitCharsets(): void
    {
        $recipe = new FilemanUploadRecipe(self::ENTROPY, self::account());
        $base = 'https://test.oblak.host:2083/execute/Fileman/';
        $directory = 'wlupload-fixture-' . self::ENTROPY;
        self::assertSame(
            $base . 'upload_files?dir=' . $directory . '&get_disk_info=1&overwrite=0&permissions=0600',
            $recipe->uploadUri('upload_success'),
        );
        foreach (['upload_partial', 'upload_all_failed', 'upload_raw', 'upload_empty'] as $phase) {
            self::assertSame(
                $base . 'upload_files?dir=' . $directory . '&overwrite=0&permissions=0600',
                $recipe->uploadUri($phase),
            );
        }
        self::assertSame($base . 'list_files?dir=%2Fhome%2Fwlupload&types=dir%7Cfile', $recipe->homeListUri());
        self::assertSame(
            $base . 'list_files?dir=' . $directory . '&include_permissions=1&show_hidden=1',
            $recipe->directoryListUri(),
        );
        self::assertSame(
            $base . 'get_file_content?dir=%2Fhome%2Fwlupload%2F' . $directory . '&file=empty-0123456789abcdef.bin'
                . '&from_charset=ISO-8859-1&to_charset=UTF-8&update_html_document_encoding=0',
            $recipe->readUri('empty'),
        );
    }

    /** @param 'upload_success'|'upload_partial'|'upload_all_failed'|'upload_raw'|'upload_empty' $phase */
    #[DataProvider('uploadPhases')]
    public function testHandAssembledBodyEqualsTheLibraryEncoderForEveryPhase(string $phase): void
    {
        $recipe = new FilemanUploadRecipe(self::ENTROPY, self::account());
        $parts = [];
        foreach ($recipe->parts($phase) as $part) {
            $parts[] = ['name' => $part['field'], 'value' => Upload::fromString($part['filename'], $part['bytes'])];
        }
        $boundary = 'whm-api-recipe-test-boundary-0001';
        $body = StreamingMultipartEncoder::encode($parts, static fn (): string => $boundary);
        self::assertSame($recipe->expectedBody($phase, $boundary), (string) $body);
    }

    /** @return iterable<string, array{'upload_success'|'upload_partial'|'upload_all_failed'|'upload_raw'|'upload_empty'}> */
    public static function uploadPhases(): iterable
    {
        foreach (['upload_success', 'upload_partial', 'upload_all_failed', 'upload_raw', 'upload_empty'] as $phase) {
            yield $phase => [$phase];
        }
    }

    public function testFailingPhasesReuseOwnedNamesWithConflictingBytes(): void
    {
        $recipe = new FilemanUploadRecipe(self::ENTROPY, self::account());
        self::assertSame($recipe->names['bytes'], $recipe->parts('upload_partial')[0]['filename']);
        self::assertSame(FilemanUploadRecipe::CONFLICT_BYTES, $recipe->parts('upload_partial')[0]['bytes']);
        self::assertSame($recipe->names['partial'], $recipe->parts('upload_partial')[1]['filename']);
        self::assertSame($recipe->names['cursor'], $recipe->parts('upload_all_failed')[0]['filename']);
        self::assertSame(FilemanUploadRecipe::CONFLICT_BYTES, $recipe->parts('upload_all_failed')[0]['bytes']);
    }

    private static function account(): DisposableAccount
    {
        return DisposableAccount::fresh(
            FilemanUploadRecipe::ACCOUNT,
            FilemanUploadRecipe::DOMAIN,
            FilemanUploadRecipe::TOKEN_NAME,
            900,
            readOnlyToken: false,
        );
    }
}
