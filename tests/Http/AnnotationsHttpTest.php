<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Tests\Support\Scenario;

final class AnnotationsHttpTest extends TestCase
{
    public function testValidationEscapingFilteringAndPagination(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $path = '/projects/' . $s->acmeProject->publicId . '/annotations';
        $browser->get($path);
        foreach ([['date' => "2026-01\0-01"], ['date' => '2026-02-30'], ['date' => '2099-01-01'], ['url' => 'https://foreign.test/'], ['url' => 'https://shop.acme.test:443/'], ['url' => 'https://user@shop.acme.test/'], ['kind' => 'made-up'], ['title' => str_repeat('x', 121)], ['note' => str_repeat('x', 2001)]] as $bad) {
            $response = $browser->post($path, $bad + ['date' => '2026-01-01', 'kind' => 'content', 'title' => 'New content']);
            self::assertSame(422, $response->getStatusCode());
        }
        self::assertSame(303, $browser->post($path, ['date' => '2026-01-01', 'kind' => 'content', 'title' => '<script>alert(1)</script>', 'note' => '<img src=x onerror=alert(1)>', 'url' => 'https://shop.acme.test/Upper?x=One'])->getStatusCode());
        $html = (string) $browser->get($path)->getBody();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;img src=x', $html);
        $repo = $s->container->make(AnnotationRepository::class);
        $repo->save($s->acmeProject, new AnnotationInput('2026-01-02', 'release', 'Site-wide release', '', null), $s->owner->id, null);
        $range = new DateRange('2026-01-01', '2026-01-03');
        self::assertCount(2, $repo->list($s->acmeProject, $range, 'https://shop.acme.test/Upper?x=One', 100, 0));
        self::assertCount(1, $repo->list($s->acmeProject, $range, 'https://shop.acme.test/upper?x=One', 100, 0));
        self::assertCount(1, $repo->list($s->acmeProject, $range, 'https://shop.acme.test/Upper?x=one', 100, 0));
        self::assertSame(422, $browser->get($path . '?from=bad&to=2026-01-01')->getStatusCode());
        for ($i = 0; $i < 26; ++$i) {
            $repo->save($s->acmeProject, new AnnotationInput('2026-01-03', 'links', 'Link ' . $i, '', null), $s->owner->id, null);
        }
        $page = $s->container->make(AnnotationService::class)->listing($s->acmeProject, page: 2);
        self::assertCount(3, $page['items']);
        self::assertSame(28, $page['total']);
        self::assertStringContainsString('Annotation pages', (string) $browser->get($path)->getBody());
    }

    public function testTimelineShowsCountersCountdownAndMeasuredEarlyRead(): void
    {
        $clock = new \SeoControlCenter\Core\Support\FrozenClock(new \DateTimeImmutable('2026-10-11 09:00:00 UTC'));
        $s = new Scenario(clock: $clock);
        $repo = $s->container->make(AnnotationRepository::class);
        $repo->save($s->acmeProject, new AnnotationInput('2026-09-20', 'title', 'New homepage title', '', null), $s->owner->id, null);
        $repo->save($s->acmeProject, new AnnotationInput('2026-10-09', 'content', 'Expanded guide', '', 'https://shop.acme.test/guide'), $s->owner->id, null);
        $repo->save($s->acmeProject, new AnnotationInput('2026-05-01', 'release', 'Old release', '', null), $s->owner->id, null);
        $days = [];
        for ($day = new \DateTimeImmutable('2026-08-25'); $day->format('Y-m-d') <= '2026-10-08'; $day = $day->modify('+1 day')) {
            $days[] = new \SeoControlCenter\Domain\SearchConsole\DailyMetrics($day->format('Y-m-d'), new \SeoControlCenter\Domain\SearchConsole\Metrics($day->format('Y-m-d') > '2026-09-20' ? 12 : 10, 100, 5.0));
        }
        $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class)->upsertDailyTotals($s->acmeProject->id, $days);

        $html = (string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/annotations')->getBody();
        self::assertMatchesRegularExpression('#Recorded changes</span><strong>3</strong>#', $html);
        self::assertMatchesRegularExpression('#Waiting for recrawl</span><strong>1</strong>#', $html);
        self::assertMatchesRegularExpression('#In the effect window</span><strong>1</strong>#', $html);
        self::assertStringContainsString('1 older changes settled', $html);
        // The title change: day 21 of 29, early read over 18 complete days on each side (data ends 2026-10-08).
        self::assertStringContainsString('<strong>Day 21</strong> of 29', $html);
        self::assertStringContainsString('Full comparison in 8 days', $html);
        self::assertStringContainsString('Early read · 18 days before vs after, whole site', $html);
        self::assertStringContainsString('trend-up">Clicks +20.0 %', $html);
        self::assertStringContainsString('trend-flat">Impressions ±0.0 %', $html);
        // The content change two days ago waits for a recrawl and has no early read yet.
        self::assertStringContainsString('Signal expected in 12 days', $html);
        self::assertStringContainsString('Early read after 7 days of imported Google data', $html);
        self::assertStringContainsString('Recorded 163 days ago', $html);
        self::assertStringContainsString('October 2026', $html);
        self::assertStringContainsString('When to expect results', $html);
    }

    public function testPageDetailsIncludeExactPageAndSiteWideChangesOnly(): void
    {
        $s = new Scenario();
        $url = 'https://shop.acme.test/Guide';
        $date = $s->container->make(Clock::class)->now()->format('Y-m-d');
        $pages = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryPages::class);
        $pages->pages[$s->acmeProject->id][] = new \SeoControlCenter\Modules\Pages\PageSummary($url, null, null);
        $repo = $s->container->make(AnnotationRepository::class);
        foreach ([$url => 'Page edit', 'https://shop.acme.test/guide' => 'Other case', '' => 'Site-wide release'] as $target => $title) {
            $repo->save($s->acmeProject, new AnnotationInput($date, 'content', $title, '', $target === '' ? null : $target), $s->owner->id, null);
        }
        $details = $s->container->make(\SeoControlCenter\Modules\Pages\PageService::class)->details($s->acmeProject, $url);
        self::assertEqualsCanonicalizing(['Page edit', 'Site-wide release'], array_column($details['annotations'], 'title'));
        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId . '/pages/details?url=' . rawurlencode($url))->getBody();
        self::assertStringContainsString('Page edit', $html);
        self::assertStringNotContainsString('Other case', $html);
        self::assertStringContainsString('Record or review changes to this page', $html);

        // Changes and their results (0.47.0): only this exact URL, each with its plan state.
        self::assertSame([['Page edit', 'no_plan', 'No measurement plan']], array_map(static fn(array $r): array => [$r['annotation']['title'], $r['status'], $r['label']], $details['change_results']));
        self::assertMatchesRegularExpression('#/impact">Page edit</a></td><td>—</td><td><span class="badge badge-warning">No measurement plan</span>#', $html);
    }

    public function testChartsShowMarkersWithoutChangingMetrics(): void
    {
        $s = new Scenario();
        $clock = $s->container->make(Clock::class);
        $date = $clock->now()->modify('-5 days')->format('Y-m-d');
        $s->container->make(AnnotationRepository::class)->save($s->acmeProject, new AnnotationInput($date, 'title', 'Title improved', '', null), $s->owner->id, null);
        $performance = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class);
        $performance->upsertDailyTotals($s->acmeProject->id, [new \SeoControlCenter\Domain\SearchConsole\DailyMetrics($date, new \SeoControlCenter\Domain\SearchConsole\Metrics(40, 500, 6.0))]);
        $integrations = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations::class);
        $connection = $integrations->upsert($s->acme->id, 'google', 'owner@acme.test', 'webmasters.readonly', $s->owner->id);
        $integrations->link($s->acmeProject->id, $connection->id, 'sc-domain:shop.acme.test', $s->owner->id);
        $browser = $s->loggedIn($s->owner);
        foreach (['', '/performance'] as $suffix) {
            $response = $browser->get('/projects/' . $s->acmeProject->publicId . $suffix);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('annotation-marker', (string) $response->getBody());
            self::assertStringContainsString('Title improved', (string) $response->getBody());
            self::assertStringContainsString('not proof of causation', (string) $response->getBody());
        }
        self::assertSame(40, $performance->totals($s->acmeProject->id, new DateRange($date, $date))->clicks);
    }

    public function testANewPageChangeIsMeasuredUnlessTheBoxIsUnticked(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $path = '/projects/' . $s->acmeProject->publicId . '/annotations';
        $form = (string) $browser->get($path)->getBody();
        self::assertMatchesRegularExpression('#<input type="checkbox" name="measure" value="1" checked> Measure this change#', $form, 'ticked by default');
        $today = $s->container->make(Clock::class)->now()->format('Y-m-d');
        $plans = $s->container->make(\SeoControlCenter\Modules\Annotations\MeasurementPlanRepository::class);
        $latest = static fn(string $title): \SeoControlCenter\Modules\Annotations\Annotation => array_values(array_filter($s->container->make(AnnotationRepository::class)->list($s->acmeProject, null, null, 100, 0), static fn($a): bool => $a->title === $title))[0];

        self::assertSame(303, $browser->post($path, ['date' => $today, 'kind' => 'title', 'title' => 'Shorter strawberry title', 'url' => 'https://shop.acme.test/tastes/strawberry', 'measure' => '1'])->getStatusCode());
        $plan = $plans->find($s->acmeProject, $latest('Shorter strawberry title')->publicId);
        self::assertNotNull($plan);
        self::assertSame(['ctr', ['https://shop.acme.test/tastes/strawberry'], [], 28, 'More ctr after “Shorter strawberry title”'], [$plan['metric'], $plan['target_pages'], $plan['control_pages'], $plan['days'], $plan['objective']]);
        self::assertNotNull($plan['baseline'], 'the baseline is frozen at once');
        self::assertNotNull($plan['frozen_at']);
        self::assertStringContainsString('A measurement plan was drafted and its baseline frozen (CTR, 28 days before and after)', (string) $browser->get($path)->getBody());

        $browser->post($path, ['date' => $today, 'kind' => 'content', 'title' => 'Unmeasured edit', 'url' => 'https://shop.acme.test/tastes/grape']);
        self::assertNull($plans->find($s->acmeProject, $latest('Unmeasured edit')->publicId), 'unticked: no plan');
        $browser->post($path, ['date' => $today, 'kind' => 'release', 'title' => 'Site-wide release', 'url' => '', 'measure' => '1']);
        self::assertNull($plans->find($s->acmeProject, $latest('Site-wide release')->publicId), 'a site-wide change has no page to measure');

        $id = $latest('Unmeasured edit')->publicId;
        $browser->get($path . '/' . $id . '/edit');
        $browser->post($path . '/' . $id, ['date' => $today, 'kind' => 'content', 'title' => 'Unmeasured edit', 'url' => 'https://shop.acme.test/tastes/grape', 'measure' => '1']);
        self::assertNull($plans->find($s->acmeProject, $id), 'editing a change never drafts a plan');
        $actions = array_map(static fn($e): string => $e->action, $s->audit()->events);
        self::assertSame(1, count(array_filter($actions, static fn(string $a): bool => $a === 'measurement_plan.baseline_frozen')));
    }
}
