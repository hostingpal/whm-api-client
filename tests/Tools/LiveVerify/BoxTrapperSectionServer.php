<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Literal synthetic server, independent of recipe/specs; unlisted operations cannot execute. */
final class BoxTrapperSectionServer implements ClientInterface
{
    /** @var array<string,array{status:int,configuration:array<string,int|float|string>,rules:array<string,list<string>>}> */
    public array $mailboxes = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string,string> function/route/occurrence -> before, after, lost or delayed. */
    public array $failures = [];

    /** @var array<string,string> function/route/occurrence -> literal JSON payload. */
    public array $overrides = [];

    /** @var array<string,int> */
    private array $counts = [];

    /** @var array<string,array{status:int,configuration:array<string,int|float|string>,rules:array<string,list<string>>}> */
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
        $address = str_contains($email, '@') ? $email : $email . '@' . self::parameter($parameters, 'domain');
        $payload = null;
        switch ($function) {
            case 'list_pops':
                $payload = [];
                foreach ($this->mailboxes as $identity => $mailbox) {
                    $payload[] = ['email' => $identity, 'login' => $identity];
                }
                break;
            case 'list_mail_domains':
                $payload = [['domain' => 'npr.rs']];
                break;
            case 'add_pop':
                if (isset($this->mailboxes[$address]) || self::parameter($parameters, 'send_welcome_email') !== '0') {
                    throw new LogicException('Unsafe synthetic creation.');
                }
                $mailbox = ['status' => 0, 'configuration' => [], 'rules' => []];
                if ($failure === 'delayed') {
                    $this->delayed[$address] = $mailbox;
                    throw new RuntimeException('private delayed creation');
                }
                $this->mailboxes[$address] = $mailbox;
                $payload = str_replace('@', '+', $address);
                break;
            case 'delete_pop':
                unset($this->mailboxes[$address]);
                $this->deleted[] = $address;
                break;
            case 'get_status':
                $payload = $this->mailboxes[$address]['status'];
                break;
            case 'set_status':
                if (self::parameter($parameters, 'enabled') !== '0') {
                    throw new LogicException('Fixture must never enable BoxTrapper.');
                }
                $this->mailboxes[$address]['status'] = 0;
                break;
            case 'save_configuration':
                if ($this->mailboxes[$address]['status'] !== 0) {
                    throw new LogicException('Fixture settings require disabled state.');
                }
                $this->mailboxes[$address]['configuration'] = [
                    'enable_auto_whitelist' => (int) self::parameter($parameters, 'enable_auto_whitelist'),
                    'from_addresses' => self::parameter($parameters, 'from_addresses'),
                    'from_name' => self::parameter($parameters, 'from_name'),
                    'queue_days' => (int) self::parameter($parameters, 'queue_days'),
                    'spam_score' => (float) self::parameter($parameters, 'spam_score'),
                    'whitelist_by_association' => (int) self::parameter($parameters, 'whitelist_by_association'),
                ];
                break;
            case 'get_configuration':
                $payload = $this->mailboxes[$address]['configuration'];
                break;
            case 'set_allowlist':
            case 'set_blocklist':
            case 'set_ignorelist':
                $this->mailboxes[$address]['rules'][substr($function, 4)] = [self::parameter($parameters, 'rules')];
                break;
            case 'get_allowlist':
            case 'get_blocklist':
            case 'get_ignorelist':
                $payload = $this->mailboxes[$address]['rules'][substr($function, 4)] ?? [];
                break;
            case 'list_email_templates':
                $payload = ['blacklist', 'returnverify', 'verify', 'verifyreleased'];
                break;
            case 'get_email_template':
                $payload = "To: %email%\nSubject: verify#%msgid%\nprivate template";
                break;
            case 'get_log':
                $payload = ['date' => 0, 'lines' => [], 'path' => ''];
                break;
            case 'list_queued_messages':
                $payload = [];
                break;
            default:
                throw new LogicException('Forbidden synthetic operation.');
        }
        if ($failure === 'lost') {
            throw new RuntimeException('private lost response');
        }

        return self::response(
            $this->overrides[$key] ?? json_encode($payload, JSON_THROW_ON_ERROR),
            $bridge,
            $failure !== 'after',
        );
    }

    public function completeDelayed(): void
    {
        $this->mailboxes += $this->delayed;
        $this->delayed = [];
    }

    /** @param array<mixed> $parameters */
    private static function parameter(array $parameters, string $name): string
    {
        $value = $parameters[$name] ?? '';
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
