<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api\Engine;

use HostingPal\WhmApiClient\Api\Engine\SpecChecker;
use HostingPal\WhmApiClient\Api\Engine\StructuredJsonEncoder;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class GeneratedJsonBodyTest extends TestCase
{
    public function testExplicitNonemptyListPolicy(): void
    {
        self::assertSame('[0]', StructuredJsonEncoder::encode(
            [0],
            ['type' => 'list', 'items' => ['type' => 'int'], 'nonempty' => true],
        ));
        self::assertSame('[]', StructuredJsonEncoder::encode([], ['type' => 'list', 'items' => ['type' => 'int']]));
        $this->expectException(InvalidArgumentException::class);
        StructuredJsonEncoder::encode([], ['type' => 'list', 'items' => ['type' => 'int'], 'nonempty' => true]);
    }

    public function testMalformedNonemptyPolicyIsASpecError(): void
    {
        $this->expectException(LogicException::class);
        StructuredJsonEncoder::encode([], ['type' => 'list', 'items' => ['type' => 'int'], 'nonempty' => 'true']);
    }
    public function testMalformedWholeBodySpecsFailBeforeInput(): void
    {
        $spec = [
            'function' => 'save', 'method' => 'POST', 'body' => 'json', 'outcome' => 'flag',
            'params' => [], 'returns' => null,
            'json_body' => ['php' => 'body', 'required' => true, 'routes' => 'direct', 'schema' => ['type' => 'int']],
        ];
        foreach (
            [
            ['json_body' => ['schema' => ['type' => 'json']] + $spec['json_body']] + $spec,
            ['method' => 'GET'] + $spec,
            ['params' => ['body' => ['name' => 'b', 'encoding' => 'scalar']]] + $spec,
            ['json_body' => null] + $spec,
            ['json_body' => ['routes' => 'bridge'] + $spec['json_body']] + $spec,
            ['json_body' => ['required' => false, 'schema' => ['type' => 'int', 'nullable' => true]]
                + $spec['json_body']] + $spec,
            ['json_body' => ['schema' => ['type' => 'object', 'fields' => ['api.future' => ['type' => 'string']],
                'required' => ['api.future']]] + $spec['json_body']] + $spec,
            ] as $invalid
        ) {
            try {
                SpecChecker::check($invalid);
                self::fail('Malformed whole-body spec was accepted.');
            } catch (LogicException $error) {
                self::assertNull($error->getPrevious());
            }
        }
    }
}
