<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Workspaces\Invitation;
use SeoControlCenter\Tests\Support\Fakes\InMemoryInvitations;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The invitee's side of an invitation, submitted the way a real browser does:
 * with the Origin header that the page's Referrer-Policy produces. Under
 * "no-referrer" browsers send `Origin: null`, which the CSRF check refuses,
 * so invitees could open the link but never create their account.
 */
final class InvitationSignupHttpTest extends TestCase
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

    public function testNewUserCreatesTheirAccountAndLandsSignedInInTheWorkspace(): void
    {
        $link = $this->invite('fresh@example.test', 'viewer');

        $guest = $this->s->browser();
        $page = $guest->get($link);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('same-origin', $page->getHeaderLine('Referrer-Policy'), 'The token never leaks to other sites, but the form can still be posted.');
        self::assertStringContainsString('Create account and join', (string) $page->getBody());

        $accepted = $guest->post($link, [
            'name' => 'Fresh Person',
            'password' => Scenario::PASSWORD,
            'password_confirmation' => Scenario::PASSWORD,
        ], ['Origin' => $this->browserOrigin($page)]);
        self::assertSame(303, $accepted->getStatusCode());
        self::assertSame('/', $accepted->getHeaderLine('Location'));

        $user = $this->userByEmail('fresh@example.test');
        self::assertSame('Fresh Person', $user->name);
        self::assertSame('viewer', $this->s->dir->workspaceRoles[$this->s->acme->id][$user->id] ?? null);
        self::assertArrayNotHasKey($user->id, $this->s->dir->workspaceRoles[$this->s->rival->id]);
        self::assertContains('auth.login', $this->s->audit()->actions());

        $members = $guest->get(self::PAGE);
        self::assertSame(200, $members->getStatusCode(), 'The new member is signed in.');
        self::assertStringContainsString('fresh@example.test', (string) $members->getBody());
    }

    public function testExistingUserJoinsAndSignsInWithTheirOwnPassword(): void
    {
        $link = $this->invite('later@example.test', 'admin');
        $later = $this->s->addUser('later@example.test');

        $guest = $this->s->browser();
        $page = $guest->get($link);
        self::assertStringContainsString('Join workspace', (string) $page->getBody());

        $accepted = $guest->post($link, [], ['Origin' => $this->browserOrigin($page)]);
        self::assertSame(303, $accepted->getStatusCode());
        self::assertSame('/login', $accepted->getHeaderLine('Location'), 'Holding the link is no proof of the existing password.');
        self::assertSame('admin', $this->s->dir->workspaceRoles[$this->s->acme->id][$later->id] ?? null);
        self::assertStringContainsString('You joined Acme', (string) $guest->get('/login')->getBody());
        self::assertSame(303, $guest->get(self::PAGE)->getStatusCode(), 'Not signed in by the link alone.');
    }

    public function testUsedExpiredAndUnknownLinksAreRefused(): void
    {
        $used = $this->invite('once@example.test', 'member');
        $guest = $this->s->browser();
        $page = $guest->get($used);
        $guest->post($used, ['name' => 'Once', 'password' => Scenario::PASSWORD, 'password_confirmation' => Scenario::PASSWORD], ['Origin' => $this->browserOrigin($page)]);
        $again = $this->s->browser();
        self::assertSame(404, $again->get($used)->getStatusCode());
        $again->get('/login');
        self::assertSame(404, $again->post($used, ['name' => 'Twice', 'password' => Scenario::PASSWORD, 'password_confirmation' => Scenario::PASSWORD], ['Origin' => 'http://scc.test'])->getStatusCode());

        $expired = $this->invite('late@example.test', 'member');
        $guest = $this->s->browser();
        $page = $guest->get($expired);
        foreach ($this->invitations->rows as $id => $row) {
            $i = $row['invitation'];
            $this->invitations->rows[$id]['invitation'] = new Invitation($i->id, $i->publicId, $i->workspaceId, $i->workspaceName, $i->email, $i->roleKey, $i->invitedByName, $i->createdAt, '2000-01-01 00:00:00');
        }
        self::assertSame(404, $guest->post($expired, ['name' => 'Late', 'password' => Scenario::PASSWORD, 'password_confirmation' => Scenario::PASSWORD], ['Origin' => $this->browserOrigin($page)])->getStatusCode());

        $unknown = '/invite/' . str_repeat('x', 43);
        $guest = $this->s->browser();
        $guest->get('/login');
        self::assertSame(404, $guest->post($unknown, ['name' => 'Nobody', 'password' => Scenario::PASSWORD, 'password_confirmation' => Scenario::PASSWORD], ['Origin' => 'http://scc.test'])->getStatusCode());

        foreach (['late@example.test', 'nobody@example.test'] as $email) {
            foreach ($this->s->dir->users as $row) {
                self::assertNotSame($email, $row['user']->email, 'No account is created from a dead link.');
            }
        }
    }

    public function testFormsPostedFromAnotherSiteAreStillRefused(): void
    {
        $link = $this->invite('target@example.test', 'member');
        $guest = $this->s->browser();
        $guest->get($link);

        $forged = $guest->post($link, ['name' => 'Target', 'password' => Scenario::PASSWORD, 'password_confirmation' => Scenario::PASSWORD], ['Origin' => 'https://evil.example']);
        self::assertSame(403, $forged->getStatusCode());
        self::assertCount(1, $this->invitations->pending($this->s->acme->id, '2000-01-01 00:00:00'));
    }

    /**
     * Creates an invitation as the Acme owner and returns its link path.
     */
    private function invite(string $email, string $role): string
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get(self::PAGE);
        $html = (string) $owner->post(self::PAGE, ['email' => $email, 'role' => $role])->getBody();
        self::assertSame(1, preg_match('~https?://[^/<"]+(/invite/[A-Za-z0-9_-]{43})~', $html, $m), 'The page shows the invitation link.');

        return $m[1];
    }

    /**
     * The Origin a browser sends when it submits a form on this page to the
     * same site (Fetch, "serializing a request origin"): "null" under the
     * "no-referrer" policy, the page's own origin otherwise.
     */
    private function browserOrigin(ResponseInterface $page): string
    {
        return strtolower(trim($page->getHeaderLine('Referrer-Policy'))) === 'no-referrer' ? 'null' : 'http://scc.test';
    }

    private function userByEmail(string $email): User
    {
        foreach ($this->s->dir->users as $row) {
            if ($row['user']->email === $email) {
                return $row['user'];
            }
        }
        self::fail('No user ' . $email);
    }
}
