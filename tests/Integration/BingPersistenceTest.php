<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Crypto\SecretRotator;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Bing\BingAccountRepository;
use SeoControlCenter\Modules\Bing\BingCrawlDay;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Bing\BingLinkCount;
use SeoControlCenter\Modules\Bing\BingSiteLink;
use SeoControlCenter\Modules\Bing\BingWeeklyRow;

/**
 * Bing API keys, project links and imported data against MySQL/MariaDB.
 */
final class BingPersistenceTest extends FullStackTestCase
{
    public function testApiKeyIsEncryptedWorkspaceBoundAndRotatable(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $repo = $this->app->make(BingAccountRepository::class);

        $repo->save($acme['workspace']->id, 'bingSecretApiKey0123456789', ['https://acme.test/'], $acme['user']->id);

        $raw = (string) $this->db->fetchValue('SELECT api_key FROM bing_profiles WHERE workspace_id = ?', [$acme['workspace']->id]);
        self::assertStringNotContainsString('bingSecretApiKey', $raw);
        self::assertSame('bingSecretApiKey0123456789', $repo->apiKey($acme['workspace']->id));
        self::assertSame(['https://acme.test/'], $repo->find($acme['workspace']->id)?->sites);
        self::assertNull($repo->apiKey($rival['workspace']->id));

        $repo->save($rival['workspace']->id, 'rivalKey0123456789abcdef', [], $rival['user']->id);
        $this->db->execute('UPDATE bing_profiles SET api_key = ? WHERE workspace_id = ?', [$raw, $rival['workspace']->id]);
        try {
            $repo->apiKey($rival['workspace']->id);
            self::fail('A key copied to another workspace must not decrypt.');
        } catch (\Throwable) {
        }

        $repo->setError($acme['workspace']->id, 'rejected');
        self::assertFalse($repo->find($acme['workspace']->id)?->isActive());
        $repo->updateSites($acme['workspace']->id, ['https://acme.test/', 'https://blog.acme.test/']);
        self::assertTrue($repo->find($acme['workspace']->id)?->isActive());
        $account = $repo->find($acme['workspace']->id);
        self::assertNotNull($account);
        self::assertCount(2, $account->sites);
        self::assertSame(2, $this->app->make(SecretRotator::class)->countByKey()[1] ?? 0, 'Both keys are registered for key rotation.');

        $repo->delete($acme['workspace']->id);
        self::assertNull($repo->find($acme['workspace']->id));
    }

    public function testImportedDataIsReplacedAggregatedAndProjectScoped(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $shop = $this->project($ws, '0192f0a0-0000-7000-8000-000000000001');
        $blog = $this->project($ws, '0192f0a0-0000-7000-8000-000000000002');
        $repo = $this->app->make(BingDataRepository::class);

        $repo->saveLink($shop, $ws, 'https://acme.test/', $acme['user']->id);
        $repo->saveLink($blog, $ws, 'https://blog.acme.test/', $acme['user']->id);
        self::assertSame(BingSiteLink::PENDING, $repo->link($shop)?->status);
        $repo->replaceData($shop, [$this->day('2026-09-20', 5, 100), $this->day('2026-09-27', 7, 140)], [
            $this->week('2026-09-19', PerformanceDimension::Query, 'red shoes', 3, 50, 4.0),
            $this->week('2026-09-26', PerformanceDimension::Query, 'red shoes', 5, 150, 2.0),
            $this->week('2026-09-26', PerformanceDimension::Query, 'blue shoes', 1, 40, 9.0),
            $this->week('2026-09-26', PerformanceDimension::Page, 'https://acme.test/shoes', 6, 190, 3.0),
        ]);
        $repo->replaceData($blog, [$this->day('2026-09-27', 1, 10)], []);
        $repo->setStatus($shop, BingSiteLink::OK);

        $range = new DateRange('2026-09-01', '2026-09-30');
        self::assertSame(['first' => '2026-09-20', 'last' => '2026-09-27'], $repo->dataSpan($shop));
        self::assertSame(12, $repo->totals($shop, $range)->clicks);
        self::assertCount(2, $repo->daily($shop, $range));
        $top = $repo->top($shop, PerformanceDimension::Query, $range, 10);
        self::assertSame('red shoes', $top[0]->key);
        self::assertSame(8, $top[0]->metrics->clicks);
        self::assertEqualsWithDelta(2.5, $top[0]->metrics->position, 0.01, 'Weeks are weighted by impressions.');
        self::assertSame(2, $repo->weeks($shop, $range));
        self::assertSame(1, $repo->weeks($shop, new DateRange('2026-09-20', '2026-09-30')));
        self::assertSame(1, $repo->totals($blog, $range)->clicks, 'Data is scoped by project.');
        self::assertNotNull($repo->link($shop)?->lastSuccessAt);

        // A new import replaces everything; relinking another site discards the old site's data.
        $repo->replaceData($shop, [$this->day('2026-09-28', 2, 20)], []);
        self::assertSame(['first' => '2026-09-28', 'last' => '2026-09-28'], $repo->dataSpan($shop));
        $repo->saveLink($shop, $ws, 'https://acme.test/', $acme['user']->id);
        self::assertNotNull($repo->dataSpan($shop), 'Relinking the same site keeps the data.');
        $repo->saveLink($shop, $ws, 'https://www.acme.test/', $acme['user']->id);
        self::assertNull($repo->dataSpan($shop));
        self::assertNull($repo->link($shop)?->lastSuccessAt);

        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$blog]);
        self::assertSame([['projectId' => $shop, 'workspaceId' => $ws]], $repo->linkedProjects(), 'Deleted projects are not synced.');
        $repo->unlink($shop);
        self::assertNull($repo->link($shop));
    }

    public function testCrawlStatsAndLinkCountsAreReplacedProjectScopedAndClearedOnRelink(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $shop = $this->project($ws, '0192f0a0-0000-7000-8000-000000000011');
        $blog = $this->project($ws, '0192f0a0-0000-7000-8000-000000000012');
        $repo = $this->app->make(BingDataRepository::class);
        $repo->saveLink($shop, $ws, 'https://acme.test/', $acme['user']->id);
        $day = static fn(string $date, int $inIndex): BingCrawlDay => new BingCrawlDay($date, 40, 2, $inIndex, 7, 35, 3, 2, 0, 1, 0);
        $longUrl = 'https://acme.test/' . str_repeat('ü', 1500);

        $repo->replaceCrawlData($shop, [$day('2026-09-20', 100), $day('2026-09-21', 104)], [new BingLinkCount('https://acme.test/a', 3), new BingLinkCount($longUrl, 9), new BingLinkCount('https://acme.test/a', 5)]);
        $repo->replaceCrawlData($blog, [$day('2026-09-21', 7)], [new BingLinkCount('https://blog.acme.test/', 1)]);
        $repo->replaceCrawlData($shop, [$day('2026-09-21', 105), $day('2026-09-22', 110)], [new BingLinkCount('https://acme.test/a', 5), new BingLinkCount($longUrl, 9)]);

        $stats = $repo->crawlStats($shop, DateRange::endingOn('2026-09-22', 28));
        self::assertSame(['2026-09-21', '2026-09-22'], array_map(static fn(BingCrawlDay $d): string => $d->date, $stats), 'A new import replaces the old rows.');
        self::assertEquals($day('2026-09-22', 110), $stats[1]);
        self::assertSame([$longUrl, 'https://acme.test/a'], array_map(static fn(BingLinkCount $l): string => $l->url, $repo->linkCounts($shop, 10)));
        self::assertSame(['pages' => 2, 'links' => 14], $repo->linkTotals($shop));
        self::assertSame(['pages' => 1, 'links' => 1], $repo->linkTotals($blog), 'Other projects are untouched.');

        $repo->replaceData($shop, [$this->day('2026-09-22', 1, 10)], []);
        self::assertCount(2, $repo->crawlStats($shop, DateRange::endingOn('2026-09-22', 28)), 'Performance imports keep crawl data.');
        $repo->saveLink($shop, $ws, 'https://acme.test/', $acme['user']->id);
        self::assertSame(2, $repo->linkTotals($shop)['pages'], 'Relinking the same site keeps it.');
        $repo->saveLink($shop, $ws, 'https://shop.acme.test/', $acme['user']->id);
        self::assertSame([], $repo->crawlStats($shop, DateRange::endingOn('2026-09-22', 28)), 'Another site discards it.');
        self::assertSame(['pages' => 0, 'links' => 0], $repo->linkTotals($shop));
    }

    private function project(int $workspaceId, string $publicId): int
    {
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES (?, ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$publicId, $workspaceId],
        );

        return $this->db->lastInsertId();
    }

    private function day(string $date, int $clicks, int $impressions): DailyMetrics
    {
        return new DailyMetrics($date, new Metrics($clicks, $impressions, 0.0));
    }

    private function week(string $weekEnd, PerformanceDimension $dimension, string $key, int $clicks, int $impressions, float $position): BingWeeklyRow
    {
        return new BingWeeklyRow($weekEnd, $dimension, new DimensionRow($key, new Metrics($clicks, $impressions, $position)));
    }
}
