<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryInvitations;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Member management never reaches across workspaces: the target member or
 * invitation is looked up inside the current workspace only (404 otherwise).
 */
final class MembersIsolationTest extends TestCase
{
    public function testRivalOwnerCannotChangeOrRemoveForeignMembers(): void
    {
        $s = new Scenario();
        $rival = $s->loggedIn($s->rivalOwner);
        $rival->get('/workspace/members');

        self::assertSame(404, $rival->post('/workspace/members/' . $s->member->publicId . '/role', ['role' => 'viewer'])->getStatusCode());
        $rival->get('/workspace/members');
        self::assertSame(404, $rival->post('/workspace/members/' . $s->member->publicId . '/remove')->getStatusCode());
        self::assertSame('member', $s->dir->workspaceRoles[$s->acme->id][$s->member->id]);

        $html = (string) $rival->get('/workspace/members')->getBody();
        self::assertStringNotContainsString('member@acme.test', $html);
    }

    public function testRivalOwnerCannotRevokeForeignInvitations(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $owner->get('/workspace/members');
        $owner->post('/workspace/members', ['email' => 'guest@example.test', 'role' => 'member']);
        $invitations = $s->container->get(InMemoryInvitations::class);
        self::assertInstanceOf(InMemoryInvitations::class, $invitations);
        $invitation = $invitations->pending($s->acme->id, '2000-01-01 00:00:00')[0];

        $rival = $s->loggedIn($s->rivalOwner);
        $rival->get('/workspace/members');
        self::assertSame(404, $rival->post('/workspace/invitations/' . $invitation->publicId . '/revoke')->getStatusCode());
        self::assertCount(1, $invitations->pending($s->acme->id, '2000-01-01 00:00:00'));
        self::assertStringNotContainsString('guest@example.test', (string) $rival->get('/workspace/members')->getBody());
    }

    public function testInvitingIntoTheCurrentWorkspaceOnly(): void
    {
        $s = new Scenario();
        $rival = $s->loggedIn($s->rivalOwner);
        $rival->get('/workspace/members');

        // A forged workspace field must be ignored.
        $rival->post('/workspace/members', ['email' => 'viewer@acme.test', 'role' => 'admin', 'workspace' => $s->acme->publicId, 'workspace_id' => (string) $s->acme->id]);

        self::assertSame('viewer', $s->dir->workspaceRoles[$s->acme->id][$s->viewer->id]);
        self::assertSame('admin', $s->dir->workspaceRoles[$s->rival->id][$s->viewer->id] ?? null);
    }
}
