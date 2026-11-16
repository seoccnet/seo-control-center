<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Backlinks\PageBacklink;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Backlinks\BacklinksService;
use SeoControlCenter\Modules\Backlinks\LinkReclamationService;
use SeoControlCenter\Tests\Support\Fakes\FakeBacklinksProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBacklinkHistory;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLinkTargets;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteMigrations;
use SeoControlCenter\Tests\Support\Scenario;

/** Backlinks to missing pages with suggested redirects (1.5.0). */
final class LinkReclamationHttpTest extends TestCase
{
    public function testLinksToMissingPagesAreFoundAndMatched(): void
    {
        $s = new Scenario();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-11-16 08:00:00 UTC'));
        $s->container->instance(Clock::class, $clock);
        $provider = $s->container->get(FakeBacklinksProvider::class);
        $probe = $s->container->get(InMemorySiteMigrations::class);
        $history = $s->container->get(InMemoryBacklinkHistory::class);
        $targets = $s->container->get(InMemoryLinkTargets::class);
        $search = $s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(FakeBacklinksProvider::class, $provider);
        self::assertInstanceOf(InMemorySiteMigrations::class, $probe);
        self::assertInstanceOf(InMemoryBacklinkHistory::class, $history);
        self::assertInstanceOf(InMemoryLinkTargets::class, $targets);
        self::assertInstanceOf(InMemorySearchPerformance::class, $search);
        $project = $s->acmeProject;
        $site = 'https://shop.acme.test';
        $provider->links = [
            new PageBacklink('https://blog.test/a', $site . '/old-red-shoes', 'red shoes', 40, true, '2025-01-01', null),
            new PageBacklink('https://news.test/b', $site . '/old-red-shoes#top', 'shoes', 60, false, '2025-02-01', null),
            new PageBacklink('https://forum.test/c', $site . '/moved', 'moved', 10, true, '2025-03-01', null),
            new PageBacklink('https://blog.test/d', $site . '/fine', 'fine', 40, true, '2025-01-01', null),
            new PageBacklink('https://blog.test/e', 'https://other.test/page', 'elsewhere', 40, true, '2025-01-01', null),
        ];
        $probe->answers = [
            $site . '/moved' => ['status' => 301, 'location' => $site . '/gone-too', 'error' => null],
            $site . '/gone-too' => ['status' => 410, 'location' => null, 'error' => null],
            $site . '/fine' => ['status' => 200, 'location' => null, 'error' => null],
        ];
        $search->details[$project->id]['2026-10-01']['page'] = [new DimensionRow($site . '/shoes/red-shoes', new Metrics(10, 100, 3))];

        $owner = $s->loggedIn($s->owner);
        $owner->get('/projects/' . $project->publicId . '/backlinks');
        $owner->post('/projects/' . $project->publicId . '/backlinks/fetch', ['kind' => 'bl_links']);
        $backlinks = $s->container->get(BacklinksService::class);
        self::assertInstanceOf(BacklinksService::class, $backlinks);
        $backlinks->run($project->id, $project->workspaceId, 'bl_links', false);

        self::assertSame('targets', array_column($history->queued, 'kind')[count($history->queued) - 1] ?? null, 'Fetched links queue a check of the linked pages.');
        $reclaim = $s->container->get(LinkReclamationService::class);
        self::assertInstanceOf(LinkReclamationService::class, $reclaim);
        $reclaim->run($project->id, $project->workspaceId, static fn(): int => 600);
        self::assertSame('done', $targets->run($project->id)['state'] ?? null);
        self::assertNotContains('https://other.test/page', $probe->probed, 'Only the project site is requested.');

        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $project->publicId . '/backlinks/reclaim')->getBody();

        self::assertStringContainsString('3 of 3 linked pages checked', $html);
        self::assertMatchesRegularExpression('#/old-red-shoes</td>\s*<td>Not found \(404\)</td>\s*<td>2</td>\s*<td>2 \(1\)</td>\s*<td>60</td>#', $html);
        self::assertStringContainsString('https://shop.acme.test/shoes/red-shoes', $html, 'The live page with the same words is suggested.');
        self::assertStringContainsString('Redirects to a missing page (410)', $html);
        self::assertStringNotContainsString('/fine</td>', $html);
        self::assertStringNotContainsString('Check the linked pages again', $html, 'Viewers cannot start checks.');

        // Starting again is for members who may run analyses, and not while a check is queued.
        $owner->get('/projects/' . $project->publicId . '/backlinks/reclaim');
        self::assertSame(303, $owner->post('/projects/' . $project->publicId . '/backlinks/reclaim')->getStatusCode());
        self::assertSame('queued', $targets->run($project->id)['state'] ?? null);
        $owner->get('/projects/' . $project->publicId . '/backlinks/reclaim');
        $owner->post('/projects/' . $project->publicId . '/backlinks/reclaim');
        self::assertStringContainsString('already being checked', (string) $owner->get('/projects/' . $project->publicId . '/backlinks/reclaim')->getBody());
        $viewer = $s->loggedIn($s->viewer);
        $viewer->get('/account');
        self::assertSame(403, $viewer->post('/projects/' . $project->publicId . '/backlinks/reclaim', ['_csrf' => $viewer->csrfToken()])->getStatusCode());
    }

    public function testProblemsOfAnswers(): void
    {
        $answer = static fn(?int $status, ?int $final = null): array => ['status' => $status, 'location' => null, 'final_status' => $final, 'error' => null];
        self::assertSame('Not found (404)', LinkReclamationService::problem($answer(404)));
        self::assertSame('Gone (410)', LinkReclamationService::problem($answer(410)));
        self::assertSame('Error (500)', LinkReclamationService::problem($answer(500)));
        self::assertSame('Redirects to a missing page (404)', LinkReclamationService::problem($answer(301, 404)));
        self::assertNull(LinkReclamationService::problem($answer(301, 200)));
        self::assertNull(LinkReclamationService::problem($answer(301)), 'A redirect to another site counts.');
        self::assertNull(LinkReclamationService::problem($answer(200)));
        self::assertNull(LinkReclamationService::problem($answer(null)));
    }
}
