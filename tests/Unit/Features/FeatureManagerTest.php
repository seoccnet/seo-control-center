<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Features;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Features\ConfiguredDisabledFeatures;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;

final class FeatureManagerTest extends TestCase
{
    public function testEverythingIsOnByDefault(): void
    {
        $features = new FeatureManager(new ConfiguredDisabledFeatures([]));

        foreach (Feature::cases() as $feature) {
            self::assertTrue($features->enabled($feature), $feature->value);
            $features->require($feature);
        }
    }

    public function testAListedFeatureIsOffAndNamedWhenRequired(): void
    {
        $features = new FeatureManager(new ConfiguredDisabledFeatures(['backlinks']));

        self::assertFalse($features->enabled(Feature::Backlinks));
        self::assertTrue($features->enabled(Feature::Mcp));
        try {
            $features->require(Feature::Backlinks);
            self::fail('A feature that is off was allowed.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->statusCode());
            self::assertStringContainsString('Backlinks is turned off on this installation', $e->getMessage());
        }
    }
}
