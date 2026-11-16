<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\UpdateImpact;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/** How Google's ranking updates moved the site (1.6.0). */
final class UpdateImpactHttpTest extends TestCase
{
    public function testBeforeAndAfterEachUpdate(): void
    {
        $s = new Scenario();
        $search = $s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $search);
        $project = $s->acmeProject->id;
        // Imported 2026-08-20 to 2026-11-15: the September 2026 spam update (09-24 to 10-08) has
        // four full weeks on both sides; the August one lacks data before it; November is rolling out.
        for ($day = new \DateTimeImmutable('2026-08-20'); $day <= new \DateTimeImmutable('2026-11-15'); $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $after = $date > '2026-10-08';
            $search->daily[$project][$date] = new Metrics($after ? 15 : 10, 100, $after ? 4.0 : 6.0);
            $search->details[$project][$date]['page'] = [
                new DimensionRow('https://shop.acme.test/winner', new Metrics($after ? 10 : 2, 50, 5.0)),
                new DimensionRow('https://shop.acme.test/loser', new Metrics($after ? 0 : 3, 40, 9.0)),
                new DimensionRow('https://shop.acme.test/steady', new Metrics(5, 10, 3.0)),
            ];
            $search->details[$project][$date]['query'] = [new DimensionRow('red shoes', new Metrics($after ? 15 : 10, 100, 4.0))];
        }
        $viewer = $s->loggedIn($s->viewer);
        $url = '/projects/' . $s->acmeProject->publicId . '/performance/updates';

        $html = (string) $viewer->get($url)->getBody();
        self::assertMatchesRegularExpression('#November 2026 core update</th>.*Still rolling out#s', $html);
        self::assertStringContainsString('<a href="' . $url . '?update=september-2026-spam-update">September 2026 spam update</a>', $html);
        self::assertStringContainsString('280 → 420', $html, 'Four weeks of 10 clicks a day against 15.');
        self::assertStringContainsString('+50.0 %', $html);
        self::assertMatchesRegularExpression('#August 2026 spam update</th>.*Search Console data incomplete#s', $html);
        self::assertStringContainsString('/performance/updates', (string) $viewer->get('/projects/' . $s->acmeProject->publicId . '/performance')->getBody());

        $detail = (string) $viewer->get($url . '?update=september-2026-spam-update')->getBody();
        self::assertMatchesRegularExpression('#Pages that gained most.*/winner</td><td>56 → 280</td><td>\+224</td>#s', $detail);
        self::assertMatchesRegularExpression('#Pages that lost most.*/loser</td><td>84 → 0</td><td>-84</td>#s', $detail);
        self::assertStringNotContainsString('/steady', $detail);
        self::assertStringContainsString('red shoes', $detail);
        self::assertStringNotContainsString('Pages that gained most', (string) $viewer->get($url . '?update=november-2026-core-update')->getBody(), 'No detail while an update is rolling out.');
        self::assertSame(404, $viewer->get('/projects/' . $s->rivalProject->publicId . '/performance/updates')->getStatusCode());
    }

    public function testWindowsSkipTheRollout(): void
    {
        $update = ['name' => 'X', 'start' => '2026-03-27', 'end' => '2026-04-08'];
        self::assertSame(['2026-02-27', '2026-03-26'], [UpdateImpact::before($update)->fromString(), UpdateImpact::before($update)->toString()]);
        self::assertSame(['2026-04-09', '2026-05-06'], [UpdateImpact::after($update)?->fromString(), UpdateImpact::after($update)?->toString()]);
        self::assertNull(UpdateImpact::after(['name' => 'X', 'start' => '2026-11-10', 'end' => null]));
        self::assertNull(UpdateImpact::percent(0, 5));
    }
}
