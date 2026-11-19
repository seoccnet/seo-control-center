<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/** Performance against the same weeks a year earlier (1.7.0). */
final class PerformanceYearHttpTest extends TestCase
{
    public function testYearEarlierKeepsWeekdays(): void
    {
        $earlier = DateRange::endingOn('2026-11-15', 28)->yearEarlier();
        self::assertSame('2025-10-20', $earlier->fromString());
        self::assertSame('2025-11-16', $earlier->toString());
        self::assertSame((new \DateTimeImmutable('2026-11-15'))->format('D'), $earlier->to->format('D'));
    }

    public function testYearOverYear(): void
    {
        $s = new Scenario();
        $search = $s->container->get(InMemorySearchPerformance::class);
        $integrations = $s->container->get(InMemoryIntegrations::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $search);
        self::assertInstanceOf(InMemoryIntegrations::class, $integrations);
        $connection = $integrations->upsert($s->acme->id, 'google', 'seo@acme.test', 'webmasters.readonly', $s->owner->id);
        $integrations->link($s->acmeProject->id, $connection->id, 'sc-domain:acme.test', $s->owner->id);
        $project = $s->acmeProject->id;
        // A year earlier: 2025-10-20 to 2025-11-16; now: 2026-10-19 to 2026-11-15.
        for ($day = new \DateTimeImmutable('2025-08-01'); $day <= new \DateTimeImmutable('2026-11-15'); $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $now = $date >= '2026-10-19';
            $search->daily[$project][$date] = new Metrics($now ? 10 : 5, 100, 5.0);
            $search->details[$project][$date]['query'] = [
                new DimensionRow('winter coats', new Metrics($now ? 5 : 2, 50, 4.0)),
                new DimensionRow('summer dress', new Metrics($now ? 1 : 4, 50, 6.0)),
            ];
            $search->details[$project][$date]['page'] = [new DimensionRow('https://shop.acme.test/coats', new Metrics($now ? 5 : 2, 50, 4.0))];
        }
        $url = '/projects/' . $s->acmeProject->publicId . '/performance';
        $viewer = $s->loggedIn($s->viewer);

        $previous = (string) $viewer->get($url)->getBody();
        self::assertStringContainsString('vs previous 28 days', $previous);
        self::assertStringNotContainsString('Biggest changes against last year', $previous);
        self::assertStringContainsString('compare=year', $previous);

        $html = (string) $viewer->get($url . '?compare=year')->getBody();
        self::assertStringContainsString('the same weeks a year earlier (2025-10-20 – 2025-11-16)', $html);
        self::assertStringContainsString('vs same weeks last year', $html);
        self::assertMatchesRegularExpression('#280</p>\s*<p class="stat-delta"><span class="delta delta-good">▲ \+100\.0 %</span>#', $html);
        self::assertMatchesRegularExpression('#Queries that gained most</h3>.*winter coats</td>\s*<td>140</td>\s*<td>56</td>\s*<td><span class="delta delta-good">\+84</span>#s', $html);
        self::assertMatchesRegularExpression('#Queries that lost most</h3>.*summer dress</td>\s*<td>28</td>\s*<td>112</td>\s*<td><span class="delta delta-bad">-84</span>#s', $html);
        self::assertMatchesRegularExpression('#Pages that lost most</h3>\s*<p class="muted small">None\.#', $html);
        self::assertStringContainsString('range=7d&amp;compare=year', $html, 'The range links keep the comparison.');

        $long = (string) $viewer->get($url . '?range=6m&compare=year')->getBody();
        self::assertStringContainsString('pick a shorter range', $long, 'Six months a year back are not imported.');
        self::assertStringNotContainsString('Biggest changes against last year', $long);
    }
}
