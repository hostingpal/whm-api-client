<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainInfoDocumentedOutputTest extends TestCase
{
    /** @param 'hash'|'list' $format */
    #[DataProvider('examples')]
    public function testPinnedExamplesKeepTheDocumentedEmptyString(string $route, string $format): void
    {
        $fixture = file_get_contents(__DIR__ . '/../Fixtures/DomainInfo/domains-data-' . $format . '.json');
        self::assertIsString($fixture);
        [$client, $http] = self::client($route);
        $http->addResponse(new Response(200, [], self::envelope($route, $fixture)));
        $data = $client->uapi('npr')->domainInfo()->domainsData(format: $format);
        if ($format === 'hash') {
            self::assertCount(3, $data['addon_domains']);
            foreach ([0, 1, 2] as $index) {
                self::assertIsArray($data['addon_domains'][$index]);
                self::assertSame('', $data['addon_domains'][$index]['userdirprotect']);
            }
            self::assertSame('seconddomain.com', $data['addon_domains'][0]['domain']);
            self::assertSame(1, $data['addon_domains'][0]['hascgi']);
        } else {
            self::assertCount(7, $data);
            self::assertSame(1, $data[0]['userdirprotect']);
            self::assertSame(80, $data[0]['port']);
            self::assertSame(['parkeddomain.com'], $data[1]['parked_with_https_redirects']);
            foreach ([3, 4, 5, 6] as $index) {
                self::assertSame('', $data[$index]['userdirprotect']);
                self::assertSame(0, $data[$index]['is_temporary']);
            }
        }
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function examples(): iterable
    {
        foreach (['wrapped', 'bare', 'bridge'] as $route) {
            foreach (['hash', 'list'] as $format) {
                yield $route . ' ' . $format => [$route, $format];
            }
        }
    }

    #[DataProvider('recordForms')]
    public function testSharedFieldsAcceptQuotedFlagsAndPreserveEmptyUserdirprotect(string $form): void
    {
        [$client, $http] = self::client('bare');
        $row = ['all_aliases_valid' => '0', 'can_https_redirect' => '1', 'hascgi' => '1',
            'is_https_redirecting' => '0', 'is_temporary' => '1', 'userdirprotect' => ''];
        $payload = self::container($form, $row);
        $body = json_encode(['status' => 1, 'data' => $payload], JSON_THROW_ON_ERROR);
        $http->addResponse(new Response(200, [], $body));
        $group = $client->uapi()->domainInfo();
        $actual = $form === 'single' ? $group->singleDomainData('example.test') : $group->domainsData();
        self::assertSame(self::container($form, ['all_aliases_valid' => 0, 'can_https_redirect' => 1, 'hascgi' => 1,
            'is_https_redirecting' => 0, 'is_temporary' => 1, 'userdirprotect' => '']), $actual);
    }

    #[DataProvider('invalidFlags')]
    public function testEmptyStringCompatibilityDoesNotAdmitOtherFlagValues(string $form, mixed $invalid): void
    {
        [$client, $http] = self::client('bare');
        $http->addResponse(new Response(200, [], json_encode(
            ['status' => 1, 'data' => self::container($form, ['userdirprotect' => $invalid])],
            JSON_THROW_ON_ERROR,
        )));
        $this->expectException(PayloadMismatchException::class);
        $group = $client->uapi()->domainInfo();
        $form === 'single' ? $group->singleDomainData('example.test') : $group->domainsData();
    }

    /** @return iterable<string, array{string}> */
    public static function recordForms(): iterable
    {
        foreach (['single', 'list', 'main', 'main-list', 'addon', 'parked', 'sub'] as $form) {
            yield $form => [$form];
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFlags(): iterable
    {
        foreach (self::recordForms() as [$form]) {
            foreach ([2, '2', ' ', '00', 'false', true, null] as $index => $value) {
                yield $form . ' invalid ' . $index => [$form, $value];
            }
        }
    }

    /** @param array<string, mixed> $row
     * @return array<mixed>
     */
    private static function container(string $form, array $row): array
    {
        return match ($form) {
            'single' => $row,
            'list' => [$row],
            'main' => ['main_domain' => $row],
            'main-list' => ['main_domain' => [$row]],
            'addon' => ['addon_domains' => [$row]],
            'parked' => ['parked_domains' => [$row]],
            default => ['sub_domains' => [$row]],
        };
    }

    private static function envelope(string $route, string $result): string
    {
        return match ($route) {
            'wrapped' => '{"result":' . $result . '}',
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $result . '}}',
            default => $result,
        };
    }

    /** @return array{Client, MockClient} */
    private static function client(string $route): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'bridge' ? 'whm' : 'cpanel',
            'username' => $route === 'bridge' ? 'root' : 'npr', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        ), $http];
    }
}
