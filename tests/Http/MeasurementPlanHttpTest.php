<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\Annotations\MeasurementPlanService;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMeasurementPlans;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/** Measurement plans on the change-impact page (ADR-042, 0.39.0). */
final class MeasurementPlanHttpTest extends TestCase
{
    private const string TARGET = 'https://shop.acme.test/guide';
    private const string CONTROL = 'https://shop.acme.test/other-guide';

    public function testAPlanStaysPendingUntilItsDataIsInThenReadsAgainstControls(): void
    {
        $s = new Scenario();
        $date = $s->container->make(Clock::class)->now()->modify('-40 days')->format('Y-m-d');
        $note = $s->container->make(AnnotationRepository::class)->save($s->acmeProject, new AnnotationInput($date, 'title', 'New title', '', self::TARGET), $s->owner->id, null);
        $page = '/projects/' . $s->acmeProject->publicId . '/annotations/' . $note->publicId . '/impact';
        $owner = $s->loggedIn($s->owner);
        $html = (string) $owner->get($page)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('No plan yet.', $html);
        self::assertStringContainsString(self::TARGET . '</textarea>', $html, 'The change address is the default target.');

        $valid = ['objective' => 'More clicks from <b>guide</b> searches', 'metric' => 'clicks', 'days' => '14', 'target_pages' => self::TARGET, 'target_queries' => '', 'control_pages' => self::CONTROL, 'review_dates' => '', 'version' => '0'];
        foreach ([['control_pages' => self::TARGET], ['target_pages' => 'https://rival.test/guide'], ['metric' => 'sessions', 'target_queries' => 'guide'], ['days' => '30'], ['objective' => 'x'], ['review_dates' => '2001-01-01']] as $change) {
            $owner->get($page);
            self::assertSame(422, $owner->post(substr($page, 0, -7) . '/plan', [...$valid, ...$change])->getStatusCode(), (string) json_encode($change));
        }
        $owner->get($page);
        self::assertSame(303, $owner->post(substr($page, 0, -7) . '/plan', $valid)->getStatusCode());
        $plans = $s->container->make(InMemoryMeasurementPlans::class);
        $plan = $plans->find($s->acmeProject, $note->publicId);
        self::assertNotNull($plan);
        self::assertCount(2, $plan['review_dates'], 'Default reviews: halfway and after the last day is imported.');
        self::assertContains('measurement_plan.saved', $s->audit()->actions());
        $html = (string) $owner->get($page)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('More clicks from &lt;b&gt;guide&lt;/b&gt; searches', $html);
        self::assertStringContainsString('Only 0 of 14 days after the change are imported so far.', $html);

        // Data arrives: both periods imported for the targets and the controls.
        $service = $s->container->make(MeasurementPlanService::class);
        $before = $service->before($date, 14);
        $after = $service->after($date, 14);
        $pages = $s->container->make(InMemoryPages::class);
        $pages->importDays[$s->acmeProject->id] = [...$before->eachDay(), ...$after->eachDay()];
        $pages->periodPages[$s->acmeProject->id][$before->fromString()] = [new PageSummary(self::TARGET, new Metrics(100, 2000, 8.0), null), new PageSummary(self::CONTROL, new Metrics(200, 4000, 6.0), null)];
        $pages->periodPages[$s->acmeProject->id][$after->fromString()] = [new PageSummary(self::TARGET, new Metrics(130, 2400, 7.0), null), new PageSummary(self::CONTROL, new Metrics(210, 4100, 6.0), null)];
        $performance = $s->container->make(InMemorySearchPerformance::class);
        $performance->daily[$s->acmeProject->id][$s->container->make(Clock::class)->now()->modify('-3 days')->format('Y-m-d')] = new Metrics(10, 100, 5.0);

        $owner->get($page);
        self::assertSame(409, $owner->post(substr($page, 0, -7) . '/plan/freeze', ['version' => '0'])->getStatusCode(), 'A stale version is refused.');
        $owner->get($page);
        self::assertSame(303, $owner->post(substr($page, 0, -7) . '/plan/freeze', ['version' => '1'])->getStatusCode());
        $plan = $plans->find($s->acmeProject, $note->publicId);
        self::assertSame(14, $plan['baseline']['targets']['google_days'] ?? null);
        $read = $service->plan($s->acmeProject, $note->publicId)['result'];
        self::assertNotNull($read);
        self::assertSame(['improved', 'controlled', 25.0], [$read['status'], $read['attribution'], $read['effect']], 'Targets +30 %, controls +5 %.');
        $html = (string) $owner->get($page)->getBody();
        self::assertStringContainsString('Improved · controlled', $html);
        self::assertStringContainsString('Effect beyond the control pages: +25.0 percentage points', $html);

        // Another setup no longer matches the frozen baseline.
        $owner->get($page);
        self::assertSame(303, $owner->post(substr($page, 0, -7) . '/plan', [...$valid, 'control_pages' => '', 'version' => '2'])->getStatusCode());
        self::assertNull($plans->find($s->acmeProject, $note->publicId)['baseline'] ?? null);
        $owner->get($page);
        self::assertSame(409, $owner->post(substr($page, 0, -7) . '/plan', [...$valid, 'version' => '2'])->getStatusCode());

        $viewer = $s->loggedIn($s->viewer);
        $html = (string) $viewer->get($page)->getBody();
        self::assertStringNotContainsString('Save plan', $html);
        self::assertStringNotContainsString('Freeze baseline', $html);
        $viewer->get($page);
        self::assertSame(403, $viewer->post(substr($page, 0, -7) . '/plan', [...$valid, 'version' => '3'])->getStatusCode());
    }

    public function testANewPageGetsAnAfterOnlyGoal(): void
    {
        $s = new Scenario();
        $date = $s->container->make(Clock::class)->now()->modify('-40 days')->format('Y-m-d');
        $note = $s->container->make(AnnotationRepository::class)->save($s->acmeProject, new AnnotationInput($date, 'new_page', 'New guide', '', self::TARGET), $s->owner->id, null);
        $base = '/projects/' . $s->acmeProject->publicId . '/annotations/' . $note->publicId;
        $owner = $s->loggedIn($s->owner);
        $owner->get($base . '/impact');
        self::assertSame(422, $owner->post($base . '/plan', ['objective' => 'Get the guide seen', 'metric' => 'ctr', 'days' => '14', 'target_pages' => self::TARGET, 'goal' => '120', 'version' => '0'])->getStatusCode(), 'A CTR goal is a percentage.');
        $owner->get($base . '/impact');
        self::assertSame(303, $owner->post($base . '/plan', ['objective' => 'Get the guide seen', 'metric' => 'impressions', 'days' => '14', 'target_pages' => self::TARGET, 'goal' => '500', 'version' => '0'])->getStatusCode());
        $service = $s->container->make(MeasurementPlanService::class);
        $after = $service->after($date, 14);
        $pages = $s->container->make(InMemoryPages::class);
        $pages->importDays[$s->acmeProject->id] = $after->eachDay();
        $pages->periodPages[$s->acmeProject->id][$after->fromString()] = [new PageSummary(self::TARGET, new Metrics(12, 640, 9.0), null)];
        $s->container->make(InMemorySearchPerformance::class)->daily[$s->acmeProject->id][$s->container->make(Clock::class)->now()->modify('-3 days')->format('Y-m-d')] = new Metrics(10, 100, 5.0);
        $html = (string) $owner->get($base . '/impact')->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('goal: at least 500 in the 14 days after the change (no baseline needed)', $html);
        self::assertStringContainsString('Goal reached', $html);
        self::assertStringContainsString('Measured 640 against the goal of 500.', $html);
    }

    public function testWhatWorkedGroupsMeasuredResultsAndListsWhatIsMissing(): void
    {
        // 0.45.0: the results ledger reads each plan's result behind every gate.
        $s = new Scenario();
        $clock = $s->container->make(Clock::class);
        $notes = $s->container->make(AnnotationRepository::class);
        $date = $clock->now()->modify('-40 days')->format('Y-m-d');
        $measured = $notes->save($s->acmeProject, new AnnotationInput($date, 'title', 'New guide title', '', self::TARGET), $s->owner->id, null);
        $unfrozen = $notes->save($s->acmeProject, new AnnotationInput($clock->now()->modify('-30 days')->format('Y-m-d'), 'links', 'Links to the guide', '', self::CONTROL), $s->owner->id, null);
        $notes->save($s->acmeProject, new AnnotationInput($clock->now()->modify('-10 days')->format('Y-m-d'), 'content', 'Rewrote the FAQ', '', 'https://shop.acme.test/faq'), $s->owner->id, null);
        $notes->save($s->acmeProject, new AnnotationInput($clock->now()->modify('-5 days')->format('Y-m-d'), 'release', 'Site release', '', null), $s->owner->id, null);
        $owner = $s->loggedIn($s->owner);
        $results = '/projects/' . $s->acmeProject->publicId . '/annotations/results';
        self::assertStringContainsString('No measured changes yet', (string) $owner->get($results)->getBody());

        $plan = static fn(string $id, string $controls): array => ['objective' => 'More clicks from guide searches', 'metric' => 'clicks', 'days' => '14', 'target_pages' => self::TARGET, 'target_queries' => '', 'control_pages' => $controls, 'review_dates' => '', 'version' => '0'];
        foreach ([[$measured->publicId, self::CONTROL], [$unfrozen->publicId, '']] as [$id, $controls]) {
            $owner->get('/projects/' . $s->acmeProject->publicId . '/annotations/' . $id . '/impact');
            self::assertSame(303, $owner->post('/projects/' . $s->acmeProject->publicId . '/annotations/' . $id . '/plan', $plan($id, $controls))->getStatusCode());
        }
        $service = $s->container->make(MeasurementPlanService::class);
        $before = $service->before($date, 14);
        $after = $service->after($date, 14);
        $pages = $s->container->make(InMemoryPages::class);
        $pages->importDays[$s->acmeProject->id] = [...$before->eachDay(), ...$after->eachDay()];
        $pages->periodPages[$s->acmeProject->id][$before->fromString()] = [new PageSummary(self::TARGET, new Metrics(100, 2000, 8.0), null), new PageSummary(self::CONTROL, new Metrics(200, 4000, 6.0), null)];
        $pages->periodPages[$s->acmeProject->id][$after->fromString()] = [new PageSummary(self::TARGET, new Metrics(130, 2400, 7.0), null), new PageSummary(self::CONTROL, new Metrics(210, 4100, 6.0), null)];
        $s->container->make(InMemorySearchPerformance::class)->daily[$s->acmeProject->id][$clock->now()->modify('-3 days')->format('Y-m-d')] = new Metrics(10, 100, 5.0);
        $owner->get('/projects/' . $s->acmeProject->publicId . '/annotations/' . $measured->publicId . '/impact');
        self::assertSame(303, $owner->post('/projects/' . $s->acmeProject->publicId . '/annotations/' . $measured->publicId . '/plan/freeze', ['version' => '1'])->getStatusCode());
        // The measured change was deployed from a bundle started on a low-CTR finding.
        $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryChangeBundles::class)->create($s->acmeProject, '0192f0a0-0000-7000-8000-00000000b001', 'Guide title', self::TARGET, 'deployed', ['annotation_id' => $measured->publicId, 'source' => ['opportunity_id' => 'x', 'playbook' => 'low_ctr', 'metric' => 'ctr']], $s->owner->id, '2026-09-01 10:00:00');

        $html = (string) $owner->get($results)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertMatchesRegularExpression('#<td>Title / snippet <span class="muted small">too few to show a pattern</span></td><td class="num">1 <span class="muted small">\(1 controlled\)</span></td><td class="num">1</td><td class="num">0</td><td class="num">0</td><td class="num">\+25 %</td>#', $html);
        self::assertStringContainsString('<td>Earn more clicks from the rankings you already have', $html, 'grouped by the bundle’s action plan too');
        self::assertStringContainsString('<span class="badge badge-ok">Improved</span> <span class="muted small">controlled</span>', $html);
        self::assertStringContainsString('Freeze the baseline', $html);
        self::assertStringContainsString('>Links to the guide</a>', $html);
        self::assertStringContainsString('Changes on a page without a plan', $html);
        self::assertStringContainsString('>Rewrote the FAQ</a>', $html);
        self::assertStringNotContainsString('>Site release</a>', $html, 'a site-wide change has no page to measure');

        $ledger = $s->container->make(\SeoControlCenter\Modules\Annotations\ResultsLedgerService::class);
        $own = $ledger->forPlaybook($s->acmeProject, 'low_ctr');
        self::assertNotNull($own);
        self::assertSame('On your site: 1 of 1 measured change improved, median +25 %; too few to show a pattern yet.', \SeoControlCenter\Domain\Measurement\ResultsLedger::sentence($own));
        self::assertNull($ledger->forPlaybook($s->acmeProject, 'quick_win'));

        self::assertSame(200, $s->loggedIn($s->viewer)->get($results)->getStatusCode(), 'viewers read the results');
        self::assertSame(404, $s->loggedIn($s->rivalOwner)->get($results)->getStatusCode());
    }

    public function testTheBaselineCannotBeFrozenBeforeTheChange(): void
    {
        $s = new Scenario();
        $date = $s->container->make(Clock::class)->now()->modify('+5 days')->format('Y-m-d');
        $note = $s->container->make(AnnotationRepository::class)->save($s->acmeProject, new AnnotationInput($date, 'content', 'Planned rewrite', '', self::TARGET), $s->owner->id, null);
        $base = '/projects/' . $s->acmeProject->publicId . '/annotations/' . $note->publicId;
        $owner = $s->loggedIn($s->owner);
        $owner->get($base . '/impact');
        $owner->post($base . '/plan', ['objective' => 'Rank for the rewrite', 'metric' => 'impressions', 'days' => '28', 'target_pages' => self::TARGET, 'version' => '0']);
        self::assertStringContainsString('Planned', (string) $owner->get($base . '/impact')->getBody());
        self::assertSame(409, $owner->post($base . '/plan/freeze', ['version' => '1'])->getStatusCode());
    }

    public function testPastChangesGetPlansInBatchesAndAreMarkedAsMeasuredAfterwards(): void
    {
        $s = new Scenario();
        $clock = $s->container->make(Clock::class);
        $notes = $s->container->make(AnnotationRepository::class);
        $old = $notes->save($s->acmeProject, new AnnotationInput($clock->now()->modify('-40 days')->format('Y-m-d'), 'title', 'Old title change', '', self::TARGET), $s->owner->id, null);
        $notes->save($s->acmeProject, new AnnotationInput($clock->now()->modify('-20 days')->format('Y-m-d'), 'content', 'Same page later', '', self::TARGET), $s->owner->id, null);
        $notes->save($s->acmeProject, new AnnotationInput($clock->now()->modify('-5 days')->format('Y-m-d'), 'release', 'Site release', '', null), $s->owner->id, null);
        $tooOld = $notes->save($s->acmeProject, new AnnotationInput($clock->now()->modify('-400 days')->format('Y-m-d'), 'content', 'Too old', '', self::CONTROL), $s->owner->id, null);
        $recent = $notes->save($s->acmeProject, new AnnotationInput($clock->now()->format('Y-m-d'), 'links', 'Today', '', self::CONTROL), $s->owner->id, null);
        $results = '/projects/' . $s->acmeProject->publicId . '/annotations/results';
        $owner = $s->loggedIn($s->owner);

        $html = (string) $owner->get($results)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('3 page changes from the last year have no measurement plan', $html, 'neither site-wide nor older than a year');
        self::assertStringNotContainsString('Measure past changes', (string) $s->loggedIn($s->viewer)->get($results)->getBody(), 'only for people who can edit');

        $viewer = $s->loggedIn($s->viewer);
        $viewer->get($results);
        self::assertSame(403, $viewer->post($results . '/measure-past')->getStatusCode());

        $owner->get($results);
        self::assertSame(303, $owner->post($results . '/measure-past')->getStatusCode());
        $html = (string) $owner->get($results)->getBody();
        self::assertStringContainsString('3 past changes measured.', $html);
        self::assertStringNotContainsString('have no measurement plan', $html);

        $plans = $s->container->make(InMemoryMeasurementPlans::class);
        $plan = $plans->find($s->acmeProject, $old->publicId);
        self::assertNotNull($plan);
        self::assertSame(['ctr', [self::TARGET], 28], [$plan['metric'], $plan['target_pages'], $plan['days']]);
        self::assertNotNull($plan['baseline']);
        self::assertNull($plans->find($s->acmeProject, $tooOld->publicId));

        $ledger = $s->container->make(\SeoControlCenter\Modules\Annotations\ResultsLedgerService::class);
        $byTitle = [];
        foreach ($ledger->build($s->acmeProject)['rows'] as $row) {
            $byTitle[$row['annotation']->title] = $row;
        }
        self::assertTrue($byTitle['Old title change']['afterwards'], 'frozen 40 days after the change');
        self::assertFalse($byTitle['Today']['afterwards'], 'frozen on the day of the change');
        self::assertGreaterThan(0, $byTitle['Old title change']['overlapping'], 'another change to the same page falls inside its period');
        self::assertSame(['measured' => 0, 'failed' => 0, 'left' => 0], $ledger->measurePast($s->owner, $s->acmeProject, \SeoControlCenter\Core\Support\ClientInfo::cli()), 'running it again changes nothing');
        self::assertNotNull($plans->find($s->acmeProject, $recent->publicId));
        $impact = (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/annotations/' . $old->publicId . '/impact')->getBody();
        self::assertStringContainsString('Measured afterwards: this baseline was frozen after the change', $impact);
        self::assertStringContainsString('measured afterwards</span>', (string) $owner->get($results)->getBody());

        // One click handles a batch; the rest stay for the next one.
        foreach (range(1, \SeoControlCenter\Modules\Annotations\ResultsLedgerService::PAST_BATCH + 1) as $i) {
            $notes->save($s->acmeProject, new AnnotationInput($clock->now()->modify('-3 days')->format('Y-m-d'), 'content', 'Batch ' . $i, '', 'https://shop.acme.test/batch-' . $i), $s->owner->id, null);
        }
        self::assertSame(['measured' => 40, 'failed' => 0, 'left' => 1], $ledger->measurePast($s->owner, $s->acmeProject, \SeoControlCenter\Core\Support\ClientInfo::cli()));
        self::assertSame(['measured' => 1, 'failed' => 0, 'left' => 0], $ledger->measurePast($s->owner, $s->acmeProject, \SeoControlCenter\Core\Support\ClientInfo::cli()));
    }
}
