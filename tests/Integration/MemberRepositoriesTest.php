<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Workspaces\InvitationRepository;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;

/**
 * Members and invitations against MySQL/MariaDB: role changes, removal
 * (with project narrowing), hashed one-time invitations and workspace scoping.
 */
final class MemberRepositoriesTest extends FullStackTestCase
{
    public function testMembersCanBeAddedChangedAndRemoved(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $guest = $this->createUser('guest@acme.test')['user'];
        self::assertNotNull($acme['workspace']);
        $workspace = $acme['workspace'];
        $repo = $this->app->make(WorkspaceRepository::class);

        self::assertTrue($repo->addMember($workspace->id, $guest->id, 'viewer'));
        self::assertFalse($repo->addMember($workspace->id, $guest->id, 'admin'), 'Already a member.');
        $roles = [];
        foreach ($repo->members($workspace->id) as $member) {
            $roles[$member->email] = $member->roleKey;
            self::assertGreaterThan(0, $member->userId);
        }
        self::assertSame(['guest@acme.test' => 'viewer', 'owner@acme.test' => 'owner'], $roles);

        self::assertTrue($repo->changeRole($workspace->id, $guest->id, 'admin'));
        self::assertSame('admin', $this->db->fetchValue(
            'SELECT r.role_key FROM workspace_users wu JOIN roles r ON r.id = wu.role_id WHERE wu.workspace_id = ? AND wu.user_id = ?',
            [$workspace->id, $guest->id],
        ));

        $ownerBrowser = $this->browserFor('owner@acme.test');
        $project = $this->createProject($ownerBrowser, 'Shop', 'shop.acme.test');
        $this->db->execute(
            'INSERT INTO project_members (project_id, user_id, role_id, created_at) SELECT id, ?, NULL, UTC_TIMESTAMP() FROM projects WHERE public_id = ?',
            [$guest->id, $project],
        );

        self::assertTrue($repo->removeMember($workspace->id, $guest->id));
        self::assertFalse($repo->removeMember($workspace->id, $guest->id));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM project_members WHERE user_id = ?', [$guest->id]));
        self::assertCount(1, $repo->members($workspace->id));
    }

    public function testInvitationsAreHashedOneTimeAndScoped(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $repo = $this->app->make(InvitationRepository::class);
        $token = str_repeat('T', 43);
        $hash = hash('sha256', $token, true);
        $now = '2026-09-30 12:00:00';

        $invitation = $repo->create($acme['workspace']->id, 'new@example.test', 'member', $hash, $acme['user']->id, '2026-10-07 12:00:00');
        self::assertSame('Acme', $invitation->workspaceName);
        self::assertSame('member', $invitation->roleKey);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM workspace_invitations WHERE token_hash = ?', [$token]), 'The token is never stored.');

        self::assertSame($invitation->id, $repo->findPendingByTokenHash($hash, $now)?->id);
        self::assertNull($repo->findPendingByTokenHash($hash, '2026-10-08 00:00:00'), 'Expired.');
        self::assertCount(1, $repo->pending($acme['workspace']->id, $now));
        self::assertSame([], $repo->pending($rival['workspace']->id, $now));
        self::assertFalse($repo->revoke($rival['workspace']->id, $invitation->publicId, $now), 'Scoped to its workspace.');

        self::assertTrue($repo->markAccepted($invitation->id, $now));
        self::assertFalse($repo->markAccepted($invitation->id, $now), 'Used once.');
        self::assertNull($repo->findPendingByTokenHash($hash, $now));

        $second = $repo->create($acme['workspace']->id, 'other@example.test', 'viewer', hash('sha256', 'other', true), $acme['user']->id, '2026-10-07 12:00:00');
        self::assertTrue($repo->revoke($acme['workspace']->id, $second->publicId, $now));
        self::assertFalse($repo->markAccepted($second->id, $now), 'A revoked invitation cannot be accepted.');
        self::assertSame([], $repo->pending($acme['workspace']->id, $now));
    }
}
