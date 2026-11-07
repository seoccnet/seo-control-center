<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\IndexNow\IndexNowRepository;

/**
 * IndexNow settings, sitemap URLs and the submission log against MySQL/MariaDB.
 */
final class IndexNowPersistenceTest extends FullStackTestCase
{
    public function testSettingsUrlsAndLog(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000011', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$ws],
        );
        $project = $this->db->lastInsertId();
        $repo = $this->app->make(IndexNowRepository::class);

        $repo->create($project, $ws, str_repeat('c', 32));
        self::assertFalse($repo->settings($project)?->isVerified());
        $repo->markVerified($project, 'shop.test');
        self::assertSame('/', $repo->settings($project)?->keyPath);
        $repo->markVerified($project, 'shop.test', '/blog/');
        self::assertSame(['shop.test', '/blog/'], [$repo->settings($project)?->host, $repo->settings($project)?->keyPath]);
        $repo->saveSitemap($project, 'https://shop.test/sitemap.xml', true);
        self::assertSame([['projectId' => $project, 'workspaceId' => $ws]], $repo->autoSubmitProjects());

        $repo->rememberUrls($project, [['loc' => 'https://shop.test/', 'lastmod' => '2026-09-01'], ['loc' => 'https://shop.test/a', 'lastmod' => null]], []);
        $repo->rememberUrls($project, [['loc' => 'https://shop.test/a', 'lastmod' => '2026-10-01']], ['https://shop.test/a']);
        $known = $repo->knownUrls($project);
        ksort($known);
        self::assertSame(['https://shop.test/' => '2026-09-01', 'https://shop.test/a' => '2026-10-01'], $known);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM indexnow_urls WHERE project_id = ? AND submitted_at IS NOT NULL', [$project]));

        $repo->logSubmission($project, 'sitemap', 1, 200, 'Accepted.', null);
        $repo->recordSitemapCheck($project, 'boom');
        self::assertSame('boom', $repo->settings($project)?->lastError);
        self::assertSame('/blog/', $repo->settings($project)?->keyPath, 'Sitemap updates keep the key path.');
        self::assertSame([['source' => 'sitemap', 'url_count' => 1, 'status_code' => 200, 'result' => 'Accepted.']], array_map(static fn(array $r): array => array_diff_key($r, ['created_at' => true]), $repo->submissions($project, 10)));

        // A new key resets the verification.
        $repo->create($project, $ws, str_repeat('d', 32));
        self::assertNull($repo->settings($project)?->verifiedAt);
        self::assertSame('/', $repo->settings($project)?->keyPath);
        self::assertSame([], $repo->autoSubmitProjects());
        $repo->delete($project);
        self::assertNull($repo->settings($project));
    }
}
