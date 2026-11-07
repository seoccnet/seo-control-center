<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\Reports\ReportScheduleRepository;

/**
 * Report schedules and deliveries against MySQL/MariaDB.
 */
final class ReportPersistenceTest extends FullStackTestCase
{
    public function testScheduleDeliveriesAndDeletedProjects(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $publicId = '0192f0a0-0000-7000-8000-000000000021';
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES (?, ?, 'Shop', 'shop.test', 'Europe/Berlin', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$publicId, $ws],
        );
        $project = $this->db->lastInsertId();
        $repo = $this->app->make(ReportScheduleRepository::class);
        self::assertTrue($repo->sections($project)->has('search'));
        $sections = new \SeoControlCenter\Modules\Reports\ReportSections(['rankings', 'analytics']);
        $repo->saveSections($project, $sections);
        self::assertEquals($sections, $repo->sections($project));
        self::assertTrue($repo->sections($project + 100)->has('search'));
        $brand = new \SeoControlCenter\Core\Branding\Branding('Acme Reports', '#336699', 'Prepared for Acme');
        $repo->saveBranding($ws, $brand);
        self::assertEquals($brand, $repo->branding($ws));
        self::assertNull($repo->branding($ws + 100)->productName);
        $repo->saveLogo($ws, "binary\0fixture", 'image/png');
        self::assertSame(['body' => "binary\0fixture", 'type' => 'image/png'], $repo->logo($ws));
        self::assertNull($repo->logo($ws + 100));
        $repo->saveLogo($ws, null, null);
        self::assertNull($repo->logo($ws));
        self::assertEquals($brand, $repo->branding($ws));

        $repo->save($project, $ws, 'weekly', ['client@shop.test'], true, $acme['user']->id);
        $repo->save($project, $ws, 'monthly', ['client@shop.test', 'team@shop.test'], true, $acme['user']->id);
        $schedule = $repo->find($project);
        self::assertNotNull($schedule);
        self::assertSame('monthly', $schedule->frequency);
        self::assertSame(['client@shop.test', 'team@shop.test'], $schedule->recipients);
        self::assertSame($publicId, $repo->enabled()[0]['projectPublicId'] ?? null);

        $repo->markSent($project, '2026-09-01..2026-09-30');
        $repo->logDelivery($project, '2026-09-01..2026-09-30', 'scheduled', 2, 1, 'SMTP said no');
        self::assertSame('2026-09-01..2026-09-30', $repo->find($project)?->lastPeriod);
        self::assertSame(['period' => '2026-09-01..2026-09-30', 'kind' => 'scheduled', 'recipients' => 2, 'failed' => 1, 'error' => 'SMTP said no'], array_diff_key($repo->deliveries($project, 5)[0], ['created_at' => true]));

        $repo->save($project, $ws, 'monthly', ['client@shop.test'], false, $acme['user']->id);
        self::assertSame([], $repo->enabled(), 'Paused schedules are skipped.');
        $repo->save($project, $ws, 'monthly', ['client@shop.test'], true, $acme['user']->id);
        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$project]);
        self::assertSame([], $repo->enabled(), 'Deleted projects get no reports.');
    }
}
