<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Independent PostgreSQL account state; only this double manufactures payloads and mutation effects. */
final class PostgresqlSectionServer implements ClientInterface
{
    /** @var array<string, list<string>> */
    public array $databases = [];

    /** @var list<string> */
    public array $users = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string, string> Function/route/occurrence to raw JSON payload. */
    public array $overrides = [];

    /** @var array<string, string> Function/route/occurrence to before/after/lost failure. */
    public array $failures = [];

    /** @var array<string, list<string>> */
    private array $delayed = [];

    /** @var array<string, int> */
    private array $counts = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $bridge = $request->getUri()->getPort() === 2087;
        parse_str($request->getUri()->getQuery(), $parameters);
        $function = $parameters['cpanel_function'] ?? basename($request->getUri()->getPath());
        if (!is_string($function)) {
            throw new LogicException('Invalid synthetic function.');
        }
        if ($function === 'version') {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}');
        }
        if ($request->getMethod() === 'POST') {
            parse_str((string) $request->getBody(), $parameters);
        }
        $key = $function . '/' . ($bridge ? 'bridge' : 'direct');
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $key .= '/' . $this->counts[$key];
        $failure = $this->failures[$key] ?? '';
        if ($failure === 'before') {
            return self::response('null', $bridge, false);
        }
        $name = self::parameter($parameters, 'name');
        $database = self::parameter($parameters, 'database');
        $user = self::parameter($parameters, 'user');
        if ($failure === 'delayed') {
            $identity = $function === 'create_database' ? $name : self::parameter($parameters, 'newname');
            $old = self::parameter($parameters, 'oldname');
            $this->delayed[$identity] = $this->databases[$old] ?? [];
            unset($this->databases[$old]);
            throw new RuntimeException('private request remains in flight');
        }
        $payload = null;
        switch ($function) {
            case 'create_database':
                if (isset($this->databases[$name])) {
                    throw new LogicException('Synthetic database collision.');
                }
                $this->databases[$name] = [];
                break;
            case 'create_user':
                if (in_array($name, $this->users, true)) {
                    throw new LogicException('Synthetic user collision.');
                }
                $this->users[] = $name;
                $payload = 1;
                break;
            case 'grant_all_privileges':
                $this->databases[$database] = [$user];
                break;
            case 'revoke_all_privileges':
                $this->databases[$database] = [];
                break;
            case 'rename_database':
                $old = self::parameter($parameters, 'oldname');
                $new = self::parameter($parameters, 'newname');
                $this->databases[$new] = $this->databases[$old] ?? [];
                unset($this->databases[$old]);
                break;
            case 'rename_user':
            case 'rename_user_no_password':
                $old = self::parameter($parameters, 'oldname');
                $new = self::parameter($parameters, 'newname');
                $this->users = array_map(
                    static fn (string $value): string => $value === $old ? $new : $value,
                    $this->users,
                );
                foreach ($this->databases as &$users) {
                    $users = array_map(static fn (string $value): string => $value === $old ? $new : $value, $users);
                }
                unset($users);
                break;
            case 'delete_database':
                unset($this->databases[$name]);
                $this->deleted[] = $name;
                break;
            case 'delete_user':
                $this->users = array_values(array_filter(
                    $this->users,
                    static fn (string $value): bool => $value !== $name,
                ));
                $this->deleted[] = $name;
                break;
            case 'list_databases':
                $payload = [];
                foreach ($this->databases as $identity => $users) {
                    $payload[] = ['database' => $identity, 'users' => $users, 'disk_usage' => 0];
                }
                break;
            case 'list_users':
                $payload = $this->users;
                break;
            case 'get_restrictions':
                $payload = ['prefix' => 'npr_', 'max_username_length' => 63, 'max_database_name_length' => 63];
                break;
            case 'set_password':
            case 'update_privileges':
                break;
            default:
                throw new LogicException('Unexpected synthetic operation.');
        }
        if ($failure === 'lost') {
            throw new RuntimeException('private response lost');
        }

        return self::response(
            $this->overrides[$key] ?? json_encode($payload, JSON_THROW_ON_ERROR),
            $bridge,
            $failure !== 'after',
        );
    }

    /** Model server completion after the client has lost the response and observed absence. */
    public function completeDelayed(): void
    {
        $this->databases += $this->delayed;
        $this->delayed = [];
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
