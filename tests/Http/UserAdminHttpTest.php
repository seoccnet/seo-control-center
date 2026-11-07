<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Accounts\MfaRepository;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Fakes\FakeMailer;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMfaRepository;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * System → Users: deactivate, reactivate, reset two-factor, send reset links,
 * grant and remove administrator rights, with the self and last-admin guards.
 */
final class UserAdminHttpTest extends TestCase
{
    private Scenario $s;

    private User $root;

    private Browser $admin;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->root = $this->s->addUser('root@acme.test', SystemRole::Admin);
        $this->admin = $this->s->loggedIn($this->root);
    }

    public function testTheListShowsEveryUser(): void
    {
        $page = (string) $this->admin->get('/system/users')->getBody();
        self::assertStringContainsString('member@acme.test', $page);
        self::assertStringContainsString('owner@rival.test', $page);
        self::assertStringContainsString('Acme', $page);
        self::assertStringNotContainsString('/system/users/' . $this->root->publicId . '/deactivate', $page, 'No self-deactivation button.');

        self::assertSame(403, $this->s->loggedIn($this->s->owner)->get('/system/users')->getStatusCode(), 'Workspace owners are not installation admins.');
    }

    public function testDeactivationSignsOutAndBlocksSignIn(): void
    {
        $member = $this->s->loggedIn($this->s->member);
        self::assertSame(200, $member->get('/projects')->getStatusCode());

        $this->admin->get('/system/users');
        self::assertSame(303, $this->admin->post($this->url($this->s->member, 'deactivate'))->getStatusCode());
        self::assertFalse($this->s->dir->users[$this->s->member->id]['user']->active);
        self::assertContains('user.deactivated', $this->s->audit()->actions());
        self::assertSame(303, $member->get('/projects')->getStatusCode(), 'The session ended.');
        self::assertNotSame(303, $this->s->browser()->login('member@acme.test', Scenario::PASSWORD)->getStatusCode(), 'Sign-in is refused.');

        $this->admin->get('/system/users');
        $this->admin->post($this->url($this->s->member, 'activate'));
        self::assertTrue($this->s->dir->users[$this->s->member->id]['user']->active);
        self::assertSame(303, $this->s->browser()->login('member@acme.test', Scenario::PASSWORD)->getStatusCode());
    }

    public function testAdminsCannotLockThemselvesOrTheInstallationOut(): void
    {
        $this->admin->get('/system/users');
        $this->admin->post($this->url($this->root, 'deactivate'));
        self::assertTrue($this->s->dir->users[$this->root->id]['user']->active);
        self::assertStringContainsString('You cannot deactivate your own account.', (string) $this->admin->get('/system/users')->getBody());

        $this->admin->post($this->url($this->root, 'revoke-admin'));
        self::assertSame(SystemRole::Admin, $this->s->dir->users[$this->root->id]['user']->systemRole);

        $this->admin->get('/system/users');
        $this->admin->post($this->url($this->s->owner, 'grant-admin'));
        self::assertSame(SystemRole::Admin, $this->s->dir->users[$this->s->owner->id]['user']->systemRole);
        self::assertContains('user.admin_granted', $this->s->audit()->actions());

        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/system/users');
        $owner->post($this->url($this->root, 'revoke-admin'));
        self::assertSame(SystemRole::None, $this->s->dir->users[$this->root->id]['user']->systemRole, 'With two admins, one may remove the other.');
        $owner->get('/system/users');
        $owner->post($this->url($this->s->owner, 'revoke-admin'));
        self::assertSame(SystemRole::Admin, $this->s->dir->users[$this->s->owner->id]['user']->systemRole, 'The last admin stays.');
    }

    public function testResetTwoFactor(): void
    {
        $mfa = $this->s->container->get(MfaRepository::class);
        self::assertInstanceOf(InMemoryMfaRepository::class, $mfa);
        $mfa->saveTotp($this->s->member->id, 'secret');
        $mfa->replaceRecoveryCodes($this->s->member->id, ['a', 'b']);
        $this->s->dir->mfaUsers[$this->s->member->id] = true;

        $this->admin->get('/system/users');
        self::assertSame(303, $this->admin->post($this->url($this->s->member, 'reset-mfa'))->getStatusCode());
        self::assertNull($mfa->totpFor($this->s->member->id));
        self::assertSame(0, $mfa->remainingRecoveryCodes($this->s->member->id));
        self::assertContains('mfa.reset_by_admin', $this->s->audit()->actions());
    }

    public function testSendResetLink(): void
    {
        $mailer = $this->s->container->get(FakeMailer::class);
        self::assertInstanceOf(FakeMailer::class, $mailer);
        self::assertStringNotContainsString('Send reset link', (string) $this->admin->get('/system/users')->getBody(), 'Hidden without e-mail.');

        $mailer->configured = true;
        $this->admin->get('/system/users');
        $this->admin->post($this->url($this->s->member, 'send-reset'));
        self::assertSame('member@acme.test', $mailer->last()?->to);
        self::assertStringContainsString('A password-reset link was sent', (string) $this->admin->get('/system/users')->getBody());

        $mailer->failWith = 'down';
        $this->admin->post($this->url($this->s->member, 'send-reset'));
        self::assertStringContainsString('could not be sent', (string) $this->admin->get('/system/users')->getBody());
    }

    public function testUnknownUsersAre404(): void
    {
        $this->admin->get('/system/users');
        self::assertSame(404, $this->admin->post('/system/users/00000000-0000-7000-8000-000000000000/deactivate')->getStatusCode());
    }

    private function url(User $user, string $action): string
    {
        return '/system/users/' . $user->publicId . '/' . $action;
    }
}
