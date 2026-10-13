<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\DomainInfo;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\ServerDomains;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ServerDomainsTest extends TestCase
{
    public function testHydratesRecordsInOrderAndExposesCountableIterableDomains(): void
    {
        $domains = ServerDomains::fromPayload((object) [
            'domains' => [(object) ['domain' => 'one.test'], (object) ['domain' => 'two.test']],
            'extension' => true,
        ], new ResponseMetadata(200));

        self::assertNotNull($domains->domains);
        self::assertCount(2, $domains);
        self::assertInstanceOf(DomainInfo::class, $domains->domains[0]);
        self::assertSame('one.test', $domains->domains[0]->domain);
        self::assertSame('two.test', $domains->domains[1]->domain);
        self::assertSame($domains->domains, iterator_to_array($domains));
        self::assertSame(['domains' => [
            ['domain' => 'one.test'],
            ['domain' => 'two.test'],
        ]], $domains->toArray());
    }

    public function testOmittedAndEmptyDomainListsRemainDistinct(): void
    {
        $metadata = new ResponseMetadata(200);
        $omitted = ServerDomains::fromPayload(new stdClass(), $metadata);
        $empty = ServerDomains::fromPayload((object) ['domains' => []], $metadata);
        $emptyRecord = ServerDomains::fromPayload((object) ['domains' => [new stdClass()]], $metadata);

        self::assertNull($omitted->domains);
        self::assertSame([], $omitted->toArray());
        self::assertCount(0, $omitted);
        self::assertSame([], iterator_to_array($omitted));
        self::assertSame([], $empty->domains);
        self::assertSame(['domains' => []], $empty->toArray());
        self::assertCount(0, $empty);
        self::assertSame([], iterator_to_array($empty));
        self::assertCount(1, $emptyRecord);
        self::assertSame(['domains' => [[]]], $emptyRecord->toArray());
    }

    public function testInputObjectsListReferencesAndExportsCannotAlterTheSnapshot(): void
    {
        $name = 'example.test';
        $record = (object) ['domain' => &$name];
        $records = [&$record];
        $payload = (object) ['domains' => &$records];
        $domains = ServerDomains::fromPayload($payload, new ResponseMetadata(200));

        $name = 'changed.test';
        $record->user = 'changed';
        $record = new stdClass();
        $records[] = (object) ['domain' => 'added.test'];
        $payload->domains = [];
        $exported = $domains->toArray();
        $exported['domains'][0]['domain'] = 'exported.test';
        $iterated = iterator_to_array($domains);
        $iterated[] = DomainInfo::fromPayload(new stdClass(), new ResponseMetadata(200));

        self::assertCount(1, $domains);
        self::assertSame('example.test', $domains->domains[0]->domain ?? null);
        self::assertSame(['domains' => [['domain' => 'example.test']]], $domains->toArray());
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedContainersAndRecordsWithTheResponseSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            ServerDomains::fromPayload($payload, $metadata);
            self::fail('Malformed server domains must not produce a collection.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'null payload' => [null];
        yield 'scalar payload' => ['domains'];
        yield 'list payload' => [[]];
        yield 'associative array payload' => [['domains' => []]];
        yield 'non-JSON payload' => [new ResponseMetadata(200)];
        yield 'null domains' => [(object) ['domains' => null]];
        yield 'scalar domains' => [(object) ['domains' => 'example.test']];
        yield 'object domains' => [(object) ['domains' => new stdClass()]];
        yield 'associative domains' => [(object) ['domains' => ['key' => new stdClass()]]];
        yield 'sparse domains' => [(object) ['domains' => [1 => new stdClass()]]];
        yield 'null record' => [(object) ['domains' => [null]]];
        yield 'scalar record' => [(object) ['domains' => ['example.test']]];
        yield 'list record' => [(object) ['domains' => [[]]]];
        yield 'associative array record' => [(object) ['domains' => [['domain' => 'example.test']]]];
        yield 'invalid second record' => [(object) ['domains' => [new stdClass(), (object) ['port' => '8e1']]]];
    }
}
