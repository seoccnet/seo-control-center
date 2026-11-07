<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The DataForSEO spending indicator in the top bar.
 */
final class SpendWidgetHttpTest extends TestCase
{
    public function testSpendingForecastBudgetAndBalance(): void
    {
        $s = new Scenario();
        $s->container->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-15 12:00:00', new \DateTimeZone('UTC'))));
        $store = $s->container->get(InMemoryDataForSeo::class);
        self::assertInstanceOf(InMemoryDataForSeo::class, $store);

        $owner = $s->loggedIn($s->owner);
        self::assertStringNotContainsString('DataForSEO spending', (string) $owner->get('/projects')->getBody(), 'not connected');

        $store->save($s->acme->id, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, $s->owner->id);
        $store->saveBudget($s->acme->id, 'dataforseo', new ApiBudget(5.0, 30.0), $s->owner->id);
        $row = static fn(string $operation, float $cost, string $at, string $status = 'charged'): array => ['workspace_id' => $s->acme->id, 'project_id' => null, 'provider' => 'dataforseo', 'operation' => $operation, 'status' => $status, 'estimated' => $cost, 'cost' => $status === 'charged' ? $cost : null, 'task_id' => null, 'created_at' => $at];
        $store->usage = [
            1 => $row('serp_organic', 1.0, '2026-09-20 10:00:00'),
            2 => $row('labs_ranked_keywords', 2.0, '2026-10-05 10:00:00'),
            3 => $row('backlinks_summary', 0.5, '2026-10-15 09:00:00'),
            4 => $row('backlinks_anchors', 9.0, '2026-10-15 09:30:00', 'released'),
            5 => ['workspace_id' => $s->rival->id] + $row('serp_organic', 50.0, '2026-10-15 09:00:00'),
        ];

        $page = (string) $owner->get('/projects')->getBody();
        self::assertStringContainsString('DataForSEO spending', $page);
        self::assertStringContainsString('<strong>$2.50</strong>', $page, 'this month; released requests and other workspaces do not count');
        self::assertStringContainsString('→ $5.36', $page, '2.50 + 2.50 / 14 days × 16 days left');
        self::assertStringContainsString('spend-fill w-10', $page, '2.50 of 30 → 8 %, shown in 5 % steps');
        self::assertStringContainsString('<dt>Last month</dt><dd>$1.00</dd>', $page);
        self::assertStringContainsString('$30.00 · $27.50 left', $page);
        self::assertStringContainsString('$5.00 · $4.50 left today', $page);
        self::assertStringContainsString('≈ 56 days at this pace', $page);
        self::assertMatchesRegularExpression('#<li><span>Keyword research</span><span>\$2.00</span></li>\s*<li><span>Backlinks</span><span>\$0.50</span></li>#', $page);
        self::assertStringContainsString('href="/integrations/dataforseo"', $page);
        self::assertStringContainsString('spend spend-ok', $page);

        $store->saveBudget($s->acme->id, 'dataforseo', new ApiBudget(null, 5.0), $s->owner->id);
        self::assertStringContainsString('spend spend-over', (string) $owner->get('/projects')->getBody(), 'the forecast passes the monthly limit');

        $viewer = $s->loggedIn($s->viewer);
        self::assertStringNotContainsString('DataForSEO spending', (string) $viewer->get('/projects')->getBody(), 'viewers cannot spend');
        $rival = $s->loggedIn($s->rivalOwner);
        self::assertStringNotContainsString('DataForSEO spending', (string) $rival->get('/projects')->getBody(), 'the rival has no DataForSEO account');
    }
}
