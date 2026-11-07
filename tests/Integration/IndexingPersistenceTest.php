<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Indexing\IndexingRepository;
use SeoControlCenter\Modules\Indexing\Inspection;
use SeoControlCenter\Modules\Pages\PageRepository;

final class IndexingPersistenceTest extends FullStackTestCase
{
    public function testSavedEvidenceLimitsBindingIdempotencyAlertsAndPagesCatalog(): void
    {
        $owner = $this->createUser('indexing@acme.test', 'Indexing');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace'];
        $uuid = Uuid::v7();
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$uuid, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $uuid);
        self::assertNotNull($project);
        $data = $this->app->make(IndexingRepository::class);
        $binding = hash('sha256', 'connection-one');
        $other = hash('sha256', 'connection-two');
        $url = 'https://shop.test/Upper?Q=Ω';
        self::assertTrue($data->track($project->id, $binding, $url, true));
        self::assertNull($data->find($project->id, $other, $url));
        self::assertNull($data->find($project->id + 1, $binding, $url));
        self::assertNull($data->find($project->id, $binding, strtolower($url)));
        $pass = Inspection::fromArray(['verdict' => 'PASS', 'coverageState' => 'Indexed Ω', 'googleCanonical' => $url, 'lastCrawlTime' => '2026-10-01T12:00:00+02:00']);
        $firstJob = Uuid::v7();
        $data->save($project->id, $binding, $url, $pass, '2026-10-05 12:00:00', $firstJob);
        self::assertSame($pass->present(), $data->find($project->id, $binding, $url)?->result?->present());
        self::assertSame([], $data->changes($project->id, $binding, '2000-01-01'));
        $fail = Inspection::fromArray(['verdict' => 'FAIL', 'coverageState' => 'Not indexed', 'googleCanonical' => 'https://shop.test/other']);
        $data->save($project->id, $binding, $url, $fail, '2026-10-05 13:00:00', $firstJob);
        self::assertSame('PASS', $data->find($project->id, $binding, $url)?->result?->verdict, 'A replay cannot change successful evidence.');
        $data->attempted($project->id, $binding, $url, '2026-10-05 13:00:00', 'Temporary failure');
        self::assertSame('PASS', $data->find($project->id, $binding, $url)?->result?->verdict);
        $data->save($project->id, $binding, $url, $fail, '2026-10-05 14:00:00', Uuid::v7());
        self::assertNull($data->find($project->id, $binding, $url)?->error);
        $events = $data->changes($project->id, $binding, '2026-10-05');
        self::assertCount(1, $events);
        self::assertTrue($events[0]['canonical_changed']);
        self::assertSame('PASS', $events[0]['before']);
        self::assertSame('FAIL', $events[0]['after']);
        self::assertSame([], $data->changes($project->id, $other, '2000-01-01'));
        self::assertSame([], $data->changes($project->id + 1, $binding, '2000-01-01'));
        self::assertSame(1, $this->app->make(PageRepository::class)->count($project->id));
        self::assertSame(0, $this->app->make(PageRepository::class)->count($project->id + 1));
        for ($i = 0; $i < 49; ++$i) {
            self::assertTrue($data->track($project->id, $binding, 'https://shop.test/' . $i, true));
        }
        self::assertFalse($data->track($project->id, $binding, 'https://shop.test/overflow', true));
        self::assertCount(50, $data->due('2026-10-20', 10000));
        self::assertTrue($data->track($project->id, $binding, $url, false));
        self::assertCount(49, $data->due('2026-10-20', 50));
        self::assertTrue($data->track($project->id, $binding, 'https://shop.test/overflow', true));
        for ($i = 49; $i < 498; ++$i) {
            self::assertTrue($data->track($project->id, $binding, 'https://shop.test/' . $i, false));
        }
        self::assertCount(500, $data->records($project->id, $binding));
        self::assertFalse($data->track($project->id, $binding, 'https://shop.test/limit', false));
        self::assertTrue($data->track($project->id, $other, $url, true), 'Relinking replaces evidence rather than accumulating another URL.');
        self::assertNull($data->find($project->id, $binding, $url));
        self::assertNull($data->find($project->id, $other, $url)?->result);
        self::assertTrue($data->find($project->id, $other, $url)?->watched);
        $this->db->execute('DELETE FROM projects WHERE id = ?', [$project->id]);
        self::assertSame([], $data->records($project->id, $binding));
        self::assertSame([], $data->changes($project->id, $binding, '2000-01-01'));
    }
}
