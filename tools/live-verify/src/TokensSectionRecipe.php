<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use HostingPal\WhmApiClient\Api\Uapi\Spec\TokensSpec;
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
 * Each route owns one throwaway token named from the run's suffix: created read-only with an expiry, listed, renamed,
 * revoked, revoked again and confirmed absent. Bodies are spelled literally here, independently of the client's
 * encoder, so the guard compares two spellings of each request; the names use only characters that need no encoding.
 * The cleanup phases exist only for a failed run and are never part of passing evidence. Nothing here performs I/O,
 * and no expectation keeps or returns a token secret.
 *
 * @phpstan-type Route 'direct-uapi'|'bridged-uapi'
 * @phpstan-type Step array{probe:Probe,method:'GET'|'POST',body:string}
 * @phpstan-type Definition array{operation:string,route:'whm'|Route,spec:array<mixed>,method:'GET'|'POST'}
 */
final readonly class TokensSectionRecipe
{
    public const RECIPE = 'tokens-lifecycle-v1';

    private const LIFECYCLE = [
        'preflight', 'create', 'listed', 'rename', 'renamed', 'revoke', 'revoke_again', 'absent',
    ];

    private const CLEANUP = ['cleanup_original', 'cleanup_renamed', 'cleanup_check'];

    private const DIRECT = 'https://test.oblak.host:2083/execute/Tokens/';

    private const BRIDGE = 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
        . '&cpanel.module=Tokens&cpanel.function=';

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
            ($route === 'direct-uapi' ? self::DIRECT : self::BRIDGE) . substr($definition['operation'], 12),
            static fn (#[SensitiveParameter] Client $client): mixed =>
                $invoke($client->uapi($route === 'direct-uapi' ? null : 'npr')->tokens()),
        );

        return ['probe' => $probe, 'method' => $definition['method'], 'body' => $body];
    }

    /**
     * Whether a phase's original response shows what the lifecycle requires at that point.
     *
     * The response is decoded here from its raw bytes, independently of the generated client. Cleanup revocations
     * carry no expectation: only the absence check after them decides whether a failed run left a token behind.
     */
    public function holds(string $phase, #[SensitiveParameter] string $raw): bool
    {
        if ($phase === 'version') {
            return true;
        }
        [$stage, $name] = self::split($phase);
        try {
            $document = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (!$document instanceof stdClass) {
            return false;
        }
        if ($name === 'direct') {
            $envelope = property_exists($document, 'result') ? $document->result : $document;
        } else {
            $envelope = ($document->data ?? null) instanceof stdClass ? ($document->data->uapi ?? null) : null;
        }
        if (!$envelope instanceof stdClass || !property_exists($envelope, 'data')) {
            return false;
        }
        $data = $envelope->data;
        $original = $this->named($data, $this->tokenName($name, false));
        $renamed = $this->named($data, $this->tokenName($name, true));

        return match ($stage) {
            'create' => $data instanceof stdClass && is_int($data->create_time ?? null)
                && is_string($data->token ?? null) && $data->token !== '',
            'rename' => $data === null,
            'revoke' => $data === 1,
            'revoke_again' => $data === 0,
            'listed' => $original !== null && count($original) === 1 && $this->settled($original[0])
                && $renamed === [],
            'renamed' => $renamed !== null && count($renamed) === 1 && $this->settled($renamed[0])
                && $original === [],
            'preflight', 'absent', 'cleanup_check' => $original === [] && $renamed === [],
            default => true,
        };
    }

    /**
     * @param 'direct'|'bridge' $name
     * @return Definition
     */
    private static function definition(string $stage, string $name): array
    {
        [$function, $spec, $method] = match ($stage) {
            'create' => ['create_full_access', TokensSpec::CREATE_FULL_ACCESS, 'POST'],
            'rename' => ['rename', TokensSpec::RENAME, 'POST'],
            'revoke', 'revoke_again', 'cleanup_original', 'cleanup_renamed' => ['revoke', TokensSpec::REVOKE, 'POST'],
            default => ['list', TokensSpec::LIST, 'GET'],
        };

        return ['operation' => 'uapi:Tokens/' . $function,
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

    /** @param 'direct'|'bridge' $name */
    private function tokenName(string $name, bool $renamed): string
    {
        return 'whmapiclient-live-' . $this->suffix . '-' . $name . ($renamed ? '-renamed' : '');
    }

    /**
     * The rows carrying this name, or null unless the payload is a list of token rows without any secret.
     *
     * @return list<stdClass>|null
     */
    private function named(#[SensitiveParameter] mixed $data, string $name): ?array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }
        $rows = [];
        foreach ($data as $row) {
            if (!$row instanceof stdClass || property_exists($row, 'token')) {
                return null;
            }
            if (($row->name ?? null) === $name) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** Whether a row still has the expiry, read-only restriction and full access its token was created with. */
    private function settled(stdClass $row): bool
    {
        return in_array($row->expires_at ?? null, [$this->expiresAt, (string) $this->expiresAt], true)
            && ($row->readonly ?? null) === 1 && ($row->has_full_access ?? null) === 1;
    }
}
