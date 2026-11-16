<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\Rankings\RankingImportStore;

/** Imported ranking history (1.3.0) on the real engine. */
final class RankingImportPersistenceTest extends FullStackTestCase
{
    public function testImportsKeepChecksAndChecksReplaceImports(): void
    {
        $owner = $this->createUser('import@rank.test', 'Import');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('import@rank.test'), 'Import', 'import.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $market = KeywordLocale::default();
        $this->app->make(KeywordRepository::class)->add($projectId, ['rain barrel', 'hose'], $market, $owner['user']->id);
        $store = $this->app->make(RankingImportStore::class);
        $rankings = $this->app->make(RankingRepository::class);

        $ids = $store->keywordIds($projectId, ['rain barrel', 'hose', 'unknown'], $market);
        $found = array_keys($ids);
        sort($found);
        self::assertSame(['hose', 'rain barrel'], $found, 'Unknown keywords have no ID.');
        self::assertSame([], $store->keywordIds($projectId, ['hose'], new KeywordLocale(2276, 'de')), 'Another market is another keyword.');

        $rankings->storeSnapshot(new RankSnapshot($ids['hose'], $projectId, SerpDevice::Desktop, '2026-10-08', 2, 2, null, [], []));
        self::assertSame(2, $store->insert($projectId, [
            ['keywordId' => $ids['hose'], 'device' => 'desktop', 'date' => '2026-10-08', 'position' => 9, 'url' => null],
            ['keywordId' => $ids['hose'], 'device' => 'desktop', 'date' => '2026-10-01', 'position' => 5, 'url' => 'https://import.test/hose'],
            ['keywordId' => $ids['rain barrel'], 'device' => 'mobile', 'date' => '2026-10-01', 'position' => null, 'url' => null],
        ]));
        $rows = $this->db->fetchAll('SELECT checked_on, device, position, source FROM ranking_snapshots WHERE project_id = ? ORDER BY checked_on, device', [$projectId]);
        self::assertSame([
            ['checked_on' => '2026-10-01', 'device' => 'desktop', 'position' => 5, 'source' => 'import'],
            ['checked_on' => '2026-10-01', 'device' => 'mobile', 'position' => null, 'source' => 'import'],
            ['checked_on' => '2026-10-08', 'device' => 'desktop', 'position' => 2, 'source' => null],
        ], array_map(static fn(array $r): array => ['checked_on' => (string) $r['checked_on'], 'device' => (string) $r['device'], 'position' => $r['position'] !== null ? (int) $r['position'] : null, 'source' => $r['source'] !== null ? (string) $r['source'] : null], $rows));

        // A real check of an imported day replaces the import.
        $rankings->storeSnapshot(new RankSnapshot($ids['hose'], $projectId, SerpDevice::Desktop, '2026-10-01', 4, 4, null, [], []));
        self::assertNull($this->db->fetchValue("SELECT source FROM ranking_snapshots WHERE project_keyword_id = ? AND checked_on = '2026-10-01'", [$ids['hose']]));
        $rankings->aggregate($projectId, $owner['workspace']->id, SerpDevice::Desktop, '2026-10-01');
        self::assertNotNull($rankings->dayOnOrBefore($projectId, SerpDevice::Desktop, '2026-10-01'));
    }
}
