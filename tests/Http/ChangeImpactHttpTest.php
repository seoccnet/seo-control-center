<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Modules\Annotations\ChangeImpactService;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Scenario;

final class ChangeImpactHttpTest extends TestCase
{
    public function testEqualWindowsCoverageClassificationAndZeroBaselines(): void
    {
        $s = new Scenario();
        $date = $s->container->make(Clock::class)->now()->modify('-40 days')->format('Y-m-d');
        $note = $s->container->make(AnnotationRepository::class)->save($s->acmeProject, new AnnotationInput($date, 'title', '<script>Title</script>', 'Demo note', 'https://shop.acme.test/Upper?x=One'), $s->owner->id, null);
        self::assertNotNull($note->url);
        $service = $s->container->make(ChangeImpactService::class);
        $report = $service->compare($s->acmeProject, $note->publicId, 14);
        $before = new DateRange($report['before']['from'], $report['before']['to']);
        $after = new DateRange($report['after']['from'], $report['after']['to']);
        self::assertSame(14, $before->days());
        self::assertSame(14, $after->days());
        self::assertFalse($before->contains($date));
        self::assertFalse($after->contains($date));
        self::assertSame('Insufficient data', $report['status']);
        $pages = $s->container->make(InMemoryPages::class);
        $pages->importDays[$s->acmeProject->id] = [...$before->eachDay(), ...$after->eachDay()];
        foreach ([[10,100,20,150,'Improved'], [20,150,10,100,'Declined'], [10,100,20,90,'Mixed'], [10,100,10,100,'Unchanged'], [0,100,10,100,'Improved']] as [$bc,$bi,$ac,$ai,$status]) {
            $pages->periodPages[$s->acmeProject->id][$before->fromString()] = [new PageSummary($note->url, new Metrics($bc, $bi, 8), null)];
            $pages->periodPages[$s->acmeProject->id][$after->fromString()] = [new PageSummary($note->url, new Metrics($ac, $ai, 5), null)];
            $report = $service->compare($s->acmeProject, $note->publicId, 14);
            self::assertSame($status, $report['status']);
            self::assertSame((float) ($ac - $bc), $report['google_changes']['clicks']['delta']);
            self::assertSame(-3.0, $report['google_changes']['position']['delta']);
        }
        self::assertNull($report['google_changes']['clicks']['percent']);
        array_pop($pages->importDays[$s->acmeProject->id]);
        self::assertSame('Insufficient data', $service->compare($s->acmeProject, $note->publicId, 14)['status']);
        $pages->importDays[$s->acmeProject->id] = [...$before->eachDay(), ...$after->eachDay()];
        $pages->periodPages[$s->acmeProject->id][$after->fromString()] = [new PageSummary(strtolower($note->url), new Metrics(100, 500, 1), null)];
        self::assertSame('Insufficient data', $service->compare($s->acmeProject, $note->publicId, 14)['status']);
        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId . '/annotations/' . $note->publicId . '/impact?days=14')->getBody();
        self::assertStringContainsString('&lt;script&gt;Title&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>Title', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));
    }

    public function testGa4CompleteZeroRowsAndQualityCurrencyGuards(): void
    {
        $s = new Scenario();
        $date = $s->container->make(Clock::class)->now()->modify('-100 days')->format('Y-m-d');
        $repo = $s->container->make(AnnotationRepository::class);
        $note = $repo->save($s->acmeProject, new AnnotationInput($date, 'content', 'Content update', '', 'https://shop.acme.test/Guide'), $s->owner->id, null);
        $service = $s->container->make(ChangeImpactService::class);
        $initial = $service->compare($s->acmeProject, $note->publicId);
        $before = new DateRange($initial['before']['from'], $initial['before']['to']);
        $after = new DateRange($initial['after']['from'], $initial['after']['to']);
        $ga4 = $s->container->make(Ga4Repository::class);
        $ga4->link($s->acmeProject->id, '123');
        foreach ([$before, $after] as $range) {
            $ga4->saveReport($s->acmeProject->id, $range, new Ga4Report([], 'USD', 'Europe/Berlin', false, false), '123');
        }
        $report = $service->compare($s->acmeProject, $note->publicId);
        self::assertTrue($report['analytics_changes']['revenue']['available']);
        self::assertSame(0.0, $report['analytics_changes']['revenue']['delta']);
        self::assertNull($report['analytics_changes']['revenue']['percent']);
        $ga4->saveReport($s->acmeProject->id, $after, new Ga4Report([], 'USD', 'Europe/Berlin', true, false), '123');
        self::assertFalse($service->compare($s->acmeProject, $note->publicId)['analytics_changes']['revenue']['available']);
        $ga4->saveReport($s->acmeProject->id, $after, new Ga4Report([], 'EUR', 'Europe/Berlin', false, false), '123');
        self::assertFalse($service->compare($s->acmeProject, $note->publicId)['analytics_changes']['revenue']['available']);
        $other = $repo->save($s->acmeProject, new AnnotationInput($date, 'release', 'Concurrent site release', '', null), $s->owner->id, null);
        $report = $service->compare($s->acmeProject, $note->publicId);
        self::assertSame($other->publicId, $report['other_changes'][0]['annotation_id']);
        $today = $s->container->make(Clock::class)->now()->format('Y-m-d');
        $recent = $repo->save($s->acmeProject, new AnnotationInput($today, 'content', 'Recent', '', null), $s->owner->id, null);
        self::assertFalse($service->compare($s->acmeProject, $recent->publicId)['elapsed']);
        self::assertFalse($service->compare($s->acmeProject, $other->publicId)['analytics_changes']['revenue']['available']);
    }

    public function testSiteWideDailyCoverageAndNinetyDayFutureGuard(): void
    {
        $s = new Scenario();
        $date = $s->container->make(Clock::class)->now()->modify('-40 days')->format('Y-m-d');
        $note = $s->container->make(AnnotationRepository::class)->save($s->acmeProject, new AnnotationInput($date, 'release', 'Site-wide', '', null), $s->owner->id, null);
        $service = $s->container->make(ChangeImpactService::class);
        $report = $service->compare($s->acmeProject, $note->publicId, 14);
        $google = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class);
        foreach (['before' => 10, 'after' => 20] as $period => $clicks) {
            $range = new DateRange($report[$period]['from'], $report[$period]['to']);
            foreach ($range->eachDay() as $day) {
                $google->daily[$s->acmeProject->id][$day] = new Metrics($clicks, 100, 5);
            }
        }
        $complete = $service->compare($s->acmeProject, $note->publicId, 14);
        self::assertSame('Improved', $complete['status']);
        self::assertSame(140.0, $complete['google_changes']['clicks']['delta']);
        self::assertEqualsWithDelta(100.0, $complete['google_changes']['clicks']['percent'], 0.0001);
        unset($google->daily[$s->acmeProject->id][$report['before']['from']]);
        self::assertSame('Insufficient data', $service->compare($s->acmeProject, $note->publicId, 14)['status']);
        $future = $service->compare($s->acmeProject, $note->publicId, 90);
        self::assertSame(90, $future['after']['expected_days']);
        self::assertFalse($future['elapsed']);
        self::assertFalse($future['google_changes']['clicks']['available']);
    }
}
