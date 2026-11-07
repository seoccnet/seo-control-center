<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Crypto\KeyRing;
use SeoControlCenter\Core\Crypto\SecretRotator;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Queue\CronRunner;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Security\Totp;
use SeoControlCenter\Domain\Accounts\MfaService;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Modules\System\Health\SystemHealth;
use SeoControlCenter\Modules\System\Jobs\SystemCleanupJob;
use SeoControlCenter\Tests\Support\Browser;

/**
 * Slice 3 against the real database: MFA with encrypted secrets, key
 * rotation, cron runner, cleanup and System Health.
 */
final class PlatformFullStackTest extends FullStackTestCase
{
    public function testMfaSecretIsEncryptedAndLoginRequiresCode(): void
    {
        $this->createUser('owner@acme.test', 'Acme');
        $browser = $this->browserFor('owner@acme.test');
        $browser->get('/account/security');
        $page = (string) $browser->post('/account/security/mfa/setup')->getBody();
        self::assertSame(1, preg_match('#<p class="setup-key"><code>([A-Z2-7 ]+)</code></p>#', $page, $m));
        $secret = Totp::base32Decode(str_replace(' ', '', $m[1]));
        self::assertSame(200, $browser->post('/account/security/mfa/confirm', ['code' => Totp::code($secret, Totp::stepAt(time()))])->getStatusCode());

        $stored = (string) $this->db->fetchValue('SELECT secret FROM user_mfa_methods');
        self::assertStringNotContainsString($secret, $stored);
        self::assertSame(10, (int) $this->db->fetchValue('SELECT COUNT(*) FROM user_recovery_codes WHERE used_at IS NULL'));

        $second = new Browser($this->app);
        self::assertSame('/login/verify', $second->login('owner@acme.test', self::PASSWORD)->getHeaderLine('Location'));
        $second->get('/login/verify');
        self::assertSame(303, $second->post('/login/verify', ['code' => Totp::code($secret, Totp::stepAt(time()) + 1)])->getStatusCode());
        self::assertSame(200, $second->get('/')->getStatusCode());
    }

    public function testKeyRotationReencryptsSecrets(): void
    {
        $user = $this->createUser('owner@acme.test', 'Acme')['user'];
        $oldRing = $this->app->make(KeyRing::class);
        $secret = Totp::generateSecret();
        $this->app->make(\SeoControlCenter\Domain\Accounts\MfaRepository::class)->saveTotp(
            $user->id,
            (new Encrypter($oldRing))->encrypt($secret, MfaService::context($user->id)),
        );

        $newRing = $oldRing->withNewActiveKey(random_bytes(32));
        $newEncrypter = new Encrypter($newRing);
        $rotator = new SecretRotator($this->app->make(Connection::class), $newEncrypter, $newRing, [
            new \SeoControlCenter\Core\Crypto\EncryptedColumn('user_mfa_methods', 'id', 'secret', ['user_id'], static fn(array $r): string => MfaService::context((int) $r['user_id'])),
        ]);

        self::assertSame([1 => 1], $rotator->countByKey());
        self::assertSame(1, $rotator->rotateBatch());
        self::assertSame([2 => 1], $rotator->countByKey());
        self::assertSame(0, $rotator->rotateBatch(), 'Idempotent.');

        $stored = (string) $this->db->fetchValue('SELECT secret FROM user_mfa_methods');
        self::assertSame($secret, $newEncrypter->decrypt($stored, MfaService::context($user->id)));
    }

    // A real cron worker starts in a fresh process; PHPUnit's accumulated suite
    // memory must not trip its intentional 80%-of-memory_limit soft ceiling.
    #[RunInSeparateProcess]
    public function testCronRunnerSchedulesRunsJobsAndRecordsHeartbeat(): void
    {
        $this->db->execute(
            "INSERT INTO sessions (id, user_id, data, created_at, last_activity_at, expires_at)
             VALUES (?, NULL, '{}', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-02 00:00:00')",
            [str_repeat('a', 64)],
        );
        $this->app->make(Queue::class)->dispatch(SystemCleanupJob::TYPE, [], 'cleanup');

        $result = $this->app->make(CronRunner::class)->run();

        self::assertGreaterThanOrEqual(1, $result['processed']);
        self::assertSame(1, $result['slot']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sessions'), 'Expired session cleaned up.');
        $heartbeat = json_decode((string) $this->db->fetchValue("SELECT value FROM system_state WHERE name = 'cron.heartbeat'"), true);
        self::assertIsArray($heartbeat);
        self::assertSame(PHP_VERSION, $heartbeat['php_version']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM locks WHERE name LIKE 'worker:%' OR name = 'scheduler'"), 'Locks released.');
    }

    public function testSystemHealthRunsAndIsAdminOnly(): void
    {
        $this->app->make(\SeoControlCenter\Domain\Accounts\UserProvisioning::class)->createUser(
            'root@acme.test',
            'Root',
            self::PASSWORD,
            true,
            'Acme',
            \SeoControlCenter\Core\Audit\ActorType::Cli,
            \SeoControlCenter\Core\Support\ClientInfo::cli(),
        );
        $checks = $this->app->make(SystemHealth::class)->run();
        $groups = array_unique(array_map(static fn($c): string => $c->group, $checks));
        foreach (['Application', 'PHP', 'Database', 'Storage', 'Encryption', 'Cron', 'Queue'] as $group) {
            self::assertContains($group, $groups);
        }
        self::assertNotContains('System', $groups, 'No check crashed.');

        $html = (string) $this->browserFor('root@acme.test')->get('/system/health')->getBody();
        self::assertStringContainsString('System health', $html);
        self::assertStringNotContainsString(base64_encode(str_repeat("\x42", 32)), $html, 'Keys are never shown.');
        self::assertSame(SystemRole::Admin, $this->app->make(\SeoControlCenter\Domain\Accounts\UserRepository::class)->findByEmail(new \SeoControlCenter\Domain\Accounts\Email('root@acme.test'))?->systemRole);
    }
}
