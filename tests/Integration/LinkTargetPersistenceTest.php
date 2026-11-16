<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\Backlinks\LinkTargetRepository;

/** Checks of linked pages (1.5.0) on the real engine. */
final class LinkTargetPersistenceTest extends FullStackTestCase
{
    public function testRunStateAndAnswersRoundTrip(): void
    {
        $owner = $this->createUser('reclaim@acme.test', 'Reclaim');
        self::assertNotNull($owner['workspace']);
        $ws = $owner['workspace']->id;
        $publicId = $this->createProject($this->browserFor('reclaim@acme.test'), 'Reclaim', 'acme.test');
        $project = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $repo = $this->app->make(LinkTargetRepository::class);

        self::assertNull($repo->run($project));
        $repo->setState($project, $ws, LinkTargetRepository::QUEUED);
        $repo->setState($project, $ws + 999, LinkTargetRepository::DONE);
        self::assertSame(LinkTargetRepository::QUEUED, $repo->run($project)['state'] ?? null, 'Another workspace cannot change the run.');
        $repo->setState($project, $ws, LinkTargetRepository::FAILED, str_repeat('x', 400));
        $run = $repo->run($project) ?? self::fail('Run lost.');
        self::assertSame(255, mb_strlen((string) $run['error']));
        self::assertNotNull($run['finished_at']);
        $repo->setState($project, $ws, LinkTargetRepository::QUEUED);
        self::assertNull(($repo->run($project) ?? self::fail('Run lost.'))['finished_at'], 'Queueing again starts a new run.');

        $long = 'https://acme.test/' . str_repeat('ü', 1500);
        $repo->save($project, 'https://acme.test/old', 301, 'https://acme.test/gone', 404, null);
        $repo->save($project, 'https://acme.test/old', 404, null, null, null);
        $repo->save($project, $long, null, null, null, 'Timed out');
        $results = $repo->results($project);
        self::assertCount(2, $results);
        self::assertSame(404, $results['https://acme.test/old']['status']);
        self::assertNull($results['https://acme.test/old']['location']);
        self::assertSame('Timed out', $results[$long]['error']);
    }
}
