<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\AccountDomains;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AccountDomainsTest extends TestCase
{
    public function testMapsTheReviewedDomainFieldsAndIgnoresUnknownExtensions(): void
    {
        $domains = AccountDomains::fromPayload((object) [
            'main_domain' => 'example.test',
            'addon_domains' => ['addon.example.test'],
            'parked_domains' => ['parked.example.test'],
            'sub_domains' => ['sub.example.test'],
            'is_temporary' => (object) ['example.test' => 0, 'temporary.example.test' => 1],
            'extension' => (object) ['future' => true],
        ], new ResponseMetadata(200));

        self::assertSame('example.test', $domains->mainDomain);
        self::assertSame(['addon.example.test'], $domains->addonDomains);
        self::assertSame(['parked.example.test'], $domains->parkedDomains);
        self::assertSame(['sub.example.test'], $domains->subDomains);
        self::assertSame(['example.test' => 0, 'temporary.example.test' => 1], $domains->isTemporary);
        self::assertSame([
            'main_domain' => 'example.test',
            'addon_domains' => ['addon.example.test'],
            'parked_domains' => ['parked.example.test'],
            'sub_domains' => ['sub.example.test'],
            'is_temporary' => ['example.test' => 0, 'temporary.example.test' => 1],
        ], $domains->toArray());
    }

    public function testKeepsOmittedFieldsDistinctFromExplicitNullAndEmptyValues(): void
    {
        $metadata = new ResponseMetadata(200);
        $omitted = AccountDomains::fromPayload(new stdClass(), $metadata);
        $explicit = AccountDomains::fromPayload((object) [
            'main_domain' => null,
            'addon_domains' => [],
            'parked_domains' => [],
            'sub_domains' => [],
            'is_temporary' => new stdClass(),
        ], $metadata);

        self::assertNull($omitted->mainDomain);
        self::assertNull($omitted->addonDomains);
        self::assertNull($omitted->parkedDomains);
        self::assertNull($omitted->subDomains);
        self::assertNull($omitted->isTemporary);
        self::assertSame([], $omitted->toArray());
        self::assertNull($explicit->mainDomain);
        self::assertSame([], $explicit->addonDomains);
        self::assertSame([], $explicit->parkedDomains);
        self::assertSame([], $explicit->subDomains);
        self::assertSame([], $explicit->isTemporary);
        self::assertSame([
            'main_domain' => null,
            'addon_domains' => [],
            'parked_domains' => [],
            'sub_domains' => [],
            'is_temporary' => [],
        ], $explicit->toArray());
    }

    public function testPreservesBlankDomainValuesFromSuccessfulButIncompleteResponses(): void
    {
        $domains = AccountDomains::fromPayload((object) [
            'main_domain' => '',
            'addon_domains' => [''],
        ], new ResponseMetadata(200));

        self::assertSame('', $domains->mainDomain);
        self::assertSame(['main_domain' => '', 'addon_domains' => ['']], $domains->toArray());
    }

    public function testCopiesMutablePayloadsAndNestedReferencesIntoAnIndependentSnapshot(): void
    {
        $mainDomain = 'example.test';
        $addonDomain = 'addon.example.test';
        $temporaryFlag = 0;
        $flags = (object) ['example.test' => &$temporaryFlag];
        $payload = (object) [
            'main_domain' => &$mainDomain,
            'addon_domains' => [&$addonDomain],
            'is_temporary' => $flags,
        ];
        $domains = AccountDomains::fromPayload($payload, new ResponseMetadata(200));

        $mainDomain = 'changed.example.test';
        $addonDomain = 'changed-addon.example.test';
        $temporaryFlag = 1;
        $flags->{'new.example.test'} = 1;
        $exported = $domains->toArray();
        $exported['main_domain'] = 'exported.example.test';
        $exported['addon_domains'] = ['exported-addon.example.test'];
        $exported['is_temporary'] = ['example.test' => 1];

        self::assertSame('example.test', $domains->mainDomain);
        self::assertSame(['addon.example.test'], $domains->addonDomains);
        self::assertSame(['example.test' => 0], $domains->isTemporary);
        self::assertSame([
            'main_domain' => 'example.test',
            'addon_domains' => ['addon.example.test'],
            'is_temporary' => ['example.test' => 0],
        ], $domains->toArray());
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedKnownFieldsWithTheResponseSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1], ['status' => 1]);

        try {
            AccountDomains::fromPayload($payload, $metadata);
            self::fail('Malformed domain data must not produce a typed payload.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
            self::assertStringNotContainsString('sensitive-response-value', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'null payload' => [null];
        yield 'scalar payload' => ['sensitive-response-value'];
        yield 'empty list payload' => [[]];
        yield 'associative array payload' => [['main_domain' => 'example.test']];
        yield 'non-json object payload' => [new ResponseMetadata(200)];
        yield 'integer main domain' => [(object) ['main_domain' => 1]];
        yield 'object main domain' => [(object) ['main_domain' => new stdClass()]];
        yield 'null addon list' => [(object) ['addon_domains' => null]];
        yield 'scalar addon list' => [(object) ['addon_domains' => 'sensitive-response-value']];
        yield 'object addon list' => [(object) ['addon_domains' => new stdClass()]];
        yield 'associative addon list' => [(object) ['addon_domains' => ['domain' => 'example.test']]];
        yield 'sparse addon list' => [(object) ['addon_domains' => [1 => 'example.test']]];
        yield 'non-string addon item' => [(object) ['addon_domains' => [null]]];
        yield 'null parked list' => [(object) ['parked_domains' => null]];
        yield 'non-string parked item' => [(object) ['parked_domains' => [1]]];
        yield 'null subdomain list' => [(object) ['sub_domains' => null]];
        yield 'nested subdomain item' => [(object) ['sub_domains' => [['example.test']]]];
        yield 'null temporary map' => [(object) ['is_temporary' => null]];
        yield 'empty list temporary map' => [(object) ['is_temporary' => []]];
        yield 'associative array temporary map' => [(object) ['is_temporary' => ['example.test' => 0]]];
        yield 'non-json temporary object' => [(object) ['is_temporary' => new ResponseMetadata(200)]];
        yield 'numeric temporary map key' => [(object) ['is_temporary' => (object) ['123' => 0]]];
        yield 'boolean temporary flag' => [(object) ['is_temporary' => (object) ['example.test' => false]]];
        yield 'string temporary flag' => [(object) ['is_temporary' => (object) ['example.test' => '0']]];
        yield 'float temporary flag' => [(object) ['is_temporary' => (object) ['example.test' => 1.0]]];
        yield 'out-of-range temporary flag' => [(object) ['is_temporary' => (object) ['example.test' => 2]]];
        yield 'null temporary flag' => [(object) ['is_temporary' => (object) ['example.test' => null]]];
    }
}
