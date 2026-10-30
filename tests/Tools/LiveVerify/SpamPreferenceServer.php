<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Literal synthetic server, independent of recipe/specs; no real network access. */
final class SpamPreferenceServer implements ClientInterface
{
    /** @var array<string,mixed> */
    public array $preferences = ['required_score' => ['5.0'], 'private_custom_name' => ['private_value']];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var array<string,string> function/route/occurrence -> before, after, lost or delayed. */
    public array $failures = [];

    /** @var array<string,string> function/route/occurrence -> literal JSON payload. */
    public array $overrides = [];

    /** @var array<string,int> */
    private array $counts = [];

    /** @var list<array<mixed>> */
    private array $delayed = [];

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
        $key = $function . '/' . ($bridge ? 'bridge' : 'direct');
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $key .= '/' . $this->counts[$key];
        $failure = $this->failures[$key] ?? '';
        if ($failure === 'before') {
            return self::response('null', $bridge, false);
        }
        switch ($function) {
            case 'update_user_preference':
                parse_str((string) $request->getBody(), $parameters);
                if ($request->getMethod() !== 'POST' || ($parameters['preference'] ?? null) !== 'required_score') {
                    throw new LogicException('Forbidden synthetic preference write.');
                }
                if ($failure === 'delayed') {
                    $this->delayed[] = $parameters;
                    throw new RuntimeException('private delayed write');
                }
                $this->apply($parameters);
                $payload = json_encode((object) $this->preferences, JSON_THROW_ON_ERROR);
                break;
            case 'get_user_preferences':
                $payload = json_encode((object) $this->preferences, JSON_THROW_ON_ERROR);
                break;
            case 'has_greylisting_enabled':
                $payload = '{}';
                break;
            case 'list_email_templates':
            case 'validate_current_dkims':
            case 'validate_current_dmarcs':
            case 'validate_current_ptrs':
            case 'validate_current_spfs':
            case 'get_symbolic_test_names':
            case 'list_domains':
                $payload = '[]';
                break;
            default:
                throw new LogicException('Forbidden synthetic operation.');
        }
        if ($failure === 'lost') {
            throw new RuntimeException('private lost response');
        }

        return self::response($this->overrides[$key] ?? $payload, $bridge, $failure !== 'after');
    }

    public function completeDelayed(): void
    {
        foreach ($this->delayed as $parameters) {
            $this->apply($parameters);
        }
        $this->delayed = [];
    }

    /** @param array<mixed> $parameters */
    private function apply(array $parameters): void
    {
        if (array_key_exists('value', $parameters)) {
            if (!is_string($parameters['value'])) {
                throw new LogicException('Invalid synthetic preference value.');
            }
            $this->preferences['required_score'] = [$parameters['value']];
        } else {
            unset($this->preferences['required_score']);
        }
    }

    private static function response(string $payload, bool $bridge, bool $success): ResponseInterface
    {
        $result = '{"status":' . ($success ? '1' : '0') . ',"data":' . $payload
            . ',"errors":' . ($success ? 'null' : '["private upstream failure"]') . '}';

        return new Response(200, [], $bridge ? '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}' : $result);
    }
}
