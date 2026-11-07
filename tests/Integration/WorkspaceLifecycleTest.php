<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Authorization\MembershipProvider;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;

/**
 * Renaming and soft-deleting workspaces against MySQL/MariaDB: a deleted
 * workspace disappears from lists and access checks, and
 * its projects are soft-deleted with it.
 */
final class WorkspaceLifecycleTest extends FullStackTestCase
{
    public function testRenameAndSoftDelete(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        $workspace = $acme['workspace'];
        $repo = $this->app->make(WorkspaceRepository::class);
        $members = $this->app->make(MembershipProvider::class);
        $project = $this->createProject($this->browserFor('owner@acme.test'), 'Shop', 'shop.acme.test');

        $repo->rename($workspace->id, 'Acme Group');
        self::assertSame('Acme Group', $repo->forUser($acme['user']->id)[0]->workspace->name);
        self::assertSame(2, $repo->countAll());
        self::assertNotNull($members->workspaceMembership($acme['user']->id, $workspace->id));

        $repo->softDelete($workspace->id);
        self::assertSame([], $repo->forUser($acme['user']->id));
        self::assertSame(1, $repo->countAll());
        self::assertNull($members->workspaceMembership($acme['user']->id, $workspace->id));
        self::assertNotNull($this->db->fetchValue('SELECT deleted_at FROM projects WHERE public_id = ?', [$project]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM workspaces WHERE id = ?', [$workspace->id]), 'The data is kept.');

        $repo->rename($workspace->id, 'Ignored');
        self::assertSame('Acme Group', $this->db->fetchValue('SELECT name FROM workspaces WHERE id = ?', [$workspace->id]), 'Deleted workspaces are not renamed.');
    }
}
