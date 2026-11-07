<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Workspaces\Invitation;
use SeoControlCenter\Tests\Support\Fakes\InMemoryInvitations;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Members: adding existing users, invitation links (create, accept, revoke,
 * expiry, throttling), role changes and removal with their guard rules.
 */
final class MembersHttpTest extends TestCase
{
    private const string PAGE = '/workspace/members';

    private Scenario $s;

    private InMemoryInvitations $invitations;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $invitations = $this->s->container->get(InMemoryInvitations::class);
        self::assertInstanceOf(InMemoryInvitations::class, $invitations);
        $this->invitations = $invitations;
    }

    public function testOnlyManagersSeeTheAddForm(): void
    {
        $owner = (string) $this->s->loggedIn($this->s->owner)->get(self::PAGE)->getBody();
        self::assertStringContainsString('Add a member', $owner);
        self::assertStringContainsString('/workspace/members/' . $this->s->member->publicId . '/remove', $owner);
        self::assertStringNotContainsString('/workspace/members/' . $this->s->owner->publicId . '/remove', $owner, 'Nobody removes themselves.');

        $admin = (string) $this->s->loggedIn($this->s->admin)->get(self::PAGE)->getBody();
        self::assertStringContainsString('Add a member', $admin);
        self::assertStringNotContainsString('/workspace/members/' . $this->s->owner->publicId . '/remove', $admin, 'Admins cannot remove owners.');

        $viewer = $this->s->loggedIn($this->s->viewer)->get(self::PAGE);
        self::assertSame(200, $viewer->getStatusCode());
        self::assertStringContainsString('owner@acme.test', (string) $viewer->getBody());
        self::assertStringNotContainsString('Add a member', (string) $viewer->getBody());

        $browser = $this->s->loggedIn($this->s->member);
        $browser->get('/account/security');
        self::assertSame(403, $browser->post(self::PAGE, ['email' => 'new@acme.test', 'role' => 'viewer'])->getStatusCode());
        self::assertSame([], $this->invitations->rows);
    }

    public function testInvitationLinkCreatesAnAccountAndMembership(): void
    {
        $admin = $this->s->loggedIn($this->s->admin);
        $admin->get(self::PAGE);
        $created = $admin->post(self::PAGE, ['email' => ' New.Person@Example.test ', 'role' => 'member']);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('no-store', $created->getHeaderLine('Cache-Control'));
        $link = $this->link((string) $created->getBody());
        self::assertContains('workspace.invitation_created', $this->s->audit()->actions());

        $invitation = $this->onlyInvitation();
        self::assertSame('new.person@example.test', $invitation->email);
        self::assertSame(32, strlen($this->invitations->rows[$invitation->id]['hash']), 'Only the binary SHA-256 is stored.');
        self::assertStringNotContainsString($link, (string) $admin->get(self::PAGE)->getBody(), 'The link is shown once.');
        self::assertStringContainsString('new.person@example.test', (string) $admin->get(self::PAGE)->getBody());

        $guest = $this->s->browser();
        $page = $guest->get($link);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Join Acme', (string) $page->getBody());
        self::assertStringContainsString('Create account and join', (string) $page->getBody());

        $mismatch = $guest->post($link, ['name' => 'New Person', 'password' => Scenario::PASSWORD, 'password_confirmation' => 'something else entirely']);
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertStringContainsString('The passwords do not match.', (string) $mismatch->getBody());
        $weak = $guest->post($link, ['name' => 'New Person', 'password' => 'short', 'password_confirmation' => 'short']);
        self::assertSame(422, $weak->getStatusCode());
        self::assertSame($invitation->id, $this->onlyInvitation()->id, 'A rejected password does not use up the invitation.');

        $accepted = $guest->post($link, ['name' => 'New Person', 'password' => Scenario::PASSWORD, 'password_confirmation' => Scenario::PASSWORD]);
        self::assertSame(303, $accepted->getStatusCode());
        self::assertSame('/', $accepted->getHeaderLine('Location'), 'The new member is signed in.');
        self::assertContains('workspace.invitation_accepted', $this->s->audit()->actions());

        $user = null;
        foreach ($this->s->dir->users as $row) {
            if ($row['user']->email === 'new.person@example.test') {
                $user = $row['user'];
            }
        }
        self::assertNotNull($user);
        self::assertSame('member', $this->s->dir->workspaceRoles[$this->s->acme->id][$user->id] ?? null);
        self::assertSame(404, $this->s->browser()->get($link)->getStatusCode(), 'A link works once.');
        self::assertSame([], $this->invitations->pending($this->s->acme->id, '2000-01-01 00:00:00'));

        self::assertSame(303, $this->s->browser()->login('new.person@example.test', Scenario::PASSWORD)->getStatusCode());
    }

    public function testExistingUsersAreAddedDirectly(): void
    {
        $outsider = $this->s->addUser('freelancer@example.test');
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get(self::PAGE);

        $added = $owner->post(self::PAGE, ['email' => 'FREELANCER@example.test', 'role' => 'viewer']);
        self::assertSame(303, $added->getStatusCode());
        self::assertSame('viewer', $this->s->dir->workspaceRoles[$this->s->acme->id][$outsider->id] ?? null);
        self::assertContains('workspace.member_added', $this->s->audit()->actions());
        self::assertSame([], $this->invitations->rows);

        $again = $owner->post(self::PAGE, ['email' => 'freelancer@example.test', 'role' => 'viewer']);
        self::assertSame(422, $again->getStatusCode());
        self::assertStringContainsString('already a member', (string) $again->getBody());
    }

    public function testInviteValidation(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get(self::PAGE);

        $owned = $owner->post(self::PAGE, ['email' => 'boss@example.test', 'role' => 'owner']);
        self::assertSame(422, $owned->getStatusCode(), 'Owner is not assignable.');
        self::assertStringContainsString('Choose Admin, Member or Viewer.', (string) $owned->getBody());
        self::assertSame(422, $owner->post(self::PAGE, ['email' => 'not-an-address', 'role' => 'member'])->getStatusCode());

        self::assertSame(201, $owner->post(self::PAGE, ['email' => 'twice@example.test', 'role' => 'member'])->getStatusCode());
        $duplicate = $owner->post(self::PAGE, ['email' => 'twice@example.test', 'role' => 'admin']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertStringContainsString('already has an open invitation', (string) $duplicate->getBody());
    }

    public function testRevokedAndExpiredLinksStopWorking(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get(self::PAGE);
        $link = $this->link((string) $owner->post(self::PAGE, ['email' => 'late@example.test', 'role' => 'member'])->getBody());
        $invitation = $this->onlyInvitation();

        self::assertSame(303, $owner->post('/workspace/invitations/' . $invitation->publicId . '/revoke')->getStatusCode());
        self::assertContains('workspace.invitation_revoked', $this->s->audit()->actions());
        $revoked = $this->s->browser()->get($link);
        self::assertSame(404, $revoked->getStatusCode());
        self::assertStringContainsString('not valid', (string) $revoked->getBody());
        self::assertSame(404, $owner->post('/workspace/invitations/' . $invitation->publicId . '/revoke')->getStatusCode());

        $owner->get(self::PAGE);
        $link = $this->link((string) $owner->post(self::PAGE, ['email' => 'late@example.test', 'role' => 'member'])->getBody());
        foreach ($this->invitations->rows as $id => $row) {
            $i = $row['invitation'];
            $this->invitations->rows[$id]['invitation'] = new Invitation($i->id, $i->publicId, $i->workspaceId, $i->workspaceName, $i->email, $i->roleKey, $i->invitedByName, $i->createdAt, '2000-01-01 00:00:00');
        }
        self::assertSame(404, $this->s->browser()->get($link)->getStatusCode(), 'Expired links are invalid.');
    }

    public function testInvalidLinksAreThrottledPerIp(): void
    {
        $guest = $this->s->browser();
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(404, $guest->get('/invite/' . str_repeat('a', 42) . chr(65 + $i))->getStatusCode());
        }
        self::assertSame(429, $guest->get('/invite/' . str_repeat('b', 43))->getStatusCode());
    }

    public function testRoleChangesFollowTheRules(): void
    {
        $admin = $this->s->loggedIn($this->s->admin);
        $admin->get(self::PAGE);
        $acme = $this->s->acme->id;

        self::assertSame(303, $admin->post($this->memberUrl($this->s->member->publicId, 'role'), ['role' => 'viewer'])->getStatusCode());
        self::assertSame('viewer', $this->s->dir->workspaceRoles[$acme][$this->s->member->id]);
        self::assertContains('workspace.member_role_changed', $this->s->audit()->actions());

        $admin->post($this->memberUrl($this->s->owner->publicId, 'role'), ['role' => 'viewer']);
        self::assertSame('owner', $this->s->dir->workspaceRoles[$acme][$this->s->owner->id]);
        self::assertStringContainsString('Only an owner can change the role of an owner.', (string) $admin->get(self::PAGE)->getBody());

        $admin->post($this->memberUrl($this->s->admin->publicId, 'role'), ['role' => 'member']);
        self::assertSame('admin', $this->s->dir->workspaceRoles[$acme][$this->s->admin->id]);
        self::assertStringContainsString('You cannot change the role of yourself.', (string) $admin->get(self::PAGE)->getBody());

        $admin->post($this->memberUrl($this->s->viewer->publicId, 'role'), ['role' => 'owner']);
        self::assertSame('viewer', $this->s->dir->workspaceRoles[$acme][$this->s->viewer->id], 'Owner is not assignable.');

        self::assertSame(404, $admin->post($this->memberUrl($this->s->rivalOwner->publicId, 'role'), ['role' => 'viewer'])->getStatusCode());
    }

    public function testRemovalFollowsTheRules(): void
    {
        $acme = $this->s->acme->id;
        $admin = $this->s->loggedIn($this->s->admin);
        $admin->get(self::PAGE);

        $admin->post($this->memberUrl($this->s->owner->publicId, 'remove'));
        self::assertArrayHasKey($this->s->owner->id, $this->s->dir->workspaceRoles[$acme]);
        self::assertStringContainsString('Only an owner can remove an owner.', (string) $admin->get(self::PAGE)->getBody());

        $admin->post($this->memberUrl($this->s->admin->publicId, 'remove'));
        self::assertArrayHasKey($this->s->admin->id, $this->s->dir->workspaceRoles[$acme]);

        self::assertSame(303, $admin->post($this->memberUrl($this->s->member->publicId, 'remove'))->getStatusCode());
        self::assertArrayNotHasKey($this->s->member->id, $this->s->dir->workspaceRoles[$acme]);
        self::assertArrayNotHasKey($this->s->member->id, $this->s->dir->projectRoles[$this->s->acmeProject->id] ?? []);
        self::assertContains('workspace.member_removed', $this->s->audit()->actions());

        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get(self::PAGE);
        self::assertSame(303, $owner->post($this->memberUrl($this->s->admin->publicId, 'remove'))->getStatusCode());
        self::assertArrayNotHasKey($this->s->admin->id, $this->s->dir->workspaceRoles[$acme]);
    }

    public function testExistingAccountJoinsWithoutAPassword(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get(self::PAGE);
        $link = $this->link((string) $owner->post(self::PAGE, ['email' => 'later@example.test', 'role' => 'admin'])->getBody());
        $later = $this->s->addUser('later@example.test');

        $guest = $this->s->browser();
        self::assertStringContainsString('Join workspace', (string) $guest->get($link)->getBody());
        self::assertSame(303, $guest->post($link)->getStatusCode());
        self::assertSame('admin', $this->s->dir->workspaceRoles[$this->s->acme->id][$later->id] ?? null);
        self::assertStringContainsString('You joined Acme', (string) $guest->get('/login')->getBody());
    }

    private function link(string $html): string
    {
        self::assertSame(1, preg_match('~https?://[^/<"]+(/invite/[A-Za-z0-9_-]{43})~', $html, $m), 'The page shows the invitation link.');

        return $m[1];
    }

    private function onlyInvitation(): Invitation
    {
        $pending = $this->invitations->pending($this->s->acme->id, '2000-01-01 00:00:00');
        self::assertCount(1, $pending);

        return $pending[0];
    }

    private function memberUrl(string $userPublicId, string $action): string
    {
        return '/workspace/members/' . $userPublicId . '/' . $action;
    }
}
