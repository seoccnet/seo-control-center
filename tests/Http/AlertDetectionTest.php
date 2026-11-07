<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\Alerts\AlertDetector;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The alert rules on the in-memory data of a project: ranking drops out of
 * the top 10, new site audit errors and lost strong backlinks.
 */
final class AlertDetectionTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->s->container->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-05 07:00:00 UTC')));
    }

    public function testNothingWithoutData(): void
    {
        self::assertSame([], $this->detect());
    }

    public function testKeywordsThatLeftTheTop10(): void
    {
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        $project = $this->s->acmeProject->id;
        $keywords->add($project, ['red shoes', 'blue shoes', 'green shoes'], KeywordLocale::default(), $this->s->owner->id);
        $rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::DAILY), $this->s->owner->id);
        $ids = array_column($keywords->keywords[$project], 'id');
        // red 3 → 14 (drop), blue 8 → gone (drop), green 20 → 5 (gain).
        foreach (['2026-10-03' => [3, 8, 20], '2026-10-04' => [14, null, 5]] as $date => $positions) {
            foreach ($positions as $i => $position) {
                $rankings->storeSnapshot(new RankSnapshot($ids[$i], $project, SerpDevice::Desktop, $date, $position, $position, null, [], []));
            }
            $rankings->aggregate($project, $this->s->acme->id, SerpDevice::Desktop, $date);
        }

        $events = $this->detect();
        self::assertCount(1, $events);
        self::assertSame(AlertType::RankingDrop, $events[0]['type']);
        self::assertSame('Acme Shop: 2 keywords dropped out of the top 10', $events[0]['title']);
        self::assertSame(['Rank check of 2026-10-04 compared with 2026-10-03:', '“red shoes”: from #3 to #14', '“blue shoes”: from #8 to not in the top 100'], $events[0]['lines']);
        self::assertSame('rank:' . $project . ':2026-10-04', $events[0]['fingerprint']);
    }

    public function testNewSiteAuditErrors(): void
    {
        $audits = $this->s->container->get(InMemorySiteAudit::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        $project = $this->s->acmeProject;
        $first = $audits->create($project->id, $project->workspaceId, 'https://shop.acme.test/', 100, 'manual', null);
        $audits->finish($first->id, 'completed', 90, ['error' => 1, 'warning' => 0, 'notice' => 0], [['pageId' => 1, 'type' => IssueType::BrokenPage, 'detail' => 'HTTP 404']], null);
        self::assertSame(AlertType::AuditErrors, $this->detect()[0]['type'], 'The first audit with errors alerts.');

        $second = $audits->create($project->id, $project->workspaceId, 'https://shop.acme.test/', 100, 'manual', null);
        $audits->finish($second->id, 'completed', 70, ['error' => 3, 'warning' => 0, 'notice' => 0], [
            ['pageId' => 1, 'type' => IssueType::BrokenPage, 'detail' => 'HTTP 404'],
            ['pageId' => 2, 'type' => IssueType::BrokenPage, 'detail' => 'HTTP 404'],
            ['pageId' => 3, 'type' => IssueType::ServerError, 'detail' => 'HTTP 500'],
        ], null);
        $events = $this->detect();
        self::assertSame('Acme Shop: the site audit found 3 errors', $events[0]['title']);
        self::assertSame('audit:' . $second->publicId, $events[0]['fingerprint']);
        self::assertContains('Health score: 70.', $events[0]['lines']);

        $third = $audits->create($project->id, $project->workspaceId, 'https://shop.acme.test/', 100, 'manual', null);
        $audits->finish($third->id, 'completed', 75, ['error' => 2, 'warning' => 0, 'notice' => 0], [], null);
        self::assertSame([], $this->detect(), 'Fewer errors than the audit before: no alert.');
    }

    public function testStrongLostBacklinks(): void
    {
        $research = $this->s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        $project = $this->s->acmeProject;
        $research->queue($project->id, $project->workspaceId, 'bl_lost', '', [], null);
        $research->complete($project->id, 'bl_lost', '', [
            ['d' => 'weak.test', 'r' => 5, 'l' => '2026-10-01'],
            ['d' => 'news.test', 'r' => 70, 'l' => '2026-10-02'],
            ['d' => 'blog.test', 'r' => 40],
        ]);

        $events = $this->detect();
        self::assertCount(1, $events);
        self::assertSame(AlertType::LostLinks, $events[0]['type']);
        self::assertSame('Acme Shop: 2 strong backlinks lost', $events[0]['title']);
        self::assertSame('news.test (domain rank 70), lost 2026-10-02', $events[0]['lines'][0]);
        self::assertSame('blog.test (domain rank 40), lost recently', $events[0]['lines'][1]);
    }

    /**
     * @return list<array{type: AlertType, fingerprint: string, title: string, lines: list<string>, url: string}>
     */
    private function detect(): array
    {
        $detector = $this->s->container->get(AlertDetector::class);
        self::assertInstanceOf(AlertDetector::class, $detector);

        return $detector->project($this->s->acmeProject->id, $this->s->acmeProject->publicId, 'Acme Shop');
    }
}
