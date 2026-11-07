<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

final class UpdatesAccessTest extends TestCase
{
    public function testWorkspaceRolesCannotRequestInstallationUpdates(): void
    {
        $s = new Scenario();
        foreach ([$s->owner, $s->admin, $s->member, $s->viewer, $s->rivalOwner] as $user) {
            $browser = $s->loggedIn($user);
            $browser->get('/account/security');
            self::assertSame(403, $browser->post('/system/updates/upload')->getStatusCode());
        }
    }
}
