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
 * with a digit-string expiry in list rows, null rename data and a 1/0 revoke result. It also plays the rest of a
 * shared account: another party's token can appear between two of the run's requests, and a request whose reply was
 * lost can take effect later.
 *
 * @phpstan-type Token array{create_time:int,expires_at:string|null,readonly:int}
 */
final class TokensSectionServer implements ClientInterface
{
    public const SECRET = 'SYNTHETICSECRET0123456789ABCDEFG';

    public const FOREIGN = ['create_time' => 1794990000, 'expires_at' => null, 'readonly' => 0];

    /** @var array<string,Token> */
    public array $tokens = ['existing' => ['create_time' => 1791912500, 'expires_at' => null, 'readonly' => 0]];

    /** @var array<string,mixed> The account's notification targets, as Variables/get_user_information reports them. */
    public array $contact = ['contact_email' => '', 'contact_email_2' => '', 'pushbullet_access_token' => ''];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * function/route/occurrence -> api (refused, no effect), lost (applied, no reply) or delayed (no reply, and
     * applied only by completeDelayed()).
     *
     * @var array<string,string>
     */
    public array $failures = [];

    /** @var array<string,string> function/route/occurrence -> literal JSON payload returned instead. */
    public array $overrides = [];

    /** @var array<string,string> function/route/occurrence -> name of another party's token created just before it. */
    public array $arrivals = [];

    /** @var Token The token another party creates; a test can give it the run's own settings, the hardest case. */
    public array $foreign = self::FOREIGN;

    /** @var array<string,int> */
    private array $counts = [];

    /** @var list<array<mixed>> */
    private array $delayed = [];

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
        if ($module !== ($function === 'get_user_information' ? 'Variables' : 'Tokens')) {
            throw new LogicException('Forbidden synthetic module.');
        }
        $key = $function . '/' . ($bridge ? 'bridge' : 'direct');
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $key .= '/' . $this->counts[$key];
        if (isset($this->arrivals[$key])) {
            $this->tokens[$this->arrivals[$key]] = $this->foreign;
        }
        $failure = $this->failures[$key] ?? '';
        if ($failure === 'api') {
            return self::response('null', $bridge, false);
        }
        parse_str((string) $request->getBody(), $body);
        $read = in_array($function, ['list', 'get_user_information'], true);
        if ($read !== ($request->getMethod() === 'GET') || ($read && (string) $request->getBody() !== '')) {
            throw new LogicException('Forbidden synthetic request.');
        }
        if ($failure === 'delayed') {
            $this->delayed[] = ['function' => $function] + $body;
            throw new RuntimeException('private delayed reply');
        }
        if ($function === 'get_user_information') {
            $names = 'name=contact_email&name=contact_email_2&name=pushbullet_access_token';
            if (!str_ends_with($request->getUri()->getQuery(), $names)) {
                throw new LogicException('Forbidden synthetic variable selection.');
            }
            $payload = json_encode((object) $this->contact, JSON_THROW_ON_ERROR);
            $refused = false;
        } elseif ($function === 'list') {
            $rows = [];
            foreach ($this->tokens as $tokenName => $token) {
                $rows[] = ['readonly' => $token['readonly'], 'whitelist_ips' => null,
                    'create_time' => $token['create_time'], 'expires_at' => $token['expires_at'],
                    'features' => [], 'has_full_access' => 1, 'name' => $tokenName];
            }
            $payload = json_encode($rows, JSON_THROW_ON_ERROR);
            $refused = false;
        } else {
            [$payload, $refused] = $this->apply($function, $body);
        }
        if ($failure === 'lost') {
            throw new RuntimeException('private lost response');
        }

        return self::response($this->overrides[$key] ?? $payload, $bridge, !$refused, $function === 'list');
    }

    /** Let every request whose reply was delayed take effect now, as a timed-out request can on a real server. */
    public function completeDelayed(): void
    {
        foreach ($this->delayed as $request) {
            $function = $request['function'];
            if (is_string($function)) {
                $this->apply($function, $request);
            }
        }
        $this->delayed = [];
    }

    /**
     * @param array<mixed> $body
     * @return array{string, bool} The JSON payload and whether the server refused the mutation.
     */
    private function apply(string $function, array $body): array
    {
        $name = $body['name'] ?? '';
        if (!is_string($name) || $name === '') {
            throw new LogicException('Forbidden synthetic token name.');
        }
        switch ($function) {
            case 'create_full_access':
                $expiresAt = $body['expires_at'] ?? null;
                if (isset($this->tokens[$name]) || ($expiresAt !== null && !is_string($expiresAt))) {
                    return ['null', true];
                }
                $this->tokens[$name] = ['create_time' => 1794993667, 'expires_at' => $expiresAt,
                    'readonly' => ($body['readonly'] ?? '0') === '1' ? 1 : 0];

                return ['{"create_time":1794993667,"whitelist_ips":null,"token":"' . self::SECRET . '"}', false];
            case 'rename':
                $newName = $body['new_name'] ?? '';
                if (
                    !is_string($newName) || $newName === '' || $newName === $name
                    || !isset($this->tokens[$name]) || isset($this->tokens[$newName])
                ) {
                    return ['null', true];
                }
                $this->tokens[$newName] = $this->tokens[$name];
                unset($this->tokens[$name]);

                return ['null', false];
            case 'revoke':
                $payload = isset($this->tokens[$name]) ? '1' : '0';
                unset($this->tokens[$name]);

                return [$payload, false];
            default:
                throw new LogicException('Forbidden synthetic operation.');
        }
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
