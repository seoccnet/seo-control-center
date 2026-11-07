<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Analytics\LandingPageMetrics;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Tests\Support\Ga4Fixture;

final class Ga4PersistenceTest extends FullStackTestCase
{
    public function testEncryptedCredentialsReplacementAndPropertyChangeAreScoped(): void
    {
        $owner = $this->createUser('ga4@acme.test', 'GA4');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('ga4@acme.test'), 'GA4', 'ga4.test');
        $id = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $data = $this->app->make(Ga4Repository::class);
        $data->saveCredentials($owner['workspace']->id, Ga4Fixture::credentials());
        self::assertStringNotContainsString('PRIVATE KEY', (string) $this->db->fetchValue('SELECT credentials FROM ga4_settings WHERE workspace_id = ?', [$owner['workspace']->id]));
        self::assertEquals(Ga4Fixture::credentials(), $data->credentials($owner['workspace']->id));
        self::assertNull($data->credentials($owner['workspace']->id + 100));
        $data->link($id, '123');
        $range = new DateRange('2026-09-01', '2026-09-28');
        $url = 'https://ga4.test/Page?x=1';
        $report = new Ga4Report([new LandingPageMetrics('2026-09-01', $url, 10, 2.5, 100), new LandingPageMetrics('2026-09-02', $url, 5, 1, 20)], 'EUR', 'Europe/Berlin', false, true);
        $data->saveReport($id, $range, $report, '123');
        $data->saveReport($id, $range, $report, '123');
        self::assertSame([['url' => $url, 'sessions' => 15, 'conversions' => 3.5, 'revenue' => 120.0]], $data->landingPages($id, $range));
        self::assertSame([], $data->landingPages($id + 100, $range));
        self::assertSame([], $data->landingPages($id, $range, 10, 'https://ga4.test/page?x=1'));
        self::assertSame(28, $data->coveredDays($id, $range), 'Successful zero-row days are covered.');
        self::assertSame(['sessions' => 15, 'conversions' => 3.5, 'revenue' => 120.0, 'pages' => 1], $data->totals($id, $range));
        self::assertSame(0, $data->totals($id + 100, $range)['sessions']);
        self::assertSame([], $data->landingPages($id, $range, offset: 1));
        $daily = $data->daily($id, $range);
        self::assertCount(28, $daily);
        self::assertSame(['date' => '2026-09-03', 'sessions' => 0, 'conversions' => 0.0, 'revenue' => 0.0], $daily[2]);
        self::assertSame(0, $data->daily($id, $range, 'https://ga4.test/absent')[0]['sessions']);
        self::assertSame([], $data->daily($id + 100, $range));
        $october = new DateRange('2026-10-01', '2026-10-28');
        $data->saveReport($id, $october, new Ga4Report([new LandingPageMetrics('2026-10-01', $url, 7, 1, 30)], 'EUR', 'Europe/Berlin', false, false), '123');
        self::assertSame(120.0, $data->landingPages($id, $range)[0]['revenue'], 'Imports preserve earlier history.');
        self::assertSame(120.0, $data->landingPages($id, $range, urls: [$url])[0]['revenue']);
        self::assertTrue($data->metadata($id, $range)['thresholded'] ?? false);
        self::assertFalse($data->metadata($id, $october)['thresholded'] ?? true);
        self::assertSame([], $data->landingPages($id, $range, urls: ['https://ga4.test/unknown']));
        self::assertSame(56, $data->coveredDays($id, new DateRange('2026-09-01', '2026-10-28')), 'Gaps remain missing, not zero.');
        $data->saveReport($id, $october, new Ga4Report([], 'USD', 'Europe/Berlin', false, false), '123');
        self::assertSame([], $data->landingPages($id, $range), 'Currency changes cannot mix revenue.');
        self::assertSame(0, $data->coveredDays($id, $range));
        self::assertSame(0, $data->totals($id, $range)['sessions']);
        self::assertSame([], $data->daily($id, $range));
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM ga4_landing_pages WHERE project_id = ?', [$id]), 'Earlier currency data remains stored.');
        $data->link($id, '456');
        self::assertSame([], $data->landingPages($id, $range));
        self::assertNull($data->metadata($id));
        self::assertSame(0, $data->coveredDays($id, $october));
        $data->saveReport($id, $range, $report, '123');
        self::assertNull($data->metadata($id), 'An old job cannot overwrite the newly linked property.');
        $data->deleteCredentials($owner['workspace']->id);
        self::assertFalse($data->hasCredentials($owner['workspace']->id));
    }
}
