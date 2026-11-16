<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Modules\Competitors\CompetitorWatchService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitorWatch;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Scenario;

/** Competitor content watch (1.6.0). */
final class CompetitorWatchHttpTest extends TestCase
{
    public function testNewAndRemovedPagesMatchedToKeywords(): void
    {
        $s = new Scenario();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-16 08:00:00 UTC'));
        $s->container->instance(Clock::class, $clock);
        $watch = $s->container->get(InMemoryCompetitorWatch::class);
        $competitors = $s->container->get(InMemoryCompetitors::class);
        $keywords = $s->container->get(InMemoryKeywords::class);
        self::assertInstanceOf(InMemoryCompetitorWatch::class, $watch);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        $project = $s->acmeProject;
        $rival = $competitors->add($project->id, 'rival.test', Competitor::MANUAL, $s->owner->id);
        self::assertNotNull($rival);
        $keywords->add($project->id, ['red running shoes', 'blue shoes'], KeywordLocale::default(), $s->owner->id);
        $url = '/projects/' . $project->publicId . '/competitors/new-pages';

        $owner = $s->loggedIn($s->owner);
        $owner->get($url);
        self::assertSame(303, $owner->post($url . '/watch', ['enabled' => '1'])->getStatusCode());
        self::assertTrue($watch->enabled($project->id));
        self::assertSame([['competitorId' => $rival->id, 'projectId' => $project->id, 'workspaceId' => $project->workspaceId, 'domain' => 'rival.test']], $watch->queued, 'Turning it on reads every competitor right away.');
        self::assertContains('competitors.watch_enabled', $s->audit()->actions());

        $service = $s->container->get(CompetitorWatchService::class);
        self::assertInstanceOf(CompetitorWatchService::class, $service);
        $watch->sitemaps['rival.test'] = ['https://rival.test/', 'https://rival.test/old-page', 'https://rival.test/a', 'https://rival.test/b'];
        $service->check($rival->id, $project->id, $project->workspaceId, 'rival.test');
        self::assertSame([], $watch->changes($project->id, '2000-01-01', 10), 'The first read is the baseline.');

        $clock->advance('+1 day');
        $watch->sitemaps['rival.test'] = ['https://rival.test/', 'https://rival.test/a', 'https://rival.test/b', 'https://rival.test/blog/Best-Red-Running-Shoes-2026', 'https://rival.test/about-us'];
        $service->check($rival->id, $project->id, $project->workspaceId, 'rival.test');
        $service->check($rival->id, $project->id, $project->workspaceId, 'other.test');

        $html = (string) $s->loggedIn($s->viewer)->get($url)->getBody();
        self::assertMatchesRegularExpression('#rival.test</th>\s*<td>2026-11-17</td>\s*<td>5</td>\s*<td>2</td>\s*<td>1</td>#', $html);
        self::assertMatchesRegularExpression('#New</span></td>\s*<td class="url-cell">https://rival.test/blog/Best-Red-Running-Shoes-2026</td>\s*<td>red running shoes</td>#', $html);
        self::assertStringContainsString('Removed</span>', $html);
        self::assertStringNotContainsString('Stop watching', $html, 'Viewers cannot change the watch.');

        $matched = (string) $s->loggedIn($s->viewer)->get($url . '?matched=1')->getBody();
        self::assertStringContainsString('Best-Red-Running-Shoes-2026', $matched);
        self::assertStringNotContainsString('/about-us', $matched);

        // A read that loses more than half the pages marks nothing removed; an unreadable sitemap keeps the pages.
        $clock->advance('+1 day');
        $watch->sitemaps['rival.test'] = ['https://rival.test/'];
        $service->check($rival->id, $project->id, $project->workspaceId, 'rival.test');
        self::assertNull($watch->pages[$rival->id]['https://rival.test/a']['removed_on']);
        $clock->advance('+1 day');
        $watch->sitemaps['rival.test'] = 'Timed out';
        $service->check($rival->id, $project->id, $project->workspaceId, 'rival.test');
        self::assertSame('Timed out', $watch->states[$rival->id]['error']);

        $viewer = $s->loggedIn($s->viewer);
        $viewer->get('/account');
        self::assertSame(403, $viewer->post($url . '/watch', ['_csrf' => $viewer->csrfToken(), 'enabled' => '0'])->getStatusCode());
    }

    public function testKeywordMatching(): void
    {
        $keywords = ['red running shoes', 'shoes', 'uv 400 lens', 'a b'];
        self::assertSame(['red running shoes', 'shoes'], CompetitorWatchService::matches('https://x.test/shop/red_running-shoes.html', $keywords));
        self::assertSame(['uv 400 lens'], CompetitorWatchService::matches('https://x.test/uv-400-lens', $keywords));
        self::assertSame([], CompetitorWatchService::matches('https://x.test/', $keywords));
        self::assertSame([], CompetitorWatchService::matches('https://x.test/a-b', $keywords), 'Keywords of short words only never match.');
    }
}
