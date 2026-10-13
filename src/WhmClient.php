<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\Whm\Domains;
use HostingPal\WhmApiClient\Api\Whm\Features;
use HostingPal\WhmApiClient\Api\Whm\Php;
use HostingPal\WhmApiClient\Api\Whm\Server;
use HostingPal\WhmApiClient\Authentication\TokenType;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use LogicException;

/**
 * Hold WHM configuration and the metadata state for WHM operations.
 *
 * Only WHM credentials are accepted. Endpoint groups share this handle's metadata;
 * constructing it performs no authentication request or other network I/O.
 */
final class WhmClient extends AbstractApiClient
{
    private ?Server $server = null;

    private ?Domains $domains = null;

    private ?Features $features = null;

    private ?Php $php = null;

    /**
     * Bind a WHM configuration to its HTTP transport.
     *
     * Credentials are checked before accepting the context. The supplied transport
     * remains responsible for PSR HTTP behavior, TLS verification, and disabling redirects.
     *
     * @throws LogicException If the credentials are not a WHM token.
     */
    public function __construct(ClientConfig $config, HttpTransport $transport)
    {
        if ($config->credentials->type !== TokenType::Whm) {
            throw new LogicException('WHM access requires WHM credentials.');
        }

        parent::__construct($config, $transport);
    }

    /**
     * Select the server operation group without making a request.
     *
     * The stable group updates this WHM handle's metadata when an operation runs.
     */
    public function server(): Server
    {
        return $this->server ??= new Server($this->request(...));
    }

    /**
     * Select the server domain operation group without making a request.
     *
     * The stable group shares this WHM handle's metadata with other WHM groups.
     */
    public function domains(): Domains
    {
        return $this->domains ??= new Domains($this->request(...));
    }

    /**
     * Select feature inventory for the authenticated WHM user without I/O.
     *
     * The cached group shares only this WHM handle's response metadata.
     */
    public function features(): Features
    {
        return $this->features ??= new Features($this->request(...), $this->beginRequest(...));
    }

    /**
     * Select server PHP configuration inventory without making a request.
     *
     * The cached group updates this WHM handle's response metadata on execution.
     */
    public function php(): Php
    {
        return $this->php ??= new Php($this->request(...));
    }

    /**
     * Execute a read-only operation declared by an internal operation group.
     *
     * Groups supply endpoint parameters; the API version cannot be overridden.
     * Execution clears metadata before query validation or I/O. Groups that
     * validate inputs before calling this helper must invoke beginRequest first.
     * @param array<string, string|int> $query
     */
    private function request(string $operation, array $query): ApiResponse
    {
        return $this->executeGet('/json-api/' . $operation, ['api.version' => 1] + $query, ResponseRoute::Whm);
    }
}
