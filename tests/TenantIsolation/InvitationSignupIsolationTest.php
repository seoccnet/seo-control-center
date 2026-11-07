<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Accepting an invitation joins exactly the inviting workspace with the
 * invited role, and the new member's session sees nothing of another
 * workspace. A signed-in member of another workspace who opens the link
 * joins nothing themselves.
 */
final class InvitationSignupIsolationTest extends TestCase
{
    public function testANewMemberJoinsOnlyTheInvitingWorkspace(): void
    {
        $s = new Scenario();
        $link = $this->invite($s->loggedIn($s->rivalOwner), 'rival.hire@example.test', 'member');

        $guest = $s->browser();
        $guest->get($link);
        $accepted = $guest->post($link, ['name' => 'Rival Hire', 'password' => Scenario::PASSWORD, 'password_confirmation' => Scenario::PASSWORD], ['Origin' => 'http://scc.test']);
        self::assertSame(303, $accepted->getStatusCode());

        $hire = null;
        foreach ($s->dir->users as $row) {
            if ($row['user']->email === 'rival.hire@example.test') {
                $hire = $row['user'];
            }
        }
        self::assertNotNull($hire);
        self::assertSame('member', $s->dir->workspaceRoles[$s->rival->id][$hire->id] ?? null);
        self::assertArrayNotHasKey($hire->id, $s->dir->workspaceRoles[$s->acme->id]);

        self::assertSame(404, $guest->get('/projects/' . $s->acmeProject->publicId)->getStatusCode());
        $members = (string) $guest->get('/workspace/members')->getBody();
        self::assertStringContainsString('rival.hire@example.test', $members);
        self::assertStringNotContainsString('owner@acme.test', $members);
    }

    public function testASignedInOutsiderOpeningTheLinkDoesNotJoin(): void
    {
        $s = new Scenario();
        $link = $this->invite($s->loggedIn($s->owner), 'acme.hire@example.test', 'admin');

        $rival = $s->loggedIn($s->rivalOwner);
        $page = $rival->get($link);
        self::assertStringContainsString('acme.hire@example.test', (string) $page->getBody(), 'The invitation names its own address.');
        $rival->post($link, ['name' => 'Acme Hire', 'password' => Scenario::PASSWORD, 'password_confirmation' => Scenario::PASSWORD], ['Origin' => 'http://scc.test']);

        self::assertArrayNotHasKey($s->rivalOwner->id, $s->dir->workspaceRoles[$s->acme->id], 'Only the invited address joins.');
        self::assertSame(404, $s->loggedIn($s->rivalOwner)->get('/projects/' . $s->acmeProject->publicId)->getStatusCode());
    }

    private function invite(Browser $inviter, string $email, string $role): string
    {
        $inviter->get('/workspace/members');
        $html = (string) $inviter->post('/workspace/members', ['email' => $email, 'role' => $role])->getBody();
        self::assertSame(1, preg_match('~https?://[^/<"]+(/invite/[A-Za-z0-9_-]{43})~', $html, $m), 'The page shows the invitation link.');

        return $m[1];
    }
}
