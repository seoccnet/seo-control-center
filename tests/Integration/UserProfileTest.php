<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Accounts\Email;
use SeoControlCenter\Domain\Accounts\UserRepository;

/**
 * Profile changes against MySQL/MariaDB.
 */
final class UserProfileTest extends FullStackTestCase
{
    public function testNameAndEmailAreUpdated(): void
    {
        $user = $this->createUser('owner@acme.test', 'Acme')['user'];
        $repo = $this->app->make(UserRepository::class);

        $repo->updateProfile($user->id, 'Olivia Owner', new Email('olivia@acme.test'));

        $reloaded = $repo->findById($user->id);
        self::assertNotNull($reloaded);
        self::assertSame('Olivia Owner', $reloaded->name);
        self::assertSame('olivia@acme.test', $reloaded->email);
        self::assertNull($repo->findByEmail(new Email('owner@acme.test')));
        self::assertSame($user->id, $repo->credentialsByEmail(new Email('olivia@acme.test'))?->user->id);
    }
}
