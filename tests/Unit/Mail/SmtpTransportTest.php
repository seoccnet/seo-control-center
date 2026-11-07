<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Mail\MailException;
use SeoControlCenter\Core\Mail\MailSettings;
use SeoControlCenter\Core\Mail\Message;
use SeoControlCenter\Core\Mail\MessageFormatter;
use SeoControlCenter\Core\Mail\SmtpTransport;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Tests\Support\Fakes\ScriptedSmtpServer;

/**
 * The SMTP dialogue against a scripted server.
 */
final class SmtpTransportTest extends TestCase
{
    public function testStartTlsAuthAndDelivery(): void
    {
        $server = new ScriptedSmtpServer([
            '220 mail.example.com ESMTP',
            '250-mail.example.com', '250-STARTTLS', '250 SIZE 1000000',
            '220 Ready to start TLS',
            '250-mail.example.com', '250-AUTH PLAIN LOGIN', '250 SIZE 1000000',
            '235 Authenticated',
            '250 OK', '250 OK', '354 Go ahead', '250 Queued', '221 Bye',
        ]);
        $this->transport($server)->send($this->settings('tls'), new Message('anna@client.test', 'Hello', ".leading dot\nsecond line", 'Anna'));

        self::assertSame(['mail.example.com', 587, false], $server->opened);
        self::assertTrue($server->tlsEnabled);
        self::assertTrue($server->closed);
        $commands = $server->commands();
        self::assertSame('EHLO seo.example.com', $commands[0]);
        self::assertSame('STARTTLS', $commands[1]);
        self::assertSame('AUTH PLAIN ' . base64_encode("\0noreply@example.com\0s3cret"), $commands[3]);
        self::assertSame('MAIL FROM:<noreply@example.com>', $commands[4]);
        self::assertSame('RCPT TO:<anna@client.test>', $commands[5]);
        self::assertSame('DATA', $commands[6]);
        $data = $server->written[7];
        self::assertStringContainsString("To: Anna <anna@client.test>\r\n", $data);
        self::assertStringContainsString("From: SEO Control Center <noreply@example.com>\r\n", $data);
        self::assertStringContainsString("\r\n..leading dot\r\n", $data, 'Lines starting with a dot are stuffed.');
        self::assertStringEndsWith("\r\n.\r\n", $data);
    }

    public function testAuthLoginWhenPlainIsNotOffered(): void
    {
        $server = new ScriptedSmtpServer([
            '220 hi', '250-hi', '250 AUTH LOGIN',
            '334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '235 OK',
            '250 OK', '250 OK', '354 Go', '250 OK', '221 Bye',
        ]);
        $this->transport($server)->send($this->settings('ssl'), new Message('anna@client.test', 'Hi', 'Body'));

        self::assertSame(['mail.example.com', 465, true], $server->opened);
        self::assertFalse($server->tlsEnabled, 'Implicit TLS needs no STARTTLS.');
        $commands = $server->commands();
        self::assertSame('AUTH LOGIN', $commands[1]);
        self::assertSame(base64_encode('noreply@example.com'), $commands[2]);
        self::assertSame(base64_encode('s3cret'), $commands[3]);
    }

    public function testRejectedLoginNeverEchoesThePassword(): void
    {
        $server = new ScriptedSmtpServer(['220 hi', '250-hi', '250 AUTH PLAIN', '535 5.7.8 Authentication failed for s3cret?']);
        try {
            $this->transport($server)->send($this->settings('none'), new Message('anna@client.test', 'Hi', 'Body'));
            self::fail('Rejected login accepted.');
        } catch (MailException $e) {
            self::assertSame('The mail server rejected the user name or password.', $e->getMessage());
        }
        self::assertTrue($server->closed);
    }

    public function testMissingStartTlsAndRefusedRecipientsAreReported(): void
    {
        $server = new ScriptedSmtpServer(['220 hi', '250 hi']);
        $this->expectExceptionMessage('does not offer STARTTLS');
        $this->transport($server)->send($this->settings('tls'), new Message('anna@client.test', 'Hi', 'Body'));
    }

    public function testRefusedRecipient(): void
    {
        $server = new ScriptedSmtpServer(['220 hi', '250 hi', '250 OK', '550 5.1.1 No such user']);
        $settings = new MailSettings('smtp', 'mail.example.com', 25, 'none', null, null, 'noreply@example.com', null);
        $this->expectExceptionMessage('The mail server refused RCPT: 550 5.1.1 No such user');
        $this->transport($server)->send($settings, new Message('nobody@client.test', 'Hi', 'Body'));
    }

    public function testNonAsciiHeadersAreEncoded(): void
    {
        $formatter = new MessageFormatter(new FrozenClock(new \DateTimeImmutable('2026-10-02 12:00:00')));
        $formatted = $formatter->format(new Message('anna@client.test', 'Grüße', "Zeile 1\nZeile 2 ü", 'Jürgen'), 'noreply@example.com', 'Team, Inc.');

        self::assertSame('=?UTF-8?B?' . base64_encode('Grüße') . '?=', $formatted['headers']['Subject']);
        self::assertSame('=?UTF-8?B?' . base64_encode('Jürgen') . '?= <anna@client.test>', $formatted['headers']['To']);
        self::assertSame('"Team, Inc." <noreply@example.com>', $formatted['headers']['From']);
        self::assertStringContainsString("Zeile 1\r\nZeile 2 =C3=BC", $formatted['body']);
    }

    public function testHeaderInjectionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message('anna@client.test', "Hi\r\nBcc: everyone@example.com", 'Body');
    }

    private function transport(ScriptedSmtpServer $server): SmtpTransport
    {
        return new SmtpTransport($server, new MessageFormatter(new FrozenClock(new \DateTimeImmutable('2026-10-02 12:00:00'))), 'seo.example.com');
    }

    private function settings(string $encryption): MailSettings
    {
        return new MailSettings('smtp', 'mail.example.com', null, $encryption, 'noreply@example.com', 's3cret', 'noreply@example.com', 'SEO Control Center');
    }
}
