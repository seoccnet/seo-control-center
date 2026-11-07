<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Users change their own name, e-mail address and password.
 */
final class AccountHttpTest extends TestCase
{
    private const string NEW_PASSWORD = 'another long passphrase 456';

    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testNameChangesWithoutAPassword(): void
    {
        $browser = $this->s->loggedIn($this->s->member);
        self::assertStringContainsString('Your account', (string) $browser->get('/account')->getBody());

        self::assertSame(422, $browser->post('/account/profile', ['name' => '', 'email' => 'member@acme.test'])->getStatusCode());
        self::assertSame(303, $browser->post('/account/profile', ['name' => 'Mia Member', 'email' => 'member@acme.test'])->getStatusCode());
        self::assertSame('Mia Member', $this->s->dir->users[$this->s->member->id]['user']->name);
        self::assertContains('account.profile_updated', $this->s->audit()->actions());
    }

    public function testEmailChangesNeedTheCurrentPasswordAndAFreeAddress(): void
    {
        $browser = $this->s->loggedIn($this->s->member);
        $browser->get('/account');

        $noPassword = $browser->post('/account/profile', ['name' => 'Member', 'email' => 'new@acme.test']);
        self::assertSame(422, $noPassword->getStatusCode());
        self::assertStringContainsString('The current password is incorrect.', (string) $noPassword->getBody());

        $taken = $browser->post('/account/profile', ['name' => 'Member', 'email' => 'OWNER@acme.test', 'current_password_profile' => Scenario::PASSWORD]);
        self::assertSame(422, $taken->getStatusCode());
        self::assertStringContainsString('Another account already uses this e-mail address.', (string) $taken->getBody());

        self::assertSame(303, $browser->post('/account/profile', ['name' => 'Member', 'email' => 'New@Acme.test', 'current_password_profile' => Scenario::PASSWORD])->getStatusCode());
        self::assertSame('new@acme.test', $this->s->dir->users[$this->s->member->id]['user']->email);
        self::assertSame(303, $this->s->browser()->login('new@acme.test', Scenario::PASSWORD)->getStatusCode(), 'The new address signs in.');
    }

    public function testPasswordChangeSignsOutOtherSessions(): void
    {
        $other = $this->s->loggedIn($this->s->member);
        self::assertSame(200, $other->get('/projects')->getStatusCode());

        $browser = $this->s->loggedIn($this->s->member);
        $browser->get('/account');
        $wrong = $browser->post('/account/password', ['current_password' => 'nope', 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]);
        self::assertSame(422, $wrong->getStatusCode());
        $weak = $browser->post('/account/password', ['current_password' => Scenario::PASSWORD, 'new_password' => 'short', 'new_password_confirmation' => 'short']);
        self::assertSame(422, $weak->getStatusCode());
        $mismatch = $browser->post('/account/password', ['current_password' => Scenario::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => 'something else']);
        self::assertStringContainsString('The passwords do not match.', (string) $mismatch->getBody());

        $changed = $browser->post('/account/password', ['current_password' => Scenario::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]);
        self::assertSame(303, $changed->getStatusCode());
        self::assertContains('account.password_changed', $this->s->audit()->actions());
        $hasher = $this->s->container->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        self::assertTrue($hasher->verify(self::NEW_PASSWORD, $this->s->dir->users[$this->s->member->id]['hash']));

        self::assertSame(200, $browser->get('/projects')->getStatusCode(), 'This session stays signed in.');
        self::assertSame(303, $other->get('/projects')->getStatusCode(), 'The other session is signed out.');
        self::assertSame(303, $this->s->browser()->login('member@acme.test', self::NEW_PASSWORD)->getStatusCode());
        self::assertNotSame(303, $this->s->browser()->login('member@acme.test', Scenario::PASSWORD)->getStatusCode(), 'The old password no longer works.');
    }

    public function testWrongCurrentPasswordsAreLimited(): void
    {
        $browser = $this->s->loggedIn($this->s->member);
        $browser->get('/account');
        for ($i = 0; $i < 5; ++$i) {
            $browser->post('/account/password', ['current_password' => 'wrong ' . $i, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]);
        }
        $blocked = $browser->post('/account/password', ['current_password' => Scenario::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]);
        self::assertSame(422, $blocked->getStatusCode());
        self::assertStringContainsString('Too many wrong attempts', (string) $blocked->getBody());
        self::assertNotContains('account.password_changed', $this->s->audit()->actions());
    }

    public function testTheAccountPageNeedsASignedInUser(): void
    {
        self::assertSame(303, $this->s->browser()->get('/account')->getStatusCode());
    }
}
