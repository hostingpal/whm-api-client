<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient;

use HostingPal\WhmApiClient\Authentication\TokenType;
use HostingPal\WhmApiClient\Config\ClientConfig;
use HostingPal\WhmApiClient\Config\ClientConfigFactory;
use HostingPal\WhmApiClient\Http\HttpTransport;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Select WHM and account-scoped UAPI clients from one credential configuration.
 *
 * Handles share an HTTP transport but retain separate account and metadata state.
 * Selection prepares context without I/O; terminal operations live on each
 * handle's operation groups (the README lists the operation mapping).
 * Credentials select direct cPanel access or account-scoped execution through WHM.
 *
 * @phpstan-import-type ClientOptions from ClientConfigFactory
 */
final class Client
{
    private readonly ClientConfig $config;

    private readonly HttpTransport $transport;

    private ?WhmClient $whm = null;

    /** @var array<string, UapiClient> */
    private array $uapi = [];

    /**
     * Create a client from validated options or an immutable configuration.
     *
     * The transport is explicit: its PSR-18 client must verify TLS, disable
     * redirects, and set timeouts for authenticated requests. HttpTransport::discover()
     * is the explicit opt-in for a discovered client with unverified defaults.
     * Construction performs no network requests and never treats a WHM user as a
     * UAPI target. Terminal API operations do not retry failures.
     *
     * @param ClientOptions|ClientConfig $config
     * @throws InvalidArgumentException If configuration values are invalid.
     */
    public function __construct(#[SensitiveParameter] array|ClientConfig $config, HttpTransport $transport)
    {
        $this->config = is_array($config) ? ClientConfigFactory::fromArray($config) : $config;
        $this->transport = $transport;
    }

    /**
     * Return the configured WHM client handle.
     *
     * Repeated calls reuse the handle and its metadata state. cPanel credentials
     * cannot authorize WHM access; no request is made by this selector. Its
     * server, domains, php and features groups execute WHM operations on demand.
     *
     * @throws \LogicException If the configuration contains cPanel credentials.
     */
    public function whm(): WhmClient
    {
        return $this->whm ??= new WhmClient($this->config, $this->transport);
    }

    /**
     * Return the UAPI client for a cPanel account.
     *
     * Direct cPanel credentials default to their own account. WHM credentials use
     * the explicit target or configured default cPanel account. Each target has
     * a stable handle; selecting another account never changes an existing one.
     * Its domains, php, dns and features groups execute UAPI operations on demand
     * and update only the selected handle's response metadata.
     *
     * @param string|null $user Target account; null resolves the configured default.
     * @throws InvalidArgumentException If the target is missing, invalid, or mismatched.
     * @see https://api.docs.cpanel.net/cpanel/tokens
     * @see https://api.docs.cpanel.net/whm/use-whm-api-to-call-cpanel-api-and-uapi
     */
    public function uapi(?string $user = null): UapiClient
    {
        $target = $user ?? ($this->config->credentials->type === TokenType::Cpanel
            ? $this->config->credentials->username
            : $this->config->defaultCpanelUser);

        if ($target === null) {
            throw new InvalidArgumentException('UAPI through WHM requires an explicit or default cPanel account.');
        }

        // Prefix keys so numeric usernames are not converted to integer array keys.
        $key = 'account:' . $target;

        return $this->uapi[$key] ??= new UapiClient($this->config, $this->transport, $target);
    }
}
