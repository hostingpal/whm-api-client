<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\Tools\LiveVerify\DisposableAccount;
use HostingPal\WhmApiClient\Tools\LiveVerify\DomainRowsRecipe;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class DomainRowsRecipeTest extends TestCase
{
    /**
     * The recipe spells every request itself; the generated client must send exactly that, so the guard compares two
     * independent representations of each request.
     */
    #[DataProvider('phases')]
    public function testEveryProbeSendsExactlyTheRecipeRequest(string $phase): void
    {
        self::recipe();
        $probe = DomainRowsRecipe::probe($phase);
        $recorder = new class implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $sent = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return new Response(200, [], '{"metadata":{"result":0},"status":0,"errors":["refused"]}');
            }
        };
        $factory = new Psr17Factory();
        $direct = $probe->route === 'direct-uapi';
        $client = new Client([
            'host' => 'test.oblak.host', 'port' => $direct ? 2083 : 2087, 'authType' => $direct ? 'cpanel' : 'whm',
            'username' => $direct ? DomainRowsRecipe::ACCOUNT : 'root', 'token' => 'TOKEN',
        ], new HttpTransport($recorder, $factory, $factory));
        try {
            ($probe->invoke)($client);
        } catch (Throwable) {
            // The refusal is synthetic; only the request matters here.
        }

        $sent = $recorder->sent;
        self::assertCount(1, $sent);
        self::assertSame($probe->uri, (string) $sent[0]->getUri());
        $body = DomainRowsRecipe::mutationBody($phase);
        self::assertSame($body === null ? 'GET' : 'POST', $sent[0]->getMethod());
        self::assertSame($body ?? '', (string) $sent[0]->getBody());
        self::assertSame($probe->spec['method'], $sent[0]->getMethod());
    }

    /** @return iterable<string, array{string}> */
    public static function phases(): iterable
    {
        foreach (DomainRowsRecipe::PHASES as $phase) {
            yield $phase => [$phase];
        }
    }

    public function testPhasesAreTheSetupThenDnsThenDomainInfoPhasesOnce(): void
    {
        self::assertSame(
            [
                ...DomainRowsRecipe::SETUP_PHASES,
                ...DomainRowsRecipe::DNS_PHASES,
                ...DomainRowsRecipe::DOMAIN_INFO_PHASES,
            ],
            DomainRowsRecipe::PHASES,
        );
        self::assertSame(DomainRowsRecipe::PHASES, array_values(array_unique(DomainRowsRecipe::PHASES)));
        self::assertCount(5, DomainRowsRecipe::SETUP_PHASES);
        self::assertCount(16, DomainRowsRecipe::DNS_PHASES);
        self::assertCount(14, DomainRowsRecipe::DOMAIN_INFO_PHASES);
    }

    /** Each multi-value read is sent in both orders, so request order and a sorted order can be told apart. */
    public function testDnsReadsSendBothDistinctDomainsInBothOrders(): void
    {
        $forward = [DomainRowsRecipe::DOMAIN, DomainRowsRecipe::ADDON];
        foreach (DomainRowsRecipe::DNS_PHASES as $phase) {
            $expected = str_ends_with($phase, '_forward') ? $forward : array_reverse($forward);
            self::assertSame($expected, DomainRowsRecipe::requestedDomains($phase), $phase);
            $query = (string) parse_url(DomainRowsRecipe::probe($phase)->uri, PHP_URL_QUERY);
            $values = [];
            foreach (explode('&', $query) as $pair) {
                if (str_starts_with($pair, 'domain=')) {
                    $values[] = substr($pair, 7);
                }
            }
            self::assertSame($expected, $values, $phase);
        }
        // The addon sorts before the main domain, so neither order is also the sorted one by accident of naming.
        self::assertLessThan(0, strcmp(DomainRowsRecipe::ADDON, DomainRowsRecipe::DOMAIN));
    }

    public function testEveryExtraIdentityIsThisRunsOwnRfc2606Name(): void
    {
        $names = [...array_values(DomainRowsRecipe::DOMAINS), ...array_values(DomainRowsRecipe::ZONES)];
        foreach ($names as $name) {
            self::assertMatchesRegularExpression(DisposableAccount::DOMAIN_PATTERN, $name);
            self::assertStringEndsWith('.example.com', $name);
            self::assertStringContainsString('wld', $name);
            self::assertNotSame(DomainRowsRecipe::DOMAIN, $name);
        }
        $suffix = '.' . DomainRowsRecipe::DOMAIN;
        self::assertSame(DomainRowsRecipe::ADDON_LABEL . $suffix, DomainRowsRecipe::ADDON_SUBDOMAIN);
        self::assertSame(DomainRowsRecipe::SUB_LABEL . '.' . DomainRowsRecipe::DOMAIN, DomainRowsRecipe::SUBDOMAIN);
    }

    public function testOnlyTheDesignatedWritableBindingIsAccepted(): void
    {
        self::recipe();
        foreach (
            [
            ['wldomainsx', 'wldomains.example.com', 'wldomains-rows', false],
                ['wldomains', 'wldomains.example.net', 'wldomains-rows', false],
                ['wldomains', 'wldomains.example.com', 'wldomains-other', false],
                ['wldomains', 'wldomains.example.com', 'wldomains-rows', true]] as [$user, $domain, $name, $readOnly]
        ) {
            try {
                new DomainRowsRecipe(DisposableAccount::fresh($user, $domain, $name, 900, $readOnly));
                $refused = false;
            } catch (LogicException) {
                $refused = true;
            }
            self::assertTrue($refused, $user . ' ' . $domain . ' ' . $name);
        }
    }

    public function testOnlyMutationPhasesHaveABody(): void
    {
        self::recipe();
        foreach (DomainRowsRecipe::PHASES as $phase) {
            $mutation = in_array($phase, ['limits', 'create_addon', 'park_alias', 'create_subdomain'], true);
            self::assertSame($mutation, DomainRowsRecipe::mutationBody($phase) !== null, $phase);
        }
        self::assertSame(
            'user=wldomains&MAXADDON=1&MAXFTP=2&MAXPARK=1&MAXSUB=2',
            DomainRowsRecipe::mutationBody('limits'),
        );
    }

    private static function recipe(): DomainRowsRecipe
    {
        return new DomainRowsRecipe(DisposableAccount::fresh(
            DomainRowsRecipe::ACCOUNT,
            DomainRowsRecipe::DOMAIN,
            DomainRowsRecipe::TOKEN_NAME,
            900,
            false,
        ));
    }
}
