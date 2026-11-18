<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\TokensSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\VariablesSpec;
use HostingPal\WhmApiClient\Api\Uapi\Tokens;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Client;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * The exact requests and expectations of one API token lifecycle on the designated npr account.
 *
 * Each route owns one throwaway token named from the run's suffix. Before creating it the route must show that
 * neither of its names exists and that the account has no notification target, because the server announces every
 * created token to the account's contacts. The token is then created read-only with an expiry, listed, renamed,
 * revoked, revoked again and confirmed absent. Bodies are spelled literally here, independently of the client's
 * encoder, so the guard compares two spellings of each request; the names use only characters that need no encoding.
 * The cleanup phases exist only for a failed run and are never part of passing evidence. Nothing here performs I/O,
 * and no expectation keeps or returns a token secret.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Step array{probe:Probe,method:'GET'|'POST',body:string}
 * @phpstan-type Definition array{operation:string,route:'whm'|Route,spec:array<mixed>,method:'GET'|'POST'}
 * @phpstan-type Sighting 'absent'|'settled'|'other'
 * @phpstan-type Inventory array{original:Sighting,renamed:Sighting}
 */
final readonly class TokensSectionRecipe
{
    public const RECIPE = 'tokens-lifecycle-v2';

    /** The account variables that hold a cPanel user's notification targets. */
    private const TARGETS = ['contact_email', 'contact_email_2', 'pushbullet_access_token'];

    private const LIFECYCLE = [
        'preflight', 'targets', 'create', 'listed', 'rename', 'renamed', 'revoke', 'revoke_again', 'absent',
    ];

    private const CLEANUP = ['cleanup_inspect', 'cleanup_original', 'cleanup_renamed', 'cleanup_check'];

    private const DIRECT = 'https://test.oblak.host:2083/execute/';

    private const BRIDGE = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
        . '&cpanel.module=';

    /** Bind one run's suffix and the expiry its tokens are created with; performs no I/O. */
    public function __construct(private string $suffix, private int $expiresAt)
    {
        if (preg_match('/\A[0-9a-f]{6}\z/', $suffix) !== 1 || $expiresAt < 1) {
            throw new InvalidArgumentException('Token recipe needs a six-digit hexadecimal suffix and an expiry.');
        }
    }

    /**
     * The ordered phases of a passing run: WHM version, then the whole lifecycle on each route.
     *
     * @return array<string,Definition>
     */
    public static function definitions(): array
    {
        $result = ['version' => [
            'operation' => 'whm1:version', 'route' => 'whm', 'spec' => ServerSpec::VERSION, 'method' => 'GET',
        ]];
        foreach (['direct', 'bridge'] as $name) {
            foreach (self::LIFECYCLE as $stage) {
                $result[$stage . '_' . $name] = self::definition($stage, $name);
            }
        }

        return $result;
    }

    /**
     * One phase's generated call and the literal request it must send.
     *
     * @return Step
     */
    public function step(string $phase): array
    {
        if ($phase === 'version') {
            return ['probe' => new Probe(
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                'https://test.oblak.host:2087/json-api/version?api.version=1',
                static fn (#[SensitiveParameter] Client $client): mixed => $client->whm()->server()->version(),
            ), 'method' => 'GET', 'body' => ''];
        }
        [$stage, $name] = self::split($phase);
        $definition = self::definition($stage, $name);
        $route = $definition['route'];
        $account = $route === 'direct-uapi' ? null : 'npr';
        [$module, $function] = explode('/', substr($definition['operation'], 5));
        $uri = $route === 'direct-uapi'
            ? self::DIRECT . $module . '/' . $function
            : self::BRIDGE . $module . '&cpanel.function=' . $function;
        if ($stage === 'targets') {
            $uri .= ($route === 'direct-uapi' ? '?' : '&') . 'name=' . implode('&name=', self::TARGETS);

            return ['probe' => new Probe(
                $definition['operation'],
                $route,
                $definition['spec'],
                $uri,
                static fn (#[SensitiveParameter] Client $client): mixed =>
                    $client->uapi($account)->variables()->getUserInformation(self::TARGETS),
            ), 'method' => 'GET', 'body' => ''];
        }
        $original = $this->tokenName($name, false);
        $renamed = $this->tokenName($name, true);
        $expiresAt = $this->expiresAt;
        [$body, $invoke] = match ($stage) {
            'create' => [
                'name=' . $original . '&expires_at=' . $expiresAt . '&readonly=1',
                static fn (Tokens $tokens): mixed => $tokens->createFullAccess($original, $expiresAt, true),
            ],
            'rename' => [
                'name=' . $original . '&new_name=' . $renamed,
                static fn (Tokens $tokens): mixed => $tokens->renameToken($original, $renamed),
            ],
            'revoke', 'revoke_again', 'cleanup_renamed' => [
                'name=' . $renamed,
                static fn (Tokens $tokens): mixed => $tokens->revokeToken($renamed),
            ],
            'cleanup_original' => [
                'name=' . $original,
                static fn (Tokens $tokens): mixed => $tokens->revokeToken($original),
            ],
            default => ['', static fn (Tokens $tokens): mixed => $tokens->listTokens()],
        };
        $probe = new Probe(
            $definition['operation'],
            $route,
            $definition['spec'],
            $uri,
            static fn (#[SensitiveParameter] Client $client): mixed => $invoke($client->uapi($account)->tokens()),
        );

        return ['probe' => $probe, 'method' => $definition['method'], 'body' => $body];
    }

    /**
     * Whether a phase's original response shows what the lifecycle requires at that point.
     *
     * The response is decoded here from its raw bytes, independently of the generated client. Cleanup phases carry
     * no expectation here: the fixture decides them from inventory() and from what the run proved it owns.
     */
    public function holds(string $phase, #[SensitiveParameter] string $raw): bool
    {
        if ($phase === 'version') {
            return true;
        }
        [$stage, $name] = self::split($phase);
        $payload = self::payload($name, $raw);
        if ($payload === null) {
            return false;
        }
        $data = $payload[0];
        $inventory = $this->read($data, $name);

        return match ($stage) {
            'targets' => self::withoutTargets($data),
            'create' => $data instanceof stdClass && is_int($data->create_time ?? null)
                && is_string($data->token ?? null) && $data->token !== '',
            'rename' => $data === null,
            'revoke' => $data === 1,
            'revoke_again' => $data === 0,
            'listed' => $inventory === ['original' => 'settled', 'renamed' => 'absent'],
            'renamed' => $inventory === ['original' => 'absent', 'renamed' => 'settled'],
            'preflight', 'absent' => $inventory === ['original' => 'absent', 'renamed' => 'absent'],
            default => true,
        };
    }

    /**
     * What a list response shows for this route's two names, or null when it cannot be relied on.
     *
     * A name is absent only when every row of the list identifies itself: one row without a name, or one exposing a
     * token secret, makes the whole list unusable for inferring absence. A name is settled when exactly one row
     * carries it with the expiry, read-only restriction and full access this run creates its token with; any other
     * presence is reported as other and proves nothing about ownership.
     *
     * @param 'direct'|'bridge' $name
     * @return Inventory|null
     */
    public function inventory(string $name, #[SensitiveParameter] string $raw): ?array
    {
        $payload = self::payload($name, $raw);

        return $payload === null ? null : $this->read($payload[0], $name);
    }

    /**
     * @param 'direct'|'bridge' $name
     * @return Definition
     */
    private static function definition(string $stage, string $name): array
    {
        [$operation, $spec, $method] = match ($stage) {
            'targets' => ['Variables/get_user_information', VariablesSpec::GET_USER_INFORMATION, 'GET'],
            'create' => ['Tokens/create_full_access', TokensSpec::CREATE_FULL_ACCESS, 'POST'],
            'rename' => ['Tokens/rename', TokensSpec::RENAME, 'POST'],
            'revoke', 'revoke_again', 'cleanup_original', 'cleanup_renamed' => [
                'Tokens/revoke', TokensSpec::REVOKE, 'POST',
            ],
            default => ['Tokens/list', TokensSpec::LIST, 'GET'],
        };

        return ['operation' => 'uapi:' . $operation,
            'route' => $name === 'direct' ? 'direct-uapi' : 'bridged-uapi', 'spec' => $spec, 'method' => $method];
    }

    /** @return array{string, 'direct'|'bridge'} */
    private static function split(string $phase): array
    {
        foreach (['direct', 'bridge'] as $name) {
            foreach ([...self::LIFECYCLE, ...self::CLEANUP] as $stage) {
                if ($phase === $stage . '_' . $name) {
                    return [$stage, $name];
                }
            }
        }

        throw new LogicException('Unknown token phase.');
    }

    /**
     * The data member of a UAPI response on this route, wrapped so that an explicit null stays distinct from absence.
     *
     * @param 'direct'|'bridge' $name
     * @return array{mixed}|null
     */
    private static function payload(string $name, #[SensitiveParameter] string $raw): ?array
    {
        try {
            $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!$document instanceof stdClass) {
            return null;
        }
        if ($name === 'direct') {
            $envelope = property_exists($document, 'result') ? $document->result : $document;
        } else {
            $envelope = ($document->data ?? null) instanceof stdClass ? ($document->data->uapi ?? null) : null;
        }
        if (!$envelope instanceof stdClass || !property_exists($envelope, 'data')) {
            return null;
        }

        return [$envelope->data];
    }

    /** Whether every notification target is reported and none is set. */
    private static function withoutTargets(#[SensitiveParameter] mixed $data): bool
    {
        if (!$data instanceof stdClass) {
            return false;
        }
        foreach (self::TARGETS as $target) {
            if (!property_exists($data, $target) || !in_array($data->$target, [null, ''], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param 'direct'|'bridge' $name */
    private function tokenName(string $name, bool $renamed): string
    {
        return 'whmapiclient-live-' . $this->suffix . '-' . $name . ($renamed ? '-renamed' : '');
    }

    /**
     * @param 'direct'|'bridge' $name
     * @return Inventory|null
     */
    private function read(#[SensitiveParameter] mixed $data, string $name): ?array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }
        $names = ['original' => $this->tokenName($name, false), 'renamed' => $this->tokenName($name, true)];
        $rows = ['original' => [], 'renamed' => []];
        foreach ($data as $row) {
            // Every row must identify itself and none may hold a secret, or the list proves nothing.
            if (!$row instanceof stdClass || property_exists($row, 'token')) {
                return null;
            }
            $rowName = $row->name ?? null;
            if (!is_string($rowName) || $rowName === '') {
                return null;
            }
            foreach ($names as $identity => $tokenName) {
                if ($rowName === $tokenName) {
                    $rows[$identity][] = $row;
                }
            }
        }

        return ['original' => $this->sighting($rows['original']), 'renamed' => $this->sighting($rows['renamed'])];
    }

    /**
     * @param list<stdClass> $rows
     * @return Sighting
     */
    private function sighting(array $rows): string
    {
        if ($rows === []) {
            return 'absent';
        }
        $row = $rows[0];

        return count($rows) === 1
            && in_array($row->expires_at ?? null, [$this->expiresAt, (string) $this->expiresAt], true)
            && ($row->readonly ?? null) === 1 && ($row->has_full_access ?? null) === 1 ? 'settled' : 'other';
    }
}
