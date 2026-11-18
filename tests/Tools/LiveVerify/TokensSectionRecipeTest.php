<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\TokensSectionRecipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The recipe's reading of original responses, checked on literal bodies without the fixture or the client. */
final class TokensSectionRecipeTest extends TestCase
{
    private const ORIGINAL = 'whmapiclient-live-0a1b2c-direct';

    private const RENAMED = 'whmapiclient-live-0a1b2c-direct-renamed';

    private const SETTINGS = ',"expires_at":"1795080067","readonly":1,"has_full_access":1}';

    /** @return iterable<string,array{string,array{original:string,renamed:string}|null}> */
    public static function lists(): iterable
    {
        $original = '{"name":"' . self::ORIGINAL . '"' . self::SETTINGS;
        $renamed = '{"name":"' . self::RENAMED . '"' . self::SETTINGS;
        $absent = ['original' => 'absent', 'renamed' => 'absent'];
        yield 'empty list' => ['[]', $absent];
        yield 'only other tokens' => ['[{"name":"existing"},{"name":"whmapiclient-live-ffffff-direct"}]', $absent];
        yield 'a name that only starts like the run name' => ['[{"name":"' . self::ORIGINAL . 'x"}]', $absent];
        yield 'first name settled' => ['[{"name":"existing"},' . $original . ']',
            ['original' => 'settled', 'renamed' => 'absent']];
        yield 'renamed settled' => ['[' . $renamed . ']', ['original' => 'absent', 'renamed' => 'settled']];
        yield 'integer expiry is settled too' => [
            '[{"name":"' . self::ORIGINAL . '","expires_at":1795080067,"readonly":1,"has_full_access":1}]',
            ['original' => 'settled', 'renamed' => 'absent'],
        ];
        yield 'both names present' => ['[' . $original . ',' . $renamed . ']',
            ['original' => 'settled', 'renamed' => 'settled']];
        yield 'another expiry' => [
            '[{"name":"' . self::ORIGINAL . '","expires_at":"1795080068","readonly":1,"has_full_access":1}]',
            ['original' => 'other', 'renamed' => 'absent'],
        ];
        yield 'no expiry' => [
            '[{"name":"' . self::ORIGINAL . '","expires_at":null,"readonly":1,"has_full_access":1}]',
            ['original' => 'other', 'renamed' => 'absent'],
        ];
        yield 'writable' => [
            '[{"name":"' . self::RENAMED . '","expires_at":"1795080067","readonly":0,"has_full_access":1}]',
            ['original' => 'absent', 'renamed' => 'other'],
        ];
        yield 'without full access' => [
            '[{"name":"' . self::ORIGINAL . '","expires_at":"1795080067","readonly":1,"has_full_access":0}]',
            ['original' => 'other', 'renamed' => 'absent'],
        ];
        $other = ['original' => 'other', 'renamed' => 'absent'];
        yield 'bare name only' => ['[{"name":"' . self::ORIGINAL . '"}]', $other];
        yield 'listed twice' => ['[' . $original . ',' . $original . ']', $other];
        yield 'a row without a name' => ['[{}]', null];
        yield 'a nameless row after the run name' => ['[' . $original . ',{"create_time":1}]', null];
        yield 'a null name' => ['[{"name":null}]', null];
        yield 'an empty name' => ['[{"name":""}]', null];
        yield 'a numeric name' => ['[{"name":7}]', null];
        yield 'a row that is a string' => ['["existing"]', null];
        yield 'a row that is a list' => ['[[]]', null];
        yield 'a row exposing a secret' => ['[{"name":"existing","token":"T"}]', null];
        yield 'a secret that is null' => ['[{"name":"existing","token":null}]', null];
        yield 'an object instead of a list' => ['{"name":"existing"}', null];
        yield 'null' => ['null', null];
        yield 'a number' => ['1', null];
    }

    /** @param array{original:string,renamed:string}|null $expected */
    #[DataProvider('lists')]
    public function testInventoryReportsWhatAListProvesOnEveryEnvelopeLayout(string $data, ?array $expected): void
    {
        $recipe = new TokensSectionRecipe('0a1b2c', 1795080067);

        self::assertSame($expected, $recipe->inventory('direct', '{"status":1,"data":' . $data . '}'));
        self::assertSame($expected, $recipe->inventory('direct', '{"result":{"status":1,"data":' . $data . '}}'));
        // The bridged route has its own names, so the direct route's names never count there.
        $bridged = $recipe->inventory('bridge', '{"metadata":{"result":1},"data":{"uapi":{"data":' . $data . '}}}');
        self::assertSame($expected === null ? null : ['original' => 'absent', 'renamed' => 'absent'], $bridged);

        $absent = $expected === ['original' => 'absent', 'renamed' => 'absent'];
        foreach (['preflight_direct', 'absent_direct'] as $phase) {
            self::assertSame($absent, $recipe->holds($phase, '{"status":1,"data":' . $data . '}'), $phase);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function unusableBodies(): iterable
    {
        yield 'not JSON' => ['<html>'];
        yield 'a list document' => ['[]'];
        yield 'no data member' => ['{"status":1}'];
        yield 'a bridged body without the UAPI envelope' => ['{"metadata":{"result":1},"data":{}}'];
    }

    #[DataProvider('unusableBodies')]
    public function testABodyWithoutAPayloadProvesNothing(string $raw): void
    {
        $recipe = new TokensSectionRecipe('0a1b2c', 1795080067);

        self::assertNull($recipe->inventory('bridge', $raw));
        foreach (['preflight_bridge', 'targets_bridge', 'create_bridge', 'rename_bridge', 'revoke_bridge'] as $phase) {
            self::assertFalse($recipe->holds($phase, $raw), $phase);
        }
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function payloads(): iterable
    {
        $none = '{"contact_email":"","contact_email_2":null,"pushbullet_access_token":""}';
        yield 'no notification target' => ['targets_direct', $none, true];
        yield 'a contact address' => ['targets_direct',
            '{"contact_email":"a@example.test","contact_email_2":"","pushbullet_access_token":""}', false];
        yield 'a whitespace contact address' => ['targets_direct',
            '{"contact_email":" ","contact_email_2":"","pushbullet_access_token":""}', false];
        yield 'a Pushbullet token' => ['targets_direct',
            '{"contact_email":"","contact_email_2":"","pushbullet_access_token":"o.x"}', false];
        yield 'a target that is not reported' => ['targets_direct', '{"contact_email":"","contact_email_2":""}', false];
        yield 'targets as a list' => ['targets_direct', '[]', false];
        yield 'targets null' => ['targets_direct', 'null', false];
        yield 'created' => ['create_direct', '{"create_time":1794993667,"token":"T","whitelist_ips":null}', true];
        yield 'created without a secret' => ['create_direct', '{"create_time":1794993667}', false];
        yield 'created with an empty secret' => ['create_direct', '{"create_time":1794993667,"token":""}', false];
        yield 'created without a time' => ['create_direct', '{"token":"T"}', false];
        yield 'created with a string time' => ['create_direct', '{"create_time":"1794993667","token":"T"}', false];
        yield 'renamed' => ['rename_direct', 'null', true];
        yield 'rename returning an object' => ['rename_direct', '{}', false];
        yield 'rename returning zero' => ['rename_direct', '0', false];
        yield 'revoked' => ['revoke_direct', '1', true];
        yield 'revoke returning zero' => ['revoke_direct', '0', false];
        yield 'revoke returning a digit string' => ['revoke_direct', '"1"', false];
        yield 'revoke returning true' => ['revoke_direct', 'true', false];
        yield 'second revoke' => ['revoke_again_direct', '0', true];
        yield 'second revoke returning one' => ['revoke_again_direct', '1', false];
        yield 'second revoke returning null' => ['revoke_again_direct', 'null', false];
    }

    #[DataProvider('payloads')]
    public function testEachPhaseHoldsOnlyOnItsExactPayload(string $phase, string $data, bool $expected): void
    {
        $recipe = new TokensSectionRecipe('0a1b2c', 1795080067);

        self::assertSame($expected, $recipe->holds($phase, '{"status":1,"data":' . $data . '}'));
    }

    public function testListPhasesRequireTheirOwnInventory(): void
    {
        $recipe = new TokensSectionRecipe('0a1b2c', 1795080067);
        $original = '{"name":"' . self::ORIGINAL . '"' . self::SETTINGS;
        $renamed = '{"name":"' . self::RENAMED . '"' . self::SETTINGS;
        $both = $original . ',' . $renamed;
        $cases = [
            'listed_direct' => [$original => true, $renamed => false, $both => false, '' => false],
            'renamed_direct' => [$renamed => true, $original => false, $both => false, '' => false],
        ];
        foreach ($cases as $phase => $lists) {
            foreach ($lists as $rows => $expected) {
                self::assertSame($expected, $recipe->holds($phase, '{"status":1,"data":[' . $rows . ']}'), $phase);
            }
        }
    }
}
