<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\UserTasksRecipe;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserTasksRecipeTest extends TestCase
{
    private const ENTROPY = '0123456789abcdef0123456789abcdef';

    private const ROOT = '/home/wltasks/wltasks-fixture-0123456789abcdef0123456789abcdef';

    public function testRepositoryIdentitiesAreDerivedFromTheRunValueOnly(): void
    {
        $recipe = new UserTasksRecipe(self::ENTROPY, self::account());
        self::assertSame('wltasks-fixture-' . self::ENTROPY, $recipe->directory);
        self::assertSame(self::ROOT . '/source', $recipe->sourceRoot());
        self::assertSame(self::ROOT . '/clone', $recipe->cloneRoot());
        self::assertSame('wltasks-source-0123456789abcdef', $recipe->sourceName);
        self::assertSame('wltasks-clone-0123456789abcdef', $recipe->cloneName);
    }

    /** The clone source is a path inside the same account, so the server's git never reaches a network. */
    public function testCloneSourceIsALocalPathInsideTheAccount(): void
    {
        $recipe = new UserTasksRecipe(self::ENTROPY, self::account());
        self::assertSame('{"url":"' . self::ROOT . '/source"}', $recipe->sourceRepositoryJson());
        $decoded = json_decode($recipe->sourceRepositoryJson(), true, 2, JSON_THROW_ON_ERROR);
        self::assertSame(['url' => $recipe->sourceRoot()], $decoded);
        self::assertStringStartsWith(UserTasksRecipe::HOME . '/', $decoded['url']);
        self::assertStringNotContainsString('://', $decoded['url']);
        self::assertStringNotContainsString(':', $decoded['url']);
        self::assertStringNotContainsString('@', $decoded['url']);
    }

    public function testCreateParametersCarryOnlyTheReviewedFields(): void
    {
        $recipe = new UserTasksRecipe(self::ENTROPY, self::account());
        self::assertSame([
            'repository_root' => self::ROOT . '/source', 'name' => 'wltasks-source-0123456789abcdef', 'type' => 'git',
        ], $recipe->createParameters('source'));
        self::assertSame([
            'repository_root' => self::ROOT . '/clone', 'name' => 'wltasks-clone-0123456789abcdef', 'type' => 'git',
            'source_repository' => '{"url":"' . self::ROOT . '/source"}',
        ], $recipe->createParameters('clone'));
    }

    /** Bodies are spelled literally, independently of the client's encoder, so the guard compares two spellings. */
    public function testCreateBodiesAreLiteralFormEncodings(): void
    {
        $recipe = new UserTasksRecipe(self::ENTROPY, self::account());
        $root = '%2Fhome%2Fwltasks%2Fwltasks-fixture-' . self::ENTROPY;
        self::assertSame(
            'repository_root=' . $root . '%2Fsource&name=wltasks-source-0123456789abcdef&type=git',
            $recipe->createBody('source'),
        );
        self::assertSame(
            'repository_root=' . $root . '%2Fclone&name=wltasks-clone-0123456789abcdef&type=git'
                . '&source_repository=%7B%22url%22%3A%22' . $root . '%2Fsource%22%7D',
            $recipe->createBody('clone'),
        );
        foreach (['source', 'clone'] as $label) {
            $parsed = [];
            parse_str($recipe->createBody($label), $parsed);
            self::assertSame($recipe->createParameters($label), $parsed);
        }
    }

    public function testUrisNameOnlyThisAccount(): void
    {
        $recipe = new UserTasksRecipe(self::ENTROPY, self::account());
        self::assertSame(
            'https://test.oblak.host:2083/execute/Variables/get_user_information?name=home',
            $recipe->homeUri(),
        );
        self::assertSame('https://test.oblak.host:2083/execute/UserTasks/retrieve', $recipe->tasksUri(false));
        self::assertSame(
            'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=wltasks'
                . '&cpanel.module=UserTasks&cpanel.function=retrieve',
            $recipe->tasksUri(true),
        );
        self::assertSame('https://test.oblak.host:2083/execute/VersionControl/create', $recipe->createUri());
        self::assertSame('https://test.oblak.host:2083/execute/UserTasks/delete', $recipe->deleteUri());
    }

    public function testDeleteBodySpellsOnlyAQueueElementIdentifier(): void
    {
        $recipe = new UserTasksRecipe(self::ENTROPY, self::account());
        self::assertSame('id=00000000%2F5a9ec8dd4c345d', $recipe->deleteBody('00000000/5a9ec8dd4c345d'));
        self::assertTrue(UserTasksRecipe::isTaskId('0000000a/0123456789abcd'));
        foreach (
            ['', '00000000\\/5a9ec8dd4c345d', '00000000/5a9ec8dd4c345', '0000000/5a9ec8dd4c345d0',
                '00000000/5A9EC8DD4C345D', "00000000/5a9ec8dd4c345d\n", '00000000/5a9ec8dd4c345d/x',
                '../00000000/5a9ec8dd4c345d'] as $id
        ) {
            self::assertFalse(UserTasksRecipe::isTaskId($id), var_export($id, true));
            try {
                $recipe->deleteBody($id);
                $refused = false;
            } catch (InvalidArgumentException) {
                $refused = true;
            }
            self::assertTrue($refused, var_export($id, true));
        }
    }

    /** Only an exact VersionControl clone row for this run's root is owned; nothing looser, nothing else. */
    public function testOwnedRowRequiresExactSubsystemActionAndRoot(): void
    {
        $recipe = new UserTasksRecipe(self::ENTROPY, self::account());
        $row = (object) [
            'subsystem' => 'VersionControl', 'action' => 'create', 'id' => '00000000/5a9ec8dd4c345d',
            'args' => (object) ['repository_root' => self::ROOT . '/clone', 'log_file' => 'private.log'],
        ];
        self::assertTrue($recipe->isOwnedRow($row));
        $variants = [
            ['subsystem' => 'versioncontrol'], ['subsystem' => 'WPX'], ['action' => 'deploy'],
            ['args' => (object) ['repository_root' => self::ROOT . '/source']],
            ['args' => (object) ['repository_root' => self::ROOT . '/clone/']],
            ['args' => (object) ['repository_root' => '/home/other/wltasks-fixture-' . self::ENTROPY . '/clone']],
            ['args' => (object) []], ['args' => ['repository_root' => self::ROOT . '/clone']],
        ];
        foreach ($variants as $index => $change) {
            self::assertFalse($recipe->isOwnedRow((object) [...(array) $row, ...$change]), (string) $index);
        }
        self::assertFalse($recipe->isOwnedRow(['subsystem' => 'VersionControl']));
        self::assertFalse($recipe->isOwnedRow(null));
    }

    #[DataProvider('invalidEntropy')]
    public function testRefusesWeakRunValues(string $entropy): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UserTasksRecipe($entropy, self::account());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEntropy(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['0123456789abcdef'];
        yield 'uppercase' => ['0123456789ABCDEF0123456789ABCDEF'];
        yield 'separator' => ['0123456789abcdef/0123456789abcde'];
    }

    public function testRefusesAnotherAccountOrAReadOnlyToken(): void
    {
        foreach (
            [
                DisposableAccount::fresh('wlupload', 'wlupload.example.com', 'wltasks-fixture', 900, false),
                DisposableAccount::fresh('wltasks', 'wltasks.example.com', 'wltasks-other', 900, false),
                DisposableAccount::fresh('wltasks', 'wltasks.example.com', 'wltasks-fixture', 900, true),
            ] as $index => $account
        ) {
            try {
                new UserTasksRecipe(self::ENTROPY, $account);
                $refused = false;
            } catch (InvalidArgumentException) {
                $refused = true;
            }
            self::assertTrue($refused, (string) $index);
        }
    }

    private static function account(): DisposableAccount
    {
        return DisposableAccount::fresh('wltasks', 'wltasks.example.com', 'wltasks-fixture', 900, false);
    }
}
