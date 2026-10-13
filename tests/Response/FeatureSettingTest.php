<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Response;

use HostingPal\WhmApiClient\Exception\InvalidResponseException;
use HostingPal\WhmApiClient\Response\FeatureListData;
use HostingPal\WhmApiClient\Response\FeatureSetting;
use HostingPal\WhmApiClient\Response\ResponseMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class FeatureSettingTest extends TestCase
{
    public function testExposesAllReviewedFieldsWithNormalizedFlags(): void
    {
        $wire = [
            'id' => 'ssl', 'is_disabled' => '0', 'value' => '1',
            'dependencies' => ['sslinstall'], 'name' => 'SSL', 'is_standalone_experience' => true,
            'badge_label' => '', 'badge_class' => 'featured', 'required_package_extension' => '',
            'is_meridian_only' => false, 'redirect_url' => '/ssl', 'suppress_plugin_label' => 1,
            'only_one_rules' => ['^standalone-.*'],
        ];
        $setting = FeatureSetting::fromPayload((object) $wire, new ResponseMetadata(200));

        self::assertSame('ssl', $setting->id);
        self::assertSame(0, $setting->isDisabled);
        self::assertSame(1, $setting->value);
        self::assertSame(['sslinstall'], $setting->dependencies);
        self::assertSame('SSL', $setting->name);
        self::assertSame(1, $setting->isStandaloneExperience);
        self::assertSame('', $setting->badgeLabel);
        self::assertSame('featured', $setting->badgeClass);
        self::assertSame('', $setting->requiredPackageExtension);
        self::assertSame(0, $setting->isMeridianOnly);
        self::assertSame('/ssl', $setting->redirectUrl);
        self::assertSame(1, $setting->suppressPluginLabel);
        self::assertSame(['^standalone-.*'], $setting->onlyOneRules);
        self::assertSame($wire, $setting->toArray());
    }

    public function testOptionalFieldsAreNullWhileExplicitMeridianNullIsExported(): void
    {
        $minimal = FeatureSetting::fromPayload(
            (object) ['id' => 'ssl', 'is_disabled' => 1, 'value' => 0],
            new ResponseMetadata(200),
        );
        $nullMeridian = FeatureSetting::fromPayload(
            (object) ['id' => 'ssl', 'is_disabled' => 1, 'value' => 0, 'is_meridian_only' => null],
            new ResponseMetadata(200),
        );

        self::assertNull($minimal->dependencies);
        self::assertNull($minimal->isStandaloneExperience);
        self::assertNull($minimal->isMeridianOnly);
        self::assertSame(['id' => 'ssl', 'is_disabled' => 1, 'value' => 0], $minimal->toArray());
        self::assertNull($nullMeridian->isMeridianOnly);
        self::assertSame(
            ['id' => 'ssl', 'is_disabled' => 1, 'value' => 0, 'is_meridian_only' => null],
            $nullMeridian->toArray(),
        );
    }

    #[DataProvider('invalidRecords')]
    public function testRejectsInvalidRecordsWithTheResponseSnapshot(mixed $payload): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            FeatureSetting::fromPayload($payload, $metadata);
            self::fail('Expected an invalid record.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRecords(): iterable
    {
        $valid = ['id' => 'ssl', 'is_disabled' => 0, 'value' => 1];
        yield 'not an object' => ['ssl'];
        yield 'missing id' => [(object) ['is_disabled' => 0, 'value' => 1]];
        yield 'missing is_disabled' => [(object) ['id' => 'ssl', 'value' => 1]];
        yield 'missing value' => [(object) ['id' => 'ssl', 'is_disabled' => 0]];
        yield 'boolean value' => [(object) ([...$valid, 'value' => true])];
        yield 'string standalone flag' => [(object) ([...$valid, 'is_standalone_experience' => '1'])];
        yield 'string meridian flag' => [(object) ([...$valid, 'is_meridian_only' => '0'])];
        yield 'non-string dependency' => [(object) ([...$valid, 'dependencies' => [1]])];
    }

    public function testListDataKeepsOmittedEmptyAndPresentFieldsDistinct(): void
    {
        $metadata = new ResponseMetadata(200);
        $omitted = FeatureListData::fromPayload(new stdClass(), $metadata);
        $empty = FeatureListData::fromPayload((object) ['featurelist' => 'default', 'features' => []], $metadata);
        $list = FeatureListData::fromPayload((object) ['featurelist' => 'disabled', 'features' => [
            (object) ['id' => 'ssl', 'is_disabled' => '0', 'value' => '1'],
        ]], $metadata);

        self::assertNull($omitted->featureList);
        self::assertNull($omitted->features);
        self::assertSame([], $omitted->toArray());
        self::assertCount(0, $omitted);
        self::assertSame('default', $empty->featureList);
        self::assertSame([], $empty->features);
        self::assertSame(['featurelist' => 'default', 'features' => []], $empty->toArray());
        self::assertCount(1, $list);
        self::assertSame($list->features, iterator_to_array($list));
        self::assertSame(1, $list->features[0]->value ?? null);
        self::assertSame(['featurelist' => 'disabled', 'features' => [
            ['id' => 'ssl', 'is_disabled' => '0', 'value' => '1'],
        ]], $list->toArray());
    }

    public function testListDataRejectsMalformedContainersWithTheResponseSnapshot(): void
    {
        $metadata = new ResponseMetadata(200, ['result' => 1]);

        try {
            FeatureListData::fromPayload((object) ['featurelist' => 1], $metadata);
            self::fail('Expected an invalid feature list.');
        } catch (InvalidResponseException $exception) {
            self::assertSame($metadata, $exception->metadata);
        }
    }
}
