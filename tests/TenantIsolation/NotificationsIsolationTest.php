<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAlerts;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Notification settings and recent alerts act on the current workspace only:
 * foreign alerts are never listed, and foreign project IDs in the form are
 * ignored.
 */
final class NotificationsIsolationTest extends TestCase
{
    public function testSharedProjectSettingsHideForeignProjectsAndRequireCsrf(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        foreach (['', '/webhook'] as $suffix) {
            $owner->get('/account/notifications');
            self::assertSame(404, $owner->post('/account/notifications/projects/' . $s->rivalProject->publicId . $suffix)->getStatusCode());
            $owner->get('/account/notifications');
            self::assertSame(419, $owner->post('/account/notifications/projects/' . $s->acmeProject->publicId . $suffix, withCsrf: false)->getStatusCode());
        }
    }

    public function testAlertsAndProjectMutesStayInTheirWorkspace(): void
    {
        $s = new Scenario();
        $alerts = $s->container->get(InMemoryAlerts::class);
        self::assertInstanceOf(InMemoryAlerts::class, $alerts);
        $alerts->record($s->acme->id, $s->acmeProject->id, AlertType::TrafficDrop, 'a', 'Acme Shop: clicks down 50%', [], '/projects/' . $s->acmeProject->publicId . '/performance');
        $alerts->record($s->acme->id, null, AlertType::Budget, 'b', 'Acme budget running low', [], '/integrations/dataforseo');

        $rival = $s->loggedIn($s->rivalOwner);
        $html = (string) $rival->get('/account/notifications')->getBody();
        self::assertStringNotContainsString('Acme', $html);
        self::assertStringContainsString('Rival Site', $html);

        $rival->post('/account/notifications', ['alerts' => '1', 'projects' => [$s->acmeProject->publicId], 'workspace' => $s->acme->publicId]);
        self::assertSame([$s->rivalProject->id], $alerts->settings($s->rivalOwner->id)->mutedProjects, 'The foreign project ID is ignored; the unchecked own project is muted.');

        $member = (string) $s->loggedIn($s->member)->get('/account/notifications')->getBody();
        self::assertStringContainsString('Acme Shop: clicks down 50%', $member);
        self::assertStringNotContainsString('Acme budget running low', $member, 'Budget alerts need integrations.manage.');
    }
}
