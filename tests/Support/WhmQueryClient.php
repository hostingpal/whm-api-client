<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Support;

use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\NumberedList;
use HostingPal\WhmApiClient\Config\ClientConfigFactory;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\ResponseRoute;

/** Expose the shared WHM executors so tests can exercise query and body encoding directly. */
final class WhmQueryClient extends AbstractApiClient
{
    public function __construct(HttpTransport $transport)
    {
        parent::__construct(ClientConfigFactory::fromArray([
            'host' => 'api.example.test',
            'authType' => 'whm',
            'token' => 'fixture-token',
        ]), $transport);
    }

    /** @param array<string, NumberedList> $query */
    public function get(array $query): ApiResponse
    {
        return $this->executeGet(
            '/json-api/get_users_features_settings',
            ['api.version' => 1] + $query,
            ResponseRoute::Whm,
        );
    }

    /** @param array<string, string|int|list<string>|NumberedList> $body */
    public function post(array $body): ApiResponse
    {
        return $this->executePost(
            '/json-api/get_users_features_settings',
            ['api.version' => 1],
            $body,
            ResponseRoute::Whm,
        );
    }
}
