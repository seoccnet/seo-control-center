<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * CSV downloads of keywords, rankings, opportunities and Search Console
 * performance: format, filters, formula escaping and tenant isolation.
 */
final class CsvExportHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryKeywords $keywords;

    private InMemoryRankings $rankings;

    private InMemorySearchPerformance $performance;

    protected function setUp(): void
    {
        $this->s = new Scenario(clock: new \SeoControlCenter\Core\Support\FrozenClock(new \DateTimeImmutable('2026-09-26')));
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        $performance = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        self::assertInstanceOf(InMemorySearchPerformance::class, $performance);
        $this->keywords = $keywords;
        $this->rankings = $rankings;
        $this->performance = $performance;
        $this->rankings->projects[$this->s->acmeProject->id] = ['workspace_id' => $this->s->acme->id, 'domain' => 'shop.acme.test'];
    }

    public function testKeywordsExportIsExcelFriendlyAndEscapesFormulas(): void
    {
        $this->keywords->add($this->s->acmeProject->id, ['red shoes', '=hyperlink("x")'], KeywordLocale::default(), $this->s->owner->id);

        $response = $this->s->loggedIn($this->s->viewer)->get($this->url('keywords') . '/export');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('#^attachment; filename="acme-shop-keywords-\d{4}-\d{2}-\d{2}\.csv"$#', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $body = (string) $response->getBody();
        self::assertStringStartsWith("\u{FEFF}Keyword,Location,Language,", $body);
        self::assertStringContainsString("\r\n", $body);
        $rows = $this->parse($response);
        self::assertCount(3, $rows);
        $cells = array_column(array_slice($rows, 1), 0);
        self::assertContains('red shoes', $cells);
        self::assertContains('\'=hyperlink("x")', $cells, 'Formula cells get an apostrophe.');
    }

    public function testRankingsExportKeepsThePositionFilter(): void
    {
        $project = $this->s->acmeProject->id;
        $this->keywords->add($project, ['red shoes', 'blue shoes'], KeywordLocale::default(), $this->s->owner->id);
        $this->rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::DAILY), $this->s->owner->id);
        $ids = array_column($this->keywords->keywords[$project], 'id');
        foreach ([3, 40] as $i => $position) {
            $this->rankings->storeSnapshot(new RankSnapshot($ids[$i], $project, SerpDevice::Desktop, '2026-09-26', $position, $position, 'https://shop.acme.test/p' . $i, [], []));
        }
        $this->rankings->aggregate($project, $this->s->acme->id, SerpDevice::Desktop, '2026-09-26');
        $browser = $this->s->loggedIn($this->s->member);

        $all = $this->parse($browser->get($this->url('rankings') . '/export'));
        $top = $this->parse($browser->get($this->url('rankings') . '/export?filter=top3'));

        self::assertSame('Keyword', $all[0][0]);
        self::assertCount(3, $all);
        self::assertCount(2, $top);
        self::assertSame(['red shoes', '3'], [$top[1][0], $top[1][2]]);
        self::assertSame('https://shop.acme.test/p0', $top[1][6]);
    }

    public function testOpportunitiesExportFollowsStatus(): void
    {
        $this->seedSearchConsole();
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get($this->url('opportunities'));
        $browser->post($this->url('opportunities') . '/analyze');

        $open = $this->parse($browser->get($this->url('opportunities') . '/export'));
        $done = $this->parse($browser->get($this->url('opportunities') . '/export?status=done'));

        self::assertSame('Type', $open[0][0]);
        self::assertCount(3, $open);
        self::assertStringContainsString('weak snippet', $open[1][1]);
        self::assertSame('open', $open[1][6]);
        self::assertCount(1, $done, 'Only the header.');
    }

    public function testPerformanceExportByDimension(): void
    {
        $this->seedSearchConsole();
        $browser = $this->s->loggedIn($this->s->viewer);

        $response = $browser->get($this->url('performance') . '/export?dimension=query&range=7d');
        $queries = $this->parse($response);
        $pages = $this->parse($browser->get($this->url('performance') . '/export?dimension=page'));

        self::assertSame('attachment; filename="acme-shop-queries-7d-2026-09-24.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(['Query', 'Clicks', 'Impressions', 'CTR (%)', 'Average position'], $queries[0]);
        self::assertSame(['fine query', '150', '1000', '15', '1'], $queries[1]);
        self::assertSame(['Page', 'Clicks', 'Impressions', 'CTR (%)', 'Average position'], $pages[0]);
        self::assertSame('https://shop.acme.test/', $pages[1][0]);
    }

    public function testOtherTenantsAndAnonymousUsersCannotExport(): void
    {
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        foreach (['keywords', 'rankings', 'opportunities', 'performance'] as $section) {
            $response = $rival->get($this->url($section) . '/export');
            self::assertSame(404, $response->getStatusCode(), $section);
            self::assertStringNotContainsString('text/csv', $response->getHeaderLine('Content-Type'), $section);
        }
        self::assertSame(303, $this->s->browser()->get($this->url('keywords') . '/export')->getStatusCode());
    }

    private function seedSearchConsole(): void
    {
        $project = $this->s->acmeProject->id;
        $this->performance->upsertDailyTotals($project, [new DailyMetrics('2026-09-24', new Metrics(154, 2400, 8.0))]);
        $this->performance->replaceDay($project, '2026-09-24', [
            'query' => [
                new DimensionRow('fine query', new Metrics(150, 1000, 1.0)),
                new DimensionRow('weak snippet', new Metrics(4, 1000, 2.0)),
                new DimensionRow('deep topic', new Metrics(0, 400, 34.0)),
            ],
            'page' => [new DimensionRow('https://shop.acme.test/', new Metrics(154, 2400, 8.0))],
        ]);
    }

    /**
     * @return list<list<string>>
     */
    private function parse(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        $body = substr((string) $response->getBody(), 3);
        $rows = [];
        foreach (explode("\r\n", rtrim($body, "\r\n")) as $line) {
            $rows[] = array_map(strval(...), str_getcsv($line, ',', '"', ''));
        }

        return $rows;
    }

    private function url(string $section): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/' . $section;
    }
}
