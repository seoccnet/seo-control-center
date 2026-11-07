<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\Research\ResearchRepository;

/**
 * Stored research results against MySQL/MariaDB.
 */
final class ResearchPersistenceTest extends FullStackTestCase
{
    public function testOverlappingResearchJobMakesNoPaidCall(): void
    {
        $subject = 'rival.test';
        $lock = new \SeoControlCenter\Core\Database\LeaseLock($this->db, 'research.fetch:7:gap:' . hash('sha256', $subject), 600);
        self::assertTrue($lock->acquire());
        $before = $this->db->fetchValue('SELECT COUNT(*) FROM api_usage');
        $job = new \SeoControlCenter\Modules\Research\Jobs\FetchResearchJob($this->app->make(\SeoControlCenter\Modules\Research\ResearchService::class), $this->db);
        try {
            $job->handle(['project_id' => 7, 'workspace_id' => 3, 'kind' => 'gap', 'subject' => $subject], new \SeoControlCenter\Core\Queue\JobContext('test', 'research.fetch', 1, 3, static fn(): bool => true, time() + 120));
            self::fail('An overlapping job must wait for the existing lookup.');
        } catch (\RuntimeException $e) {
            self::assertSame('Another worker is processing this research lookup.', $e->getMessage());
            self::assertSame($before, $this->db->fetchValue('SELECT COUNT(*) FROM api_usage'));
        } finally {
            $lock->release();
        }
    }

    public function testQueueCompleteAndFail(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000051', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$ws],
        );
        $project = $this->db->lastInsertId();
        $repo = $this->app->make(ResearchRepository::class);
        // Real ENUM and archived publication: the 0.9.62 fake-only tests missed this kind.
        $repo->queue($project, $ws, 'rendered_audit', '', ['url' => 'https://shop.test/'], null);
        $rendered = $repo->find($project, 'rendered_audit');
        self::assertNotNull($rendered);
        $repo->publishRendered($rendered, [['url' => 'https://shop.test/', 'status' => 200, 'findings' => ['Missing title']]]);
        self::assertCount(1, $repo->renderedHistory($project));
        self::assertSame('done', $repo->find($project, 'rendered_audit')?->status);
        self::assertSame([], $repo->renderedHistory($project + 1));
        $repo->queue($project, $ws, 'rendered_audit', '', ['url' => 'https://shop.test/new'], null);
        try {
            $repo->publishRendered($rendered, []);
            self::fail('An old render must not publish into a new request.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('changed', $e->getMessage());
        }
        self::assertTrue($repo->find($project, 'rendered_audit')?->isQueued());
        $oldId = $rendered->params['_request_id'];
        self::assertIsString($oldId);
        $repo->fail($project, 'rendered_audit', '', 'Stale worker error', $oldId);
        self::assertTrue($repo->find($project, 'rendered_audit')?->isQueued());
        self::assertNull($repo->find($project, 'ranked'));

        $repo->queue($project, $ws, 'ranked', '', ['location' => 2276, 'language' => 'de', 'domain' => 'shop.test'], null);
        $queued = $repo->find($project, 'ranked');
        self::assertTrue($queued?->isQueued());
        self::assertSame('2276:de', $queued->locale()->key());
        self::assertSame([], $queued->items);

        // Progress survives a new repository instance and JSON key normalization.
        $batch = new \SeoControlCenter\Modules\Research\ResumableLookup($repo, $queued);
        self::assertSame([['traffic' => 10.5, 'label' => 'grüne schuhe']], $batch->step('own', static fn(): array => [['traffic' => 10.5, 'label' => 'grüne schuhe']]));
        $reloaded = new \SeoControlCenter\Modules\Research\Persistence\PdoResearchRepository($this->newConnection(), new \SeoControlCenter\Core\Support\SystemClock());
        $saved = $reloaded->find($project, 'ranked');
        self::assertNotNull($saved);
        $resumed = new \SeoControlCenter\Modules\Research\ResumableLookup($reloaded, $saved);
        self::assertSame([['label' => 'grüne schuhe', 'traffic' => 10.5]], $resumed->step('own', static fn(): array => throw new \LogicException('Completed step must not repeat.')));
        self::assertSame([], $resumed->step('empty', static fn(): array => []));
        self::assertSame([], $reloaded->find($project, 'ranked')?->items);
        try {
            $repo->checkpoint($queued, [...$queued->params, '_batch' => []]);
            self::fail('Stale progress must not overwrite a newer checkpoint.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('lookup changed', $e->getMessage());
        }

        $repo->complete($project, 'ranked', '', [(new ResearchKeyword('grüne schuhe', 880, 1.0, 12, null, 3, 'https://shop.test/g', 40.0))->toArray()]);
        $done = $repo->find($project, 'ranked');
        self::assertSame('done', $done?->status);
        self::assertNotNull($done->fetchedAt);
        self::assertSame('grüne schuhe', $done->keywords()[0]->keyword);
        self::assertSame(1.0, $done->keywords()[0]->cpc);

        // A new request keeps the previous rows until its result arrives; a failure keeps them too.
        $repo->queue($project, $ws, 'ranked', '', ['location' => 2840, 'language' => 'en', 'domain' => 'shop.test'], null);
        $requeued = $repo->find($project, 'ranked');
        self::assertNotNull($requeued);
        self::assertCount(1, $requeued->items);
        $repo->fail($project, 'ranked', '', 'Budget reached.');
        $failed = $repo->find($project, 'ranked');
        self::assertSame(['failed', 'Budget reached.', 1], [$failed?->status, $failed?->error, count($failed->items ?? [])]);

        $params = ['domain' => 'same.test'];
        $repo->queue($project, $ws, 'overview', '', $params, null);
        $oldRequest = $repo->find($project, 'overview');
        self::assertNotNull($oldRequest);
        $repo->queue($project, $ws, 'overview', '', $params, null);
        try {
            $repo->checkpoint($oldRequest, [...$oldRequest->params, '_batch' => []]);
            self::fail('An identical replacement request needs a distinct identity.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('lookup changed', $e->getMessage());
        }

        $repo->queue($project, $ws, 'gap', 'a-competitor', ['competitor' => 'rival.test'], null);
        $repo->queue($project, $ws, 'gap', 'b-competitor', ['competitor' => 'other.test'], null);
        self::assertCount(2, $repo->forKind($project, 'gap'));
        self::assertSame([], $repo->forKind($project, 'ideas'));
    }
}
