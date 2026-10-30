<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;

/** Independent in-memory SSL storage double; no expectations are read from generated operation specs. */
final class SslSectionServer implements ClientInterface
{
    /** @var array<string, array{id: string, modulus: string, pem: string}> */
    public array $keys = [];
    /** @var array<string, array{id: string, modulus: string, pem: string}> */
    public array $certificates = [];
    /** @var array<string, list<array<string, mixed>>> Unrelated inventory rows never created by this fixture. */
    public array $existingInventory = ['list_keys' => [], 'list_certs' => []];
    /** @var array<string, int> */
    public array $counts = [];

    public function __construct(private readonly string $failure = '')
    {
    }

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $bridge = $request->getUri()->getPort() === 2087;
        $query = [];
        parse_str(str_replace('cpanel.', 'cpanel_', $request->getUri()->getQuery()), $query);
        $function = $bridge ? ($query['cpanel_function'] ?? 'version') : basename($request->getUri()->getPath());
        if (!is_string($function)) {
            throw new RuntimeException('Invalid fixture operation.');
        }
        $this->counts[$function] = ($this->counts[$function] ?? 0) + 1;
        $fields = [];
        if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/form-data')) {
            preg_match_all(
                '/Content-Disposition: form-data; name="([a-z_]+)"\r\n\r\n(.*?)\r\n--/s',
                (string) $request->getBody(),
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                $fields[$match[1]] = $match[2];
            }
        } else {
            parse_str((string) $request->getBody(), $fields);
        }
        $status = 1;
        $data = null;
        switch ($function) {
            case 'version':
                return new Response(200, [], '{"metadata":{"result":1},"data":{"version":"11.138.0.19"}}');
            case 'list_keys':
            case 'list_certs':
                $rows = $function === 'list_keys' ? $this->keys : $this->certificates;
                $data = array_values(array_map(static fn (array $row): array => [
                    'id' => $row['id'], 'modulus' => $row['modulus'], 'friendly_name' => 'fixture label',
                ], $rows));
                $data = [...$data, ...$this->existingInventory[$function]];
                if ($this->failure === 'preflight') {
                    $status = 0;
                }
                break;
            case 'upload_key':
            case 'upload_cert':
                $isKey = $function === 'upload_key';
                $pem = $fields[$isKey ? 'key' : 'crt'] ?? null;
                if (!is_string($pem)) {
                    throw new RuntimeException('Fixture upload lacks PEM.');
                }
                $public = $isKey ? openssl_pkey_get_private($pem) : openssl_pkey_get_public($pem);
                if ($public === false) {
                    throw new RuntimeException('Fixture PEM invalid.');
                }
                $details = openssl_pkey_get_details($public);
                $rsa = $details !== false ? ($details['rsa'] ?? null) : null;
                if (!is_array($rsa) || !is_string($rsa['n'] ?? null)) {
                    throw new RuntimeException('Fixture RSA invalid.');
                }
                $id = 'owned-' . ($isKey ? 'key-' : 'cert-') . ($bridge ? 'bridge' : 'direct');
                $row = ['id' => $id, 'modulus' => bin2hex($rsa['n']), 'pem' => $pem];
                if ($isKey) {
                    $this->keys[$id] = $row;
                } else {
                    $this->certificates[$id] = $row;
                }
                $data = [['id' => $id, 'modulus' => $row['modulus']]];
                if (($isKey && $this->failure === 'key_api') || (!$isKey && $this->failure === 'cert_api')) {
                    $status = 0;
                }
                if (($isKey && $this->failure === 'key_payload') || (!$isKey && $this->failure === 'cert_payload')) {
                    $data[0]['created'] = 'malformed';
                }
                if ($isKey && $this->failure === 'key_missing_id') {
                    unset($data[0]['id']);
                }
                if ($isKey && $this->failure === 'key_duplicate') {
                    $data[] = $data[0];
                }
                break;
            case 'fetch_key_and_cabundle_for_certificate':
                $id = 'owned-key-' . ($bridge ? 'bridge' : 'direct');
                $data = ['cab' => '', 'crt' => $fields['certificate'] ?? '',
                    'key' => $this->keys[$id]['pem'], 'status' => 1];
                if ($this->failure === 'fetch') {
                    $status = 0;
                }
                break;
            case 'delete_key':
            case 'delete_cert':
                $id = $fields['id'] ?? null;
                if (!is_string($id)) {
                    throw new RuntimeException('Fixture deletion lacks exact identity.');
                }
                if ($function === 'delete_key') {
                    unset($this->keys[$id]);
                } else {
                    if ($this->failure === 'cert_cleanup') {
                        $status = 0;
                    } else {
                        unset($this->certificates[$id]);
                    }
                }
                $data = [];
                break;
            default:
                throw new RuntimeException('Unreviewed fixture operation.');
        }
        $result = ['status' => $status, 'data' => $data, 'errors' => $status === 1 ? null : ['fixture failure']];
        return new Response(200, [], json_encode($bridge
            ? ['metadata' => ['result' => 1], 'data' => ['uapi' => $result]]
            : ['result' => $result], JSON_THROW_ON_ERROR));
    }
}
