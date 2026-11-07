<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Mail\MailSettings;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Tests\Support\Fakes\FakeMailer;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMailSettings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPasswordResets;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * E-mail (ADR-027): System → E-mail, "forgot password" and e-mailed invitations.
 */
final class EmailHttpTest extends TestCase
{
    private const string NEW_PASSWORD = 'a brand new passphrase 789';

    private Scenario $s;

    private FakeMailer $mailer;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $mailer = $this->s->container->get(FakeMailer::class);
        self::assertInstanceOf(FakeMailer::class, $mailer);
        $this->mailer = $mailer;
    }

    public function testAdminsSaveSettingsAndSendATestEmail(): void
    {
        $root = $this->s->addUser('root@acme.test', SystemRole::Admin);
        $browser = $this->s->loggedIn($root);
        self::assertStringContainsString('Send e-mails with', (string) $browser->get('/system/email')->getBody());

        $invalid = $browser->post('/system/email', ['transport' => 'smtp', 'host' => 'bad host!', 'port' => '99999', 'from_address' => 'nope']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Enter the SMTP server', (string) $invalid->getBody());

        $saved = $browser->post('/system/email', [
            'transport' => 'smtp', 'host' => 'Mail.Example.com', 'port' => '587', 'encryption' => 'tls',
            'username' => 'noreply@example.com', 'password' => 's3cret', 'from_address' => 'noreply@example.com', 'from_name' => 'SCC',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $store = $this->s->container->get(InMemoryMailSettings::class);
        self::assertInstanceOf(InMemoryMailSettings::class, $store);
        self::assertSame('mail.example.com', $store->settings->host);
        self::assertSame('s3cret', $store->settings->password);
        self::assertContains('system.mail_settings_updated', $this->s->audit()->actions());
        self::assertStringNotContainsString('s3cret', (string) $browser->get('/system/email')->getBody(), 'The password is never shown.');

        $browser->post('/system/email', ['transport' => 'smtp', 'host' => 'mail.example.com', 'from_address' => 'noreply@example.com', 'password' => '']);
        self::assertSame('s3cret', $store->settings->password, 'An empty field keeps the password.');

        $this->mailer->configured = true;
        $browser->get('/system/email');
        self::assertSame(303, $browser->post('/system/email/test')->getStatusCode());
        self::assertSame('root@acme.test', $this->mailer->last()?->to);
        self::assertStringContainsString('Test e-mail sent', (string) $browser->get('/system/email')->getBody());

        $this->mailer->failWith = 'The mail server rejected the user name or password.';
        $browser->post('/system/email/test');
        self::assertStringContainsString('could not be sent: The mail server rejected', (string) $browser->get('/system/email')->getBody());
    }

    public function testOnlyInstallationAdminsSeeEmailSettings(): void
    {
        self::assertSame(403, $this->s->loggedIn($this->s->owner)->get('/system/email')->getStatusCode());
    }

    public function testForgotPasswordWithoutEmailExplainsTheAlternative(): void
    {
        $page = (string) $this->s->browser()->get('/forgot-password')->getBody();
        self::assertStringContainsString('not set up on this installation', $page);
        self::assertStringContainsString('/forgot-password', (string) $this->s->browser()->get('/login')->getBody());
    }

    public function testPasswordResetEndToEnd(): void
    {
        $this->mailer->configured = true;
        $old = $this->s->loggedIn($this->s->member);
        self::assertSame(200, $old->get('/projects')->getStatusCode());

        $guest = $this->s->browser();
        $guest->get('/forgot-password');
        $sent = $guest->post('/forgot-password', ['email' => 'Member@Acme.test']);
        self::assertStringContainsString('If an account exists', (string) $sent->getBody());
        $message = $this->mailer->last();
        self::assertNotNull($message);
        self::assertSame('member@acme.test', $message->to);
        self::assertSame(1, preg_match('~(/reset-password/[A-Za-z0-9_-]{43})~', $message->body, $m));
        $link = $m[1];
        $resets = $this->s->container->get(InMemoryPasswordResets::class);
        self::assertInstanceOf(InMemoryPasswordResets::class, $resets);
        self::assertSame(32, strlen($resets->rows[0]['hash']), 'Only the binary hash is stored.');

        $page = $guest->get($link);
        self::assertStringContainsString('member@acme.test', (string) $page->getBody());
        // Under "no-referrer" browsers would post the form with "Origin: null", which the CSRF check refuses.
        self::assertSame('same-origin', $page->getHeaderLine('Referrer-Policy'));
        $mismatch = $guest->post($link, ['password' => self::NEW_PASSWORD, 'password_confirmation' => 'other'], ['Origin' => 'http://scc.test']);
        self::assertSame(422, $mismatch->getStatusCode());
        $done = $guest->post($link, ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], ['Origin' => 'http://scc.test']);
        self::assertSame(303, $done->getStatusCode());
        self::assertSame('/login', $done->getHeaderLine('Location'));
        self::assertContains('auth.password_reset', $this->s->audit()->actions());

        $hasher = $this->s->container->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        self::assertTrue($hasher->verify(self::NEW_PASSWORD, $this->s->dir->users[$this->s->member->id]['hash']));
        self::assertSame(303, $old->get('/projects')->getStatusCode(), 'Existing sessions are signed out.');
        self::assertSame(404, $this->s->browser()->get($link)->getStatusCode(), 'The link works once.');
    }

    public function testUnknownAddressesLookTheSameAndSendNothing(): void
    {
        $this->mailer->configured = true;
        $guest = $this->s->browser();
        $guest->get('/forgot-password');
        $response = $guest->post('/forgot-password', ['email' => 'nobody@acme.test']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('If an account exists', (string) $response->getBody());
        self::assertSame([], $this->mailer->sent);
    }

    public function testANewRequestInvalidatesTheOldLinkAndRequestsAreLimited(): void
    {
        $this->mailer->configured = true;
        $guest = $this->s->browser();
        $guest->get('/forgot-password');
        $guest->post('/forgot-password', ['email' => 'member@acme.test']);
        self::assertSame(1, preg_match('~(/reset-password/[A-Za-z0-9_-]{43})~', (string) $this->mailer->last()?->body, $first));
        $guest->get('/forgot-password');
        $guest->post('/forgot-password', ['email' => 'member@acme.test']);
        self::assertSame(404, $this->s->browser()->get($first[1])->getStatusCode(), 'The older link stops working.');

        $guest->get('/forgot-password');
        $guest->post('/forgot-password', ['email' => 'member@acme.test']);
        $guest->get('/forgot-password');
        $limited = $guest->post('/forgot-password', ['email' => 'member@acme.test']);
        self::assertSame(429, $limited->getStatusCode());
        self::assertCount(3, $this->mailer->sent);
    }

    public function testInvitationsAreEmailedWhenConfigured(): void
    {
        $this->mailer->configured = true;
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/workspace/members');
        $page = (string) $owner->post('/workspace/members', ['email' => 'guest@example.test', 'role' => 'member'])->getBody();
        self::assertStringContainsString('We sent the invitation to', $page);
        $message = $this->mailer->last();
        self::assertNotNull($message);
        self::assertSame('guest@example.test', $message->to);
        self::assertSame(1, preg_match('~/invite/[A-Za-z0-9_-]{43}~', $message->body));

        $this->mailer->failWith = 'down';
        $owner->get('/workspace/members');
        $fallback = (string) $owner->post('/workspace/members', ['email' => 'other@example.test', 'role' => 'viewer'])->getBody();
        self::assertStringContainsString('Copy the link now', $fallback, 'A failed e-mail falls back to sharing the link.');
    }

    public function testSettingsDefaultToOff(): void
    {
        self::assertFalse((new MailSettings())->isConfigured());
        self::assertSame(465, (new MailSettings('smtp', 'h', null, 'ssl'))->port());
    }
}
