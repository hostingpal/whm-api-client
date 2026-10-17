<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** A small account state double; no production lifecycle or expectation helpers supply its behavior. */
final class MysqlFixtureServer implements ClientInterface
{
    /** @var array<string, list<string>> */
    public array $databases = [];

    /** @var array<string, list<string>> */
    public array $users = [];

    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $steps = [];

    /** @var list<string> */
    public array $mutations = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $passwords = [];

    /** @var array<string, string> */
    public array $failures = [];

    /** @var array<string, string> */
    public array $overrides = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $bridge = str_contains((string) $request->getUri(), 'uapi_cpanel');
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $function = $bridge ? $query['cpanel_function'] : basename($request->getUri()->getPath());
        if (!is_string($function)) {
            throw new LogicException('Unknown synthetic function.');
        }
        $key = ($bridge ? 'bridge:' : '') . $function;
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $step = $key . ':' . $this->counts[$key];
        $this->steps[] = $step;
        $failure = $this->failures[$step] ?? '';
        if ($failure === 'throw_before') {
            self::failTransport();
        }
        if ($failure === 'api_before') {
            return self::response('null', false, false);
        }
        if ($function === 'version') {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.15"}}');
        }
        $parameters = [];
        parse_str((string) $request->getBody(), $parameters);
        $name = $parameters['name'] ?? '';
        if (!is_string($name)) {
            throw new LogicException('Unexpected synthetic name.');
        }
        if ($request->getMethod() === 'POST') {
            $this->mutations[] = $function;
        }
        switch ($function) {
            case 'create_database':
                $this->databases[$name] = [];
                break;
            case 'create_user':
                $this->users[$name] = [];
                if (is_string($parameters['password'] ?? null)) {
                    $this->passwords[] = $parameters['password'];
                }
                break;
            case 'set_privileges_on_database':
                $this->databases['npr_wva1b2c3d'] = ['npr_wva1b2c3u'];
                $this->users['npr_wva1b2c3u'] = ['npr_wva1b2c3d'];
                break;
            case 'delete_user':
                unset($this->users[$name]);
                foreach ($this->databases as &$users) {
                    $users = array_values(array_filter($users, static fn (string $user): bool => $user !== $name));
                }
                unset($users);
                $this->deleted[] = $name;
                break;
            case 'delete_database':
                unset($this->databases[$name]);
                $this->deleted[] = $name;
                break;
        }
        if ($failure === 'throw_after') {
            self::failTransport();
        }
        if ($failure === 'api_after') {
            return self::response('null', false, false);
        }
        $data = null;
        if ($function === 'list_databases') {
            $data = [];
            foreach ($this->databases as $database => $users) {
                $data[] = ['database' => $database, 'disk_usage' => 0, 'users' => $users];
            }
        } elseif ($function === 'list_users') {
            $data = [];
            foreach ($this->users as $user => $databases) {
                $data[] = ['user' => $user, 'shortuser' => substr($user, 4), 'databases' => $databases];
            }
        }

        return self::response($this->overrides[$step] ?? json_encode($data, JSON_THROW_ON_ERROR), $bridge, true);
    }

    private static function response(string $payload, bool $bridge, bool $success): ResponseInterface
    {
        $result = '{"status":' . ($success ? '1' : '0') . ',"data":' . $payload
            . ',"errors":' . ($success ? 'null' : '["private upstream error"]') . '}';

        return new Response(200, [], $bridge ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : $result);
    }

    private static function failTransport(): never
    {
        throw new class ('private transport failure') extends RuntimeException implements ClientExceptionInterface {
        };
    }
}
