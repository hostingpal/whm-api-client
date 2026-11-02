<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Fixed identifiers, per-run names, in-memory passwords and exact wire expectations for one Mime and directory run.
 *
 * Every name derives from one high-entropy per-run value, so each resource is fresh and recognisably this run's. The
 * two directory-user passwords are drawn separately from the entropy and exist only in memory: nothing reads them
 * except the two add_user requests and the guard that compares those bodies. Expected requests are spelled here by
 * hand, rather than by the library's encoder, so the guard compares the client against a second implementation.
 *
 * Each Mime resource kind runs two rounds. Round a adds through direct UAPI and removes through the WHM bridge; round
 * b reverses the routes, so every mutation runs once on each route and each route reads back a populated row and its
 * removal. The directory section uses one fresh directory under public_html, created by uploading one file.
 *
 * @phpstan-type Section 'version'|'mime'|'directory'
 * @phpstan-type Route 'whm'|'direct-uapi'|'bridged-uapi'
 * @phpstan-type Wire array{route: Route, method: 'GET'|'POST', uri: string, body: string}
 * @phpstan-type Round 'a'|'b'
 */
final readonly class MimeDirectoryRecipe
{
    public const ACCOUNT = 'wlmime';

    public const DOMAIN = 'wlmime.example.com';

    public const HOME = '/home/wlmime';

    public const DOCROOT = '/home/wlmime/public_html';

    public const TOKEN_NAME = 'wlmime-fixture';

    public const UPLOAD_PERMISSIONS = '0644';

    /** Synthetic, nonsecret bytes of the one file whose upload creates the fixture directory. */
    public const UPLOAD_BYTES = "Synthetic directory-access fixture file.\n";

    /**
     * Each Mime round's add and remove routes: round a adds directly and removes through the bridge, round b reverses.
     *
     * @var array{a: array{'direct', 'bridged'}, b: array{'bridged', 'direct'}}
     */
    public const ROUNDS = ['a' => ['direct', 'bridged'], 'b' => ['bridged', 'direct']];

    /** The hotlink referrer URLs each round protects, sorted as list_hotlinks reports them. */
    public const HOTLINK_URLS = [
        'a' => ['http://wlmime.example.com'],
        'b' => ['http://wlmime.example.com', 'https://wlmime.example.com'],
    ];

    /**
     * Every phase in execution order: section, route, API-map operation and actual HTTP method.
     *
     * @var array<string, array{0: Section, 1: Route, 2: string, 3: 'GET'|'POST'}>
     */
    public const PHASES = [
        'version' => ['version', 'whm', 'whm1:version', 'GET'],
        'mime_types_baseline' => ['mime', 'direct-uapi', 'uapi:Mime/list_mime', 'GET'],
        'handlers_baseline' => ['mime', 'direct-uapi', 'uapi:Mime/list_handlers', 'GET'],
        'redirects_baseline' => ['mime', 'direct-uapi', 'uapi:Mime/list_redirects', 'GET'],
        'redirect_domain_baseline' => ['mime', 'direct-uapi', 'uapi:Mime/get_redirect', 'GET'],
        'hotlinks_baseline' => ['mime', 'direct-uapi', 'uapi:Mime/list_hotlinks', 'GET'],
        'add_mime_direct' => ['mime', 'direct-uapi', 'uapi:Mime/add_mime', 'POST'],
        'mime_types_after_add_direct' => ['mime', 'direct-uapi', 'uapi:Mime/list_mime', 'GET'],
        'delete_mime_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/delete_mime', 'POST'],
        'mime_types_after_delete_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/list_mime', 'GET'],
        'add_mime_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/add_mime', 'POST'],
        'mime_types_after_add_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/list_mime', 'GET'],
        'delete_mime_direct' => ['mime', 'direct-uapi', 'uapi:Mime/delete_mime', 'POST'],
        'mime_types_after_delete_direct' => ['mime', 'direct-uapi', 'uapi:Mime/list_mime', 'GET'],
        'add_handler_direct' => ['mime', 'direct-uapi', 'uapi:Mime/add_handler', 'POST'],
        'handlers_after_add_direct' => ['mime', 'direct-uapi', 'uapi:Mime/list_handlers', 'GET'],
        'delete_handler_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/delete_handler', 'POST'],
        'handlers_after_delete_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/list_handlers', 'GET'],
        'add_handler_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/add_handler', 'POST'],
        'handlers_after_add_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/list_handlers', 'GET'],
        'delete_handler_direct' => ['mime', 'direct-uapi', 'uapi:Mime/delete_handler', 'POST'],
        'handlers_after_delete_direct' => ['mime', 'direct-uapi', 'uapi:Mime/list_handlers', 'GET'],
        'add_redirect_direct' => ['mime', 'direct-uapi', 'uapi:Mime/add_redirect', 'POST'],
        'redirects_after_add_direct' => ['mime', 'direct-uapi', 'uapi:Mime/list_redirects', 'GET'],
        'redirect_domain_after_add_direct' => ['mime', 'direct-uapi', 'uapi:Mime/get_redirect', 'GET'],
        'delete_redirect_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/delete_redirect', 'POST'],
        'redirects_after_delete_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/list_redirects', 'GET'],
        'redirect_domain_after_delete_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/get_redirect', 'GET'],
        'add_redirect_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/add_redirect', 'POST'],
        'redirects_after_add_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/list_redirects', 'GET'],
        'redirect_domain_after_add_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/get_redirect', 'GET'],
        'delete_redirect_direct' => ['mime', 'direct-uapi', 'uapi:Mime/delete_redirect', 'POST'],
        'redirects_after_delete_direct' => ['mime', 'direct-uapi', 'uapi:Mime/list_redirects', 'GET'],
        'redirect_domain_after_delete_direct' => ['mime', 'direct-uapi', 'uapi:Mime/get_redirect', 'GET'],
        'add_hotlink_direct' => ['mime', 'direct-uapi', 'uapi:Mime/add_hotlink', 'POST'],
        'hotlinks_after_add_direct' => ['mime', 'direct-uapi', 'uapi:Mime/list_hotlinks', 'GET'],
        'delete_hotlink_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/delete_hotlink', 'POST'],
        'hotlinks_after_delete_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/list_hotlinks', 'GET'],
        'add_hotlink_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/add_hotlink', 'POST'],
        'hotlinks_after_add_bridged' => ['mime', 'bridged-uapi', 'uapi:Mime/list_hotlinks', 'GET'],
        'delete_hotlink_direct' => ['mime', 'direct-uapi', 'uapi:Mime/delete_hotlink', 'POST'],
        'hotlinks_after_delete_direct' => ['mime', 'direct-uapi', 'uapi:Mime/list_hotlinks', 'GET'],
        'docroot_listing' => ['directory', 'direct-uapi', 'uapi:Fileman/list_files', 'GET'],
        'create_directory' => ['directory', 'direct-uapi', 'uapi:Fileman/upload_files', 'POST'],
        'directory_inventory' => ['directory', 'direct-uapi', 'uapi:Fileman/list_files', 'GET'],
        'leech_initial' => ['directory', 'direct-uapi', 'uapi:DirectoryProtection/list_directories', 'GET'],
        'indexing_initial' => ['directory', 'direct-uapi', 'uapi:DirectoryIndexes/get_indexing', 'GET'],
        'privacy_initial' => ['directory', 'direct-uapi', 'uapi:DirectoryPrivacy/is_directory_protected', 'GET'],
        'set_indexing_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryIndexes/set_indexing', 'POST'],
        'indexing_after_set_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryIndexes/get_indexing', 'GET'],
        'set_indexing_bridged' => ['directory', 'bridged-uapi', 'uapi:DirectoryIndexes/set_indexing', 'POST'],
        'indexing_after_set_bridged' => ['directory', 'bridged-uapi', 'uapi:DirectoryIndexes/get_indexing', 'GET'],
        'indexes_listing_bridged' => ['directory', 'bridged-uapi', 'uapi:DirectoryIndexes/list_directories', 'GET'],
        'restore_indexing_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryIndexes/set_indexing', 'POST'],
        'indexing_after_restore_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryIndexes/get_indexing', 'GET'],
        'protect_direct' => [
            'directory', 'direct-uapi', 'uapi:DirectoryPrivacy/configure_directory_protection', 'POST',
        ],
        'privacy_after_protect_direct' => [
            'directory', 'direct-uapi', 'uapi:DirectoryPrivacy/is_directory_protected', 'GET',
        ],
        'privacy_listing_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryPrivacy/list_directories', 'GET'],
        'add_user_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryPrivacy/add_user', 'POST'],
        'users_after_add_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryPrivacy/list_users', 'GET'],
        'add_user_bridged' => ['directory', 'bridged-uapi', 'uapi:DirectoryPrivacy/add_user', 'POST'],
        'users_after_add_bridged' => ['directory', 'bridged-uapi', 'uapi:DirectoryPrivacy/list_users', 'GET'],
        'delete_user_bridged' => ['directory', 'bridged-uapi', 'uapi:DirectoryPrivacy/delete_user', 'POST'],
        'users_after_delete_bridged' => ['directory', 'bridged-uapi', 'uapi:DirectoryPrivacy/list_users', 'GET'],
        'delete_user_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryPrivacy/delete_user', 'POST'],
        'users_after_delete_direct' => ['directory', 'direct-uapi', 'uapi:DirectoryPrivacy/list_users', 'GET'],
        'unprotect_bridged' => [
            'directory', 'bridged-uapi', 'uapi:DirectoryPrivacy/configure_directory_protection', 'POST',
        ],
        'privacy_after_unprotect_bridged' => [
            'directory', 'bridged-uapi', 'uapi:DirectoryPrivacy/is_directory_protected', 'GET',
        ],
        'protect_bridged' => [
            'directory', 'bridged-uapi', 'uapi:DirectoryPrivacy/configure_directory_protection', 'POST',
        ],
        'privacy_after_protect_bridged' => [
            'directory', 'bridged-uapi', 'uapi:DirectoryPrivacy/is_directory_protected', 'GET',
        ],
        'unprotect_direct' => [
            'directory', 'direct-uapi', 'uapi:DirectoryPrivacy/configure_directory_protection', 'POST',
        ],
        'privacy_after_unprotect_direct' => [
            'directory', 'direct-uapi', 'uapi:DirectoryPrivacy/is_directory_protected', 'GET',
        ],
        'leech_final' => ['directory', 'bridged-uapi', 'uapi:DirectoryProtection/list_directories', 'GET'],
    ];

    private const WHM = 'https://test.oblak.host:2087/json-api/';

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    /** The directory this run creates under public_html; only its relative name. */
    public string $directory;

    public string $uploadName;

    /** @var array{a: string, b: string} */
    public array $mimeTypes;

    /** @var array{a: string, b: string} */
    public array $mimeExtensions;

    /** @var array{a: string, b: string} */
    public array $handlerExtensions;

    /** @var array{a: string, b: string} */
    public array $handlers;

    /** @var array{a: string, b: string} */
    public array $redirectSources;

    /** @var array{a: string, b: string} Synthetic reserved-domain targets; never fetched. */
    public array $redirectTargets;

    /** @var array{a: string, b: string} Comma-separated, as list_hotlinks reports them. */
    public array $hotlinkExtensions;

    /** @var array{a: string, b: string} Synthetic reserved-domain targets; never fetched. */
    public array $hotlinkTargets;

    /** @var array{a: string, b: string} */
    public array $authNames;

    /** @var array{one: string, two: string} */
    public array $users;

    /** @var array{one: string, two: string} */
    private array $passwords;

    /** @var array<string, list<array{0: string, 1: string}>> Ordered wire fields by phase. */
    private array $fields;

    /**
     * Bind one 128-bit lowercase hexadecimal run value to this recipe's own account; performs no I/O.
     *
     * Mutations need a writable token, and every URI here names one account, so any other binding is refused.
     */
    public function __construct(#[SensitiveParameter] string $entropy, public DisposableAccount $account)
    {
        if (
            preg_match('/\A[0-9a-f]{32}\z/', $entropy) !== 1 || $account->user !== self::ACCOUNT
            || $account->domain !== self::DOMAIN || $account->tokenName !== self::TOKEN_NAME || $account->readOnlyToken
        ) {
            throw new InvalidArgumentException('Mime directory recipe needs 128 bits of entropy and its own account.');
        }
        $short = substr($entropy, 0, 12);
        $this->directory = 'wlmime-fixture-' . $entropy;
        $this->uploadName = 'wlmime-' . substr($entropy, 0, 16) . '.txt';
        $this->mimeTypes = ['a' => 'application/x-wlmime-a-' . $short, 'b' => 'application/x-wlmime-b-' . $short];
        $this->mimeExtensions = ['a' => '.wlma' . $short, 'b' => '.wlmb' . $short];
        $this->handlerExtensions = ['a' => '.wlha' . $short, 'b' => '.wlhb' . $short];
        $this->handlers = ['a' => 'wlmime-handler-a-' . $short, 'b' => 'wlmime-handler-b-' . $short];
        $this->redirectSources = ['a' => '/wlmime-redirect-a-' . $short, 'b' => '/wlmime-redirect-b-' . $short];
        $this->redirectTargets = [
            'a' => 'https://example.net/wlmime-target-a-' . $short,
            'b' => 'https://example.net/wlmime-target-b-' . $short,
        ];
        $this->hotlinkExtensions = ['a' => 'wlhla' . $short, 'b' => 'wlhlb' . $short . ',wlhlc' . $short];
        $this->hotlinkTargets = [
            'a' => 'https://example.net/wlmime-hotlink-a-' . $short,
            'b' => 'https://example.net/wlmime-hotlink-b-' . $short,
        ];
        $this->authNames = ['a' => 'wlmime-realm-a-' . $short, 'b' => 'wlmime-realm-b-' . $short];
        $this->users = ['one' => 'wlmime-user-one-' . $short, 'two' => 'wlmime-user-two-' . $short];
        $this->passwords = ['one' => self::password(), 'two' => self::password()];
        $this->fields = $this->allFields();
    }

    /**
     * The in-memory password of one directory user; it is sent only in that user's add_user body.
     *
     * @param 'one'|'two' $user
     */
    public function userPassword(string $user): string
    {
        return $this->passwords[$user];
    }

    /**
     * The newline-separated referrer URLs one round sends to add_hotlink.
     *
     * @param Round $round
     */
    public function hotlinkUrls(string $round): string
    {
        return implode("\n", self::HOTLINK_URLS[$round]);
    }

    public function absoluteDirectory(): string
    {
        return self::DOCROOT . '/' . $this->directory;
    }

    /** The upload's dir argument: relative, which the source resolves against the account home. */
    public function uploadDirectory(): string
    {
        return 'public_html/' . $this->directory;
    }

    /**
     * The exact request of one phase. A multipart upload has its query here and its body in expectedUploadBody().
     *
     * @return Wire
     * @throws LogicException For an unknown phase.
     */
    public function wire(string $phase): array
    {
        [, $route, $operation, $method] = self::PHASES[$phase]
            ?? throw new LogicException('Mime directory recipe has no such phase.');
        $encoded = implode('&', array_map(
            static fn (array $field): string => rawurlencode($field[0]) . '=' . rawurlencode($field[1]),
            $this->fields[$phase],
        ));
        if ($route === 'whm') {
            return ['route' => $route, 'method' => $method, 'uri' => self::WHM . 'version?api.version=1', 'body' => ''];
        }
        [$module, $function] = explode('/', substr($operation, strlen('uapi:')), 2);
        $inQuery = $method === 'GET' || $phase === 'create_directory';
        if ($route === 'direct-uapi') {
            $uri = self::DIRECT . $module . '/' . $function . ($inQuery && $encoded !== '' ? '?' . $encoded : '');
        } else {
            $uri = self::WHM . 'uapi_cpanel?api.version=1&cpanel.user=' . self::ACCOUNT . '&cpanel.module=' . $module
                . '&cpanel.function=' . $function . ($inQuery && $encoded !== '' ? '&' . $encoded : '');
        }

        return ['route' => $route, 'method' => $method, 'uri' => $uri, 'body' => $inQuery ? '' : $encoded];
    }

    /** Assemble the exact multipart bytes of the directory upload, around the boundary the client selected. */
    public function expectedUploadBody(string $boundary): string
    {
        return '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file-0"; filename="' . $this->uploadName
            . "\"\r\nContent-Type: application/octet-stream\r\n\r\n" . self::UPLOAD_BYTES . "\r\n"
            . '--' . $boundary . "--\r\n";
    }

    /** @return array<string, list<array{0: string, 1: string}>> */
    private function allFields(): array
    {
        $dir = [['dir', $this->absoluteDirectory()]];
        $fields = [];
        foreach (array_keys(self::PHASES) as $phase) {
            $fields[$phase] = match (true) {
                str_starts_with($phase, 'mime_types_'), str_starts_with($phase, 'handlers_') => [['type', 'user']],
                str_starts_with($phase, 'redirect_domain_') => [['domain', self::DOMAIN]],
                str_starts_with($phase, 'leech_'), str_starts_with($phase, 'indexing_'),
                str_starts_with($phase, 'privacy_'), str_starts_with($phase, 'users_'),
                $phase === 'indexes_listing_bridged' => $dir,
                default => [],
            };
        }
        $mime = $this->mimeTypes;
        $handler = $this->handlerExtensions;

        return [
            ...$fields,
            'add_mime_direct' => [['extension', $this->mimeExtensions['a']], ['type', $mime['a']]],
            'delete_mime_bridged' => [['type', $mime['a']]],
            'add_mime_bridged' => [['extension', $this->mimeExtensions['b']], ['type', $mime['b']]],
            'delete_mime_direct' => [['type', $mime['b']]],
            'add_handler_direct' => [['extension', $handler['a']], ['handler', $this->handlers['a']]],
            'delete_handler_bridged' => [['extension', $handler['a']]],
            'add_handler_bridged' => [['extension', $handler['b']], ['handler', $this->handlers['b']]],
            'delete_handler_direct' => [['extension', $handler['b']]],
            'add_redirect_direct' => [
                ['domain', self::DOMAIN], ['redirect', $this->redirectTargets['a']],
                ['src', $this->redirectSources['a']],
            ],
            'delete_redirect_bridged' => [['domain', self::DOMAIN], ['src', $this->redirectSources['a']]],
            'add_redirect_bridged' => [
                ['domain', self::DOMAIN], ['redirect', $this->redirectTargets['b']], ['redirect_wildcard', '0'],
                ['redirect_www', '0'], ['src', $this->redirectSources['b']], ['type', 'temp'],
            ],
            'delete_redirect_direct' => [
                ['domain', self::DOMAIN], ['docroot', self::DOCROOT], ['src', $this->redirectSources['b']],
            ],
            'add_hotlink_direct' => [
                ['extensions', $this->hotlinkExtensions['a']], ['redirect_url', $this->hotlinkTargets['a']],
                ['urls', $this->hotlinkUrls('a')], ['allow_null', '1'],
            ],
            'add_hotlink_bridged' => [
                ['extensions', $this->hotlinkExtensions['b']], ['redirect_url', $this->hotlinkTargets['b']],
                ['urls', $this->hotlinkUrls('b')], ['allow_null', '0'],
            ],
            'docroot_listing' => [['dir', self::DOCROOT], ['types', 'dir|file']],
            'create_directory' => [
                ['dir', $this->uploadDirectory()], ['overwrite', '0'], ['permissions', self::UPLOAD_PERMISSIONS],
            ],
            'directory_inventory' => [...$dir, ['show_hidden', '1']],
            'set_indexing_direct' => [...$dir, ['type', 'disabled']],
            'set_indexing_bridged' => [...$dir, ['type', 'fancy']],
            'restore_indexing_direct' => [...$dir, ['type', 'inherit']],
            'protect_direct' => [...$dir, ['enabled', '1'], ['authname', $this->authNames['a']]],
            'add_user_direct' => [...$dir, ['user', $this->users['one']], ['password', $this->passwords['one']]],
            'add_user_bridged' => [...$dir, ['user', $this->users['two']], ['password', $this->passwords['two']]],
            'delete_user_bridged' => [...$dir, ['user', $this->users['one']]],
            'delete_user_direct' => [...$dir, ['user', $this->users['two']]],
            'unprotect_bridged' => [...$dir, ['enabled', '0']],
            'protect_bridged' => [...$dir, ['enabled', '1'], ['authname', $this->authNames['b']]],
            'unprotect_direct' => [...$dir, ['enabled', '0']],
        ];
    }

    /**
     * Draw one strong password from fresh randomness, never from the run entropy, with every character class.
     *
     * Its symbols are form-encoded once, as every value is, so they also exercise the body encoding.
     */
    private static function password(): string
    {
        return 'Wl' . bin2hex(random_bytes(16)) . '!q#Z9';
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** The recipe holds every run identifier and both passwords, so it cannot be persisted. */
    public function __serialize(): never
    {
        throw new LogicException('Mime directory recipe cannot be serialized.');
    }
}
