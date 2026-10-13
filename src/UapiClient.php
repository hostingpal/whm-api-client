<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Uapi\Dns;
use HostingPal\WhmApiClient\Api\Uapi\Domains;
use HostingPal\WhmApiClient\Api\Uapi\Features;
use HostingPal\WhmApiClient\Api\Uapi\Php;
use HostingPal\WhmApiClient\Authentication\TokenCredentials;
use HostingPal\WhmApiClient\Authentication\TokenType;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use InvalidArgumentException;

/**
 * Hold UAPI context for one immutable cPanel target account.
 *
 * The credential type selects direct cPanel access or execution through WHM.
 * Both routes share this handle type and expose the same endpoint groups.
 */
final class UapiClient extends AbstractApiClient
{
    private ?Domains $domains = null;

    private ?Php $php = null;

    private ?Dns $dns = null;

    private ?Features $features = null;

    /**
     * Bind an explicit cPanel target to a configuration and transport.
     *
     * Direct-token targets must match the token owner. With WHM credentials the
     * target identifies the account to impersonate, subject to server permissions.
     *
     * @throws InvalidArgumentException If the target is invalid or mismatched.
     */
    public function __construct(
        ClientConfig $config,
        HttpTransport $transport,
        private readonly string $username,
    ) {
        TokenCredentials::validateUsername($username);

        if ($config->credentials->type === TokenType::Cpanel && $username !== $config->credentials->username) {
            throw new InvalidArgumentException('The UAPI target must match the cPanel credential account.');
        }

        parent::__construct($config, $transport);
    }

    /**
     * Return the selected cPanel account username.
     *
     * This is the target account, which can differ from the WHM authentication user.
     */
    public function username(): string
    {
        return $this->username;
    }

    /**
     * Report whether UAPI execution must use the WHM bridge.
     *
     * Routing follows the configured credential type and requires no server probe.
     */
    public function isBridged(): bool
    {
        return $this->config->credentials->type === TokenType::Whm;
    }

    /**
     * Select domain operations for this account without making a request.
     *
     * The stable group retains this account and updates only this handle's metadata.
     */
    public function domains(): Domains
    {
        return $this->domains ??= new Domains(fn (string $operation, array $query): ApiResponse =>
            $this->request('DomainInfo', $operation, $query));
    }

    /**
     * Select PHP operations for this account without making a request.
     *
     * The stable group retains this account and updates only this handle's metadata.
     */
    public function php(): Php
    {
        return $this->php ??= new Php(fn (string $operation, array $query): ApiResponse =>
            $this->request('LangPHP', $operation, $query));
    }

    /**
     * Select DNS discovery for this cPanel account without making a request.
     *
     * The stable group retains the account and updates only this handle's metadata.
     */
    public function dns(): Dns
    {
        return $this->dns ??= new Dns(fn (string $operation, array $query): ApiResponse =>
            $this->request('DNS', $operation, $query));
    }

    /**
     * Select this cPanel account's feature inventory without making a request.
     *
     * Both credential routes retain the same account and per-handle metadata.
     */
    public function features(): Features
    {
        return $this->features ??= new Features(fn (string $operation, array $query): ApiResponse =>
            $this->request('Features', $operation, $query));
    }

    /**
     * Route every read-only UAPI request through this account-bound helper.
     *
     * Groups supply operation names and endpoint parameters. The bound module,
     * account, API version, and function take precedence over bridge parameters.
     * Execution clears metadata before query validation or I/O, then retains
     * usable envelope metadata before API or group-level payload errors.
     * @param array<string, string|int|list<string>> $query
     */
    private function request(string $module, string $operation, array $query): ApiResponse
    {
        if ($this->isBridged()) {
            return $this->executeGet('/json-api/uapi_cpanel', [
                'api.version' => 1,
                'cpanel.user' => $this->username,
                'cpanel.module' => $module,
                'cpanel.function' => $operation,
            ] + $query, ResponseRoute::BridgedUapi);
        }

        return $this->executeGet('/execute/' . $module . '/' . $operation, $query, ResponseRoute::DirectUapi);
    }
}
