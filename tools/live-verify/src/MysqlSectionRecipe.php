<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\MysqlSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use LogicException;
use SensitiveParameter;

/**
 * Fixed request plan for the designated account, with separate run-owned identities on each route.
 *
 * Resource identities and passwords are memory-only. No caller-supplied operation, parameter, account,
 * hostname or SQL is accepted. Each phase has a fixed function and independent request expectation.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Names array{database: string, renamed_database: string, user: string, renamed_user: string}
 * @phpstan-type Step array{probe: Probe, method: string, body: string}
 */
final readonly class MysqlSectionRecipe
{
    private const FUNCTIONS = [
        'initial_databases' => 'list_databases', 'initial_users' => 'list_users',
        'get_host_notes' => 'get_host_notes', 'get_restrictions' => 'get_restrictions',
        'get_server_information' => 'get_server_information', 'locate_server' => 'locate_server',
        'create_database' => 'create_database', 'create_user' => 'create_user',
        'assign_select' => 'set_privileges_on_database', 'read_select' => 'get_privileges_on_database',
        'linked_databases' => 'list_databases', 'linked_users' => 'list_users',
        'set_password' => 'set_password', 'check_database' => 'check_database',
        'repair_database' => 'repair_database', 'list_routines' => 'list_routines',
        'dump_database_schema' => 'dump_database_schema', 'revoke_access' => 'revoke_access_to_database',
        'read_revoked' => 'get_privileges_on_database', 'rename_database' => 'rename_database',
        'renamed_databases' => 'list_databases', 'rename_user' => 'rename_user',
        'renamed_users' => 'list_users', 'update_privileges' => 'update_privileges',
        'cleanup_user' => 'delete_user', 'cleanup_database' => 'delete_database',
        'final_databases' => 'list_databases', 'final_users' => 'list_users',
        'cleanup_original_user' => 'delete_user', 'cleanup_original_database' => 'delete_database',
    ];

    /** @var array<Route, Names> */
    private array $names;

    /** Bind eight names short enough for the documented oldest username limit. Construction sends nothing. */
    public function __construct(
        #[SensitiveParameter] string $suffix,
        #[SensitiveParameter] private string $password,
    ) {
        if (preg_match('/\A[a-f0-9]{6}\z/', $suffix) !== 1 || $password === '') {
            throw new LogicException('Invalid section fixture identity.');
        }
        $prefix = 'npr_ws' . $suffix;
        $this->names = [
            'direct-uapi' => ['database' => $prefix . 'd', 'renamed_database' => $prefix . 'e',
                'user' => $prefix . 'u', 'renamed_user' => $prefix . 'v'],
            'bridged-uapi' => ['database' => $prefix . 'f', 'renamed_database' => $prefix . 'g',
                'user' => $prefix . 'w', 'renamed_user' => $prefix . 'x'],
        ];
    }

    /** @param Route $route
     * @return Names
     */
    public function names(string $route): array
    {
        return $this->names[$route];
    }

    /** @return array<string, string> Fixed normal-run phases; original-name cleanup is an exceptional alternative. */
    public static function phases(): array
    {
        return array_diff_key(self::FUNCTIONS, array_flip(['cleanup_original_user', 'cleanup_original_database']));
    }

    /** @return array<mixed> Effective generated contract, never inferred from the synthetic fixture. */
    public static function spec(string $function): array
    {
        return match ($function) {
            'version' => ServerSpec::VERSION,
            'list_databases' => MysqlSpec::LIST_DATABASES,
            'list_users' => MysqlSpec::LIST_USERS,
            'get_host_notes' => MysqlSpec::GET_HOST_NOTES,
            'get_restrictions' => MysqlSpec::GET_RESTRICTIONS,
            'get_server_information' => MysqlSpec::GET_SERVER_INFORMATION,
            'locate_server' => MysqlSpec::LOCATE_SERVER,
            'create_database' => MysqlSpec::CREATE_DATABASE,
            'create_user' => MysqlSpec::CREATE_USER,
            'set_privileges_on_database' => MysqlSpec::SET_PRIVILEGES_ON_DATABASE,
            'get_privileges_on_database' => MysqlSpec::GET_PRIVILEGES_ON_DATABASE,
            'set_password' => MysqlSpec::SET_PASSWORD,
            'check_database' => MysqlSpec::CHECK_DATABASE,
            'repair_database' => MysqlSpec::REPAIR_DATABASE,
            'dump_database_schema' => MysqlSpec::DUMP_DATABASE_SCHEMA,
            'list_routines' => MysqlSpec::LIST_ROUTINES,
            'revoke_access_to_database' => MysqlSpec::REVOKE_ACCESS_TO_DATABASE,
            'rename_database' => MysqlSpec::RENAME_DATABASE,
            'rename_user' => MysqlSpec::RENAME_USER,
            'update_privileges' => MysqlSpec::UPDATE_PRIVILEGES,
            'delete_user' => MysqlSpec::DELETE_USER,
            'delete_database' => MysqlSpec::DELETE_DATABASE,
            default => throw new LogicException('Unknown section operation.'),
        };
    }

    /**
     * Materialize exactly one approved phase; the guard consumes its authorization before sending.
     *
     * @param 'whm'|Route $route
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
        if ($route === 'whm' || !isset(self::FUNCTIONS[$phase])) {
            throw new LogicException('Unknown section phase.');
        }
        $function = self::FUNCTIONS[$phase];
        $names = $this->names[$route];
        $database = $names['database'];
        $user = $names['user'];
        $newDatabase = $names['renamed_database'];
        $newUser = $names['renamed_user'];
        $password = $this->password;
        $deleteDatabase = $phase === 'cleanup_original_database' ? $database : $newDatabase;
        $deleteUser = $phase === 'cleanup_original_user' ? $user : $newUser;
        $parameters = match ($function) {
            'create_database', 'check_database', 'repair_database' => ['name' => $database],
            'create_user' => ['name' => $user, 'password' => $password],
            'set_password' => ['user' => $user, 'password' => $password . 'Changed!9'],
            'set_privileges_on_database' => ['database' => $database, 'user' => $user, 'privileges' => 'SELECT'],
            'get_privileges_on_database', 'revoke_access_to_database' => ['database' => $database, 'user' => $user],
            'dump_database_schema' => ['dbname' => $database],
            'list_routines' => ['database_user' => $user],
            'rename_database' => ['oldname' => $database, 'newname' => $newDatabase],
            'rename_user' => ['oldname' => $user, 'newname' => $newUser],
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
            $password,
            $deleteDatabase,
            $deleteUser,
        ): mixed {
            $mysql = $client->uapi('npr')->mysql();

            return match ($function) {
                'list_databases' => $mysql->listDatabases(),
                'list_users' => $mysql->listUsers(),
                'get_host_notes' => $mysql->getHostNotes(),
                'get_restrictions' => $mysql->getRestrictions(),
                'get_server_information' => $mysql->getServerInformation(),
                'locate_server' => $mysql->locateServer(),
                'create_database' => $mysql->createDatabase($database),
                'create_user' => $mysql->createUser($user, $password),
                'set_privileges_on_database' => $mysql->setPrivilegesOnDatabase($database, $user, 'SELECT'),
                'get_privileges_on_database' => $mysql->getPrivilegesOnDatabase($database, $user),
                'set_password' => $mysql->setPassword($user, $password . 'Changed!9'),
                'check_database' => $mysql->checkDatabase($database),
                'repair_database' => $mysql->repairDatabase($database),
                'dump_database_schema' => $mysql->dumpDatabaseSchema($database),
                'list_routines' => $mysql->listRoutines($user),
                'revoke_access_to_database' => $mysql->revokeAccessToDatabase($database, $user),
                'rename_database' => $mysql->renameDatabase($database, $newDatabase),
                'rename_user' => $mysql->renameUser($user, $newUser),
                'update_privileges' => $mysql->updatePrivileges(),
                'delete_user' => $mysql->deleteUser($deleteUser),
                'delete_database' => $mysql->deleteDatabase($deleteDatabase),
            };
        };
        $spec = self::spec($function);
        $method = $spec['method'];
        if (!is_string($method)) {
            throw new LogicException('Invalid section request method.');
        }
        $uri = $route === 'direct-uapi' ? 'https://test.oblak.host:2083/execute/Mysql/' . $function
            : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                . '&cpanel.module=Mysql&cpanel.function=' . $function;
        $encoded = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        if ($method === 'GET' && $encoded !== '') {
            $uri .= ($route === 'direct-uapi' ? '?' : '&') . $encoded;
        }

        return [
            'probe' => new Probe('uapi:Mysql/' . $function, $route, $spec, $uri, $invoke),
            'method' => $method, 'body' => $method === 'POST' ? $encoded : '',
        ];
    }
}
