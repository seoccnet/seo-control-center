<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck;
use SeoControlCenter\Domain\Analytics\Ga4AiReferralReport;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository;
use SeoControlCenter\Modules\Analytics\AiReferralRepository;

/**
 * AI visibility readiness tables (0.43.0): the latest crawler check per
 * project and the GA4 AI referral rows.
 */
final class AiReadinessPersistenceTest extends FullStackTestCase
{
    public function testCrawlerChecksAreReplacedPerProjectAndDueProjectsSkipFreshChecks(): void
    {
        $owner = $this->createUser('crawlers@acme.test', 'Crawlers');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('crawlers@acme.test'), 'Crawlers', 'crawlers.test');
        $id = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $repo = $this->app->make(AiCrawlerRepository::class);

        self::assertNull($repo->latest($id));
        self::assertContains($id, array_column($repo->dueProjects('2026-10-18 00:00:00', 50), 'project_id'), 'never checked');
        $agents = [['token' => 'OAI-SearchBot', 'state' => 'blocked', 'named' => true, 'blocked' => []], ['token' => 'Applebot', 'state' => 'partial', 'named' => false, 'blocked' => ['/private/a?b=1']]];
        $repo->save($owner['workspace']->id, new AiCrawlerCheck($id, 'https://www.crawlers.test/robots.txt', 'ok', 200, $agents, 'missing', 404, null, '2026-10-20 08:00:00'));
        $repo->save($owner['workspace']->id, new AiCrawlerCheck($id, 'https://crawlers.test/robots.txt', 'ok', 200, $agents, 'found', 200, 321, '2026-10-25 08:00:00'));

        $check = $repo->latest($id);
        self::assertNotNull($check);
        self::assertSame(['https://crawlers.test/robots.txt', 'found', 321, '2026-10-25 08:00:00'], [$check->robotsUrl, $check->llmsState, $check->llmsBytes, $check->checkedAt]);
        self::assertSame($agents, $check->agents);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM ai_crawler_checks WHERE project_id = ?', [$id]));
        self::assertNotContains($id, array_column($repo->dueProjects('2026-10-18 00:00:00', 50), 'project_id'), 'checked this week');
        self::assertContains($id, array_column($repo->dueProjects('2026-10-26 00:00:00', 50), 'project_id'));
        self::assertNull($repo->latest($id + 100));
    }

    public function testAiReferralImportsReplaceTheirWindowAndKeepOlderDays(): void
    {
        $owner = $this->createUser('referrals@acme.test', 'Referrals');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('referrals@acme.test'), 'Referrals', 'referrals.test');
        $id = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $repo = $this->app->make(AiReferralRepository::class);
        self::assertNull($repo->lastImport($id));

        $repo->recordFailure($id, 'GA4 quota exhausted.');
        self::assertSame(['from' => null, 'error' => 'GA4 quota exhausted.'], array_intersect_key((array) $repo->lastImport($id), ['from' => 1, 'error' => 1]));

        $september = new DateRange('2026-09-01', '2026-09-30');
        $repo->save($id, $september, new Ga4AiReferralReport([
            ['date' => '2026-09-02', 'source' => 'chatgpt.com', 'sessions' => 3, 'key_events' => 1.0, 'revenue' => 20.5],
            ['date' => '2026-09-03', 'source' => 'chatgpt.com', 'sessions' => 2, 'key_events' => 0.0, 'revenue' => 0.0],
            ['date' => '2026-09-03', 'source' => 'chatgpt.com', 'sessions' => 1, 'key_events' => 0.0, 'revenue' => 0.0],
            ['date' => '2026-09-29', 'source' => 'perplexity', 'sessions' => 4, 'key_events' => 2.0, 'revenue' => 0.0],
        ], 'EUR', false));
        self::assertSame([
            ['source' => 'chatgpt.com', 'sessions' => 6, 'key_events' => 1.0, 'revenue' => 20.5],
            ['source' => 'perplexity', 'sessions' => 4, 'key_events' => 2.0, 'revenue' => 0.0],
        ], $repo->bySource($id, $september), 'repeated sources of a day are merged');

        $repo->save($id, new DateRange('2026-09-15', '2026-10-14'), new Ga4AiReferralReport([['date' => '2026-10-01', 'source' => 'claude.ai', 'sessions' => 1, 'key_events' => 0.0, 'revenue' => 0.0]], 'EUR', true));
        self::assertSame(['chatgpt.com'], array_column($repo->bySource($id, $september), 'source'), 'the new window replaced 2026-09-29 and kept older days');
        self::assertSame(['claude.ai'], array_column($repo->bySource($id, new DateRange('2026-10-01', '2026-10-14')), 'source'));
        $import = $repo->lastImport($id);
        self::assertNotNull($import);
        self::assertSame(['2026-09-15', '2026-10-14', 'EUR', true, null], [$import['from'], $import['to'], $import['currency'], $import['limited'], $import['error']], 'a successful import clears the failure');
        self::assertSame([], $repo->bySource($id + 100, $september));
    }
}
