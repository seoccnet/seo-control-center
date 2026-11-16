<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Backlinks\WebMention;
use SeoControlCenter\Modules\Research\ResearchRepository;

/** The bl_mentions lookup kind (1.6.0) on the real engine. */
final class BrandMentionLookupPersistenceTest extends FullStackTestCase
{
    public function testMentionsAreQueuedAndStored(): void
    {
        $owner = $this->createUser('mentions@acme.test', 'Mentions');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('mentions@acme.test'), 'Mentions', 'acme.test');
        $project = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $results = $this->app->make(ResearchRepository::class);

        $results->queue($project, $owner['workspace']->id, 'bl_mentions', '', ['domain' => 'acme.test', 'brand' => 'Acme Ü'], $owner['user']->id);
        self::assertTrue($results->find($project, 'bl_mentions')?->isQueued());
        $mention = new WebMention('https://blog.test/ü', 'blog.test', 'Acme Ü review', 'Snippet', 61, '2026-10-02', 'blogs');
        $results->complete($project, 'bl_mentions', '', [$mention->toArray()]);

        $stored = $results->find($project, 'bl_mentions') ?? self::fail('Lookup lost.');
        self::assertSame('Acme Ü', $stored->params['brand']);
        self::assertEquals($mention, WebMention::fromArray($stored->items[0]));
    }
}
