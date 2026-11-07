<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Analytics\Ga4EventReport;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\BusinessEventRepository;
use SeoControlCenter\Modules\Analytics\Ga4Repository;

/** ADR-042: business events, daily GA4 event counts and test checks on MySQL. */
final class BusinessEventStoreTest extends FullStackTestCase
{
    public function testEventsCountsFailuresChecksAndPropertyChange(): void
    {
        $owner = $this->createUser('events@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES ('0192f0a0-0000-7000-8000-000000000701', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$workspace]);
        $project = $this->db->lastInsertId();
        $repo = $this->app->make(BusinessEventRepository::class);

        $purchase = $repo->add($workspace, $project, 'purchase', 'purchase', 'Seed order', $owner['user']->id);
        $repo->add($workspace, $project, 'lead', 'generate_lead', 'Wholesale enquiry', null);
        self::assertSame(['purchase', 'generate_lead'], array_map(static fn($e): string => $e->eventName, $repo->events($project)));
        try {
            $repo->add($workspace, $project, 'other', 'purchase', 'Again', null);
            self::fail('One definition per GA4 event name.');
        } catch (\Throwable) {
        }

        self::assertNull($repo->eventImport($project));
        $range = new DateRange('2026-10-01', '2026-10-03');
        $rows = [];
        foreach ($range->eachDay() as $day) {
            $rows[] = ['date' => $day, 'event_name' => 'purchase', 'organic' => 1, 'total' => 2];
            $rows[] = ['date' => $day, 'event_name' => 'generate_lead', 'organic' => 0, 'total' => 0];
        }
        $repo->saveEventReport($project, $range, new Ga4EventReport($rows, 'Europe/Berlin', false, true));
        $counts = $repo->counts($project, $range);
        ksort($counts);
        self::assertSame(['generate_lead' => ['organic' => 0, 'total' => 0, 'days' => 3], 'purchase' => ['organic' => 3, 'total' => 6, 'days' => 3]], $counts, 'Zero days are stored, so a covered day is known.');
        self::assertSame(['organic' => 1, 'total' => 2, 'days' => 1], $repo->counts($project, new DateRange('2026-10-03', '2026-10-09'))['purchase']);
        $import = $repo->eventImport($project);
        self::assertSame(['2026-10-01', '2026-10-03', 'Europe/Berlin', false, true, null], [$import['from'] ?? null, $import['to'] ?? null, $import['timezone'] ?? null, $import['limited'] ?? null, $import['thresholded'] ?? null, $import['error'] ?? null]);

        $repo->recordEventFailure($project, 'HTTP 403');
        self::assertSame('HTTP 403', $repo->eventImport($project)['error'] ?? null);
        self::assertSame('2026-10-03', $repo->eventImport($project)['to'] ?? null, 'A failure keeps the last successful period.');

        $check = $repo->addCheck($workspace, $project, 'Test order #1', $owner['user']->id);
        self::assertSame('queued', $repo->check($check)['status'] ?? null);
        $repo->finishCheck($check, ['purchase' => 1, 'generate_lead' => 0], null);
        self::assertSame(['done', ['purchase' => 1, 'generate_lead' => 0]], [$repo->check($check)['status'] ?? null, $repo->check($check)['result'] ?? null]);
        self::assertCount(1, $repo->checks($project));

        self::assertTrue($repo->remove($project, $purchase->publicId));
        self::assertArrayNotHasKey('purchase', $repo->counts($project, $range), 'Removing an event removes its counts.');
        self::assertFalse($repo->remove($project, $purchase->publicId));

        $ga4 = $this->app->make(Ga4Repository::class);
        $ga4->link($project, '111');
        $ga4->link($project, '222');
        self::assertSame([], $repo->counts($project, $range), 'A new property starts a new event history.');
        self::assertNull($repo->eventImport($project));
    }
}
