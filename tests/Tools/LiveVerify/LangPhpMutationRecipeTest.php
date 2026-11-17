<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\LangPhpMutationRecipe;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LangPhpMutationRecipeTest extends TestCase
{
    private const ENTROPY = '0123456789abcdef0123456789abcdef';

    public function testReadsAreSpelledLiterallyForBothRoutes(): void
    {
        self::assertSame(
            'https://test.oblak.host:2083/execute/LangPHP/php_ini_get_user_content?type=home',
            LangPhpMutationRecipe::readUri('php_ini_get_user_content', 'direct-uapi', 'type=home'),
        );
        self::assertSame(
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wlphpset'
                . '&cpanel.module=LangPHP&cpanel.function=php_get_vhost_versions&vhost=wlphpset.example.com',
            LangPhpMutationRecipe::readUri('php_get_vhost_versions', 'bridged-uapi', 'vhost=wlphpset.example.com'),
        );
        self::assertSame(
            'https://test.oblak.host:2083/execute/LangPHP/php_get_installed_versions',
            LangPhpMutationRecipe::readUri('php_get_installed_versions', 'direct-uapi'),
        );
        self::assertSame(
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wlphpset'
                . '&cpanel.module=LangPHP&cpanel.function=php_set_vhost_versions',
            LangPhpMutationRecipe::mutationUri('php_set_vhost_versions', 'bridged-uapi'),
        );
        self::assertCount(10, array_unique(LangPhpMutationRecipe::readUris()));
    }

    public function testDirectiveBodiesSendOneScalarThenANumberedPair(): void
    {
        self::assertSame('max_execution_time:37', LangPhpMutationRecipe::directiveArgument('direct-uapi'));
        self::assertSame(
            ['max_execution_time:41', 'max_input_time:43'],
            LangPhpMutationRecipe::directiveArgument('bridged-uapi'),
        );
        self::assertSame('directive=max_execution_time%3A37&type=home', LangPhpMutationRecipe::directiveBody(
            'direct-uapi',
        ));
        self::assertSame(
            'directive=max_execution_time%3A41&directive-1=max_input_time%3A43&type=home',
            LangPhpMutationRecipe::directiveBody('bridged-uapi'),
        );
    }

    public function testContentIsEncodedOnceAndCarriesItsLabelledType(): void
    {
        $recipe = self::recipe();
        foreach (LangPhpMutationRecipe::CONTENT_LABELS as $label) {
            $text = $recipe->contentText($label);
            self::assertStringStartsWith("[PHP]\n; wlphpset " . $label . ' ' . self::ENTROPY, $text);
            self::assertStringContainsString(' plus+sign %41 "quoted" &amp;' . "\n", $text);
            self::assertStringEndsWith(
                "\nmax_input_vars = " . LangPhpMutationRecipe::CONTENT_INPUT_VARS[$label] . "\n",
                $text,
            );
            $type = str_starts_with($label, 'host-') ? 'host' : 'home';
            self::assertSame($type, LangPhpMutationRecipe::contentType($label));
            self::assertSame('content=' . rawurlencode($text) . '&type=' . $type, $recipe->contentBody($label));
            self::assertStringContainsString('plus%2Bsign%20%2541%20%22quoted%22%20%26amp%3B', $recipe->contentBody(
                $label,
            ));
        }
        self::assertCount(4, array_unique(array_map($recipe->contentText(...), LangPhpMutationRecipe::CONTENT_LABELS)));
    }

    /** Each class is told apart from the others, and only comment or blank lines may precede the sent content. */
    public function testContentClassificationNamesEveryModel(): void
    {
        $recipe = self::recipe();
        $sent = $recipe->contentText('direct');
        $model = str_replace(['"quoted"', '&amp;'], ['&quot;quoted&quot;', '&amp;'], $sent);
        $header = "; first server line &gt;&gt; private\n; second server line\n\n";

        self::assertSame(['class' => 'verbatim', 'server_comment_lines' => 0, 'server_blank_lines' => 0], $recipe
            ->classifyContent('direct', $sent));
        self::assertSame('entity_model', $recipe->classifyContent('direct', $model)['class']);
        self::assertSame(
            ['class' => 'entity_model_after_server_comments', 'server_comment_lines' => 2, 'server_blank_lines' => 1],
            $recipe->classifyContent('direct', $header . $model),
        );
        self::assertSame(
            'verbatim_after_server_comments',
            $recipe->classifyContent('direct', $header . $sent)['class'],
        );
        self::assertSame('other', $recipe->classifyContent('direct', "not a comment\n" . $model)['class']);
        self::assertSame('other', $recipe->classifyContent('direct', $header . urldecode($model))['class']);
        self::assertSame('other', $recipe->classifyContent('direct', $header . str_replace(
            '&amp;',
            '&amp;amp;',
            $model,
        ))['class']);
        self::assertSame('other', $recipe->classifyContent('bridged', $header . $model)['class']);
        self::assertSame('other', $recipe->classifyContent('direct', rtrim($header . $model))['class']);
        self::assertSame('absent', $recipe->classifyContent('direct', null)['class']);
        self::assertSame('absent', $recipe->classifyContent('direct', 7)['class']);
    }

    /** The reviewed write/read model, without the server's comment block, spelled independently of the classifier. */
    public function testEntityModelDecodesTheAmpersandOnWriteAndEncodesQuotesOnRead(): void
    {
        $model = self::recipe()->entityModel('bridged');
        self::assertStringContainsString(' plus+sign %41 &quot;quoted&quot; &amp;' . "\n", $model);
        self::assertStringNotContainsString('&amp;amp;', $model);
        self::assertStringNotContainsString('"', $model);
    }

    public function testVersionBodyBindsTheDomainAndRefusesUnsafeVersions(): void
    {
        self::assertSame(
            'version=ea-php84&vhost=wlphpset.example.com',
            LangPhpMutationRecipe::versionBody('ea-php84'),
        );
        $refused = 0;
        foreach (['', 'ea php84', 'ea-php84&vhost=other.example', 'EA-PHP84', str_repeat('a', 65)] as $unsafe) {
            try {
                LangPhpMutationRecipe::versionBody($unsafe);
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(5, $refused);
    }

    #[DataProvider('foreignBindings')]
    public function testRecipeNeedsEntropyAndItsOwnWritableAccount(string $entropy, DisposableAccount $account): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LangPhpMutationRecipe($entropy, $account);
    }

    /** @return iterable<string, array{string, DisposableAccount}> */
    public static function foreignBindings(): iterable
    {
        yield 'short entropy' => ['0123', self::account()];
        yield 'uppercase entropy' => [strtoupper(self::ENTROPY), self::account()];
        yield 'another account' => [self::ENTROPY, DisposableAccount::fresh(
            'wlupload',
            'wlupload.example.com',
            LangPhpMutationRecipe::TOKEN_NAME,
            900,
            false,
        )];
        yield 'read-only token' => [self::ENTROPY, DisposableAccount::fresh(
            LangPhpMutationRecipe::ACCOUNT,
            LangPhpMutationRecipe::DOMAIN,
            LangPhpMutationRecipe::TOKEN_NAME,
            900,
            true,
        )];
    }

    private static function recipe(): LangPhpMutationRecipe
    {
        return new LangPhpMutationRecipe(self::ENTROPY, self::account());
    }

    private static function account(): DisposableAccount
    {
        return DisposableAccount::fresh(
            LangPhpMutationRecipe::ACCOUNT,
            LangPhpMutationRecipe::DOMAIN,
            LangPhpMutationRecipe::TOKEN_NAME,
            900,
            false,
        );
    }
}
