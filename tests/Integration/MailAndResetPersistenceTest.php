<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Mail\MailSettings;
use SeoControlCenter\Core\Mail\MailSettingsRepository;
use SeoControlCenter\Domain\Accounts\PasswordResetRepository;

/**
 * Mail settings (encrypted SMTP password) and password-reset tokens against
 * MySQL/MariaDB.
 */
final class MailAndResetPersistenceTest extends FullStackTestCase
{
    public function testMailSettingsEncryptThePassword(): void
    {
        $admin = $this->createUser('root@acme.test', 'Acme')['user'];
        $repo = $this->app->make(MailSettingsRepository::class);
        self::assertSame('none', $repo->get()->transport);

        $repo->save(new MailSettings('smtp', 'mail.example.com', 587, 'tls', 'noreply@example.com', 's3cret', 'noreply@example.com', 'SCC'), false, $admin->id);
        $stored = (string) $this->db->fetchValue('SELECT password FROM mail_settings WHERE id = 1');
        self::assertStringNotContainsString('s3cret', $stored, 'The password is encrypted at rest.');
        self::assertSame('s3cret', $repo->get()->password);
        self::assertTrue($repo->get()->isConfigured());

        $repo->save(new MailSettings('smtp', 'smtp.example.com', null, 'ssl', 'noreply@example.com', null, 'noreply@example.com', null), true, $admin->id);
        self::assertSame('s3cret', $repo->get()->password, 'Keeping the password.');
        self::assertSame(465, $repo->get()->port());

        $repo->save(new MailSettings('php', null, null, 'tls', null, null, 'noreply@example.com', null), false, $admin->id);
        self::assertNull($repo->get()->password, 'Clearing the password.');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM mail_settings'));
    }

    public function testResetTokensAreSingleUseAndExpire(): void
    {
        $user = $this->createUser('member@acme.test')['user'];
        $repo = $this->app->make(PasswordResetRepository::class);
        $hash = hash('sha256', 'token', true);

        $repo->create($user->id, $hash, '2026-10-02 13:00:00', '203.0.113.5');
        self::assertSame($user->id, $repo->findValid($hash, '2026-10-02 12:30:00'));
        self::assertNull($repo->findValid($hash, '2026-10-02 13:00:01'), 'Expired.');

        $repo->invalidateForUser($user->id, '2026-10-02 12:31:00');
        self::assertNull($repo->findValid($hash, '2026-10-02 12:32:00'), 'Used.');
    }
}
