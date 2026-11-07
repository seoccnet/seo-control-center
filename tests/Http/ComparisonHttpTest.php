<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SerpLandscape;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Clusters\ClusterService;
use SeoControlCenter\Modules\Comparison\ComparisonService;
use SeoControlCenter\Modules\Opportunities\SignalCollector;
use SeoControlCenter\Modules\Pages\PageQuery;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAnalyses;
use SeoControlCenter\Tests\Support\Fakes\InMemorySerpLandscapes;
use SeoControlCenter\Tests\Support\Scenario;
use SeoControlCenter\Tests\Unit\Comparison\ComparisonRulesTest;

/**
 * "Why they outrank you" (ADR-038, D1): eligibility, queueing, the job with
 * fetched pages, the result page, opportunities and access rules.
 */
final class ComparisonHttpTest extends TestCase
{
    private Scenario $s;

    private string $clusterId;

    private string $today;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->today = $this->s->container->make(\SeoControlCenter\Core\Support\Clock::class)->now()->format('Y-m-d');
        $project = $this->s->acmeProject;
        $members = ['sunflower guide', 'sunflower growing guide'];
        $topics = array_fill_keys($members, ['label' => 'sunflower guide', 'size' => 2, 'members' => $members]);
        $queryPages = [new PageQuery('sunflower guide', 'https://shop.acme.test/guide', new Metrics(20, 900, 8.0))];
        $this->s->container->make(ClusterService::class)->refresh($project->id, $this->s->acme->id, 'shop.acme.test', $topics, ['sunflower guide' => 2000.0, 'sunflower growing guide' => 300.0], new ProjectSignals(queryPages: $queryPages));
        $this->clusterId = $this->s->container->make(ClusterService::class)->listing($project)['items'][0]->publicId;
    }

    public function testStoredResultsPageIsFreeAndTheJobComparesFetchedPages(): void
    {
        $this->storeSerp($this->today);
        $this->fakeWeb();
        $owner = $this->s->loggedIn($this->s->owner);
        $page = html_entity_decode((string) $owner->get($this->url())->getBody());
        self::assertStringContainsString('Free: uses the stored results page', $page);
        self::assertStringContainsString('Not analyzed yet', $page);

        self::assertSame(303, $owner->post($this->url(), [])->getStatusCode());
        $analyses = $this->s->container->get(InMemoryAnalyses::class);
        self::assertInstanceOf(InMemoryAnalyses::class, $analyses);
        $id = (string) array_key_first($analyses->rows);
        self::assertSame(Analysis::QUEUED, $analyses->rows[$id]['status']);
        $owner->post($this->url(), []);
        self::assertCount(1, $analyses->rows, 'A second request while queued is refused.');
        self::assertStringContainsString('already being analyzed', html_entity_decode((string) $owner->get($this->url())->getBody()));
        $scheduler = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\FakeAnalysisScheduler::class);
        self::assertSame([$id], $scheduler->analyses);

        $this->service()->run($id);
        $analysis = $this->service()->get($this->s->acmeProject, $id);
        self::assertNotNull($analysis);
        self::assertSame(Analysis::DONE, $analysis->status, (string) $analysis->error);
        self::assertSame('https://shop.acme.test/guide', $analysis->ownUrl, 'The topic owner is the page to compare.');
        self::assertSame(4, $analysis->ownPosition);
        self::assertSame('dominant', $analysis->verdict['format']['verdict']);
        self::assertSame('content', $analysis->bottleneck());
        self::assertSame(['https://a.test/grow', 'https://b.test/guide', 'https://c.test/blog/blocked', 'https://shop.acme.test/guide'], array_keys($analysis->pages));
        self::assertSame(PageStructure::BLOCKED, $analysis->pages['https://c.test/blog/blocked']->state);
        self::assertSame([], $this->s->container->get(FakeHttpClient::class)->requestsTo('https://www.reddit.com'), 'Forums count in the format but are never fetched.');
        self::assertSame('forum', array_column($analysis->results, 'type', 'url')['https://www.reddit.com/r/grow/comments/1']);

        $result = html_entity_decode((string) $owner->get($this->url())->getBody());
        self::assertStringContainsString('Content gaps', $result);
        self::assertStringContainsString('Cover the missing subtopics', $result);
        self::assertStringContainsString('2 of 3 competing pages readable', $result);
        self::assertStringContainsString('Analyze again', $result);
        self::assertStringNotContainsString('<script>alert', (string) $owner->get($this->url())->getBody(), 'Text from fetched pages is escaped.');

        self::assertSame($id, $scheduler->refreshes[0]['analysis_id'], 'Opportunities are refreshed after the analysis.');
        $signals = $this->s->container->make(SignalCollector::class)->collect($this->s->acmeProject->id, $this->s->acme->id);
        self::assertSame('available', $signals->coverage['competitor_analysis']['status']);
        $found = (new \SeoControlCenter\Domain\Opportunities\Rules\CompetitorAnalysisRule())->evaluate($signals);
        self::assertContains('analysis:' . $this->clusterId . ':add_section', array_map(static fn($o): string => $o->subject, $found));
        self::assertContains('comparison.requested', $this->s->audit()->actions());
    }

    public function testPaidLookupNeedsConfirmationAndUnavailableSourcesExplainWhy(): void
    {
        $this->storeSerp((new \DateTimeImmutable($this->today))->modify('-30 days')->format('Y-m-d'));
        $owner = $this->s->loggedIn($this->s->owner);
        $page = html_entity_decode((string) $owner->get($this->url())->getBody());
        self::assertStringContainsString('DataForSEO is not connected', $page, 'An old stored page is not used; without DataForSEO nothing can run.');
        self::assertStringNotContainsString('name="confirm_cost"', $page);
        $owner->post($this->url(), []);
        self::assertSame([], $this->s->container->get(InMemoryAnalyses::class)->rows);
    }

    public function testPaidLookupIsQueuedOnlyAfterTheCostIsConfirmed(): void
    {
        $store = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo::class, $store);
        $store->save($this->s->acme->id, new \SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials('login@acme.test', 'secret'), 50.0, $this->s->owner->id);
        $owner = $this->s->loggedIn($this->s->owner);
        $page = html_entity_decode((string) $owner->get($this->url())->getBody());
        self::assertStringContainsString('name="confirm_cost" value="1" required', $page);
        self::assertStringContainsString('Look up the current results page for “sunflower guide”', $page);
        $owner->post($this->url(), []);
        self::assertSame([], $this->s->container->get(InMemoryAnalyses::class)->rows, 'Nothing is spent without confirmation.');
        $owner->get($this->url());
        $owner->post($this->url(), ['confirm_cost' => '1']);
        $rows = $this->s->container->get(InMemoryAnalyses::class)->rows;
        self::assertCount(1, $rows);
        self::assertSame(Analysis::PAID, array_values($rows)[0]['serp_source']);

        // A paid results lookup repeated within 14 days asks first (D3).
        $analyses = $this->s->container->get(InMemoryAnalyses::class);
        self::assertInstanceOf(InMemoryAnalyses::class, $analyses);
        $id = (string) array_key_first($analyses->rows);
        $analyses->start($id, $this->today . ' 08:00:00');
        $analyses->complete($id, [], null, null, [], [], 0.002, $this->today . ' 08:01:00');
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url(), ['confirm_cost' => '1', 'rerun' => '1'])->getStatusCode());
        self::assertCount(1, $analyses->rows, 'Not bought again without asking.');
        $page = html_entity_decode((string) $owner->get($this->url())->getBody());
        self::assertStringContainsString('was already looked up on ' . $this->today, $page);
        self::assertStringContainsString('Run again and pay', $page);
        $owner->post($this->url(), ['confirm_cost' => '1', 'rerun' => '1', 'run_again' => '1']);
        self::assertCount(2, $analyses->rows, 'Confirmed: a new paid lookup is queued.');
    }

    public function testPermissionsAndIsolation(): void
    {
        $this->storeSerp($this->today);
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertSame(200, $viewer->get($this->url())->getStatusCode());
        self::assertSame(403, $viewer->post($this->url(), [])->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $rival->get($this->url())->getStatusCode());
        $rival->get('/projects/' . $this->s->rivalProject->publicId . '/annotations');
        self::assertSame(404, $rival->post($this->url(), [])->getStatusCode());
        self::assertSame(404, $this->s->loggedIn($this->s->owner)->get('/projects/' . $this->s->rivalProject->publicId . '/keywords/topics/' . $this->clusterId . '/analysis')->getStatusCode());
        self::assertSame([], $this->s->container->get(InMemoryAnalyses::class)->rows);
    }

    private function storeSerp(string $date): void
    {
        $serps = $this->s->container->get(InMemorySerpLandscapes::class);
        self::assertInstanceOf(InMemorySerpLandscapes::class, $serps);
        $serps->serps[$this->s->acmeProject->id]['desktop']['sunflower guide'] = new SerpLandscape('sunflower guide', $date, [
            ['position' => 1, 'domain' => 'a.test', 'url' => 'https://a.test/grow', 'title' => 'Grow sunflowers'],
            ['position' => 2, 'domain' => 'b.test', 'url' => 'https://b.test/guide', 'title' => 'Sunflower guide'],
            ['position' => 3, 'domain' => 'www.reddit.com', 'url' => 'https://www.reddit.com/r/grow/comments/1', 'title' => 'Help'],
            ['position' => 4, 'domain' => 'shop.acme.test', 'url' => 'https://shop.acme.test/guide', 'title' => 'Our guide'],
            ['position' => 5, 'domain' => 'c.test', 'url' => 'https://c.test/blog/blocked', 'title' => 'Blocked guide'],
        ], [], false);
    }

    private function fakeWeb(): void
    {
        $http = $this->s->container->get(FakeHttpClient::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        $html = static fn(string $body): \Closure => static fn(): HttpResponse => new HttpResponse(200, $body, headers: ['content-type' => 'text/html; charset=utf-8']);
        foreach (['a.test', 'b.test', 'shop.acme.test'] as $host) {
            $http->on('GET', 'https://' . $host . '/robots.txt', static fn(): HttpResponse => new HttpResponse(404, ''));
        }
        $http->on('GET', 'https://c.test/robots.txt', static fn(): HttpResponse => new HttpResponse(200, "User-agent: *\nDisallow: /blog/blocked"));
        $http->on('GET', 'https://a.test/grow', $html(ComparisonRulesTest::article('Grow sunflowers <script>alert(1)</script>', ['Best soil for sunflowers', 'Watering schedule', 'Lighting hours', 'Harvest time'], 1500)));
        $http->on('GET', 'https://b.test/guide', $html(ComparisonRulesTest::article('Sunflower guide', ['Soil for sunflowers', 'Watering schedule tips', 'Lighting hours per day', 'Pests'], 1500)));
        $http->on('GET', 'https://shop.acme.test/guide', $html(ComparisonRulesTest::article('Our sunflower guide', ['Harvest time'], 300)));
    }

    private function service(): ComparisonService
    {
        return $this->s->container->make(ComparisonService::class);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/keywords/topics/' . $this->clusterId . '/analysis';
    }
}
