<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMeasurementPlans;
use SeoControlCenter\Tests\Support\Scenario;

/** Measurement plans (ADR-042) stay inside their project and workspace. */
final class MeasurementPlanIsolationTest extends TestCase
{
    public function testForeignChangesAndTheirPlansAreNotFound(): void
    {
        $s = new Scenario();
        $repo = $s->container->make(AnnotationRepository::class);
        $rival = $repo->save($s->rivalProject, new AnnotationInput('2026-01-01', 'title', 'Rival change', '', null), $s->rival->id ?? $s->owner->id, null);
        $own = $repo->save($s->acmeProject, new AnnotationInput('2026-01-01', 'title', 'Own change', '', null), $s->owner->id, null);
        $plans = $s->container->make(InMemoryMeasurementPlans::class);
        $plans->save($s->rivalProject, $rival->publicId, '0192f0a0-0000-7000-8000-000000000f01', ['objective' => 'Rival objective', 'metric' => 'clicks', 'target_pages' => [], 'target_queries' => [], 'control_pages' => [], 'days' => 28, 'goal' => null, 'review_dates' => [], 'timezone' => 'UTC'], 0, false, null, '2026-01-01 00:00:00');
        $member = $s->loggedIn($s->member);
        $input = ['objective' => 'Take over', 'metric' => 'clicks', 'days' => '28', 'version' => '1'];
        foreach ([[$s->rivalProject, $rival->publicId], [$s->acmeProject, $rival->publicId], [$s->acmeOther, $own->publicId]] as [$project, $id]) {
            $base = '/projects/' . $project->publicId . '/annotations/' . $id;
            self::assertSame(404, $member->get($base . '/impact')->getStatusCode());
            $member->get('/account');
            self::assertSame(404, $member->post($base . '/plan', $input)->getStatusCode());
            $member->get('/account');
            self::assertSame(404, $member->post($base . '/plan/freeze', ['version' => '1'])->getStatusCode());
        }
        self::assertSame('Rival objective', $plans->find($s->rivalProject, $rival->publicId)['objective'] ?? null);
        self::assertNull($plans->find($s->acmeProject, $rival->publicId));

        // 0.45.0: the results ledger of a foreign or unassigned project stays invisible, and never lists another project's changes.
        self::assertSame(404, $member->get('/projects/' . $s->rivalProject->publicId . '/annotations/results')->getStatusCode());
        self::assertSame(404, $member->get('/projects/' . $s->acmeOther->publicId . '/annotations/results')->getStatusCode());
        // 0.51.0: measuring past changes in a foreign or unassigned project is not found either.
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $member->get('/account');
            self::assertSame(404, $member->post('/projects/' . $project->publicId . '/annotations/results/measure-past')->getStatusCode());
        }
        self::assertSame([], $plans->plannedChanges($s->acmeProject, 10));
        self::assertStringNotContainsString('Rival', (string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/annotations/results')->getBody());
    }
}
