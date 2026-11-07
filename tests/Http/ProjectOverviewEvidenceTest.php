<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Analytics\LandingPageMetrics;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Overview\ProjectOverview;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\OverviewFixture;

final class ProjectOverviewEvidenceTest extends TestCase
{
    public function testForecastHistoryIsIndependentOfSevenDayDisplayAndProjectScoped(): void
    {
        $s = OverviewFixture::scenario();
        $store = $s->container->make(SearchPerformanceStore::class);
        $store->upsertDailyTotals($s->rivalProject->id, [new DailyMetrics('2026-10-06', new Metrics(999999, 999999, 1))]);
        $data = $s->container->make(ProjectOverview::class)->build($s->viewer, $s->acmeProject, '7d');
        self::assertCount(7, $data['search']['daily']);
        self::assertCount(56, $data['search']['forecast']['history']);
        self::assertSame('2026-10-07', $data['search']['forecast']['asOf']);
        self::assertSame(145, $data['search']['forecast']['history'][55]['clicks']);
        $response = $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId . '?range=7d');
        self::assertSame(200, $response->getStatusCode());
        $doc = HTMLDocument::createFromString((string) $response->getBody(), LIBXML_NOERROR);
        $card = $doc->querySelector('[data-prognosis]');
        self::assertNotNull($card);
        self::assertCount(7, json_decode((string) $card->getAttribute('data-days'), true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(56, json_decode((string) $card->getAttribute('data-history'), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('2026-10-07', $card->getAttribute('data-as-of'));
        $store->upsertDailyTotals($s->acmeProject->id, [new DailyMetrics('2026-04-03', new Metrics(5, 100, 3))]);
        $long = $s->container->make(ProjectOverview::class)->build($s->viewer, $s->acmeProject, '12m');
        self::assertCount(57, $long['search']['forecast']['display'], 'Chart retains history older than the training bound.');
        self::assertCount(56, $long['search']['forecast']['history']);
    }

    public function testEmptyProjectRendersOneStartingPointAndPreservedPrognosis(): void
    {
        $s = OverviewFixture::scenario(false);
        $response = $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $doc = HTMLDocument::createFromString((string) $response->getBody(), LIBXML_NOERROR);
        self::assertCount(1, $doc->querySelectorAll('.overview-start'));
        self::assertCount(0, $doc->querySelectorAll('.overview-decision-grid'));
        self::assertCount(0, $doc->querySelectorAll('.overview-search-summary'));
        self::assertCount(1, $doc->querySelectorAll('[data-prognosis]'));
        self::assertStringContainsString('Performance prognosis', (string) $response->getBody());
    }

    public function testCompleteEvidenceRendersComparisonsAndMakesNoProviderCalls(): void
    {
        $s = OverviewFixture::scenario();
        $data = $s->container->make(ProjectOverview::class)->build($s->viewer, $s->acmeProject, '28d');
        $evidence = $data['evidence'];
        self::assertTrue($evidence['comparable']);
        self::assertTrue($evidence['analytics']['comparable']);
        self::assertSame(8, $evidence['movers']['query']['total']);
        self::assertNotContains('new query without baseline', array_column($evidence['movers']['query']['gainers'], 'key'));
        self::assertSame(5, $evidence['ranking']['common']);
        self::assertSame(6, $evidence['ranking']['sample']);
        self::assertSame(1, $evidence['ranking']['unrepresented']);
        self::assertSame(1, $evidence['demand'][0]['tracked']);
        self::assertSame(3, $evidence['demand'][0]['untracked']);
        self::assertSame(1400, $evidence['demand'][0]['untrackedVolume']);
        self::assertSame(1, $evidence['demand'][0]['unknownVolume']);
        self::assertStringEndsWith('/guides/germination', $evidence['analytics']['rows'][0]['url']);
        $response = $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $html = (string) $response->getBody();
        foreach (['What moved', 'Estimated click gap', 'Where keywords rank', 'Landing-page outcomes', 'Demand to pursue', 'Competitive &amp; AI visibility', 'Traffic risks to review', 'Recent SEO changes', '70,000 impressions', 'USD', 'Not found'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringContainsString('Google clicks', $html);
        self::assertStringNotContainsString('new query without baseline</td>', $html);
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        self::assertCount(1, $doc->querySelectorAll('[data-prognosis]'));
        self::assertCount(0, $doc->querySelectorAll('script:not([src]):not([nonce])'));
        self::assertCount(0, $doc->querySelectorAll('form[action$="/render"]'));
        $http = $s->container->make(HttpClient::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        self::assertSame([], $http->requests);
        self::assertNotSame(200, $s->loggedIn($s->rivalOwner)->get('/projects/' . $s->acmeProject->publicId)->getStatusCode());
    }

    public function testPartialImportsDoNotRenderMoversOrCtrEstimates(): void
    {
        $s = OverviewFixture::scenario(false);
        $store = $s->container->make(InMemorySearchPerformance::class);
        $store->upsertDailyTotals($s->acmeProject->id, [new DailyMetrics('2026-10-06', new Metrics(10, 200, 8))]);
        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId)->getBody();
        self::assertStringContainsString('Query/page imports cover 0 / 28', $html);
        self::assertStringNotContainsString('Estimated click gap', $html);
        self::assertStringNotContainsString('Δ clicks', $html);
        self::assertStringContainsString('No GA4 Organic Search data imported', $html);
        $store->upsertDailyTotals($s->acmeProject->id, array_map(static fn(string $date): DailyMetrics => new DailyMetrics($date, Metrics::zero()), DateRange::endingOn('2026-10-06', 56)->eachDay()));
        $data = $s->container->make(ProjectOverview::class)->build($s->viewer, $s->acmeProject, '28d');
        self::assertTrue($data['evidence']['comparable'], 'Recorded empty dates need no provider breakdown.');
        self::assertSame([], $data['evidence']['movers']['query']['gainers'], 'Empty dates do not invent measured query rows.');
    }

    public function testGa4OnlyUsesLatestSavedPeriodAndQualityRestrictionsWithholdRates(): void
    {
        $s = OverviewFixture::scenario(false);
        $ga = $s->container->make(Ga4Repository::class);
        $ga->link($s->acmeProject->id, '123456');
        $range = new DateRange('2026-08-01', '2026-08-28');
        $ga->saveReport($s->acmeProject->id, $range, new Ga4Report([new LandingPageMetrics('2026-08-28', 'https://shop.acme.test/', 20, 0, 0)], 'EUR', 'Europe/Berlin', true, false), '123456');
        $data = $s->container->make(ProjectOverview::class)->build($s->viewer, $s->acmeProject, '28d');
        self::assertSame('2026-08-28', $data['evidence']['range']->toString());
        self::assertFalse($data['evidence']['analytics']['complete']);
        self::assertFalse($data['evidence']['analytics']['comparable']);
        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId)->getBody();
        self::assertStringContainsString('Google marked this report limited or thresholded', $html);
        self::assertStringContainsString('EUR', $html);
        self::assertStringNotContainsString('overview-start-heading', $html);
    }

    public function testResearchKeepsMarketsSeparateAndExcludesUnpublishedResults(): void
    {
        $s = OverviewFixture::scenario();
        $research = $s->container->make(ResearchRepository::class);
        $research->queue($s->acmeProject->id, $s->acme->id, 'ranked', '', ['location' => 2826, 'language' => 'en'], $s->owner->id);
        $research->complete($s->acmeProject->id, 'ranked', '', [['k' => 'indoor growing', 'v' => 600]]);
        $research->queue($s->acmeProject->id, $s->acme->id, 'ideas', '', ['location' => KeywordLocale::default()->locationCode, 'language' => 'en'], $s->owner->id);
        $data = $s->container->make(ProjectOverview::class)->build($s->viewer, $s->acmeProject, '28d');
        self::assertCount(2, $data['evidence']['demand']);
        $uk = array_values(array_filter($data['evidence']['demand'], static fn($g): bool => $g['locale']->locationCode === 2826))[0];
        self::assertSame(0, $uk['tracked']);
        self::assertSame(1, $uk['untracked']);
        self::assertSame(600, $uk['untrackedVolume']);
    }

    public function testSparseBingStillShowsTheGoogleComparison(): void
    {
        $s = OverviewFixture::scenario();
        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId)->getBody();
        self::assertStringContainsString('vs previous period</span>', $html, 'Google alone compares over the full periods.');
        self::assertStringNotContainsString('<p class="stat-note">Comparison unavailable</p>', $html);

        // Bing reports rows only for days with data, so its earlier period is incomplete.
        $bing = $s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryBing::class);
        $bing->saveLink($s->acmeProject->id, $s->acme->id, 'https://shop.acme.test/', $s->owner->id);
        $bing->replaceData($s->acmeProject->id, [new DailyMetrics('2026-10-01', new Metrics(4, 90, 3.0)), new DailyMetrics('2026-10-03', new Metrics(2, 40, 4.0))], []);
        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId)->getBody();
        self::assertStringContainsString('Google + Bing', $html);
        self::assertStringContainsString('vs previous period · Google only</span>', $html, 'The change is shown on Google’s figures and labelled.');
        self::assertStringNotContainsString('<p class="stat-note">Comparison unavailable</p>', $html);
    }
}
