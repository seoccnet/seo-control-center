<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

final class InsightPagesHttpTest extends TestCase
{
    public function testTrendGapsAndUndefinedRatesStayUnavailable(): void
    {
        $s = new Scenario();
        $p = $s->acmeProject;
        $store = $s->container->make(InMemorySearchPerformance::class);
        $store->upsertDailyTotals($p->id, [new DailyMetrics('2026-10-01', new Metrics(7, 100, 4)),new DailyMetrics('2026-10-03', new Metrics(0, 0, 0)),new DailyMetrics('2026-10-05', new Metrics(9, 120, 3))]);
        $links = $s->container->make(InMemoryIntegrations::class);
        $connection = $links->upsert($s->acme->id, 'google', 'owner@acme.test', 'webmasters.readonly', $s->owner->id);
        $links->link($p->id, $connection->id, 'sc-domain:shop.acme.test', $s->owner->id);
        $response = $s->loggedIn($s->owner)->get('/projects/' . $p->publicId . '/performance');
        self::assertSame(200, $response->getStatusCode());
        $dom = new \DOMDocument();
        @$dom->loadHTML((string) $response->getBody());
        $xpath = new \DOMXPath($dom);
        $svgs = $xpath->query('//div[@data-insight-panel="ctr"]//svg[@data-points]');
        self::assertNotFalse($svgs);
        self::assertSame(1, $svgs->length);
        $svg = $svgs->item(0);
        self::assertInstanceOf(\DOMElement::class, $svg);
        $points = json_decode($svg->getAttribute('data-points'), true, flags: JSON_THROW_ON_ERROR);
        $byDate = array_column($points, 3, 2);
        self::assertNull($byDate['2026-10-02']);
        self::assertNull($byDate['2026-10-03'], 'Zero impressions do not define CTR.');
        self::assertEqualsWithDelta(7, $byDate['2026-10-01'], 0.00001);
        $paths = $xpath->query('//div[@data-insight-panel="clicks"]//path[@class="line"]');
        self::assertNotFalse($paths);
        $line = $paths->item(0);
        self::assertInstanceOf(\DOMElement::class, $line);
        $path = $line->getAttribute('d');
        self::assertSame(3, substr_count($path, 'M'), 'Missing days interrupt the line.');
        self::assertStringNotContainsString($s->rivalProject->name, (string) $response->getBody());
    }
}
