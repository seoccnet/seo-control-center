<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;
use SeoControlCenter\Domain\Mcp\McpWriteRequests;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationRepository;

/**
 * Writable MCP storage (ADR-036) against MySQL/MariaDB: idempotency claims
 * per token, annotation revisions and `via`.
 */
final class McpWritePersistenceTest extends FullStackTestCase
{
    public function testWriteRequestsAreClaimedOncePerTokenAndExpire(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $tokens = $this->app->make(McpTokenRepository::class);
        $token = $tokens->create($owner['workspace']->id, $owner['user']->id, 'Claude', hash('sha256', 'scc_one', true), 'scc_one', [McpScope::AnnotationsWrite], null, 60, null);
        $other = $tokens->create($owner['workspace']->id, $owner['user']->id, 'Other', hash('sha256', 'scc_two', true), 'scc_two', [McpScope::AnnotationsWrite], null, 60, null);
        $repo = $this->app->make(McpWriteRequests::class);
        $hash = hash('sha256', 'args', true);
        $claim = static fn(int $tokenId, string $hash, string $now = '2026-10-15 10:00:00') => $repo->claim($tokenId, 'annotation_create', 'req-0001', $hash, $now, '2026-10-14 10:00:00', '2026-10-15 09:50:00');

        self::assertSame(McpWriteRequests::NEW, $claim($token->id, $hash)['state']);
        self::assertSame(McpWriteRequests::PENDING, $claim($token->id, $hash)['state']);
        self::assertSame(McpWriteRequests::MISMATCH, $claim($token->id, hash('sha256', 'other', true))['state']);
        self::assertSame(McpWriteRequests::NEW, $claim($other->id, $hash)['state'], 'Ids are per token.');

        $repo->complete($token->id, 'annotation_create', 'req-0001', ['annotation' => ['title' => 'Ω note']], '2026-10-15 10:00:01');
        self::assertSame(['state' => McpWriteRequests::DONE, 'result' => ['annotation' => ['title' => 'Ω note']]], $claim($token->id, $hash));

        $repo->release($other->id, 'annotation_create', 'req-0001');
        self::assertSame(McpWriteRequests::NEW, $claim($other->id, $hash)['state'], 'A released claim can be retried.');
        $repo->release($token->id, 'annotation_create', 'req-0001');
        self::assertSame(McpWriteRequests::DONE, $claim($token->id, $hash, '2026-10-15 10:00:02')['state'], 'Release never forgets a finished write.');

        self::assertSame(McpWriteRequests::NEW, $repo->claim($token->id, 'annotation_create', 'req-0001', $hash, '2026-10-16 11:00:00', '2026-10-15 11:00:00', '2026-10-16 10:50:00')['state'], 'After 24 hours the id is forgotten.');
        $this->db->execute('DELETE FROM mcp_tokens WHERE id = ?', [$token->id]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM mcp_write_requests WHERE token_id = ?', [$token->id]), 'Deleting a token removes its claims.');
    }

    public function testAnnotationRevisionsGuardMcpUpdatesAndRecordVia(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $id = '0192f0a0-0000-7000-8000-000000000093';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $owner['workspace']->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(AnnotationRepository::class);

        $note = $repo->save($project, new AnnotationInput('2026-01-01', 'title', 'Title', '', null), $owner['user']->id, null, null, 'Claude (sign-in)');
        self::assertSame(1, $note->revision);
        self::assertSame('Claude (sign-in)', $note->via);

        $edited = $repo->save($project, new AnnotationInput('2026-01-01', 'title', 'Same title', '', null), $owner['user']->id, $note->publicId, 1, 'Claude (sign-in)');
        self::assertSame(2, $edited->revision);

        try {
            $repo->save($project, new AnnotationInput('2026-01-01', 'title', 'Stale', '', null), $owner['user']->id, $note->publicId, 1, 'Claude (sign-in)');
            self::fail('A stale revision must not save.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->statusCode());
        }
        $browser = $repo->save($project, new AnnotationInput('2026-01-01', 'title', 'Browser edit', '', null), $owner['user']->id, $note->publicId);
        self::assertSame(3, $browser->revision);
        self::assertNull($browser->via, 'A browser edit clears via.');
    }
}
