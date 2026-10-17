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
 * additional and differently encoded parameters without lossy parsing. Linked resources use direct
 * npr credentials; unlinked resources use the root WHM bridge scoped to npr. SELECT on the linked
 * pair is explicitly permitted through each route. Raw values never enter diagnostics.
 *
 * @phpstan-type Names array{
 *     linked_database: string, unlinked_database: string, linked_user: string, unlinked_user: string
 * }
 */
final readonly class MysqlFixtureGuard implements ClientInterface
{
    private const BASE = 'https://test.oblak.host:2083/execute/Mysql/';

    private const BRIDGE = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1'
        . '&cpanel.user=npr&cpanel.module=Mysql&cpanel.function=';

    /** @var array<string, string> */
    private array $bodies;

    /**
     * Bind both route credentials, one generated password and one run suffix without I/O.
     *
     * @param array{whm: string, cpanel: string} $tokens
     */
    public function __construct(
        #[SensitiveParameter]
        private ClientInterface $client,
        #[SensitiveParameter]
        private array $tokens,
        #[SensitiveParameter]
        string $suffix,
        #[SensitiveParameter]
        string $password,
    ) {
        $names = self::names($suffix);
        if ($tokens['cpanel'] === '' || $tokens['whm'] === '' || $password === '') {
            throw new LogicException('Fixture credentials must be present.');
        }
        $bodies = [];
        foreach (['linked' => self::BASE, 'unlinked' => self::BRIDGE] as $role => $base) {
            $database = 'name=' . $names[$role . '_database'];
            $user = 'name=' . $names[$role . '_user'];
            $bodies[$base . 'create_database'] = $database;
            $bodies[$base . 'delete_database'] = $database;
            $bodies[$base . 'create_user'] = $user . '&password=' . rawurlencode($password);
            $bodies[$base . 'delete_user'] = $user;
            $bodies[$base . 'set_privileges_on_database'] = 'database=' . $names['linked_database']
                . '&user=' . $names['linked_user'] . '&privileges=SELECT';
        }
        $this->bodies = $bodies;
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
        $uri = (string) $request->getUri();
        $bridge = str_starts_with($uri, self::BRIDGE);
        $headers = [
            'host' => ['test.oblak.host:' . ($bridge ? '2087' : '2083')],
            'authorization' => [$bridge ? 'whm root:' . $this->tokens['whm'] : 'cpanel npr:' . $this->tokens['cpanel']],
            'accept' => ['application/json'],
        ];
        if ($post) {
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }
        $actual = array_change_key_case($request->getHeaders(), CASE_LOWER);
        ksort($headers);
        ksort($actual);
        $body = (string) $request->getBody();
        $approved = $request->getMethod() === 'GET' && $body === ''
            && in_array($uri, [self::BASE . 'list_databases', self::BASE . 'list_users'], true);
        if ($post) {
            $approved = isset($this->bodies[$uri]) && $body === $this->bodies[$uri];
        }
        if (!$approved || $actual !== $headers) {
            throw new LogicException('Request outside the MySQL fixture boundary.');
        }

        return $this->client->sendRequest($request);
    }
}
