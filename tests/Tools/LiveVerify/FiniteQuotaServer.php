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

/** Minimal independent account/token state double, with failures before or after real fixture side effects. */
final class FiniteQuotaServer implements ClientInterface
{
    public bool $account = false;

    public bool $zone = false;

    public bool $retainZone = false;

    public ?string $concurrentContactEmail = null;

    private string $contactEmail = '';

    /** @var list<string> */
    public array $tokens = [];

    /** @var array<string, mixed> Synthetic optional token fields, independently configurable for setup validation. */
    public array $tokenFields = [];

    private int $expiresAt = 0;

    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $mutations = [];

    /** @var array<string, string> */
    public array $failures = [];

    /** @var array<string, string> */
    public array $overrides = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $path = $request->getUri()->getPath();
        $function = basename($path);
        $bridge = $function === 'uapi_cpanel';
        if ($bridge) {
            if (!is_string($query['cpanel_module'] ?? null) || !is_string($query['cpanel_function'] ?? null)) {
                throw new LogicException('Synthetic bridge request is malformed.');
            }
            $function = $query['cpanel_module'] . '/' . $query['cpanel_function'];
        } elseif (str_contains($path, '/execute/Quota/')) {
            $function = 'direct:Quota/' . $function;
        }
        $this->counts[$function] = ($this->counts[$function] ?? 0) + 1;
        $step = $function . ':' . $this->counts[$function];
        $failure = $this->failures[$step] ?? '';
        if ($function === 'createacct' && $this->concurrentContactEmail !== null) {
            $this->account = true;
            $this->zone = true;
            $this->contactEmail = $this->concurrentContactEmail;
        }
        if ($failure === 'throw_before') {
            self::failTransport();
        }
        if ($failure === 'api_before') {
            return self::response('null', $bridge, false);
        }
        if ($request->getMethod() === 'POST') {
            $this->mutations[] = $function;
        }
        switch ($function) {
            case 'createacct':
                $this->account = true;
                $this->zone = true;
                $parameters = [];
                parse_str((string) $request->getBody(), $parameters);
                $email = $parameters['contactemail'] ?? '';
                if (!is_string($email)) {
                    throw new LogicException('Synthetic account ownership is malformed.');
                }
                $this->contactEmail = $email;
                break;
            case 'Tokens/create_full_access':
                $this->tokens[] = 'wqfinite-quota-readonly';
                $parameters = [];
                parse_str((string) $request->getBody(), $parameters);
                if (!is_string($parameters['expires_at'] ?? null)) {
                    throw new LogicException('Synthetic token expiration is missing.');
                }
                $this->expiresAt = (int) $parameters['expires_at'];
                break;
            case 'Tokens/revoke':
                $this->tokens = array_values(array_filter($this->tokens, static fn (string $name): bool =>
                    $name !== 'wqfinite-quota-readonly'));
                break;
            case 'removeacct':
                $this->account = false;
                $this->tokens = [];
                if (!$this->retainZone) {
                    $this->zone = false;
                }
                break;
        }
        if ($failure === 'throw_after') {
            self::failTransport();
        }
        if ($failure === 'api_after') {
            return self::response('null', $bridge, false);
        }
        $data = match ($function) {
            'version' => ['version' => '11.138.0.15'],
            'listaccts' => ['acct' => array_merge([
                ['user' => 'existing', 'domain' => 'existing.example', 'email' => 'private-existing@example.com'],
                ['user' => 'wqfiniteextra', 'domain' => 'wqfinite.example.com.other'],
            ], $this->account
                ? [['user' => 'wqfinite', 'domain' => 'wqfinite.example.com', 'email' => $this->contactEmail]] : [])],
            'listzones' => ['zone' => $this->zone
                ? [['domain' => 'wqfinite.example.com', 'zonefile' => 'private.db']] : []],
            'get_domain_info' => ['domains' => $this->account
                ? [['domain' => 'wqfinite.example.com', 'user' => 'wqfinite']] : []],
            'Tokens/list' => array_map(fn (string $name): array => array_replace([
                'name' => $name, 'readonly' => 1, 'expires_at' => $this->expiresAt,
            ], $this->tokenFields), $this->tokens),
            'Tokens/create_full_access' => ['token' => 'private-fixture-token', 'create_time' => 1790000000],
            'Tokens/revoke' => 1,
            'Quota/get_quota_info', 'direct:Quota/get_quota_info' => [
                'megabyte_limit' => 64, 'megabytes_used' => 4, 'megabytes_remain' => 60,
                'under_megabyte_limit' => 1, 'under_inode_limit' => 1, 'under_quota_overall' => 1,
                'inode_limit' => 0, 'inodes_used' => 24, 'inodes_remain' => 0,
            ],
            'Quota/get_local_quota_info', 'direct:Quota/get_local_quota_info' => [
                'byte_limit' => 67108864, 'bytes_used' => 4194304, 'inode_limit' => 0, 'inodes_used' => 24,
            ],
            default => null,
        };
        $payload = $this->overrides[$step] ?? json_encode($data, JSON_THROW_ON_ERROR);
        if (str_starts_with($function, 'direct:')) {
            return new Response(200, [], '{"status":1,"data":' . $payload . '}');
        }

        return self::response($payload, $bridge, true);
    }

    private static function response(string $payload, bool $bridge, bool $success): ResponseInterface
    {
        $metadata = '"metadata":{"result":' . ($success ? '1' : '0') . ',"reason":"private upstream text"}';
        if ($bridge) {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"uapi":{"status":'
                . ($success ? '1' : '0') . ',"data":' . $payload . ',"errors":["private upstream text"]}}}');
        }

        return new Response(200, [], '{' . $metadata . ',"data":' . $payload . '}');
    }

    private static function failTransport(): never
    {
        throw new class ('private transport failure') extends RuntimeException implements ClientExceptionInterface {
        };
    }
}
