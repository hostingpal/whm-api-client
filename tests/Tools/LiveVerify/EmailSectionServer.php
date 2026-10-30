<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Independent mailbox state and literal synthetic payloads; no recipe or generated specs are consulted. */
final class EmailSectionServer implements ClientInterface
{
    /** @var array<string, array{quota: int, password: string, suspended: int}> */
    public array $mailboxes = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string, string> Function/route/occurrence to raw JSON payload. */
    public array $overrides = [];

    /** @var array<string, string> Function/route/occurrence to before/after/lost/delayed failure. */
    public array $failures = [];

    /** @var array<string, array{quota: int, password: string, suspended: int}> */
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
        $email = self::parameter($parameters, 'email');
        $domain = self::parameter($parameters, 'domain');
        $address = str_contains($email, '@') ? $email : $email . '@' . $domain;
        $payload = null;
        switch ($function) {
            case 'list_pops':
                $payload = [];
                foreach ($this->mailboxes as $name => $mailbox) {
                    $payload[] = ['email' => $name, 'login' => $name,
                        'suspended_incoming' => 0, 'suspended_login' => $mailbox['suspended']];
                }
                break;
            case 'count_pops':
                $payload = count($this->mailboxes);
                break;
            case 'list_mail_domains':
                $payload = [['domain' => 'npr.rs']];
                break;
            case 'list_domain_forwarders':
            case 'list_forwarders':
            case 'list_mxs':
                $payload = [];
                break;
            case 'get_default_email_quota':
            case 'get_max_email_quota':
                $payload = 33554432;
                break;
            case 'add_pop':
                if (isset($this->mailboxes[$address]) || self::parameter($parameters, 'send_welcome_email') !== '0') {
                    throw new LogicException('Unsafe synthetic mailbox creation.');
                }
                $mailbox = ['quota' => 32, 'password' => self::parameter($parameters, 'password'), 'suspended' => 0];
                if ($failure === 'delayed') {
                    $this->delayed[$address] = $mailbox;
                    throw new RuntimeException('private creation remains in flight');
                }
                $this->mailboxes[$address] = $mailbox;
                $payload = $email . '+' . $domain;
                break;
            case 'get_pop_quota':
                $payload = $this->mailboxes[$address]['quota'];
                break;
            case 'passwd_pop':
                $this->mailboxes[$address]['password'] = self::parameter($parameters, 'password');
                break;
            case 'verify_password':
                $payload = (int) ($this->mailboxes[$address]['password'] === self::parameter($parameters, 'password'));
                break;
            case 'edit_pop_quota':
                $this->mailboxes[$address]['quota'] = 64;
                break;
            case 'suspend_login':
                $this->mailboxes[$address]['suspended'] = 1;
                break;
            case 'unsuspend_login':
                $this->mailboxes[$address]['suspended'] = 0;
                break;
            case 'delete_pop':
                unset($this->mailboxes[$address]);
                $this->deleted[] = $address;
                break;
            default:
                throw new LogicException('Unexpected synthetic Email function.');
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

    /** Complete an uncertain create after the final inventory has already observed absence. */
    public function completeDelayed(): void
    {
        $this->mailboxes += $this->delayed;
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
