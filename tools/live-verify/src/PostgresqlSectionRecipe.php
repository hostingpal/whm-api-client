<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\PostgresqlSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use LogicException;
use SensitiveParameter;

/**
 * Fixed PostgreSQL request plan for npr; resource identities and passwords remain in memory.
 *
 * Every phase has exact function, argument, method, route and body expectations. No caller-selected
 * SQL, server, account, function or identity is accepted. Rename destinations are disjoint on each route.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Names array{
 *     database: string, renamed_database: string, user: string, renamed_user: string, final_user: string
 * }
 * @phpstan-type Step array{probe: Probe, method: string, body: string}
 */
final readonly class PostgresqlSectionRecipe
{
    private const PREFLIGHT = [
        'initial_databases' => 'list_databases', 'initial_users' => 'list_users',
        'get_restrictions' => 'get_restrictions',
    ];

    private const FUNCTIONS = [
        'ready_databases' => 'list_databases', 'ready_users' => 'list_users',
        'create_database' => 'create_database', 'create_user' => 'create_user',
        'grant' => 'grant_all_privileges', 'linked_databases' => 'list_databases', 'linked_users' => 'list_users',
        'rename_database' => 'rename_database', 'renamed_databases' => 'list_databases',
        'rename_user' => 'rename_user', 'renamed_users' => 'list_users', 'set_password' => 'set_password',
        'rename_user_no_password' => 'rename_user_no_password', 'set_final_password' => 'set_password',
        'final_named_users' => 'list_users',
        'bounded_databases' => 'list_databases', 'bounded_users' => 'list_users',
        'update_privileges' => 'update_privileges',
        'revoke' => 'revoke_all_privileges', 'revoked_databases' => 'list_databases', 'revoked_users' => 'list_users',
        'cleanup_user' => 'delete_user', 'cleanup_database' => 'delete_database',
        'final_databases' => 'list_databases', 'final_users' => 'list_users',
    ];

    private const EXCEPTIONAL = [
        'cleanup_original_user' => 'delete_user', 'cleanup_renamed_user' => 'delete_user',
        'cleanup_original_database' => 'delete_database',
    ];

    /** @var array<Route, Names> */
    private array $names;

    public function __construct(
        #[SensitiveParameter] string $suffix,
        #[SensitiveParameter] private string $password,
    ) {
        if (preg_match('/\A[a-f0-9]{6}\z/', $suffix) !== 1 || $password === '') {
            throw new LogicException('Invalid section fixture identity.');
        }
        $prefix = 'npr_wp' . $suffix;
        $this->names = [
            'direct-uapi' => ['database' => $prefix . 'd', 'renamed_database' => $prefix . 'e',
                'user' => $prefix . 'u', 'renamed_user' => $prefix . 'v', 'final_user' => $prefix . 'z'],
            'bridged-uapi' => ['database' => $prefix . 'f', 'renamed_database' => $prefix . 'g',
                'user' => $prefix . 'w', 'renamed_user' => $prefix . 'x', 'final_user' => $prefix . 'y'],
        ];
    }

    /** @param Route $route
     * @return Names
     */
    public function names(string $route): array
    {
        return $this->names[$route];
    }

    /** @return array<string, string> Six reads must finish across both routes before any mutation. */
    public static function preflight(): array
    {
        return self::PREFLIGHT;
    }

    /** @return array<string, string> Ordered normal lifecycle after the global preflight. */
    public static function phases(): array
    {
        return self::FUNCTIONS;
    }

    /** @return array<mixed> The generated effective contract, never inferred from fixture responses. */
    public static function spec(string $function): array
    {
        return match ($function) {
            'version' => ServerSpec::VERSION,
            'create_database' => PostgresqlSpec::CREATE_DATABASE,
            'create_user' => PostgresqlSpec::CREATE_USER,
            'delete_database' => PostgresqlSpec::DELETE_DATABASE,
            'delete_user' => PostgresqlSpec::DELETE_USER,
            'get_restrictions' => PostgresqlSpec::GET_RESTRICTIONS,
            'grant_all_privileges' => PostgresqlSpec::GRANT_ALL_PRIVILEGES,
            'list_databases' => PostgresqlSpec::LIST_DATABASES,
            'list_users' => PostgresqlSpec::LIST_USERS,
            'rename_database' => PostgresqlSpec::RENAME_DATABASE,
            'rename_user' => PostgresqlSpec::RENAME_USER,
            'rename_user_no_password' => PostgresqlSpec::RENAME_USER_NO_PASSWORD,
            'revoke_all_privileges' => PostgresqlSpec::REVOKE_ALL_PRIVILEGES,
            'set_password' => PostgresqlSpec::SET_PASSWORD,
            'update_privileges' => PostgresqlSpec::UPDATE_PRIVILEGES,
            default => throw new LogicException('Unknown section operation.'),
        };
    }

    /** @param 'whm'|Route $route
     * @return Step
     */
    public function step(string $phase, string $route): array
    {
        if ($phase === 'version' && $route === 'whm') {
            return ['probe' => new Probe(
                'whm1:version',
                'whm',
                self::spec('version'),
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (#[SensitiveParameter] Client $client): mixed => $client->whm()->server()->version(),
            ), 'method' => 'GET', 'body' => ''];
        }
        $function = self::PREFLIGHT[$phase] ?? self::FUNCTIONS[$phase] ?? self::EXCEPTIONAL[$phase] ?? null;
        if ($route === 'whm' || $function === null) {
            throw new LogicException('Unknown section phase.');
        }
        $names = $this->names[$route];
        $database = $names['database'];
        $user = $names['user'];
        $newDatabase = $names['renamed_database'];
        $newUser = $names['renamed_user'];
        $finalUser = $names['final_user'];
        $password = $this->password;
        $passwordUser = $phase === 'set_final_password' ? $finalUser : $newUser;
        $changedPassword = $password . ($phase === 'set_final_password' ? 'Final!9' : 'Changed!9');
        $deleteDatabase = $phase === 'cleanup_original_database' ? $database : $newDatabase;
        $deleteUser = match ($phase) {
            'cleanup_original_user' => $user, 'cleanup_renamed_user' => $newUser, default => $finalUser,
        };
        $parameters = match ($function) {
            'create_database' => ['name' => $database],
            'create_user' => ['name' => $user, 'password' => $password],
            'grant_all_privileges' => ['database' => $database, 'user' => $user],
            'rename_database' => ['oldname' => $database, 'newname' => $newDatabase],
            'rename_user' => ['oldname' => $user, 'newname' => $newUser, 'password' => $password . 'Renamed!9'],
            'rename_user_no_password' => ['oldname' => $newUser, 'newname' => $finalUser],
            'set_password' => ['user' => $passwordUser, 'password' => $changedPassword],
            'revoke_all_privileges' => ['database' => $newDatabase, 'user' => $finalUser],
            'delete_database' => ['name' => $deleteDatabase],
            'delete_user' => ['name' => $deleteUser],
            default => [],
        };
        $invoke = static function (#[SensitiveParameter] Client $client) use (
            $function,
            $database,
            $user,
            $newDatabase,
            $newUser,
            $finalUser,
            $password,
            $passwordUser,
            $changedPassword,
            $deleteDatabase,
            $deleteUser,
        ): mixed {
            $postgresql = $client->uapi('npr')->postgresql();

            return match ($function) {
                'list_databases' => $postgresql->listDatabases(),
                'list_users' => $postgresql->listUsers(),
                'get_restrictions' => $postgresql->getRestrictions(),
                'create_database' => $postgresql->createDatabase($database),
                'create_user' => $postgresql->createUser($user, $password),
                'grant_all_privileges' => $postgresql->grantAllPrivileges($database, $user),
                'rename_database' => $postgresql->renameDatabase($database, $newDatabase),
                'rename_user' => $postgresql->renameUser($user, $newUser, $password . 'Renamed!9'),
                'rename_user_no_password' => $postgresql->renameUserNoPassword($newUser, $finalUser),
                'set_password' => $postgresql->setPassword($passwordUser, $changedPassword),
                'revoke_all_privileges' => $postgresql->revokeAllPrivileges($newDatabase, $finalUser),
                'update_privileges' => $postgresql->updatePrivileges(),
                'delete_database' => $postgresql->deleteDatabase($deleteDatabase),
                'delete_user' => $postgresql->deleteUser($deleteUser),
            };
        };
        $spec = self::spec($function);
        // Independent of the generated spec: a changed transport policy must fail the guard.
        $method = in_array($function, ['list_databases', 'list_users', 'get_restrictions'], true) ? 'GET' : 'POST';
        $uri = $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/Postgresql/' . $function
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Postgresql&cpanel.function=' . $function;

        return [
            'probe' => new Probe('uapi:Postgresql/' . $function, $route, $spec, $uri, $invoke),
            'method' => $method,
            'body' => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
        ];
    }
}
