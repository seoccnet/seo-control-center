<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Opportunities\ConfidenceReader;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;

final class ConfidenceReaderTest extends FullStackTestCase
{
    public function testDailySampleDeviationBoundariesDevicesAndTenantIsolation(): void
    {
        $owner = $this->createUser('confidence@acme.test', 'Confidence');
        self::assertNotNull($owner['workspace']);
        $project = $this->project($owner['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fc1');
        $foreign = $this->project($owner['workspace']->id, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fc2');
        $store = $this->app->make(SearchPerformanceStore::class);
        $keywords = $this->app->make(KeywordRepository::class);
        $keywords->add($project, ['topic'], KeywordLocale::default(), $owner['user']->id);
        $id = (int) $this->db->fetchValue('SELECT id FROM project_keywords WHERE project_id = ?', [$project]);
        $rankings = $this->app->make(RankingRepository::class);
        foreach ([4, 6, 8] as $i => $position) {
            $day = '2026-10-0' . ($i + 4);
            $store->replaceDay($project, $day, ['query' => [new DimensionRow('topic', new Metrics(10, 100, $position))], 'page' => [new DimensionRow('https://acme.test/topic', new Metrics(10, 100, $position))], 'device' => [], 'country' => []]);
            $rankings->storeSnapshot(new RankSnapshot($id, $project, SerpDevice::Desktop, $day, $position, $position, null, [], []));
            $rankings->storeSnapshot(new RankSnapshot($id, $project, SerpDevice::Mobile, $day, 50, 50, null, [], []));
        }
        $store->replaceDay($foreign, '2026-10-06', ['query' => [new DimensionRow('foreign-only', new Metrics(999, 1000, 1))], 'page' => [], 'device' => [], 'country' => []]);
        $reader = $this->app->make(ConfidenceReader::class);
        $rows = $reader->observations($project, SerpDevice::Desktop, new DateRange('2026-10-04', '2026-10-06'));
        self::assertCount(3, $rows);
        self::assertSame(300, $rows[0]->impressions);
        self::assertSame(30, $rows[0]->clicks);
        foreach ($rows as $row) {
            self::assertSame(3, $row->days);
            self::assertSame(6.0, $row->position);
            self::assertEqualsWithDelta(2.0, $row->deviation ?? -1, 0.0001);
            self::assertSame('2026-10-06', $row->date);
            self::assertNotSame('foreign-only', $row->key);
        }
        $single = $reader->observations($project, SerpDevice::Desktop, new DateRange('2026-10-06', '2026-10-06'));
        self::assertNull($single[0]->deviation, 'One observation is not evidence of stable position.');
        $mobile = $reader->observations($project, SerpDevice::Mobile, new DateRange('2026-10-04', '2026-10-06'));
        self::assertSame(50.0, $mobile[2]->position);
        self::assertCount(1, $reader->observations($foreign, SerpDevice::Desktop, new DateRange('2026-10-04', '2026-10-06')));
    }

    private function project(int $workspace, string $publicId): int
    {
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$publicId, $workspace]);
        return $this->db->lastInsertId();
    }
}
