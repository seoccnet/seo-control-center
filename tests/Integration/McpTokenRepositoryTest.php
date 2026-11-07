<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;

/**
 * MCP tokens against MySQL/MariaDB: only hashes stored, workspace scoping, revocation.
 */
final class McpTokenRepositoryTest extends FullStackTestCase
{
    public function testTokensAreStoredAsHashesAndScopedToTheWorkspace(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $repo = $this->app->make(McpTokenRepository::class);
        $plaintext = 'scc_' . str_repeat('A', 43);

        $token = $repo->create($acme['workspace']->id, $acme['user']->id, 'Claude', hash('sha256', $plaintext, true), 'scc_AAAAAAAA', [McpScope::Projects, McpScope::Search], [7, 9], 60, '2027-01-01 00:00:00');

        self::assertSame([McpScope::Projects, McpScope::Search], $token->scopes);
        self::assertSame([7, 9], $token->projectIds);
        self::assertSame('2027-01-01 00:00:00', $token->expiresAt);
        self::assertSame($token->id, $repo->findByHash(hash('sha256', $plaintext, true))?->id);
        self::assertNull($repo->findByHash(hash('sha256', 'scc_other', true)));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM mcp_tokens WHERE token_hash = ? OR token_prefix = ?', [$plaintext, $plaintext]), 'The plaintext is never stored.');

        self::assertCount(1, $repo->forWorkspace($acme['workspace']->id));
        self::assertSame([], $repo->forWorkspace($rival['workspace']->id));
        self::assertNull($repo->findInWorkspace($rival['workspace']->id, $token->publicId));

        $repo->touch($token->id);
        self::assertNotNull($repo->findInWorkspace($acme['workspace']->id, $token->publicId)?->lastUsedAt);
        $repo->revoke($token->id);
        $revoked = $repo->findByHash(hash('sha256', $plaintext, true));
        self::assertNotNull($revoked?->revokedAt);
        self::assertFalse($revoked->isUsable(new \DateTimeImmutable('2026-09-26 12:00:00')));

        $all = $repo->create($acme['workspace']->id, $acme['user']->id, 'All', hash('sha256', 'scc_x', true), 'scc_x', [McpScope::Projects], null, 60, null);
        self::assertNull($all->projectIds);
        self::assertTrue($all->coversProject(12345));
        self::assertTrue($all->isUsable(new \DateTimeImmutable('2099-01-01')));
    }
}
