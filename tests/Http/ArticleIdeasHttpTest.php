<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRun;
use SeoControlCenter\Domain\ArticleIdeas\ArticleOutline;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaJobs;
use SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\FakeResearchProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Scenario;

/** Research → Article ideas (ADR-041, G1). */
final class ArticleIdeasHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testPaidLookupNeedsCompetitorsAndConfirmationThenShowsIdeas(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('No competitors yet', $html);
        self::assertStringNotContainsString('Find article ideas', $html);

        $this->competitors()->add($this->s->acmeProject->id, 'www.rival.test', Competitor::MANUAL, $this->s->owner->id);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Compared with: rival.test', $html);
        self::assertStringContainsString('Find article ideas', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));

        $owner->post($this->url());
        self::assertStringContainsString('Confirm the paid run', (string) $owner->get($this->url())->getBody());
        self::assertSame([], $this->ideas()->queued, 'Nothing is bought without confirmation.');

        $owner->post($this->url(), ['confirm_paid' => '1']);
        self::assertCount(1, $this->ideas()->queued);
        self::assertContains('article_ideas.run_requested', $this->s->audit()->actions());
        $waiting = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Lookup waiting for cron', $waiting);
        self::assertStringNotContainsString('Look up again', $waiting, 'No second lookup offered while one waits.');
        $owner->post($this->url(), ['confirm_paid' => '1']);
        self::assertStringContainsString('already being looked up', (string) $owner->get($this->url())->getBody());

        $this->provider()->rankedFor['rival.test'] = [
            new ResearchKeyword('how to germinate seeds', 1200, null, 20, null, 2, 'https://rival.test/blog/germinate-seeds', 240.0, null, 6),
            new ResearchKeyword('seed starter kit', 3000, null, 30, null, 1, 'https://rival.test/products/starter-kit', 600.0, null, 9),
        ];
        $this->service()->execute($this->ideas()->queued[0]['run_id']);
        self::assertSame([['ranked', ['rival.test', '2840:en', 500]]], array_map(static fn(array $c): array => [$c['call'], $c['args']], $this->provider()->calls));
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('How to germinate seeds', $html);
        self::assertStringContainsString('New article', $html);
        self::assertStringContainsString('/blog/germinate-seeds', $html);
        self::assertStringNotContainsString('starter-kit', $html, 'Product pages are not articles.');
        self::assertStringContainsString('Time to rank', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('Top article ideas', (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/opportunities')->getBody());

        $owner->post($this->url(), ['confirm_paid' => '1']);
        self::assertStringContainsString('tick “Run again and pay”', (string) $owner->get($this->url())->getBody(), 'A recent lookup is not bought again by accident.');
        self::assertCount(1, $this->ideas()->queued);
    }

    public function testFailedLookupIsShownAndDismissHidesTheIdea(): void
    {
        $this->competitors()->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url(), ['confirm_paid' => '1']);
        $this->provider()->fail = new IntegrationException('DataForSEO budget reached.', false);
        $this->service()->execute($this->ideas()->queued[0]['run_id']);
        self::assertSame(ArticleIdeaRun::FAILED, $this->ideas()->runs[$this->ideas()->queued[0]['run_id']]->status);
        self::assertStringContainsString('The last lookup failed: DataForSEO budget reached.', (string) $owner->get($this->url())->getBody());

        $this->provider()->ranked = [new ResearchKeyword('what is hydroponics', 800, null, 10, null, 4, 'https://rival.test/guides/hydroponics', 60.0, null, 2)];
        $owner->post($this->url(), ['confirm_paid' => '1']);
        $this->service()->execute($this->ideas()->queued[1]['run_id']);
        $idea = $this->ideas()->ideas[$this->s->acmeProject->id]['ideas'][0];
        $owner->get($this->url());
        $owner->post($this->url() . '/' . $idea->key . '/dismiss', ['reason' => 'not_relevant']);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('marked as not relevant', $html);
        self::assertStringNotContainsString('What is hydroponics', $html);
        self::assertContains('article_ideas.dismissed', $this->s->audit()->actions());

        // The daily rebuild leaves the dismissal in place and rules the search out.
        self::assertSame(0, $this->service()->rebuild($this->s->acmeProject->id, $this->s->acme->id));
    }

    public function testRebuildNowIsFreeQueuedOncePerWindowAndLimited(): void
    {
        $this->competitors()->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringNotContainsString('Rebuild ideas now', (string) $owner->get($this->url())->getBody(), 'Nothing stored to rebuild from yet.');
        $owner->post($this->url() . '/rebuild');
        self::assertStringContainsString('no finished lookup to rebuild from', (string) $owner->get($this->url())->getBody());
        self::assertSame([], $this->ideas()->rebuilds);

        $this->provider()->ranked = [new ResearchKeyword('what is hydroponics', 800, null, 10, null, 4, 'https://rival.test/guides/hydroponics', 60.0, null, 2)];
        $owner->post($this->url(), ['confirm_paid' => '1']);
        $runId = $this->ideas()->queued[0]['run_id'];
        self::assertStringNotContainsString('Rebuild ideas now', (string) $owner->get($this->url())->getBody(), 'Not while a lookup waits.');
        $this->service()->execute($runId);
        $calls = count($this->provider()->calls);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Rebuild ideas now', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));

        $owner->post($this->url() . '/rebuild');
        self::assertStringContainsString('Rebuilding the ideas from the stored lookup, for free', (string) $owner->get($this->url())->getBody());
        $owner->post($this->url() . '/rebuild');
        self::assertCount(1, $this->ideas()->rebuilds, 'A double click within ten minutes queues one rebuild.');
        self::assertSame(['project_id' => $this->s->acmeProject->id, 'workspace_id' => $this->s->acme->id], array_values($this->ideas()->rebuilds)[0]);
        self::assertContains('article_ideas.rebuild_requested', $this->s->audit()->actions());
        self::assertCount($calls, $this->provider()->calls, 'Nothing is bought.');
        for ($i = 0; $i < ArticleIdeaService::REBUILDS_PER_HOUR; ++$i) {
            $owner->get($this->url());
            $owner->post($this->url() . '/rebuild');
        }
        self::assertStringContainsString('Wait a while before rebuilding again', (string) $owner->get($this->url())->getBody());

        // The queued job rebuilds only that project, from its stored rows.
        $ideas = $this->ideas();
        unset($ideas->ideas[$this->s->acmeProject->id]);
        $handler = new ArticleIdeaJobs($this->s->container->make(\SeoControlCenter\Core\Queue\Queue::class), $this->service(), $ideas);
        $handler->handle(['project_id' => $this->s->acmeProject->id, 'workspace_id' => $this->s->acme->id], new JobContext('job', ArticleIdeaJobs::REBUILD, 1, 3, static fn(): bool => true, time() + 60));
        self::assertSame('what is hydroponics', $ideas->idea($this->s->acmeProject, hash('sha256', 'what is hydroponics'))?->mainKeyword);
        self::assertCount($calls, $this->provider()->calls);
    }

    public function testCompetitorOutlinesGiveSubtopicsAndPrefillABrief(): void
    {
        $owner = $this->ideaWithTwoCompetitors();
        $idea = $this->ideas()->ideas[$this->s->acmeProject->id]['ideas'][0];
        self::assertSame('how to water seedlings', $idea->mainKeyword);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Read competitor outlines', $html);
        self::assertStringContainsString('content-briefs/new?article_idea=' . $idea->key, $html);

        $owner->post($this->url() . '/' . $idea->key . '/outline');
        self::assertStringContainsString('Reading the competitor outlines, for free', (string) $owner->get($this->url())->getBody());
        self::assertSame([['project_id' => $this->s->acmeProject->id, 'workspace_id' => $this->s->acme->id, 'idea_key' => $idea->key]], $this->ideas()->outlineJobs);
        $owner->post($this->url() . '/' . $idea->key . '/outline');
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('already being read', $html);
        self::assertStringContainsString('Reading the competitor outlines (requested', $html);
        self::assertCount(1, $this->ideas()->outlineJobs);
        self::assertContains('article_ideas.outline_requested', $this->s->audit()->actions());

        $this->fakeWeb();
        $this->outlineJob($idea->key);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Watering schedule <span class="muted">(2 of 2)</span>', $html);
        self::assertStringContainsString('How often should you water seedlings?', $html);
        self::assertStringContainsString('not read: robots.txt of the site does not allow this page to be fetched.', $html);
        self::assertStringNotContainsString('lorem ipsum', $html, 'Only headings are kept and shown, never the text.');
        self::assertStringNotContainsString('Read outlines again', $html, 'Not again within a week.');
        self::assertSame([], AccessibilityTest::problems($html, true));
        $owner->post($this->url() . '/' . $idea->key . '/outline');
        self::assertStringContainsString('can be read again a week later', (string) $owner->get($this->url())->getBody());
        self::assertSame([], array_values(array_filter(array_column($this->provider()->calls, 'call'), static fn(string $c): bool => $c !== 'ranked')), 'Outlines buy nothing.');

        $brief = (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/content-briefs/new?article_idea=' . $idea->key)->getBody();
        self::assertStringContainsString('how to water seedlings', $brief);
        self::assertStringContainsString("H1: how to water seedlings\nH2: Soil for seedlings\nH2: Watering schedule", html_entity_decode($brief));
        self::assertStringContainsString('Answer: How often should you water seedlings?', html_entity_decode($brief));
        self::assertStringContainsString('seedling watering', $brief, 'Related searches.');
        self::assertStringContainsString('Competitor article: https://rival.test/blog/watering-seedlings', $brief);
        self::assertStringContainsString('Competitor outlines read', $brief);
        self::assertStringContainsString('Informational: searchers want an article or guide', $brief);
        $plain = (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/content-briefs/new?article_idea=' . str_repeat('0', 64) . '&keyword=plain+brief')->getBody();
        self::assertStringContainsString('plain brief', $plain, 'An unknown idea falls back to the plain form.');
        self::assertStringNotContainsString('Competitor article:', $plain);
    }

    public function testOutlinesOfAnUpdateMarkWhatYourPageMisses(): void
    {
        $owner = $this->ideaWithTwoCompetitors();
        $built = $this->ideas()->ideas[$this->s->acmeProject->id]['ideas'][0];
        // Your article ranks around 9 for it (as the opportunity signals would show): an update.
        $idea = new \SeoControlCenter\Domain\ArticleIdeas\ArticleIdea($built->key, \SeoControlCenter\Domain\ArticleIdeas\ArticleIdea::UPDATE, $built->mainKeyword, $built->volume, $built->keywords, $built->competitors, ['url' => 'https://shop.acme.test/blog/seedlings', 'position' => 9.0, 'impressions' => 300], $built->prediction, $built->score, $built->reasons);
        $this->ideas()->replaceIdeas($this->s->acmeProject->id, $this->s->acme->id, 1, [$idea], '2026-10-18 10:00:00');
        $owner->post($this->url() . '/' . $idea->key . '/outline');
        $this->fakeWeb();
        $this->outlineJob($idea->key);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('✓ marks subtopics your page already has', $html);
        self::assertStringContainsString('Watering schedule <span class="muted">(2 of 2)</span> <span class="muted">· missing on your page</span>', $html);
        self::assertStringContainsString('Soil for seedlings <span class="muted">(2 of 2)</span> <span aria-label="on your page">✓</span>', $html);
        $brief = html_entity_decode((string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/content-briefs/new?article_idea=' . $idea->key)->getBody());
        self::assertStringContainsString('Missing on your page: Watering schedule', $brief);
        self::assertStringContainsString('https://shop.acme.test/blog/seedlings', $brief, 'The brief targets your page.');
    }

    public function testOneArticlePerSiteIsReadFirst(): void
    {
        // 0.28.0 live check, finding 10: two articles of one site were read and the other site's was not.
        foreach (['rival.test', 'other.test'] as $domain) {
            $this->competitors()->add($this->s->acmeProject->id, $domain, Competitor::MANUAL, $this->s->owner->id);
        }
        $this->provider()->rankedFor['rival.test'] = [
            // Two more rival articles that win no search: listed with the article that wins theirs.
            new ResearchKeyword('how to water seedlings', 2400, null, 12, null, 2, 'https://rival.test/blog/watering-seedlings', 500.0, null, 8),
            new ResearchKeyword('how to water seedlings', 2400, null, 12, null, 9, 'https://rival.test/blog/seedling-care', 250.0, null, 8),
            new ResearchKeyword('how to water seedlings', 2400, null, 12, null, 10, 'https://rival.test/blog/watering-101', 40.0, null, 8),
        ];
        $this->provider()->rankedFor['other.test'] = [new ResearchKeyword('how to water seedlings', 2400, null, 12, null, 4, 'https://other.test/guides/seedlings', 20.0, null, 8)];
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url(), ['confirm_paid' => '1']);
        $this->service()->execute($this->ideas()->queued[0]['run_id']);
        $idea = $this->ideas()->ideas[$this->s->acmeProject->id]['ideas'][0];
        self::assertCount(4, $idea->competitors);
        self::assertSame('https://other.test/guides/seedlings', $idea->competitors[3]['url'], 'Fourth by traffic.');
        $owner->get($this->url());
        $owner->post($this->url() . '/' . $idea->key . '/outline');
        $this->fakeWeb();
        $this->outlineJob($idea->key);

        $read = array_values(array_filter(array_column($this->http()->requests, 'url'), static fn(string $u): bool => !str_ends_with($u, '/robots.txt')));
        self::assertSame(['https://rival.test/blog/watering-seedlings', 'https://other.test/guides/seedlings', 'https://rival.test/blog/seedling-care'], $read, 'The other site comes second.');
    }

    public function testUnreadableOutlinesFailWithTheReason(): void
    {
        $owner = $this->ideaWithTwoCompetitors();
        $idea = $this->ideas()->ideas[$this->s->acmeProject->id]['ideas'][0];
        $owner->post($this->url() . '/' . $idea->key . '/outline');
        $http = $this->http();
        foreach (['rival.test', 'other.test'] as $host) {
            $http->on('GET', 'https://' . $host . '/robots.txt', static fn(): HttpResponse => new HttpResponse(200, "User-agent: *\nDisallow: /"));
        }
        $this->outlineJob($idea->key);
        self::assertSame(ArticleOutline::FAILED, $this->ideas()->outlineRows[$this->s->acmeProject->id][$idea->key]['outline']->status);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('The outlines could not be read: No competitor article could be read: robots.txt of the site does not allow this page to be fetched.', $html);
        self::assertStringContainsString('Read outlines again', $html, 'A failed read can be repeated.');
    }

    public function testViewersSeeIdeasButCannotBuyOrDismiss(): void
    {
        $this->competitors()->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($this->url())->getBody();
        self::assertStringNotContainsString('Find article ideas', $html);
        self::assertSame(403, $viewer->post($this->url(), ['confirm_paid' => '1'])->getStatusCode());
        $viewer->get($this->url());
        self::assertSame(403, $viewer->post($this->url() . '/rebuild')->getStatusCode());
        self::assertSame([], $this->ideas()->rebuilds);
        self::assertSame([], $this->ideas()->queued);
    }

    /** An idea answered by articles of two competitors, built from a finished lookup. */
    private function ideaWithTwoCompetitors(): \SeoControlCenter\Tests\Support\Browser
    {
        foreach (['rival.test', 'other.test'] as $domain) {
            $this->competitors()->add($this->s->acmeProject->id, $domain, Competitor::MANUAL, $this->s->owner->id);
        }
        $this->provider()->rankedFor['rival.test'] = [
            new ResearchKeyword('how to water seedlings', 2400, null, 12, null, 2, 'https://rival.test/blog/watering-seedlings', 300.0, null, 8),
            new ResearchKeyword('seedling watering', 900, null, 10, null, 3, 'https://rival.test/blog/watering-seedlings', 90.0, null, 8),
        ];
        $this->provider()->rankedFor['other.test'] = [
            new ResearchKeyword('how to water seedlings', 2400, null, 12, null, 4, 'https://other.test/guides/seedlings', 120.0, null, 8),
            new ResearchKeyword('how to water seedlings', 2400, null, 12, null, 6, 'https://other.test/guides/blocked', 10.0, null, 8),
        ];
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url(), ['confirm_paid' => '1']);
        $this->service()->execute($this->ideas()->queued[0]['run_id']);
        $owner->get($this->url());

        return $owner;
    }

    private function outlineJob(string $key): void
    {
        $job = new ArticleIdeaJobs($this->s->container->make(\SeoControlCenter\Core\Queue\Queue::class), $this->service(), $this->ideas());
        $job->handle(['project_id' => $this->s->acmeProject->id, 'workspace_id' => $this->s->acme->id, 'idea_key' => $key], new JobContext('job', ArticleIdeaJobs::OUTLINE, 1, 2, static fn(): bool => true, time() + 60));
    }

    private function fakeWeb(): void
    {
        $http = $this->http();
        foreach (['rival.test', 'shop.acme.test'] as $host) {
            $http->on('GET', 'https://' . $host . '/robots.txt', static fn(): HttpResponse => new HttpResponse(404, ''));
        }
        $http->on('GET', 'https://other.test/robots.txt', static fn(): HttpResponse => new HttpResponse(200, "User-agent: *\nDisallow: /guides/blocked"));
        $http->on('GET', 'https://rival.test/blog/watering-seedlings', self::article('Watering seedlings', ['Watering schedule', 'Soil for seedlings', 'How often should you water seedlings?']));
        $http->on('GET', 'https://other.test/guides/seedlings', self::article('Seedling care', ['Watering schedule for seedlings', 'Best soil for seedlings', 'About us']));
        $http->on('GET', 'https://shop.acme.test/blog/seedlings', self::article('Our seedlings', ['Soil for your seedlings']));
    }

    /**
     * @param list<string> $headings
     * @return \Closure(): HttpResponse
     */
    private static function article(string $title, array $headings): \Closure
    {
        return static fn(): HttpResponse => new HttpResponse(200, \SeoControlCenter\Tests\Unit\Comparison\ComparisonRulesTest::article($title, $headings, 900), headers: ['content-type' => 'text/html; charset=utf-8']);
    }

    private function http(): FakeHttpClient
    {
        $http = $this->s->container->get(FakeHttpClient::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);

        return $http;
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/article-ideas';
    }

    private function ideas(): InMemoryArticleIdeas
    {
        $ideas = $this->s->container->get(InMemoryArticleIdeas::class);
        self::assertInstanceOf(InMemoryArticleIdeas::class, $ideas);

        return $ideas;
    }

    private function competitors(): InMemoryCompetitors
    {
        $competitors = $this->s->container->get(InMemoryCompetitors::class);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);

        return $competitors;
    }

    private function provider(): FakeResearchProvider
    {
        $provider = $this->s->container->get(FakeResearchProvider::class);
        self::assertInstanceOf(FakeResearchProvider::class, $provider);

        return $provider;
    }

    private function service(): ArticleIdeaService
    {
        return $this->s->container->make(ArticleIdeaService::class);
    }
}
