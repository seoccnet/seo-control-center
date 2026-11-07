<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTracking;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaJobs;
use SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService;
use SeoControlCenter\Modules\ArticleIdeas\ArticleTrackingService;
use SeoControlCenter\Tests\Support\Fakes\FakeResearchProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleTracking;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Scenario;

/** Research → Article ideas → Published articles (ADR-041, G3). */
final class ArticleTrackingHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        // The measurements read the 28 days before today: pin today so the fixture's 2026-09-28 week is inside and its 2026-09-14 week outside.
        $this->s->container->instance(\SeoControlCenter\Core\Support\Clock::class, new \SeoControlCenter\Core\Support\FrozenClock(new \DateTimeImmutable('2026-10-20 08:00:00', new \DateTimeZone('UTC'))));
    }

    public function testAPublishedArticleIsMeasuredAgainstItsPrediction(): void
    {
        $idea = $this->seedIdea('mold on pepper', ['mold on pepper', 'moldy blossoms']);
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Mark as published', $html);
        self::assertStringNotContainsString('Published articles', $html);

        $owner->post($this->url() . '/' . $idea->key . '/published', ['url' => 'https://rival.test/blog/mold', 'published_on' => '2026-08-01']);
        self::assertStringContainsString('Enter the full address of the published article on shop.acme.test.', (string) $owner->get($this->url())->getBody());
        $owner->post($this->url() . '/' . $idea->key . '/published', ['url' => 'https://shop.acme.test/blog/mold', 'published_on' => '2099-01-01']);
        self::assertStringContainsString('not in the future', (string) $owner->get($this->url())->getBody());
        self::assertSame([], $this->trackings()->trackings);

        $owner->post($this->url() . '/' . $idea->key . '/published', ['url' => 'https://www.shop.acme.test/blog/mold/', 'published_on' => '2026-08-01']);
        self::assertStringContainsString('Tracking “mold on pepper”', (string) $owner->get($this->url())->getBody());
        self::assertContains('article_ideas.published', $this->s->audit()->actions());
        $tracking = array_values($this->trackings()->trackings)[0];
        self::assertSame([['mold on pepper', 'moldy blossoms'], ArticleIdea::NEW, 'low', ArticleTracking::MANUAL], [$tracking->keywords, $tracking->kind, $tracking->level, $tracking->source]);
        self::assertStringContainsString('not checked yet', (string) $owner->get($this->url())->getBody());

        $pages = $this->s->container->get(InMemoryPages::class);
        self::assertInstanceOf(InMemoryPages::class, $pages);
        $row = static fn(string $q, string $url, string $week, int $c, int $i, float $p): array => ['query' => $q, 'url' => $url, 'week' => $week, 'clicks' => $c, 'impressions' => $i, 'position' => $p];
        $pages->weekly[$this->s->acmeProject->id] = [
            $row('mold on pepper', 'https://shop.acme.test/blog/mold', '2026-08-03', 0, 40, 31.0),
            $row('mold on pepper', 'https://shop.acme.test/blog/mold', '2026-09-14', 12, 90, 8.6),
            $row('mold on pepper', 'https://shop.acme.test/blog/mold', '2026-09-28', 40, 140, 6.0),
            $row('moldy blossoms', 'https://shop.acme.test/blog/mold', '2026-09-28', 6, 30, 9.0),
            $row('mold on pepper', 'https://shop.acme.test/seeds/mold-free', '2026-09-28', 99, 999, 1.0),
        ];
        $this->job();
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('after 1.4 months', $html, 'The first top-10 week, 2026-09-14, from 2026-08-01.');
        self::assertStringContainsString('<td class="num">50</td>', $html, 'Clicks a month from the last four weeks (46 × 30.44 / 28); the other page does not count.');
        self::assertStringContainsString('<td class="num">6.5</td>', $html, 'Best weekly position, impression-weighted over the searches.');
        self::assertStringContainsString('Once 3 tracked articles of one kind and difficulty reach the top 10', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));

        // Viewers read, editors stop tracking.
        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewerHtml = (string) $viewer->get($this->url())->getBody();
        self::assertStringContainsString('Published articles', $viewerHtml);
        self::assertStringNotContainsString('Stop tracking', $viewerHtml);
        self::assertSame(403, $viewer->post($this->url() . '/published/' . $tracking->publicId . '/stop')->getStatusCode());
        $viewer->get($this->url());
        self::assertSame(403, $viewer->post($this->url() . '/' . $idea->key . '/published', ['url' => 'https://shop.acme.test/x', 'published_on' => '2026-08-01'])->getStatusCode());
        $owner->get($this->url());
        $owner->post($this->url() . '/published/' . $tracking->publicId . '/stop');
        self::assertSame([], $this->trackings()->trackings);
        self::assertContains('article_ideas.tracking_stopped', $this->s->audit()->actions());
    }

    public function testABriefSavedAsPublishedTracksItsIdea(): void
    {
        $idea = $this->seedIdea('mold on pepper', ['mold on pepper']);
        $owner = $this->s->loggedIn($this->s->owner);
        $briefs = '/projects/' . $this->s->acmeProject->publicId . '/content-briefs';
        $owner->get($briefs . '/new?article_idea=' . $idea->key);
        $owner->post($briefs, ['title' => 'Mold on pepper', 'keyword' => 'Mold on pepper', 'url' => 'https://shop.acme.test/blog/mold', 'status' => 'draft']);
        self::assertSame([], $this->trackings()->trackings, 'A draft is not published.');
        $owner->get($briefs . '/new');
        $owner->post($briefs, ['title' => 'Mold on pepper', 'keyword' => 'Mold on pepper', 'url' => 'https://shop.acme.test/blog/mold', 'status' => 'published']);
        $tracking = $this->firstTracking();
        self::assertNotNull($tracking);
        self::assertSame([$idea->key, 'https://shop.acme.test/blog/mold', ArticleTracking::BRIEF], [$tracking->ideaKey, $tracking->url, $tracking->source]);
        $owner->get($briefs . '/new');
        $owner->post($briefs, ['title' => 'Other', 'keyword' => 'something else', 'url' => 'https://shop.acme.test/blog/other', 'status' => 'published']);
        self::assertCount(1, $this->trackings()->trackings, 'A brief for a search no idea has tracks nothing.');
    }

    public function testThreeArticlesAtTheTopTenReplaceTheAssumedTimeToRank(): void
    {
        $tracking = $this->s->container->make(ArticleTrackingService::class);
        foreach (['2026-02-15', '2026-04-01', '2026-06-01'] as $i => $top10) {
            $idea = self::idea('topic ' . $i, ['topic ' . $i]);
            $this->ideas()->replaceIdeas($this->s->acmeProject->id, $this->s->acme->id, 1, [$idea], '2026-10-18 10:00:00');
            $t = $tracking->markPublished($this->s->owner, $this->s->acmeProject, $idea->key, 'https://shop.acme.test/blog/' . $i, '2026-01-01', new \SeoControlCenter\Core\Support\ClientInfo('203.0.113.1', 'test', null));
            $this->trackings()->saveResult($t->id, ['weeks' => [], 'top10_week' => $top10, 'best_position' => 5.0, 'clicks_28d' => 0], '2026-10-18 10:00:00');
        }
        self::assertSame(['new:low' => ['low' => 2, 'high' => 4, 'count' => 3]], $tracking->calibration($this->s->acmeProject));

        // A rebuild of the ideas now uses the site's own months for new, low-difficulty articles.
        $this->competitors()->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        $this->provider()->ranked = [new ResearchKeyword('what is hydroponics', 800, null, 10, null, 4, 'https://rival.test/guides/hydroponics', 60.0, null, 2)];
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url(), ['confirm_paid' => '1']);
        $this->s->container->make(ArticleIdeaService::class)->execute($this->ideas()->queued[0]['run_id']);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Your site: 3 tracked new articles of low difficulty reached the top 10 in about 2–4 months', $html);
        self::assertStringContainsString('Time-to-rank estimates now use your own results for: new articles, low difficulty (3 articles, 2–4 months)', $html);
    }

    /** @param list<string> $keywords */
    private function seedIdea(string $main, array $keywords): ArticleIdea
    {
        $idea = self::idea($main, $keywords);
        $this->ideas()->replaceIdeas($this->s->acmeProject->id, $this->s->acme->id, 1, [$idea], '2026-10-18 10:00:00');

        return $idea;
    }

    /** @param list<string> $keywords */
    private static function idea(string $main, array $keywords): ArticleIdea
    {
        return new ArticleIdea(hash('sha256', $main), ArticleIdea::NEW, $main, 1000, array_map(static fn(string $k): array => ['keyword' => $k, 'volume' => 500, 'difficulty' => 10], $keywords), [], null, [
            'traffic' => ['low' => 20, 'expected' => 60, 'high' => 150],
            'difficulty' => ['level' => 'low', 'reasons' => []],
            'backlinks' => null,
            'months' => ['low' => 1, 'high' => 3, 'basis' => 'An assumption.'],
        ], 10.0, []);
    }

    private function job(): void
    {
        $handler = new ArticleIdeaJobs($this->s->container->make(\SeoControlCenter\Core\Queue\Queue::class), null, null, null, $this->s->container->make(ArticleTrackingService::class));
        $handler->handle([], new JobContext('job', ArticleIdeaJobs::TRACK, 1, 3, static fn(): bool => true, time() + 60));
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/article-ideas';
    }

    private function firstTracking(): ?ArticleTracking
    {
        return array_values($this->trackings()->trackings)[0] ?? null;
    }

    private function trackings(): InMemoryArticleTracking
    {
        $t = $this->s->container->get(InMemoryArticleTracking::class);
        self::assertInstanceOf(InMemoryArticleTracking::class, $t);

        return $t;
    }

    private function ideas(): InMemoryArticleIdeas
    {
        $i = $this->s->container->get(InMemoryArticleIdeas::class);
        self::assertInstanceOf(InMemoryArticleIdeas::class, $i);

        return $i;
    }

    private function competitors(): InMemoryCompetitors
    {
        $c = $this->s->container->get(InMemoryCompetitors::class);
        self::assertInstanceOf(InMemoryCompetitors::class, $c);

        return $c;
    }

    private function provider(): FakeResearchProvider
    {
        $p = $this->s->container->get(FakeResearchProvider::class);
        self::assertInstanceOf(FakeResearchProvider::class, $p);

        return $p;
    }
}
