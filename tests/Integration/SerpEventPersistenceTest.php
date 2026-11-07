<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SerpHistory\SerpEventRepository;
use SeoControlCenter\Modules\SerpHistory\SerpHistoryService;

/**
 * Results-page change history against MySQL/MariaDB (ADR-039, E1):
 * comparable checks, idempotent events, filters and summaries.
 */
final class SerpEventPersistenceTest extends FullStackTestCase
{
    public function testComparableChecksEventsAndSummaries(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES ('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f91', ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$ws]);
        $project = $this->db->lastInsertId();
        $this->app->make(KeywordRepository::class)->add($project, ['red shoes', 'blue shoes'], KeywordLocale::default(), $acme['user']->id);
        $ids = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM project_keywords WHERE project_id = ? ORDER BY id', [$project]), 'id'));
        $rankings = $this->app->make(RankingRepository::class);
        $rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::WEEKLY), $acme['user']->id);
        $this->app->make(CompetitorRepository::class)->add($project, 'rival.test', 'manual', $acme['user']->id);
        $day = static fn(int $ago): string => (new \DateTimeImmutable('-' . $ago . ' days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $rankings->storeSnapshot(self::snap($project, $ids[0], $day(40), 3, [], []));
        $rankings->storeSnapshot(self::snap($project, $ids[0], $day(7), 4, ['images'], [['position' => 1, 'domain' => 'a.test', 'url' => 'https://a.test/']]));
        $rankings->storeSnapshot(self::snap($project, $ids[0], $day(0), 15, ['images', 'ai_overview'], [['position' => 1, 'domain' => 'www.rival.test', 'url' => 'https://www.rival.test/shoes']]));
        $rankings->storeSnapshot(self::snap($project, $ids[1], $day(0), 9, [], []));
        $rankings->storeSnapshot(new RankSnapshot($ids[0], $project, SerpDevice::Mobile, $day(0), 2, 2, null, [], []));

        $pairs = $rankings->comparableChecks($project, SerpDevice::Desktop, $day(0), 14);
        self::assertSame(['red shoes', 'blue shoes'], array_column($pairs, 'keyword'));
        self::assertSame($day(7), $pairs[0]['previous']?->date, 'The latest earlier check within 14 days.');
        self::assertSame(['images'], $pairs[0]['previous']->features);
        self::assertNull($pairs[1]['previous'], 'No earlier check for blue shoes.');
        self::assertNull($rankings->comparableChecks($project, SerpDevice::Desktop, $day(7), 14)[0]['previous'], 'The check 33 days earlier is too old to compare.');
        self::assertSame([$day(40), $day(7), $day(0)], $rankings->checkDays($project, SerpDevice::Desktop, $day(60)));
        self::assertContains(['project_id' => $project, 'workspace_id' => $ws], $rankings->trackedProjects());

        $history = $this->app->make(SerpHistoryService::class);
        self::assertSame(5, $history->catchUp(), 'Feature added, you left the top 10, rival entered, rival URL new, page type home page → other.');
        self::assertSame(0, $history->catchUp());
        $events = $this->app->make(SerpEventRepository::class);
        self::assertSame(['competitor_entered' => 1, 'feature_added' => 1, 'format_shift' => 1, 'new_url' => 1, 'own_left' => 1], self::sorted($events->counts($project, 'desktop', $day(30))));
        self::assertSame(['rival.test' => ['new_urls' => 1, 'entered' => 1, 'left' => 0]], $events->competitors($project, 'desktop', $day(30)));
        $listed = $events->list($project, 'desktop', ['kinds' => ['feature_added'], 'q' => 'red'], 10, 0);
        self::assertSame([['red shoes', 'ai_overview', $day(7)]], array_map(static fn($e): array => [$e->keyword, $e->subject, $e->previousOn], $listed));
        self::assertSame(2, $events->count($project, 'desktop', ['subject' => 'rival.test/shoes']), 'The new URL and the competitor entry that names it.');
        self::assertSame(0, $events->count($project, 'mobile', []), 'Devices are never mixed.');
        self::assertTrue($events->hasAny($project));
        self::assertSame(5, $events->prune($day(-1)));
    }

    /**
     * @param list<string> $features
     * @param list<array{position: int, domain: string, url: string}> $top
     */
    private static function snap(int $project, int $id, string $day, ?int $position, array $features, array $top): RankSnapshot
    {
        return new RankSnapshot($id, $project, SerpDevice::Desktop, $day, $position, $position, null, $features, $top);
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
