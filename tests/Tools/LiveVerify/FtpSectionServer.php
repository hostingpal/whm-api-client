<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;

/** Independent stateful FTP double: no generated specs or recipe expectations. */
final class FtpSectionServer implements ClientInterface
{
    /** @var array<string, array{directory: string, quota: int}> */
    public array $accounts = [];

    /** @var list<array{route: string, function: string, method: string, parameters: array<array-key, mixed>}> */
    public array $requests = [];

    /** @var list<string> */
    public array $deleted = [];

    public function __construct(public string $fault = '')
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $bridge = $request->getUri()->getPort() === 2087;
        $query = [];
        $parameters = [];
        parse_str($request->getUri()->getQuery(), $query);
        parse_str((string) $request->getBody(), $parameters);
        $function = $bridge ? ($query['cpanel_function'] ?? 'version') : basename($request->getUri()->getPath());
        if (!is_string($function)) {
            throw new RuntimeException('Unexpected function.');
        }
        foreach ($query as $key => $value) {
            if (is_string($key) && !str_starts_with($key, 'cpanel_') && $key !== 'api_version') {
                $parameters[$key] = $value;
            }
        }
        $route = $bridge ? 'bridged-uapi' : 'direct-uapi';
        $this->requests[] = ['route' => $route, 'function' => $function,
            'method' => $request->getMethod(), 'parameters' => $parameters];
        if ($function === 'version') {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}');
        }
        $user = $parameters['user'] ?? $parameters['account'] ?? '';
        if (!is_string($user)) {
            throw new RuntimeException('Unexpected user.');
        }
        $status = 1;
        $data = null;
        switch ($function) {
            case 'list_ftp':
                $data = [];
                foreach ($this->accounts as $name => $account) {
                    $identity = $this->fault === 'full_identity' ? $name . '@npr.rs' : $name;
                    $data[] = ['user' => $identity, 'type' => 'sub', 'homedir' => $account['directory']];
                    if ($this->fault === 'ambiguous_identity') {
                        $data[] = ['user' => $name . '@npr.rs', 'type' => 'sub', 'homedir' => $account['directory']];
                    }
                }
                if ($this->fault === 'malformed_inventory') {
                    $data[] = ['homedir' => '/private/unowned'];
                }
                break;
            case 'add_ftp':
                if ($this->fault === 'create_transport_absent' && !$bridge) {
                    throw new RuntimeException('private creation response lost before inventory visibility');
                }
                if ($this->fault === 'create_race' && !$bridge) {
                    $this->accounts[$user] = ['directory' => '/private/unowned', 'quota' => 0];
                    $status = 0;
                } else {
                    $this->accounts[$user] = ['directory' => '/home/npr', 'quota' => 0];
                }
                if ($this->fault === 'create_transport' && !$bridge) {
                    throw new RuntimeException('private creation response lost');
                }
                if ($this->fault === 'create_payload' && !$bridge) {
                    $data = 'private invalid payload';
                }
                break;
            case 'delete_ftp':
                if (($parameters['destroy'] ?? null) !== '0' || ($parameters['domain'] ?? null) !== 'npr.rs') {
                    throw new RuntimeException('Unsafe deletion.');
                }
                $this->deleted[] = $user;
                if ($this->fault === 'delete_failure' && !$bridge) {
                    $status = 0;
                } else {
                    unset($this->accounts[$user]);
                }
                break;
            case 'set_homedir':
                $this->accounts[$user]['directory'] = $this->fault === 'normalized_home' ? '/home/npr/./' : '/home/npr';
                break;
            case 'set_quota':
                $this->accounts[$user]['quota'] = 17;
                break;
            case 'get_quota':
                $quota = $this->accounts[$user]['quota'];
                $data = $quota === 0 ? 'unlimited' : (string) $quota;
                if ($this->fault === 'quota_float' && $quota !== 0) {
                    $data = $quota + 0.5;
                }
                if ($this->fault === 'quota_integer' && $quota !== 0) {
                    $data = $quota;
                }
                if ($this->fault === 'wrong_quota' && $quota !== 0) {
                    $data = '18';
                }
                break;
            case 'list_ftp_with_disk':
                $data = [];
                foreach ($this->accounts as $name => $account) {
                    $data[] = ['_diskquota' => '17.00', '_diskused' => '0.00', 'accttype' => 'sub',
                        'deleteable' => 1, 'dir' => $account['directory'], 'diskquota' => '17.00',
                        'diskused' => '0.00', 'diskusedpercent' => 0, 'diskusedpercent20' => 0,
                        'htmldir' => null, 'humandiskquota' => '17 MB', 'humandiskused' => '0 MB',
                        'login' => $this->fault === 'full_identity' ? $name . '@npr.rs' : $name,
                        'reldir' => '', 'serverlogin' => $name . '@npr.rs'];
                }
                break;
            case 'ftp_exists':
            case 'passwd':
                break;
            case 'get_ftp_daemon_info':
                $data = ['enabled' => 1, 'name' => 'pure-ftpd',
                    'supports' => ['quota' => 1, 'login_without_domain' => 0]];
                if ($this->fault === 'unknown_field') {
                    $data['private-secret'] = 'private value';
                }
                break;
            case 'get_port':
                $data = ['port' => 21];
                break;
            case 'get_welcome_message':
                $data = 'private welcome';
                break;
            case 'server_name':
                $data = 'pure-ftpd';
                break;
            case 'allows_anonymous_ftp':
            case 'allows_anonymous_ftp_incoming':
                $data = ['allows' => 0];
                break;
            case 'list_sessions':
                $data = [];
                break;
            default:
                throw new RuntimeException('Unplanned function.');
        }
        $result = ['status' => $status, 'data' => $data, 'errors' => $status === 1 ? null : ['private failure'],
            'messages' => null, 'warnings' => null, 'metadata' => new \stdClass()];
        $envelope = $bridge ? ['metadata' => ['result' => 1], 'data' => ['uapi' => $result]] : ['result' => $result];

        return new Response(200, [], json_encode($envelope, JSON_THROW_ON_ERROR));
    }
}
