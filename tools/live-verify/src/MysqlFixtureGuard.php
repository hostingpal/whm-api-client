<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * Restrict fixture traffic to two inventories and exact mutations on four run-bound resources.
 *
 * Both opt-ins are checked on every send. Exact canonical bodies reject duplicate, aliased,
 * additional and differently encoded parameters without lossy parsing. Only direct npr cPanel
 * authentication is accepted; passwords and resource identities never enter diagnostics.
 *
 * @phpstan-type Names array{
 *     linked_database: string, unlinked_database: string, linked_user: string, unlinked_user: string
 * }
 */
final readonly class MysqlFixtureGuard implements ClientInterface
{
    private const BASE = 'https://test.oblak.host:2083/execute/Mysql/';

    /** @var array<string, list<string>> */
    private array $bodies;

    /** Bind one generated password and one run suffix; construction performs no I/O. */
    public function __construct(
        #[SensitiveParameter]
        private ClientInterface $client,
        #[SensitiveParameter]
        private string $token,
        #[SensitiveParameter]
        string $suffix,
        #[SensitiveParameter]
        string $password,
    ) {
        $names = self::names($suffix);
        if ($token === '' || $password === '') {
            throw new LogicException('Fixture credentials must be present.');
        }
        $databases = ['name=' . $names['linked_database'], 'name=' . $names['unlinked_database']];
        $users = ['name=' . $names['linked_user'], 'name=' . $names['unlinked_user']];
        $this->bodies = [
            'create_database' => $databases,
            'delete_database' => $databases,
            'create_user' => array_map(static fn (string $body): string =>
                $body . '&password=' . rawurlencode($password), $users),
            'delete_user' => $users,
            'set_privileges_on_database' => ['database=' . $names['linked_database']
                . '&user=' . $names['linked_user'] . '&privileges=SELECT'],
        ];
    }

    /** @return Names Fixed account-prefixed names fit even the oldest documented 16-character username limit. */
    public static function names(#[SensitiveParameter] string $suffix): array
    {
        if (preg_match('/\A[a-f0-9]{6}\z/', $suffix) !== 1) {
            throw new LogicException('Fixture suffix must contain six lowercase hexadecimal characters.');
        }
        $prefix = 'npr_wv' . $suffix;

        return [
            'linked_database' => $prefix . 'd', 'unlinked_database' => $prefix . 'e',
            'linked_user' => $prefix . 'u', 'unlinked_user' => $prefix . 'v',
        ];
    }

    /** Reject every request outside the exact account, URI, header and body boundary before delegation. */
    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        if (getenv('WHM_API_LIVE') !== '1' || getenv('WHM_API_LIVE_MUTATIONS') !== '1') {
            throw new LogicException('MySQL fixture requests require both live opt-ins.');
        }
        $post = $request->getMethod() === 'POST';
        $headers = [
            'host' => ['test.oblak.host:2083'],
            'authorization' => ['cpanel npr:' . $this->token],
            'accept' => ['application/json'],
        ];
        if ($post) {
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);
        ksort($actual);
        $uri = (string) $request->getUri();
        $body = (string) $request->getBody();
        $approved = $request->getMethod() === 'GET' && $body === ''
            && in_array($uri, [self::BASE . 'list_databases', self::BASE . 'list_users'], true);
        if ($post) {
            foreach ($this->bodies as $function => $bodies) {
                $approved = $approved || ($uri === self::BASE . $function && in_array($body, $bodies, true));
            }
        }
        if (!$approved || $actual !== $headers) {
            throw new LogicException('Request outside the MySQL fixture boundary.');
        }

        return $this->client->sendRequest($request);
    }
}
