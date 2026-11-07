<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Analytics\LandingPageMetrics;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\DataQuality\DataQualityService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Data quality inspector (phase B): import gaps versus measured zeros,
 * GA4 coverage states, compatibility checks, the page and its isolation.
 */
final class DataQualityHttpTest extends TestCase
{
    private Scenario $s;

    private string $today;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->today = $this->s->container->make(Clock::class)->now()->format('Y-m-d');
    }

    public function testSearchConsoleGapsAreSeparatedFromMeasuredZeros(): void
    {
        $project = $this->s->acmeProject;
        $google = $this->s->container->make(InMemoryIntegrations::class);
        $connection = $google->upsert($this->s->acme->id, 'google', 'demo@example.test', '', $this->s->owner->id);
        $google->link($project->id, $connection->id, 'sc-domain:shop.acme.test', $this->s->owner->id);
        $performance = $this->s->container->make(InMemorySearchPerformance::class);
        $last = $this->day(-3);
        foreach (DateRange::endingOn($last, 10)->eachDay() as $i => $date) {
            if (in_array($i, [3, 4, 7], true)) {
                continue; // never imported
            }
            $zero = $i === 5;
            $performance->daily[$project->id][$date] = new Metrics($zero ? 0 : 5, $zero ? 0 : 100, $zero ? 0.0 : 8.0);
            if (!$zero) {
                $performance->details[$project->id][$date] = ['query' => []];
            }
        }

        $sc = $this->service()->searchConsole($project);
        self::assertSame('gaps', $sc['state']);
        self::assertSame(10, $sc['days']);
        self::assertSame(3, $sc['missing']);
        self::assertSame(1, $sc['zero_days'], 'A covered day without impressions is a measured zero.');
        self::assertSame([
            ['from' => DateRange::endingOn($last, 10)->eachDay()[7], 'to' => DateRange::endingOn($last, 10)->eachDay()[7], 'days' => 1],
            ['from' => DateRange::endingOn($last, 10)->eachDay()[3], 'to' => DateRange::endingOn($last, 10)->eachDay()[4], 'days' => 2],
        ], $sc['missing_ranges'], 'Newest gap first, consecutive days joined.');
        self::assertSame(3, $sc['lag_days']);

        $checks = array_column($this->service()->inspect($project)['compatibility'], 'title');
        self::assertContains('Days missing from Search Console', $checks);
    }

    public function testUnlinkedAndEmptySourcesAreMissingNotZero(): void
    {
        $report = $this->service()->inspect($this->s->acmeProject);
        self::assertSame('not_linked', $report['imports']['search_console']['state']);
        self::assertSame('not_linked', $report['imports']['bing']['state']);
        self::assertSame('not_connected', $report['imports']['ga4']['state']);
        self::assertNotEmpty($report['imports']['ga4']['limitations']);
        self::assertSame('missing', array_column($report['datasets'], 'status', 'source')['search_console']);
    }

    public function testGa4CoverageFlagsMissingKeyEventsAndTimezone(): void
    {
        $project = $this->s->acmeProject;
        $ga4 = $this->s->container->make(Ga4Repository::class);
        $ga4->saveCredentials($this->s->acme->id, new Ga4Credentials('test', 'test'));
        self::assertSame('not_linked', $this->service()->ga4Coverage($project)['state']);
        $ga4->link($project->id, '123');
        self::assertSame('never_imported', $this->service()->ga4Coverage($project)['state']);

        $range = DateRange::endingOn($this->day(-1), 5);
        $ga4->saveReport($project->id, $range, new Ga4Report([
            new LandingPageMetrics($range->fromString(), 'https://shop.acme.test/a', 40, 0.0, 0.0),
            new LandingPageMetrics($range->toString(), 'https://shop.acme.test/b', 10, 0.0, 0.0),
        ], 'EUR', 'Asia/Tokyo', false, true), '123');

        $coverage = $this->service()->ga4Coverage($project);
        self::assertSame('sampled', $coverage['state'], 'Thresholded data is complete in days but not in rows.');
        self::assertSame(50, $coverage['sessions']);
        self::assertSame('none_recorded', $coverage['key_events_state']);
        self::assertSame('none_recorded', $coverage['revenue_state']);
        $checks = array_column($this->service()->inspect($project)['compatibility'], 'title');
        self::assertContains('No key events from organic sessions', $checks);
        self::assertContains('GA4 data is limited or thresholded', $checks);
        self::assertContains('GA4 and the project use different timezones', $checks);
    }

    public function testPageRendersForMembersAndHidesForeignProjects(): void
    {
        $base = '/projects/' . $this->s->acmeProject->publicId . '/data-quality';
        $page = $this->s->loggedIn($this->s->viewer)->get($base);
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        self::assertStringContainsString('Can you trust what you see?', $html);
        self::assertStringContainsString('Import completeness', $html);
        self::assertStringContainsString('Not connected', $html);
        self::assertStringContainsString('href="' . $base . '"', (string) $this->s->loggedIn($this->s->owner)->get('/projects/' . $this->s->acmeProject->publicId . '/annotations')->getBody(), 'Linked from the Manage navigation.');

        self::assertSame(404, $this->s->loggedIn($this->s->rivalOwner)->get($base)->getStatusCode());
        self::assertSame(404, $this->s->loggedIn($this->s->unassigned)->get($base)->getStatusCode());
        self::assertSame(404, $this->s->loggedIn($this->s->owner)->get('/projects/' . $this->s->rivalProject->publicId . '/data-quality')->getStatusCode());
    }

    private function service(): DataQualityService
    {
        return $this->s->container->make(DataQualityService::class);
    }

    private function day(int $offset): string
    {
        return (new \DateTimeImmutable($this->today))->modify($offset . ' days')->format('Y-m-d');
    }
}
