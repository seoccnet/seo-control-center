<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Indexing\IndexingEvidence;
use SeoControlCenter\Modules\Indexing\IndexingRepository;
use SeoControlCenter\Modules\Indexing\Inspection;

/** Indexing history (ADR-042, 0.37.0): bounded check history, recorded requests and SEOcc's own evidence. */
final class IndexingHistoryDbTest extends FullStackTestCase
{
    public function testHistoryRequestsAndEvidenceStayPerProjectAndBounded(): void
    {
        $owner = $this->createUser('history@acme.test', 'History');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES ('0192f0a0-0000-7000-8000-000000000c01', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$workspace]);
        $project = $this->db->lastInsertId();
        $data = $this->app->make(IndexingRepository::class);
        $binding = hash('sha256', 'connection-one');
        $url = 'https://shop.test/Guide?Q=Ω';
        self::assertTrue($data->track($project, $binding, $url, false));

        for ($i = 0; $i < 32; ++$i) {
            $at = (new \DateTimeImmutable('2026-09-01 08:00:00'))->modify('+' . $i . ' days')->format('Y-m-d H:i:s');
            $data->save($project, $binding, $url, Inspection::fromArray(['verdict' => $i % 2 === 0 ? 'NEUTRAL' : 'PASS', 'coverageState' => 'Coverage ' . $i, 'robotsTxtState' => 'ALLOWED', 'indexingState' => 'INDEXING_ALLOWED', 'googleCanonical' => $url, 'userCanonical' => $url, 'lastCrawlTime' => '2026-08-31T10:00:00Z']), $at, Uuid::v7());
        }
        $history = $data->history($project, $binding, $url);
        self::assertCount(30, $history, 'At most 30 checks per address.');
        self::assertSame('2026-10-02 08:00:00', $history[0]['checked_at']);
        self::assertSame(['PASS', 'Coverage 31', 'ALLOWED', 'INDEXING_ALLOWED', '2026-08-31 10:00:00'], [$history[0]['verdict'], $history[0]['coverage'], $history[0]['robots'], $history[0]['indexing_state'], $history[0]['last_crawl']]);
        self::assertSame('2026-09-03 08:00:00', $history[29]['checked_at']);
        self::assertSame([], $data->history($project, hash('sha256', 'connection-two'), $url));
        self::assertSame([], $data->history($project, $binding, strtolower($url)));

        $data->addRequest($workspace, $project, '0192f0a0-0000-7000-8000-000000000c02', $url, 'search_console', '2026-10-01', 'Indexing requested', 'First', $owner['user']->id, '2026-10-01 09:00:00');
        $data->addRequest($workspace, $project, '0192f0a0-0000-7000-8000-000000000c03', $url, 'other', '2026-10-03', 'Asked via a sitemap ping', '', null, '2026-10-03 09:00:00');
        $requests = $data->requests($project, $url);
        self::assertSame(['2026-10-03', '2026-10-01'], array_column($requests, 'requested_on'));
        self::assertSame(['0192f0a0-0000-7000-8000-000000000c02', 'search_console', 'Indexing requested', 'First', '2026-10-01 09:00:00'], [$requests[1]['public_id'], $requests[1]['channel'], $requests[1]['evidence'], $requests[1]['note'], $requests[1]['created_at']]);
        self::assertSame([], $data->requests($project + 1, $url));

        $evidence = $this->app->make(IndexingEvidence::class);
        self::assertNull($evidence->crawl($project, $url), 'No completed audit yet.');
        $this->db->execute("INSERT INTO site_audits (public_id, project_id, workspace_id, status, trigger_type, start_url, max_pages, created_at, updated_at, finished_at) VALUES ('0192f0a0-0000-7000-8000-000000000c04', ?, ?, 'completed', 'manual', 'https://shop.test/', 100, '2026-10-20 08:00:00', '2026-10-20 10:00:00', '2026-10-20 10:00:00')", [$project, $workspace]);
        $audit = $this->db->lastInsertId();
        $this->db->execute("INSERT INTO site_audit_pages (audit_id, url, url_hash, depth, state, status_code, noindex, canonical, crawled_at) VALUES (?, ?, ?, 1, 'done', 200, 1, 'https://shop.test/guide', '2026-10-20 09:00:00')", [$audit, $url, sha1($url)]);
        self::assertSame(['status_code' => 200, 'noindex' => true, 'canonical' => 'https://shop.test/guide', 'redirect_to' => null, 'error' => null, 'crawled_at' => '2026-10-20 09:00:00'], $evidence->crawl($project, $url));
        self::assertNull($evidence->crawl($project + 1, $url));
        self::assertNull($evidence->sitemap($project, $url));
        $this->db->execute('INSERT INTO indexnow_urls (project_id, url_hash, url, lastmod, first_seen_at, submitted_at) VALUES (?, ?, ?, NULL, ?, NULL)', [$project, hash('sha256', $url, true), $url, '2026-10-02 07:00:00']);
        self::assertSame(['first_seen_at' => '2026-10-02 07:00:00', 'submitted_at' => null], $evidence->sitemap($project, $url));

        $this->db->execute('DELETE FROM projects WHERE id = ?', [$project]);
        self::assertSame([], $data->history($project, $binding, $url));
        self::assertSame([], $data->requests($project, $url));
    }
}
