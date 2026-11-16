<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryShareLinks;
use SeoControlCenter\Tests\Support\Scenario;

final class ReportShareLinksIsolationTest extends TestCase
{
    public function testOnlyEditorsManageLinksAndForeignLinksAreNotFound(): void
    {
        $s = new Scenario();
        $store = $s->container->get(InMemoryShareLinks::class);
        $rival = $store->create($s->rivalProject->workspaceId, $s->rivalProject->id, hash('sha256', 'x', true), 'xxxxxx', 'Rival', '2099-01-01 00:00:00', $s->rivalOwner->id, '2026-11-16 00:00:00');
        $reports = '/projects/' . $s->acmeProject->publicId . '/reports';

        $viewer = $s->loggedIn($s->viewer);
        self::assertStringContainsString('Project editors can create them.', (string) $viewer->get($reports)->getBody());
        $viewer->get('/account');
        $csrf = $viewer->csrfToken();
        self::assertSame(403, $viewer->post($reports . '/links', ['_csrf' => $csrf, 'label' => 'x', 'days' => '7'])->getStatusCode());

        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        $csrf = $owner->csrfToken();
        self::assertSame(404, $owner->post($reports . '/links/' . $rival->publicId . '/revoke', ['_csrf' => $csrf])->getStatusCode());
        self::assertSame(404, $owner->post('/projects/' . $s->rivalProject->publicId . '/reports/links', ['_csrf' => $csrf, 'label' => 'x', 'days' => '7'])->getStatusCode());
        self::assertNull($store->links[$rival->id]->revokedAt);
        $owner->post($reports . '/links', ['_csrf' => $csrf, 'label' => 'x', 'days' => '3']);
        self::assertCount(1, $store->links, 'Only the offered durations are accepted.');
    }
}
