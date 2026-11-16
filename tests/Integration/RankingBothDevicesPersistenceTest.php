<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\SerpDevice;

/** The both-devices ranking setting (1.6.0) on the real engine. */
final class RankingBothDevicesPersistenceTest extends FullStackTestCase
{
    public function testSettingRoundTrips(): void
    {
        $owner = $this->createUser('devices@acme.test', 'Devices');
        $publicId = $this->createProject($this->browserFor('devices@acme.test'), 'Devices', 'acme.test');
        $project = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $rankings = $this->app->make(RankingRepository::class);

        self::assertFalse($rankings->settings($project)->bothDevices);
        $rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Mobile, RankingSettings::WEEKLY, true), $owner['user']->id);
        $settings = $rankings->settings($project);
        self::assertTrue($settings->bothDevices);
        self::assertSame([SerpDevice::Mobile, SerpDevice::Desktop], $settings->devices());
        $rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Mobile, RankingSettings::WEEKLY), $owner['user']->id);
        self::assertFalse($rankings->settings($project)->bothDevices);
    }
}
