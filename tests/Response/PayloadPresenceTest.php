<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Response\EnvelopeReader;
use HostingPal\WhmApiClient\Response\ResponseRoute;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class PayloadPresenceTest extends TestCase
{
    public function testMissingPayloadRemainsDistinctFromExplicitNullOnEveryRoute(): void
    {
        foreach ([false, true] as $present) {
            $field = $present ? ',"data":null' : '';
            foreach (
                [
                [ResponseRoute::Whm, '{"metadata":{"result":1}' . $field . '}'],
                [ResponseRoute::DirectUapi, '{"status":1' . $field . '}'],
                [ResponseRoute::DirectUapi, '{"result":{"status":1' . $field . '}}'],
                [ResponseRoute::BridgedUapi,
                    '{"metadata":{"result":1},"data":{"uapi":{"status":1' . $field . '}}}'],
                ] as [$route, $body]
            ) {
                $response = EnvelopeReader::read(new Response(200, [], $body), $route);
                self::assertSame($present, $response->hasData);
                self::assertNull($response->data);
                self::assertSame(200, $response->metadata->httpStatus);
            }
        }
    }
}
