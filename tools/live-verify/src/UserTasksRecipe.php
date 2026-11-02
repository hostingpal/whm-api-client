<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use InvalidArgumentException;
use SensitiveParameter;
use stdClass;

/**
 * The exact requests and identities of one UserTasks queue run on its own disposable account.
 *
 * The producer is a VersionControl clone whose source is an empty repository this run creates inside the same account,
 * so the server's git clones a local path and contacts no network. Every name derives from 128 bits of run entropy.
 * Bodies are spelled literally here, independently of the client's encoder, so the guard compares two spellings of
 * each request. Nothing here performs I/O.
 *
 * @phpstan-type Repository 'source'|'clone'
 */
final readonly class UserTasksRecipe
{
    public const ACCOUNT = 'wltasks';

    public const DOMAIN = 'wltasks.example.com';

    public const HOME = '/home/wltasks';

    public const TOKEN_NAME = 'wltasks-fixture';

    public const SUBSYSTEM = 'VersionControl';

    public const ACTION = 'create';

    /**
     * Directory::Queue::Normal 2.2 element names: an eight-digit hexadecimal directory and a fourteen-digit name.
     *
     * Its _check_element() refuses anything else, so no other identifier can name a queue entry.
     */
    private const TASK_ID = '/\A[0-9a-f]{8}\/[0-9a-f]{14}\z/';

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    public string $directory;

    public string $sourceName;

    public string $cloneName;

    /** Bind one run's entropy to this recipe's own writable disposable account; performs no I/O. */
    public function __construct(#[SensitiveParameter] string $entropy, public DisposableAccount $account)
    {
        if (
            preg_match('/\A[0-9a-f]{32}\z/', $entropy) !== 1 || $account->user !== self::ACCOUNT
            || $account->domain !== self::DOMAIN || $account->tokenName !== self::TOKEN_NAME || $account->readOnlyToken
        ) {
            throw new InvalidArgumentException('User task recipe needs 128 bits of entropy and its own account.');
        }
        $this->directory = 'wltasks-fixture-' . $entropy;
        $this->sourceName = 'wltasks-source-' . substr($entropy, 0, 16);
        $this->cloneName = 'wltasks-clone-' . substr($entropy, 0, 16);
    }

    /** Whether $id is a queue element name, the only form the server's queue can hold. */
    public static function isTaskId(#[SensitiveParameter] mixed $id): bool
    {
        return is_string($id) && preg_match(self::TASK_ID, $id) === 1;
    }

    public function sourceRoot(): string
    {
        return self::HOME . '/' . $this->directory . '/source';
    }

    public function cloneRoot(): string
    {
        return self::HOME . '/' . $this->directory . '/clone';
    }

    /** The clone's source: the local source repository's absolute path, with no scheme, host or credential. */
    public function sourceRepositoryJson(): string
    {
        return '{"url":"' . $this->sourceRoot() . '"}';
    }

    /**
     * The generic create call's parameters; the source omits source_repository, so it is created without a task.
     *
     * @param Repository $repository
     * @return array<string, string>
     */
    public function createParameters(string $repository): array
    {
        return $repository === 'source'
            ? ['repository_root' => $this->sourceRoot(), 'name' => $this->sourceName, 'type' => 'git']
            : ['repository_root' => $this->cloneRoot(), 'name' => $this->cloneName, 'type' => 'git',
                'source_repository' => $this->sourceRepositoryJson()];
    }

    /**
     * The exact form body of one create, spelled literally.
     *
     * @param Repository $repository
     */
    public function createBody(string $repository): string
    {
        $root = '%2Fhome%2Fwltasks%2F' . $this->directory;

        return $repository === 'source'
            ? 'repository_root=' . $root . '%2Fsource&name=' . $this->sourceName . '&type=git'
            : 'repository_root=' . $root . '%2Fclone&name=' . $this->cloneName . '&type=git'
                . '&source_repository=%7B%22url%22%3A%22' . $root . '%2Fsource%22%7D';
    }

    /** The exact form body that deletes one queue element, spelled literally; anything else is refused. */
    public function deleteBody(#[SensitiveParameter] string $id): string
    {
        if (!self::isTaskId($id)) {
            throw new InvalidArgumentException('User task identifier is not a queue element.');
        }

        return 'id=' . substr($id, 0, 8) . '%2F' . substr($id, 9);
    }

    /**
     * Whether a decoded raw queue row is exactly this run's clone task.
     *
     * Only the exact subsystem, action and clone root count: the account is this run's own and its queue was empty
     * before the producer, but the match never relies on that alone. Rows are read from the original JSON, so an
     * object stays distinct from a list.
     */
    public function isOwnedRow(#[SensitiveParameter] mixed $row): bool
    {
        if (!$row instanceof stdClass || !($row->args ?? null) instanceof stdClass) {
            return false;
        }

        return ($row->subsystem ?? null) === self::SUBSYSTEM && ($row->action ?? null) === self::ACTION
            && ($row->args->repository_root ?? null) === $this->cloneRoot();
    }

    public function homeUri(): string
    {
        return self::DIRECT . 'Variables/get_user_information?name=home';
    }

    public function tasksUri(bool $bridged): string
    {
        return $bridged
            ? self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=' . self::ACCOUNT
                . '&cpanel.module=UserTasks&cpanel.function=retrieve'
            : self::DIRECT . 'UserTasks/retrieve';
    }

    public function createUri(): string
    {
        return self::DIRECT . 'VersionControl/create';
    }

    public function deleteUri(): string
    {
        return self::DIRECT . 'UserTasks/delete';
    }
}
