<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/** New Search Console queries (1.6.0). */
final class NewQueriesHttpTest extends TestCase
{
    public function testNewQueriesFirstTimeOrBackAndTracking(): void
    {
        $s = new Scenario();
        $search = $s->container->get(InMemorySearchPerformance::class);
        $keywords = $s->container->get(InMemoryKeywords::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $search);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        $project = $s->acmeProject->id;
        $keywords->add($project, ['tracked new query'], KeywordLocale::default(), $s->owner->id);
        // Data from 2026-05-01 to 2026-11-15; the latest 28 days are 10-19 to 11-15, the 28 before 09-21 to 10-18.
        for ($day = new \DateTimeImmutable('2026-05-01'); $day <= new \DateTimeImmutable('2026-11-15'); $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $search->daily[$project][$date] = new Metrics(1, 10, 5.0);
            $rows = [new DimensionRow('always', new Metrics(1, 5, 4.0))];
            if ($date >= '2026-10-19') {
                $rows[] = new DimensionRow('brand new query', new Metrics(0, 3, 12.0));
                $rows[] = new DimensionRow('tracked new query', new Metrics(0, 1, 30.0));
                $rows[] = new DimensionRow('one impression', new Metrics(0, $date === '2026-11-01' ? 1 : 0, 50.0));
            }
            if ($date >= '2026-10-19' || $date < '2026-07-01') {
                $rows[] = new DimensionRow('seasonal query', new Metrics(1, 2, 8.0));
            }
            $search->details[$project][$date]['query'] = $rows;
        }
        $url = '/projects/' . $s->acmeProject->publicId . '/performance/new-queries';

        $html = (string) $s->loggedIn($s->owner)->get($url)->getBody();
        self::assertStringContainsString('3 new queries', $html, 'At least two impressions; "always" is not new.');
        self::assertMatchesRegularExpression('#brand new query</label></td>\s*<td>84</td>.*First time#s', $html);
        self::assertMatchesRegularExpression('#seasonal query</label></td>.*Back after a break#s', $html);
        self::assertMatchesRegularExpression('#tracked new query <span class="badge">Tracked</span>#', $html);
        self::assertStringNotContainsString('one impression', $html);
        self::assertStringContainsString('name="location" value="2840"', $html);
        self::assertStringContainsString('Track selected as keywords', $html);

        $viewer = (string) $s->loggedIn($s->viewer)->get($url . '?days=7')->getBody();
        self::assertStringNotContainsString('Track selected as keywords', $viewer, 'Viewers cannot add keywords.');
        self::assertStringContainsString('aria-current="page">7 days', $viewer);
        self::assertStringContainsString('/performance/new-queries', (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId . '/performance')->getBody());

        // Selected queries go to the existing keyword form.
        $owner = $s->loggedIn($s->owner);
        $owner->get($url);
        self::assertSame(303, $owner->post('/projects/' . $s->acmeProject->publicId . '/keywords', ['suggested' => ['brand new query'], 'location' => '2840', 'language' => 'en'])->getStatusCode());
        self::assertContains('brand new query', $keywords->texts($project));
    }

    public function testWithoutData(): void
    {
        $s = new Scenario();
        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId . '/performance/new-queries?days=999')->getBody();
        self::assertStringContainsString('No Search Console data yet.', $html);
        self::assertSame(404, $s->loggedIn($s->member)->get('/projects/' . $s->rivalProject->publicId . '/performance/new-queries')->getStatusCode());
    }
}
