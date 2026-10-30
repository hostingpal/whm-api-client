<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Independent in-memory account state with deliberately controllable unsuccessful effects. */
final class MysqlSectionServer implements ClientInterface
{
    /** @var array<string, list<string>> */
    public array $databases = [];

    /** @var array<string, list<string>> */
    public array $users = [];

    /** @var array<string, list<string>> */
    private array $privileges = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string, string> Function/route/occurrence to raw JSON payload. */
    public array $overrides = [];

    /** @var array<string, string> Function/route/occurrence to before/after/lost/keep_original failure. */
    public array $failures = [];

    /** @var array<string, int> */
    private array $counts = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $bridge = $request->getUri()->getPort() === 2087;
        $parameters = [];
        parse_str($request->getUri()->getQuery(), $parameters);
        $function = $parameters['cpanel_function'] ?? basename($request->getUri()->getPath());
        if (!is_string($function)) {
            throw new LogicException('Invalid synthetic function.');
        }
        if ($function === 'version') {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.15"}}');
        }
        if ($request->getMethod() === 'POST') {
            parse_str((string) $request->getBody(), $parameters);
        }
        $key = $function . '/' . ($bridge ? 'bridge' : 'direct');
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $key .= '/' . $this->counts[$key];
        $failure = $this->failures[$key] ?? '';
        $name = self::parameter($parameters, 'name');
        $database = self::parameter($parameters, 'database');
        $user = self::parameter($parameters, 'user');
        if ($failure === 'before') {
            return self::response('null', $bridge, false);
        }
        $payload = null;
        switch ($function) {
            case 'create_database':
                $this->databases[$name] = [];
                break;
            case 'create_user':
                $this->users[$name] = [];
                break;
            case 'set_privileges_on_database':
                $this->databases[$database] = [$user];
                $this->users[$user] = [$database];
                $this->privileges[$database . '/' . $user] = ['SELECT'];
                break;
            case 'revoke_access_to_database':
                $this->databases[$database] = [];
                $this->users[$user] = [];
                $this->privileges[$database . '/' . $user] = [];
                break;
            case 'get_privileges_on_database':
                $payload = $this->privileges[$database . '/' . $user] ?? [];
                break;
            case 'rename_database':
                $old = self::parameter($parameters, 'oldname');
                $new = self::parameter($parameters, 'newname');
                $this->databases[$new] = $this->databases[$old] ?? [];
                if ($failure !== 'keep_original') {
                    unset($this->databases[$old]);
                }
                break;
            case 'rename_user':
                $old = self::parameter($parameters, 'oldname');
                $new = self::parameter($parameters, 'newname');
                $this->users[$new] = $this->users[$old] ?? [];
                if ($failure !== 'keep_original') {
                    unset($this->users[$old]);
                }
                break;
            case 'delete_database':
                unset($this->databases[$name]);
                $this->deleted[] = $name;
                break;
            case 'delete_user':
                unset($this->users[$name]);
                $this->deleted[] = $name;
                break;
            case 'list_databases':
                $payload = [];
                foreach ($this->databases as $identity => $users) {
                    $payload[] = ['database' => $identity, 'users' => $users, 'disk_usage' => 0];
                }
                break;
            case 'list_users':
                $payload = [];
                foreach ($this->users as $identity => $databases) {
                    $payload[] = ['user' => $identity, 'databases' => $databases, 'shortuser' => substr($identity, 4)];
                }
                break;
            case 'get_restrictions':
                $payload = ['prefix' => 'npr_', 'max_username_length' => 32, 'max_database_name_length' => 64];
                break;
            case 'get_server_information':
                $payload = ['host' => 'private-host', 'version' => '8.0', 'is_remote' => 0];
                break;
            case 'locate_server':
                $payload = ['is_remote' => 0, 'remote_host' => 'private-host'];
                break;
            case 'get_host_notes':
                $payload = ['private-host' => 'private-note'];
                break;
            case 'list_routines':
            case 'check_database':
            case 'repair_database':
                $payload = [];
                break;
            case 'dump_database_schema':
                $payload = '-- private schema';
                break;
            case 'set_password':
                $payload = (object) ['failures' => []];
                break;
            case 'update_privileges':
                break;
            default:
                throw new LogicException('Unexpected synthetic operation.');
        }

        if ($failure === 'lost') {
            throw new RuntimeException('Synthetic response lost.');
        }

        return self::response(
            $this->overrides[$key] ?? json_encode($payload, JSON_THROW_ON_ERROR),
            $bridge,
            $failure !== 'after',
        );
    }

    /** @param array<mixed> $parameters */
    private static function parameter(array $parameters, string $key): string
    {
        $value = $parameters[$key] ?? '';
        if (!is_string($value)) {
            throw new LogicException('Invalid synthetic parameter.');
        }

        return $value;
    }

    private static function response(string $payload, bool $bridge, bool $success): ResponseInterface
    {
        $result = '{"status":' . ($success ? '1' : '0') . ',"data":' . $payload
            . ',"errors":' . ($success ? 'null' : '["private upstream failure"]') . '}';

        return new Response(200, [], $bridge ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : $result);
    }
}
