<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\UserRepository;

/**
 * System → Users queries against MySQL/MariaDB.
 */
final class UserAdministrationPersistenceTest extends FullStackTestCase
{
    public function testOverviewStatusAndRoles(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme')['user'];
        $plain = $this->createUser('plain@acme.test')['user'];
        $repo = $this->app->make(UserRepository::class);

        $rows = [];
        foreach ($repo->overview() as $row) {
            $rows[$row->user->email] = $row;
        }
        self::assertSame(['Acme'], $rows['owner@acme.test']->workspaces);
        self::assertSame([], $rows['plain@acme.test']->workspaces);
        self::assertFalse($rows['owner@acme.test']->mfaEnabled);

        self::assertSame(0, $repo->countActiveAdmins());
        $repo->setSystemRole($owner->id, SystemRole::Admin);
        self::assertSame(1, $repo->countActiveAdmins());

        $repo->setActive($plain->id, false);
        self::assertFalse($repo->findById($plain->id)?->active);
        $repo->setActive($owner->id, false);
        self::assertSame(0, $repo->countActiveAdmins(), 'Deactivated admins do not count.');
    }
}
