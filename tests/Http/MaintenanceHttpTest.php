<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Queue\FailedJob;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Modules\System\Support\SupportBundle;
use SeoControlCenter\Tests\Support\Fakes\InMemoryQueueMonitor;
use SeoControlCenter\Tests\Support\Scenario;
use ZipArchive;

/**
 * Backups page and support bundle: installation administrators only, and
 * the bundle never contains secrets.
 */
final class MaintenanceHttpTest extends TestCase
{
    public function testWorkspaceOwnersAreNotInstallationAdmins(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);

        self::assertSame(403, $owner->get('/system/backups')->getStatusCode());
        self::assertSame(403, $owner->get('/system/support-bundle')->getStatusCode());
        self::assertSame(403, $owner->get('/system/backups/scc-backup-20260901-010000.sql.gz')->getStatusCode());
    }

    public function testAdminDownloadsSupportBundleWithoutSecrets(): void
    {
        $s = new Scenario(['DB_PASSWORD' => 'db-password-never-shared']);
        $root = $s->addUser('root@acme.test', SystemRole::Admin);
        $browser = $s->loggedIn($root);

        $response = $browser->get('/system/support-bundle');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/attachment; filename="scc-support-\d{8}-\d{6}\.zip"/', $response->getHeaderLine('Content-Disposition'));
        $file = (string) tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($file, (string) $response->getBody());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file) === true);
        $names = [];
        $all = '';
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
            $all .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($file);

        foreach (['README.txt', 'environment.json', 'health.json', 'migrations.json', 'queue.json', 'settings.json'] as $expected) {
            self::assertContains($expected, $names);
        }
        self::assertStringContainsString('"app.env": "testing"', $all);
        self::assertStringNotContainsString('db-password-never-shared', $all);
        self::assertStringNotContainsString(base64_encode(str_repeat("\x42", 32)), $all, 'The test key ring value is never included.');
        self::assertContains('system.support_bundle_downloaded', $s->audit()->actions());
    }

    public function testBackupDownloadsOnlyAcceptBackupFileNames(): void
    {
        $s = new Scenario();
        $root = $s->addUser('root@acme.test', SystemRole::Admin);
        $browser = $s->loggedIn($root);

        self::assertSame(200, $browser->get('/system/backups')->getStatusCode());
        self::assertSame(404, $browser->get('/system/backups/scc-backup-19990101-000000.sql.gz')->getStatusCode(), 'Unknown backup.');
        self::assertSame(404, $browser->get('/system/backups/..%2F.env')->getStatusCode(), 'No paths.');
    }

    public function testUpdatesPageIsForInstallationAdminsOnly(): void
    {
        $s = new Scenario();
        $root = $s->addUser('root@acme.test', SystemRole::Admin);

        self::assertSame(403, $s->loggedIn($s->owner)->get('/system/updates')->getStatusCode());
        $response = $s->loggedIn($root)->get('/system/updates');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('enctype="multipart/form-data"', (string) $response->getBody());
    }

    public function testFailedJobsCanBeRetriedAndDismissedByAdmins(): void
    {
        $s = new Scenario();
        $root = $s->addUser('root@acme.test', SystemRole::Admin);
        $monitor = $s->container->make(InMemoryQueueMonitor::class);
        $monitor->add(new FailedJob('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f01', 'rankings.check', 'rankings', 3, 'RuntimeException: <script>budget exhausted', '2026-09-27 01:00:00'));
        $monitor->add(new FailedJob('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f02', 'keywords.metrics', 'analysis', 3, null, '2026-09-27 01:05:00'));

        $owner = $s->loggedIn($s->owner);
        self::assertSame(403, $owner->get('/system/jobs')->getStatusCode());
        $owner->get('/account/security'); // CSRF token
        self::assertSame(403, $owner->post('/system/jobs/retry-all', [])->getStatusCode());
        self::assertCount(2, $monitor->failed);

        $browser = $s->loggedIn($root);
        $page = (string) $browser->get('/system/jobs')->getBody();
        self::assertStringContainsString('rankings.check', $page);
        self::assertStringContainsString('&lt;script&gt;budget exhausted', $page, 'Errors are escaped.');

        self::assertSame(303, $browser->post('/system/jobs/0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f01/retry', [])->getStatusCode());
        self::assertSame(303, $browser->post('/system/jobs/0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f02/dismiss', [])->getStatusCode());
        self::assertSame(['0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f01'], $monitor->retried);
        self::assertSame([], $monitor->failed);
        self::assertContains('system.job_retried', $s->audit()->actions());
        self::assertContains('system.job_dismissed', $s->audit()->actions());
    }

    public function testScrubRemovesEmailsAndTokens(): void
    {
        $scrubbed = SupportBundle::scrub('User owner@acme.test sent Authorization: Bearer abc.DEF-123 with scc_aOW97BWKLoBySmHxwz0u9q_zcW8QSVJNq4fZPlaHs3U');

        self::assertSame('User [email] sent Authorization: Bearer [redacted] with scc_[redacted]', $scrubbed);
    }
}
