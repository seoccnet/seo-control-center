<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Indexing;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Indexing\IndexingHistory;

final class IndexingHistoryTest extends TestCase
{
    private const string URL = 'https://s.test/guide';

    public function testARecordedRequestIsNeverIndexedUntilAGoogleCheckShowsIt(): void
    {
        $request = self::request('2026-10-20', '2026-10-20 09:00:00');
        $none = self::states(IndexingHistory::build(self::URL, self::crawl(200), null, [], [$request], 'UTC'));
        self::assertSame('yes', $none['request']['value']);
        self::assertSame('unknown', $none['indexed']['value'], 'A request alone says nothing about the index.');
        self::assertStringContainsString('a recorded request does not show that Google indexed the page', $none['indexed']['evidence']);
        self::assertSame('unknown', $none['recrawl']['value']);

        $before = self::check('2026-10-18 08:00:00', 'NEUTRAL', '2026-10-10 05:00:00');
        $old = self::states(IndexingHistory::build(self::URL, self::crawl(200), null, [$before], [$request], 'UTC'));
        self::assertSame('no', $old['indexed']['value']);
        self::assertStringContainsString('older than the latest recorded request', $old['indexed']['evidence']);
        self::assertSame('unknown', $old['recrawl']['value'], 'No check since the request.');

        $notYet = self::check('2026-10-22 08:00:00', 'NEUTRAL', '2026-10-10 05:00:00');
        self::assertSame('no', self::states(IndexingHistory::build(self::URL, self::crawl(200), null, [$notYet, $before], [$request], 'UTC'))['recrawl']['value']);

        $after = self::check('2026-10-23 08:00:00', 'PASS', '2026-10-21 14:00:00');
        $done = self::states(IndexingHistory::build(self::URL, self::crawl(200), null, [$after, $notYet, $before], [$request], 'UTC'));
        self::assertSame('yes', $done['recrawl']['value']);
        self::assertSame('yes', $done['indexed']['value']);
        self::assertSame('2026-10-23 08:00:00', $done['indexed']['at']);
    }

    public function testACrawlOnTheRequestDayIsUncertainUnlessItFollowsTheRecording(): void
    {
        $request = self::request('2026-10-20', '2026-10-20 18:00:00');
        $sameDay = self::check('2026-10-21 08:00:00', 'PASS', '2026-10-20 10:00:00');
        self::assertSame('unknown', self::states(IndexingHistory::build(self::URL, null, null, [$sameDay], [$request], 'UTC'))['recrawl']['value']);
        $afterRecording = self::check('2026-10-21 08:00:00', 'PASS', '2026-10-20 19:00:00');
        self::assertSame('yes', self::states(IndexingHistory::build(self::URL, null, null, [$afterRecording], [$request], 'UTC'))['recrawl']['value']);
        // 23:30 UTC is already the next day in Berlin, after a request made on the 20th.
        $nextLocalDay = self::check('2026-10-21 08:00:00', 'PASS', '2026-10-20 23:30:00');
        $early = self::request('2026-10-20', '2026-10-21 07:00:00');
        self::assertSame('yes', self::states(IndexingHistory::build(self::URL, null, null, [$nextLocalDay], [$early], 'Europe/Berlin'))['recrawl']['value']);
        self::assertSame('unknown', self::states(IndexingHistory::build(self::URL, null, null, [$nextLocalDay], [$early], 'UTC'))['recrawl']['value']);
    }

    public function testPublicAndIndexableComeFromTheSiteAuditAndRobots(): void
    {
        self::assertSame(['unknown', 'unknown'], self::pair(IndexingHistory::build(self::URL, null, null, [], [], 'UTC')));
        self::assertSame(['yes', 'yes'], self::pair(IndexingHistory::build(self::URL, self::crawl(200), null, [], [], 'UTC')));
        self::assertSame(['yes', 'no'], self::pair(IndexingHistory::build(self::URL, self::crawl(200, noindex: true), null, [], [], 'UTC')));
        self::assertSame(['yes', 'no'], self::pair(IndexingHistory::build(self::URL, self::crawl(200, canonical: 'https://s.test/other'), null, [], [], 'UTC')));
        self::assertSame(['yes', 'yes'], self::pair(IndexingHistory::build(self::URL, self::crawl(200, canonical: self::URL), null, [], [], 'UTC')));
        self::assertSame(['no', 'no'], self::pair(IndexingHistory::build(self::URL, self::crawl(301), null, [], [], 'UTC')));
        self::assertSame(['unknown', 'unknown'], self::pair(IndexingHistory::build(self::URL, self::crawl(null), null, [], [], 'UTC')));
        $blocked = self::check('2026-10-21 08:00:00', 'NEUTRAL', null);
        $blocked['robots'] = 'DISALLOWED';
        self::assertSame(['yes', 'no'], self::pair(IndexingHistory::build(self::URL, self::crawl(200), null, [$blocked], [], 'UTC')));

        $unknown = self::check('2026-10-21 08:00:00', 'NEUTRAL', null);
        $unknown['coverage'] = 'URL is unknown to Google';
        $states = self::states(IndexingHistory::build(self::URL, null, null, [$unknown], [], 'UTC'));
        self::assertSame('no', $states['known']['value']);
        self::assertSame('no', $states['indexed']['value']);
        self::assertSame('not_applicable', $states['recrawl']['value']);
        self::assertSame('no', $states['request']['value']);
    }

    public function testTheTimelineMergesSourcesNewestFirstAndLabelsIndexNow(): void
    {
        $older = self::check('2026-10-10 08:00:00', 'NEUTRAL', '2026-10-01 05:00:00');
        $newer = self::check('2026-10-23 08:00:00', 'PASS', '2026-10-21 14:00:00');
        $newer['google_canonical'] = 'https://s.test/guide/';
        $history = IndexingHistory::build(self::URL, self::crawl(200), ['first_seen_at' => '2026-09-30 10:00:00', 'submitted_at' => '2026-09-30 10:00:00'], [$newer, $older], [self::request('2026-10-20', '2026-10-20 09:00:00')], 'UTC');
        self::assertSame(['check', 'site_audit', 'google_crawl', 'request', 'check', 'google_crawl', 'indexnow', 'sitemap'], array_column($history->events, 'kind'));
        self::assertSame('Google check: changed from not indexed to indexed', $history->events[0]['title']);
        self::assertStringContainsString('Google-selected canonical changed to https://s.test/guide/', $history->events[0]['detail']);
        self::assertStringContainsString('not Google', $history->events[6]['detail']);
        self::assertSame('SEOcc first read it in your sitemap', $history->events[7]['title']);
        self::assertStringContainsString('not a publication date', $history->events[7]['detail'], 'The day SEOcc first read the sitemap is not when the page was added.');
    }

    /** @return array<string, array{key: string, label: string, value: string, evidence: string, at: ?string}> */
    private static function states(IndexingHistory $history): array
    {
        return array_column($history->states, null, 'key');
    }

    /** @return array{string, string} */
    private static function pair(IndexingHistory $history): array
    {
        $states = self::states($history);

        return [$states['public']['value'], $states['indexable']['value']];
    }

    /** @return array{status_code: ?int, noindex: bool, canonical: ?string, redirect_to: ?string, error: ?string, crawled_at: ?string} */
    private static function crawl(?int $code, bool $noindex = false, ?string $canonical = null): array
    {
        return ['status_code' => $code, 'noindex' => $noindex, 'canonical' => $canonical, 'redirect_to' => $code === 301 ? 'https://s.test/new' : null, 'error' => $code === null ? 'Timed out' : null, 'crawled_at' => '2026-10-22 12:00:00'];
    }

    /** @return array{checked_at: string, verdict: string, coverage: string, indexing_state: string, robots: string, last_crawl: ?string, google_canonical: string, user_canonical: string} */
    private static function check(string $at, string $verdict, ?string $crawl): array
    {
        return ['checked_at' => $at, 'verdict' => $verdict, 'coverage' => $verdict === 'PASS' ? 'Submitted and indexed' : 'Crawled - currently not indexed', 'indexing_state' => 'INDEXING_ALLOWED', 'robots' => 'ALLOWED', 'last_crawl' => $crawl, 'google_canonical' => self::URL, 'user_canonical' => self::URL];
    }

    /** @return array{public_id: string, channel: string, requested_on: string, evidence: string, note: string, created_at: string} */
    private static function request(string $day, string $recorded): array
    {
        return ['public_id' => '0192f0a0-0000-7000-8000-0000000000f1', 'channel' => 'search_console', 'requested_on' => $day, 'evidence' => 'Indexing requested', 'note' => '', 'created_at' => $recorded];
    }
}
