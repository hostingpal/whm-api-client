<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Literal synthetic token store in the wire layout observed on cPanel 11.138.0.24; no real network access.
 *
 * Independent of the recipe and the generated specs: it parses each request itself and answers as the server did,
 * with a digit-string expiry in list rows, null rename data and a 1/0 revoke result.
 */
final class TokensSectionServer implements ClientInterface
{
    public const SECRET = 'SYNTHETICSECRET0123456789ABCDEFG';

    /** @var array<string,array{create_time:int,expires_at:string|null,readonly:int}> */
    public array $tokens = ['existing' => ['create_time' => 1791912500, 'expires_at' => null, 'readonly' => 0]];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var array<string,string> function/route/occurrence -> api (refused, no effect) or lost (applied, no reply). */
    public array $failures = [];

    /** @var array<string,string> function/route/occurrence -> literal JSON payload returned instead. */
    public array $overrides = [];

    /** @var array<string,int> */
    private array $counts = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $bridge = $request->getUri()->getPort() === 2087;
        parse_str($request->getUri()->getQuery(), $query);
        $function = $query['cpanel_function'] ?? basename($request->getUri()->getPath());
        if (!is_string($function)) {
            throw new LogicException('Invalid synthetic function.');
        }
        if ($function === 'version') {
            return new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.24"}}');
        }
        $module = $bridge ? ($query['cpanel_module'] ?? null) : basename(dirname($request->getUri()->getPath()));
        if ($module !== 'Tokens') {
            throw new LogicException('Forbidden synthetic module.');
        }
        $key = $function . '/' . ($bridge ? 'bridge' : 'direct');
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $key .= '/' . $this->counts[$key];
        $failure = $this->failures[$key] ?? '';
        if ($failure === 'api') {
            return self::response('null', $bridge, false);
        }
        parse_str((string) $request->getBody(), $body);
        $name = $body['name'] ?? '';
        if (($function === 'list') !== ($request->getMethod() === 'GET') || !is_string($name)) {
            throw new LogicException('Forbidden synthetic request.');
        }
        $refused = false;
        switch ($function) {
            case 'list':
                $rows = [];
                foreach ($this->tokens as $tokenName => $token) {
                    $rows[] = ['readonly' => $token['readonly'], 'whitelist_ips' => null,
                        'create_time' => $token['create_time'], 'expires_at' => $token['expires_at'],
                        'features' => [], 'has_full_access' => 1, 'name' => $tokenName];
                }
                $payload = json_encode($rows, JSON_THROW_ON_ERROR);
                break;
            case 'create_full_access':
                $expiresAt = $body['expires_at'] ?? null;
                if (isset($this->tokens[$name]) || ($expiresAt !== null && !is_string($expiresAt))) {
                    $refused = true;
                    $payload = 'null';
                    break;
                }
                $this->tokens[$name] = ['create_time' => 1794993667, 'expires_at' => $expiresAt,
                    'readonly' => ($body['readonly'] ?? '0') === '1' ? 1 : 0];
                $payload = '{"create_time":1794993667,"whitelist_ips":null,"token":"' . self::SECRET . '"}';
                break;
            case 'rename':
                $newName = $body['new_name'] ?? '';
                if (!is_string($newName) || !isset($this->tokens[$name]) || $newName === $name) {
                    $refused = true;
                    $payload = 'null';
                    break;
                }
                $this->tokens[$newName] = $this->tokens[$name];
                unset($this->tokens[$name]);
                $payload = 'null';
                break;
            case 'revoke':
                $payload = isset($this->tokens[$name]) ? '1' : '0';
                unset($this->tokens[$name]);
                break;
            default:
                throw new LogicException('Forbidden synthetic operation.');
        }
        if ($failure === 'lost') {
            throw new RuntimeException('private lost response');
        }

        return self::response($this->overrides[$key] ?? $payload, $bridge, !$refused, $function === 'list');
    }

    private static function response(
        string $payload,
        bool $bridge,
        bool $success,
        bool $list = false,
    ): ResponseInterface {
        $result = '{"messages":null,"status":' . ($success ? '1' : '0')
            . ',"metadata":' . ($list ? '{"transformed":1}' : '[]') . ',"data":' . $payload
            . ',"errors":' . ($success ? 'null' : '["private upstream failure"]') . ',"warnings":null}';

        return new Response(200, [], $bridge
            ? '{"data":{"uapi":' . $result . '},'
                . '"metadata":{"version":1,"result":1,"reason":"OK","command":"uapi_cpanel"}}'
            : $result);
    }
}
