<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use HostingPal\WhmApiClient\Response\UserFeatureSetting;
use HostingPal\WhmApiClient\Response\UserFeatureSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UserFeatureSettingTest extends TestCase
{
    public function testNormalizesTheListSettingAndKeepsTheOverride(): void
    {
        $wire = [
            'cpuser_setting' => 0, 'feature' => 'ssl', 'feature_list' => 'default',
            'feature_list_setting' => '1', 'user' => 'alice',
        ];
        $setting = UserFeatureSetting::fromPayload((object) $wire, new ResponseMetadata(200));

        self::assertSame(0, $setting->cpuserSetting);
        self::assertSame('ssl', $setting->feature);
        self::assertSame('default', $setting->featureList);
        self::assertSame(1, $setting->featureListSetting);
        self::assertSame('alice', $setting->user);
        self::assertSame($wire, $setting->toArray());
    }

    public function testExplicitNullOverrideIsExportedButOmissionIsNot(): void
    {
        $explicit = UserFeatureSetting::fromPayload((object) ['cpuser_setting' => null], new ResponseMetadata(200));
        $omitted = UserFeatureSetting::fromPayload(new stdClass(), new ResponseMetadata(200));

        self::assertNull($explicit->cpuserSetting);
        self::assertSame(['cpuser_setting' => null], $explicit->toArray());
        self::assertNull($omitted->cpuserSetting);
        self::assertNull($omitted->featureListSetting);
        self::assertSame([], $omitted->toArray());
    }

    #[DataProvider('invalidRecords')]
    public function testRejectsInvalidRecordsWithTheResponseSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            UserFeatureSetting::fromPayload($payload, $metadata);
            self::fail('Expected an invalid record.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRecords(): iterable
    {
        yield 'not an object' => [null];
        yield 'string override' => [(object) ['cpuser_setting' => '1']];
        yield 'boolean list setting' => [(object) ['feature_list_setting' => false]];
        yield 'integer user' => [(object) ['user' => 7]];
    }

    public function testCollectionKeepsOmittedAndEmptyListsDistinct(): void
    {
        $metadata = new ResponseMetadata(200);
        $omitted = UserFeatureSettings::fromPayload(new stdClass(), $metadata);
        $empty = UserFeatureSettings::fromPayload((object) ['users_features_settings' => []], $metadata);
        $settings = UserFeatureSettings::fromPayload((object) ['users_features_settings' => [
            (object) ['user' => 'alice', 'feature_list_setting' => '0'],
        ]], $metadata);

        self::assertNull($omitted->settings);
        self::assertSame([], $omitted->toArray());
        self::assertCount(0, $omitted);
        self::assertSame([], $empty->settings);
        self::assertSame(['users_features_settings' => []], $empty->toArray());
        self::assertCount(1, $settings);
        self::assertSame($settings->settings, iterator_to_array($settings));
        self::assertSame(0, $settings->settings[0]->featureListSetting ?? null);
        self::assertSame(
            ['users_features_settings' => [['feature_list_setting' => '0', 'user' => 'alice']]],
            $settings->toArray(),
        );
    }

    public function testCollectionRejectsMalformedListsWithTheResponseSnapshot(): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            UserFeatureSettings::fromPayload((object) ['users_features_settings' => (object) []], $metadata);
            self::fail('Expected an invalid settings list.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }
}
