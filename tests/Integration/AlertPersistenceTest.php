<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\Alerts\AlertRepository;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\Alerts\NotificationSettings;

/**
 * Alerts (recorded once per fingerprint), notification settings and the
 * digest log against MySQL/MariaDB.
 */
final class AlertPersistenceTest extends FullStackTestCase
{
    public function testAlertsSettingsAndDigestLog(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $ws = $acme['workspace']->id;
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000091', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$ws],
        );
        $project = $this->db->lastInsertId();
        $alerts = $this->app->make(AlertRepository::class);
        self::assertEquals(new \SeoControlCenter\Modules\Alerts\AlertThresholds(), $alerts->thresholds($project));
        $thresholds = new \SeoControlCenter\Modules\Alerts\AlertThresholds(15, 25, 3, 75);
        $alerts->saveThresholds($project, $thresholds);
        self::assertEquals($thresholds, $alerts->thresholds($project));
        self::assertEquals(new \SeoControlCenter\Modules\Alerts\AlertThresholds(), $alerts->thresholds($project + 100));

        self::assertSame([['projectId' => $project, 'workspaceId' => $ws, 'publicId' => '0192f0a0-0000-7000-8000-000000000091', 'name' => 'Shop', 'domain' => 'shop.test']], $alerts->projects());
        self::assertSame([$ws, $rival['workspace']->id], $alerts->workspaces());

        $alert = $alerts->record($ws, $project, AlertType::TrafficDrop, 'traffic:1:2026-W40', 'Shop: clicks down 40%', ['70 clicks', '“quoted” ünïcode'], '/projects/x/performance');
        self::assertNotNull($alert);
        self::assertSame(['70 clicks', '“quoted” ünïcode'], $alert->lines);
        self::assertNull($alert->sentAt);
        $hooks = $this->app->make(\SeoControlCenter\Modules\Alerts\WebhookRepository::class);
        $version = '0192f0a0-0000-7000-8000-000000000092';
        $hook = new \SeoControlCenter\Modules\Alerts\AlertWebhook($version, $project, $acme['user']->id, true, 'json', 'https://notify.example.test/secret', 'secret-bearer');
        $hooks->save($hook);
        self::assertSame($hook->destination(), $hooks->find($project)?->destination());
        self::assertSame('secret-bearer', $hooks->find($project)?->bearerToken());
        $encrypted = $this->db->fetchOne('SELECT destination, bearer_token FROM project_alert_webhooks WHERE project_id = ?', [$project]);
        self::assertNotNull($encrypted);
        self::assertStringNotContainsString('secret', (string) $encrypted['destination']);
        self::assertStringNotContainsString('secret-bearer', (string) $encrypted['bearer_token']);
        self::assertSame(2, $this->app->make(\SeoControlCenter\Core\Crypto\SecretRotator::class)->countByKey()[1] ?? 0, 'Both webhook secrets participate in key rotation.');
        self::assertNull($hooks->find($project + 100));
        self::assertTrue($hooks->claim($alert->id, $project, $version));
        self::assertFalse($hooks->claim($alert->id, $project, $version));
        $hooks->finish($alert->id, 'delivered', 204, 'Accepted.');
        self::assertSame('delivered', $hooks->history($project)[0]['status']);
        self::assertSame([], $hooks->history($project + 100));
        $hooks->remove($project);
        self::assertNull($hooks->find($project));
        self::assertNull($alerts->record($ws, $project, AlertType::TrafficDrop, 'traffic:1:2026-W40', 'again', [], '/'), 'Once per fingerprint.');
        self::assertNotNull($alerts->record($rival['workspace']->id, null, AlertType::Budget, 'traffic:1:2026-W40', 'Budget', [], '/integrations/dataforseo'), 'Fingerprints are per workspace and type.');
        $budget = $alerts->record($ws, null, AlertType::Budget, 'budget:2026-10:balance', 'Budget', [], '/integrations/dataforseo');
        self::assertNotNull($budget);

        $alerts->markSent($alert->id);
        self::assertNotNull($alerts->find($alert->id)?->sentAt);
        self::assertCount(2, $alerts->recent($ws, null, '2000-01-01 00:00:00', 10));
        self::assertSame([$budget->id], array_map(static fn($a): int => $a->id, $alerts->recent($ws, [], '2000-01-01 00:00:00', 10)), 'Without visible projects only workspace alerts.');
        self::assertCount(2, $alerts->recent($ws, [$project], '2000-01-01 00:00:00', 10));
        self::assertSame([], $alerts->recent($ws, null, '2999-01-01 00:00:00', 10));

        $user = $acme['user']->id;
        self::assertEquals(new NotificationSettings(), $alerts->settings($user), 'Defaults without a row.');
        $settings = new NotificationSettings(false, [AlertType::LostLinks, AlertType::Budget], [$project], false, true);
        $alerts->saveSettings($user, $settings);
        self::assertEquals($settings, $alerts->settings($user));
        $alerts->saveSettings($user, new NotificationSettings());
        self::assertEquals(new NotificationSettings(), $alerts->settings($user));

        self::assertTrue($alerts->claimDigest($user, $ws, '2026-W41'));
        self::assertFalse($alerts->claimDigest($user, $ws, '2026-W41'));
        self::assertTrue($alerts->claimDigest($user, $ws, '2026-W42'));
    }
}
